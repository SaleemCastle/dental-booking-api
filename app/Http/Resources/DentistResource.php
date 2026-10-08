<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DentistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => trim($this->first_name.' '.$this->last_name),
            'email' => $this->email,
            'contact_number' => $this->contact_number,
            'specialization' => $this->specialization,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
