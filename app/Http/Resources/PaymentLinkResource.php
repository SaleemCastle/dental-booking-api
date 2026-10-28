<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'provider' => $this->provider,
            'external_reference' => $this->external_reference,
            'checkout_url' => $this->status === 'active' ? $this->checkout_url : null,
            'expires_at' => $this->expires_at,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'idempotency_key' => $this->idempotency_key,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
