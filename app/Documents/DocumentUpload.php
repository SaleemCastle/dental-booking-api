<?php

namespace App\Documents;

use App\Tenancy\TenantRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;

class DocumentUpload
{
    public static function validate(array $input): array
    {
        $validator = Validator::make($input, self::rules());
        self::checkFile($validator, $input);

        return $validator->validate();
    }

    public static function rules(): array
    {
        $rules = [
            'file' => ['required', 'file', 'max:'.config('patient_documents.max_size_kb'), 'mimetypes:'.implode(',', array_keys(config('patient_documents.mime_extensions')))],
            'appointment_id' => ['sometimes', 'nullable', 'integer', TenantRules::exists('appointment')],
            'clinical_note_id' => ['sometimes', 'nullable', 'integer', TenantRules::exists('clinical_notes')->whereNull('deleted_at')],
        ];
        foreach (['id', 'tenant_id', 'tenantId', 'patient_id', 'disk', 'storage_key', 'url', 'original_filename', 'mime_type', 'size_bytes', 'uploaded_by_user_id', 'archived_by_user_id', 'deleted_by_user_id', 'created_at', 'updated_at', 'archived_at', 'deleted_at', 'purged_at'] as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }

    public static function checkFile(ValidationValidator $validator, array $input): void
    {
        $validator->after(function ($validator) use ($input): void {
            foreach (array_diff(array_keys($input), ['file', 'appointment_id', 'clinical_note_id']) as $field) {
                $validator->errors()->add($field, 'This field cannot be supplied.');
            }
            $file = $input['file'] ?? null;
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                return;
            }
            $name = $file->getClientOriginalName();
            if (filesize($file->getRealPath()) < 1 || filesize($file->getRealPath()) > config('patient_documents.max_size_kb') * 1024
                || mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F\\\\\/]/u', $name)
                || ! in_array(strtolower($file->getClientOriginalExtension()), config('patient_documents.mime_extensions')[self::mimeType($file)] ?? [], true)) {
                $validator->errors()->add('file', 'Use a valid filename with an extension matching the detected file type.');
            }
        });

    }

    public static function mimeType(UploadedFile $file): string
    {
        return (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: 'application/octet-stream';
    }
}
