<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — bind a doctor's session to the
 * branch it was established under.
 *
 * WHY THIS IS A SEPARATE MIGRATION AND NOT AN EDIT TO ITS OWN TABLE'S
 * CREATE. `trx_doctor_session_leases` is already deployed. Its create migration
 * (2026_09_10_100001) deliberately shipped with no branch column and said so in
 * its own docblock, on the rule that a table must never carry a column nothing
 * writes. One of the two columns below could not have existed there anyway:
 * `effective_cover_id` references `trx_doctor_branch_covers`, which is created
 * three migrations later, so the foreign key had no target until now. The
 * create migration is therefore left exactly as it was deployed and this one is
 * purely additive — the additive-migration-only rule the enterprise baseline
 * locks (ENT1-R008), and the reason `migrate:fresh` and `db:wipe` are never run
 * on the VPS.
 *
 * WHY THE EFFECTIVE BRANCH IS RECORDED ON THE LEASE AT ALL.
 *
 * Section Q: the session is bound to the branch it was established under, and
 * every protected request recomputes EFFECTIVE_CLINICAL_BRANCH purely from
 * current timestamps and compares. A cover that started, a cover that ended, a
 * transfer that was approved — all three become the same comparison, all three
 * invalidate the session, and none of them needs a scheduler to be correct.
 * Structurally, a branch cannot switch silently mid-session.
 *
 * BOTH COLUMNS ARE NULLABLE, AND NULL IS THE COMPATIBILITY STATE RATHER THAN
 * MISSING DATA. A doctor with no home lock and no cover has no effective branch
 * (owner decision O1: while UNSET, preserve current behaviour exactly). NULL
 * recomputes to NULL, matches, and the doctor is never evicted.
 * COMPARE IN PHP WITH ===, NEVER IN SQL: `NULL = NULL` is UNKNOWN, so a SQL
 * comparison would evict every UNSET doctor on their next request.
 *
 * THERE IS DELIBERATELY NO BACKFILL.
 *
 * Leases claimed between the two deploys carry NULL in both columns. That is
 * not a gap to be repaired: the comparison already tolerates it — a lease with
 * no recorded branch is treated as having been established before the
 * capability could answer, and is never evicted for a mismatch — and inferring
 * a branch for a session that is in flight right now would be exactly the
 * guess-recorded-as-fact that owner decision O1 forbids. Those sessions end
 * naturally at their next logout and the one after is claimed with a real
 * answer.
 *
 * ── AND WHY THIS MIGRATION RE-ASSERTS AN INDEX IT DID NOT CREATE ──────────
 *
 * FOUND BY READING IT BACK, NOT BY REASONING ABOUT IT. Adding a column that
 * carries a FOREIGN KEY makes SQLite rebuild the whole table, and Laravel
 * re-creates that table's indexes from its own introspection — which does not
 * carry a partial index's WHERE clause. The measured effect on the local SQLite
 * database was that
 *
 *     CREATE UNIQUE INDEX ... (user_id) WHERE released_at IS NULL
 *
 * from 2026_09_10_100001 came back as a PLAIN unique index on `user_id`. That is
 * not cosmetic: it turns 'at most one UNRELEASED lease per user' into 'at most
 * one lease row per user, ever', so the second login a doctor makes after any
 * logout raises a unique violation. The whole of PR-A's engine would break — and
 * only on SQLite, which is to say only in the test suite, so a green PostgreSQL
 * gate would have said nothing about it.
 *
 * PostgreSQL is unaffected: ADD COLUMN there never rebuilds a table. The
 * re-assert below therefore exists for the engine the suite runs on, and on
 * PostgreSQL it is a drop and re-create of an identical definition, which cannot
 * fail because the rows already satisfy it.
 *
 * It is re-asserted in `down()` too, for the same reason: dropping these two
 * columns rebuilds the table a second time and would flatten the predicate
 * again. The precedent is the sibling partial index that had to be re-asserted
 * after the same kind of rebuild in 2026_07_19_100010, where the defect stayed
 * latent for a sprint because nothing exercised a second row.
 */
return new class extends Migration
{
    private const ACTIVE_LEASE_INDEX = 'trx_doctor_session_leases_active_uq';

    public function up(): void
    {
        Schema::table('trx_doctor_session_leases', function (Blueprint $table): void {
            if (! Schema::hasColumn('trx_doctor_session_leases', 'effective_branch_id')) {
                // EFFECTIVE_CLINICAL_BRANCH at claim time. NULL means UNSET,
                // which is a legitimate, matchable state.
                $table->foreignId('effective_branch_id')
                    ->nullable()
                    ->after('session_token_hash')
                    ->constrained('mst_branches')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
            }

            if (! Schema::hasColumn('trx_doctor_session_leases', 'effective_cover_id')) {
                // WHICH authority produced that branch. Not decoration: without
                // it a cover that expires onto the same branch as home, or one
                // cover replacing another at the same target, would compare
                // equal and the session would survive — while section Q says
                // expiry MUST invalidate. nullOnDelete rather than restrict so a
                // vanished cover degrades to a mismatch (evict) instead of an FK
                // error.
                $table->foreignId('effective_cover_id')
                    ->nullable()
                    ->after('effective_branch_id')
                    ->constrained('trx_doctor_branch_covers')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            }
        });

        // Declared explicitly because `constrained()` creates the supporting
        // index only on some drivers, and PostgreSQL is not one of them.
        Schema::table('trx_doctor_session_leases', function (Blueprint $table): void {
            $table->index('effective_branch_id');
        });

        $this->reassertActiveLeaseIndex();
    }

    public function down(): void
    {
        Schema::table('trx_doctor_session_leases', function (Blueprint $table): void {
            $table->dropIndex(['effective_branch_id']);
        });

        Schema::table('trx_doctor_session_leases', function (Blueprint $table): void {
            if (Schema::hasColumn('trx_doctor_session_leases', 'effective_cover_id')) {
                $table->dropConstrainedForeignId('effective_cover_id');
            }

            if (Schema::hasColumn('trx_doctor_session_leases', 'effective_branch_id')) {
                $table->dropConstrainedForeignId('effective_branch_id');
            }
        });

        $this->reassertActiveLeaseIndex();
    }

    /**
     * Put PR-A's cardinality invariant back the way it was declared.
     *
     * THE PREDICATE IS THE INVARIANT. Without `WHERE released_at IS NULL` this
     * index says a user may own one lease ROW rather than one LIVE lease, which
     * is a different and much stricter rule that PR-A's own history of released
     * leases immediately violates.
     *
     * Idempotent by construction, and it must be: this runs on both sides of the
     * migration, and on a driver that never lost the predicate it is a
     * no-difference rewrite.
     */
    private function reassertActiveLeaseIndex(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::ACTIVE_LEASE_INDEX);

        DB::statement(
            'CREATE UNIQUE INDEX '.self::ACTIVE_LEASE_INDEX.' '
            .'ON trx_doctor_session_leases (user_id) WHERE released_at IS NULL'
        );
    }
};
