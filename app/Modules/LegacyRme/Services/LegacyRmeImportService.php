<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmeMalwareScannerInterface;
use App\Modules\LegacyRme\Jobs\ProcessLegacyRmePdfImport;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmePdfException;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use App\Modules\Patient\Models\Patient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-1B — intake of one historical RME document.
 *
 * Order of operations, and why:
 *
 *   feature flag
 *   → date rules (1A service, never re-derived here)
 *   → origin branch validation
 *   → structural file validation + server-side SHA-256
 *   → exact-file duplicate precheck
 *   → optional malware scan
 *   → store the private source PDF
 *   → DB transaction: create the staging row
 *   → AFTER COMMIT: dispatch the processing job
 *
 * The file is written before the transaction because a filesystem write cannot
 * participate in it; if the transaction then fails, the orphan file is removed
 * in the catch block. The job is dispatched only after commit, so a worker can
 * never pick up an id that is not yet visible.
 *
 * NOTHING here rasterizes a PDF: rendering only ever happens in the queued job.
 */
class LegacyRmeImportService
{
    public function __construct(
        private readonly LegacyRmeImportRepositoryInterface $imports,
        private readonly LegacyRmeDateRuleService $dateRules,
        private readonly LegacyRmeDuplicateDetectionService $duplicates,
        private readonly LegacyRmeStorageService $storage,
        private readonly LegacyRmeAuditService $audit,
        private readonly LegacyRmeFeatureGuard $feature,
        private readonly LegacyRmeMalwareScannerInterface $malware,
        private readonly BranchService $branches,
        private readonly LegacyRmeWorkspaceScope $scope,
    ) {}

    /**
     * @throws ValidationException
     */
    public function createFromUpload(
        Patient $patient,
        string $selectedRmeDate,
        ?int $originBranchId,
        UploadedFile $document,
        User $actor,
    ): LegacyRmeImport {
        $this->feature->assertEnabled();

        // 1A owns the date domain. Never re-derive a bound here.
        $this->dateRules->assert($patient, $selectedRmeDate);
        $cutoff = $this->dateRules->snapshotCutoff($patient);

        $originBranchId = $this->resolveOriginBranch($originBranchId, $actor);

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

            $duplicate = $this->duplicates->evaluate((int) $patient->getKey(), $sha256);

            if ($duplicate->blocked) {
                $this->audit->logImportEvent(
                    LegacyRmeAuditEvent::DUPLICATE_DETECTED,
                    null,
                    $duplicate->auditContext() + [
                        'patient_id' => (int) $patient->getKey(),
                        'source_pdf_sha256' => $sha256,
                    ],
                    $actor,
                );

                throw ValidationException::withMessages(['document' => $duplicate->message]);
            }

            $scan = $this->malware->scan($absoluteUpload);
        } catch (LegacyRmePdfException $exception) {
            throw $exception->toValidationException();
        }

        $uuid = (string) Str::uuid();
        $path = $this->storage->sourcePath((int) $patient->getKey(), $uuid);

        try {
            $this->storage->putFile($path, $absoluteUpload);
        } catch (LegacyRmePdfException $exception) {
            throw $exception->toValidationException();
        }

        try {
            $import = DB::transaction(fn (): LegacyRmeImport => $this->imports->create([
                'uuid' => $uuid,
                'patient_id' => (int) $patient->getKey(),
                'origin_branch_id' => $originBranchId,
                'selected_rme_date' => $selectedRmeDate,
                'earliest_native_rme_date_snapshot' => $cutoff,
                'original_filename' => $this->safeFilename($document),
                'source_disk' => $this->storage->diskName(),
                'source_pdf_path' => $path,
                'source_pdf_sha256' => $sha256,
                'mime_type' => 'application/pdf',
                'size_bytes' => (int) $document->getSize(),
                'status' => LegacyRmeImportStatus::UPLOADED,
                'uploaded_by' => (int) $actor->getKey(),
                'uploaded_at' => now(),
            ]));
        } catch (\Throwable $exception) {
            // The staging row never existed, so the stored bytes are an orphan.
            $this->storage->deleteDirectory($this->storage->importDirectory((int) $patient->getKey(), $uuid));

            throw $exception;
        }

        $this->audit->logImportEvent(LegacyRmeAuditEvent::IMPORT_CREATED, $import, [
            'source_pdf_sha256' => $sha256,
            'selected_rme_date' => $selectedRmeDate,
            'earliest_native_rme_date' => $cutoff,
        ], $actor);

