<?php

namespace App\Services;

use App\Billing\Money;
use App\Billing\Providers\CheckoutRequest;
use App\Billing\Providers\CheckoutResult;
use App\Billing\Providers\PaymentProviderRegistry;
use App\Billing\Providers\ProviderException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\PaymentProviderConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentLinkWorkflow
{
    public function __construct(
        private readonly PaymentProviderRegistry $providers,
        private readonly AuditLogService $auditLogs,
        private readonly PaymentSettlement $settlement,
    ) {}

    public function create(Invoice $invoice, array $data, User $actor): PaymentLink
    {
        Gate::forUser($actor)->authorize('create', PaymentLink::class);
        Gate::forUser($actor)->authorize('createPaymentLink', $invoice);
        $this->validatePayload($data, ['idempotency_key' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9._:-]+\z/']]);

        // Commit the attempt BEFORE any external side effect. A crash or ambiguous
        // timeout must not roll the key back and permit another hosted checkout.
        [$link, $dispatch] = DB::transaction(function () use ($invoice, $data) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $existing = $invoice->paymentLinks()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if ($existing->status === 'creating') {
                    throw new ProviderException('in_progress');
                }
                if ($existing->status === 'unknown') {
                    throw new ProviderException('reconciliation_required');
                }

                return [$existing, false];
            }
            if (! in_array($invoice->status, ['issued', 'partially_paid'], true)) {
                $this->invalid('invoice_id', 'Payment links require an issued invoice with an outstanding balance.');
            }
            if ($invoice->paymentLinks()->where(function ($query) {
                $query->whereIn('status', PaymentLink::OUTSTANDING)
                    ->orWhere(fn ($query) => $query->where('status', 'completed')->whereNotIn('id', Payment::whereNotNull('payment_link_id')->select('payment_link_id')));
            })->exists()) {
                $this->invalid('invoice_id', 'Resolve the existing checkout before creating another payment link.');
            }
            $paid = $invoice->payments()->get()->sum(fn (Payment $payment) => Money::minor($payment->amount));
            $balance = Money::minor($invoice->amount_due) - $paid;
            if ($balance <= 0) {
                $this->invalid('invoice_id', 'The invoice has no outstanding balance.');
            }
            $configuration = PaymentProviderConfiguration::query()->lockForUpdate()->where('enabled', true)->first();
            if (! $configuration) {
                throw new ProviderException('not_configured');
            }
            $provider = $this->providers->resolve($configuration->provider);
            if ($provider instanceof \App\Billing\Providers\WebhookPaymentProvider && empty($configuration->credentials['webhook_secret'])) {
                throw new ProviderException('not_configured');
            }
            if (str_starts_with($configuration->credentials['api_key'] ?? '', 'hp_live_')
                && ! PaymentProviderConfiguration::where('provider', $configuration->provider)->whereNotNull('sandbox_verified_at')->exists()) {
                throw new ProviderException('sandbox_required');
            }
            $link = $invoice->paymentLinks()->create([
                'payment_provider_configuration_id' => $configuration->id,
                'provider' => $configuration->provider,
                'operation_key' => (string) Str::uuid(),
                'idempotency_key' => $data['idempotency_key'],
                'amount' => Money::decimal($balance), 'currency' => $invoice->currency, 'status' => 'creating',
            ]);

            return [$link, true];
        }, 3);

        if (! $dispatch) {
            return $link;
        }

        try {
            $configuration = $link->configuration;
            $result = $this->providers->resolve($link->provider)->createCheckout(new CheckoutRequest(
                $link->operation_key, Money::minor($link->amount), $link->currency,
                $configuration->success_url, $configuration->cancel_url,
            ), $configuration->credentials);
            $this->validateResult($link, $result, creating: true);
        } catch (Throwable $error) {
            // Adapter exceptions may contain secrets; never propagate upstream errors.
            $safe = $error instanceof ProviderException ? $error : new ProviderException('unavailable', uncertain: true);
            DB::transaction(function () use ($link, $safe, $actor) {
                $stored = PaymentLink::query()->lockForUpdate()->findOrFail($link->id);
                if (! in_array($stored->status, ['creating', 'unknown'], true)) {
                    return; // A signed callback already established the remote outcome.
                }
                $stored->update(['status' => $safe->uncertain ? 'unknown' : 'failed']);
                $this->auditLogs->record($stored->status, 'payment_links', $stored->id, $actor->id);
            }, 3);
            throw $safe;
        }

        try {
            return DB::transaction(function () use ($link, $result, $actor) {
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($link->invoice_id);
                $stored = PaymentLink::query()->lockForUpdate()->findOrFail($link->id);
                if ($stored->status !== 'creating') {
                    if ($stored->external_reference !== $result->reference) {
                        throw new ProviderException('reconciliation_required');
                    }
                    $stored->wasRecentlyCreated = true;

                    return $stored;
                }
                $stored->fill([
                    'external_reference' => $result->reference, 'checkout_url' => $result->checkoutUrl,
                    'expires_at' => $result->expiresAt, 'status' => $result->status,
                ])->save();
                if ($result->status === 'completed') {
                    $this->settlement->record($invoice, $stored, $result, $actor->id);
                }
                $this->auditLogs->record('created', 'payment_links', $stored->id, $actor->id);
                $this->auditLogs->record($stored->status, 'payment_links', $stored->id, $actor->id);
                $stored->wasRecentlyCreated = true;

                return $stored;
            }, 3);
        } catch (Throwable) {
            // The committed reservation survives; avoid logging a database exception
            // containing the hosted URL, and never retry the external operation.
            throw new ProviderException('reconciliation_required', uncertain: true);
        }
    }

    public function refresh(PaymentLink $link, array $data, User $actor): PaymentLink
    {
        return $this->change($link, $data, $actor, false);
    }

    public function cancel(PaymentLink $link, array $data, User $actor): PaymentLink
    {
        return $this->change($link, $data, $actor, true);
    }

    public function validatePayload(array $data, array $rules = []): void
    {
        foreach (array_diff(array_keys($data), array_keys($rules)) as $unknown) {
            $this->invalid($unknown, 'Unsupported payment-link field. Only documented fields may be supplied.');
        }
        Validator::make($data, $rules)->validate();
    }

    private function change(PaymentLink $link, array $data, User $actor, bool $cancel): PaymentLink
    {
        Gate::forUser($actor)->authorize($cancel ? 'cancel' : 'refresh', $link);
        $this->validatePayload($data);

        // Bounded remote operation under the invoice lock. Never automatically retry
        // this transaction: a cancellation is an external side effect.
        return DB::transaction(function () use ($link, $actor, $cancel) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($link->invoice_id);
            $link = PaymentLink::query()->lockForUpdate()->findOrFail($link->id);
            if (! in_array($link->status, PaymentLink::OUTSTANDING, true) && ! (! $cancel && $link->status === 'completed' && ! Payment::where('payment_link_id', $link->id)->exists())) {
                if ($cancel && $link->status !== 'cancelled') {
                    $this->invalid('payment_link', 'Only an outstanding checkout can be cancelled.');
                }

                return $link;
            }
            if (! $link->external_reference) {
                throw new ProviderException('reconciliation_required');
            }
            try {
                $provider = $this->providers->resolve($link->provider);
                $result = $cancel
                    ? $provider->cancelCheckout($link->external_reference, $link->configuration->credentials)
                    : $provider->checkoutStatus($link->external_reference, $link->configuration->credentials);
                $this->validateResult($link, $result);
                if ($cancel && ! in_array($result->status, ['cancelled', 'expired'], true)) {
                    throw new ProviderException('reconciliation_required');
                }
                if ($link->status === 'pending' && $result->status === 'active') {
                    throw new ProviderException('invalid_response');
                }
            } catch (ProviderException $error) {
                throw $error;
            } catch (Throwable) {
                throw new ProviderException('unavailable');
            }
            $oldStatus = $link->status;
            if ($result->status === 'completed') {
                $this->settlement->record($invoice, $link, $result, $actor->id);
            }
            $link->fill([
                'status' => $result->status,
                'checkout_url' => $result->checkoutUrl ?? $link->checkout_url,
                'expires_at' => $result->expiresAt ?? $link->expires_at,
            ])->save();
            if ($oldStatus !== $link->status) {
                $this->auditLogs->record($link->status, 'payment_links', $link->id, $actor->id);
            }

            return $link;
        });
    }

    private function validateResult(PaymentLink $link, CheckoutResult $result, bool $creating = false): void
    {
        $url = $result->checkoutUrl;
        if (! preg_match('/\A[A-Za-z0-9._:-]{1,255}\z/', $result->reference)
            || (! $creating && $result->reference !== $link->external_reference)
            || ! in_array($result->status, ['active', 'pending', 'completed', 'expired', 'cancelled', 'failed'], true)
            || ($result->amountMinor !== null && $result->amountMinor !== Money::minor($link->amount))
            || ($result->currency !== null && $result->currency !== $link->currency)
            || ($result->status === 'active' && $url === null)
            || ($url !== null && (strlen($url) > 4096 || ! filter_var($url, FILTER_VALIDATE_URL)
                || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null))) {
            throw new ProviderException('invalid_response', uncertain: $creating);
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
