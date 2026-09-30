<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Services;

use App\Models\User;
use App\Modules\LegacyImport\MassUpload\Adapters\LegacyMassUploadAdapter;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadBatch;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadItem;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadBatchStatus;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadExtractedDocument;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadItemStatus;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadManifestRow;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadPackageContents;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Preflight — FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 §18.
 *
 * Evaluates every manifest row and writes a staging item for each. It creates
 * NO clinical lifecycle and enqueues NOTHING. A blocked row therefore costs
 * nothing and leaves no trace in the clinical archive, which is what makes it
 * safe for an operator to submit a messy 250-row archive and simply read the
 * result.
 *
 * TWO REFUSALS THAT ONLY PREFLIGHT CAN SEE
 * ----------------------------------------
 * Most eligibility comes from the canonical services. Two do not, because they
 * are properties of the BATCH rather than of the system:
 *
 *   DUPLICATE_MANIFEST_PATIENT — the same patient appearing twice. At preflight
 *   time neither occurrence has created anything, so the single-active-document
 *   slot is genuinely free for both and would pass both. Only the first can
 *   ever succeed; the second would be refused at creation. Catching it here
 *   turns a confusing mid-dispatch refusal into an obvious "row 88 duplicates
 *   row 12" the operator can fix before confirming. This is also exactly the
 *   multi-year case §12 warns about — three yearly PDFs for one patient must be
 *   merged into one archive document, not submitted as three lifecycles.
 *
 *   DUPLICATE_SOURCE — two rows carrying byte-identical documents. Usually a
 *   copy-paste in the manifest rather than two real records.
 *
 * Both are evaluated in manifest order, so the FIRST occurrence stays eligible
 * and later ones are refused. Order matters and must stay deterministic:
 * otherwise a re-run of the same archive could import a different row.
 */
class LegacyMassUploadPreflightService
{
    public function __construct(
        private readonly LegacyMassUploadAuditService $audit,
    ) {}

    /**
     * Evaluate the package and persist one staging item per manifest row.
     */
    public function run(
        LegacyMassUploadBatch $batch,
        LegacyMassUploadPackageContents $contents,
        LegacyMassUploadAdapter $adapter,
        User $actor,
    ): LegacyMassUploadBatch {
        $seenPatients = [];
        $seenDigests = [];
        $rows = [];

        foreach ($contents->rows as $row) {
            $document = $contents->documentFor($row->fileName);

            // The package validator already proved every referenced document
            // exists, so a null here means the two disagree — refuse the row
            // rather than assume.
            if ($document === null) {
                $rows[] = $this->itemAttributes(
                    $batch,
                    $row,
                    LegacyMassUploadItemStatus::BLOCKED,
                    LegacyMassUploadReason::MANIFEST_FILE_MISSING,
                    null,
                );

                continue;
            }

            $resolution = $adapter->resolvePatient($actor, $row->medicalRecordNumber);

            if (! $resolution->resolved()) {
                $rows[] = $this->itemAttributes(
                    $batch,
                    $row,
                    LegacyMassUploadItemStatus::BLOCKED,
                    (string) $resolution->reasonCode,
                    null,
                    document: $document,
                );

                continue;
            }

            $patient = $resolution->patient;
            $patientId = (int) $patient->getKey();

            // Batch-level duplicate patient. See class docblock.
            if (isset($seenPatients[$patientId])) {
                $rows[] = $this->itemAttributes(
                    $batch,
                    $row,
                    LegacyMassUploadItemStatus::BLOCKED,
                    LegacyMassUploadReason::DUPLICATE_MANIFEST_PATIENT,
                    sprintf(
                        'Pasien ini sudah dirujuk pada baris %d manifest yang sama. Gabungkan dokumen menjadi satu arsip per pasien.',
                        $seenPatients[$patientId],
                    ),
                    patientId: $patientId,
                    document: $document,
                );

                continue;
            }

            // Byte-identical document already claimed by an earlier row.
            if (isset($seenDigests[$document->sha256])) {
                $rows[] = $this->itemAttributes(
                    $batch,
                    $row,
                    LegacyMassUploadItemStatus::BLOCKED,
                    LegacyMassUploadReason::DUPLICATE_SOURCE,
                    sprintf(
                        'Dokumen ini identik dengan berkas pada baris %d manifest yang sama.',
                        $seenDigests[$document->sha256],
                    ),
                    patientId: $patientId,
                    document: $document,
                );

                continue;
            }

            $verdict = $adapter->evaluateRow($patient, $row, $actor);

            // Only rows that can actually proceed reserve the patient and the
            // digest. A blocked row must not shadow a later valid row for the
            // same patient — if row 12 is refused for a bad date and row 88 is
            // the same patient with a good one, row 88 deserves its chance.
            if ($verdict->isDispatchable()) {
                $seenPatients[$patientId] = $row->rowNumber;
                $seenDigests[$document->sha256] = $row->rowNumber;
            }

            $rows[] = $this->itemAttributes(
                $batch,
                $row,
                $verdict->status,
                $verdict->reasonCode,
                $verdict->reasonMessage,
                patientId: $patientId,
                branchId: $verdict->branchId,
                document: $document,
                normalizedSourceRm: $verdict->normalizedSourceRm,
            );
        }

        return DB::transaction(function () use ($batch, $rows, $contents, $actor): LegacyMassUploadBatch {
            $locked = LegacyMassUploadBatch::query()
                ->whereKey($batch->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotent re-run: a retried preflight replaces its own staging
            // rows rather than appending a second copy. Safe because nothing
            // clinical exists yet — guaranteed by the status guard below.
            if (! LegacyMassUploadBatchStatus::canTransition(
                (string) $locked->status,
                LegacyMassUploadBatchStatus::PREFLIGHT_READY,
            )) {
                return $locked;
            }

            LegacyMassUploadItem::query()
                ->where('mass_upload_batch_id', $locked->getKey())
                ->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                LegacyMassUploadItem::query()->insert($chunk);
            }

            $counts = $this->countsFor((int) $locked->getKey());

            $locked->fill([
                'status' => LegacyMassUploadBatchStatus::PREFLIGHT_READY,
                'manifest_sha256' => $contents->manifestSha256,
                'total_items' => $counts['total'],
                'eligible_items' => $counts['eligible'],
                'warning_items' => $counts['warning'],
                'blocked_items' => $counts['blocked'],
                'error_items' => 0,
                'preflight_completed_at' => now(),
            ])->save();

            $this->audit->logBatchEvent('MASS_UPLOAD_PREFLIGHT_COMPLETED', $locked, [
                'total_items' => $counts['total'],
                'eligible_items' => $counts['eligible'],
                'warning_items' => $counts['warning'],
                'blocked_items' => $counts['blocked'],
                'unreferenced_documents' => count($contents->unreferencedDocuments),
            ], $actor);

            return $locked->refresh();
        });
    }

