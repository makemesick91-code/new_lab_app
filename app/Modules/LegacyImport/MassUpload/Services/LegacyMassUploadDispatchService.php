<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Services;

use App\Models\User;
use App\Modules\LegacyImport\MassUpload\Adapters\LegacyMassUploadAdapter;
use App\Modules\LegacyImport\MassUpload\Exceptions\LegacyMassUploadPackageRejected;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadBatch;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadItem;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadBatchStatus;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadExtractedDocument;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadItemStatus;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadManifestRow;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadReason;
use App\Modules\LegacyImport\Services\LegacySingleActiveDocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Confirmation and bounded dispatch — FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 §19, §23, §26, §27.
 *
 * WHY THERE IS NO SECOND LOCKING SYSTEM
 * -------------------------------------
 * §15 asks for a re-read of slot occupancy under a lock before each new
 * lifecycle. That already happens — inside the canonical
 * createFromUpload(), which takes the advisory lock and re-evaluates every
 * gate. So this service does NOT lock patients, does not consult occupancy
 * again, and does not decide eligibility. It calls the canonical service and
 * records what the canonical service decided.
 *
 * That is the whole stale-preflight story: preflight said eligible, a single
 * upload published in the meantime, createFromUpload() refuses, and this
 * service writes the row as BLOCKED. No duplicate lifecycle is possible
 * because the only code that can create one is the code that holds the lock.
 * Adding our own lock would give two lock orders over the same rows, which is
 * how deadlocks are built.
 *
 * WHY DISPATCH IS BOUNDED RATHER THAN QUEUED WHOLESALE
 * ----------------------------------------------------
 * Each created import dispatches a multi-minute rasterization job on the
 * EXISTING legacy render queue. Mass upload introduces no queue of its own, so
 * the only way to protect those workers is to create fewer lifecycles per pass.
 * A batch legitimately rests in DISPATCHING between passes; that is what makes
 * it resumable rather than an all-or-nothing request that dies at a timeout.
 */
class LegacyMassUploadDispatchService
{
    public function __construct(
        private readonly LegacyMassUploadPackageService $packages,
        private readonly LegacyMassUploadManifestParser $manifests,
        private readonly LegacyMassUploadPreflightService $preflight,
        private readonly LegacyMassUploadAuditService $audit,
        // Consulted ONLY to classify a refusal after the fact — never to decide
        // eligibility. See the ValidationException handler in createOne().
        private readonly LegacySingleActiveDocumentService $slots,
    ) {}

    /**
     * PREFLIGHT_READY -> CONFIRMED, exactly once (§27).
     *
     * The row lock plus the transition guard is what makes a double-clicked
     * "Mulai Upload" harmless: the second request finds the batch already
     * CONFIRMED, cannot transition again, and returns the same batch rather
     * than starting a parallel dispatch.
     */
    public function confirm(LegacyMassUploadBatch $batch, User $actor): LegacyMassUploadBatch
    {
        return DB::transaction(function () use ($batch, $actor): LegacyMassUploadBatch {
            $locked = LegacyMassUploadBatch::query()
                ->whereKey($batch->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== LegacyMassUploadBatchStatus::PREFLIGHT_READY) {
                // Not an error. A repeated confirm is a normal consequence of a
                // double click or a browser retry, and the honest response is
                // the current state.
                return $locked;
            }

            $locked->fill([
                'status' => LegacyMassUploadBatchStatus::CONFIRMED,
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => now(),
            ])->save();

            $this->audit->logBatchEvent('MASS_UPLOAD_CONFIRMED', $locked, [
                'eligible_items' => (int) $locked->eligible_items,
                'warning_items' => (int) $locked->warning_items,
                'blocked_items' => (int) $locked->blocked_items,
            ], $actor);

            return $locked->refresh();
        });
    }

