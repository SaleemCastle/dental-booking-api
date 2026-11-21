<?php

namespace App\Insurance;

use InvalidArgumentException;

final class InsuranceRemittanceRules
{
    public static function normalizeReference(string $reference): string
    {
        return trim($reference);
    }

    public static function amount(?string $amount, bool $allowNegative = false): ?string
    {
        if ($amount === null) {
            return null;
        }

        $pattern = $allowNegative
            ? '/\\A-?(?:0|[1-9][0-9]{0,11})(?:\\.([0-9]{1,2}))?\\z/'
            : '/\\A(?:0|[1-9][0-9]{0,11})(?:\\.([0-9]{1,2}))?\\z/';
        if (! preg_match($pattern, $amount)) {
            throw new InvalidArgumentException('Amounts must be exact decimal strings with at most two fractional digits.');
        }

        $hasNegativeSign = str_starts_with($amount, '-');
        $negative = $hasNegativeSign && ! preg_match('/\\A-0(?:\\.0{1,2})?\\z/', $amount);
        $unsigned = $hasNegativeSign ? substr($amount, 1) : $amount;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');

        return ($negative ? '-' : '').$whole.'.'.str_pad($fraction, 2, '0');
    }

    public static function correctionAllowed(bool $sameTenant, bool $sameInsurer, bool $alreadyCorrected): bool
    {
        return $sameTenant && $sameInsurer && ! $alreadyCorrected;
    }

    public static function lineReferencesConsistent(?int $claimId, ?int $submissionId, ?int $evidenceId): bool
    {
        return ($submissionId === null || $claimId !== null)
            && ($evidenceId === null || $submissionId !== null);
    }

    public static function uniqueLineReferences(array $references): bool
    {
        $references = array_values(array_filter(
            array_map(fn ($reference) => $reference === null ? null : self::normalizeReference($reference), $references),
            fn ($reference) => $reference !== null,
        ));

        return count($references) === count(array_unique($references));
    }
}
