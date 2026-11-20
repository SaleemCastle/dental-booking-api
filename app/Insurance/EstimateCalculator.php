<?php

namespace App\Insurance;

use App\Billing\Money;
use App\Models\InsuranceBenefitRule;

final class EstimateCalculator
{
    public const VERSION = 'p4-m3-a.1';

    public function calculate(int $gross, string $currency, ?InsuranceBenefitRule $rule): array
    {
        $base = ['calculator_version' => self::VERSION, 'gross_amount' => Money::decimal($gross), 'currency' => $currency,
            'benefit_rule_id' => $rule?->id, 'benefit_revision' => $rule?->revision,
            'benefit_coverage' => $rule?->coverage, 'benefit_type' => $rule?->benefit_type, 'benefit_scope' => $rule?->scope,
            'benefit_percentage' => $rule?->percentage, 'benefit_fixed_amount' => $rule?->amount,
            'benefit_currency' => $rule?->currency, 'procedure_limit' => $rule?->procedure_limit,
            'source' => $rule === null ? null : ['type' => $rule->source, 'plan_version_id' => $rule->plan_version_id,
                'association_id' => $rule->association_id, 'association_revision_id' => $rule->association_revision_id,
                'policy_revision_id' => $rule->policy_revision_id]];
        if ($rule === null) {
            return [...$base, 'status' => 'unresolved', 'insurer_amount' => null, 'patient_amount' => null,
                'reason_codes' => ['BENEFIT_NOT_SELECTED'], 'assumptions' => []];
        }
        if ($rule->coverage === 'excluded') {
            return [...$base, 'status' => 'excluded', 'insurer_amount' => '0.00', 'patient_amount' => Money::decimal($gross),
                'reason_codes' => ['PROCEDURE_EXCLUDED'], 'assumptions' => ['Explicit exclusion applies to this service date.']];
        }
        if (in_array($rule->coverage, ['unknown', 'unspecified'], true)
            || in_array($rule->benefit_type, ['unknown', 'unspecified'], true)) {
            return [...$base, 'status' => 'unresolved', 'insurer_amount' => null, 'patient_amount' => null,
                'reason_codes' => ['BENEFIT_UNKNOWN'], 'assumptions' => []];
        }
        if ($rule->scope === 'category') {
            return [...$base, 'status' => 'unresolved', 'insurer_amount' => null, 'patient_amount' => null,
                'reason_codes' => ['CATEGORY_MAPPING_NOT_SUPPLIED'], 'assumptions' => []];
        }
        if ((bool) $rule->prior_authorization_required) {
            return [...$base, 'status' => 'unresolved', 'insurer_amount' => null, 'patient_amount' => null,
                'reason_codes' => ['PRIOR_AUTHORIZATION_REQUIRED'], 'assumptions' => []];
        }
        if ((int) ($rule->waiting_period_days ?? 0) > 0) {
            return [...$base, 'status' => 'unresolved', 'insurer_amount' => null, 'patient_amount' => null,
                'reason_codes' => ['WAITING_PERIOD_UNRESOLVED'], 'assumptions' => []];
        }
        if ($rule->currency !== null && $rule->currency !== $currency) {
            return [...$base, 'status' => 'unresolved', 'insurer_amount' => null, 'patient_amount' => null,
                'reason_codes' => ['CURRENCY_MISMATCH'], 'assumptions' => []];
        }
        if ($rule->annual_limit !== null || $rule->visit_limit !== null) {
            return [...$base, 'status' => 'unresolved', 'insurer_amount' => null, 'patient_amount' => null,
                'reason_codes' => ['LIMIT_USAGE_UNKNOWN'], 'assumptions' => []];
        }
        $percentageParts = explode('.', (string) $rule->percentage, 2);
        $basisPoints = ((int) $percentageParts[0] * 100) + (int) str_pad($percentageParts[1] ?? '', 2, '0');
        $coverage = $rule->benefit_type === 'percentage'
            ? intdiv(($gross * $basisPoints) + 5000, 10000)
            : $this->minorOrNull((string) $rule->amount);
        if ($coverage === null) {
            return [...$base, 'status' => 'unresolved', 'insurer_amount' => null, 'patient_amount' => null,
                'reason_codes' => ['LIMIT_PRECISION_UNSUPPORTED'], 'assumptions' => []];
        }
        $applied = [$gross, $coverage];
        $trace = ['benefit_type' => $rule->benefit_type, 'percentage' => $rule->percentage,
            'fixed_amount' => $rule->amount, 'pre_limit_insurer_amount' => Money::decimal(min($gross, $coverage)), 'applied_limits' => []];
        foreach (['annual_limit', 'visit_limit', 'procedure_limit'] as $field) {
            if ($rule->{$field} !== null) {
                $limit = $this->minorOrNull((string) $rule->{$field});
                if ($limit === null) {
                    return [...$base, 'status' => 'unresolved', 'insurer_amount' => null, 'patient_amount' => null,
                        'reason_codes' => ['LIMIT_PRECISION_UNSUPPORTED'], 'assumptions' => []];
                }
                $applied[] = $limit;
                $trace['applied_limits'][$field] = Money::decimal($limit);
            }
        }
        $insurer = min($applied);
        $trace['insurer_amount'] = Money::decimal($insurer);
        $trace['patient_amount'] = Money::decimal($gross - $insurer);

        return [...$base, 'status' => 'resolved', 'insurer_amount' => $trace['insurer_amount'],
            'patient_amount' => $trace['patient_amount'], 'reason_codes' => [], 'assumptions' => [
            'One explicitly selected benefit rule is evaluated; a procedure limit is treated as an applicable per-service cap.',
                'No deductible, benefit usage, coordination-of-benefits, allowed-fee, or external adjudication is inferred.',
            ], 'trace' => $trace];
    }

    private function minorOrNull(string $value): ?int
    {
        try { return Money::minor($value); } catch (\InvalidArgumentException) { return null; }
    }
}
