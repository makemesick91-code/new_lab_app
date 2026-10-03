<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Support;

/**
 * PII-safe metadata about ONE queued document — PR1 §4, §11, §12.
 *
 * WHAT IS DELIBERATELY ABSENT
 * ---------------------------
 * No KTP, no NIK, no date of birth, no address, no clinical content, no page
 * bytes and no filesystem path. A reviewer identifies a document by patient
 * name plus medical record number — exactly what the canonical workspace
 * listing already exposes, and exactly what `paginateInBranches()` already
 * eager-loads (`patient:id,name,medical_record_number`). Nothing here widens
 * what a legacy workspace may show.
 *
 * The source checksum is carried because §4 requires a decision to identify the
 * bytes that were inspected. It is a hash, not content.
 */
final class LegacyBatchReviewItemSummary
{
    /**
     * @param  list<int>  $pageNumbers
     */
    public function __construct(
        public readonly int $importId,
        public readonly string $importType,
        public readonly ?int $patientId,
        public readonly ?string $patientName,
        public readonly ?string $medicalRecordNumber,
        public readonly ?int $branchId,
        public readonly ?string $branchName,
        public readonly string $status,
        public readonly ?string $clinicalDate,
        public readonly ?string $sourceSha256,
        public readonly int $pageCount,
        public readonly array $pageNumbers = [],
        public readonly ?int $uploadedBy = null,
        public readonly ?string $uploadedByName = null,
    ) {}

    /**
     * Short checksum prefix for display. The full hash goes on the decision row;
     * an operator only ever needs enough to tell two documents apart.
     */
    public function checksumPrefix(): ?string
    {
        if ($this->sourceSha256 === null || $this->sourceSha256 === '') {
            return null;
        }

        return substr($this->sourceSha256, 0, 12);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'import_id' => $this->importId,
            'import_type' => $this->importType,
            'patient_id' => $this->patientId,
            'patient_name' => $this->patientName,
            'medical_record_number' => $this->medicalRecordNumber,
            'branch_id' => $this->branchId,
            'branch_name' => $this->branchName,
            'status' => $this->status,
            'clinical_date' => $this->clinicalDate,
            'checksum_prefix' => $this->checksumPrefix(),
            'page_count' => $this->pageCount,
            'page_numbers' => $this->pageNumbers,
            'uploaded_by_name' => $this->uploadedByName,
        ];
    }
}
