<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Adapters;

use App\Models\User;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishEligibility;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishOutcome;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishReason;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishRefusalClassifier;
use App\Modules\LegacyImport\BatchReview\Adapters\LegacyOdontogramBatchReviewAdapter;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewItemSummary;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramImportRepositoryInterface;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramRecordRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramBranchBindingService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramDateRuleService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramFeatureGuard;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPublishService;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramWorkspaceScope;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Legacy odontogram side of the batch publish seam — PR2 §5, §6, §7.
 *
 * Odontogram has NO lifecycle service. Its canonical write path is the sequence
 * its own controller performs, and this adapter reproduces that sequence
 * exactly, in the same order, with the same meanings:
 *
 *   1. feature capability (the controller aborts 404 when migration is off)
 *   2. branch-scoped resolution, ABSENCE on failure — never 403, so an actor
 *      cannot probe which archive ids exist in a branch they cannot see
 *   3. the canonical policy ability `publish`
 *   4. LegacyOdontogramPublishService::publish()
 *
 * AND IT ADDS NO SEPARATION-OF-DUTIES CHECK, deliberately.
 *
 * §7 is explicit: "Do not invent one shared RME/Odontogram SOD rule… PR2 must
 * not make Odontogram either weaker OR silently stricter than its single-item
 * publish workflow without an explicit owner decision." The PR1 audit found
 * zero `separation` references anywhere in this module — no guard, no staffing
 * class, and a policy that checks only permission, scope and transition. An
 * uploader publishing their own odontogram chart is permitted behaviour today
 * through the single-item page, so adding the RME rule here would make the
 * batch surface silently stricter than the page it mirrors. If separation is
 * wanted for odontogram it belongs in the odontogram module, applied to both
 * surfaces at once, with an owner decision behind it.
 *
 * The canonical publish still re-locks the row and re-validates the visit
 * attestation, the date rules, the branch binding, the origin-branch drift,
 * every rendered page and the source file before writing the archive record.
 */
