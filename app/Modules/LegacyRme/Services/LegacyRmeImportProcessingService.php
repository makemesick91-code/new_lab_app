<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Models\User;
use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeImportPageStatus;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmePdfException;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use App\Modules\LegacyRme\Support\LegacyRmePdfMetadata;
use App\Modules\LegacyRme\Support\LegacyRmeRasterizationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-1B — turns a stored source PDF into private page images.
 *
 * Only ever invoked from the queued job: no HTTP request rasterizes a PDF.
 *
 * IDEMPOTENCY. The status claim is a single locked transaction, so two workers
 * can never both start the same import. Everything after it is repeatable:
 * stale rendered output and stale page rows are removed before rendering, and
 * page rows are upserted on UNIQUE(legacy_import_id, page_number), so a retry
 * converges on exactly one row per page instead of accumulating duplicates.
 *
 * FAILURE. Any failure lands the import in FAILED with a STABLE code and a safe
 * message; the queue is not asked to retry, because the operator's explicit
 * Retry (FAILED → QUEUED, a legal 1A transition) is the recovery path and it
 * keeps a human in the loop. Queue-level retries still cover the case where the
 * worker dies before it could record anything.
 *
 * The temporary render directory is always removed, including on failure.
 */
class LegacyRmeImportProcessingService
{
    public function __construct(
        private readonly LegacyRmeImportRepositoryInterface $imports,
        private readonly LegacyRmePdfInspectorInterface $inspector,
        private readonly LegacyRmePdfRasterizerInterface $rasterizer,
        private readonly LegacyRmeStorageService $storage,
        private readonly LegacyRmeAuditService $audit,
    ) {}

    public function process(int $importId): void
    {
        $import = $this->claim($importId);

        if ($import === null) {
            // Already processed, cancelled, published, or claimed by another
            // worker — a no-op, never an error.
            return;
        }

        $temporaryDirectory = null;

        try {
            $source = $this->storage->absolutePathForProcessing((string) $import->source_pdf_path);

            if ($source === null) {
                throw LegacyRmePdfException::make(LegacyRmePdfFailure::SOURCE_FILE_MISSING);
            }

            $metadata = $this->inspector->inspect($source);

            $this->assertRenderable($metadata);

            // Remove anything a previous attempt left behind BEFORE rendering,
            // so a retry can never mix stale pages with fresh ones.
            $this->storage->deleteRenderedOutput((int) $import->patient_id, (string) $import->uuid);
            $this->imports->deletePages($import);

            $temporaryDirectory = $this->makeTemporaryDirectory($import);

            $result = $this->rasterizer->rasterize($source, $temporaryDirectory, $this->dpi());

            if ($result->pageCount() !== $metadata->pageCount) {
                throw LegacyRmePdfException::make(
                    LegacyRmePdfFailure::PAGE_OUTPUT_COUNT_MISMATCH,
                    sprintf(
                        'Dokumen memiliki %d halaman tetapi %d halaman berhasil dirender.',
                        $metadata->pageCount,
                        $result->pageCount(),
                    ),
                );
            }

            $this->persistPages($import, $result);

            $this->complete($import, $result);
        } catch (LegacyRmePdfException $exception) {
            $this->fail($import, $exception->failureCode, $exception->getMessage());
        } catch (\Throwable $exception) {
            Log::error('legacy_rme.processing_failed', [
                'import_id' => (int) $import->getKey(),
                'exception' => $exception::class,
                'message' => mb_substr($exception->getMessage(), 0, 500),
            ]);

            $this->fail($import, LegacyRmePdfFailure::PDF_RENDER_FAILED, LegacyRmePdfFailure::message(LegacyRmePdfFailure::PDF_RENDER_FAILED));
        } finally {
            if ($temporaryDirectory !== null) {
                $this->removeDirectory($temporaryDirectory);
            }
        }
    }

    /**
     * Operator-initiated reprocessing. FAILED → QUEUED is legal; a PROCESSING
     * import whose worker died is first moved to FAILED so the same legal path
     * applies rather than inventing a transition.
     *
     * @throws ValidationException
     */
    public function retry(LegacyRmeImport $import, LegacyRmeImportService $importService, ?User $actor = null): LegacyRmeImport
    {
        if ($import->status === LegacyRmeImportStatus::PROCESSING && $this->isStaleProcessing($import)) {
            $this->fail($import, LegacyRmePdfFailure::PDF_PROCESS_TIMEOUT, LegacyRmePdfFailure::message(LegacyRmePdfFailure::PDF_PROCESS_TIMEOUT));
            $import->refresh();
        }

        if (! $import->canTransitionTo(LegacyRmeImportStatus::QUEUED)) {
            throw ValidationException::withMessages([
                'status' => LegacyRmePdfFailure::message(LegacyRmePdfFailure::IMPORT_NOT_RETRYABLE),
            ]);
        }

        // A retry without a source PDF would only fail again — refuse it with a
        // clear reason instead of burning a processing cycle.
        if (! $this->storage->exists((string) $import->source_pdf_path)) {
            throw ValidationException::withMessages([
                'status' => LegacyRmePdfFailure::message(LegacyRmePdfFailure::SOURCE_FILE_MISSING),
            ]);
        }

        return $importService->queue($import, $actor, isRetry: true);
    }

