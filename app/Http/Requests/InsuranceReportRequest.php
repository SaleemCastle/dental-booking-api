<?php

namespace App\Http\Requests;

use App\Insurance\InsuranceReportAccessPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class InsuranceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null && InsuranceReportAccessPolicy::allows($actor);
    }

    public function rules(): array
    {
        return [
            'date_from' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'date_to' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $from = CarbonImmutable::parse($this->input('date_from'), config('app.timezone'));
            $to = CarbonImmutable::parse($this->input('date_to'), config('app.timezone'));
            if ($to->lt($from)) {
                $validator->errors()->add('date_to', 'The end date must be on or after the start date.');
            } elseif ($from->diffInDays($to) > 365) {
                $validator->errors()->add('date_to', 'Reports support at most 366 inclusive calendar dates.');
            }
        });
    }
}
