<?php

namespace App\Http\Requests;

use App\Models\ProviderAvailability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProviderAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', ProviderAvailability::class);
    }

    public function rules(): array
    {
        $rules = [
            'weekly_hours' => ['present', 'array', 'max:100'],
            'breaks' => ['present', 'array', 'max:100'],
            'exceptions' => ['present', 'array', 'max:366'],
            'exceptions.*' => ['required', 'array:date,working_hours,breaks'],
            'exceptions.*.date' => ['required', 'date_format:Y-m-d', 'distinct:strict'],
            'exceptions.*.working_hours' => ['present', 'array', 'max:24'],
            'exceptions.*.breaks' => ['present', 'array', 'max:24'],
            'tenant_id' => ['prohibited'],
            'dentist_id' => ['prohibited'],
            'timezone' => ['prohibited'],
            'configured' => ['prohibited'],
        ];

        foreach (['weekly_hours', 'breaks', 'exceptions.*.working_hours', 'exceptions.*.breaks'] as $path) {
            $weekly = in_array($path, ['weekly_hours', 'breaks'], true);
            $rules[$path.'.*'] = ['required', 'array:'.($weekly ? 'day_of_week,' : '').'start_time,end_time'];
            $rules[$path.'.*.start_time'] = ['required', 'date_format:H:i'];
            $rules[$path.'.*.end_time'] = ['required', 'regex:/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z|\A24:00\z/'];
            if ($weekly) {
                $rules[$path.'.*.day_of_week'] = ['required', 'integer', 'between:0,6'];
            }
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $groups = ['weekly_hours' => $this->input('weekly_hours'), 'breaks' => $this->input('breaks')];
            foreach ($this->input('exceptions') as $index => $exception) {
                foreach (['working_hours', 'breaks'] as $field) {
                    $groups["exceptions.$index.$field"] = $exception[$field];
                }
            }

            foreach ($groups as $path => $intervals) {
                if (! array_is_list($intervals)) {
                    $validator->errors()->add($path, 'Intervals must be a JSON list.');
                }
                foreach ($intervals as $index => $interval) {
                    if ($interval['end_time'] <= $interval['start_time']) {
                        $validator->errors()->add("$path.$index.end_time", 'End time must be after start time. Split overnight periods at midnight.');
                    }
                }
            }
            if (! array_is_list($this->input('exceptions'))) {
                $validator->errors()->add('exceptions', 'Exceptions must be a JSON list.');
            }
        });
    }
}
