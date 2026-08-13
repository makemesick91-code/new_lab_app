<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-ROLL-4 — the outcome of the operations layer.
 *
 * Immutable and PII-free, exactly like ROLL-3's LegacyRmeAdmissionDecision:
 * counts, codes and operational labels. Callers branch on the CODE; the message
 * is for the operator.
 *
 * WHAT "CLEARED" DOES NOT MEAN. A cleared decision says the wave, the branch
 * enrollment, the operator assignment and the quota all permit this document.
 * It says nothing about capability, ROLL-3 admission, capacity, the acting
 * user's permission, patient ownership or the date rules — every one of those
 * is a separate gate that has already run or still has to.
 *
 * THE LAYER CAN ONLY NARROW. There is deliberately no constructor path that
 * turns a ROLL-3 denial into a clearance. `clear()` is reached only after
 * ROLL-3 has already admitted, and the caller composes them in that order.
 */
final class LegacyRmeOperationsDecision
{
    /** Every ROLL-4 operational gate permits this document. */
    public const CODE_CLEARED = 'CLEARED';

    /**
     * The operations layer is not enforced on this deployment (a local or CI
     * posture). Reported rather than silently skipped so evidence shows which
     * gates actually ran.
     */
    public const CODE_NOT_ENFORCED = 'NOT_ENFORCED';

    /** ROLL-3 admitted a branch, but no wave label is declared in config. */
    public const CODE_WAVE_NOT_DECLARED = 'WAVE_NOT_DECLARED';

    /**
     * Config declares a wave that has no operational record. Fail closed: an
     * unregistered wave has no operators, no quota and no completion path, so
     * migrating under it would be uncontrolled by construction.
     */
    public const CODE_WAVE_NOT_REGISTERED = 'WAVE_NOT_REGISTERED';

    /** The wave exists but is DRAFT or APPROVED — not started yet. */
    public const CODE_WAVE_NOT_ACTIVE = 'WAVE_NOT_ACTIVE';

    /** The wave is temporarily stopped. Accepted work is untouched. */
    public const CODE_WAVE_PAUSED = 'WAVE_PAUSED';

    /** The wave is winding down; new intake is closed. */
    public const CODE_WAVE_DRAINING = 'WAVE_DRAINING';

    /** The wave is COMPLETED or CANCELLED. */
    public const CODE_WAVE_CLOSED = 'WAVE_CLOSED';

    /**
     * The wave record and the ROLL-3 config approval disagree — a different
     * reference, or a different approved branch set.
     *
     * This is the drift ROLL-4 exists to catch one level up from ROLL-3: an
     * environment edited without the governance record, or a governance record
     * edited without the environment. Neither may be assumed to be the correct
     * one, so both are refused until a human reconciles them.
     */
    public const CODE_WAVE_BINDING_MISMATCH = 'WAVE_BINDING_MISMATCH';

    /** The branch is admitted by ROLL-3 but was never enrolled in this wave. */
    public const CODE_BRANCH_NOT_ENROLLED = 'BRANCH_NOT_ENROLLED';

    /** The branch enrollment exists but is not ACTIVE. */
    public const CODE_BRANCH_NOT_ACTIVE = 'BRANCH_NOT_ACTIVE';

    public const CODE_BRANCH_PAUSED = 'BRANCH_PAUSED';

    public const CODE_BRANCH_DRAINING = 'BRANCH_DRAINING';

    public const CODE_BRANCH_CLOSED = 'BRANCH_CLOSED';

    /**
     * The acting user holds the permission but is not assigned to this branch
     * in this wave.
     *
     * A permission answers "may this person migrate?"; it cannot answer "may
     * this person migrate THIS branch?". Across a multi-branch wave those are
     * different questions and only the second one protects a clinic from having
     * documents filed into it by someone with no connection to it.
     */
    public const CODE_OPERATOR_NOT_ASSIGNED = 'OPERATOR_NOT_ASSIGNED';

    /** Today's ceiling for this branch has been reached. */
    public const CODE_QUOTA_BRANCH_EXHAUSTED = 'QUOTA_BRANCH_EXHAUSTED';

    /** Today's ceiling for the whole wave has been reached. */
    public const CODE_QUOTA_WAVE_EXHAUSTED = 'QUOTA_WAVE_EXHAUSTED';

    /** @var list<string> */
    public const CODES = [
        self::CODE_CLEARED,
        self::CODE_NOT_ENFORCED,
        self::CODE_WAVE_NOT_DECLARED,
        self::CODE_WAVE_NOT_REGISTERED,
        self::CODE_WAVE_NOT_ACTIVE,
        self::CODE_WAVE_PAUSED,
        self::CODE_WAVE_DRAINING,
        self::CODE_WAVE_CLOSED,
        self::CODE_WAVE_BINDING_MISMATCH,
        self::CODE_BRANCH_NOT_ENROLLED,
        self::CODE_BRANCH_NOT_ACTIVE,
        self::CODE_BRANCH_PAUSED,
        self::CODE_BRANCH_DRAINING,
        self::CODE_BRANCH_CLOSED,
        self::CODE_OPERATOR_NOT_ASSIGNED,
        self::CODE_QUOTA_BRANCH_EXHAUSTED,
        self::CODE_QUOTA_WAVE_EXHAUSTED,
    ];

    private function __construct(
        public readonly bool $cleared,
        public readonly string $code,
        public readonly ?string $branchCode,
        public readonly ?string $wave,
        public readonly ?int $waveId,
        public readonly ?string $message,
    ) {}

    public static function clear(?string $branchCode, ?string $wave, ?int $waveId): self
    {
        return new self(true, self::CODE_CLEARED, $branchCode, $wave !== '' ? $wave : null, $waveId, null);
    }

    /**
     * The layer is switched off. Cleared, but reported under its own code so no
     * evidence pack can mistake "not checked" for "checked and passed".
     */
    public static function notEnforced(?string $branchCode): self
    {
        return new self(true, self::CODE_NOT_ENFORCED, $branchCode, null, null, null);
    }

    public static function deny(
        string $code,
        string $message,
        ?string $branchCode = null,
        ?string $wave = null,
        ?int $waveId = null,
    ): self {
        return new self(false, $code, $branchCode, $wave !== '' ? $wave : null, $waveId, $message);
    }

    public function denied(): bool
    {
        return ! $this->cleared;
    }

    /**
     * PII-free audit context, using the metadata keys the legacy audit
     * allow-list already permits.
     *
     * @return array<string, scalar|null>
     */
    public function auditContext(): array
    {
        return array_filter([
            'branch_code' => $this->branchCode,
            'rule_code' => $this->code,
            'wave' => $this->wave,
        ], static fn ($value): bool => $value !== null);
    }
}
