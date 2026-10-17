<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClinicalNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'dentist_id' => $this->dentist_id,
            'appointment_id' => $this->appointment_id,
            'note_text' => $this->note_text,
            'author_user_id' => $this->author_user_id,
            'updated_by_user_id' => $this->updated_by_user_id,
            'authorName' => $this->whenLoaded('author', fn () => $this->author?->name),
            'updatedByName' => $this->whenLoaded('lastEditor', fn () => $this->lastEditor?->name),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
