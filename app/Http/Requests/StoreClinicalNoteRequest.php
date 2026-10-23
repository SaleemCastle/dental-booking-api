<?php

namespace App\Http\Requests;

use App\Models\ClinicalNote;
use App\Tenancy\TenantRules;
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
            'patient_id' => ['required', 'integer', TenantRules::exists('patient')],
            'dentist_id' => ['required', 'integer', TenantRules::exists('dentist')],
            'appointment_id' => ['sometimes', 'nullable', 'integer', TenantRules::exists('appointment')],
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
