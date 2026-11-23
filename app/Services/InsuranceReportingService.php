<?php

namespace App\Services;

use App\Insurance\InsuranceReportAccessPolicy;
use App\Models\InsuranceBenefitRule;
use App\Models\InsuranceClaim;
use App\Models\InsuranceClaimAppeal;
use App\Models\InsuranceEstimate;
use App\Models\InsurancePriorAuthorization;
use App\Models\InsuranceRemittanceAdvice;
use App\Models\InsurerObligation;
use App\Models\InsurerReceipt;
use App\Models\PatientInsuranceCheck;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InsuranceReportingService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function summary(array $filters, User $actor): array
    {
        if (! InsuranceReportAccessPolicy::allows($actor)) {
            throw new HttpException(403);
        }

        $from = CarbonImmutable::parse($filters['date_from'], config('app.timezone'))->startOfDay();
        $until = CarbonImmutable::parse($filters['date_to'], config('app.timezone'))->startOfDay()->addDay();

        $claims = $this->counts(InsuranceClaim::query()->where('created_at', '>=', $from)->where('created_at', '<', $until), 'status', InsuranceClaim::STATUSES);
        $appeals = $this->counts(InsuranceClaimAppeal::query()->where('created_at', '>=', $from)->where('created_at', '<', $until), 'status', InsuranceClaimAppeal::STATUSES);
        $authorizations = $this->counts(InsurancePriorAuthorization::query()->where('created_at', '>=', $from)->where('created_at', '<', $until), 'status', InsurancePriorAuthorization::STATUSES);
        $estimates = $this->counts(InsuranceEstimate::query()->where('recorded_at', '>=', $from)->where('recorded_at', '<', $until), 'status', ['resolved', 'unresolved', 'excluded']);
        $eligibility = $this->counts(PatientInsuranceCheck::query()->where('checked_at', '>=', $from)->where('checked_at', '<', $until), 'outcome', ['unknown', 'active', 'inactive', 'unable_to_verify']);
        $benefits = $this->counts(InsuranceBenefitRule::query()->where('recorded_at', '>=', $from)->where('recorded_at', '<', $until), 'source', ['insurer_plan', 'patient_reported', 'staff_observation']);

        $remittances = InsuranceRemittanceAdvice::query()->where('recorded_at', '>=', $from)->where('recorded_at', '<', $until)->count();
        $receipts = InsurerReceipt::query()->where('recorded_at', '>=', $from)->where('recorded_at', '<', $until);
        $receiptCount = (clone $receipts)->count();
        $verifiedReceiptCount = (clone $receipts)->whereHas('verification')->count();
        $obligations = InsurerObligation::query()->where('proposed_at', '>=', $from)->where('proposed_at', '<', $until);
        $obligationCount = (clone $obligations)->count();
        $approvedObligationCount = (clone $obligations)->whereHas('approval')->count();

        $this->audit->record('insurance_report.viewed', 'insurance_reports', 'summary', $actor->id);

        return [
            'recorded_window' => ['from' => $filters['date_from'], 'to' => $filters['date_to']],
            'eligibility_checks' => ['by_outcome' => $eligibility],
            'benefit_rules' => ['by_source' => $benefits],
            'estimates' => ['by_status' => $estimates],
            'claims' => ['by_current_status' => $claims],
            'appeals' => ['by_current_status' => $appeals],
            'prior_authorizations' => ['by_current_status' => $authorizations],
            'remittance_advice' => ['recorded_count' => $remittances],
            'insurer_receipts' => [
                'recorded_count' => $receiptCount,
                'verified_count' => $verifiedReceiptCount,
                'unverified_count' => $receiptCount - $verifiedReceiptCount,
            ],
            'insurer_obligations' => [
                'proposed_count' => $obligationCount,
                'approved_count' => $approvedObligationCount,
                'pending_approval_count' => $obligationCount - $approvedObligationCount,
            ],
        ];
    }

    private function counts(Builder $query, string $column, array $values): array
    {
        $counts = $query->selectRaw($column.', COUNT(*) AS total')->groupBy($column)->pluck('total', $column);

        return collect($values)->mapWithKeys(fn (string $value) => [$value => (int) $counts->get($value, 0)])->all();
    }
}
