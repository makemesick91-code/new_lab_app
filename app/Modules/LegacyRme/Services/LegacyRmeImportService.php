<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Models\User;
use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmeMalwareScannerInterface;
use App\Modules\LegacyRme\Jobs\ProcessLegacyRmePdfImport;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeWaveBranch;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeBranchResolution;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeOperationsDecision;
use App\Modules\LegacyRme\Support\LegacyRmePdfException;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use App\Modules\LegacyRme\Support\LegacyRmeSourceRmBinding;
use App\Modules\LegacyRme\Support\LegacyRmeSourceRmFailure;
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
 *   → SOURCE RM → PATIENT BINDING (SOURCE-RM-BINDING-1, first for a reason)
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
 * WHY THE BINDING GATE IS FIRST. Everything after it costs something real: a
 * hashed file, a stored PDF, a reserved quota slot, a queued render job. A
 * document about the WRONG PATIENT must consume none of them, so the question
 * "is this document even about this patient?" is answered before any other
 * question is asked. Refusing here leaves no staging row, no orphan file, no
 * queued job and — because quota is reserved inside the transaction at the very
 * end — no consumed migration slot.
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
        private readonly LegacyRmeOperationsGateService $operations,
        private readonly LegacyRmeMigrationQuotaService $quota,
        private readonly LegacyRmeWaveBindingService $waveBinding,
        private readonly LegacyRmeSourcePatientBindingService $sourceBinding,
    ) {}

    /**
     * @param  string|null  $sourceRm  the Nomor RM the operator CONFIRMED reading on
     *                                 the document — never the selected patient's own RM,
     *                                 never a filename, never OCR output
     * @param  string|null  $latestRmeDate  the LATEST clinical date the document
     *                                      represents; null means a single-date document
     *
     * @throws ValidationException
     */
    public function createFromUpload(
        Patient $patient,
        string $selectedRmeDate,
        ?string $sourceRm,
        ?int $originBranchId,
        UploadedFile $document,
        User $actor,
        ?string $latestRmeDate = null,
    ): LegacyRmeImport {
        $this->feature->assertMigrationEnabled();

        // SOURCE-RM-BINDING-1 — FIRST, and before anything is spent.
        //
        // The document must state, independently of the operator's row
        // selection, which patient it is about; the server resolves that claim
        // exactly and refuses unless it names the SELECTED patient. This is the
        // gate the Wave-2 wrong-patient binding did not have.
        $binding = $this->assertSourcePatientBinding($sourceRm, $patient, $actor);

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

        // ROLL-4: only NOW, after ROLL-3 has admitted, may the operations layer
        // speak — and the only thing it can say is "no". Running it last is what
        // makes "ROLL-4 can only narrow, never widen" true by construction
        // rather than by inspection.
        $operations = $this->assertOperationsCleared($branch, $patient, $actor);

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
            $import = DB::transaction(function () use (
                $uuid, $patient, $originBranchId, $selectedRmeDate, $latestRmeDate, $cutoff, $document, $path, $sha256, $actor, $operations, $branch, $binding
            ): LegacyRmeImport {
                /*
                 * ROLL-4 — the AUTHORITATIVE quota reservation.
                 *
                 * Inside this transaction, taking `FOR UPDATE` on the day's
                 * buckets, and therefore the only quota decision that may be
                 * trusted: the pre-check before the upload was advisory and
                 * another operator may have taken the last slot since.
                 *
                 * Sharing the transaction with the row it counts is what makes
                 * the ledger self-correcting — if this insert fails, the
                 * increment rolls back with it and no compensating write is
                 * needed.
                 */
                if ($operations !== null) {
                    $this->quota->reserve($operations['wave'], $operations['branch'], $branch->branchCode);
                }

                return $this->imports->create([
                    'uuid' => $uuid,
                    'patient_id' => (int) $patient->getKey(),
                    'origin_branch_id' => $originBranchId,
                    // Attribution is RECORDED, not inferred: reconciliation must
                    // be able to say which documents belonged to this wave
                    // without guessing from a branch plus a date window. NULL
                    // only where the operations layer is not enforced, and NULL
                    // never means "the current wave".
                    'migration_wave_id' => $operations !== null
                        ? (int) $operations['wave']->getKey()
                        : null,
                    'selected_rme_date' => $selectedRmeDate,
                    'latest_rme_date' => $latestRmeDate,
                    'earliest_native_rme_date_snapshot' => $cutoff,
                    // SOURCE-RM-BINDING-1 — what the DOCUMENT asserted about its
                    // own patient. The raw transcription is evidence and is kept
                    // verbatim; the normalized value is what identity was
                    // resolved on; the resolution code records HOW. All three
                    // are written once, here, and never updated again.
                    'source_rm_raw' => $binding->rawSourceRm,
                    'source_rm_normalized' => $binding->normalizedSourceRm,
                    'source_rm_resolution' => $binding->resolutionCode,
                    'original_filename' => $this->safeFilename($document),
                    'source_disk' => $this->storage->diskName(),
                    'source_pdf_path' => $path,
                    'source_pdf_sha256' => $sha256,
                    'mime_type' => 'application/pdf',
                    'size_bytes' => (int) $document->getSize(),
                    'status' => LegacyRmeImportStatus::UPLOADED,
                    'uploaded_by' => (int) $actor->getKey(),
                    'uploaded_at' => now(),
                ]);
            });
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
            // SOURCE-RM-BINDING-1 — the accepted binding, so the trail records
            // which asserted identity this document was filed on and how it was
            // established, not merely that an import happened.
            'source_rm_normalized' => $binding->normalizedSourceRm,
            'source_rm_resolution' => $binding->resolutionCode,
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
     * LEGACY-RME-SOURCE-RM-BINDING-1 — the document must name the patient it is
     * being filed under.
     *
     * THE RULE IS DECIDED IN THE SERVICE, NOT AT THE HTTP BOUNDARY. The form
     * request only checks the field's SHAPE. This runs on every path that can
     * create an import — the browser, a direct service call, a future adapter —
     * so no surface can be a weaker door than another, and the test suite proves
     * it by calling this service directly with a mismatched patient.
     *
     * A REFUSAL IS AUDITED AS ITS OWN ACTION. Nothing was created, so reusing
     * IMPORT_CREATED would put a lie in the trail — the same reasoning
     * FIX-ROLL2-1 and ROLL-3 already applied to their own refusals. The payload
     * carries the normalized number and the reason code, never a patient name
     * and never the id of whoever the number actually resolved to.
     *
     * @throws ValidationException
     */
    private function assertSourcePatientBinding(?string $sourceRm, Patient $patient, User $actor): LegacyRmeSourceRmBinding
    {
        $binding = $this->sourceBinding->bind($sourceRm, $patient);

        if ($binding->failed()) {
            $this->audit->logImportEvent(
                LegacyRmeAuditEvent::SOURCE_RM_BINDING_REJECTED,
                null,
                $binding->auditContext() + ['patient_id' => (int) $patient->getKey()],
                $actor,
            );

            throw ValidationException::withMessages([
                LegacyRmeSourceRmFailure::FIELD => (string) $binding->message,
            ]);
        }

        return $binding;
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
        // SOURCE-RM-BINDING-1 — a retry restarts render work on a document, so
        // the document must still be about the patient it is filed under.
        //
        // The stored source RM is immutable, so this can only fail when the
        // MASTER DATA moved underneath it: the patient's Nomor RM was corrected,
        // the patient was soft-deleted, a duplicate appeared. Stranding such a
        // row mid-render is the intended outcome — the operator cancels and
        // re-imports, which is exactly why cancel is never gated by this rule.
        //
        // A PRE-ENFORCEMENT row (staged before capture existed) is refused here
        // too, for the same reason it is refused at review and publish: its
        // binding cannot be verified at all, and guessing is what this sprint
        // removed.
        $this->assertStagedBindingStillValid($import, $actor);

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

        /*
         * ROLL-4 — a retry starts migration WORK, so the wave and the branch
         * must still be running.
         *
         * Deliberately NARROWER than the upload gate. Quota is not charged
         * again: the document was already accepted and already counted, and
         * billing a transient Poppler failure a second time would silently cost
         * a branch capacity it never used. The operator assignment is not
         * re-checked either — re-checking it would strand a reviewed document
         * because the colleague who uploaded it went on leave.
         *
         * What DOES still apply is state: a paused or draining wave means "stop
         * starting work", and a retry starts work.
         */
        $operations = $this->operations->decideForRetry(
            is_string($branchCode) ? $branchCode : null,
        );

        if ($operations->denied()) {
            $this->audit->logImportEvent(
                LegacyRmeAuditEvent::IMPORT_OPERATIONS_REJECTED,
                $import,
                $operations->auditContext(),
                $actor,
            );

            throw ValidationException::withMessages([
                'legacy_rme_operations' => (string) $operations->message,
            ]);
        }
    }

    /**
     * LEGACY-RME-SOURCE-RM-BINDING-1 — re-verify a STAGED row's binding.
     *
     * The canonical guard is asked; nothing is re-implemented here. Only the
     * refusal handling — an audit row plus a field error — belongs to this
     * service.
     *
     * @throws ValidationException
     */
    private function assertStagedBindingStillValid(LegacyRmeImport $import, ?User $actor): void
    {
        $binding = $this->sourceBinding->verifyStaged($import);

        if ($binding->failed()) {
            $this->audit->logImportEvent(
                LegacyRmeAuditEvent::SOURCE_RM_BINDING_REJECTED,
                $import,
                $binding->auditContext(),
                $actor,
            );

            throw ValidationException::withMessages([
                LegacyRmeSourceRmFailure::FIELD => (string) $binding->message,
            ]);
        }
    }

    /**
     * LEGACY-RME-PDF-ROLL-4 — the operations layer must clear this intake.
     *
     * Runs AFTER ROLL-3's capability, admission and capacity gates, and can only
     * refuse: there is no branch here that turns a ROLL-3 denial into an
     * acceptance. That ordering is the entire "narrow, never widen" guarantee.
     *
     * Returns the wave and enrollment the document belongs to so the caller can
     * reserve quota and record attribution inside its transaction, or NULL when
     * the layer is not enforced (a local/CI posture the readiness gate FAILs in
     * any environment that expects a real worker).
     *
     * A refusal is audited under its own action. Reusing ROLL-3's
     * IMPORT_ADMISSION_REJECTED would tell an operator the rollout declined the
     * branch when in fact the branch is admitted and their assignment, the
     * wave's pause state or today's quota is what refused — different problems
     * with different remedies.
     *
     * @return array{wave: LegacyRmeMigrationWave, branch: LegacyRmeWaveBranch}|null
     *
     * @throws ValidationException
     */
    private function assertOperationsCleared(LegacyRmeBranchResolution $branch, Patient $patient, User $actor): ?array
    {
        $decision = $this->operations->decide($actor, $branch->branchCode);

        if ($decision->denied()) {
            $this->audit->logImportEvent(
                LegacyRmeAuditEvent::IMPORT_OPERATIONS_REJECTED,
                null,
                $decision->auditContext() + ['patient_id' => (int) $patient->getKey()],
                $actor,
            );

            throw ValidationException::withMessages([
                'legacy_rme_operations' => (string) $decision->message,
            ]);
        }

        if ($decision->code === LegacyRmeOperationsDecision::CODE_NOT_ENFORCED) {
            return null;
        }

        $wave = $this->waveBinding->resolveWave();

        if ($wave === null) {
            // Only reachable if the wave disappeared between the decision and
            // here. Fail closed rather than accepting an unattributable
            // document.
            throw ValidationException::withMessages([
                'legacy_rme_operations' => 'Gelombang migrasi tidak lagi tersedia, sehingga dokumen tidak diterima.',
            ]);
        }

        $waveBranch = $this->operations->resolveWaveBranch($wave, (string) $branch->branchCode);

        if ($waveBranch === null) {
            throw ValidationException::withMessages([
                'legacy_rme_operations' => 'Pendaftaran cabang pada gelombang migrasi tidak lagi tersedia.',
            ]);
        }

        return ['wave' => $wave, 'branch' => $waveBranch];
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
