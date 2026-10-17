<?php

namespace App\Http\Requests;

use App\Models\ClinicalNote;
use Illuminate\Foundation\Http\FormRequest;

class StoreClinicalNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ClinicalNote::class);
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patient,id'],
            'dentist_id' => ['required', 'integer', 'exists:dentist,id'],
            'appointment_id' => ['sometimes', 'nullable', 'integer', 'exists:appointment,id'],
            'note_text' => ['required', 'string', 'max:16000'],
            'author_user_id' => ['missing'],
            'updated_by_user_id' => ['missing'],
            'deleted_by_user_id' => ['missing'],
            'deleted_at' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
        ];
    }
}
