<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Models\User;
use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmeRecordRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeImportPage;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeBranchResolution;
use App\Modules\LegacyRme\Support\LegacyRmeImportPageStatus;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use App\Modules\LegacyRme\Support\LegacyRmePublishRefusal;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-1C — turning a reviewed staging import into an immutable
 * legacy RME record.
 *
 * WHAT PUBLISHING IS NOT. A legacy record is the patient's OLD medical-record
 * document, not a live encounter: publishing creates no clinic visit, no
 * medical record, no invoice, no payment, no consent, no odontogram, no lab
 * candidate/order and no SATUSEHAT candidate, and it never touches a visit
 * status. This service writes to exactly two tables — trx_rme_legacy_records
 * and trx_rme_legacy_record_pages — plus the staging row it consumed.
 *
 * STATE. The 1A transition map is the contract and is not widened here:
 * READY_FOR_REVIEW → REVIEWED (review) and REVIEWED → PUBLISHED (publish).
 * Reviewing is therefore a real gate, not a formality — an unreviewed import
 * can never be published, and PUBLISHED is terminal (no republish, ever).
 *
 * ATOMICITY / IDEMPOTENCY. Publishing runs inside ONE transaction that opens
 * with a row lock on the staging import and re-reads its status under that
 * lock, so a double click, a retried request or two concurrent operators
 * converge on a single record. UNIQUE(source_import_id) on
 * trx_rme_legacy_records is the independent database-level defence behind it:
 * even if every application check were bypassed, a second record for the same
 * import cannot exist.
 *
 * REVALIDATION. The date rule is re-evaluated against a freshly resolved
 * cutoff at publish time. The patient's native history can change between
 * upload and publish, and the upload-time snapshot must never be the authority
 * for a permanent record.
 *
 * FILES. Publishing promotes metadata; it moves no bytes. 1B already stores the
 * source PDF and every rendered page under an opaque, private, per-import
 * directory that is never rewritten, and the staging row is soft-deleted rather
 * than erased — so the published record simply points at the same private
 * paths. Nothing to copy means nothing to compensate for when a transaction
 * rolls back, and no orphan file can be produced by a failed publish.
 */
class LegacyRmePublishService
{
    public function __construct(
        private readonly LegacyRmeImportRepositoryInterface $imports,
        private readonly LegacyRmeRecordRepositoryInterface $records,
        private readonly LegacyRmeDateRuleService $dateRules,
        private readonly LegacyRmeBranchResolver $branchResolver,
        private readonly LegacyRmeStorageService $storage,
        private readonly LegacyRmeAuditService $audit,
    ) {}

    /**
     * Mark a rendered import as reviewed by a human operator.
     *
     * @throws ValidationException
     */
    public function review(LegacyRmeImport $import, ?User $actor = null): LegacyRmeImport
    {
        try {
            $reviewed = $this->reviewWithinTransaction($import, $actor);
        } catch (LegacyRmePublishRefusal $refusal) {
            // The refusal type is internal to this service — it must never
            // escape to the HTTP boundary, where it would surface as a 500
            // instead of a field error.
            throw $refusal->toValidationException();
        }

        $this->audit->logImportEvent(LegacyRmeAuditEvent::IMPORT_REVIEWED, $reviewed, [], $actor);

        return $reviewed;
    }

    /**
     * @throws LegacyRmePublishRefusal
     */
    private function reviewWithinTransaction(LegacyRmeImport $import, ?User $actor): LegacyRmeImport
    {
        return DB::transaction(function () use ($import, $actor): LegacyRmeImport {
            $locked = $this->imports->lockForUpdate((int) $import->getKey());

            if ($locked === null) {
                $this->refuse(LegacyRmePdfFailure::IMPORT_NOT_REVIEWABLE);
            }

            // Re-reviewing an already reviewed import is a harmless no-op: the
            // operator simply pressed the button twice.
            if ($locked->status === LegacyRmeImportStatus::REVIEWED) {
                return $locked;
            }

            if (! $locked->canTransitionTo(LegacyRmeImportStatus::REVIEWED)) {
                $this->refuse(LegacyRmePdfFailure::IMPORT_NOT_REVIEWABLE);
            }

            // Reviewing asserts the rendered result is complete, so the same
            // page checks that guard publishing apply here too — an operator
            // must never be able to "review" a half-rendered document.
            $this->assertRenderedPagesUsable($locked, $this->imports->pagesFor($locked));

            return $this->imports->update($locked, [
                'status' => LegacyRmeImportStatus::REVIEWED,
                'reviewed_by' => $actor?->getKey(),
                'reviewed_at' => now(),
            ]);
        });
    }

