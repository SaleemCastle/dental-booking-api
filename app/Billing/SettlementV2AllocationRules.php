<?php

namespace App\Billing;

use InvalidArgumentException;

/** Pure ceiling checks for a future allocator; this class never records or posts money. */
final class SettlementV2AllocationRules
{
    public static function amountMinor(
        string $amount,
        string $availableCash,
        string $eligibleOutstanding,
        string $paymentCurrency,
        string $invoiceCurrency,
    ): int {
        foreach ([$paymentCurrency, $invoiceCurrency] as $currency) {
            if (! preg_match('/\A[A-Z]{3}\z/', $currency)) {
                throw new InvalidArgumentException('Settlement currency must be a three-letter uppercase code.');
            }
        }
        if ($paymentCurrency !== $invoiceCurrency) {
            throw new InvalidArgumentException('Settlement currencies must match.');
        }

        $minor = Money::minor($amount);
        if ($minor < 1 || $minor > Money::minor($availableCash) || $minor > Money::minor($eligibleOutstanding)) {
            throw new InvalidArgumentException('Allocation must be positive and within available cash and eligible outstanding.');
        }

        return $minor;
    }
}
