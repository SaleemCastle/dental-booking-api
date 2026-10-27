<?php

namespace App\Http\Requests;

use App\Documents\DocumentUpload;
use App\Models\PatientDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePatientDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PatientDocument::class);
    }

    public function rules(): array
    {
        return DocumentUpload::rules();
    }

    public function withValidator(Validator $validator): void
    {
        DocumentUpload::checkFile($validator, $this->all());
    }
}