    /**
     * Run one bounded creation pass.
     *
     * Safe to call repeatedly. Each call creates at most
     * `dispatch.max_items_per_request` lifecycles and leaves the batch in
     * DISPATCHING while work remains.
     */
    public function dispatchPass(
        LegacyMassUploadBatch $batch,
        LegacyMassUploadAdapter $adapter,
        User $actor,
    ): LegacyMassUploadBatch {
        $batch = $this->markDispatching($batch, $actor);

        if ($batch->isTerminal()) {
            return $batch;
        }

        $maxItems = max(1, (int) config('legacy_mass_upload.dispatch.max_items_per_request', 100));
        $chunkSize = max(1, (int) config('legacy_mass_upload.dispatch.chunk_size', 25));

        $pending = LegacyMassUploadItem::query()
            ->where('mass_upload_batch_id', $batch->getKey())
            ->whereIn('status', LegacyMassUploadItemStatus::dispatchable())
            ->whereNull('rme_legacy_import_id')
            ->whereNull('odontogram_legacy_import_id')
            // Deterministic order so a resumed pass continues where the last
            // one stopped and a re-run of the same archive behaves identically.
            ->orderBy('row_number')
            ->limit($maxItems)
            ->get();

        if ($pending->isEmpty()) {
            return $this->finalize($batch, $actor);
        }

        // Documents live in the extraction workspace. A resumed batch may find
        // it gone (cleaned, or a new container), so re-extract from the stored
        // archive rather than asking the operator to upload 400 MB again.
        $documents = $this->resolveDocuments($batch, $adapter);

        if ($documents === null) {
            return $this->fail($batch, LegacyMassUploadReason::PACKAGE_UNREADABLE, $actor);
        }

        $created = 0;

        foreach ($pending->chunk($chunkSize) as $chunk) {
            foreach ($chunk as $item) {
                if ($this->createOne($item, $adapter, $documents, $actor)) {
                    $created++;
                }
            }
        }

        $this->audit->logBatchEvent('MASS_UPLOAD_DISPATCH_PASS', $batch, [
            'items_in_pass' => $created,
            'chunk_size' => $chunkSize,
        ], $actor);

        return $this->finalize($batch->refresh(), $actor);
    }

    /**
     * Create one canonical lifecycle for one item.
     *
     * @param  array<string, LegacyMassUploadExtractedDocument>  $documents
     * @return bool whether a lifecycle was created
     */
    private function createOne(
        LegacyMassUploadItem $item,
        LegacyMassUploadAdapter $adapter,
        array $documents,
        User $actor,
    ): bool {
        // Idempotency, re-read immediately before the attempt. Two concurrent
        // passes over the same batch can both have selected this row.
        $fresh = LegacyMassUploadItem::query()->whereKey($item->getKey())->first();

        if ($fresh === null || $fresh->hasCreatedLifecycle() || ! $fresh->isDispatchable()) {
            return false;
        }

        $document = $documents[$fresh->manifest_file_name] ?? null;

        if ($document === null || ! is_file($document->absolutePath)) {
            $this->markItem($fresh, LegacyMassUploadItemStatus::FAILED, LegacyMassUploadReason::MANIFEST_FILE_MISSING, null, $actor);

            return false;
        }

        // The document's bytes must still be the bytes preflight evaluated.
        //
        // This is the source-substitution guard: the workspace is private and
        // server-owned, but a re-extraction between passes, a partial write, or
        // any tampering would otherwise let a DIFFERENT document be filed
        // against the patient that preflight approved. Comparing digests is
        // nearly free and closes that entirely.
        if (is_string($fresh->document_sha256) && $fresh->document_sha256 !== '') {
            $actualDigest = hash_file('sha256', $document->absolutePath);

            if (! is_string($actualDigest) || ! hash_equals($fresh->document_sha256, $actualDigest)) {
                $this->markItem($fresh, LegacyMassUploadItemStatus::FAILED, LegacyMassUploadReason::FILE_INVALID, null, $actor);

                return false;
            }
        }

        $patient = $fresh->patient;

        if ($patient === null) {
            $this->markItem($fresh, LegacyMassUploadItemStatus::BLOCKED, LegacyMassUploadReason::PATIENT_NOT_FOUND, null, $actor);

            return false;
        }

        $row = new LegacyMassUploadManifestRow(
            rowNumber: (int) $fresh->row_number,
            medicalRecordNumber: (string) $fresh->manifest_medical_record_number,
            fileName: (string) $fresh->manifest_file_name,
            selectedDate: $fresh->manifest_selected_date,
            latestDate: $fresh->manifest_latest_date,
        );

        // Synthesised rather than a real HTTP upload. `test: true` is required
        // because the file was written by us and never passed through PHP's
        // upload machinery, so is_uploaded_file() would reject it.
        $upload = new UploadedFile(
            path: $document->absolutePath,
            originalName: $document->logicalName,
            mimeType: 'application/pdf',
            error: null,
            test: true,
        );

        try {
            $importId = $adapter->create($patient, $row, $upload, $actor);

            $adapter->assignCreatedImport($fresh, $importId);

            $fresh->fill([
                'status' => LegacyMassUploadItemStatus::DISPATCHED,
                'reason_code' => null,
                'reason_message' => null,
                'dispatched_at' => now(),
            ])->save();

            $this->audit->logItemEvent('MASS_UPLOAD_ITEM_DISPATCHED', $fresh, [], $actor);

            return true;
        } catch (ValidationException $refusal) {
            /*
             * EVERY domain refusal arrives here, including the slot one.
             *
             * createFromUpload() catches LegacyDocumentSlotOccupied itself —
             * it has compensation to run (delete the orphaned bytes) and an
             * audit row to write after the transaction has rolled back — and
             * rethrows it as a ValidationException. So catching
             * LegacyDocumentSlotOccupied here would never fire.
             *
             * The precise reason still matters: §46/§47 require
             * ALREADY_PUBLISHED and ACTIVE_IMPORT_EXISTS to be distinguishable
             * in the report, and collapsing both into DOMAIN_REFUSED would make
             * "5 already published, 3 import in flight" unreadable.
             *
             * So we ask the AUTHORITY rather than parse the message: if the
             * patient's slot is occupied right now, this refusal was the slot
             * and the occupancy carries its own exact code. Matching on message
             * text would break the first time a translation changed.
             */
            $occupancy = $this->slots->previewForNewLifecycle(
                $adapter->importType(),
                (int) $patient->getKey(),
            );

            if ($occupancy !== null) {
                $this->markItem(
                    $fresh,
                    LegacyMassUploadItemStatus::BLOCKED,
                    $this->mapOccupancyCode($occupancy->code()),
                    $occupancy->message(),
                    $actor,
                );

                return false;
            }

            // Any other refusal. The domain's own message is carried through
            // because it was written for an operator; only the first is taken
            // so the report stays readable.
            $this->markItem(
                $fresh,
                LegacyMassUploadItemStatus::BLOCKED,
                LegacyMassUploadReason::DOMAIN_REFUSED,
                $this->firstMessage($refusal),
                $actor,
            );

            return false;
        } catch (Throwable $exception) {
            // Technical failure. The operator gets a safe generic message; the
            // detail goes to the log where it belongs.
            Log::warning('Legacy mass upload item failed technically.', [
                'mass_upload_item_id' => $fresh->getKey(),
                'mass_upload_batch_id' => $fresh->mass_upload_batch_id,
                'exception' => $exception::class,
            ]);

            $this->markItem($fresh, LegacyMassUploadItemStatus::FAILED, LegacyMassUploadReason::PROCESSING_ERROR, null, $actor);

            return false;
        }
    }

