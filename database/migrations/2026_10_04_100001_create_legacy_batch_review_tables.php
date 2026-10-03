<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR1) — batch review workspace staging.
 *
 * ADDITIVE ONLY. Three new tables. No column dropped, no existing table
 * altered, no backfill. `migrate` only — never migrate:fresh / db:wipe on the
 * VPS.
 *
 * WHY NOTHING HERE IS CLINICAL
 * ----------------------------
 * A batch review session is an operator work-queue. The clinical record of
 * review stays exactly where it already is: `reviewed_by` / `reviewed_at` on
 * the canonical staging row, stamped only by the canonical review service under
 * its own row lock. These tables remember which items an operator looked at,
 * what they attested per item, and what the server then decided — never the
 * review itself.
 *
 * That separation is what keeps this feature non-destructive. A decision row
 * has no clinical authority: deleting every row here would lose the operator's
 * work-queue and change not one patient's archive.
 *
 * WHY TRIAGE IS ITS OWN TABLE
 * ---------------------------
 * The owner's rule is that "Blocked" must leave the canonical lifecycle
 * untouched:
 *
 *     canonical import status = READY_FOR_REVIEW   (unchanged, clinical)
 *     review_triage_status    = BLOCKED            (new, operational)
 *
 * and NOT `canonical import status = CANCELLED`. No new clinical lifecycle
 * state is invented because the existing schema does not require one — triage
 * is a second, independent axis.
 *
 * It is a separate table from the decision history rather than a derived
 * "latest decision" for two concrete reasons. First, PR2 must answer "is this
 * item blocked?" by reading exactly one indexed row, not by ranking history.
 * Second, clearing a block is an explicit authorized state change with its own
 * actor and timestamp; inferring "cleared" from the absence of a newer row
 * would make the authorization boundary invisible.
 *
 * WHY TWO NULLABLE FKs INSTEAD OF A POLYMORPHIC PAIR
 * --------------------------------------------------
 * Same choice `stg_legacy_mass_upload_items` already makes. A polymorphic
 * (type, id) pair buys nothing and gives up referential integrity; two nullable
 * foreign keys let the database itself refuse a decision that points at an
 * import which does not exist. The `import_type` discriminator is kept
 * alongside them for indexing and for audit payloads.
 *
 * The unique indexes below exploit the fact that both PostgreSQL and SQLite
 * treat NULLs as DISTINCT in a unique index: in an RME session every row has
 * `odontogram_legacy_import_id IS NULL`, so that index permits all of them
 * while still pinning one decision per real odontogram import.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stg_legacy_batch_review_sessions')) {
            Schema::create('stg_legacy_batch_review_sessions', function (Blueprint $table): void {
                $table->id();

                // Public handle for URLs and audit payloads, so the numeric id
                // is never what an operator pastes around.
                $table->uuid('uuid')->unique();

                // LegacyImportType::LEGACY_RME | LEGACY_ODONTOGRAM. A session is
                // single-type on purpose: the two document types have different
                // enforcement layers (RME enforces separation of duties,
                // odontogram does not), and a mixed session would have to carry
                // two guard chains behind one submit button.
                $table->string('import_type', 40)->index();

                $table->string('status', 40)->index();

                // The reviewer who opened the session. RESTRICT, never cascade:
                // deleting a user must not erase who attested a clinical review.
                $table->foreignId('opened_by')
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->foreignId('submitted_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                // Provenance only — the reviewer's BranchContext when the
                // session opened. NOT the authorization boundary: every read and
                // every write re-resolves scope from the canonical workspace
                // scope for the acting user at that moment.
                $table->foreignId('origin_branch_id')
                    ->nullable()
                    ->constrained('mst_branches')
                    ->nullOnDelete();

                // Counts are a reporting convenience for the operator summary
                // and the batch audit payload. The decision rows remain the
                // source of truth; these are recomputed from them, never
                // incremented blind.
                $table->unsignedInteger('marked_reviewed')->default(0);
                $table->unsignedInteger('marked_blocked')->default(0);
                $table->unsignedInteger('marked_attention')->default(0);
                $table->unsignedInteger('applied_reviewed')->default(0);
                $table->unsignedInteger('refused_reviewed')->default(0);

                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('abandoned_at')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->index(['import_type', 'status'], 'stg_lbr_sessions_type_status_index');
                $table->index(['opened_by', 'created_at'], 'stg_lbr_sessions_opener_index');
            });
        }

        if (! Schema::hasTable('stg_legacy_batch_review_decisions')) {
            Schema::create('stg_legacy_batch_review_decisions', function (Blueprint $table): void {
                $table->id();

                // A decision is meaningless without its session, so cascade is
                // correct here — it mirrors how mass-upload items hang off their
                // batch. Nothing clinical is reachable through this cascade.
                $table->foreignId('batch_review_session_id')
                    ->constrained('stg_legacy_batch_review_sessions')
                    ->cascadeOnDelete();

                $table->string('import_type', 40)->index();

                $table->foreignId('rme_legacy_import_id')
                    ->nullable()
                    ->constrained('stg_rme_legacy_imports')
                    ->nullOnDelete();

                $table->foreignId('odontogram_legacy_import_id')
                    ->nullable()
                    ->constrained('stg_odontogram_legacy_imports')
                    ->nullOnDelete();

                // Server-resolved from the import, never submitted by the client.
                $table->foreignId('patient_id')
                    ->nullable()
                    ->constrained('mst_patients')
                    ->nullOnDelete();

                // The source document's checksum as it stood when the reviewer
                // attested. §4 requires the decision to identify the bytes that
                // were actually inspected; if the document were ever replaced,
                // this is what proves the attestation referred to something else.
                $table->string('source_sha256', 64)->nullable()->index();

                // LegacyBatchReviewDecision: REVIEWED | BLOCKED | NEEDS_ATTENTION.
                $table->string('decision', 40)->index();

                // Stable machine code plus a note safe to show an operator. No
                // SQL, no stack trace, no path, no clinical content.
                $table->string('reason_code', 64)->nullable();
                $table->text('reason_note')->nullable();

                // The human attestation: who decided, and when.
                $table->foreignId('decided_by')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('decided_at');

                // SUPPORTING EVIDENCE ONLY — never a precondition the system can
                // satisfy on the operator's behalf. Browser-open does not prove
                // clinical review; the attestation above does. These exist so an
                // audit can see whether a decision looks plausible, and are
                // deliberately not consulted by any gate.
                $table->timestamp('first_viewed_at')->nullable();
                $table->unsignedSmallInteger('pages_viewed')->default(0);

                // LegacyBatchReviewSubmitStatus: PENDING | APPLIED | REFUSED |
                // SKIPPED. PENDING is what makes a session resumable after a
                // browser disconnect — see §16.
                $table->string('submit_status', 40)->default('PENDING')->index();

                $table->string('refusal_code', 64)->nullable();
                $table->text('refusal_message')->nullable();
                $table->timestamp('applied_at')->nullable();

                $table->timestamps();

                // One decision per item per session. A re-mark updates the row
                // rather than appending, so "what did this reviewer decide about
                // this item in this session" has exactly one answer.
                $table->unique(
                    ['batch_review_session_id', 'rme_legacy_import_id'],
                    'stg_lbr_decisions_session_rme_uq'
                );
                $table->unique(
                    ['batch_review_session_id', 'odontogram_legacy_import_id'],
                    'stg_lbr_decisions_session_odo_uq'
                );

                $table->index(
                    ['batch_review_session_id', 'decision'],
                    'stg_lbr_decisions_session_decision_index'
                );
                $table->index(
                    ['batch_review_session_id', 'submit_status'],
                    'stg_lbr_decisions_session_submit_index'
                );
            });
        }

        if (! Schema::hasTable('stg_legacy_review_triage')) {
            Schema::create('stg_legacy_review_triage', function (Blueprint $table): void {
                $table->id();

                $table->string('import_type', 40)->index();

                $table->foreignId('rme_legacy_import_id')
                    ->nullable()
                    ->constrained('stg_rme_legacy_imports')
                    ->nullOnDelete();

                $table->foreignId('odontogram_legacy_import_id')
                    ->nullable()
                    ->constrained('stg_odontogram_legacy_imports')
                    ->nullOnDelete();

                $table->foreignId('patient_id')
                    ->nullable()
                    ->constrained('mst_patients')
                    ->nullOnDelete();

                // LegacyReviewTriageStatus: BLOCKED | NEEDS_ATTENTION | CLEARED.
                //
                // CLEARED is retained rather than deleting the row, so that
                // "this was once blocked and someone with review authority
                // cleared it" stays answerable. PR2's eligibility check is
                // therefore "no row with status in (BLOCKED, NEEDS_ATTENTION)".
                $table->string('triage_status', 40)->index();

                $table->string('reason_code', 64)->nullable();
                $table->text('reason_note')->nullable();

                // The session the triage was raised from, when it came from one.
                // Nullable because triage outlives its session: a cleared-then-
                // re-blocked item may be touched from a later session, and a
                // deleted session must not drop the block.
                $table->foreignId('raised_in_session_id')
                    ->nullable()
                    ->constrained('stg_legacy_batch_review_sessions')
                    ->nullOnDelete();

                $table->foreignId('decided_by')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('decided_at');

                // Clearing is an authorized review act in its own right, so it
                // records its own actor and time rather than overwriting the
                // original reviewer's attestation.
                $table->foreignId('cleared_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamp('cleared_at')->nullable();

                $table->timestamps();

                // THE STICKINESS INVARIANT: at most one triage row per canonical
                // import. Nullable columns plus NULL-distinct semantics mean the
                // RME index ignores every odontogram row and vice versa.
                $table->unique('rme_legacy_import_id', 'stg_lbr_triage_rme_uq');
                $table->unique('odontogram_legacy_import_id', 'stg_lbr_triage_odo_uq');

                $table->index(['import_type', 'triage_status'], 'stg_lbr_triage_type_status_index');
            });
        }
    }

    public function down(): void
    {
        // Drops ONLY what this migration created, children first. Nothing
        // pre-existing is touched, so a rollback cannot take a clinical table
        // or a canonical staging row with it.
        Schema::dropIfExists('stg_legacy_review_triage');
        Schema::dropIfExists('stg_legacy_batch_review_decisions');
        Schema::dropIfExists('stg_legacy_batch_review_sessions');
    }
};
