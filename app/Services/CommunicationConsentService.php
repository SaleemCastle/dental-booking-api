<?php

namespace App\Services;

use App\Exceptions\ConsentBlocked;
use App\Models\CommunicationConsent;
use App\Models\CommunicationConsentDecision;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CommunicationConsentService
{
    public const SCOPES = ['transactional' => ['appointments', 'billing', 'payment_receipts'], 'clinical' => ['follow_up'], 'general' => ['non_essential']];

    public function authorize(User $actor): void
    {
        abort_unless($actor->isMemberOfCurrentTenant() && ! $actor->hasAnyRole([Role::ADMIN, 'patient'])
            && $actor->hasPermission('messaging.preferences.manage'), 403);
    }

    public function validateScope(array $data): array
    {
        $purpose = is_string($data['purpose'] ?? null) ? $data['purpose'] : '';

        return Validator::make($data, [
            'channel' => ['required', 'string', Rule::in(['in_app', 'email', 'sms'])],
            'purpose' => ['required', 'string', Rule::in(array_keys(self::SCOPES))],
            'scope' => ['required', 'string', Rule::in(self::SCOPES[$purpose] ?? [])],
        ])->validate();
    }

    public function record(int $patientId, array $data, User $actor): CommunicationConsent
    {
        $this->authorize($actor);
        $scope = $this->validateScope($data);
        $fields = Validator::make($data, [
            'status' => ['required', Rule::in(['granted', 'declined', 'withdrawn', 'revoked'])],
            'source' => ['required', Rule::in(['in_person', 'written_form', 'phone', 'patient_request'])],
            'text_version' => ['required', 'string', 'max:100'],
            'acknowledged_text' => ['required', 'string', 'max:10000'],
            'evidence' => ['required', 'string', 'max:2000'],
            'captured_by' => ['prohibited'], 'captured_at' => ['prohibited'], 'version' => ['prohibited'],
            'capture_actor_type' => ['prohibited'], 'captured_by_patient_id' => ['prohibited'],
            'patient_id' => ['prohibited'], 'legacy_preference_id' => ['prohibited'],
        ])->validate();

        return DB::transaction(function () use ($patientId, $actor, $scope, $fields) {
            app(MessagingService::class)->lockTenant();
            Patient::findOrFail($patientId);
            $version = (int) CommunicationConsent::where('patient_id', $patientId)->where($scope)->max('version') + 1;
            $consent = CommunicationConsent::create([
                ...$scope, ...$fields, 'patient_id' => $patientId, 'captured_by' => $actor->id,
                'capture_actor_type' => 'staff', 'captured_at' => now('UTC'), 'version' => $version,
            ]);
            app(AuditLogService::class)->record('consent_'.$consent->status, 'communication_consents', $consent->id, $actor->id);

            return $consent;
        }, 3);
    }

    public function resolve(int $patientId, string $purpose, string $scope, string $channel): array
    {
        Patient::findOrFail($patientId);
        $this->validateScope(compact('purpose', 'scope', 'channel'));
        $consent = CommunicationConsent::where('patient_id', $patientId)->where(compact('purpose', 'scope', 'channel'))->orderByDesc('version')->first();
        $allowed = $consent?->status === 'granted';

        return ['allowed' => $allowed, 'reason' => $allowed ? 'consent_granted' : ($consent ? 'consent_'.$consent->status : 'consent_missing'),
            'patient_id' => $patientId, 'purpose' => $purpose, 'scope' => $scope, 'channel' => $channel,
            'consent_id' => $consent?->id, 'version' => $consent?->version,
            'status' => $consent?->status ?? 'not_recorded', 'text_version' => $consent?->text_version,
            'source' => $consent?->source, 'captured_by' => $consent?->captured_by,
            'capture_actor_type' => $consent?->capture_actor_type, 'captured_by_patient_id' => $consent?->captured_by_patient_id,
            'captured_at' => $consent?->captured_at?->toISOString()];
    }

    public function requireAllowed(array $decision): array
    {
        if (! $decision['allowed']) {
            throw new ConsentBlocked($decision);
        }

        return $decision;
    }

    public function capture(array $decision, string $resourceType, int $resourceId, ?int $actorId): CommunicationConsentDecision
    {
        $record = CommunicationConsentDecision::create([
            'patient_id' => $decision['patient_id'], 'consent_id' => $decision['consent_id'],
            'purpose' => $decision['purpose'], 'scope' => $decision['scope'], 'channel' => $decision['channel'],
            'allowed' => $decision['allowed'], 'reason' => $decision['reason'], 'snapshot' => $decision,
            'resource_type' => $resourceType, 'resource_id' => $resourceId, 'actor_id' => $actorId, 'decided_at' => now('UTC'),
        ]);
        app(AuditLogService::class)->record($decision['allowed'] ? 'consent_allowed' : 'consent_blocked', 'communication_consent_decisions', $record->id, $actorId);

        return $record;
    }

    public function forUseCase(int $patientId, string $useCase): array
    {
        [$purpose, $scope] = match ($useCase) {
            'care' => ['clinical', 'follow_up'],
            'billing' => ['transactional', 'billing'],
            'appointments' => ['transactional', 'appointments'],
            'general' => ['general', 'non_essential'],
            default => throw new \InvalidArgumentException('Unsupported communication use case.'),
        };

        return $this->resolve($patientId, $purpose, $scope, 'in_app');
    }

    public function forEvent(int $patientId, string $event, string $channel): array
    {
        [$purpose, $scope] = match ($event) {
            'appointment.confirmed', 'appointment.reminder', 'appointment.cancelled', 'appointment.rescheduled' => ['transactional', 'appointments'],
            'payment.recorded' => ['transactional', 'payment_receipts'],
            'payment_link.created', 'payment_link.failed', 'payment_link.expired' => ['transactional', 'billing'],
            'task.reminder' => ['clinical', 'follow_up'],
            default => throw new \InvalidArgumentException('Unsupported communication event.'),
        };

        return $this->resolve($patientId, $purpose, $scope, $channel);
    }
}
