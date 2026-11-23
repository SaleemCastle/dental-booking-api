<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Insurance reporting indexes require PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
CREATE INDEX insurance_claims_report_created ON insurance_claims(tenant_id,created_at);
CREATE INDEX insurance_claim_appeals_report_created ON insurance_claim_appeals(tenant_id,created_at);
CREATE INDEX insurance_prior_authorizations_report_created ON insurance_prior_authorizations(tenant_id,created_at);
CREATE INDEX insurance_estimates_report_recorded ON insurance_estimates(tenant_id,recorded_at);
CREATE INDEX patient_insurance_checks_report_checked ON patient_insurance_checks(tenant_id,checked_at);
CREATE INDEX insurance_benefit_rules_report_recorded ON insurance_benefit_rules(tenant_id,recorded_at);
CREATE INDEX insurance_remittance_advices_report_recorded ON insurance_remittance_advices(tenant_id,recorded_at);
CREATE INDEX insurer_receipts_report_recorded ON insurer_receipts(tenant_id,recorded_at);
CREATE INDEX insurer_obligations_report_proposed ON insurer_obligations(tenant_id,proposed_at);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX insurance_claims_report_created;
DROP INDEX insurance_claim_appeals_report_created;
DROP INDEX insurance_prior_authorizations_report_created;
DROP INDEX insurance_estimates_report_recorded;
DROP INDEX patient_insurance_checks_report_checked;
DROP INDEX insurance_benefit_rules_report_recorded;
DROP INDEX insurance_remittance_advices_report_recorded;
DROP INDEX insurer_receipts_report_recorded;
DROP INDEX insurer_obligations_report_proposed;
SQL);
    }
};
