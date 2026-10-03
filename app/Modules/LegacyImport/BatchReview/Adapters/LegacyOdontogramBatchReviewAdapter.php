<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Adapters;

use App\Models\User;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewApplyOutcome;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewItemSummary;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewReason;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewRefusalClassifier;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramImportRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramFeatureGuard;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPublishService;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramWorkspaceScope;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Legacy odontogram side of the batch review seam — PR1 §3, §10, §18.
 *
 * Odontogram has NO lifecycle service. Its canonical write path is the sequence
 * its own controller performs, and this adapter reproduces that sequence
 * exactly — in the same order, with the same meanings:
 *
 *   1. feature capability (abort 404 when migration is off)
 *   2. branch-scoped resolution, ABSENCE on failure (never 403 — an actor must
 *      not be able to probe which archive ids exist in a branch they cannot see)
 *   3. the canonical policy ability `review`
 *   4. LegacyOdontogramPublishService::review()
 *
 * AND IT ADDS NO SEPARATION-OF-DUTIES CHECK, deliberately.
 *
 * That is not an oversight and it is not a weakening. The audit found zero
 * `separation` references anywhere in the odontogram module: no
 * SeparatePublisherGuard, no staffing support class, and a policy that checks
 * only permission, scope and transition. On this document type an uploader
 * reviewing their own chart is permitted behaviour today through the
 * single-item page. Introducing the RME rule here would mean the batch surface
 * silently enforced a stricter policy than the canonical page it mirrors, which
 * §2 forbids: batch mode must enforce the CURRENT policy truth, "exactly as in
 * single-item mode". If separation is wanted for odontogram it belongs in the
 * odontogram module, applied to both surfaces at once, in its own sprint.
 */
class LegacyOdontogramBatchReviewAdapter implements LegacyBatchReviewAdapter
{
    public function __construct(
        private readonly LegacyOdontogramImportRepositoryInterface $imports,
        private readonly LegacyOdontogramPublishService $publisher,
        private readonly LegacyOdontogramWorkspaceScope $scope,
        private readonly LegacyOdontogramFeatureGuard $feature,
        private readonly Gate $gate,
    ) {}

    public function importType(): string
    {
        return LegacyImportType::LEGACY_ODONTOGRAM;
    }

    public function migrationEnabled(): bool
    {
        return $this->feature->migrationEnabled();
    }

    public function importForeignKey(): string
    {
        return 'odontogram_legacy_import_id';
    }

    public function paginateReviewQueue(?User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $filters['status'] = LegacyOdontogramImportStatus::READY_FOR_REVIEW;

        return $this->imports->paginateInBranches(
            $this->scope->branchIdsFor($actor),
            $filters,
            $this->scope->includesUnscopedRowsFor($actor),
            $perPage,
        );
    }

    public function findInScope(?User $actor, int $importId): ?Model
    {
        return $this->imports->findByIdInBranches(
            $this->scope->branchIdsFor($actor),
            $importId,
            $this->scope->includesUnscopedRowsFor($actor),
        );
    }

    public function summarize(Model $import, bool $withPages = false): LegacyBatchReviewItemSummary
    {
        /** @var LegacyOdontogramImport $import */
        // Pages are loaded ONLY when the caller will render them. The
        // staging row already carries page_count, written by the
        // rasterizer, so a queue listing needs no page query at all.
        $pages = $withPages ? $this->imports->pagesFor($import) : null;

        return new LegacyBatchReviewItemSummary(
            importId: (int) $import->getKey(),
            importType: $this->importType(),
            patientId: $import->patient_id !== null ? (int) $import->patient_id : null,
            patientName: $import->patient?->name,
            medicalRecordNumber: $import->patient?->medical_record_number,
            branchId: $import->origin_branch_id !== null ? (int) $import->origin_branch_id : null,
            branchName: $import->originBranch?->name,
            status: (string) $import->status,
            // ONE date, not a range. The odontogram archive models a document as
            // a single representative clinical date; there is no latest-date
            // field to show and inventing one would be a field with no source of
            // truth.
            clinicalDate: $import->selected_odontogram_date?->toDateString(),
            sourceSha256: $import->source_pdf_sha256,
            pageCount: $pages !== null
                ? $pages->count()
                : (int) ($import->page_count ?? 0),
            pageNumbers: $pages !== null
                ? $pages->pluck('page_number')->map(fn ($n): int => (int) $n)->values()->all()
                : [],
            uploadedBy: $import->uploaded_by !== null ? (int) $import->uploaded_by : null,
            uploadedByName: $import->uploadedBy?->name,
        );
    }

