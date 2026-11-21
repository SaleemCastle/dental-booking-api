<?php

namespace App\Insurance;

final class InsurerReceiptRules
{
    public static function amount(?string $amount): ?string
    {
        if ($amount === null || ! preg_match('/\A(?:0|[1-9][0-9]{0,11})(?:\.([0-9]{1,2}))?\z/', $amount)) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    public static function canVerify(bool $alreadyVerified, bool $hasProvenance, bool $sameTenant, bool $sameCurrency, bool $sameAmount): bool
    {
        return ! $alreadyVerified && $hasProvenance && $sameTenant && $sameCurrency && $sameAmount;
    }
}
