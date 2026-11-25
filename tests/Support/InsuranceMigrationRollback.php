<?php

namespace Tests\Support;

use Illuminate\Database\Migrations\Migration;

final class InsuranceMigrationRollback
{
    private const MIGRATIONS = [
        '2026_11_13_000001_create_insurance_catalog.php',
        '2026_11_14_000001_protect_document_evidence.php',
        '2026_11_15_000001_create_patient_insurance.php',
        '2026_11_16_000001_create_insurance_benefit_rules.php',
        '2026_11_16_000002_create_insurance_estimates.php',
        '2026_11_17_000001_link_estimates_to_treatment_plan_items.php',
        '2026_11_18_000001_create_insurance_claims.php',
        '2026_11_19_000001_create_prior_authorizations.php',
        '2026_11_20_000001_extend_claim_responses_appeals.php',
        '2026_11_21_000001_create_insurer_remittance_advices.php',
        '2026_11_21_000002_create_insurer_receipt_register.php',
        '2026_11_21_000003_create_insurer_obligation_register.php',
        '2026_11_22_000001_create_settlement_v2_foundation.php',
        '2026_11_23_000001_add_insurance_reporting_indexes.php',
    ];

    /** @return list<Migration> Migrations in the order they were rolled back. */
    public static function rollbackAfter(string $migrationFilename): array
    {
        $targetIndex = array_search($migrationFilename, self::MIGRATIONS, true);
        if ($targetIndex === false) {
            throw new \InvalidArgumentException('The migration target is not in the insurance migration chain.');
        }

        $remaining = array_slice(self::MIGRATIONS, $targetIndex + 1);
        $migrations = array_map(fn (string $filename): Migration => require database_path('migrations/'.$filename), $remaining);
        $rolledBack = array_reverse($migrations);
        foreach ($rolledBack as $migration) {
            $migration->down();
        }

        return $rolledBack;
    }

    /** @param list<Migration> $rolledBack */
    public static function restore(array $rolledBack): void
    {
        foreach (array_reverse($rolledBack) as $migration) {
            $migration->up();
        }
    }
}
