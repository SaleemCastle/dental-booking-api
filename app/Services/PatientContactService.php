<?php

namespace App\Services;

use App\Communications\SafeTemplate;
use App\Models\Patient;
use App\Models\PatientContact;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class PatientContactService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function create(Patient $patient, array $data, User $actor): PatientContact
    {
        app(TenantContext::class)->requireId();
        Gate::forUser($actor)->authorize('update', $patient);
        $data = Validator::make(['contact' => $data], ['contact' => 'required|array:channel,address,role,authorized_events',
            'contact.channel' => ['required', Rule::in(['email', 'sms'])],
            'contact.address' => ['required', 'string', 'max:254', ($data['channel'] ?? '') === 'email' ? 'email:rfc' : 'regex:/\A\+[1-9][0-9]{7,14}\z/'],
            'contact.role' => ['required', Rule::in(['patient', 'billing_copy'])],
            'contact.authorized_events' => ['required', 'array', 'min:1', 'max:'.count(SafeTemplate::DEFAULTS)],
            'contact.authorized_events.*' => ['required', 'distinct', Rule::in(array_keys(SafeTemplate::DEFAULTS))],
        ])->validate()['contact'];

        return DB::transaction(function () use ($patient, $data, $actor) {
            Patient::query()->active()->lockForUpdate()->findOrFail($patient->id);
            $data['address'] = $data['channel'] === 'email' ? strtolower(trim($data['address'])) : $data['address'];
            $contact = PatientContact::create(['patient_id' => $patient->id, ...$data]);
            $this->audit->record('created', 'patient_contacts', $contact->id, $actor->id);

            return $contact;
        });
    }

    public function verify(Patient $patient, int $contactId, string $method, User $actor): PatientContact
    {
        return $this->change($patient, $contactId, $actor, function ($contact) use ($method, $actor) {
            Validator::make(['method' => $method], ['method' => ['required', Rule::in(['in_person', 'verified_callback'])]])->validate();
            abort_if($contact->revoked_at !== null, 422, 'Revoked contacts must be replaced and verified again.');
            $contact->forceFill(['verified_at' => $contact->verified_at ?? now('UTC'), 'verified_by' => $actor->id, 'verification_method' => $method]);
        }, 'verified');
    }

    public function revoke(Patient $patient, int $contactId, User $actor): PatientContact
    {
        return $this->change($patient, $contactId, $actor, fn ($contact) => $contact->forceFill(['revoked_at' => $contact->revoked_at ?? now('UTC')]), 'revoked');
    }

    private function change(Patient $patient, int $id, User $actor, callable $change, string $action): PatientContact
    {
        app(TenantContext::class)->requireId();
        Gate::forUser($actor)->authorize('update', $patient);

        return DB::transaction(function () use ($patient, $id, $actor, $change, $action) {
            Patient::query()->lockForUpdate()->findOrFail($patient->id);
            $contact = PatientContact::where('patient_id', $patient->id)->lockForUpdate()->findOrFail($id);
            $change($contact);
            if ($contact->isDirty()) {
                $contact->save();
                $this->audit->record($action, 'patient_contacts', $contact->id, $actor->id);
            }

            return $contact;
        });
    }
}
