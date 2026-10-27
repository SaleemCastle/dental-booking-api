<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'appointment_id' => $this->appointment_id,
            'invoice_number' => $this->invoice_number,
            'amount_due' => $this->amount_due,
            'amount_paid' => $this->amount_paid,
            'currency' => $this->currency,
            'balance' => $this->balance,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'status' => $this->status,
            'issued_at' => $this->issued_at,
            'due_date' => $this->due_date?->toDateString(),
            'voided_at' => $this->voided_at,
            'void_reason' => $this->void_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
