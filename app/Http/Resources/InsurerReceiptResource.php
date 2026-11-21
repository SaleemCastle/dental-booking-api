<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsurerReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'insurer_id' => $this->insurer_id, 'remittance_advice_id' => $this->remittance_advice_id, 'external_reference' => $this->external_reference, 'received_on' => $this->received_on?->toDateString(), 'recorded_at' => $this->recorded_at, 'recorded_by_user_id' => $this->recorded_by_user_id, 'channel' => $this->channel, 'source_provenance' => $this->source_provenance, 'amount' => $this->amount, 'currency' => $this->currency, 'verified' => $this->whenLoaded('verification', fn () => $this->verification !== null), 'verification' => $this->whenLoaded('verification', fn () => $this->verification ? ['verified_at' => $this->verification->verified_at, 'verified_by_user_id' => $this->verification->verified_by_user_id, 'verification_method' => $this->verification->verification_method, 'provenance' => $this->verification->provenance, 'observed_amount' => $this->verification->observed_amount, 'observed_currency' => $this->verification->observed_currency] : null), 'financial_posted' => false];
    }
}
