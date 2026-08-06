<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-1B — immutable outcome of the exact-file duplicate precheck.
 *
 * Mirrors LegacyRmeDateRuleResult: a STABLE machine code plus a safe message,
 * and a PII-free context (row ids only).
 */
final class LegacyRmeDuplicateDecision
{
    private function __construct(
        public readonly bool $blocked,
        public readonly ?string $code,
        public readonly ?string $message,
        public readonly ?int $duplicateImportId,
        public readonly ?int $duplicateRecordId,
        public readonly ?int $duplicatePatientId,
    ) {}

    public static function allow(): self
    {
        return new self(false, null, null, null, null, null);
    }

    public static function block(
        string $code,
        string $message,
        ?int $duplicateImportId = null,
        ?int $duplicateRecordId = null,
        ?int $duplicatePatientId = null,
    ): self {
        return new self(true, $code, $message, $duplicateImportId, $duplicateRecordId, $duplicatePatientId);
    }

    /**
     * PII-free audit context.
     *
     * @return array<string, int|string|null>
     */
    public function auditContext(): array
    {
        return array_filter([
            'failure_code' => $this->code,
            'duplicate_import_id' => $this->duplicateImportId,
            'duplicate_record_id' => $this->duplicateRecordId,
            'duplicate_patient_id' => $this->duplicatePatientId,
        ], fn ($value) => $value !== null);
    }
}
