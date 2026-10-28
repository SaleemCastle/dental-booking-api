<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Treatment;
use App\Tenancy\TenantRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $models = match ($this->route()->getActionMethod()) {
            'billing' => [Invoice::class, Payment::class],
            'treatments' => [Appointment::class, Treatment::class],
            default => [Appointment::class],
        };

        return collect($models)->every(fn (string $model) => $this->user()->can('viewAny', $model));
    }

    public function rules(): array
    {
        return [
            'date_from' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'date_to' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'dentist_id' => ['sometimes', 'required', 'integer', TenantRules::exists('dentist')],
            'page' => ['sometimes', 'required', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'required', 'integer', 'between:1,100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $from = CarbonImmutable::parse($this->input('date_from'));
            $to = CarbonImmutable::parse($this->input('date_to'));
            if ($to->lt($from)) {
                $validator->errors()->add('date_to', 'The end date must be on or after the start date.');
            } elseif ($from->diffInDays($to) > 365) {
                $validator->errors()->add('date_to', 'Reports support at most 366 inclusive calendar dates.');
            }
        });
    }
}