    /**
     * Ensure the extracted documents are on disk, re-extracting if needed.
     *
     * @return array<string, LegacyMassUploadExtractedDocument>|null
     */
    private function resolveDocuments(LegacyMassUploadBatch $batch, LegacyMassUploadAdapter $adapter): ?array
    {
        try {
            $contents = $this->packages->validateAndExtract(
                packageRelativePath: (string) $batch->package_path,
                batchUuid: (string) $batch->uuid,
                manifestParser: $this->manifests,
                importType: $adapter->importType(),
            );

            return $contents->documents;
        } catch (LegacyMassUploadPackageRejected $rejected) {
            Log::warning('Legacy mass upload could not re-extract its package for dispatch.', [
                'mass_upload_batch_id' => $batch->getKey(),
                'reason_code' => $rejected->reasonCode,
            ]);

            return null;
        } catch (Throwable $exception) {
            Log::warning('Legacy mass upload package re-extraction failed.', [
                'mass_upload_batch_id' => $batch->getKey(),
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    private function markDispatching(LegacyMassUploadBatch $batch, User $actor): LegacyMassUploadBatch
    {
        return DB::transaction(function () use ($batch, $actor): LegacyMassUploadBatch {
            $locked = LegacyMassUploadBatch::query()
                ->whereKey($batch->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === LegacyMassUploadBatchStatus::CONFIRMED) {
                $locked->fill([
                    'status' => LegacyMassUploadBatchStatus::DISPATCHING,
                    'dispatch_started_at' => $locked->dispatch_started_at ?? now(),
                ])->save();

                $this->audit->logBatchEvent('MASS_UPLOAD_DISPATCH_STARTED', $locked, [], $actor);

                return $locked->refresh();
            }

            return $locked;
        });
    }

    /**
     * Recount and close the batch if nothing dispatchable remains.
     */
    private function finalize(LegacyMassUploadBatch $batch, User $actor): LegacyMassUploadBatch
    {
        return DB::transaction(function () use ($batch, $actor): LegacyMassUploadBatch {
            $locked = LegacyMassUploadBatch::query()
                ->whereKey($batch->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $counts = $this->preflight->countsFor((int) $locked->getKey());

            $remaining = LegacyMassUploadItem::query()
                ->where('mass_upload_batch_id', $locked->getKey())
                ->whereIn('status', LegacyMassUploadItemStatus::dispatchable())
                ->whereNull('rme_legacy_import_id')
                ->whereNull('odontogram_legacy_import_id')
                ->count();

            $attributes = [
                'total_items' => $counts['total'],
                'eligible_items' => $counts['eligible'],
                'warning_items' => $counts['warning'],
                'blocked_items' => $counts['blocked'],
                'dispatched_items' => $counts['dispatched'],
                'failed_items' => $counts['failed'],
            ];

            if ($remaining === 0 && $locked->status === LegacyMassUploadBatchStatus::DISPATCHING) {
                // "Completed with blocked items" is the normal shape of a real
                // migration and must not read as a failure (§28).
                $attributes['status'] = ($counts['blocked'] > 0 || $counts['failed'] > 0)
                    ? LegacyMassUploadBatchStatus::COMPLETED_WITH_BLOCKED_ITEMS
                    : LegacyMassUploadBatchStatus::COMPLETED;
                $attributes['completed_at'] = now();
            }

            $locked->fill($attributes)->save();

            if (isset($attributes['status'])) {
                $this->audit->logBatchEvent('MASS_UPLOAD_COMPLETED', $locked, [
                    'dispatched_items' => $counts['dispatched'],
                    'blocked_items' => $counts['blocked'],
                    'failed_items' => $counts['failed'],
                ], $actor);

                // The workspace is only removed once nothing more will be
                // created from it. Canonical source documents already stored by
                // the single-item service live elsewhere and are untouched.
                $this->packages->cleanWorkspace((string) $locked->uuid);

                $locked->fill(['workspace_cleaned_at' => now()])->save();
            }

            return $locked->refresh();
        });
    }

    private function fail(LegacyMassUploadBatch $batch, string $reasonCode, User $actor): LegacyMassUploadBatch
    {
        $batch->fill([
            'status' => LegacyMassUploadBatchStatus::FAILED,
            'failure_code' => $reasonCode,
            'failure_message' => LegacyMassUploadReason::message($reasonCode),
        ])->save();

        $this->audit->logBatchEvent('MASS_UPLOAD_FAILED', $batch, [
            'failure_code' => $reasonCode,
        ], $actor);

        return $batch->refresh();
    }

    private function markItem(
        LegacyMassUploadItem $item,
        string $status,
        string $reasonCode,
        ?string $message,
        User $actor,
    ): void {
        $item->fill([
            'status' => $status,
            'reason_code' => $reasonCode,
            'reason_message' => $message ?? LegacyMassUploadReason::message($reasonCode),
        ])->save();

        $this->audit->logItemEvent(
            $status === LegacyMassUploadItemStatus::BLOCKED
                ? 'MASS_UPLOAD_ITEM_BLOCKED'
                : 'MASS_UPLOAD_ITEM_FAILED',
            $item,
            ['reason_code' => $reasonCode],
            $actor,
        );
    }

    /**
     * First validation message, truncated.
     *
     * Only the first: a refusal can carry several and a report row that runs to
     * a paragraph is a report nobody reads.
     */
    private function firstMessage(ValidationException $exception): ?string
    {
        foreach ($exception->errors() as $messages) {
            foreach ((array) $messages as $message) {
                if (is_string($message) && trim($message) !== '') {
                    return mb_substr($message, 0, 500);
                }
            }
        }

        return null;
    }

    private function mapOccupancyCode(?string $code): string
    {
        return match ($code) {
            'ALREADY_PUBLISHED' => LegacyMassUploadReason::ALREADY_PUBLISHED,
            'ACTIVE_IMPORT_EXISTS' => LegacyMassUploadReason::ACTIVE_IMPORT_EXISTS,
            default => LegacyMassUploadReason::ACTIVE_IMPORT_EXISTS,
        };
    }
}
