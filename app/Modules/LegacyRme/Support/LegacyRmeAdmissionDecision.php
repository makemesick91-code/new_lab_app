<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-ROLL-3 — the outcome of asking whether a branch may start new
 * legacy migration work on this deployment.
 *
 * Immutable and PII-free: a branch code and a wave label are operational
 * labels, never patient data. Callers branch on the CODE, never on the message
 * — the wording is for the operator, the code is the contract.
 *
 * ADMISSION IS NOT AUTHORIZATION. A decision of ADMITTED means the rollout has
 * cleared this branch to ingest; it says nothing about whether the acting user
 * holds the permission, owns the patient, or passed the date rules. Those gates
 * are evaluated independently and every one of them still has to pass.
 */
final class LegacyRmeAdmissionDecision
{
    /** The branch is cleared to start new migration work. */
    public const CODE_ADMITTED = 'ADMITTED';

    /** The global capability is off — nothing may be ingested anywhere. */
    public const CODE_FEATURE_DISABLED = 'FEATURE_DISABLED';

    /**
     * Enforcement is on and the allowlist is empty. Capability without
     * admission migrates nothing; this is the documented fail-closed default.
     */
    public const CODE_NO_BRANCH_ADMITTED = 'NO_BRANCH_ADMITTED';

    /** The branch resolved, but this wave does not include it. */
    public const CODE_BRANCH_NOT_ADMITTED = 'BRANCH_NOT_ADMITTED';

    /** The branch may never host a clinical migration (MAIN). */
    public const CODE_BRANCH_FORBIDDEN = 'BRANCH_FORBIDDEN';

    /**
     * The branch could not be derived from the patient's Nomor RM, so there is
     * nothing to admit. The underlying reason is carried by the branch
     * resolution itself; admission simply refuses to guess.
     */
    public const CODE_BRANCH_UNRESOLVED = 'BRANCH_UNRESOLVED';

    /** @var list<string> */
    public const CODES = [
        self::CODE_ADMITTED,
        self::CODE_FEATURE_DISABLED,
        self::CODE_NO_BRANCH_ADMITTED,
        self::CODE_BRANCH_NOT_ADMITTED,
        self::CODE_BRANCH_FORBIDDEN,
        self::CODE_BRANCH_UNRESOLVED,
    ];

    private function __construct(
        public readonly bool $admitted,
        public readonly string $code,
        public readonly ?string $branchCode,
        public readonly ?string $wave,
        public readonly ?string $message,
    ) {}

    public static function admit(string $branchCode, ?string $wave): self
    {
        return new self(true, self::CODE_ADMITTED, $branchCode, $wave !== '' ? $wave : null, null);
    }

    public static function deny(string $code, string $message, ?string $branchCode = null, ?string $wave = null): self
    {
        return new self(false, $code, $branchCode, $wave !== '' ? $wave : null, $message);
    }

    public function denied(): bool
    {
        return ! $this->admitted;
    }

    /**
     * PII-free audit context. `branch_code` is an operational label such as
     * `TKM1` taken from the patient's own Nomor RM — never the full Nomor RM,
     * never a name, never a document reference.
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
