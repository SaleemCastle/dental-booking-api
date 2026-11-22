<?php

namespace Tests\Unit;

use App\Billing\SettlementV2AllocationRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SettlementV2AllocationRulesTest extends TestCase
{
    public function test_accepts_exact_decimal_amount_within_both_ceilings(): void
    {
        $this->assertSame(1250, SettlementV2AllocationRules::amountMinor('12.50', '20.00', '15.00', 'USD', 'USD'));
    }

    #[DataProvider('invalidAllocationProvider')]
    public function test_rejects_invalid_amount_currency_or_ceiling(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);

        SettlementV2AllocationRules::amountMinor(...$arguments);
    }

    public static function invalidAllocationProvider(): array
    {
        return [
            'zero amount' => [['0.00', '20.00', '15.00', 'USD', 'USD']],
            'over available cash' => [['20.01', '20.00', '30.00', 'USD', 'USD']],
            'over eligible outstanding' => [['15.01', '30.00', '15.00', 'USD', 'USD']],
            'currency mismatch' => [['1.00', '2.00', '2.00', 'USD', 'JMD']],
            'invalid currency format' => [['1.00', '2.00', '2.00', 'usd', 'USD']],
            'fractional minor unit' => [['1.001', '2.00', '2.00', 'USD', 'USD']],
        ];
    }
}
