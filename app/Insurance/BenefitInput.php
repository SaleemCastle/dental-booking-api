<?php

namespace App\Insurance;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BenefitInput
{
    public static function validate(array $input, bool $plan): array
    {
        $text = ['nullable', 'string', 'regex:/\S/u'];
        $money = ['nullable', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'];
        $rules = [
            'source' => ['required', Rule::in($plan ? ['insurer_plan'] : ['patient_reported', 'staff_observation'])],
            'scope' => ['required', Rule::in(['all', 'category', 'procedure'])],
            'category' => [...$text, 'max:255'], 'procedure_code' => [...$text, 'max:100'], 'code_system' => [...$text, 'max:100'],
            'coverage' => ['required', Rule::in(['covered', 'excluded', 'unknown', 'unspecified'])],
            'benefit_type' => ['required', Rule::in(['percentage', 'fixed', 'unknown', 'unspecified'])],
            'percentage' => ['nullable', 'string', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/', 'numeric', 'between:0,100'],
            'amount' => $money, 'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'annual_limit' => $money, 'visit_limit' => $money, 'procedure_limit' => $money,
            'waiting_period_days' => ['nullable', 'integer', 'between:0,2147483647'],
            'prior_authorization_required' => ['nullable', 'boolean'],
            'service_from' => ['nullable', 'date_format:Y-m-d'], 'service_to' => ['nullable', 'date_format:Y-m-d'],
            'observed_at' => ['required', 'date', 'before_or_equal:now', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/'],
            'provenance' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
            'reference' => [...$text, 'max:255'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
            'supersedes_id' => ['nullable', 'integer', 'min:1'],
            ...($plan ? ['expected_version' => ['required', 'integer', 'min:1']] : ['policy_revision_id' => ['nullable', 'integer', 'min:1']]),
        ];
        self::only($input, array_keys($rules));
        $data = Validator::make($input, $rules)->validate();
        $fail = fn ($field, $message) => throw ValidationException::withMessages([$field => [$message]]);
        foreach (['category', 'procedure_code', 'code_system'] as $field) {
            $required = $field === 'category' ? $data['scope'] === 'category' : $data['scope'] === 'procedure';
            if ($required !== isset($data[$field])) {
                $fail($field, 'Supply only the target fields required by the selected scope.');
            }
        }
        foreach (['percentage' => 'percentage', 'amount' => 'fixed'] as $field => $type) {
            if (($data['benefit_type'] === $type) !== isset($data[$field])) {
                $fail($field, 'Supply only the value required by the selected benefit type.');
            }
        }
        if ($data['coverage'] !== 'covered' && ! in_array($data['benefit_type'], ['unknown', 'unspecified'], true)) {
            $fail('benefit_type', 'Only explicitly covered benefits may have a percentage or fixed amount.');
        }
        if (isset($data['procedure_limit']) && $data['scope'] !== 'procedure') {
            $fail('procedure_limit', 'A procedure-specific limit requires a procedure target.');
        }
        if (array_filter(array_intersect_key($data, array_flip(['amount', 'annual_limit', 'visit_limit', 'procedure_limit'])), fn ($v) => $v !== null) !== [] && ! isset($data['currency'])) {
            $fail('currency', 'An explicit currency is required for monetary values.');
        }
        if (isset($data['service_from'], $data['service_to']) && $data['service_from'] > $data['service_to']) {
            $fail('service_to', 'The end date must not precede the start date.');
        }

        return $data;
    }

    public static function only(array $input, array $allowed): void
    {
        foreach (array_diff(array_keys($input), $allowed) as $field) {
            throw ValidationException::withMessages([$field => ['Unsupported benefit field.']]);
        }
    }
}
