<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Adapters;

use App\Models\User;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishEligibility;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishOutcome;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishReason;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishRefusalClassifier;
use App\Modules\LegacyImport\BatchReview\Adapters\LegacyRmeBatchReviewAdapter;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewItemSummary;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmeRecordRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Services\LegacyRmeBranchResolver;
use App\Modules\LegacyRme\Services\LegacyRmeDateRuleService;
use App\Modules\LegacyRme\Services\LegacyRmeImportLifecycleService;
use App\Modules\LegacyRme\Services\LegacyRmeSourcePatientBindingService;
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
 * Legacy RME side of the batch publish seam — PR2 §5, §6, §7.
 *
 * RME already has one canonical write path, LegacyRmeImportLifecycleService
 * ::perform(), shared verbatim by the HTTP controller and the ops CLI. It runs
 * six numbered gates: feature capability, branch-scoped resolution, route
 * permission, policy, SEPARATION OF DUTIES, and the mutation wrapped in an
 * audit channel. The publish service beneath it then re-locks the row and
 * re-validates the date rules, the branch binding, the source-patient binding,
 * the visit attestation and every rendered page before writing the archive
 * record — all inside one transaction.
 *
 * So this adapter re-expresses NONE of it. applyPublish() calls perform() once
 * per import with the BATCH channel and classifies whatever comes back.
 * Separation of duties in particular is INHERITED: asserting it here as well
 * would create a second expression of a clinical control free to drift from the
 * one the CLI and the single-item page already trust.
 *
 * WHAT revalidate() IS FOR, AND WHAT IT IS NOT
 * --------------------------------------------
 * It is the §6 pre-flight, and it REUSES the very services the canonical
 * publish uses — the same date-rule service, the same branch resolver, the same
 * source-binding service — rather than reimplementing their logic. Its only
 * jobs are to give §12 an honest "eligible now" count and to recover the
 * PRECISE refusal code that a canonical ValidationException cannot carry
 * (notably native-boundary vs any other date failure).
 *
 * It is NOT the gate. The canonical publish re-runs all of it under its own row
 * lock, and an item this method calls eligible can still be refused there.
 */
