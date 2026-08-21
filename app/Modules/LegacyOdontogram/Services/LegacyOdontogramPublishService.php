<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Services;

use App\Models\User;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramImportRepositoryInterface;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramRecordRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImportPage;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramAuditEvent;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportPageStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramRecordStatus;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FIX-04b — review, then publish a staged chart into IMMUTABLE evidence.
 *
 * FOUR PROPERTIES MAKE THIS SAFE, and each is enforced here rather than assumed:
 *
 *  1. A HUMAN REVIEWED IT. PUBLISHED is reachable only from REVIEWED (the
 *     status map says so), so nothing is promoted straight off the rasterizer.
 *
 *  2. IT IS RE-VALIDATED. The date rules and the branch derivation are
 *     re-evaluated under the row lock, not trusted from upload time. A staged
 *     chart can sit for days while the patient gains a native odontogram that
 *     invalidates it, and publishing must notice.
 *
 *  3. IT IS ATOMIC AND IDEMPOTENT. Everything happens in one transaction, and
 *     an import that already produced a record returns that SAME record instead
 *     of creating a second one — enforced structurally by
 *     UNIQUE(source_import_id), so even a lost race ends with one record.
 *
 *  4. IT CREATES NOTHING ELSE. Only a record and its pages are written. No
 *     ClinicVisit, no native Odontogram, no MedicalRecord, no invoice, no
 *     payment, no treatment, no prescription, no LabOrder, no SATUSEHAT
 *     candidate — and no legacy RME row of any kind.
 *
 * PUBLISHING PROMOTES METADATA, NOT BYTES. The record points at the SAME files
 * the staging row already validated. Copying them would create a second copy of
 * a clinical document to keep in sync and to forget to delete.
 */
class LegacyOdontogramPublishService
{
    public function __construct(
        private readonly LegacyOdontogramImportRepositoryInterface $imports,
        private readonly LegacyOdontogramRecordRepositoryInterface $records,
        private readonly LegacyOdontogramDateRuleService $dateRules,
        private readonly LegacyOdontogramBranchBindingService $branchBinding,
        private readonly LegacyOdontogramStorageService $storage,
        private readonly LegacyOdontogramAuditService $audit,
        private readonly LegacyOdontogramFeatureGuard $feature,
    ) {}

    /**
     * Mark a rendered import as reviewed by a human.
     *
     * @throws ValidationException
     */
    public function review(LegacyOdontogramImport $import, ?User $actor = null): LegacyOdontogramImport
    {
        $this->feature->assertMigrationEnabled();

        $reviewed = DB::transaction(function () use ($import, $actor): LegacyOdontogramImport {
            $locked = $this->imports->lockForUpdate((int) $import->getKey());

            if ($locked === null) {
                $this->refuse(LegacyRmePdfFailure::IMPORT_NOT_REVIEWABLE);
            }

            // Idempotent: reviewing twice is a no-op, not an error.
            if ($locked->status === LegacyOdontogramImportStatus::REVIEWED) {
                return $locked;
            }

            if (! $locked->canTransitionTo(LegacyOdontogramImportStatus::REVIEWED)) {
                $this->refuse(LegacyRmePdfFailure::IMPORT_NOT_REVIEWABLE);
            }

            $this->assertRenderedPagesUsable($locked, $this->imports->pagesFor($locked));

            return $this->imports->update($locked, [
                'status' => LegacyOdontogramImportStatus::REVIEWED,
                'reviewed_by' => $actor?->getKey(),
                'reviewed_at' => now(),
            ]);
        });

        $this->audit->logImportEvent(LegacyOdontogramAuditEvent::IMPORT_REVIEWED, $reviewed, [], $actor);

        return $reviewed;
    }

    /**
     * @param  array{title?: string|null, description?: string|null}  $attributes
     *
     * @throws ValidationException
     */
    public function publish(LegacyOdontogramImport $import, array $attributes = [], ?User $actor = null): LegacyOdontogramRecord
    {
        $this->feature->assertMigrationEnabled();

        try {
            $outcome = $this->publishWithinTransaction($import, $attributes, $actor);
        } catch (ValidationException $exception) {
            $this->audit->logImportEvent(
                LegacyOdontogramAuditEvent::PUBLISH_REJECTED,
                $import,
                ['rule_code' => 'PUBLISH_REFUSED'],
                $actor,
            );

            throw $exception;
        }

        if ($outcome['created']) {
            $this->audit->logRecordEvent(LegacyOdontogramAuditEvent::PUBLISHED, $outcome['record'], [
                'import_id' => (int) $import->getKey(),
            ], $actor);
        }

        return $outcome['record'];
    }

