<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Services;

use App\Models\User;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramImportRepositoryInterface;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramRecordRepositoryInterface;
use App\Modules\LegacyOdontogram\Jobs\ProcessLegacyOdontogramPdfImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramAuditEvent;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramRecordStatus;
use App\Modules\LegacyRme\Interfaces\LegacyRmeMalwareScannerInterface;
use App\Modules\LegacyRme\Support\LegacyRmeBranchResolution;
use App\Modules\LegacyRme\Support\LegacyRmePdfException;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use App\Modules\Patient\Models\Patient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FIX-04b — intake: validate a scanned historical odontogram chart and stage it.
 *
 * ORDER MATTERS, and it is the cheap-and-safe checks first:
 *
 *   1. the migration capability is on;
 *   2. the operator-declared date passes every domain rule;
 *   3. the owning branch is DERIVED from the patient's Nomor RM and is inside
 *      the operator's own scope;
 *   4. only then is the file inspected, hashed, malware-scanned and stored.
 *
 * Doing it the other way round would write a clinical document to disk before
 * establishing that it may be archived at all.
 *
 * PDF ONLY, VERIFIED FROM THE BYTES (v1). The FormRequest's `mimetypes` rule
 * already inspects real content rather than the filename, and
 * {@see assertUploadedPdf()} re-checks the `%PDF-` magic header from the file
 * itself — so a JPEG, a ZIP or an HTML page renamed `chart.pdf` is refused at
 * the boundary, not discovered later by a failing rasterizer. The format is
 * restricted on purpose: the whole rendering pipeline is Poppler, which is
 * already a deployed dependency, and admitting image formats would mean a
 * second unvalidated ingestion path (orientation, colour profile, multi-page
 * TIFF) for no clinical gain — a scanner can already emit PDF.
 *
 * NOTHING CLINICAL IS CREATED HERE. No visit, no native odontogram, no medical
 * record, no invoice, no payment, no lab candidate, no SATUSEHAT candidate.
 *
 * THIS TOUCHES NO LEGACY RME STATE. It does not read or write legacy RME
 * staging rows, records, waves, quotas or branch admission, and it never asks
 * the legacy RME capability for permission to run.
 *
 * FILE-BEFORE-ROW, THEN COMPENSATE. The PDF is written before the row exists
 * because the row must persist the path and the hash of a file that is already
 * on disk. If the transaction then fails, the just-written directory is removed
 * so a failed intake cannot leave an orphaned clinical document behind.
 */
class LegacyOdontogramImportService
{
    public function __construct(
        private readonly LegacyOdontogramImportRepositoryInterface $imports,
        // Duplicate detection consults the PUBLISHED archive too: a chart already
        // filed against another patient is the case that matters most.
        private readonly LegacyOdontogramRecordRepositoryInterface $records,
        private readonly LegacyOdontogramDateRuleService $dateRules,
        private readonly LegacyOdontogramBranchBindingService $branchBinding,
        private readonly LegacyOdontogramStorageService $storage,
        private readonly LegacyOdontogramAuditService $audit,
        private readonly LegacyOdontogramFeatureGuard $feature,
        private readonly LegacyRmeMalwareScannerInterface $malware,
    ) {}

    /**
     * @throws ValidationException
     */
    public function createFromUpload(
        Patient $patient,
        string $selectedOdontogramDate,
        UploadedFile $document,
        User $actor,
    ): LegacyOdontogramImport {
        $this->feature->assertMigrationEnabled();

        $dateResult = $this->dateRules->assert($patient, $selectedOdontogramDate);
        $cutoff = $this->dateRules->snapshotCutoff($patient);

        $branch = $this->resolveOriginBranch($patient, $actor);

        try {
            $this->assertUploadedPdf($document);

            $absoluteUpload = $document->getRealPath();

            if (! is_string($absoluteUpload) || ! is_file($absoluteUpload)) {
                throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_STORAGE_FAILED);
            }

            $sha256 = hash_file('sha256', $absoluteUpload);

            if (! is_string($sha256) || $sha256 === '') {
                throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_STORAGE_FAILED);
            }