class LegacyRmeBatchPublishAdapter implements LegacyBatchPublishAdapter
{
    public function __construct(
        private readonly LegacyRmeImportRepositoryInterface $imports,
        private readonly LegacyRmeRecordRepositoryInterface $records,
        private readonly LegacyRmeImportLifecycleService $lifecycle,
        private readonly LegacyRmeWorkspaceScope $scope,
        private readonly LegacyRmeFeatureGuard $feature,
        private readonly LegacyRmeDateRuleService $dateRules,
        private readonly LegacyRmeBranchResolver $branchResolver,
        private readonly LegacyRmeSourcePatientBindingService $sourceBinding,
        // Consulted ONLY to answer the advisory "could this actor publish this?"
        // for rendering and the confirmation summary — never to decide whether a
        // publish happens. perform() owns that.
        private readonly SeparatePublisherGuard $separation,
        // Reused wholesale for the PII-safe summary rather than duplicating it.
        private readonly LegacyRmeBatchReviewAdapter $reviewAdapter,
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

    public function recordForeignKey(): string
    {
        return 'rme_legacy_record_id';
    }

    public function paginatePublishQueue(?User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        // FORCED, not taken from the request. This is the publish queue; letting
        // a caller pass `status` would surface a non-REVIEWED document as
        // selectable.
        $filters['status'] = LegacyRmeImportStatus::REVIEWED;

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
        return $this->reviewAdapter->summarize($import, $withPages);
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

    public function isReviewed(Model $import): bool
    {
        /** @var LegacyRmeImport $import */
        return $import->status === LegacyRmeImportStatus::REVIEWED;
    }

    public function publishedRecordId(int $importId): ?int
    {
        $record = $this->records->findBySourceImportId($importId);

        return $record !== null ? (int) $record->getKey() : null;
    }

    public function canPublish(?User $actor, Model $import): bool
    {
        /** @var LegacyRmeImport $import */
        if ($actor === null) {
            return false;
        }

        if (! $actor->can('publish_legacy_rme_imports')) {
            return false;
        }

        if (! $this->scope->allows($actor, $import->origin_branch_id !== null ? (int) $import->origin_branch_id : null)) {
            return false;
        }

        // The canonical separation guard, read-only. RME bars an uploader from
        // publishing their own document.
        return ! $this->separation->violates(LegacyRmeLifecycleAction::PUBLISH, $import, $actor);
    }

    public function revalidate(?User $actor, Model $import): LegacyBatchPublishEligibility
    {
        /** @var LegacyRmeImport $import */
        if (! $this->migrationEnabled()) {
            return LegacyBatchPublishEligibility::refused(LegacyBatchPublishReason::FEATURE_DISABLED);
        }

        // Already filed. Checked FIRST so a re-selected document reports the
        // benign ALREADY_PUBLISHED rather than the misleading NOT_REVIEWED it
        // would otherwise hit (a published import is no longer REVIEWED).
        $existing = $this->publishedRecordId((int) $import->getKey());

        if ($existing !== null) {
            return LegacyBatchPublishEligibility::alreadyPublished($existing);
        }

        if (! $this->isReviewed($import)) {
            return LegacyBatchPublishEligibility::refused(LegacyBatchPublishReason::NOT_REVIEWED);
        }

        if (! $this->canPublish($actor, $import)) {
            // Distinguish the one refusal an operator acts on differently: a
            // separation violation needs a DIFFERENT publisher, not a fix.
            $code = $actor !== null
                && $this->separation->violates(LegacyRmeLifecycleAction::PUBLISH, $import, $actor)
                    ? LegacyBatchPublishReason::SOD_REFUSED
                    : LegacyBatchPublishReason::AUTHORIZATION_REFUSED;

            return LegacyBatchPublishEligibility::refused(
                $code,
                $code === LegacyBatchPublishReason::SOD_REFUSED
                    ? $this->separation->message(LegacyRmeLifecycleAction::PUBLISH)
                    : null
            );
        }

        $patient = $import->patient;

        if ($patient === null) {
            return LegacyBatchPublishEligibility::refused(LegacyBatchPublishReason::PATIENT_BINDING_FAILED);
        }

        // THE SAME date-rule service the canonical publish uses. Reading
        // $result->code is the only way to tell a native-boundary failure from
        // any other date failure — a canonical ValidationException cannot carry
        // it, which is exactly why this pre-flight exists.
        // Dates passed EXACTLY as the canonical publish service passes them —
        // as date strings. The model casts these columns to
        // Illuminate\Support\Carbon, which evaluate() does not accept (it takes
        // CarbonImmutable|string|null), so handing the cast value straight
        // through is a TypeError rather than a validation result.
        $dateResult = $this->dateRules->evaluate(
            $patient,
            $import->selected_rme_date?->toDateString(),
            $import->latest_rme_date?->toDateString(),
            $import->isVisitPreverified() ? $import->verification_visit_date?->toDateString() : null,
        );

        if ($dateResult->failed()) {
            $code = $dateResult->code === LegacyRmeDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_RME
                ? LegacyBatchPublishReason::NATIVE_BOUNDARY_FAILED
                : LegacyBatchPublishReason::DATE_RULE_FAILED;

            return LegacyBatchPublishEligibility::refused($code, $dateResult->message);
        }

        // The same branch resolver, including the origin-branch drift check the
        // canonical publish performs.
        $branch = $this->branchResolver->resolveForPatient($patient);

        if ($branch->failed()) {
            return LegacyBatchPublishEligibility::refused(
                LegacyBatchPublishReason::BRANCH_REFUSED,
                $branch->message
            );
        }

        if ($import->origin_branch_id !== null
            && (int) $import->origin_branch_id !== (int) $branch->branchId
        ) {
            return LegacyBatchPublishEligibility::refused(
                LegacyBatchPublishReason::BRANCH_REFUSED,
                'Cabang arsip tidak lagi sesuai dengan Nomor RM pasien.'
            );
        }

        // The same source-patient binding service.
        $binding = $this->sourceBinding->verifyStaged($import);

        if ($binding->failed()) {
            return LegacyBatchPublishEligibility::refused(
                LegacyBatchPublishReason::PATIENT_BINDING_FAILED,
                $binding->message
            );
        }

        return LegacyBatchPublishEligibility::eligible();
    }

    public function applyPublish(User $actor, int $importId, array $attributes = []): LegacyBatchPublishOutcome
    {
        // Observed BEFORE the call so an idempotent result can be reported as
        // ALREADY_PUBLISHED rather than as a fresh publication.
        $before = $this->publishedRecordId($importId);

        try {
            $outcome = $this->lifecycle->perform(
                $actor,
                $importId,
                LegacyRmeLifecycleAction::PUBLISH,
                $attributes,
                LegacyRmeAuditEvent::CHANNEL_BATCH,
            );

            $recordId = $outcome->recordId !== null ? (int) $outcome->recordId : $this->publishedRecordId($importId);

            if ($recordId === null) {
                // The canonical path reported success without a record. Never
                // assume a publication happened.
                return LegacyBatchPublishOutcome::refused(
                    LegacyBatchPublishReason::PUBLISH_UNKNOWN,
                    'Publikasi tidak menghasilkan arsip yang dapat diverifikasi.'
                );
            }

            // created:false — the record already existed, so exactly one
            // publication exists and this attempt was the idempotent one.
            return $before !== null
                ? LegacyBatchPublishOutcome::alreadyPublished($recordId)
                : LegacyBatchPublishOutcome::created($recordId);
        } catch (Throwable $exception) {
            return LegacyBatchPublishRefusalClassifier::classify($exception);
        }
    }

    public function recordRouteName(): string
    {
        return 'rme.legacy-records.show';
    }

    public function singleItemRouteName(): string
    {
        return 'settings.rme.legacy-imports.show';
    }
}
