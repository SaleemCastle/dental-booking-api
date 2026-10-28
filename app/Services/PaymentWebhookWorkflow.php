<?php

namespace App\Services;

use App\Billing\Money;
use App\Billing\Providers\CheckoutResult;
use App\Billing\Providers\PaymentProviderRegistry;
use App\Billing\Providers\ProviderException;
use App\Billing\Providers\VerifiedPaymentEvent;
use App\Billing\Providers\WebhookPaymentProvider;
use App\Models\Invoice;
use App\Models\PaymentLink;
use App\Models\PaymentProviderConfiguration;
use App\Models\PaymentProviderEvent;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PaymentWebhookWorkflow
{
    public function __construct(
        private readonly PaymentProviderRegistry $providers,
        private readonly TenantContext $context,
        private readonly PaymentSettlement $settlement,
        private readonly AuditLogService $auditLogs,
    ) {}

    public function receive(string $endpoint, #[\SensitiveParameter] string $body, #[\SensitiveParameter] string $signature): void
    {
        try {
            // The endpoint chooses a credential revision only. No caller-supplied tenant header is trusted.
            $configuration = PaymentProviderConfiguration::withoutGlobalScopes()->where('provider', 'handypay')
                ->where('webhook_endpoint', $endpoint)->first();
            if (! $configuration) {
                throw new ProviderException('invalid_signature');
            }
            $provider = $this->providers->resolve($configuration->provider);
            if (! $provider instanceof WebhookPaymentProvider) {
                throw new ProviderException('not_configured');
            }
            $event = $provider->verifyWebhook($body, $signature, $configuration->credentials);
            $hash = hash('sha256', $body);
            $this->context->run($configuration->tenant, function () use ($configuration, $event, $hash) {
                DB::transaction(function () use ($configuration, $event, $hash) {
                    $link = $this->findLink($configuration, $event);
                    if ($link) {
                        // Match every other payment writer's invoice -> link -> configuration lock order.
                        Invoice::whereKey($link->invoice_id)->lockForUpdate()->firstOrFail();
                        $link = PaymentLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
                    }
                    // Serialize event IDs before ledger side effects. No network calls occur under these locks.
                    PaymentProviderConfiguration::whereKey($configuration->id)->lockForUpdate()->firstOrFail();
                    $receipt = PaymentProviderEvent::where('payment_provider_configuration_id', $configuration->id)
                        ->where('event_id', $event->id)->lockForUpdate()->first();
                    if ($receipt) {
                        if (! hash_equals($receipt->payload_hash, $hash)) {
                            throw new ProviderException('reconciliation_required');
                        }

                        if (! in_array($receipt->outcome, ['unmatched', 'reconciliation_required'], true)) {
                            return;
                        }
                    }
                    $receipt ??= PaymentProviderEvent::create([
                        'payment_provider_configuration_id' => $configuration->id,
                        'event_id' => $event->id, 'event_type' => $event->type, 'payload_hash' => $hash,
                        'provider_transaction_reference' => $event->transactionReference,
                    ]);
                    $receipt->outcome = $event->status === null ? 'ignored' : $this->reconcile($configuration, $event, $receipt, $link);
                    $receipt->save();
                }, 3);
            });
        } catch (ProviderException $error) {
            throw $error;
        } catch (Throwable) {
            // Database/transport exceptions may contain upstream data. Roll back and let the provider retry.
            throw new ProviderException('unavailable');
        }
    }

    private function findLink(PaymentProviderConfiguration $configuration, VerifiedPaymentEvent $event): ?PaymentLink
    {
        if ($event->status === null) {
            return null;
        }
        $query = PaymentLink::where('payment_provider_configuration_id', $configuration->id);
        if ($event->sessionReference !== null) {
            $link = (clone $query)->where('external_reference', $event->sessionReference)->first();
            // A webhook may arrive before createCheckout's response is committed.
            if (! $link && $event->operationKey !== null) {
                $link = (clone $query)->where('operation_key', $event->operationKey)->whereNull('external_reference')->first();
            }
        } else {
            if ($event->operationKey === null) {
                return null; // Other merchant payments may have no application metadata.
            }
            $link = $query->where('operation_key', $event->operationKey)->first();
        }

        return $link;
    }

    private function reconcile(PaymentProviderConfiguration $configuration, VerifiedPaymentEvent $event, PaymentProviderEvent $receipt, ?PaymentLink $link): string
    {
        $live = str_starts_with($configuration->credentials['api_key'] ?? '', 'hp_live_');
        if ($event->liveMode !== null && $event->liveMode !== $live) {
            return 'reconciliation_required';
        }
        if (! $link) {
            return 'unmatched';
        }
        $invoice = Invoice::whereKey($link->invoice_id)->lockForUpdate()->firstOrFail();
        $receipt->payment_link_id = $link->id;
        if (($event->operationKey !== null && $event->operationKey !== $link->operation_key)
            || ($event->amountMinor !== null && $event->amountMinor !== Money::minor($link->amount))
            || ($event->currency !== null && $event->currency !== $link->currency)
            || ($event->sessionReference !== null && $link->external_reference !== null && $event->sessionReference !== $link->external_reference)) {
            return 'reconciliation_required';
        }
        if ($link->external_reference === null && $event->sessionReference !== null) {
            $link->external_reference = $event->sessionReference;
        }
        if ($event->status === 'completed') {
            if ($link->external_reference === null || $invoice->paymentLinks()->where('id', '!=', $link->id)->whereIn('status', PaymentLink::OUTSTANDING)->exists()) {
                return 'reconciliation_required';
            }
            try {
                $this->settlement->record($invoice, $link, new CheckoutResult(
                    $link->external_reference, null, 'completed', null,
                    $event->amountMinor, $event->currency, $event->transactionReference,
                ));
            } catch (ProviderException) {
                return 'reconciliation_required';
            }
        } elseif (in_array($link->status, ['completed', 'expired', 'cancelled', 'failed'], true)) {
            return 'ignored';
        }
        if ($link->status !== $event->status) {
            $link->status = $event->status;
            $this->auditLogs->record($link->status, 'payment_links', $link->id, null);
        }
        $link->save();

        return 'processed';
    }
}
