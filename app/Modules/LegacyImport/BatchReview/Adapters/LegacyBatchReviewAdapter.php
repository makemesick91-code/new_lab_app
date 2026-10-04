<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Adapters;

use App\Models\User;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewApplyOutcome;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewItemSummary;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * The seam between batch orchestration and per-type clinical truth — PR1 §3, §10.
 *
 * Batch review owns the work-queue, the per-item attestation record and the
 * reporting. It owns NO clinical rule and NO authorization rule. Everything
 * type-specific — which service performs a review, which gates run, whether
 * separation of duties applies at all — lives behind this interface, and each
 * implementation DELEGATES to the canonical path rather than re-deciding.
 *
 * WHY AN INTERFACE AND NOT A TYPE BRANCH
 * --------------------------------------
 * The audit that opened this sprint found the two lifecycles share an identical
 * nine-state machine but differ sharply in enforcement:
 *
 *   - RME routes every write through LegacyRmeImportLifecycleService::perform(),
 *     the same canonical entry point the ops CLI uses, which runs six numbered
 *     gates INCLUDING separation of duties on review.
 *   - Odontogram has no lifecycle service at all. Its controller composes the
 *     gates itself (feature guard, branch-scoped resolve, policy) and calls
 *     LegacyOdontogramPublishService directly. It enforces NO separation of
 *     duties — grep over the module for `separation` returns zero hits, so an
 *     uploader may legitimately review their own odontogram.
 *
 * A single method with `if ($type === ...)` branches would bury that asymmetry
 * in the middle of the orchestrator and make each type impossible to test on
 * its own. It would also invite the worst possible bug in this feature:
 * accidentally applying RME's separation rule to odontogram (breaking a
 * legitimate workflow) or, far worse, dropping it from RME (silently removing a
 * clinical control). Two implementations keep both honest, and the mass-upload
 * adapter already set this precedent for exactly the same reason.
 *
 * NOTHING HERE MAY WIDEN AUTHORIZATION. Implementations consult the canonical
 * scope, the canonical policy and the canonical guards. None of them accepts a
 * branch id, a permission name or a status from the request.
 */
interface LegacyBatchReviewAdapter
{
    /** LegacyImportType::LEGACY_RME | LEGACY_ODONTOGRAM. */
    public function importType(): string;

    /**
     * Is the migration capability on for this document type?
     *
     * Read as a boolean so the workspace can render an honest "off" state
     * rather than a 404 mid-session; the write paths still abort.
     */
    public function migrationEnabled(): bool;

    /**
     * The decision table's foreign-key column for this type.
     *
     * `rme_legacy_import_id` or `odontogram_legacy_import_id`. One accessor so
     * no caller re-derives the discriminator — that is how a type-confusion bug
     * would write an odontogram id into the RME column.
     */
    public function importForeignKey(): string;

    /**
     * The review queue: imports in READY_FOR_REVIEW, scoped to what this actor
     * may see.
     *
     * Branch scope comes from the canonical workspace scope for this type, which
     * for odontogram resolves a doctor through their practice set rather than
     * BranchContext, and which has deliberately separate membership from RME's.
     * The filters array is operator search input only; it can never widen scope.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateReviewQueue(?User $actor, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Resolve one import within the actor's scope, or null.
     *
     * Null means "not visible to you", which callers must surface the way the
     * canonical controllers do — as absence, not as a permission error, so an
     * actor cannot probe which ids exist in a branch they cannot see.
     */
    public function findInScope(?User $actor, int $importId): ?Model;

    /**
     * PII-safe metadata for the workspace. Never KTP/NIK, never page bytes.
     *
     * `$withPages` controls whether the rendered page NUMBERS are loaded. A
     * queue listing needs only the count, which is already a column on the
     * staging row — loading pages for every row would issue one query per row
     * and buy nothing, since the list renders no previews. The focused document
     * passes true because it actually displays each page.
     */
    public function summarize(Model $import, bool $withPages = false): LegacyBatchReviewItemSummary;

    /** The source document checksum the reviewer's attestation refers to. */
    public function sourceChecksum(Model $import): ?string;

    /** The patient this import is filed against, server-resolved. */
    public function patientId(Model $import): ?int;

    /**
     * Is this import still in a state where review is the next step?
     *
     * Advisory, for rendering and for the submit summary. It is NOT the gate —
     * the canonical path re-decides under its own row lock, and an item that
     * looks eligible here may still be refused there. That is the design
     * working, exactly as the mass-upload ELIGIBLE verdict documents.
     */
    public function isReviewable(Model $import): bool;

    /**
     * May this actor review this import right now, per the canonical rules?
     *
     * Advisory for the same reason as isReviewable(). Implementations consult
     * the canonical permission, the canonical policy and — where the type has
     * one — the canonical separation-of-duties guard.
     */
    public function canReview(?User $actor, Model $import): bool;

    /**
     * May this actor CLEAR or CHANGE sticky triage on this import? — PR1 §5.
     *
     * Clearing a block is a review-authority act, so this follows the same
     * policy truth as review for this document type. On RME that means an
     * uploader barred from reviewing their own document is equally barred from
     * clearing a block on it; on odontogram, where no separation guard exists,
     * an actor holding review permission may. The rule is REUSED, never
     * reinvented per surface.
     */
    public function canClearTriage(?User $actor, Model $import): bool;

    /**
     * Carry ONE attested REVIEWED decision to the canonical review path.
     *
     * MUST call the canonical service. That call is where the row lock is taken
     * and every gate re-evaluated, so a stale attestation becomes a refusal here
     * rather than a false review. Implementations catch the canonical refusal,
     * classify it, and return it — they never swallow it, and they never open a
     * transaction spanning more than the canonical call, because one import is
     * one transaction and the post-commit audit rows would otherwise lie.
     */
    public function applyReview(User $actor, int $importId): LegacyBatchReviewApplyOutcome;

    /** Route name for the private page-image preview of one rendered page. */
    public function pagePreviewRouteName(): string;

    /** Route name for the canonical single-item workspace page. */
    public function singleItemRouteName(): string;
}
