<?php

namespace Tests\Concerns;

use App\Models\CommunicationConsent;
use App\Models\Patient;
use App\Models\User;
use App\Services\CommunicationConsentService;

trait CommunicationConsentFixtures
{
    /** Consent is an explicit fixture independent of contact verification. */
    private function grantCommunicationConsent(Patient $patient, User $actor, string $channel): void
    {
        foreach (CommunicationConsentService::SCOPES as $purpose => $scopes) {
            foreach ($scopes as $scope) {
                if (! CommunicationConsent::where('patient_id', $patient->id)->where(compact('purpose', 'scope', 'channel'))->exists()) {
                    CommunicationConsent::create([
                        'patient_id' => $patient->id, ...compact('purpose', 'scope', 'channel'),
                        'status' => 'granted', 'source' => 'written_form', 'captured_by' => $actor->id,
                        'captured_at' => now('UTC'), 'version' => 1, 'text_version' => 'fixture-v1',
                        'acknowledged_text' => 'Patient agrees to this communication scope and channel.',
                        'evidence' => 'Explicit consent fixture, separate from verified address.',
                    ]);
                }
            }
        }
    }
}
