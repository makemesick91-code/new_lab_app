<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR2) — batch publish orchestration.
 *
 * ADDITIVE ONLY. Two new tables. No column dropped, no existing table altered,
 * no backfill. `migrate` only — never migrate:fresh / db:wipe on the VPS.
 *
 * ORCHESTRATION ONLY — NOT A SECOND CLINICAL STATE MACHINE (§10)
 * --------------------------------------------------------------
 * These tables remember which documents an operator selected, what the server
 * then decided about each, and which immutable archive record (if any) each
 * attempt produced. They are a work-queue and a report.
 *
 * Clinical truth stays exactly where it already is: the canonical import's own
 * status, and the `trx_*_legacy_records` row with its `UNIQUE(source_import_id)`.
 * Deleting every row here would lose the operator's report and change not one
 * patient's archive — and, critically, would NOT un-publish anything.
 *
 * WHY `published_record_id` IS A PLAIN INDEXED POINTER, NOT A UNIQUE KEY
 * ----------------------------------------------------------------------
 * It is a convenience for the report, so an operator can click through from an
 * attempt to the archive it produced. The real idempotency key is the UNIQUE
 * index the canonical records table already carries on `source_import_id` —
 * putting a second unique constraint here would mean two batch runs that both
 * legitimately *observe* the same already-published record (the second
 * returning `created: false`) would collide on our bookkeeping instead of
 * reporting ALREADY_PUBLISHED cleanly.
 *
 * WHY TWO NULLABLE FKs INSTEAD OF A POLYMORPHIC PAIR
 * --------------------------------------------------
 * Same choice `stg_legacy_batch_review_decisions` and
 * `stg_legacy_mass_upload_items` already make: a polymorphic (type, id) pair
 * gives up referential integrity for nothing. The `import_type` discriminator
 * is kept alongside them for indexing and audit payloads, and the unique
 * indexes rely on both PostgreSQL and SQLite treating NULLs as DISTINCT, so the
 * RME index ignores every odontogram row and vice versa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stg_legacy_batch_publish_runs')) {
            Schema::create('stg_legacy_batch_publish_runs', function (Blueprint $table): void {
                $table->id();

                // Public handle for URLs and audit payloads, so the numeric id is
                // never what an operator pastes around.
                $table->uuid('uuid')->unique();

                // LegacyImportType::LEGACY_RME | LEGACY_ODONTOGRAM. A run is
                // single-type on purpose: the two archives have different
                // enforcement (RME enforces separation of duties at publish,
                // odontogram does not), and a mixed run would have to carry two
                // guard chains behind one confirm button.
                $table->string('import_type', 40)->index();

                $table->string('status', 40)->index();

                // The publisher. RESTRICT, never cascade: deleting a user must
                // not erase who authorised a clinical publication run.
                $table->foreignId('started_by')
                    ->constrained('users')
                    ->restrictOnDelete();

                // Provenance only — the publisher's BranchContext when the run
                // opened. NOT the authorization boundary: scope is re-resolved
                // from the canonical workspace scope on every read and write.
                $table->foreignId('origin_branch_id')
                    ->nullable()
                    ->constrained('mst_branches')
                    ->nullOnDelete();

                // Counters are a reporting convenience for the operator summary
                // and the batch audit payload. The item rows remain the source of
                // truth; these are recomputed from them, never incremented blind.
                $table->unsignedInteger('selected_count')->default(0);
                $table->unsignedInteger('attempted_count')->default(0);
                $table->unsignedInteger('published_count')->default(0);
                $table->unsignedInteger('refused_count')->default(0);

                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('abandoned_at')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->index(['import_type', 'status'], 'stg_lbp_runs_type_status_index');
                $table->index(['started_by', 'created_at'], 'stg_lbp_runs_starter_index');
            });
        }

        if (! Schema::hasTable('stg_legacy_batch_publish_items')) {
            Schema::create('stg_legacy_batch_publish_items', function (Blueprint $table): void {
                $table->id();

                // An item is meaningless without its run, so cascade is correct
                // here. Nothing clinical is reachable through this cascade — the
                // archive record is referenced by a nullOnDelete pointer below.
                $table->foreignId('batch_publish_run_id')
                    ->constrained('stg_legacy_batch_publish_runs')
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

                // The source checksum as it stood at selection time. Evidence
                // that the attempt referred to these bytes; the authoritative
                // integrity re-check happens inside the canonical publish.
                $table->string('source_sha256', 64)->nullable()->index();

                // LegacyBatchPublishItemStatus: PENDING | PUBLISHED | REFUSED |
                // SKIPPED. PENDING is what makes a run resumable after an
                // interruption — §9.
                $table->string('status', 40)->default('PENDING')->index();

                // Stable machine reason (LegacyBatchPublishReason) plus a message
                // safe to show an operator. No SQL, no stack trace, no path.
                $table->string('reason_code', 64)->nullable()->index();
                $table->text('reason_message')->nullable();

                // The archive record this attempt produced OR observed. See the
                // class docblock for why this is not unique.
                $table->foreignId('rme_legacy_record_id')
                    ->nullable()
                    ->constrained('trx_rme_legacy_records')
                    ->nullOnDelete();

                $table->foreignId('odontogram_legacy_record_id')
                    ->nullable()
                    ->constrained('trx_odontogram_legacy_records')
                    ->nullOnDelete();

                // False when the canonical publish returned an EXISTING record
                // rather than creating one — i.e. this attempt was idempotent.
                // Distinguishes "we published it" from "it was already there",
                // which §4 requires the report to state rather than silently
                // count as a success.
                $table->boolean('created_record')->default(false);

                $table->timestamp('attempted_at')->nullable();
                $table->timestamp('published_at')->nullable();

                $table->timestamps();

                // One attempt per document per run. A re-selection updates the
                // row rather than appending, so "what did this run decide about
                // this document" has exactly one answer, and a resumed pass
                // cannot create a second attempt for the same item.
                $table->unique(
                    ['batch_publish_run_id', 'rme_legacy_import_id'],
                    'stg_lbp_items_run_rme_uq'
                );
                $table->unique(
                    ['batch_publish_run_id', 'odontogram_legacy_import_id'],
                    'stg_lbp_items_run_odo_uq'
                );

                $table->index(['batch_publish_run_id', 'status'], 'stg_lbp_items_run_status_index');
            });
        }
    }

    public function down(): void
    {
        // Drops ONLY what this migration created, children first. Nothing
        // pre-existing is touched, so a rollback cannot take a published archive
        // record or a canonical staging row with it — and cannot un-publish.
        Schema::dropIfExists('stg_legacy_batch_publish_items');
        Schema::dropIfExists('stg_legacy_batch_publish_runs');
    }
};
