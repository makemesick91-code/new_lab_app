<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Adapters;

use App\Models\User;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishEligibility;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishOutcome;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewItemSummary;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * The seam between batch publish orchestration and per-archive clinical truth
 * — PR2 §5, §7.
 *
 * Batch publish owns the work-queue, the per-item attempt record and the
 * report. It owns NO clinical rule and NO authorization rule. Everything
 * archive-specific — which service publishes, which gates run, whether
 * separation of duties applies at all — lives behind this interface, and each
 * implementation DELEGATES to the canonical path rather than re-deciding.
 *
 * WHY AN INTERFACE AND NOT A TYPE BRANCH — §7 is explicit about this
 * ------------------------------------------------------------------
 * "Do not invent one shared RME/Odontogram SOD rule." The audit that opened
 * PR1 found the enforcement layers genuinely differ:
 *
 *   - RME routes every write through LegacyRmeImportLifecycleService::perform(),
 *     the same canonical entry point the ops CLI uses, running six numbered
 *     gates INCLUDING separation of duties at PUBLISH.
 *   - Odontogram has no lifecycle service. Its controller composes the gates
 *     itself (feature capability, branch-scoped resolve, policy) and calls
 *     LegacyOdontogramPublishService directly. It enforces NO separation of
 *     duties anywhere in the module.
 *
 * §7 forbids making odontogram either weaker OR silently stricter than its
 * single-item publish workflow without an explicit owner decision. Two
 * implementations are how that is kept honest — and they make the worst
 * possible bug in this feature impossible to write by accident: dropping SOD
 * from RME (silently removing a clinical control) or adding it to odontogram
 * (silently diverging from the canonical page it mirrors).
 *
 * NOTHING HERE MAY WIDEN AUTHORIZATION. Implementations consult the canonical
 * scope, the canonical policy and the canonical guards. None accepts a branch
 * id, a permission name or a status from the request.
 */
interface LegacyBatchPublishAdapter
{
    /** LegacyImportType::LEGACY_RME | LEGACY_ODONTOGRAM. */
    public function importType(): string;

    /** Is the migration capability on for this archive? */
    public function migrationEnabled(): bool;

    /** The item table's import foreign-key column for this archive. */
    public function importForeignKey(): string;

    /** The item table's record foreign-key column for this archive. */
    public function recordForeignKey(): string;

    /**
     * The publish queue: imports in REVIEWED, scoped to what this actor may see.
     *
     * Status is FORCED by the implementation, never taken from the request —
     * this is the publish queue, and accepting a status would turn it into an
     * arbitrary workspace listing through which a non-REVIEWED document could be
     * surfaced as selectable.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginatePublishQueue(?User $actor, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Resolve one import within the actor's scope, or null.
     *
     * Null means "not visible to you", which callers surface the way the
     * canonical controllers do — as absence, never as a permission error, so an
     * actor cannot probe which ids exist in a branch they cannot see.
     */
    public function findInScope(?User $actor, int $importId): ?Model;

    /** PII-safe metadata for the workspace. Never KTP/NIK, never page bytes. */
    public function summarize(Model $import, bool $withPages = false): LegacyBatchReviewItemSummary;

    public function sourceChecksum(Model $import): ?string;

    public function patientId(Model $import): ?int;

    /** Is the canonical status still REVIEWED — publish's only legal entry state? */
    public function isReviewed(Model $import): bool;

    /**
     * The archive record id already filed for this import, or null.
     *
     * Reads the canonical records table, whose UNIQUE(source_import_id) is the
     * real idempotency key. Used to report ALREADY_PUBLISHED rather than
     * attempting a publish that the canonical layer would turn into a no-op.
     */
    public function publishedRecordId(int $importId): ?int;

    /**
     * May this actor publish this import right now, per the canonical rules?
     *
     * ADVISORY — for rendering and for §12's confirmation summary. The canonical
     * path re-decides under its own row lock, so an item that looks publishable
     * here may still be refused there. That is the design working.
     */
    public function canPublish(?User $actor, Model $import): bool;

    /**
     * Re-evaluate the archive-specific clinical rules for ONE import — §6.
     *
     * Covers what this archive actually has: date rules (reporting the PRECISE
     * native-boundary code, which a canonical ValidationException cannot carry),
     * branch binding and drift, source-patient binding, visit attestation, and
     * source/page integrity. Called immediately before the canonical publish.
     *
     * STILL ADVISORY. It never substitutes for the canonical re-validation; it
     * exists so the operator sees an honest "eligible now" count and so a
     * refusal gets its most precise code. The canonical publish re-runs every
     * one of these under its own lock regardless.
     */
    public function revalidate(?User $actor, Model $import): LegacyBatchPublishEligibility;

    /**
     * Publish ONE import through the canonical path.
     *
     * MUST call the canonical service. That call is where the row lock is taken,
     * every gate re-evaluated, and the archive record written inside its own
     * single transaction. Implementations catch the canonical refusal, classify
     * it, and return it — they never swallow it, never open a transaction
     * spanning more than the canonical call, and never retry.
     *
     * Returning an outcome with `created: false` means the canonical layer found
     * an existing record: exactly one publication exists, and this attempt was
     * the idempotent one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function applyPublish(User $actor, int $importId, array $attributes = []): LegacyBatchPublishOutcome;

    /** Route name for the published archive record detail page. */
    public function recordRouteName(): string;

    /** Route name for the canonical single-item staging page. */
    public function singleItemRouteName(): string;
}
