<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Adapters;

use App\Models\User;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewApplyOutcome;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewItemSummary;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewRefusalClassifier;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Services\LegacyRmeImportLifecycleService;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleAction;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use App\Modules\LegacyRme\Support\SeparatePublisherGuard;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Legacy RME side of the batch review seam — PR1 §3, §10, §18.
 *
 * RME already has a single canonical write path, LegacyRmeImportLifecycleService
 * ::perform(), shared verbatim by the HTTP controller and the ops CLI. It runs
 * six numbered gates: feature capability, branch-scoped resolution, route
 * permission, policy, SEPARATION OF DUTIES, and the mutation itself wrapped in
 * an audit channel.
 *
 * So this adapter re-expresses NONE of them. It calls perform() once per import
 * with the BATCH channel, and classifies whatever comes back. Separation of
 * duties in particular is inherited rather than re-checked: if the batch
 * surface asserted it independently, there would be two expressions of a
 * clinical control that could drift apart, and the one in the canonical service
 * is the one the CLI and the single-item page already trust.
 */
class LegacyRmeBatchReviewAdapter implements LegacyBatchReviewAdapter
{
    public function __construct(
        private readonly LegacyRmeImportRepositoryInterface $imports,
        private readonly LegacyRmeImportLifecycleService $lifecycle,
        private readonly LegacyRmeWorkspaceScope $scope,
        private readonly LegacyRmeFeatureGuard $feature,
        // Consulted ONLY to answer the advisory "could this actor review this?"
        // for rendering and for the clear-triage authorization, never to decide
        // whether a review happens — perform() owns that.
        private readonly SeparatePublisherGuard $separation,
    ) {}

    public function importType(): string
    {
        return LegacyImportType::LEGACY_RME;
    }

    public function migrationEnabled(): bool
    {
        return $this->feature->migrationEnabled();
    }

    public function importForeignKey(): string
    {
        return 'rme_legacy_import_id';
    }

    public function paginateReviewQueue(?User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        // Status is forced, not taken from the request: this is the REVIEW
        // queue, and letting a caller pass `status` would turn it into an
        // arbitrary workspace listing.
        $filters['status'] = LegacyRmeImportStatus::READY_FOR_REVIEW;

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
        /** @var LegacyRmeImport $import */
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
            // RME models a RANGE, so the display date is the selected date and
            // the latest date is shown alongside it by the view. Copying
            // odontogram's single-date shape here would hide a real field.
            clinicalDate: $import->selected_rme_date?->toDateString(),
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
        /** @var LegacyRmeImport $import */
        return $import->source_pdf_sha256;
    }

    public function patientId(Model $import): ?int
    {
        /** @var LegacyRmeImport $import */
        return $import->patient_id !== null ? (int) $import->patient_id : null;
    }

    public function isReviewable(Model $import): bool
    {
        /** @var LegacyRmeImport $import */
        return $import->status === LegacyRmeImportStatus::READY_FOR_REVIEW;
    }

    public function canReview(?User $actor, Model $import): bool
    {
        /** @var LegacyRmeImport $import */
        if ($actor === null) {
            return false;
        }

        if (! $actor->can('review_legacy_rme_imports')) {
            return false;
        }

        if (! $this->scope->allows($actor, $import->origin_branch_id !== null ? (int) $import->origin_branch_id : null)) {
            return false;
        }

        // The canonical separation guard, consulted read-only. RME bars an
        // uploader from reviewing their own document.
        return ! $this->separation->violates(LegacyRmeLifecycleAction::REVIEW, $import, $actor);
    }

    /**
     * Clearing sticky triage follows the SAME policy truth as reviewing — which
     * on RME includes separation of duties, so an uploader cannot clear a block
     * on a document they uploaded.
     */
    public function canClearTriage(?User $actor, Model $import): bool
    {
        return $this->canReview($actor, $import);
    }

    public function applyReview(User $actor, int $importId): LegacyBatchReviewApplyOutcome
    {
        try {
            $outcome = $this->lifecycle->perform(
                $actor,
                $importId,
                LegacyRmeLifecycleAction::REVIEW,
                [],
                LegacyRmeAuditEvent::CHANNEL_BATCH,
            );

            // An idempotent no-op is a SUCCESS. The canonical review returns the
            // already-REVIEWED row rather than complaining, and a resumed submit
            // depends on that being treated as applied.
            return LegacyBatchReviewApplyOutcome::applied($outcome->changed);
        } catch (Throwable $exception) {
            return LegacyBatchReviewRefusalClassifier::classify($exception);
        }
    }

    public function pagePreviewRouteName(): string
    {
        return 'settings.rme.legacy-imports.pages.show';
    }

    public function singleItemRouteName(): string
    {
        return 'settings.rme.legacy-imports.show';
    }
}
