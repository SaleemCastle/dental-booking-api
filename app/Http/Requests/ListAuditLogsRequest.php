<?php

namespace App\Http\Requests;

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListAuditLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', AuditLog::class);
    }

    public function rules(): array
    {
        $timestamp = '/\A\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/';

        return [
            'actor_id' => ['sometimes', 'integer', 'min:1', 'prohibited_if:actor_is_null,1,true'],
            'actor_is_null' => ['sometimes', 'boolean'],
            'action' => ['sometimes', 'string', 'max:80', 'regex:/\A[a-z][a-z0-9_.]{0,79}\z/'],
            'resource_type' => ['sometimes', 'string', 'max:80', 'regex:/\A[a-z][a-z0-9_]{0,79}\z/'],
            'resource_id' => ['sometimes', 'string', 'max:128', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/'],
            'request_id' => ['sometimes', 'string', 'max:100', 'regex:/\A[A-Za-z0-9._:-]{1,100}\z/'],
            'from' => ['sometimes', 'string', 'date', "regex:{$timestamp}"],
            'to' => ['sometimes', 'string', 'date', "regex:{$timestamp}"],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->has(['from', 'to']) && ! $validator->errors()->has('from') && ! $validator->errors()->has('to')
                && CarbonImmutable::parse($this->input('to'))->lessThan(CarbonImmutable::parse($this->input('from')))) {
                $validator->errors()->add('to', 'The end timestamp must be on or after the start timestamp.');
            }
        });
    }
}
