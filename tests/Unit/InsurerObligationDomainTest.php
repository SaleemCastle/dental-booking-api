<?php

namespace Tests\Unit;

spl_autoload_register(static function (string $class): void {
    if (! str_starts_with($class, 'App\\')) {
        return;
    }
    $file = dirname(__DIR__, 2).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($file)) {
        require_once $file;
    }
}, prepend: true);

use App\Insurance\InsurerObligationAccessPolicy;
use App\Insurance\InsurerObligationRules;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class InsurerObligationDomainTest extends TestCase
{
    public function test_amounts_are_exact_positive_invoice_denominations(): void
    {
        self::assertSame('42.10', InsurerObligationRules::normalizeAmount('42.1'));
        self::assertNull(InsurerObligationRules::normalizeAmount('0'));
        self::assertNull(InsurerObligationRules::normalizeAmount('1.001'));
        self::assertNull(InsurerObligationRules::normalizeAmount('100000000.00'));
        self::assertNull(InsurerObligationRules::normalizeAmount('-1.00'));
        self::assertTrue(InsurerObligationRules::amountWithinInvoiceLine('25.00', '25.00'));
        self::assertTrue(InsurerObligationRules::amountWithinInvoiceLine('24.99', '25.00'));
        self::assertFalse(InsurerObligationRules::amountWithinInvoiceLine('25.01', '25.00'));
        self::assertFalse(InsurerObligationRules::amountWithinInvoiceLine('0.00', '25.00'));
    }

    public function test_claim_submission_and_decision_lineage_must_be_complete(): void
    {
        self::assertTrue(InsurerObligationRules::claimLineageConsistent(null, null, null));
        self::assertTrue(InsurerObligationRules::claimLineageConsistent(1, 2, null));
        self::assertTrue(InsurerObligationRules::claimLineageConsistent(1, 2, 3));
        self::assertFalse(InsurerObligationRules::claimLineageConsistent(1, null, null));
        self::assertFalse(InsurerObligationRules::claimLineageConsistent(null, 2, null));
        self::assertFalse(InsurerObligationRules::claimLineageConsistent(1, null, 3));
    }

    public function test_approval_requires_unapproved_tenant_matched_line_and_currency(): void
    {
        self::assertTrue(InsurerObligationRules::mayApprove(false, true, true, true, true, true));
        self::assertFalse(InsurerObligationRules::mayApprove(true, true, true, true, true, true));
        self::assertFalse(InsurerObligationRules::mayApprove(false, false, true, true, true, true));
        self::assertFalse(InsurerObligationRules::mayApprove(false, true, false, true, true, true));
        self::assertFalse(InsurerObligationRules::mayApprove(false, true, true, false, true, true));
        self::assertFalse(InsurerObligationRules::mayApprove(false, true, true, true, false, true));
        self::assertFalse(InsurerObligationRules::mayApprove(false, true, true, true, true, false));
    }

    public function test_obligation_actions_require_exact_finance_and_read_permissions(): void
    {
        $actor = $this->createMock(User::class);
        $actor->expects(self::once())->method('isMemberOfCurrentTenant')->willReturn(true);
        $actor->expects(self::exactly(3))->method('hasPermission')->willReturnMap([
            ['insurance_obligations.approve', true],
            ['invoices.view', true],
            ['patients.view', true],
        ]);
        self::assertTrue(InsurerObligationAccessPolicy::allows($actor, 'approve'));

        $unprivileged = $this->createMock(User::class);
        $unprivileged->expects(self::never())->method('isMemberOfCurrentTenant');
        $unprivileged->expects(self::never())->method('hasPermission');
        self::assertFalse(InsurerObligationAccessPolicy::allows($unprivileged, '*'));

        $inactive = $this->createMock(User::class);
        $inactive->expects(self::once())->method('isMemberOfCurrentTenant')->willReturn(false);
        $inactive->expects(self::never())->method('hasPermission');
        self::assertFalse(InsurerObligationAccessPolicy::allows($inactive, 'view'));
    }
}
