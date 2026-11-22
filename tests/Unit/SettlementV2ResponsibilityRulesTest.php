<?php

namespace Tests\Unit;

use App\Billing\SettlementV2ResponsibilityRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SettlementV2ResponsibilityRulesTest extends TestCase
{
    public function test_accepts_an_explicit_partial_patient_assignment_without_filling_the_remainder(): void
    {
        $candidate = $this->patientAssignment('25.25');

        self::assertSame(2525, SettlementV2ResponsibilityRules::candidateAmountMinor($candidate, $this->line()));
    }

    public function test_accepts_structurally_matched_insurer_obligation_as_a_candidate_only(): void
    {
        $candidate = [
            'tenant_id' => 1, 'patient_id' => 2, 'invoice_id' => 3, 'invoice_item_id' => 4,
            'currency' => 'USD', 'payer_kind' => 'insurer', 'payer_id' => 8, 'amount' => '30.00',
            'obligation' => [
                'tenant_id' => 1, 'patient_id' => 2, 'invoice_id' => 3, 'invoice_item_id' => 4,
                'insurer_id' => 8, 'currency' => 'USD', 'approved_amount' => '35.00', 'approved' => true,
            ],
        ];

        self::assertSame(3000, SettlementV2ResponsibilityRules::candidateAmountMinor($candidate, $this->line()));
    }

    #[DataProvider('invalidLineContextProvider')]
    public function test_rejects_wrong_invoice_lifecycle_or_tenant_patient_invoice_line_and_currency(array $line): void
    {
        $this->expectException(InvalidArgumentException::class);

        SettlementV2ResponsibilityRules::candidateAmountMinor($this->patientAssignment('10.00'), $line);
    }

    public static function invalidLineContextProvider(): array
    {
        return [
            'draft invoice' => [['tenant_id' => 1, 'patient_id' => 2, 'invoice_id' => 3, 'invoice_item_id' => 4, 'invoice_status' => 'draft', 'currency' => 'USD', 'line_total' => '100.00']],
            'partially paid invoice' => [['tenant_id' => 1, 'patient_id' => 2, 'invoice_id' => 3, 'invoice_item_id' => 4, 'invoice_status' => 'partially_paid', 'currency' => 'USD', 'line_total' => '100.00']],
            'different tenant' => [['tenant_id' => 9, 'patient_id' => 2, 'invoice_id' => 3, 'invoice_item_id' => 4, 'invoice_status' => 'issued', 'currency' => 'USD', 'line_total' => '100.00']],
            'different patient' => [['tenant_id' => 1, 'patient_id' => 9, 'invoice_id' => 3, 'invoice_item_id' => 4, 'invoice_status' => 'issued', 'currency' => 'USD', 'line_total' => '100.00']],
            'different invoice' => [['tenant_id' => 1, 'patient_id' => 2, 'invoice_id' => 9, 'invoice_item_id' => 4, 'invoice_status' => 'issued', 'currency' => 'USD', 'line_total' => '100.00']],
            'different line' => [['tenant_id' => 1, 'patient_id' => 2, 'invoice_id' => 3, 'invoice_item_id' => 9, 'invoice_status' => 'issued', 'currency' => 'USD', 'line_total' => '100.00']],
            'currency mismatch' => [['tenant_id' => 1, 'patient_id' => 2, 'invoice_id' => 3, 'invoice_item_id' => 4, 'invoice_status' => 'issued', 'currency' => 'JMD', 'line_total' => '100.00']],
        ];
    }

    #[DataProvider('invalidAmountProvider')]
    public function test_rejects_negative_zero_fractional_and_excessive_amounts(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        SettlementV2ResponsibilityRules::candidateAmountMinor($this->patientAssignment($amount), $this->line());
    }

    public static function invalidAmountProvider(): array
    {
        return [
            'negative' => ['-1.00'],
            'zero' => ['0.00'],
            'fractional minor unit' => ['1.001'],
            'above line total' => ['100.01'],
        ];
    }

    public function test_rejects_duplicate_and_conflicting_payer_assignments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SettlementV2ResponsibilityRules::candidateAmountMinor(
            $this->patientAssignment('20.00'),
            $this->line(),
            [$this->patientAssignment('20.00')],
        );
    }

    public function test_rejects_cumulative_responsibility_above_the_line_charge(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SettlementV2ResponsibilityRules::candidateAmountMinor(
            $this->patientAssignment('60.00'),
            $this->line(),
            [$this->patientAssignment('40.01')],
        );
    }

    public function test_allows_explicit_patient_and_insurer_candidates_within_the_line_ceiling(): void
    {
        $insurerAssignment = [
            'tenant_id' => 1, 'patient_id' => 2, 'invoice_id' => 3, 'invoice_item_id' => 4,
            'currency' => 'USD', 'payer_kind' => 'insurer', 'payer_id' => 8, 'amount' => '30.00',
            'obligation' => [
                'tenant_id' => 1, 'patient_id' => 2, 'invoice_id' => 3, 'invoice_item_id' => 4,
                'insurer_id' => 8, 'currency' => 'USD', 'approved_amount' => '35.00', 'approved' => true,
            ],
        ];

        self::assertSame(6000, SettlementV2ResponsibilityRules::candidateAmountMinor(
            $this->patientAssignment('60.00'),
            $this->line(),
            [$insurerAssignment],
        ));
    }

    public function test_rejects_multiple_insurers_until_secondary_coordination_is_approved(): void
    {
        $firstInsurer = $this->insurerAssignment(8, '20.00');
        $this->expectException(InvalidArgumentException::class);

        SettlementV2ResponsibilityRules::candidateAmountMinor(
            $this->insurerAssignment(9, '20.00'),
            $this->line(),
            [$firstInsurer],
        );
    }

    public function test_requires_insurer_obligation_lineage_to_match_exactly(): void
    {
        $candidate = [
            'tenant_id' => 1, 'patient_id' => 2, 'invoice_id' => 3, 'invoice_item_id' => 4,
            'currency' => 'USD', 'payer_kind' => 'insurer', 'payer_id' => 8, 'amount' => '30.00',
            'obligation' => [
                'tenant_id' => 1, 'patient_id' => 2, 'invoice_id' => 3, 'invoice_item_id' => 5,
                'insurer_id' => 8, 'currency' => 'USD', 'approved_amount' => '35.00', 'approved' => true,
            ],
        ];
        $this->expectException(InvalidArgumentException::class);

        SettlementV2ResponsibilityRules::candidateAmountMinor($candidate, $this->line());
    }

    #[DataProvider('invalidObligationProvider')]
    public function test_rejects_mismatched_or_insufficient_obligation_context(string $field, mixed $value): void
    {
        $candidate = $this->insurerAssignment(8, '30.00');
        $candidate['obligation'][$field] = $value;
        $this->expectException(InvalidArgumentException::class);

        SettlementV2ResponsibilityRules::candidateAmountMinor($candidate, $this->line());
    }

    public static function invalidObligationProvider(): array
    {
        return [
            'tenant mismatch' => ['tenant_id', 9],
            'patient mismatch' => ['patient_id', 9],
            'invoice mismatch' => ['invoice_id', 9],
            'currency mismatch' => ['currency', 'JMD'],
            'insurer mismatch' => ['insurer_id', 9],
            'not approved' => ['approved', false],
            'amount too low' => ['approved_amount', '29.99'],
            'approval exceeds line' => ['approved_amount', '100.01'],
        ];
    }

    private function line(): array
    {
        return [
            'tenant_id' => 1, 'patient_id' => 2, 'invoice_id' => 3, 'invoice_item_id' => 4,
            'invoice_status' => 'issued', 'currency' => 'USD', 'line_total' => '100.00',
        ];
    }

    private function patientAssignment(string $amount): array
    {
        return [
            'tenant_id' => 1, 'patient_id' => 2, 'invoice_id' => 3, 'invoice_item_id' => 4,
            'currency' => 'USD', 'payer_kind' => 'patient', 'payer_id' => 2, 'amount' => $amount,
        ];
    }

    private function insurerAssignment(int $insurerId, string $amount): array
    {
        return [
            'tenant_id' => 1, 'patient_id' => 2, 'invoice_id' => 3, 'invoice_item_id' => 4,
            'currency' => 'USD', 'payer_kind' => 'insurer', 'payer_id' => $insurerId, 'amount' => $amount,
            'obligation' => [
                'tenant_id' => 1, 'patient_id' => 2, 'invoice_id' => 3, 'invoice_item_id' => 4,
                'insurer_id' => $insurerId, 'currency' => 'USD', 'approved_amount' => '35.00', 'approved' => true,
            ],
        ];
    }
}
