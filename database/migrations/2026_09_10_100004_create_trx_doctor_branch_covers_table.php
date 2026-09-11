<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — TEMPORARY_BRANCH_COVER.
 *
 * Approved, time-boxed operational authority to work somewhere other than the
 * doctor's HOME_LOCKED_BRANCH. It NEVER changes the home lock; when it stops
 * being current the effective branch simply resolves back to home.
 *
 * ACTIVE AND EXPIRED ARE NEVER WRITTEN HERE.
 *
 * `status` holds only what a human decided: pending, approved, rejected,
 * cancelled. Whether an approved cover is SCHEDULED, ACTIVE or EXPIRED is
 * derived on every read from `starts_at`, `ends_at` and the current instant.
 * Owner decision O6/Q: 'Do not persist redundant state that a delayed job
 * would have to maintain.' A column a cron has to flip is a security boundary
 * that fails open the moment the cron is late — the same reasoning
 * BranchChangeRequest::isStaleForClinicalDay() records at
 * app/Modules/RmeOnlineContext/Models/BranchChangeRequest.php:104-114,
 * 'Security correctness must never depend on a cron.'
 *
 * THE PERIOD IS AN INSTANT PAIR, NOT A CLINICAL DATE.
 *
 * Section Q corrected section O: a cover carries explicit `starts_at` and
 * `ends_at`, not an end-of-clinical-day. They are stored as ordinary instants
 * in the application's existing timestamp architecture. ClinicalClock
 * (app/Support/Clinical/ClinicalClock.php:12-48) belongs on the way IN — the
 * operator types a WITA wall-clock time and the request layer converts — and
 * on the way OUT for display. It must NOT be used to shift a stored instant;
 * that class exists precisely to say a stored value is never re-zoned.
 *
 * The interval is HALF-OPEN, [starts_at, ends_at). Two covers that meet at a
 * boundary do not overlap, so a legitimate back-to-back handover is allowed.
 *
 * NON-OVERLAP IS NOT AN INDEX, AND CANNOT BE.
 *
 * Overlap is a range predicate; a unique index compares values. PostgreSQL
 * could express it with an EXCLUDE ... USING gist constraint, but that needs
 * the btree_gist extension and has no SQLite equivalent at all, so the
 * invariant would exist only in production and never in the suite that is
 * supposed to prove it. Instead the cover services serialise on the doctor's
 * single mst_doctor_branch_locks row with lockForUpdate and evaluate the
 * overlap there — see that migration's docblock for why the mutex must be a
 * row that is certain to exist.
 *
 * The two partial indexes below are what the database CAN hold: one PENDING
 * cover per doctor, and a duplicate-period backstop. The second is a backstop,
 * not the overlap rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_doctor_branch_covers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('doctor_id')
                ->constrained('mst_doctors')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Provenance only. A doctor may NEVER self-create or self-approve a
            // cover (owner decision O6/Q); the approver check is the boundary.
            $table->foreignId('requester_user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // The home lock as it stood when the cover was requested. The
            // approval re-asserts the live lock still equals it, so a cover
            // whose home moved underneath it (an approved transfer landed
            // first) is refused as stale instead of silently granted against a
            // branch the approver never saw — the guard shape at
            // app/Modules/RmeOnlineContext/Services/BranchChangeApprovalService.php:166-170.
            $table->foreignId('source_home_branch_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Where the doctor is covering. EFFECTIVE_CLINICAL_BRANCH while the
            // cover is current.
            $table->foreignId('target_branch_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Half-open [starts_at, ends_at). Instants, never re-zoned.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            $table->text('reason');
            $table->timestamp('requested_at');

            // Decided state ONLY: pending | approved | rejected | cancelled.
            $table->string('status', 16)->default('pending');

            $table->foreignId('decided_by_user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            // Cancellation is a SECOND event that can follow an approval, so it
            // needs its own stamps rather than overwriting the decision — the
            // per-outcome stamp convention of mst_doctor_device_authorizations
            // (2026_09_03_110001:63-72). A cancelled cover stops being current
            // immediately; it is not an expiry and it does not rewrite history.
            $table->foreignId('cancelled_by_user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_reason', 500)->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('doctor_id');

            // The hot path. On every protected request the middleware asks
            // 'is there an approved cover for this doctor that has not ended?'
            $table->index(
                ['doctor_id', 'status', 'ends_at'],
                'trx_doctor_branch_covers_doctor_status_ends_index'
            );

            $table->index(
                ['status', 'starts_at'],
                'trx_doctor_branch_covers_status_starts_index'
            );

            $table->index('target_branch_id');
            $table->index('decided_by_user_id');
        });

        // At most one PENDING cover request per doctor. A true invariant, and
        // the only one of the two that is.
        DB::statement(
            'CREATE UNIQUE INDEX trx_doctor_branch_covers_pending_uq '
            ."ON trx_doctor_branch_covers (doctor_id) WHERE status = 'pending'"
        );

        // BACKSTOP, NOT THE OVERLAP RULE. It refuses two approved covers for
        // the same doctor over the IDENTICAL period, which is the double-submit
        // race, and it costs nothing. It does NOT and cannot refuse a partial
        // overlap; that is enforced under the mst_doctor_branch_locks row lock.
        DB::statement(
            'CREATE UNIQUE INDEX trx_doctor_branch_covers_period_uq '
            .'ON trx_doctor_branch_covers (doctor_id, starts_at, ends_at) '
            ."WHERE status = 'approved'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_doctor_branch_covers');
    }
};
