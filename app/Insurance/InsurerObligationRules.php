<?php

namespace App\Insurance;

use App\Billing\Money;
use InvalidArgumentException;

final class InsurerObligationRules
{
    public static function normalizeAmount(?string $amount): ?string
    {
        if ($amount === null || ! preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?\z/', $amount)) {
            return null;
        }

        try {
            $minor = Money::minor($amount);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $minor > 0 ? Money::decimal($minor) : null;
    }

    public static function amountWithinInvoiceLine(string $proposedAmount, string $lineCharge): bool
    {
        $proposed = self::normalizeAmount($proposedAmount);
        $charge = self::normalizeAmount($lineCharge);

        return $proposed !== null && $charge !== null && Money::minor($proposed) <= Money::minor($charge);
    }

    public static function claimLineageConsistent(?int $claimId, ?int $submissionId, ?int $responseId): bool
    {
        return ($claimId === null) === ($submissionId === null)
            && ($responseId === null || ($claimId !== null && $submissionId !== null));
    }

    public static function mayApprove(bool $alreadyApproved, bool $sameTenant, bool $sameInvoiceLine, bool $currencyMatches, bool $amountWithinLine, bool $eligibleInvoice): bool
    {
        return ! $alreadyApproved && $sameTenant && $sameInvoiceLine && $currencyMatches && $amountWithinLine && $eligibleInvoice;
    }
}
