<?php

namespace App\Services;

use App\Models\ClinicNotification;
use App\Models\Patient;
use App\Models\PatientContact;
use App\Models\User;

final class NotificationRecipients
{
    public function select(ClinicNotification $notification, array $settings): array
    {
        $patientId = $notification->patient_id ?? \App\Models\Invoice::findOrFail($notification->invoice_id)->patient_id;
        $engagement = $notification->invoice_id === null;
        if (! Patient::query()->active()->whereKey($patientId)->exists()) {
            return [];
        }
        $rule = $settings['events'][$notification->event] ?? [];
        $recipients = [];
        foreach (PatientContact::where('patient_id', $patientId)->whereNotNull('verified_at')->whereNull('revoked_at')
            ->orderByRaw("CASE WHEN role = 'patient' THEN 0 ELSE 1 END")->orderBy('id')->get() as $contact) {
            if (! in_array($contact->channel, $rule['channels'] ?? [], true)
                || ! in_array($notification->event, $contact->authorized_events, true)
                || ! in_array($contact->role, $engagement ? ['patient'] : ['patient', 'billing_copy'], true)) {
                continue;
            }
            $valid = $contact->channel === 'email' ? filter_var($contact->address, FILTER_VALIDATE_EMAIL)
                : preg_match('/\A\+[1-9][0-9]{7,14}\z/', $contact->address);
            if ($valid) {
                $recipients[] = ['channel' => $contact->channel, 'address' => $contact->address,
                    'patient_contact_id' => $contact->id, 'copy_user_id' => null, 'recipient_kind' => $contact->role];
            }
        }
        if (! $engagement && in_array('email', $rule['channels'] ?? [], true)) {
            foreach ($rule['copy_user_ids'] ?? [] as $id) {
                $user = User::find($id);
                if (app(CommunicationConfigurationService::class)->eligibleCopyUser($user)) {
                    $recipients[] = ['channel' => 'email', 'address' => strtolower($user->email),
                        'patient_contact_id' => null, 'copy_user_id' => $user->id, 'recipient_kind' => 'internal_copy'];
                }
            }
        }

        return collect($recipients)->unique(fn ($recipient) => $recipient['channel'].':'.strtolower($recipient['address']))->values()->all();
    }
}
