<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InsuranceRemittanceAdviceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'insurer_id' => $this->insurer_id,
            'external_reference' => $this->external_reference,
            'advice_date' => $this->advice_date?->toDateString(),
            'recorded_at' => $this->recorded_at?->toISOString(),
            'recorded_by_user_id' => $this->recorded_by_user_id,
            'communication_channel' => $this->communication_channel,
            'source_provenance' => $this->source_provenance,
            'currency' => $this->currency,
            'reported_totals' => [
                'billed' => $this->reported_billed_total,
                'allowed' => $this->reported_allowed_total,
                'paid' => $this->reported_paid_total,
                'denied' => $this->reported_denied_total,
                'adjusted' => $this->reported_adjusted_total,
            ],
            'corrects_advice_id' => $this->corrects_advice_id,
            'correction_reason' => $this->correction_reason,
        ];
    }
}