    /**
     * @return array{total: int, eligible: int, warning: int, blocked: int, dispatched: int, failed: int}
     */
    public function countsFor(int $batchId): array
    {
        $rows = LegacyMassUploadItem::query()
            ->where('mass_upload_batch_id', $batchId)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $get = static fn (string $status): int => (int) ($rows[$status] ?? 0);

        return [
            'total' => (int) $rows->sum(),
            'eligible' => $get(LegacyMassUploadItemStatus::ELIGIBLE),
            'warning' => $get(LegacyMassUploadItemStatus::WARNING),
            'blocked' => $get(LegacyMassUploadItemStatus::BLOCKED),
            'dispatched' => $get(LegacyMassUploadItemStatus::DISPATCHED),
            'failed' => $get(LegacyMassUploadItemStatus::FAILED),
        ];
    }

    /**
     * Build the raw insert payload for one item.
     *
     * Uses a plain array rather than a model save because a 250-row batch is
     * inserted in chunks; going through Eloquent per row would be 250
     * round-trips for no benefit. Timestamps are therefore set explicitly.
     *
     * @return array<string, mixed>
     */
    private function itemAttributes(
        LegacyMassUploadBatch $batch,
        LegacyMassUploadManifestRow $row,
        string $status,
        ?string $reasonCode,
        ?string $reasonMessage,
        ?int $patientId = null,
        ?int $branchId = null,
        ?LegacyMassUploadExtractedDocument $document = null,
        ?string $normalizedSourceRm = null,
    ): array {
        $now = now();

        return [
            'mass_upload_batch_id' => $batch->getKey(),
            'row_number' => $row->rowNumber,
            // Column-length safety. A manifest cell can be arbitrarily long and
            // a database-level truncation error would fail the whole batch for
            // one bad cell.
            'manifest_medical_record_number' => Str::limit($row->medicalRecordNumber, 60, ''),
            'manifest_file_name' => Str::limit($row->fileName, 240, ''),
            'manifest_selected_date' => $row->selectedDate === null ? null : Str::limit($row->selectedDate, 30, ''),
            'manifest_latest_date' => $row->latestDate === null ? null : Str::limit($row->latestDate, 30, ''),
            'normalized_source_rm' => $normalizedSourceRm === null ? null : Str::limit($normalizedSourceRm, 60, ''),
            'resolved_patient_id' => $patientId,
            'resolved_branch_id' => $branchId,
            'document_entry_path' => $document?->relativePath,
            'document_sha256' => $document?->sha256,
            'document_bytes' => $document?->bytes,
            'status' => $status,
            'reason_code' => $reasonCode,
            'reason_message' => $reasonMessage ?? ($reasonCode === null ? null : LegacyMassUploadReason::message($reasonCode)),
            'rme_legacy_import_id' => null,
            'odontogram_legacy_import_id' => null,
            'dispatched_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
