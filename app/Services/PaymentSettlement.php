<?php

namespace App\Services;

use App\Billing\Money;
use App\Billing\Providers\CheckoutResult;
use App\Billing\Providers\ProviderException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentLink;
use Illuminate\Support\Facades\DB;

final class PaymentSettlement
{
    public function __construct(private readonly AuditLogService $auditLogs) {}

    /** Caller holds invoice then link locks. All writes and event receipt commit together. */
    public function record(Invoice $invoice, PaymentLink $link, CheckoutResult $result, ?int $actorId = null): Payment
    {
        if (DB::transactionLevel() < 1 || $invoice->id !== $link->invoice_id || $invoice->tenant_id !== $link->tenant_id
            || $result->status !== 'completed' || $result->reference !== $link->external_reference
            || $result->amountMinor !== Money::minor($link->amount) || $result->currency !== $invoice->currency
            || $result->currency !== $link->currency || $result->amountMinor < 1) {
            throw new ProviderException('reconciliation_required');
        }
        $reference = $result->transactionReference ?? $result->reference;
        if (! preg_match('/\A[A-Za-z0-9._:-]{1,255}\z/', $reference)) {
            throw new ProviderException('reconciliation_required');
        }
        $existing = Payment::where('payment_link_id', $link->id)->lockForUpdate()->first();
        if ($existing) {
            // A session reference is sufficient when a provider omits its transaction ID on lookup.
            if ($reference !== $result->reference && $existing->provider_transaction_reference !== $result->reference
                && $existing->provider_transaction_reference !== $reference) {
                throw new ProviderException('reconciliation_required');
            }

            return $existing;
        }
        if (Payment::where('payment_provider_configuration_id', $link->payment_provider_configuration_id)
            ->where('provider_transaction_reference', $reference)->lockForUpdate()->first()) {
            throw new ProviderException('reconciliation_required');
        }
        $paid = $invoice->payments()->lockForUpdate()->get()->sum(fn (Payment $payment) => Money::minor($payment->amount));
        $total = Money::minor($invoice->amount_due);
        if (! in_array($invoice->status, ['issued', 'partially_paid'], true) || $result->amountMinor > $total - $paid) {
            throw new ProviderException('reconciliation_required');
        }
        $payment = new Payment;
        $payment->forceFill([
            'invoice_id' => $invoice->id, 'payment_link_id' => $link->id,
            'payment_provider_configuration_id' => $link->payment_provider_configuration_id,
            'provider_transaction_reference' => $reference, 'reference' => $reference,
            'idempotency_key' => 'provider:'.$link->operation_key,
            'amount' => Money::decimal($result->amountMinor), 'currency' => $result->currency,
            'payment_method' => 'card', 'status' => 'paid', 'paid_at' => now('UTC'),
        ])->save();
        $invoice->update([
            'amount_paid' => Money::decimal($paid + $result->amountMinor),
            'status' => $paid + $result->amountMinor === $total ? 'paid' : 'partially_paid',
        ]);
        $this->auditLogs->record('recorded', 'payments', $payment->id, $actorId);
        $this->auditLogs->record($invoice->status, 'invoices', $invoice->id, $actorId);
        // The provider result is authoritative even when staff initiated the lookup.
        // Delivery must not depend on that staff member retaining payment-entry access.
        app(ClinicNotificationService::class)->paymentRecorded($payment->id, null);
        $configuration = $link->configuration;
        if (str_starts_with($configuration->credentials['api_key'] ?? '', 'hp_test_') && $configuration->sandbox_verified_at === null) {
            $configuration->sandbox_verified_at = now('UTC');
            $configuration->save();
        }

        return $payment;
    }
}
