<?php

namespace App\Insurance;

final class InsuranceClaimLifecycle
{
    private const RESPONSE_TRANSITIONS = [
        'submitted' => ['acknowledged', 'under_review', 'additional_information_requested', 'rejected', 'approved', 'partially_approved', 'denied'],
        'acknowledged' => ['under_review', 'additional_information_requested', 'rejected', 'approved', 'partially_approved', 'denied'],
        'under_review' => ['acknowledged', 'under_review', 'additional_information_requested', 'rejected', 'approved', 'partially_approved', 'denied'],
        'additional_information_requested' => ['acknowledged', 'under_review', 'additional_information_requested', 'rejected', 'approved', 'partially_approved', 'denied'],
    ];

    private const APPEAL_TRANSITIONS = [
        'submitted' => ['acknowledged', 'under_review', 'approved', 'partially_approved', 'denied'],
        'acknowledged' => ['under_review', 'approved', 'partially_approved', 'denied'],
        'under_review' => ['acknowledged', 'under_review', 'approved', 'partially_approved', 'denied'],
    ];

    public static function canRecordResponse(string $from, string $to): bool
    {
        return in_array($to, self::RESPONSE_TRANSITIONS[$from] ?? [], true);
    }

    public static function canRecordAppealResponse(string $from, string $to): bool
    {
        return in_array($to, self::APPEAL_TRANSITIONS[$from] ?? [], true);
    }

    public static function canWithdrawAppeal(string $status): bool
    {
        return in_array($status, ['draft', 'submitted'], true);
    }

    public static function canAppeal(string $decisionType): bool
    {
        // Administrative rejection is intentionally excluded until a specific rejection is classified appealable.
        return $decisionType === 'denied';
    }

    public static function canCorrect(string $claimStatus): bool
    {
        return in_array($claimStatus, ['submitted', 'rejected', 'denied'], true);
    }

    public static function canCreateCorrection(string $claimStatus, bool $alreadyCorrected): bool
    {
        return self::canCorrect($claimStatus) && ! $alreadyCorrected;
    }

    public static function canIncludeInformationalApprovedAmount(string $responseType, bool $amountProvided): bool
    {
        return ! $amountProvided || in_array($responseType, ['approved', 'partially_approved'], true);
    }
}
