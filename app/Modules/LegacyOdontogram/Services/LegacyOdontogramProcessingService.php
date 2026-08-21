<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Services;

use App\Models\User;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramImportRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramAuditEvent;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportPageStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Support\LegacyRmePdfException;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use App\Modules\LegacyRme\Support\LegacyRmePdfMetadata;
use App\Modules\LegacyRme\Support\LegacyRmeRasterizationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FIX-04b — turn a staged legacy odontogram PDF into reviewable page images.
 *
 * REUSES THE POPPLER ABSTRACTION ON PURPOSE. LegacyRmePdfInspectorInterface and
 * LegacyRmePdfRasterizerInterface describe properties of a PDF and of a local
 * rendering process — page count, encryption, dimensions, output images. They
 * know nothing about RME, odontograms, patients or branches, so a second
 * parallel pair would be two implementations of the same Poppler contract
 * drifting apart, and two sets of fakes for tests to keep in step.
 *
 * Storage, page rows, statuses and audit are this module's own: only the
 * rendering contract is shared.
 *
 * CLAIM-THEN-WORK. The import is claimed under a row lock before any rendering
 * begins, so two workers can never render the same document into the same page
 * rows. A stale PROCESSING row (a worker that died) is reclaimable after a
 * generous multiple of the process timeout, rather than being stuck forever.
 *
 * A FAILURE IS A STATE, NOT AN EXCEPTION THAT ESCAPES. Every failure path lands
 * the import on FAILED with a stable code, so an operator sees what went wrong
 * and can retry, and the queue does not simply swallow the document.
 */
class LegacyOdontogramProcessingService
{
    public function __construct(
        private readonly LegacyOdontogramImportRepositoryInterface $imports,
        private readonly LegacyRmePdfInspectorInterface $inspector,
        private readonly LegacyRmePdfRasterizerInterface $rasterizer,
        private readonly LegacyOdontogramStorageService $storage,
        private readonly LegacyOdontogramAuditService $audit,
    ) {}

    public function process(int $importId): void
    {
        $import = $this->claim($importId);

        if ($import === null) {
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

            // A retry must never mix stale pages with fresh ones.
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
            // The message is bounded and the document itself is never logged.
            Log::error('legacy_odontogram.processing_failed', [
                'import_id' => (int) $import->getKey(),
                'exception' => $exception::class,
                'message' => mb_substr($exception->getMessage(), 0, 500),
            ]);

            $this->fail(
                $import,
                LegacyRmePdfFailure::PDF_RENDER_FAILED,
                LegacyRmePdfFailure::message(LegacyRmePdfFailure::PDF_RENDER_FAILED),
            );
        } finally {
            if ($temporaryDirectory !== null) {
                $this->removeDirectory($temporaryDirectory);
            }
        }
    }

    /**
     * @throws ValidationException
     */
    public function retry(LegacyOdontogramImport $import, LegacyOdontogramImportService $importService, ?User $actor = null): LegacyOdontogramImport
    {
        if ($import->status === LegacyOdontogramImportStatus::PROCESSING && $this->isStaleProcessing($import)) {
            $this->fail(
                $import,
                LegacyRmePdfFailure::PDF_PROCESS_TIMEOUT,
                LegacyRmePdfFailure::message(LegacyRmePdfFailure::PDF_PROCESS_TIMEOUT),
            );
            $import->refresh();
        }

        if (! $import->canTransitionTo(LegacyOdontogramImportStatus::QUEUED)) {
            throw ValidationException::withMessages([
                'status' => LegacyRmePdfFailure::message(LegacyRmePdfFailure::IMPORT_NOT_RETRYABLE),
            ]);
        }

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
    public function cancel(LegacyOdontogramImport $import, ?User $actor = null): LegacyOdontogramImport
    {
        $import = DB::transaction(function () use ($import, $actor): LegacyOdontogramImport {
            $locked = $this->imports->lockForUpdate((int) $import->getKey()) ?? $import;

            // Re-asserted under the lock: the status may have moved between the
            // policy check and here.
            if (! $locked->canTransitionTo(LegacyOdontogramImportStatus::CANCELLED)) {
                throw ValidationException::withMessages([
                    'status' => LegacyRmePdfFailure::message(LegacyRmePdfFailure::IMPORT_NOT_CANCELLABLE),
                ]);
            }

            return $this->imports->update($locked, [
                'status' => LegacyOdontogramImportStatus::CANCELLED,
                'cancelled_by' => $actor?->getKey(),
                'cancelled_at' => now(),
            ]);
        });

        $this->audit->logImportEvent(LegacyOdontogramAuditEvent::IMPORT_CANCELLED, $import, [], $actor);

        return $import;
    }

    public function markFailedAfterExhaustedRetries(int $importId): void
    {
        $import = $this->imports->findForProcessing($importId);

        if ($import === null || $import->isTerminal() || $import->status === LegacyOdontogramImportStatus::FAILED) {
            return;
        }

        $this->fail(
            $import,
            LegacyRmePdfFailure::PDF_RENDER_FAILED,
            LegacyRmePdfFailure::message(LegacyRmePdfFailure::PDF_RENDER_FAILED),
        );
    }

    private function claim(int $importId): ?LegacyOdontogramImport
    {
        return DB::transaction(function () use ($importId): ?LegacyOdontogramImport {
            $import = $this->imports->lockForUpdate($importId);

            if ($import === null) {
                return null;
            }

            $processable = $import->status === LegacyOdontogramImportStatus::QUEUED
                || ($import->status === LegacyOdontogramImportStatus::PROCESSING && $this->isStaleProcessing($import));

            if (! $processable) {
                return null;
            }

            if ($import->status === LegacyOdontogramImportStatus::QUEUED) {
                return $this->imports->update($import, [
                    'status' => LegacyOdontogramImportStatus::PROCESSING,
                    'processing_started_at' => now(),
                    'processing_completed_at' => null,
                    'failure_code' => null,
                    'failure_message' => null,
                ]);
            }

            return $this->imports->update($import, ['processing_started_at' => now()]);
        });
    }

    private function isStaleProcessing(LegacyOdontogramImport $import): bool
    {
        if ($import->processing_started_at === null) {
            return true;
        }

        $timeout = (int) config('legacy_odontogram.processing.process_timeout', 180);

        return $import->processing_started_at->lt(now()->subSeconds(max($timeout, 60) * 2 + 60));
    }

    /**
     * @throws LegacyRmePdfException
     */
    private function assertRenderable(LegacyRmePdfMetadata $metadata): void
    {
        if ($metadata->encrypted) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_ENCRYPTED);
        }

