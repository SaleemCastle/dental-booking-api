<?php

namespace App\Http\Requests;

class UpdateClinicalNoteRequest extends StoreClinicalNoteRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('clinical_note'));
    }

    public function rules(): array
    {
        $rules = parent::rules();

        foreach (['patient_id', 'dentist_id', 'note_text'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }
}