    public function sourceChecksum(Model $import): ?string
    {
        /** @var LegacyOdontogramImport $import */
        return $import->source_pdf_sha256;
    }

    public function patientId(Model $import): ?int
    {
        /** @var LegacyOdontogramImport $import */
        return $import->patient_id !== null ? (int) $import->patient_id : null;
    }

    public function isReviewable(Model $import): bool
    {
        /** @var LegacyOdontogramImport $import */
        return $import->status === LegacyOdontogramImportStatus::READY_FOR_REVIEW;
    }

    public function canReview(?User $actor, Model $import): bool
    {
        if ($actor === null) {
            return false;
        }

        // The canonical policy ability, consulted read-only. It already checks
        // permission, branch scope and the transition — so this does not
        // re-express any of them.
        return $this->gate->forUser($actor)->allows('review', $import);
    }

    /**
     * Clearing sticky triage follows the SAME policy truth as reviewing. On this
     * document type that means an actor holding review permission within scope
     * may clear a block even on a document they uploaded, because that is what
     * the canonical odontogram policy permits for reviewing it.
     */
    public function canClearTriage(?User $actor, Model $import): bool
    {
        return $this->canReview($actor, $import);
    }

    public function applyReview(User $actor, int $importId): LegacyBatchReviewApplyOutcome
    {
        // GATE 1 — feature capability. Checked as a boolean rather than letting
        // the guard abort, so one disabled capability refuses this item cleanly
        // instead of aborting the whole submit pass with a 404.
        if (! $this->feature->migrationEnabled()) {
            return LegacyBatchReviewApplyOutcome::refused(
                LegacyBatchReviewReason::REFUSAL_FEATURE_DISABLED,
                'Kapabilitas migrasi odontogram lama tidak aktif.'
            );
        }

        // GATE 2 — branch-scoped resolution. Absence, not a permission error.
        $import = $this->findInScope($actor, $importId);

        if (! $import instanceof LegacyOdontogramImport) {
            return LegacyBatchReviewApplyOutcome::refused(
                LegacyBatchReviewReason::REFUSAL_IMPORT_UNAVAILABLE,
                'Dokumen tidak tersedia pada cakupan cabang Anda.'
            );
        }

        // GATE 3 — the canonical policy.
        if (! $this->gate->forUser($actor)->allows('review', $import)) {
            return LegacyBatchReviewApplyOutcome::refused(
                LegacyBatchReviewReason::REFUSAL_NOT_AUTHORIZED,
                'Tidak berwenang meninjau dokumen ini.'
            );
        }

        // GATE 4 — the canonical review, which takes its own row lock and
        // re-validates pages and the visit attestation under it.
        try {
            $before = (string) $import->status;
            $reviewed = $this->publisher->review($import, $actor);

            return LegacyBatchReviewApplyOutcome::applied($before !== (string) $reviewed->status);
        } catch (Throwable $exception) {
            return LegacyBatchReviewRefusalClassifier::classify($exception);
        }
    }

    public function pagePreviewRouteName(): string
    {
        return 'settings.rme.legacy-odontograms.pages.show';
    }

    public function singleItemRouteName(): string
    {
        return 'settings.rme.legacy-odontograms.show';
    }
}
