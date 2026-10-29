<?php

namespace App\Services;

use App\Communications\DeliveryException;
use App\Communications\DeliveryMessage;
use App\Communications\ProviderRegistry;
use App\Communications\SafeTemplate;
use App\Communications\TracksDelivery;
use App\Models\ClinicNotification;
use App\Models\CommunicationConfiguration;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\PaymentLink;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class NotificationDeliveryService
{
    public const MAX_ATTEMPTS = 5;

    public function refreshStatus(int $tenantId, int $deliveryId): void
    {
        $tenant = Tenant::where('active', true)->find($tenantId);
        if (! $tenant) {
            return;
        }
        app(TenantContext::class)->run($tenant, function () use ($deliveryId) {
            $delivery = NotificationDelivery::where('status', 'accepted')->where('channel', 'sms')->find($deliveryId);
            if (! $delivery || $delivery->available_at->isFuture()) {
                return;
            }
            $notification = ClinicNotification::findOrFail($delivery->notification_id);
            $configuration = CommunicationConfiguration::find($notification->configuration_id);
            if (! $configuration?->enabled || $configuration->revision !== $notification->configuration_revision) {
                DB::transaction(function () use ($delivery, $notification) {
                    $current = NotificationDelivery::lockForUpdate()->findOrFail($delivery->id);
                    if ($current->status === 'accepted') {
                        $this->finish($current, $notification, 'unknown', 'configuration_changed');
                    }
                });

                return;
            }
            $settings = $configuration->settings['channels'][$delivery->channel];
            $provider = app(ProviderRegistry::class)->resolve($settings['provider']);
            if (! $provider instanceof TracksDelivery) {
                return;
            }
            try {
                $status = $provider->status($delivery->provider_reference, $settings);
            } catch (Throwable) {
                $status = 'accepted'; // Poll later, never resend an accepted message.
            }
            DB::transaction(function () use ($delivery, $notification, $status) {
                $current = NotificationDelivery::lockForUpdate()->findOrFail($delivery->id);
                if ($current->status !== 'accepted') {
                    return;
                }
                if (in_array($status, ['delivered', 'failed'], true)) {
                    $this->finish($current, $notification, $status, $status === 'failed' ? 'provider_delivery_failed' : null);
                } elseif ($current->accepted_at->lt(now('UTC')->subDays(7))) {
                    $this->finish($current, $notification, 'unknown', 'delivery_confirmation_unavailable');
                } else {
                    $current->update(['available_at' => now('UTC')->addMinutes(5)]);
                }
            });
        });
    }

    /** Trusted worker entry point. Only scalar IDs cross the worker boundary. */
    public function deliver(int $tenantId, int $deliveryId): void
    {
        $tenant = Tenant::where('active', true)->find($tenantId);
        if (! $tenant) {
            return;
        }
        app(TenantContext::class)->run($tenant, function () use ($deliveryId) {
            $claimed = DB::transaction(function () use ($deliveryId) {
                $delivery = NotificationDelivery::lockForUpdate()->find($deliveryId);
                if (! $delivery || ! in_array($delivery->status, ['pending', 'retry'], true) || $delivery->available_at->isFuture()) {
                    return null;
                }
                $notification = ClinicNotification::findOrFail($delivery->notification_id);
                try {
                    $settings = $this->validateDelivery($delivery, $notification);
                } catch (DeliveryException $error) {
                    $this->finish($delivery, $notification, 'suppressed', $error->reason);

                    return null;
                }
                $delivery->update(['status' => 'processing', 'attempts' => $delivery->attempts + 1, 'attempted_at' => now('UTC')]);
                app(AuditLogService::class)->record('attempted', 'notification_deliveries', $delivery->id, $notification->actor_id);

                return [$delivery, $notification, $settings];
            });
            if (! $claimed) {
                return;
            }
            [$delivery, $notification, $settings] = $claimed;
            try {
                $variables = $notification->variables;
                $rule = $settings['events'][$notification->event];
                if ($delivery->recipient_kind !== 'patient') {
                    // Copies never receive a bearer checkout link, including through custom templates.
                    $variables['payment_link'] = '';
                    $template = 'Payment update for {{patient_name}}. Invoice {{invoice_number}}: {{amount}}, balance {{balance}}, status {{payment_status}}.';
                } else {
                    $template = $rule['template'] ?? SafeTemplate::DEFAULTS[$notification->event];
                }
                $message = new DeliveryMessage($delivery->address, 'Clinic payment update',
                    app(SafeTemplate::class)->render($template, $variables), $delivery->operation_key);
                $channel = $settings['channels'][$delivery->channel];
                $reference = app(ProviderRegistry::class)->resolve($channel['provider'])->send($message, $channel);
                if (strlen($reference) > 255 || preg_match('/[\x00-\x1F\x7F]/', $reference)) {
                    throw new DeliveryException('provider_response_invalid', uncertain: true);
                }
                DB::transaction(fn () => $this->finish($delivery, $notification, 'accepted', null, $reference));
            } catch (DeliveryException $error) {
                $status = $error->uncertain ? 'unknown' : ($error->retryable && $delivery->attempts < self::MAX_ATTEMPTS ? 'retry' : 'failed');
                DB::transaction(fn () => $this->finish($delivery, $notification, $status, $error->reason));
            } catch (Throwable) {
                // Never log exception text: HTTP/SMTP/SQL errors may contain addresses, bodies or credentials.
                DB::transaction(fn () => $this->finish($delivery, $notification, 'unknown', 'acceptance_unknown'));
            }
        });
    }

    /** A crash after starting a send must never cause an automatic resend. */
    public function recoverStale(): void
    {
        app(TenantContext::class)->requireId();
        NotificationDelivery::where('status', 'processing')->where('attempted_at', '<', now('UTC')->subMinutes(5))
            ->orderBy('id')->eachById(function ($delivery) {
                DB::transaction(function () use ($delivery) {
                    $current = NotificationDelivery::lockForUpdate()->findOrFail($delivery->id);
                    if ($current->status === 'processing' && $current->attempted_at->lt(now('UTC')->subMinutes(5))) {
                        $this->finish($current, ClinicNotification::findOrFail($current->notification_id), 'unknown', 'worker_interrupted');
                    }
                });
            });
    }

    private function validateDelivery(NotificationDelivery $delivery, ClinicNotification $notification): array
    {
        if ($notification->user_triggered) {
            try {
                $actor = User::findOrFail($notification->actor_id);
                app(TenantResolver::class)->resolve($actor, (string) app(TenantContext::class)->requireId());
            } catch (AuthorizationException|ModelNotFoundException) {
                throw new DeliveryException('trigger_no_longer_authorized');
            }
        }
        $configuration = CommunicationConfiguration::find($notification->configuration_id);
        if (! $configuration?->enabled || $configuration->revision !== $notification->configuration_revision) {
            throw new DeliveryException('configuration_changed');
        }
        $invoice = Invoice::find($notification->invoice_id);
        if (! $invoice) {
            throw new DeliveryException('source_unavailable');
        }
        try {
            app(ClinicNotificationService::class)->authorizeTrigger($notification->event, $invoice, $notification->actor_id, $notification->payment_link_id);
        } catch (AuthorizationException|ModelNotFoundException) {
            throw new DeliveryException('trigger_no_longer_authorized');
        }
        if ($notification->event === 'payment_link.created') {
            $link = PaymentLink::find($notification->payment_link_id);
            if (! $link || $link->invoice_id !== $invoice->id || $link->status !== 'active'
                || ($link->expires_at !== null && $link->expires_at->isPast())
                || ! in_array($invoice->status, ['issued', 'partially_paid'], true)
                || $link->checkout_url !== $notification->variables['payment_link']) {
                throw new DeliveryException('payment_link_no_longer_active');
            }
        }
        if (in_array($notification->event, ['payment_link.failed', 'payment_link.expired'], true)) {
            $link = PaymentLink::find($notification->payment_link_id);
            if (! $link || $link->invoice_id !== $invoice->id || $notification->event !== 'payment_link.'.$link->status
                || ! in_array($invoice->status, ['issued', 'partially_paid'], true)
                || $invoice->paymentLinks()->where('id', '>', $link->id)->exists()) {
                throw new DeliveryException('payment_link_outcome_superseded');
            }
        }
        $recipients = app(NotificationRecipients::class)->select($notification, $configuration->settings);
        foreach ($recipients as $recipient) {
            if ($recipient['channel'] === $delivery->channel && $recipient['address'] === $delivery->address
                && $recipient['patient_contact_id'] === $delivery->patient_contact_id
                && $recipient['copy_user_id'] === $delivery->copy_user_id && $recipient['recipient_kind'] === $delivery->recipient_kind) {
                return $configuration->settings;
            }
        }

        throw new DeliveryException('recipient_no_longer_authorized');
    }

    private function finish(NotificationDelivery $delivery, ClinicNotification $notification, string $status, ?string $reason, ?string $reference = null): void
    {
        $delivery->fill(['status' => $status, 'failure_reason' => $reason]);
        if ($status === 'retry') {
            $delivery->available_at = now('UTC')->addSeconds([1 => 60, 2 => 300, 3 => 900, 4 => 3600][$delivery->attempts]);
        }
        if ($status === 'accepted') {
            $delivery->accepted_at = now('UTC');
            $delivery->provider_reference = $reference;
            $delivery->available_at = now('UTC')->addMinutes(5);
        }
        $delivery->save();
        app(AuditLogService::class)->record($status.($reason ? '.'.$reason : ''), 'notification_deliveries', $delivery->id, $notification->actor_id);
    }
}
