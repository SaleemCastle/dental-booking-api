<?php

namespace Tests\Unit;

use App\Insurance\InsurerReceiptAccessPolicy;
use App\Insurance\InsurerReceiptRules;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class InsurerReceiptDomainTest extends TestCase
{
    public function test_exact_amount_and_observation_requirements(): void
    {
        self::assertSame('42.10', InsurerReceiptRules::amount('42.1'));
        self::assertSame('0.00', InsurerReceiptRules::amount('0'));
        self::assertNull(InsurerReceiptRules::amount('1.001'));
        self::assertNull(InsurerReceiptRules::amount('1000000000000'));
        self::assertNull(InsurerReceiptRules::amount(null));
        self::assertTrue(InsurerReceiptRules::canVerify(false, true, true, true, true));
        self::assertFalse(InsurerReceiptRules::canVerify(true, true, true, true, true));
        self::assertFalse(InsurerReceiptRules::canVerify(false, false, true, true, true));
        self::assertFalse(InsurerReceiptRules::canVerify(false, true, true, false, true));
        self::assertFalse(InsurerReceiptRules::canVerify(false, true, true, true, false));
    }

    public function test_exact_permissions_and_membership_are_required(): void
    {
        $actor = $this->createMock(User::class);
        $actor->expects(self::once())->method('isMemberOfCurrentTenant')->willReturn(true);
        $actor->expects(self::once())->method('hasPermission')->with('insurance_receipts.record')->willReturn(true);
        self::assertTrue(InsurerReceiptAccessPolicy::allows($actor, 'record'));
        self::assertFalse(InsurerReceiptAccessPolicy::allows($actor, '*'));

        $inactive = $this->createMock(User::class);
        $inactive->expects(self::once())->method('isMemberOfCurrentTenant')->willReturn(false);
        $inactive->expects(self::never())->method('hasPermission');
        self::assertFalse(InsurerReceiptAccessPolicy::allows($inactive, 'view'));

        $unprivileged = $this->createMock(User::class);
        $unprivileged->expects(self::once())->method('isMemberOfCurrentTenant')->willReturn(true);
        $unprivileged->expects(self::once())->method('hasPermission')->with('insurance_receipts.verify')->willReturn(false);
        self::assertFalse(InsurerReceiptAccessPolicy::allows($unprivileged, 'verify'));
    }
}
