<?php

namespace App\Modules\Patient\Support;

/**
 * PHASE-1-PATIENT-KTP-CAMERA-OCR-SUPERVISED-PILOT — one eligibility verdict.
 *
 * Callers branch on the stable REASON code, never on prose. The payload is
 * PII-free by construction: ids, codes and booleans only.
 */
final class KtpCameraOcrPilotDecision
{
    public const ALLOWED = 'allowed';

    public const FEATURE_DISABLED = 'feature_disabled';

    public const NOT_AUTHENTICATED = 'not_authenticated';

    /** The pilot configuration is empty or invalid: covers nobody. */
    public const PILOT_NOT_CONFIGURED = 'pilot_not_configured';

    public const OPERATOR_NOT_IN_PILOT = 'operator_not_in_pilot';

    /** Not a branch-context-bound operator, or no selected working branch. */
    public const NO_WORKING_BRANCH = 'no_working_branch';

    public const BRANCH_NOT_IN_PILOT = 'branch_not_in_pilot';

    public const OUTSIDE_PILOT_PERIOD = 'outside_pilot_period';

    /** A bound trusted device is required and the session carries none. */
    public const DEVICE_NOT_BOUND = 'device_not_bound';

    public const DEVICE_NOT_APPROVED = 'device_not_approved';

    public const REASONS = [
        self::ALLOWED,
        self::FEATURE_DISABLED,
        self::NOT_AUTHENTICATED,
        self::PILOT_NOT_CONFIGURED,
        self::OPERATOR_NOT_IN_PILOT,
        self::NO_WORKING_BRANCH,
        self::BRANCH_NOT_IN_PILOT,
        self::OUTSIDE_PILOT_PERIOD,
        self::DEVICE_NOT_BOUND,
        self::DEVICE_NOT_APPROVED,
    ];

    private function __construct(
        public readonly string $reason,
        public readonly ?int $userId = null,
        public readonly ?int $branchId = null,
        public readonly ?int $deviceId = null,
    ) {}

    public static function allow(int $userId, int $branchId, ?int $deviceId): self
    {
        return new self(self::ALLOWED, $userId, $branchId, $deviceId);
    }

    public static function deny(string $reason, ?int $userId = null, ?int $branchId = null): self
    {
        return new self($reason, $userId, $branchId);
    }

    public function allowed(): bool
    {
        return $this->reason === self::ALLOWED;
    }

    /**
     * @return array{allowed: bool, reason: string, user_id: ?int, branch_id: ?int, device_id: ?int}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed(),
            'reason' => $this->reason,
            'user_id' => $this->userId,
            'branch_id' => $this->branchId,
            'device_id' => $this->deviceId,
        ];
    }
}
