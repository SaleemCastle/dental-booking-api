<?php

namespace App\Billing;

use InvalidArgumentException;

final class Money
{
    public const MAX_MINOR = 9999999999;

    public static function minor(string|int|float $amount): int
    {
        $value = (string) $amount;
        if (! preg_match('/\A(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?\z/', $value, $parts)) {
            throw new InvalidArgumentException('Money requires a nonnegative decimal with at most two decimal places.');
        }

        return ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
