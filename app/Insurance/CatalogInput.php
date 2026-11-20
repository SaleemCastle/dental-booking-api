<?php

namespace App\Insurance;

use App\Tenancy\TenantRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CatalogInput
{
    public static function rules(string $operation, ?Model $record = null): array
    {
        $name = ['required', 'string', 'max:255', 'regex:/\\S/u'];
        $expected = ['required', 'integer', 'min:1', 'max:2147483646'];
        $create = str_starts_with($operation, 'store');
        if (in_array($operation, ['storeInsurer', 'updateInsurer', 'storePlan', 'updatePlan'], true)) {
            $rules = ['name' => $create ? $name : ['sometimes', ...$name], 'active' => ['sometimes', 'boolean']];
            if ($create) {
                $table = $operation === 'storeInsurer' ? 'insurers' : 'insurance_plans';
                $unique = TenantRules::unique($table, 'code');
                if ($table === 'insurance_plans') {
                    $unique->where('insurer_id', $record?->id);
                }
                $rules['code'] = ['required', 'string', 'regex:/\\A[A-Z0-9][A-Z0-9_-]{0,49}\\z/', $unique];
            } else {
                $rules['expected_version'] = $expected;
            }

            return $rules;
        }
        if (in_array($operation, ['storeVersion', 'updateVersion'], true)) {
            $rules = [
                'name' => $name,
                'currency' => ['required', 'string', 'regex:/\\A[A-Z]{3}\\z/'],
                'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:0001-01-01', 'before_or_equal:9999-12-31'],
                'effective_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:0001-01-01', 'before_or_equal:9999-12-31'],
                'source_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            ];
            if (! $create) {
                foreach ($rules as $field => $fieldRules) {
                    if (! in_array('sometimes', $fieldRules, true)) {
                        $rules[$field] = ['sometimes', ...$fieldRules];
                    }
                }
                $rules['expected_version'] = $expected;
            }

            return $rules;
        }

        return ['expected_version' => $expected];
    }

    public static function validate(string $operation, array $data, ?Model $record = null): array
    {
        $rules = self::rules($operation, $record);
        $unknown = array_diff(array_keys($data), array_keys($rules));
        if ($unknown) {
            throw ValidationException::withMessages([(string) reset($unknown) => ['Unsupported insurance catalog field.']]);
        }
        $data = Validator::make($data, $rules)->validate();
        if (in_array($operation, ['storeVersion', 'updateVersion'], true)) {
            $from = $data['effective_from'] ?? $record?->effective_from?->toDateString();
            $to = array_key_exists('effective_to', $data) ? $data['effective_to'] : $record?->effective_to?->toDateString();
            if ($to !== null && $to < $from) {
                throw ValidationException::withMessages(['effective_to' => ['Expiry cannot precede the effective date.']]);
            }
        }

        return $data;
    }
}