class LegacyOdontogramBatchPublishAdapter implements LegacyBatchPublishAdapter
{
    public function __construct(
        private readonly LegacyOdontogramImportRepositoryInterface $imports,
        private readonly LegacyOdontogramRecordRepositoryInterface $records,
        private readonly LegacyOdontogramPublishService $publisher,
        private readonly LegacyOdontogramWorkspaceScope $scope,
        private readonly LegacyOdontogramFeatureGuard $feature,
        private readonly LegacyOdontogramDateRuleService $dateRules,
        private readonly LegacyOdontogramBranchBindingService $branchBinding,
        private readonly Gate $gate,
        private readonly LegacyOdontogramBatchReviewAdapter $reviewAdapter,
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

    public function recordForeignKey(): string
    {
        return 'odontogram_legacy_record_id';
    }

    public function paginatePublishQueue(?User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $filters['status'] = LegacyOdontogramImportStatus::REVIEWED;

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
        /** @var LegacyOdontogramImport $import */
        return $import->source_pdf_sha256;
    }

    public function patientId(Model $import): ?int
    {
        /** @var LegacyOdontogramImport $import */
        return $import->patient_id !== null ? (int) $import->patient_id : null;
    }

    public function isReviewed(Model $import): bool
    {
        /** @var LegacyOdontogramImport $import */
        return $import->status === LegacyOdontogramImportStatus::REVIEWED;
    }

    public function publishedRecordId(int $importId): ?int
    {
        $record = $this->records->findBySourceImportId($importId);

        return $record !== null ? (int) $record->getKey() : null;
    }

    public function canPublish(?User $actor, Model $import): bool
    {
        if ($actor === null) {
            return false;
        }

        // The canonical policy, read-only. It already checks the permission,
        // the branch scope and the transition — so this re-expresses none of
        // them, and it adds no separation rule this archive does not have.
        return $this->gate->forUser($actor)->allows('publish', $import);
    }

    public function revalidate(?User $actor, Model $import): LegacyBatchPublishEligibility
    {
        /** @var LegacyOdontogramImport $import */
        if (! $this->migrationEnabled()) {
            return LegacyBatchPublishEligibility::refused(LegacyBatchPublishReason::FEATURE_DISABLED);
        }

        $existing = $this->publishedRecordId((int) $import->getKey());

        if ($existing !== null) {
            return LegacyBatchPublishEligibility::alreadyPublished($existing);
        }

        if (! $this->isReviewed($import)) {
            return LegacyBatchPublishEligibility::refused(LegacyBatchPublishReason::NOT_REVIEWED);
        }

        if (! $this->canPublish($actor, $import)) {
            return LegacyBatchPublishEligibility::refused(LegacyBatchPublishReason::AUTHORIZATION_REFUSED);
        }

        $patient = $import->patient;

        if ($patient === null) {
            return LegacyBatchPublishEligibility::refused(LegacyBatchPublishReason::PATIENT_BINDING_FAILED);
        }

        // ONE date, not a range — this archive models a document as a single
        // representative clinical date, and inventing a second would be a field
        // with no source of truth.
        $dateResult = $this->dateRules->evaluate(
            $patient,
            // As a date string, matching the RME side. This service's signature
            // is looser (it accepts DateTimeInterface), but depending on that
            // difference would make the two adapters silently non-portable.
            $import->selected_odontogram_date?->toDateString(),
            $import->isVisitPreverified() ? $import->verification_visit_date?->toDateString() : null,
        );

        if ($dateResult->failed()) {
            // Native is OPTIONAL here (REVISION-LEGACY-ODONTOGRAM-NATIVE-OPTIONAL-1):
            // a patient with no native odontogram is a VALID clinical state. The
            // boundary still applies when a native chart DOES exist, and that is
            // the code this distinguishes.
            $code = $dateResult->code === LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_ODONTOGRAM
                ? LegacyBatchPublishReason::NATIVE_BOUNDARY_FAILED
                : LegacyBatchPublishReason::DATE_RULE_FAILED;

            return LegacyBatchPublishEligibility::refused($code, $dateResult->message);
        }

        $branch = $this->branchBinding->resolveForPatient($patient, null);

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

        return LegacyBatchPublishEligibility::eligible();
    }

    public function applyPublish(User $actor, int $importId, array $attributes = []): LegacyBatchPublishOutcome
    {
        $before = $this->publishedRecordId($importId);

        // GATE 1 — feature capability, as a boolean rather than letting the
        // guard abort, so one disabled capability refuses this item cleanly
        // instead of aborting the whole pass with a 404.
        if (! $this->migrationEnabled()) {
            return LegacyBatchPublishOutcome::refused(
                LegacyBatchPublishReason::FEATURE_DISABLED,
                'Kapabilitas migrasi odontogram lama tidak aktif.'
            );
        }

        // GATE 2 — branch-scoped resolution. Absence, not a permission error.
        $import = $this->findInScope($actor, $importId);

        if (! $import instanceof LegacyOdontogramImport) {
            return LegacyBatchPublishOutcome::refused(
                LegacyBatchPublishReason::IMPORT_UNAVAILABLE,
                'Dokumen tidak tersedia pada cakupan cabang Anda.'
            );
        }

        // GATE 3 — the canonical policy.
        if (! $this->gate->forUser($actor)->allows('publish', $import)) {
            return LegacyBatchPublishOutcome::refused(
                LegacyBatchPublishReason::AUTHORIZATION_REFUSED,
                'Tidak berwenang mempublikasikan dokumen ini.'
            );
        }

        // GATE 4 — the canonical publish, which takes its own row lock, is
        // idempotent on UNIQUE(source_import_id), and writes the archive record
        // inside its own single transaction.
        try {
            $record = $this->publisher->publish($import, $attributes, $actor);
            $recordId = (int) $record->getKey();

            return $before !== null
                ? LegacyBatchPublishOutcome::alreadyPublished($recordId)
                : LegacyBatchPublishOutcome::created($recordId);
        } catch (Throwable $exception) {
            return LegacyBatchPublishRefusalClassifier::classify($exception);
        }
    }

    public function recordRouteName(): string
    {
        return 'rme.legacy-odontograms.show';
    }

    public function singleItemRouteName(): string
    {
        return 'settings.rme.legacy-odontograms.show';
    }
}
