<?php

namespace App\Billing;

use InvalidArgumentException;

/**
 * Validates the shape and line ceiling of explicit responsibility candidates.
 * This is not finance approval, liability establishment, persistence, or posting.
 */
final class SettlementV2ResponsibilityRules
{
    private const PAYER_KINDS = ['patient', 'insurer', 'unresolved'];

    /**
     * Validate a proposed explicit responsibility partition for one issued line.
     * Existing rows are supplied as context; this method does not read or write records.
     */
    public static function candidateAmountMinor(array $candidate, array $line, array $existingAssignments = []): int
    {
        if (! array_is_list($existingAssignments)) {
            throw new InvalidArgumentException('Existing responsibility assignments must be a list.');
        }
        self::requireKeys($line, ['tenant_id', 'patient_id', 'invoice_id', 'invoice_item_id', 'invoice_status', 'currency', 'line_total']);
        if ($line['invoice_status'] !== 'issued') {
            throw new InvalidArgumentException('Responsibility candidates require an issued invoice.');
        }
        self::assertCurrency($line['currency']);
        $lineCharge = self::moneyMinor($line['line_total']);
        if ($lineCharge < 1) {
            throw new InvalidArgumentException('Responsibility candidates require a positive invoice-line charge.');
        }

        $totalAssigned = 0;
        $seenPayers = [];
        $insurerCount = 0;
        $candidateAmount = null;
        foreach ([...$existingAssignments, $candidate] as $index => $assignment) {
            if (! is_array($assignment)) {
                throw new InvalidArgumentException('Responsibility assignment context must be structured.');
            }
            $amount = self::validateAssignment($assignment, $line);
            $payerKey = $assignment['payer_kind'].':'.($assignment['payer_id'] ?? 'unresolved');
            if (isset($seenPayers[$payerKey])) {
                throw new InvalidArgumentException('Duplicate responsibility assignments for the same payer are not allowed.');
            }
            $seenPayers[$payerKey] = true;
            if ($assignment['payer_kind'] === 'insurer' && ++$insurerCount > 1) {
                throw new InvalidArgumentException('Multiple insurer responsibility assignments require an approved coordination policy.');
            }
            $totalAssigned += $amount;
            if ($index === count($existingAssignments)) {
                $candidateAmount = $amount;
            }
        }

        if ($totalAssigned > $lineCharge) {
            throw new InvalidArgumentException('Responsibility assignments cannot exceed the invoice-line charge.');
        }

        return $candidateAmount ?? throw new InvalidArgumentException('A responsibility candidate is required.');
    }

    private static function validateAssignment(array $assignment, array $line): int
    {
        self::requireKeys($assignment, ['tenant_id', 'patient_id', 'invoice_id', 'invoice_item_id', 'currency', 'payer_kind', 'amount']);
        foreach (['tenant_id', 'patient_id', 'invoice_id', 'invoice_item_id'] as $key) {
            if (! self::samePositiveId($assignment[$key], $line[$key])) {
                throw new InvalidArgumentException('Responsibility assignment must match tenant, patient, invoice, and invoice line.');
            }
        }
        self::assertCurrency($assignment['currency']);
        if ($assignment['currency'] !== $line['currency']) {
            throw new InvalidArgumentException('Responsibility currency must match the issued invoice.');
        }
        if (! in_array($assignment['payer_kind'], self::PAYER_KINDS, true)) {
            throw new InvalidArgumentException('An explicit supported payer kind is required.');
        }

        $amount = self::moneyMinor($assignment['amount']);
        if ($amount < 1) {
            throw new InvalidArgumentException('Responsibility amount must be positive.');
        }

        $obligation = $assignment['obligation'] ?? null;
        if ($assignment['payer_kind'] === 'patient') {
            if (! self::samePositiveId($assignment['payer_id'] ?? null, $line['patient_id']) || $obligation !== null) {
                throw new InvalidArgumentException('Patient responsibility must explicitly name the invoice patient and cannot cite an insurer obligation.');
            }
        } elseif ($assignment['payer_kind'] === 'insurer') {
            if (! self::isPositiveId($assignment['payer_id'] ?? null) || ! is_array($obligation)) {
                throw new InvalidArgumentException('Insurer responsibility requires an explicit insurer and approved-obligation reference.');
            }
            self::requireKeys($obligation, ['tenant_id', 'patient_id', 'invoice_id', 'invoice_item_id', 'insurer_id', 'currency', 'approved_amount', 'approved']);
            foreach (['tenant_id' => 'tenant_id', 'patient_id' => 'patient_id', 'invoice_id' => 'invoice_id', 'invoice_item_id' => 'invoice_item_id'] as $obligationKey => $lineKey) {
                if (! self::samePositiveId($obligation[$obligationKey], $line[$lineKey])) {
                    throw new InvalidArgumentException('Insurer obligation must match tenant, patient, invoice, and invoice line.');
                }
            }
            if (! self::samePositiveId($obligation['insurer_id'], $assignment['payer_id'])
                || $obligation['approved'] !== true
                || $obligation['currency'] !== $line['currency']) {
                throw new InvalidArgumentException('Insurer obligation approval, identity, and currency must match the assignment.');
            }
            $approvedAmount = self::moneyMinor($obligation['approved_amount']);
            if ($approvedAmount < 1 || $approvedAmount > self::moneyMinor($line['line_total']) || $amount > $approvedAmount) {
                throw new InvalidArgumentException('Insurer responsibility cannot exceed its approved obligation amount.');
            }
        } elseif (($assignment['payer_id'] ?? null) !== null || $obligation !== null) {
            throw new InvalidArgumentException('Unresolved responsibility cannot identify a patient, insurer, or obligation.');
        }

        return $amount;
    }

    private static function moneyMinor(mixed $amount): int
    {
        if (! is_string($amount) && ! is_int($amount)) {
            throw new InvalidArgumentException('Money must be supplied as an exact decimal string or integer.');
        }

        return Money::minor($amount);
    }

    private static function assertCurrency(mixed $currency): void
    {
        if (! is_string($currency) || ! preg_match('/\A[A-Z]{3}\z/', $currency)) {
            throw new InvalidArgumentException('Currency must be a three-letter uppercase code.');
        }
    }

    private static function samePositiveId(mixed $actual, mixed $expected): bool
    {
        return self::isPositiveId($actual) && self::isPositiveId($expected) && (string) $actual === (string) $expected;
    }

    private static function isPositiveId(mixed $value): bool
    {
        return (is_int($value) && $value > 0)
            || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1);
    }

    private static function requireKeys(array $values, array $keys): void
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $values)) {
                throw new InvalidArgumentException('Required responsibility context is missing: '.$key.'.');
            }
        }
    }
}
