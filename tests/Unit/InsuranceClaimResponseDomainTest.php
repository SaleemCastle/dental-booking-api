<?php

namespace Tests\Unit;

use App\Http\Resources\InsuranceClaimResponseResource;
use App\Insurance\InsuranceClaimAccessPolicy;
use App\Insurance\InsuranceClaimLifecycle;
use App\Models\InsuranceClaimAppealEvent;
use App\Models\InsuranceClaimAppealResponse;
use App\Models\InsuranceClaimAppealSubmission;
use App\Models\InsuranceClaimResponse;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\InsuranceClaimResponseWorkflow;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use LogicException;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class InsuranceClaimResponseDomainTest extends TestCase
{
    public function test_claim_response_transition_rules_keep_rejection_distinct_from_denial(): void
    {
        $this->assertTrue(InsuranceClaimLifecycle::canRecordResponse('submitted', 'rejected'));
        $this->assertTrue(InsuranceClaimLifecycle::canRecordResponse('submitted', 'denied'));
        $this->assertFalse(InsuranceClaimLifecycle::canRecordResponse('approved', 'denied'));
        $this->assertFalse(InsuranceClaimLifecycle::canRecordResponse('cancelled', 'approved'));
    }

    public function test_only_coverage_denials_are_appealable_by_default(): void
    {
        $this->assertTrue(InsuranceClaimLifecycle::canAppeal('denied'));
        $this->assertFalse(InsuranceClaimLifecycle::canAppeal('rejected'));
        $this->assertFalse(InsuranceClaimLifecycle::canAppeal('partially_approved'));
        $this->assertFalse(InsuranceClaimLifecycle::canAppeal('approved'));
    }

    public function test_appeal_response_lifecycle_is_explicit(): void
    {
        $this->assertTrue(InsuranceClaimLifecycle::canRecordAppealResponse('submitted', 'acknowledged'));
        $this->assertTrue(InsuranceClaimLifecycle::canRecordAppealResponse('under_review', 'denied'));
        $this->assertFalse(InsuranceClaimLifecycle::canRecordAppealResponse('draft', 'approved'));
        $this->assertFalse(InsuranceClaimLifecycle::canRecordAppealResponse('denied', 'approved'));
        $this->assertTrue(InsuranceClaimLifecycle::canWithdrawAppeal('draft'));
        $this->assertTrue(InsuranceClaimLifecycle::canWithdrawAppeal('submitted'));
        $this->assertFalse(InsuranceClaimLifecycle::canWithdrawAppeal('under_review'));
        $this->assertFalse(InsuranceClaimLifecycle::canWithdrawAppeal('denied'));
    }

    public function test_correction_lineage_eligibility_is_limited_to_submitted_or_adverse_claims(): void
    {
        $this->assertTrue(InsuranceClaimLifecycle::canCorrect('submitted'));
        $this->assertTrue(InsuranceClaimLifecycle::canCorrect('rejected'));
        $this->assertTrue(InsuranceClaimLifecycle::canCorrect('denied'));
        $this->assertFalse(InsuranceClaimLifecycle::canCorrect('draft'));
        $this->assertFalse(InsuranceClaimLifecycle::canCorrect('approved'));
        $this->assertTrue(InsuranceClaimLifecycle::canCreateCorrection('denied', false));
        $this->assertFalse(InsuranceClaimLifecycle::canCreateCorrection('denied', true));
        $this->assertFalse(InsuranceClaimLifecycle::canCreateCorrection('draft', false));
    }

    public function test_amounts_are_informational_only_for_approval_decisions(): void
    {
        $this->assertTrue(InsuranceClaimLifecycle::canIncludeInformationalApprovedAmount('approved', true));
        $this->assertTrue(InsuranceClaimLifecycle::canIncludeInformationalApprovedAmount('partially_approved', true));
        $this->assertFalse(InsuranceClaimLifecycle::canIncludeInformationalApprovedAmount('denied', true));
        $this->assertFalse(InsuranceClaimLifecycle::canIncludeInformationalApprovedAmount('rejected', true));
        $this->assertTrue(InsuranceClaimLifecycle::canIncludeInformationalApprovedAmount('denied', false));
    }

    public function test_response_workflow_has_no_financial_service_dependencies(): void
    {
        $constructor = (new ReflectionClass(InsuranceClaimResponseWorkflow::class))->getConstructor();
        $dependencies = array_map(fn ($parameter) => $parameter->getType()->getName(), $constructor->getParameters());

        $this->assertSame([TenantContext::class, AuditLogService::class], $dependencies);
    }

    public function test_response_appeal_event_and_submission_models_reject_mutation(): void
    {
        foreach ([new InsuranceClaimResponse, new InsuranceClaimAppealEvent, new InsuranceClaimAppealSubmission, new InsuranceClaimAppealResponse] as $model) {
            $model->exists = true;
            try {
                $model->save();
                $this->fail('Persisted history must reject updates.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_manual_response_resource_identifies_staff_entry_and_limits_fields(): void
    {
        $response = (new InsuranceClaimResponse)->setDateFormat('Y-m-d H:i:s')->forceFill([
            'id' => 3, 'tenant_id' => 81, 'patient_id' => 5, 'claim_id' => 9, 'submission_id' => 12, 'claim_version' => 3,
            'response_type' => 'approved', 'insurer_response_at' => '2026-11-20 10:00:00', 'recorded_at' => '2026-11-20 11:00:00',
            'recorded_by_user_id' => 7, 'communication_channel' => 'portal', 'insurer_reference' => 'payer-ref', 'source_provenance' => 'Staff transcribed portal',
            'approved_amount' => '90.00', 'currency' => 'USD', 'encrypted_policy_identifier' => 'secret',
        ]);
        $data = (new InsuranceClaimResponseResource($response))->resolve(Request::create('/'));
        $this->assertTrue($data['staff_entered']);
        $this->assertSame('approved', $data['response_type']);
        $this->assertSame('90.00', $data['approved_amount']);
        $this->assertArrayNotHasKey('tenant_id', $data);
        $this->assertArrayNotHasKey('encrypted_policy_identifier', $data);
    }

    public function test_access_policy_requires_membership_patient_view_and_the_exact_ability(): void
    {
        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('isMemberOfCurrentTenant')->once()->andReturnTrue();
        $actor->shouldReceive('hasPermission')->with('patients.view')->once()->andReturnTrue();
        $actor->shouldReceive('hasPermission')->with('insurance_claims.responses.record')->once()->andReturnTrue();
        $this->assertTrue(InsuranceClaimAccessPolicy::allows($actor, 'responses.record'));

        $other = Mockery::mock(User::class);
        $other->shouldReceive('isMemberOfCurrentTenant')->once()->andReturnTrue();
        $other->shouldReceive('hasPermission')->with('patients.view')->once()->andReturnTrue();
        $other->shouldReceive('hasPermission')->with('insurance_claims.responses.record')->once()->andReturnFalse();
        $this->assertFalse(InsuranceClaimAccessPolicy::allows($other, 'responses.record'));

        $nonMember = Mockery::mock(User::class);
        $nonMember->shouldReceive('isMemberOfCurrentTenant')->once()->andReturnFalse();
        $this->assertFalse(InsuranceClaimAccessPolicy::allows($nonMember, 'responses.record'));

        $noPatientView = Mockery::mock(User::class);
        $noPatientView->shouldReceive('isMemberOfCurrentTenant')->once()->andReturnTrue();
        $noPatientView->shouldReceive('hasPermission')->with('patients.view')->once()->andReturnFalse();
        $this->assertFalse(InsuranceClaimAccessPolicy::allows($noPatientView, 'responses.record'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