    /**
     * @throws ValidationException
     */
    public function cancel(LegacyRmeImport $import, ?User $actor = null): LegacyRmeImport
    {
        if (! $import->canTransitionTo(LegacyRmeImportStatus::CANCELLED)) {
            throw ValidationException::withMessages([
                'status' => LegacyRmePdfFailure::message(LegacyRmePdfFailure::IMPORT_NOT_CANCELLABLE),
            ]);
        }

        $import = DB::transaction(function () use ($import, $actor): LegacyRmeImport {
            $locked = $this->imports->lockForUpdate((int) $import->getKey()) ?? $import;

            if (! $locked->canTransitionTo(LegacyRmeImportStatus::CANCELLED)) {
                throw ValidationException::withMessages([
                    'status' => LegacyRmePdfFailure::message(LegacyRmePdfFailure::IMPORT_NOT_CANCELLABLE),
                ]);
            }

            return $this->imports->update($locked, [
                'status' => LegacyRmeImportStatus::CANCELLED,
                'cancelled_by' => $actor?->getKey(),
                'cancelled_at' => now(),
            ]);
        });

        $this->audit->logImportEvent(LegacyRmeAuditEvent::IMPORT_CANCELLED, $import, [], $actor);

        return $import;
    }

    /**
     * Mark an import failed once the queue has exhausted its retries — the
     * worker died before it could record anything itself.
     */
    public function markFailedAfterExhaustedRetries(int $importId): void
    {
        $import = $this->imports->findForProcessing($importId);

        if ($import === null || $import->isTerminal() || $import->status === LegacyRmeImportStatus::FAILED) {
            return;
        }

        $this->fail($import, LegacyRmePdfFailure::PDF_RENDER_FAILED, LegacyRmePdfFailure::message(LegacyRmePdfFailure::PDF_RENDER_FAILED));
    }

    /**
     * Atomically take ownership of an import. Returns null when there is
     * nothing to do, which makes a duplicate job delivery a harmless no-op.
     */
    private function claim(int $importId): ?LegacyRmeImport
    {
        return DB::transaction(function () use ($importId): ?LegacyRmeImport {
            $import = $this->imports->lockForUpdate($importId);

            if ($import === null) {
                return null;
            }

            $processable = $import->status === LegacyRmeImportStatus::QUEUED
                || ($import->status === LegacyRmeImportStatus::PROCESSING && $this->isStaleProcessing($import));

            if (! $processable) {
                return null;
            }

            if ($import->status === LegacyRmeImportStatus::QUEUED) {
                $import = $this->imports->update($import, [
                    'status' => LegacyRmeImportStatus::PROCESSING,
                    'processing_started_at' => now(),
                    'processing_completed_at' => null,
                    'failure_code' => null,
                    'failure_message' => null,
                ]);
            } else {
                $import = $this->imports->update($import, [
                    'processing_started_at' => now(),
                ]);
            }

            return $import;
        });
    }

    /**
     * A PROCESSING import whose worker never reported back. The window is
     * generous (twice the process timeout plus a margin) so a slow-but-alive
     * render is never stolen.
     */
    private function isStaleProcessing(LegacyRmeImport $import): bool
    {
        if ($import->processing_started_at === null) {
            return true;
        }

        $timeout = (int) config('legacy_rme.processing.process_timeout', 180);

        return $import->processing_started_at->lt(now()->subSeconds(max($timeout, 60) * 2 + 60));
    }