            $scan = $this->malware->scan($absoluteUpload);
        } catch (LegacyRmePdfException $exception) {
            throw $exception->toValidationException();
        }

        /*
         * Duplicate / cross-patient document block, mirroring the Legacy RME
         * archive. Found missing by adversarial review.
         *
         * A published legacy record is IMMUTABLE and can only be withdrawn by a
         * reasoned VOID, so filing the same scanned chart against two different
         * patients puts one patient's dental findings permanently into another
         * patient's clinical history. Checked BEFORE the bytes are stored, so a
         * refused upload leaves nothing behind.
         *
         * A VOID collision deliberately does NOT block: void-then-reimport is the
         * correction path, and blocking it would strand the document.
         */
        $this->assertNotDuplicate((int) $patient->getKey(), $sha256);

        $uuid = (string) Str::uuid();
        $path = $this->storage->sourcePath((int) $patient->getKey(), $uuid);

        try {
            $this->storage->putFile($path, $absoluteUpload);
        } catch (LegacyRmePdfException $exception) {
            throw $exception->toValidationException();
        }

        try {
            $import = DB::transaction(fn (): LegacyOdontogramImport => $this->imports->create([
                'uuid' => $uuid,
                'patient_id' => (int) $patient->getKey(),
                // Server-resolved, never submitted.
                'origin_branch_id' => $branch->branchId,
                'source_branch_code' => $branch->branchCode,
                'source_medical_record_number' => $patient->medical_record_number,
                'selected_odontogram_date' => $selectedOdontogramDate,
                'earliest_native_odontogram_date_snapshot' => $cutoff,
                'original_filename' => $this->safeFilename($document),
                'source_disk' => $this->storage->diskName(),
                'source_pdf_path' => $path,
                'source_pdf_sha256' => $sha256,
                'mime_type' => 'application/pdf',
                'size_bytes' => (int) $document->getSize(),
                'status' => LegacyOdontogramImportStatus::UPLOADED,
                'uploaded_by' => (int) $actor->getKey(),
                'uploaded_at' => now(),
            ]));
        } catch (\Throwable $exception) {
            // Compensate: the bytes are on disk but no row owns them.
            $this->storage->deleteDirectory(
                $this->storage->importDirectory((int) $patient->getKey(), $uuid),
            );

            throw $exception;
        }

        $this->audit->logImportEvent(LegacyOdontogramAuditEvent::IMPORT_CREATED, $import, [
            'source_pdf_sha256' => $sha256,
            'selected_odontogram_date' => $selectedOdontogramDate,
            'earliest_native_odontogram_date' => $cutoff,
            'branch_code' => $branch->branchCode,
            'rule_code' => $dateResult->code,
        ], $actor);

        $this->audit->logImportEvent(LegacyOdontogramAuditEvent::PDF_UPLOADED, $import, [
            'size_bytes' => (int) $document->getSize(),
            'mime_type' => 'application/pdf',
        ], $actor);

        return $this->queue($import, $actor);
    }

    /**
     * Hand the staged document to the rasterizer.
     *
     * `afterCommit()` so a worker can never pick the job up before the row it
     * describes is visible to another connection.
     */
    public function queue(LegacyOdontogramImport $import, ?User $actor = null, bool $isRetry = false): LegacyOdontogramImport
    {
        if ($isRetry) {
            $this->feature->assertMigrationEnabled();
        }

        $import = $this->imports->update($import, [
            'status' => LegacyOdontogramImportStatus::QUEUED,
            'failure_code' => null,
            'failure_message' => null,
        ]);

        $this->audit->logImportEvent(
            $isRetry
                ? LegacyOdontogramAuditEvent::PROCESSING_RETRIED
                : LegacyOdontogramAuditEvent::PROCESSING_QUEUED,
            $import,
            [],
            $actor,
        );

        ProcessLegacyOdontogramPdfImport::dispatch((int) $import->getKey())->afterCommit();

        return $import;
    }

    /**
     * @throws ValidationException
     */
    private function resolveOriginBranch(Patient $patient, User $actor): LegacyRmeBranchResolution
    {
        $resolution = $this->branchBinding->resolveForPatient($patient, $actor);

        if ($resolution->failed()) {
            $this->audit->logImportEvent(
                LegacyOdontogramAuditEvent::IMPORT_BRANCH_REJECTED,
                null,
                [
                    'patient_id' => (int) $patient->getKey(),
                    'branch_code' => $resolution->branchCode,
                    'rule_code' => $resolution->code,
                ],
                $actor,
            );

            // Reported on the patient field: the operator's remedy is to fix the
            // patient's Nomor RM (or pick a different patient), never to choose
            // a branch — there is no branch input to correct.
            throw ValidationException::withMessages([
                'patient_id' => (string) $resolution->message,
            ]);
        }

        return $resolution;
    }

    /**
     * Real bytes, not the client's word for them.
     *
     * @throws LegacyRmePdfException
     */
    private function assertUploadedPdf(UploadedFile $document): void
    {
        if (! $document->isValid()) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::INVALID_PDF);
        }

        $maxBytes = (int) config('legacy_odontogram.upload.max_bytes', 20971520);

        if ($maxBytes > 0 && (int) $document->getSize() > $maxBytes) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_FILE_TOO_LARGE);
        }

        $absolute = $document->getRealPath();

        if (! is_string($absolute) || ! is_file($absolute)) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_STORAGE_FAILED);
        }

        $handle = @fopen($absolute, 'rb');

        if ($handle === false) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_STORAGE_FAILED);
        }

        $magic = (string) config('legacy_odontogram.upload.pdf_magic', '%PDF-');
        $header = (string) fread($handle, max(5, strlen($magic)));
        fclose($handle);

        if (! str_starts_with($header, $magic)) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_HEADER_INVALID);
        }
    }

    /**
     * The operator's filename is displayed back to them, so it is reduced to a
     * bounded basename: a path is stripped (it could leak the uploader's
     * directory layout) and the length is capped to the column.
     */
    private function safeFilename(UploadedFile $document): string
    {
        $name = (string) $document->getClientOriginalName();
        $name = basename(str_replace('\\', '/', $name));

        return mb_substr($name !== '' ? $name : 'dokumen.pdf', 0, 255);
    }

    /**
     * Refuse a document whose bytes are already in the archive.
     *
     * Same patient  -> already filed / already in flight; re-uploading creates a
     *                  second immutable copy of one chart.
     * Other patient -> the serious case: the same chart in two clinical histories.
     */
    private function assertNotDuplicate(int $patientId, string $sha256): void
    {
        foreach ($this->records->findByPdfChecksum($sha256) as $record) {
            if ($record->status === LegacyOdontogramRecordStatus::VOID) {
                continue;
            }

            throw ValidationException::withMessages([
                'document' => (int) $record->patient_id === $patientId
                    ? 'Dokumen identik sudah pernah dipublikasikan untuk pasien ini.'
                    : 'Dokumen identik sudah pernah dipublikasikan untuk PASIEN LAIN. Periksa kembali dokumen dan pasien yang dipilih.',
            ]);
        }

        foreach ($this->imports->findByPdfChecksum($sha256) as $import) {
            $status = (string) $import->status;

            // Cancelled staging is the "wrong patient chosen, start again" path,
            // and a published staging row is already covered by the record loop.
            if (in_array($status, [LegacyOdontogramImportStatus::CANCELLED, LegacyOdontogramImportStatus::PUBLISHED], true)) {
                continue;
            }

            $samePatient = (int) $import->patient_id === $patientId;

            if ($status === LegacyOdontogramImportStatus::FAILED && ! $samePatient) {
                continue;
            }

            throw ValidationException::withMessages([
                'document' => $samePatient
                    ? 'Dokumen identik sudah pernah diunggah untuk pasien ini dan masih dalam proses. Gunakan impor tersebut, jangan mengunggah ulang.'
                    : 'Dokumen identik sudah pernah diunggah untuk PASIEN LAIN. Periksa kembali dokumen dan pasien yang dipilih.',
            ]);
        }
    }
}
