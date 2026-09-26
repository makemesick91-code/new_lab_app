<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1 — the human date
 * attestation made at a REAL VISIT becomes first-class structured evidence.
 *
 * ADDITIVE ONLY. No column is dropped, narrowed or rewritten, and nothing is
 * backfilled. Rows staged before this sprint keep NULLs and stay on exactly the
 * path they were filed under.
 *
 * WHAT THIS IS FOR — AND WHAT IT IS EXPLICITLY NOT.
 *
 * `VISIT_PREVERIFIED` means the DATES were verified once, by a named human, at
 * a named real visit, against a named file. It does NOT mean separation of
 * duties is bypassed and it does NOT mean the document self-publishes. The
 * uploader still cannot certify their own document where LEGACY-RME-SOD-1
 * applies; these columns exist so the SECOND human never has to re-enter the
 * dates, not so that the second human disappears.
 *
 * WHY A COLUMN AND NOT JUST AN AUDIT LINE. `sys_audit_logs` is an append-only
 * narrative: excellent for "what happened", useless for "is this attestation
 * still valid RIGHT NOW, under the row lock I am holding". Finalization has to
 * re-assert the attestation against the locked staging row before it freezes a
 * permanent clinical record, and a narrative log cannot be locked, joined or
 * compared. The audit trail is still written — it is simply not the authority.
 *
 * WHY THE VISIT DATE IS COPIED. `verification_visit_date` is the ceiling the
 * human actually attested against, stored independently of the visit row. A
 * visit can be rescheduled, corrected or hard-deleted; the question this
 * evidence must answer forever is "what bound was this document accepted
 * under?", and that answer must not be able to change underneath the record.
 * Revalidation compares the CURRENT visit against this snapshot and refuses on
 * drift rather than silently adopting the new value.
 *
 * WHY THE SHA IS COPIED. The attestation is about ONE file. `verified_source_sha256`
 * binds the human's statement to the exact bytes they read. If the stored
 * document ever differs from the hash the attestation was made against, the
 * attestation is void — a human certified a different document.
 *
 * NULLABLE, AND THAT IS NOT A WEAKNESS. The legacy backlog path (bulk/single
 * import outside a real visit) writes none of these and must keep working
 * untouched. NULL therefore means exactly "this document did not come through
 * the visit-bound path", which is a true statement about every historical row.
 * The application write path requires the full set for a preverified import,
 * and finalization refuses a partially-populated one rather than guessing.
 *
 * ODONTOGRAM CARRIES ONE DATE, NOT TWO. The legacy odontogram archive models a
 * document as a single representative clinical date, so it gets
 * `verified_selected_date` and no `verified_latest_date`. Inventing a range
 * column there to match RME would create a field with no source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stg_rme_legacy_imports', function (Blueprint $table) {
            // 'VISIT_PREVERIFIED' — an explicit mode, never inferred from the
            // mere presence of a visit id or a non-null reviewer.
            $table->string('verification_mode', 32)->nullable()->after('source_rm_resolution');

            // The REAL visit this attestation was made at. nullOnDelete rather
            // than restrict: evidence must not be able to block an operational
            // delete, and the snapshot columns below survive independently.
            $table->foreignId('verification_visit_id')
                ->nullable()
                ->after('verification_mode')
                ->constrained('trx_clinic_visits')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            // The ceiling actually attested against, frozen at attestation time.
            $table->date('verification_visit_date')->nullable()->after('verification_visit_id');

            $table->foreignId('verified_by')
                ->nullable()
                ->after('verification_visit_date')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('verified_at')->nullable()->after('verified_by');

            // The dates the human stated, kept beside the operational columns
            // so a later edit to the operational value can never masquerade as
            // the value that was attested.
            $table->date('verified_selected_date')->nullable()->after('verified_at');
            $table->date('verified_latest_date')->nullable()->after('verified_selected_date');

            // Binds the attestation to the exact bytes the human read.
            $table->string('verified_source_sha256', 64)->nullable()->after('verified_latest_date');

            $table->index('verification_mode', 'stg_rme_legacy_imports_verification_mode_idx');
            $table->index('verification_visit_id', 'stg_rme_legacy_imports_verification_visit_idx');
        });

        Schema::table('stg_odontogram_legacy_imports', function (Blueprint $table) {
            $table->string('verification_mode', 32)->nullable()->after('earliest_native_odontogram_date_snapshot');

            $table->foreignId('verification_visit_id')
                ->nullable()
                ->after('verification_mode')
                ->constrained('trx_clinic_visits')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->date('verification_visit_date')->nullable()->after('verification_visit_id');

            $table->foreignId('verified_by')
                ->nullable()
                ->after('verification_visit_date')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('verified_at')->nullable()->after('verified_by');

            // One date only — see the class comment.
            $table->date('verified_selected_date')->nullable()->after('verified_at');

            $table->string('verified_source_sha256', 64)->nullable()->after('verified_selected_date');

            $table->index('verification_mode', 'stg_odo_legacy_imports_verification_mode_idx');
            $table->index('verification_visit_id', 'stg_odo_legacy_imports_verification_visit_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stg_odontogram_legacy_imports', function (Blueprint $table) {
            $table->dropIndex('stg_odo_legacy_imports_verification_mode_idx');
            $table->dropIndex('stg_odo_legacy_imports_verification_visit_idx');
            $table->dropConstrainedForeignId('verification_visit_id');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn([
                'verification_mode',
                'verification_visit_date',
                'verified_at',
                'verified_selected_date',
                'verified_source_sha256',
            ]);
        });

        Schema::table('stg_rme_legacy_imports', function (Blueprint $table) {
            $table->dropIndex('stg_rme_legacy_imports_verification_mode_idx');
            $table->dropIndex('stg_rme_legacy_imports_verification_visit_idx');
            $table->dropConstrainedForeignId('verification_visit_id');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn([
                'verification_mode',
                'verification_visit_date',
                'verified_at',
                'verified_selected_date',
                'verified_latest_date',
                'verified_source_sha256',
            ]);
        });
    }
};