        $this->audit->logImportEvent(LegacyRmeAuditEvent::PDF_UPLOADED, $import, [
            'size_bytes' => (int) $document->getSize(),
            'mime_type' => 'application/pdf',
            'malware_scanned' => (bool) ($scan['scanned'] ?? false),
        ], $actor);

        $this->queue($import, $actor);

        return $import;
    }

    /**
     * Move an import into the processing queue and dispatch the worker after
     * the surrounding transaction commits.
     */
    public function queue(LegacyRmeImport $import, ?User $actor = null, bool $isRetry = false): LegacyRmeImport
    {
        $import = $this->imports->update($import, [
            'status' => LegacyRmeImportStatus::QUEUED,
            'failure_code' => null,
            'failure_message' => null,
        ]);

        $this->audit->logImportEvent(
            $isRetry ? LegacyRmeAuditEvent::PROCESSING_RETRIED : LegacyRmeAuditEvent::PROCESSING_QUEUED,
            $import,
            [],
            $actor,
        );

        ProcessLegacyRmePdfImport::dispatch((int) $import->getKey())
            ->afterCommit();

        return $import;
    }

    /**
     * Resolve the origin branch of a staged document.
     *
     * IMPORTANT — this field is NOT merely descriptive. `origin_branch_id` is
     * the column row visibility keys off: LegacyRmeImportRepository::scoped()
     * filters on it and LegacyRmeImportPolicy::inScope() evaluates it against
     * the caller's scope. A request-supplied value therefore decides which
     * branch owns and can see the resulting row, so it is validated against the
     * UPLOADER'S OWN scope, not just the global RME-enabled set.
     *
     * Without that check a branch-scoped operator could file a document into a
     * branch they have no authority over — and then lose access to their own
     * row, because every later read would 404 for them.
     */
    private function resolveOriginBranch(?int $originBranchId, User $actor): ?int
    {
        // A row with no origin branch is visible to the governance tier only.
        if ($originBranchId === null) {
            if ($this->scope->includesUnscopedRowsFor($actor)) {
                return null;
            }

            // A scoped operator must not create a row they could never manage;
            // anchor it to their own branch instead.
            $own = $this->scope->branchIdsFor($actor);

            if ($own === []) {
                throw ValidationException::withMessages([
                    'origin_branch_id' => 'Cabang asal tidak dapat ditentukan untuk akun ini.',
                ]);
            }

            return (int) $own[0];
        }

        if (! in_array((int) $originBranchId, $this->branches->rmeEnabledIds(), true)
            || ! $this->scope->allows($actor, (int) $originBranchId)) {
            throw ValidationException::withMessages([
                'origin_branch_id' => 'Cabang asal tidak valid.',
            ]);
        }

        return (int) $originBranchId;
    }

    /**
     * Structural checks that must hold before a byte is stored. Deep structural
     * inspection (encryption, page count, dimensions) happens in the queued job
     * so an HTTP request never runs a PDF tool.
     *
     * @throws LegacyRmePdfException
     */
    private function assertUploadedPdf(UploadedFile $document): void
    {
        if (! $document->isValid()) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::INVALID_PDF);
        }

        $maxBytes = (int) config('legacy_rme.upload.max_bytes', 20971520);

        if ($maxBytes > 0 && (int) $document->getSize() > $maxBytes) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_FILE_TOO_LARGE);
        }

        // The DETECTED mime type, from the file's own bytes — never the
        // client-declared Content-Type, and never the extension.
        $allowed = (array) config('legacy_rme.upload.allowed_mimes', ['application/pdf']);

        if (! in_array((string) $document->getMimeType(), $allowed, true)) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::INVALID_PDF);
        }

        $path = $document->getRealPath();

        if (! is_string($path) || ! is_file($path)) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::INVALID_PDF);
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::INVALID_PDF);
        }

        $head = (string) fread($handle, 8);
        fclose($handle);

        $magic = (string) config('legacy_rme.upload.pdf_magic', '%PDF-');

        if (! str_starts_with($head, $magic)) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_HEADER_INVALID);
        }
    }

    /**
     * The original filename is operator-supplied text. It is kept only as a
     * display label, so it is stripped of any directory component and bounded —
     * it is never used to build a storage path.
     */
    private function safeFilename(UploadedFile $document): string
    {
        $name = basename((string) $document->getClientOriginalName());
        $name = preg_replace('/[^\p{L}\p{N}._ -]/u', '', $name) ?? '';
        $name = trim($name);

        return $name !== '' ? mb_substr($name, 0, 255) : 'dokumen.pdf';
    }
}
