<?php

namespace Tests\Unit;

use App\Insurance\InsuranceReportAccessPolicy;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\InsuranceReportingService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InsuranceReportAccessPolicyTest extends TestCase
{
    public function test_active_tenant_membership_and_both_exact_permissions_are_required(): void
    {
        $actor = $this->createMock(User::class);
        $actor->expects(self::once())->method('isMemberOfCurrentTenant')->willReturn(true);
        $actor->expects(self::exactly(2))->method('hasPermission')->willReturnMap([
            ['patients.view', true],
            ['insurance_reports.view', true],
        ]);

        self::assertTrue(InsuranceReportAccessPolicy::allows($actor));
    }

    public function test_wildcard_access_does_not_grant_insurance_reporting(): void
    {
        $actor = $this->createMock(User::class);
        $actor->expects(self::once())->method('isMemberOfCurrentTenant')->willReturn(true);
        $actor->expects(self::exactly(2))->method('hasPermission')->willReturnMap([
            ['patients.view', true],
            ['insurance_reports.view', false],
        ]);

        self::assertFalse(InsuranceReportAccessPolicy::allows($actor));
    }

    public function test_inactive_tenant_membership_denies_before_permission_checks(): void
    {
        $actor = $this->createMock(User::class);
        $actor->expects(self::once())->method('isMemberOfCurrentTenant')->willReturn(false);
        $actor->expects(self::never())->method('hasPermission');

        self::assertFalse(InsuranceReportAccessPolicy::allows($actor));
    }

    public function test_reporting_service_rechecks_permission_before_reading_or_auditing(): void
    {
        $actor = $this->createMock(User::class);
        $actor->method('isMemberOfCurrentTenant')->willReturn(true);
        $actor->method('hasPermission')->willReturn(false);
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects(self::never())->method('record');

        $this->expectException(HttpException::class);
        (new InsuranceReportingService($audit))->summary([], $actor);
    }
}
