<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-FIX-ROLL2-1 — the outcome of resolving a legacy document's
 * branch from the patient's Nomor RM.
 *
 * Immutable, PII-free (a branch code and an id — never a patient name or a
 * Nomor RM in full), and always explicit: either it names exactly one branch,
 * or it carries the stable reason why it could not.
 *
 * Callers branch on the CODE, never on the message.
 */
final class LegacyRmeBranchResolution
{
    public const CODE_RM_MISSING = 'RM_MISSING';

    public const CODE_INVALID_RM_BRANCH_CODE = 'INVALID_RM_BRANCH_CODE';

    public const CODE_BRANCH_NOT_FOUND = 'BRANCH_NOT_FOUND';

    public const CODE_BRANCH_AMBIGUOUS = 'BRANCH_AMBIGUOUS';

    public const CODE_BRANCH_INACTIVE = 'BRANCH_INACTIVE';

    public const CODE_BRANCH_NOT_RME_ENABLED = 'BRANCH_NOT_RME_ENABLED';

    public const CODE_BRANCH_OUT_OF_SCOPE = 'BRANCH_OUT_OF_SCOPE';

    public const CODE_BRANCH_CONFLICT = 'BRANCH_CONFLICT';

    /** @var list<string> */
    public const CODES = [
        self::CODE_RM_MISSING,
        self::CODE_INVALID_RM_BRANCH_CODE,
        self::CODE_BRANCH_NOT_FOUND,
        self::CODE_BRANCH_AMBIGUOUS,
        self::CODE_BRANCH_INACTIVE,
        self::CODE_BRANCH_NOT_RME_ENABLED,
        self::CODE_BRANCH_OUT_OF_SCOPE,
        self::CODE_BRANCH_CONFLICT,
    ];

    private function __construct(
        public readonly bool $resolved,
        public readonly ?int $branchId,
        public readonly ?string $branchCode,
        public readonly ?string $branchName,
        public readonly ?string $code,
        public readonly ?string $message,
    ) {}

    public static function success(int $branchId, string $branchCode, ?string $branchName): self
    {
        return new self(true, $branchId, $branchCode, $branchName, null, null);
    }

    public static function failure(string $code, string $message, ?string $branchCode = null): self
    {
        return new self(false, null, $branchCode, null, $code, $message);
    }

    public function failed(): bool
    {
        return ! $this->resolved;
    }

    /**
     * PII-free audit context. The branch CODE is an operational label
     * (`TKM1`), not patient data, so it is safe to record.
     *
     * @return array<string, scalar|null>
     */
    public function auditContext(): array
    {
        return array_filter([
            'origin_branch_id' => $this->branchId,
            'branch_code' => $this->branchCode,
            'rule_code' => $this->code,
        ], static fn ($value): bool => $value !== null);
    }
}