        if ($metadata->pageCount < 1) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_PAGE_COUNT_INVALID);
        }

        $maxPages = (int) config('legacy_odontogram.upload.max_pages', 50);

        if ($maxPages > 0 && $metadata->pageCount > $maxPages) {
            throw LegacyRmePdfException::make(
                LegacyRmePdfFailure::PDF_PAGE_LIMIT_EXCEEDED,
                sprintf('Dokumen memiliki %d halaman, melebihi batas %d halaman.', $metadata->pageCount, $maxPages),
            );
        }
    }

    /**
     * @throws LegacyRmePdfException
     */
    private function persistPages(LegacyOdontogramImport $import, LegacyRmeRasterizationResult $result): void
    {
        $patientId = (int) $import->patient_id;
        $uuid = (string) $import->uuid;
        $disk = $this->storage->diskName();

        $maxRenderBytes = (int) config('legacy_odontogram.processing.max_render_bytes', 209715200);
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
                'image_disk' => $disk,
                'image_path' => $imagePath,
                'image_sha256' => $hash,
                'thumbnail_path' => $thumbnailPath,
                'status' => LegacyOdontogramImportPageStatus::READY,
            ]);
        }

        $ready = $this->imports->pagesFor($import)
            ->where('status', LegacyOdontogramImportPageStatus::READY)
            ->count();

        if ($ready !== $result->pageCount()) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PAGE_OUTPUT_COUNT_MISMATCH);
        }
    }

    private function complete(LegacyOdontogramImport $import, LegacyRmeRasterizationResult $result): void
    {
        $import = DB::transaction(function () use ($import, $result): LegacyOdontogramImport {
            $locked = $this->imports->lockForUpdate((int) $import->getKey()) ?? $import;

            return $this->imports->update($locked, [
                'status' => LegacyOdontogramImportStatus::READY_FOR_REVIEW,
                'page_count' => $result->pageCount(),
                'dpi' => $result->dpi,
                'processing_completed_at' => now(),
                'failure_code' => null,
                'failure_message' => null,
            ]);
        });

        $this->audit->logImportEvent(LegacyOdontogramAuditEvent::PROCESSING_COMPLETED, $import, [
            'page_count' => $result->pageCount(),
            'dpi' => $result->dpi,
        ]);
    }

    private function fail(LegacyOdontogramImport $import, string $failureCode, string $message): void
    {
        try {
            $import = DB::transaction(function () use ($import, $failureCode, $message): LegacyOdontogramImport {
                $locked = $this->imports->lockForUpdate((int) $import->getKey()) ?? $import;

                return $this->imports->update($locked, [
                    'status' => LegacyOdontogramImportStatus::FAILED,
                    'failure_code' => mb_substr($failureCode, 0, 64),
                    'failure_message' => mb_substr($message, 0, 1000),
                    'processing_completed_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            Log::error('legacy_odontogram.failure_not_recorded', [
                'import_id' => (int) $import->getKey(),
                'exception' => $exception::class,
            ]);

            return;
        }

        $this->audit->logImportEvent(LegacyOdontogramAuditEvent::PROCESSING_FAILED, $import, [
            'failure_code' => $failureCode,
        ]);
    }

    /**
     * A per-run scratch directory INSIDE the private disk, so intermediate
     * renders of a clinical document never land in a world-readable temp dir.
     *
     * @throws LegacyRmePdfException
     */
    private function makeTemporaryDirectory(LegacyOdontogramImport $import): string
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
        $dpi = (int) config('legacy_odontogram.processing.dpi', 180);

        return $dpi > 0 ? $dpi : 180;
    }
}