    /**
     * Publish a reviewed import as an immutable legacy RME record.
     *
     * @param  array{title?: string|null, description?: string|null}  $attributes
     *
     * @throws ValidationException
     */
    public function publish(LegacyRmeImport $import, array $attributes = [], ?User $actor = null): LegacyRmeRecord
    {
        try {
            /** @var array{record: LegacyRmeRecord, created: bool} $outcome */
            $outcome = $this->publishWithinTransaction($import, $attributes, $actor);
        } catch (LegacyRmePublishRefusal $refusal) {
            // Recorded only AFTER the transaction has unwound — an audit row
            // written inside it would have been rolled back with the refusal.
            $this->audit->logImportEvent(
                LegacyRmeAuditEvent::PUBLISH_REJECTED,
                $import,
                $refusal->auditMetadata,
                $actor,
            );

            throw $refusal->toValidationException();
        }

        if ($outcome['created']) {
            $this->audit->logRecordEvent(LegacyRmeAuditEvent::PUBLISHED, $outcome['record'], [
                'import_id' => (int) $import->getKey(),
            ], $actor);
        }

        return $outcome['record'];
    }

    /**
     * @param  array{title?: string|null, description?: string|null}  $attributes
     * @return array{record: LegacyRmeRecord, created: bool}
     *
     * @throws LegacyRmePublishRefusal
     */
    private function publishWithinTransaction(LegacyRmeImport $import, array $attributes, ?User $actor): array
    {
        return DB::transaction(function () use ($import, $attributes, $actor): array {
            $locked = $this->imports->lockForUpdate((int) $import->getKey());

            if ($locked === null) {
                $this->refuse(LegacyRmePdfFailure::IMPORT_NOT_PUBLISHABLE);
            }

            // Idempotency, first line: this import already produced its record.
            // Return it instead of failing — a double submit must not look like
            // an error to the operator, and it must not create a second row.
            $existing = $this->records->findBySourceImportId((int) $locked->getKey());

            if ($existing !== null) {
                return ['record' => $existing, 'created' => false];
            }

            if (! $locked->canTransitionTo(LegacyRmeImportStatus::PUBLISHED)) {
                $this->refuse(LegacyRmePdfFailure::IMPORT_NOT_PUBLISHABLE);
            }

            $patient = $locked->patient;

            if ($patient === null) {
                $this->refuse(LegacyRmePdfFailure::IMPORT_NOT_PUBLISHABLE);
            }

            // Re-run the 1A date rules against a freshly resolved cutoff. The
            // upload-time snapshot is evidence, never the authority.
            $this->assertDateStillValid($locked);
            $this->assertBranchStillValid($locked);

            $pages = $this->imports->pagesFor($locked);

            $this->assertRenderedPagesUsable($locked, $pages);
            $this->assertSourceStillPresent($locked);

            $record = $this->records->create([
                'patient_id' => $locked->patient_id,
                'origin_branch_id' => $locked->origin_branch_id,
                'rme_date' => $locked->selected_rme_date,
                'latest_rme_date' => $locked->latest_rme_date ?? $locked->selected_rme_date,
                'title' => $this->normalizeTitle($attributes['title'] ?? null),
                'description' => $this->normalizeDescription($attributes['description'] ?? null),
                'source_disk' => $this->resolveDisk($locked->source_disk),
                'source_pdf_path' => (string) $locked->source_pdf_path,
                'source_pdf_sha256' => (string) $locked->source_pdf_sha256,
                'normalized_content_hash' => $locked->normalized_content_hash,
                'page_count' => $pages->count(),
                'status' => LegacyRmeRecordStatus::PUBLISHED,
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
                    'background_disk' => $this->resolveDisk($page->background_disk),
                    'background_path' => (string) $page->background_path,
                    'background_sha256' => (string) $page->background_sha256,
                    'thumbnail_path' => $page->thumbnail_path,
                    'normalized_page_hash' => $page->normalized_page_hash,
                ]);

                // The staging page is now archived material rather than a
                // pending render target.
                $this->imports->upsertPage($locked, (int) $page->page_number, [
                    'status' => LegacyRmeImportPageStatus::PUBLISHED,
                ]);
            }

            $this->imports->update($locked, [
                'status' => LegacyRmeImportStatus::PUBLISHED,
                'page_count' => $pages->count(),
                'published_by' => $actor?->getKey(),
                'published_at' => now(),
            ]);

            return ['record' => $record, 'created' => true];
        });
    }

    /**
     * @throws LegacyRmePublishRefusal
     */
    private function assertDateStillValid(LegacyRmeImport $import): void
    {
        $patient = $import->patient;

        if ($patient === null) {
            $this->refuse(LegacyRmePdfFailure::IMPORT_NOT_PUBLISHABLE);
        }

        // FIX-ROLL2-1: revalidate the WHOLE declared range, not just the
        // representative date. This is the race that matters — an import may
        // have been staged for a patient with no native RME at all (a valid
        // migration case), and a real encounter may have been recorded for that
        // patient since. The cutoff is resolved fresh inside evaluate(), so a
        // document that now overlaps the native era is refused here rather than
        // becoming a permanent record.
        $result = $this->dateRules->evaluate(
            $patient,
            $import->selected_rme_date?->toDateString(),
            $import->latest_rme_date?->toDateString(),
        );

        if ($result->failed()) {
            throw LegacyRmePublishRefusal::dateRule(
                (string) $result->code,
                $result->message ?? 'Tanggal arsip tidak lagi valid.',
                LegacyRmeDateRuleService::FIELD,
            );
        }
    }

    /**
     * FIX-ROLL2-1 — the branch a legacy archive belongs to is derived from the
     * patient's Nomor RM, so it is re-derived here and compared with what was
     * staged.
     *
     * `origin_branch_id` decides who can read the published record. If the
     * patient's RM changed after staging, the staged branch is stale and
     * publishing it would freeze the wrong owner into an immutable row. The
     * operator re-imports instead.
     *
     * The actor is deliberately NOT passed to the resolver: this check is about
     * the document still belonging where it was filed, not about who is
     * pressing publish (that is the policy's job).
     *
     * @throws LegacyRmePublishRefusal
     */
    private function assertBranchStillValid(LegacyRmeImport $import): void
    {
        $patient = $import->patient;

        if ($patient === null) {
            $this->refuse(LegacyRmePdfFailure::IMPORT_NOT_PUBLISHABLE);
        }

        $staged = $import->origin_branch_id !== null ? (int) $import->origin_branch_id : null;

        // A pre-FIX-ROLL2-1 row may carry no branch at all. It is not made
        // worse by publishing, and inventing one now would be a guess.
        if ($staged === null) {
            return;
        }

        $resolution = $this->branchResolver->resolveForPatient($patient);

        if ($resolution->failed() || $resolution->branchId !== $staged) {
            throw LegacyRmePublishRefusal::dateRule(
                LegacyRmeBranchResolution::CODE_BRANCH_CONFLICT,
                'Cabang asal arsip tidak lagi sesuai dengan Nomor RM pasien. Batalkan impor ini dan ulangi dengan data pasien yang benar.',
                'origin_branch_id',
            );
        }
    }

    /**
     * Every page must be rendered, READY, complete and still on disk. A missing
     * or half-rendered page can never become part of the permanent archive.
     *
     * @param  Collection<int, LegacyRmeImportPage>  $pages
     *
     * @throws ValidationException
     */
    private function assertRenderedPagesUsable(LegacyRmeImport $import, Collection $pages): void
    {
        if ($pages->isEmpty()) {
            $this->refuse(LegacyRmePdfFailure::RENDERED_PAGES_MISSING);
        }

        // `page_count` is what the PDF inspector reported for the source
        // document; publishing fewer pages than the document has would silently
        // truncate a clinical record.
        $declared = $import->page_count !== null ? (int) $import->page_count : null;

        if ($declared !== null && $declared > 0 && $declared !== $pages->count()) {
            $this->refuse(LegacyRmePdfFailure::PAGE_COUNT_MISMATCH);
        }

        foreach ($pages as $page) {
            if (! LegacyRmeImportPageStatus::isPublishable((string) $page->status)) {
                $this->refuse(LegacyRmePdfFailure::RENDERED_PAGES_MISSING);
            }

            if (! is_numeric($page->width) || ! is_numeric($page->height) || ! is_numeric($page->dpi)
                || ! is_string($page->background_sha256) || $page->background_sha256 === '') {
                $this->refuse(LegacyRmePdfFailure::RENDERED_PAGES_MISSING);
            }

            $path = is_string($page->background_path) ? $page->background_path : '';

            if ($path === '' || ! $this->storage->existsOn($page->background_disk, $path)) {
                $this->refuse(LegacyRmePdfFailure::PAGE_FILE_MISSING);
            }
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertSourceStillPresent(LegacyRmeImport $import): void
    {
        $path = is_string($import->source_pdf_path) ? $import->source_pdf_path : '';
        $checksum = is_string($import->source_pdf_sha256) ? $import->source_pdf_sha256 : '';

        if ($path === '' || $checksum === '' || ! $this->storage->existsOn($import->source_disk, $path)) {
            $this->refuse(LegacyRmePdfFailure::SOURCE_FILE_MISSING);
        }
    }

    /**
     * A published record must always name a real private disk; falling back to
     * the configured one keeps a legacy staging row (whose disk column is
     * nullable) from producing an unreadable archive.
     */
    private function resolveDisk(?string $disk): string
    {
        return is_string($disk) && $disk !== '' ? $disk : $this->storage->diskName();
    }

    private function normalizeTitle(?string $title): string
    {
        $title = is_string($title) ? trim($title) : '';

        return $title !== '' ? mb_substr($title, 0, 150) : 'Arsip RME Lama';
    }

    private function normalizeDescription(?string $description): ?string
    {
        $description = is_string($description) ? trim($description) : '';

        return $description !== '' ? $description : null;
    }

    /**
     * Refuse with a stable code and a safe message. The message never carries a
     * path, a filename or any patient content; the caller records the rejection
     * once the transaction has unwound.
     *
     * @phpstan-return never
     *
     * @throws LegacyRmePublishRefusal
     */
    private function refuse(string $code): void
    {
        throw LegacyRmePublishRefusal::failure($code);
    }
}
