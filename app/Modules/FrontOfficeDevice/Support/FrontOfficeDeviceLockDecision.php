<?php

namespace App\Modules\FrontOfficeDevice\Support;

/**
 * REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1 — one decision, carried whole.
 *
 * The outcome, the reason code, and the audit context travel together because
 * they are computed together. A caller that re-derives "was this a denial?" from
 * a message string, or an audit row whose reason was recomputed at the logging
 * site, is a second copy of the decision — and a second copy eventually
 * disagrees with the first.
 *
 * The context is deliberately narrow: ids, codes and a reason. No password, no
 * credential bytes, no token, no cookie, no biometric material, and no patient
 * data — none of which is needed to explain a refused login.
 */
final class FrontOfficeDeviceLockDecision
{
    /** The account is not armed. Login proceeds exactly as it did before. */
    public const NOT_IN_SCOPE = 'not_in_scope';

    /** Armed, and every condition held. */
    public const ALLOW = 'allow';

    /**
     * No server-verified device is bound to this session.
     *
     * This is also where the credential-level refusals land, by construction:
     * an assertion that failed — no credential, unknown credential, revoked
     * credential, or a credential belonging to a device this account may not
     * use — never writes a binding, so the gate sees no device at all.
     */
    public const DENY_UNKNOWN_DEVICE = 'deny_unknown_device';

    /** Bound device exists but is not an approved, verified, active device. */
    public const DENY_DEVICE_NOT_APPROVED = 'deny_device_not_approved';

    /** Bound device is approved, but belongs to a different branch. */
    public const DENY_DEVICE_BRANCH_MISMATCH = 'deny_device_branch_mismatch';

    /** The cohort entry itself is unusable: ambiguous, oversized, or off-policy. */
    public const DENY_ACCOUNT_BRANCH_INVALID = 'deny_account_branch_invalid';

    /** The required branch is missing, inactive, not RME-enabled, or trashed. */
    public const DENY_BRANCH_INACTIVE = 'deny_branch_inactive';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $requiredBranchCode = null,
        public readonly ?int $requiredBranchId = null,
        public readonly ?int $deviceId = null,
        public readonly ?int $deviceBranchId = null,
        public readonly ?string $deviceBranchCode = null,
        public readonly ?string $requiredBranchName = null,
    ) {}

    public static function notInScope(): self
    {
        return new self(self::NOT_IN_SCOPE);
    }

    public static function allow(
        string $requiredBranchCode,
        int $requiredBranchId,
        int $deviceId,
    ): self {
        return new self(
            outcome: self::ALLOW,
            requiredBranchCode: $requiredBranchCode,
            requiredBranchId: $requiredBranchId,
            deviceId: $deviceId,
            deviceBranchId: $requiredBranchId,
            deviceBranchCode: $requiredBranchCode,
        );
    }

    public static function deny(
        string $outcome,
        ?string $requiredBranchCode = null,
        ?int $requiredBranchId = null,
        ?int $deviceId = null,
        ?int $deviceBranchId = null,
        ?string $deviceBranchCode = null,
        ?string $requiredBranchName = null,
    ): self {
        return new self(
            outcome: $outcome,
            requiredBranchCode: $requiredBranchCode,
            requiredBranchId: $requiredBranchId,
            deviceId: $deviceId,
            deviceBranchId: $deviceBranchId,
            deviceBranchCode: $deviceBranchCode,
            requiredBranchName: $requiredBranchName,
        );
    }

    public function isDenial(): bool
    {
        return $this->outcome !== self::ALLOW && $this->outcome !== self::NOT_IN_SCOPE;
    }

    public function inScope(): bool
    {
        return $this->outcome !== self::NOT_IN_SCOPE;
    }

    /**
     * Audit context. Safe to persist: ids and codes only.
     *
     * @return array<string, int|string|null>
     */
    public function auditContext(): array
    {
        return array_filter([
            'reason' => $this->outcome,
            'required_branch_id' => $this->requiredBranchId,
            'required_branch_code' => $this->requiredBranchCode,
            'device_id' => $this->deviceId,
            'actual_branch_id' => $this->deviceBranchId,
            'actual_branch_code' => $this->deviceBranchCode,
        ], static fn ($value) => $value !== null);
    }

    /**
     * The operator-facing message, in Indonesian.
     *
     * Only the branch-mismatch case names anything specific, and it names the
     * branch the ACCOUNT belongs to — which its own operator already knows.
     * Nothing here reveals device ids, statuses, cohort configuration or why
     * the server refused beyond what the person needs in order to act.
     */
    public function message(): string
    {
        return match ($this->outcome) {
            self::DENY_DEVICE_BRANCH_MISMATCH => $this->requiredBranchName !== null
                ? 'Akun Front Office ini hanya dapat digunakan pada perangkat '.$this->requiredBranchName.'.'
                : 'Akun Front Office ini hanya dapat digunakan pada perangkat cabangnya sendiri.',
            self::DENY_UNKNOWN_DEVICE,
            self::DENY_DEVICE_NOT_APPROVED => 'Perangkat ini belum terdaftar atau tidak diizinkan untuk akun Front Office ini.',
            self::DENY_ACCOUNT_BRANCH_INVALID,
            self::DENY_BRANCH_INACTIVE => 'Konfigurasi cabang untuk akun Front Office ini belum valid. Hubungi administrator.',
            default => 'Login tidak dapat dilanjutkan untuk akun Front Office ini.',
        };
    }
}
