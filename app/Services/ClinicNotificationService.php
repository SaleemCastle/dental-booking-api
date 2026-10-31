<?php

namespace App\Services;

use App\Models\ClinicNotification;
use App\Models\CommunicationConfiguration;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ClinicNotificationService
{
    public function paymentRecorded(int $paymentId, ?int $actorId): ?ClinicNotification
    {
        return $this->enqueue('payment.recorded', $paymentId, $actorId);
    }

    public function paymentLinkCreated(int $linkId, int $actorId): ?ClinicNotification
    {
        return $this->enqueue('payment_link.created', $linkId, $actorId);
    }

    /** Called only after the backend has established a terminal checkout outcome. */
    public function paymentLinkUnavailable(int $linkId, ?int $actorId = null): ?ClinicNotification
    {
        $link = PaymentLink::findOrFail($linkId);
        if (! in_array($link->status, ['failed', 'expired'], true)) {
            return null;
        }

        return $this->enqueue('payment_link.'.$link->status, $linkId, $actorId);
    }

    private function enqueue(string $event, int $sourceId, ?int $actorId): ?ClinicNotification
    {
        // Disabled clinics do not change existing billing behavior or audit counts.
        if (! CommunicationConfiguration::where('enabled', true)->exists()) {
            return null;
        }
        app(TenantContext::class)->requireId();

        return DB::transaction(function () use ($event, $sourceId, $actorId) {
            $source = $event === 'payment.recorded' ? Payment::findOrFail($sourceId) : PaymentLink::findOrFail($sourceId);
            $invoice = Invoice::lockForUpdate()->findOrFail($source->invoice_id);
            $configuration = CommunicationConfiguration::where('enabled', true)->lockForUpdate()->first();
            if (! $configuration || ! isset($configuration->settings['events'][$event])) {
                return null;
            }
            if ($event === 'payment_link.created' && ($source->status !== 'active' || ! $source->checkout_url)) {
                return null;
            }
            if ($event === 'payment.recorded' && $source->status !== 'paid') {
                return null;
            }
            if (in_array($event, ['payment_link.failed', 'payment_link.expired'], true)
                && $event !== 'payment_link.'.$source->status) {
                return null;
            }
            $this->authorizeTrigger($event, $invoice, $actorId, $event !== 'payment.recorded' ? $sourceId : null);
            $key = $event.':'.$sourceId;
            if ($existing = ClinicNotification::where('event_key', $key)->first()) {
                return $existing;
            }
            $patient = Patient::findOrFail($invoice->patient_id);
            $notification = ClinicNotification::create([
                'configuration_id' => $configuration->id, 'configuration_revision' => $configuration->revision,
                'event' => $event, 'event_key' => $key, 'invoice_id' => $invoice->id,
                'patient_id' => $invoice->patient_id, 'appointment_id' => $invoice->appointment_id,
                'payment_id' => $event === 'payment.recorded' ? $sourceId : null,
                'payment_link_id' => $event === 'payment.recorded' ? $source->payment_link_id : $sourceId,
                'actor_id' => $actorId, 'user_triggered' => $actorId !== null,
                'trigger' => $actorId !== null ? 'staff' : 'payment_provider',
                'variables' => ['patient_name' => $patient->firstName.' '.$patient->lastName,
                    'invoice_number' => $invoice->invoice_number, 'amount' => $invoice->currency.' '.$source->amount,
                    'balance' => $invoice->currency.' '.$invoice->balance,
                    'payment_link' => $event === 'payment_link.created' ? $source->checkout_url : '',
                    'payment_status' => $source->status],
                'selection_status' => 'pending',
            ]);
            $recipients = app(NotificationRecipients::class)->select($notification, $configuration->settings);
            foreach ($recipients as $recipient) {
                $notification->deliveries()->create([
                    ...$recipient, 'recipient_hash' => hash_hmac('sha256', strtolower($recipient['address']), config('app.key')),
                    'operation_key' => (string) Str::uuid(), 'available_at' => now('UTC'),
                ]);
            }
            $notification->update(['selection_status' => $recipients ? 'queued' : 'no_eligible_recipients']);
            app(AuditLogService::class)->record($recipients ? 'queued' : 'no_eligible_recipients', 'clinic_notifications', $notification->id, $actorId);

            return $notification;
        });
    }

    public function authorizeTrigger(string $event, Invoice $invoice, ?int $actorId, ?int $linkId = null): void
    {
        $tenantId = app(TenantContext::class)->requireId();
        if ($actorId === null) {
            // Only trusted settlement/webhook paths emit system billing outcomes.
            abort_unless(in_array($event, ['payment.recorded', 'payment_link.failed', 'payment_link.expired'], true), 403);

            return;
        }
        $actor = User::findOrFail($actorId);
        app(TenantResolver::class)->resolve($actor, (string) $tenantId);
        if ($event === 'payment.recorded') {
            Gate::forUser($actor)->authorize('create', Payment::class);
        } else {
            if (in_array($event, ['payment_link.failed', 'payment_link.expired'], true)) {
                $link = PaymentLink::where('invoice_id', $invoice->id)->findOrFail($linkId);
                if (Gate::forUser($actor)->allows('cancel', $link)) {
                    return;
                }
            }
            if (! Gate::forUser($actor)->allows('createPaymentLink', $invoice)) {
                Gate::forUser($actor)->authorize('refresh', PaymentLink::where('invoice_id', $invoice->id)->findOrFail($linkId));
            }
        }
    }
}
