<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmeRecordRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Support\LegacyRmeDuplicateDecision;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;

/**
 * LEGACY-RME-PDF-1B — exact-file duplicate precheck.
 *
 * Operates on the SHA-256 of the source PDF, always computed server-side; a
 * client-supplied checksum is never an input.
 *
 * THE POLICY, and why each branch is what it is:
 *
 *  - A PUBLISHED record with the same checksum BLOCKS, for the same patient
 *    ("already archived") and for a different one ("this document belongs to
 *    someone else — check the patient you picked").
 *
 *  - A VOID record with the same checksum does NOT block. 1A's own immutability
 *    rule states that the only supported correction of a published record is
 *    "VOID with a reason plus a fresh import" — so blocking on VOID would make
 *    the documented correction path impossible. The collision is audited
 *    instead, which keeps it visible without breaking the workflow.
 *
 *  - An ACTIVE staging row (anything not FAILED/CANCELLED/PUBLISHED) BLOCKS for
 *    any patient: the same document is already in flight.
 *
 *  - A FAILED staging row for the SAME patient BLOCKS, and points the operator
 *    at retry — re-uploading would leave a second staging row for one document.
 *
 *  - A FAILED or CANCELLED staging row for a DIFFERENT patient does NOT block:
 *    "wrong patient chosen, cancelled, re-upload against the right patient" is
 *    the intended correction, and blocking it would strand the document.
 */
class LegacyRmeDuplicateDetectionService
{
    public function __construct(
        private readonly LegacyRmeImportRepositoryInterface $imports,
        private readonly LegacyRmeRecordRepositoryInterface $records,
    ) {}

    public function evaluate(int $patientId, string $sha256): LegacyRmeDuplicateDecision
    {
        foreach ($this->records->findByPdfChecksum($sha256) as $record) {
            /** @var LegacyRmeRecord $record */
            if ($record->status === LegacyRmeRecordStatus::VOID) {
                // Visible, but never blocking — this is the correction path.
                continue;
            }

            $isSamePatient = (int) $record->patient_id === $patientId;

            return LegacyRmeDuplicateDecision::block(
                $isSamePatient ? LegacyRmePdfFailure::DUPLICATE_SAME_PATIENT : LegacyRmePdfFailure::DUPLICATE_OTHER_PATIENT,
                LegacyRmePdfFailure::message(
                    $isSamePatient ? LegacyRmePdfFailure::DUPLICATE_SAME_PATIENT : LegacyRmePdfFailure::DUPLICATE_OTHER_PATIENT,
                ),
                duplicateRecordId: $record->getKey() !== null ? (int) $record->getKey() : null,
                duplicatePatientId: (int) $record->patient_id,
            );
        }

        foreach ($this->imports->findByPdfChecksum($sha256) as $import) {
            /** @var LegacyRmeImport $import */
            $isSamePatient = (int) $import->patient_id === $patientId;
            $status = (string) $import->status;

            if ($status === LegacyRmeImportStatus::CANCELLED) {
                continue;
            }

            if ($status === LegacyRmeImportStatus::FAILED) {
                if (! $isSamePatient) {
                    continue;
                }

                return LegacyRmeDuplicateDecision::block(
                    LegacyRmePdfFailure::DUPLICATE_SAME_PATIENT,
                    'PDF identik sudah pernah dimasukkan untuk pasien ini dan pemrosesannya gagal. Gunakan tombol Proses Ulang pada impor tersebut, jangan mengunggah ulang.',
                    duplicateImportId: $import->getKey() !== null ? (int) $import->getKey() : null,
                    duplicatePatientId: (int) $import->patient_id,
                );
            }

            // PUBLISHED staging rows are covered by the record branch above;
            // everything remaining is an active, in-flight import.
            if ($status === LegacyRmeImportStatus::PUBLISHED) {
                continue;
            }

            return LegacyRmeDuplicateDecision::block(
                $isSamePatient ? LegacyRmePdfFailure::DUPLICATE_SAME_PATIENT : LegacyRmePdfFailure::DUPLICATE_OTHER_PATIENT,
                $isSamePatient
                    ? 'PDF identik sudah pernah dimasukkan untuk pasien ini dan masih diproses.'
                    : LegacyRmePdfFailure::message(LegacyRmePdfFailure::DUPLICATE_OTHER_PATIENT),
                duplicateImportId: $import->getKey() !== null ? (int) $import->getKey() : null,
                duplicatePatientId: (int) $import->patient_id,
            );
        }

        return LegacyRmeDuplicateDecision::allow();
    }
}