    private function assertRenderable(LegacyRmePdfMetadata $metadata): void
    {
        if ($metadata->encrypted) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_ENCRYPTED);
        }

        $maxPages = (int) config('legacy_rme.upload.max_pages', 200);

        if ($metadata->pageCount < 1) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_PAGE_COUNT_INVALID);
        }

        if ($maxPages > 0 && $metadata->pageCount > $maxPages) {
            throw LegacyRmePdfException::make(
                LegacyRmePdfFailure::PDF_PAGE_LIMIT_EXCEEDED,
                sprintf('Dokumen memiliki %d halaman, melebihi batas %d halaman.', $metadata->pageCount, $maxPages),
            );
        }

        $maxDimension = (int) config('legacy_rme.processing.max_page_dimension_pt', 20000);
        $largest = $metadata->largestDimension();

        if ($maxDimension > 0 && $largest !== null && $largest > $maxDimension) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_DIMENSION_LIMIT_EXCEEDED);
        }

        $maxPixels = (int) config('legacy_rme.processing.max_page_pixels', 40000000);
        $pixels = $metadata->largestPagePixelsAt($this->dpi());

        if ($maxPixels > 0 && $pixels !== null && $pixels > $maxPixels) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_DIMENSION_LIMIT_EXCEEDED);
        }
    }

    private function persistPages(LegacyRmeImport $import, LegacyRmeRasterizationResult $result): void
    {
        $patientId = (int) $import->patient_id;
        $uuid = (string) $import->uuid;
        $disk = $this->storage->diskName();
        $maxRenderBytes = (int) config('legacy_rme.processing.max_render_bytes', 209715200);
        $totalBytes = 0;

        foreach ($result->pages as $page) {
            $bytes = @filesize($page->imagePath);

            if ($bytes === false) {
                throw LegacyRmePdfException::make(LegacyRmePdfFailure::PAGE_IMAGE_INVALID);
            }

            $totalBytes += (int) $bytes;

            if ($maxRenderBytes > 0 && $totalBytes > $maxRenderBytes) {
                throw LegacyRmePdfException::make(LegacyRmePdfFailure::RENDER_SIZE_LIMIT_EXCEEDED);
            }

            $hash = hash_file('sha256', $page->imagePath);

            if (! is_string($hash) || $hash === '') {
                throw LegacyRmePdfException::make(LegacyRmePdfFailure::PAGE_IMAGE_INVALID);
            }

            $imagePath = $this->storage->pagePath($patientId, $uuid, $page->pageNumber);
            $this->storage->putFile($imagePath, $page->imagePath);

            $thumbnailPath = null;

            if ($page->thumbnailPath !== null && is_file($page->thumbnailPath)) {
                $thumbnailPath = $this->storage->thumbnailPath($patientId, $uuid, $page->pageNumber);
                $this->storage->putFile($thumbnailPath, $page->thumbnailPath);
            }

            $this->imports->upsertPage($import, $page->pageNumber, [
                'width' => $page->width,
                'height' => $page->height,
                'dpi' => $page->dpi,
                'rotation' => 0,
                'background_disk' => $disk,
                'background_path' => $imagePath,
                'background_sha256' => $hash,
                'thumbnail_path' => $thumbnailPath,
                'status' => LegacyRmeImportPageStatus::READY,
            ]);
        }

        // Fail-closed: publishing requires READY pages, so a page row that did
        // not make it through must never leave the import looking complete.
        $ready = $this->imports->pagesFor($import)
            ->where('status', LegacyRmeImportPageStatus::READY)
            ->count();

        if ($ready !== $result->pageCount()) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PAGE_OUTPUT_COUNT_MISMATCH);
        }
    }

    private function complete(LegacyRmeImport $import, LegacyRmeRasterizationResult $result): void
    {
        $import = DB::transaction(function () use ($import, $result): LegacyRmeImport {
            $locked = $this->imports->lockForUpdate((int) $import->getKey()) ?? $import;

            return $this->imports->update($locked, [
                'status' => LegacyRmeImportStatus::READY_FOR_REVIEW,
                'page_count' => $result->pageCount(),
                'dpi' => $result->dpi,
                'processing_completed_at' => now(),
                'failure_code' => null,
                'failure_message' => null,
            ]);
        });

        $this->audit->logImportEvent(LegacyRmeAuditEvent::PROCESSING_COMPLETED, $import, [
            'page_count' => $result->pageCount(),
            'dpi' => $result->dpi,
        ]);
    }

    private function fail(LegacyRmeImport $import, string $failureCode, string $message): void
    {
        try {
            $import = DB::transaction(function () use ($import, $failureCode, $message): LegacyRmeImport {
                $locked = $this->imports->lockForUpdate((int) $import->getKey()) ?? $import;

                return $this->imports->update($locked, [
                    'status' => LegacyRmeImportStatus::FAILED,
                    // Bounded and already safe by construction: every message
                    // comes from LegacyRmePdfFailure, never from a driver
                    // exception, a process command line or a filesystem path.
                    'failure_code' => mb_substr($failureCode, 0, 64),
                    'failure_message' => mb_substr($message, 0, 1000),
                    'processing_completed_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            Log::error('legacy_rme.failure_not_recorded', [
                'import_id' => (int) $import->getKey(),
                'exception' => $exception::class,
            ]);

            return;
        }

        $this->audit->logImportEvent(LegacyRmeAuditEvent::PROCESSING_FAILED, $import, [
            'failure_code' => $failureCode,
        ]);
    }

    /**
     * A private, per-run temporary directory inside the legacy disk, so render
     * intermediates never land in a world-readable system temp location.
     */
    private function makeTemporaryDirectory(LegacyRmeImport $import): string
    {
        $relative = $this->storage->importDirectory((int) $import->patient_id, (string) $import->uuid)
            .'/processing/'.Str::uuid()->toString();

        $this->storage->disk()->makeDirectory($relative);

        $absolute = $this->storage->disk()->path($relative);

        if (! is_dir($absolute)) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_STORAGE_FAILED);
        }

        @chmod($absolute, 0700);

        return $absolute;
    }

    private function removeDirectory(string $absolutePath): void
    {
        if (! is_dir($absolutePath)) {
            return;
        }

        foreach ((array) glob($absolutePath.DIRECTORY_SEPARATOR.'*') as $entry) {
            if (is_string($entry) && is_file($entry)) {
                @unlink($entry);
            }
        }

        @rmdir($absolutePath);
    }

    private function dpi(): int
    {
        $dpi = (int) config('legacy_rme.processing.dpi', 180);

        return $dpi > 0 ? $dpi : 180;
    }
}
