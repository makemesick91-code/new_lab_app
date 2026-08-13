<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Models\User;
use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmeMalwareScannerInterface;
use App\Modules\LegacyRme\Jobs\ProcessLegacyRmePdfImport;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeBranchResolution;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmePdfException;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
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
 *   → origin branch DERIVED from the patient's Nomor RM (FIX-ROLL2-1)
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
        private readonly LegacyRmeBranchResolver $branchResolver,
        private readonly LegacyRmeBranchAdmissionService $admission,
        private readonly LegacyRmeIngestionCapacityService $capacity,
    ) {}

    /**
     * @param  string|null  $latestRmeDate  the LATEST clinical date the document
     *                                      represents; null means a single-date document
     *
     * @throws ValidationException
     */
    public function createFromUpload(
        Patient $patient,
        string $selectedRmeDate,
        ?int $originBranchId,
        UploadedFile $document,
        User $actor,
        ?string $latestRmeDate = null,
    ): LegacyRmeImport {
        $this->feature->assertMigrationEnabled();

        // 1A owns the date domain. Never re-derive a bound here. The whole
        // declared range is validated, not just the representative date.
        $dateResult = $this->dateRules->assert(
            $patient,
            $selectedRmeDate,
            LegacyRmeDateRuleService::FIELD,
            $latestRmeDate,
        );
        $cutoff = $this->dateRules->snapshotCutoff($patient);

        // A single-date document stores the same value at both ends, so the
        // persisted range is always complete and publish-time revalidation
        // never has to guess what the operator meant.
        $latestRmeDate = (string) ($dateResult->context['latest_rme_date'] ?? $selectedRmeDate);

        // FIX-ROLL2-1: the branch is DERIVED from the patient's Nomor RM. The
        // submitted value is only compared, never used as the answer.
        $branch = $this->resolveOriginBranch($patient, $originBranchId, $actor);
        $originBranchId = $branch->branchId;

        // ROLL-3: the branch must additionally be ADMITTED to the running
        // migration wave, and the pipeline must have room. Both are checked
        // here — before a single byte is stored — so a refused intake leaves no
        // staging row, no orphan file and no queued job behind.
        $this->assertBranchAdmitted($branch, $patient, $actor);
        $this->assertIngestionCapacity($branch, $patient, $actor);

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
                'latest_rme_date' => $latestRmeDate,
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
            'latest_rme_date' => $latestRmeDate,
            'earliest_native_rme_date' => $cutoff,
            // Evidence for the two migration cases: bounded by a native RME, or
            // legitimately unbounded because the patient has none.
            'reference_mode' => $dateResult->referenceMode(),
            'branch_code' => $branch->branchCode,
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
        // ROLL-3: a RETRY starts new render work and therefore consumes fresh
        // capacity, so it is ingestion and passes the same gate. The initial
        // dispatch from createFromUpload was already admitted a few lines
        // earlier and is not re-checked — re-deciding there could only differ
        // if the config changed mid-request, and refusing then would strand a
        // stored file with no staging row.
        if ($isRetry) {
            $this->assertRetryAdmitted($import, $actor);
        }

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
     * Resolve the origin branch of a staged document — FIX-ROLL2-1.
     *
     * IMPORTANT — this field is NOT merely descriptive. `origin_branch_id` is
     * the column row visibility keys off: LegacyRmeImportRepository::scoped()
     * filters on it and LegacyRmeImportPolicy::inScope() evaluates it against
     * the caller's scope. It therefore decides which branch owns and can see
     * the resulting row, which makes it a security property.
     *
     * Because it is a security property it is DERIVED, not chosen: the branch
     * comes from the branch-code segment of the patient's own Nomor RM
     * (`DG-TKM1-2024-9985` → `TKM1` → Cabang Telkomas). The previous behaviour
     * — trust a submitted id, or silently anchor a blank one to the uploader's
     * first branch — could file a patient's history under a branch that does
     * not own that patient, and quietly change who could read it afterwards.
     *
     * There is no fallback. An unresolvable, unknown, ambiguous, inactive,
     * non-RME or out-of-scope branch fails closed so the operator fixes the
     * patient master data, rather than the archive landing somewhere plausible
     * but wrong.
     *
     * @throws ValidationException
     */
    private function resolveOriginBranch(Patient $patient, ?int $submittedBranchId, User $actor): LegacyRmeBranchResolution
    {
        $resolution = $this->branchResolver->resolveForPatient($patient, $actor);
        $resolution = $this->branchResolver->assertNoConflict($resolution, $submittedBranchId);

        if ($resolution->failed()) {
            $this->audit->logImportEvent(
                LegacyRmeAuditEvent::IMPORT_BRANCH_REJECTED,
                null,
                $resolution->auditContext() + ['patient_id' => (int) $patient->getKey()],
                $actor,
            );

            throw ValidationException::withMessages([
                'origin_branch_id' => (string) $resolution->message,
            ]);
        }

        return $resolution;
    }

    /**
     * LEGACY-RME-PDF-ROLL-3 — the branch must be admitted to the running wave.
     *
     * Capability (the feature flag) and admission (this wave) are separate
     * gates and both must pass. The value checked is the RM-DERIVED branch, so
     * no request field can influence the outcome.
     *
     * A refusal is audited as its own action: nothing was created, and reusing
     * IMPORT_CREATED would put a lie in the trail.
     *
     * @throws ValidationException
     */
    private function assertBranchAdmitted(LegacyRmeBranchResolution $branch, Patient $patient, User $actor): void
    {
        $decision = $this->admission->decide($branch);

        if ($decision->denied()) {
            $this->audit->logImportEvent(
                LegacyRmeAuditEvent::IMPORT_ADMISSION_REJECTED,
                null,
                $decision->auditContext() + ['patient_id' => (int) $patient->getKey()],
                $actor,
            );

            throw ValidationException::withMessages([
                'legacy_rme_admission' => (string) $decision->message,
            ]);
        }
    }

    /**
     * LEGACY-RME-PDF-ROLL-3 — a retry restarts migration work, so the branch
     * must still be admitted and the pipeline must still have room.
     *
     * The branch code comes from the row's own `origin_branch_id`, which was
     * derived from the patient's Nomor RM when the import was created. It is
     * re-read here rather than trusted from the caller.
     *
     * @throws ValidationException
     */
    private function assertRetryAdmitted(LegacyRmeImport $import, ?User $actor): void
    {
        $branchCode = $import->originBranch?->code;

        $decision = $this->admission->decideForBranchCode(
            is_string($branchCode) ? $branchCode : null,
        );

        if ($decision->denied()) {
            $this->audit->logImportEvent(
                LegacyRmeAuditEvent::IMPORT_ADMISSION_REJECTED,
                $import,
                $decision->auditContext(),
                $actor,
            );

            throw ValidationException::withMessages([
                'legacy_rme_admission' => (string) $decision->message,
            ]);
        }

        $capacity = $this->capacity->evaluate();

        if ($capacity->isSaturated()) {
            $this->audit->logImportEvent(
                LegacyRmeAuditEvent::INGESTION_THROTTLED,
                $import,
                $capacity->auditContext(),
                $actor,
            );

            throw ValidationException::withMessages([
                'legacy_rme_capacity' => (string) $capacity->message,
            ]);
        }
    }

    /**
     * LEGACY-RME-PDF-ROLL-3 — the rendering pipeline must have room.
     *
     * Backpressure throttles NEW intake only. Nothing already queued is
     * cancelled, re-prioritised or touched: a saturated pipeline is a reason to
     * stop adding work, never a reason to operate on clinical jobs in flight.
     *
     * @throws ValidationException
     */
    private function assertIngestionCapacity(LegacyRmeBranchResolution $branch, Patient $patient, User $actor): void
    {
        $capacity = $this->capacity->evaluate();

        if ($capacity->isSaturated()) {
            $this->audit->logImportEvent(
                LegacyRmeAuditEvent::INGESTION_THROTTLED,
                null,
                $capacity->auditContext() + [
                    'patient_id' => (int) $patient->getKey(),
                    'branch_code' => $branch->branchCode,
                ],
                $actor,
            );

            throw ValidationException::withMessages([
                'legacy_rme_capacity' => (string) $capacity->message,
            ]);
        }
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
