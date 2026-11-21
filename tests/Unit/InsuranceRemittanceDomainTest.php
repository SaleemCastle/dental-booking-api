<?php

namespace Tests\Unit;

use App\Insurance\InsuranceRemittanceAccessPolicy;
use App\Insurance\InsuranceRemittanceRules;
use App\Models\User;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class InsuranceRemittanceDomainTest extends TestCase
{
    public function test_amounts_are_exact_decimal_strings_and_unknown_values_stay_null(): void
    {
        $this->assertSame('0.00', InsuranceRemittanceRules::amount('0'));
        $this->assertSame('19.20', InsuranceRemittanceRules::amount('19.2'));
        $this->assertSame('999999999999.99', InsuranceRemittanceRules::amount('999999999999.99'));
        $this->assertSame('0.00', InsuranceRemittanceRules::amount('-0', true));
        $this->assertSame('0.00', InsuranceRemittanceRules::amount('-0.00', true));
        $this->assertSame('-12.35', InsuranceRemittanceRules::amount('-12.35', true));
        $this->assertNull(InsuranceRemittanceRules::amount(null));
    }

    public function test_amounts_reject_float_like_precision_and_negative_non_adjustment_values(): void
    {
        foreach (['1.001', '1000000000000.00', '1e2', '-1.00', '01.00'] as $amount) {
            try {
                InsuranceRemittanceRules::amount($amount);
                $this->fail('Expected amount to be rejected: '.$amount);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_correction_requires_same_tenant_and_insurer_and_no_existing_child(): void
    {
        $this->assertTrue(InsuranceRemittanceRules::correctionAllowed(true, true, false));
        $this->assertFalse(InsuranceRemittanceRules::correctionAllowed(false, true, false));
        $this->assertFalse(InsuranceRemittanceRules::correctionAllowed(true, false, false));
        $this->assertFalse(InsuranceRemittanceRules::correctionAllowed(true, true, true));
    }

    public function test_claim_submission_and_evidence_references_require_their_parent_chain(): void
    {
        $this->assertTrue(InsuranceRemittanceRules::lineReferencesConsistent(null, null, null));
        $this->assertTrue(InsuranceRemittanceRules::lineReferencesConsistent(7, null, null));
        $this->assertTrue(InsuranceRemittanceRules::lineReferencesConsistent(7, 9, 11));
        $this->assertFalse(InsuranceRemittanceRules::lineReferencesConsistent(null, 9, null));
        $this->assertFalse(InsuranceRemittanceRules::lineReferencesConsistent(7, null, 11));
    }

    public function test_external_line_references_are_unique_but_unknown_references_are_allowed(): void
    {
        $this->assertTrue(InsuranceRemittanceRules::uniqueLineReferences(['A-1', null, 'A-2']));
        $this->assertTrue(InsuranceRemittanceRules::uniqueLineReferences([null, null]));
        $this->assertFalse(InsuranceRemittanceRules::uniqueLineReferences(['A-1', 'A-1']));
        $this->assertFalse(InsuranceRemittanceRules::uniqueLineReferences([' A-1 ', 'A-1']));
        $this->assertFalse(InsuranceRemittanceRules::uniqueLineReferences(['0', '0']));
        $this->assertSame('INS-100', InsuranceRemittanceRules::normalizeReference('  INS-100  '));
    }

    public function test_access_policy_requires_membership_patient_view_and_exact_capability(): void
    {
        $actor = $this->createMock(User::class);
        $actor->expects($this->once())->method('isMemberOfCurrentTenant')->willReturn(true);
        $actor->expects($this->exactly(2))->method('hasPermission')->willReturnMap([
            ['patients.view', true],
            ['insurance_remittances.record', true],
        ]);

        $this->assertTrue(InsuranceRemittanceAccessPolicy::allows($actor, 'record'));
        $this->assertFalse(InsuranceRemittanceAccessPolicy::allows($actor, '*'));
    }

    public function test_access_policy_rejects_missing_membership_or_patient_scope(): void
    {
        $notMember = $this->createMock(User::class);
        $notMember->expects($this->once())->method('isMemberOfCurrentTenant')->willReturn(false);
        $notMember->expects($this->never())->method('hasPermission');
        $this->assertFalse(InsuranceRemittanceAccessPolicy::allows($notMember, 'view'));

        $noPatientView = $this->createMock(User::class);
        $noPatientView->expects($this->once())->method('isMemberOfCurrentTenant')->willReturn(true);
        $noPatientView->expects($this->once())->method('hasPermission')->with('patients.view')->willReturn(false);
        $this->assertFalse(InsuranceRemittanceAccessPolicy::allows($noPatientView, 'view'));
    }

    public function test_each_mutation_requires_its_exact_remittance_permission(): void
    {
        foreach (['view', 'record', 'correct'] as $action) {
            $actor = $this->createMock(User::class);
            $actor->method('isMemberOfCurrentTenant')->willReturn(true);
            $actor->expects($this->exactly(2))->method('hasPermission')->willReturnMap([
                ['patients.view', true],
                ['insurance_remittances.'.$action, true],
            ]);
            $this->assertTrue(InsuranceRemittanceAccessPolicy::allows($actor, $action));
        }

        $wrongCapability = $this->createMock(User::class);
        $wrongCapability->method('isMemberOfCurrentTenant')->willReturn(true);
        $wrongCapability->expects($this->exactly(2))->method('hasPermission')->willReturnMap([
            ['patients.view', true],
            ['insurance_remittances.record', true],
            ['insurance_remittances.correct', false],
        ]);
        $this->assertFalse(InsuranceRemittanceAccessPolicy::allows($wrongCapability, 'correct'));
    }
}
