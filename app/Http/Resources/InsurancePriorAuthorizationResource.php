<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsurancePriorAuthorizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'patient_id' => $this->patient_id, 'association_id' => $this->association_id, 'policy_revision_id' => $this->policy_revision_id,
            'claim_id' => $this->claim_id, 'treatment_plan_item_id' => $this->treatment_plan_item_id, 'estimate_id' => $this->estimate_id,
            'request_identifier' => $this->request_identifier, 'request_date' => $this->request_date?->format('Y-m-d'), 'treatment_description' => $this->treatment_description,
            'procedure_details' => $this->procedure_details, 'requested_amount' => (string) $this->requested_amount, 'currency' => $this->currency, 'status' => $this->status,
            'lock_version' => $this->lock_version, 'events' => InsurancePriorAuthorizationEventResource::collection($this->whenLoaded('events')),
            'decisions' => $this->whenLoaded('decisions', fn () => $this->decisions->map(fn ($d) => [
                'id' => $d->id, 'decision_type' => $d->decision_type, 'authorized_amount' => $d->authorized_amount === null ? null : (string) $d->authorized_amount,
                'currency' => $d->currency, 'authorized_scope' => $d->authorized_scope, 'valid_from' => $d->valid_from?->format('Y-m-d'), 'valid_through' => $d->valid_through?->format('Y-m-d'),
                'communication_channel' => $d->communication_channel, 'decision_at' => $d->decision_at?->toIso8601String(), 'recorded_by_user_id' => $d->recorded_by_user_id,
                'source_provenance' => $d->source_provenance, 'staff_entered' => true,
            ]))];
    }
}
