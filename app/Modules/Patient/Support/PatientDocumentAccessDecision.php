<?php

namespace App\Modules\Patient\Support;

/**
 * SECURITY-PATIENT-KTP-ARCHIVE-BRANCH-ISOLATION-1 — the outcome of one
 * patient-document access decision.
 *
 * Immutable, and deliberately free of identity data: it carries ids of
 * BRANCHES and a reason code, never a patient name, NIK, RM number or storage
 * path, so it can be written to the audit trail as it stands.
 */
final class PatientDocumentAccessDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly bool $privileged,
        public readonly ?int $operatorBranchId,
        public readonly ?int $capturedBranchId,
    ) {}

    public static function allow(string $reason, bool $privileged, ?int $operatorBranchId, ?int $capturedBranchId): self
    {
        return new self(true, $reason, $privileged, $operatorBranchId, $capturedBranchId);
    }

    public static function deny(string $reason, ?int $operatorBranchId, ?int $capturedBranchId): self
    {
        return new self(false, $reason, false, $operatorBranchId, $capturedBranchId);
    }

    /**
     * The audit payload. Ids and codes only.
     *
     * @return array<string, mixed>
     */
    public function toAuditPayload(string $operation, ?string $documentType): array
    {
        return [
            'operation' => $operation,
            'outcome' => $this->allowed ? 'granted' : 'denied',
            'reason' => $this->reason,
            'privileged' => $this->privileged,
            'document_type' => $documentType,
            'operator_branch_id' => $this->operatorBranchId,
            'captured_branch_id' => $this->capturedBranchId,
            'cross_branch' => $this->operatorBranchId === null
                || $this->capturedBranchId === null
                || $this->operatorBranchId !== $this->capturedBranchId,
        ];
    }
}