    /**
     * @param  array{title?: string|null, description?: string|null}  $attributes
     * @return array{record: LegacyOdontogramRecord, created: bool}
     */
    private function publishWithinTransaction(LegacyOdontogramImport $import, array $attributes, ?User $actor): array
    {
        return DB::transaction(function () use ($import, $attributes, $actor): array {
            $locked = $this->imports->lockForUpdate((int) $import->getKey());

            if ($locked === null) {
                $this->refuse(LegacyRmePdfFailure::IMPORT_NOT_PUBLISHABLE);
            }

            // IDEMPOTENCE FIRST, before any transition check: a retried publish
            // must return the existing record rather than complain that the
            // import is already terminal.
            $existing = $this->records->findBySourceImportId((int) $locked->getKey());

            if ($existing !== null) {
                return ['record' => $existing, 'created' => false];
            }

            if (! $locked->canTransitionTo(LegacyOdontogramImportStatus::PUBLISHED)) {
                $this->refuse(LegacyRmePdfFailure::IMPORT_NOT_PUBLISHABLE);
            }

            $patient = $locked->patient;

            if ($patient === null) {
                $this->refuse(LegacyRmePdfFailure::IMPORT_NOT_PUBLISHABLE);
            }

            // Re-validated under the lock, never trusted from upload time.
            $dateResult = $this->dateRules->evaluate($patient, $locked->selected_odontogram_date);

            if ($dateResult->failed()) {
                throw ValidationException::withMessages([
                    LegacyOdontogramDateRuleService::FIELD => (string) $dateResult->message,
                ]);
            }

            $branch = $this->branchBinding->resolveForPatient($patient, null);

            if ($branch->failed()) {
                throw ValidationException::withMessages([
                    'patient_id' => (string) $branch->message,
                ]);
            }

            // The branch a chart was staged under must still be the branch the
            // patient's RM names. A patient moved between branches after
            // staging is a real correction, not something to publish through.
            if ($locked->origin_branch_id !== null && (int) $locked->origin_branch_id !== (int) $branch->branchId) {
                throw ValidationException::withMessages([
                    'patient_id' => 'Cabang arsip tidak lagi sesuai dengan Nomor RM pasien. Batalkan impor ini dan unggah ulang.',
                ]);
            }

            $pages = $this->imports->pagesFor($locked);
            $this->assertRenderedPagesUsable($locked, $pages);
            $this->assertSourceStillPresent($locked);

            $record = $this->records->create([
                'patient_id' => $locked->patient_id,
                'branch_id' => $locked->origin_branch_id,
                'source_branch_code' => $locked->source_branch_code,
                'source_medical_record_number' => $locked->source_medical_record_number,
                'odontogram_date' => $locked->selected_odontogram_date,
                'title' => $this->normalizeText($attributes['title'] ?? null, 150),
                'description' => $this->normalizeText($attributes['description'] ?? null, 2000),
                'source_disk' => $this->resolveDisk($locked->source_disk),
                'source_pdf_path' => (string) $locked->source_pdf_path,
                'source_pdf_sha256' => (string) $locked->source_pdf_sha256,
                'page_count' => $pages->count(),
                'status' => LegacyOdontogramRecordStatus::PUBLISHED,
                'source_import_id' => $locked->getKey(),
                'imported_by' => $locked->uploaded_by,
                'published_by' => $actor?->getKey(),
                'published_at' => now(),
            ]);

            foreach ($pages as $page) {
                $this->records->createPage($record, [
                    'page_number' => (int) $page->page_number,
                    'width' => (int) $page->width,
                    'height' => (int) $page->height,
                    'dpi' => (int) $page->dpi,
                    'rotation' => (int) ($page->rotation ?? 0),
                    'image_disk' => $this->resolveDisk($page->image_disk),
                    'image_path' => (string) $page->image_path,
                    'image_sha256' => (string) $page->image_sha256,
                    'thumbnail_path' => $page->thumbnail_path,
                ]);

                $this->imports->upsertPage($locked, (int) $page->page_number, [
                    'status' => LegacyOdontogramImportPageStatus::PUBLISHED,
                ]);
            }

            $this->imports->update($locked, [
                'status' => LegacyOdontogramImportStatus::PUBLISHED,
                'page_count' => $pages->count(),
                'published_by' => $actor?->getKey(),
                'published_at' => now(),
            ]);

            return ['record' => $record, 'created' => true];
        });
    }

    /**
     * Every page must be renderable AND still present on disk. A record whose
     * pages 404 is worse than no record: it looks like evidence and is not.
     *
     * @param  Collection<int, LegacyOdontogramImportPage>  $pages
     */
    private function assertRenderedPagesUsable(LegacyOdontogramImport $import, Collection $pages): void
    {
        if ($pages->isEmpty()) {
            $this->refuse(LegacyRmePdfFailure::RENDERED_PAGES_MISSING);
        }

        foreach ($pages as $page) {
            if (! LegacyOdontogramImportPageStatus::isViewable($page->status)) {
                $this->refuse(LegacyRmePdfFailure::RENDERED_PAGES_MISSING);
            }

            $path = is_string($page->image_path) ? $page->image_path : '';

            if ($path === '' || ! $this->storage->diskFor($page->image_disk)->exists($path)) {
                $this->refuse(LegacyRmePdfFailure::PAGE_FILE_MISSING);
            }
        }

        $declared = (int) ($import->page_count ?? 0);

        if ($declared > 0 && $declared !== $pages->count()) {
            $this->refuse(LegacyRmePdfFailure::PAGE_COUNT_MISMATCH);
        }
    }

    private function assertSourceStillPresent(LegacyOdontogramImport $import): void
    {
        $path = is_string($import->source_pdf_path) ? $import->source_pdf_path : '';

        if ($path === '' || ! $this->storage->diskFor($import->source_disk)->exists($path)) {
            $this->refuse(LegacyRmePdfFailure::SOURCE_FILE_MISSING);
        }
    }

    private function resolveDisk(?string $disk): string
    {
        return is_string($disk) && $disk !== '' ? $disk : $this->storage->diskName();
    }

    private function normalizeText(?string $value, int $maxLength): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $failureCode): never
    {
        throw ValidationException::withMessages([
            'status' => LegacyRmePdfFailure::message($failureCode),
        ]);
    }
}
