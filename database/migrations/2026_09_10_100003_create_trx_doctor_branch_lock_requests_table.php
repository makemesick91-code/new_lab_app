<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the approval record for setting
 * or moving a doctor's HOME_LOCKED_BRANCH.
 *
 * ONE TABLE, TWO WORKFLOWS.
 *
 * Owner decision O1 names two: INITIAL BRANCH ASSIGNMENT (UNSET -> branch) and
 * BRANCH TRANSFER (branch A -> branch B). They differ in exactly one column —
 * an assignment has no `source_branch_id` — and share their status vocabulary,
 * their approver set, their single-pending invariant and their apply-inside-
 * the-approval semantics. `request_type` records which one this is; nothing
 * else about them diverges, so nothing else is duplicated.
 *
 * A TEMPORARY COVER IS NOT ONE OF THEM and lives in its own table. It has
 * different columns (a period), a different invariant (non-overlap, which no
 * partial unique index can express) and a status vocabulary whose ACTIVE and
 * EXPIRED members are DERIVED and never written. Section Q is explicit that
 * the two concepts must never be conflated; a shared table with a mode column
 * would conflate them in the one place it matters, the schema.
 *
 * AN APPROVAL IS NOT A TOKEN.
 *
 * The move is applied INSIDE the approval transaction, so no reusable
 * 'approved' credential is ever left lying around — the property
 * trx_branch_change_requests states for itself at 2026_08_29_100002:14-21.
 * `applied_at` is the proof the approval was consumed.
 *
 * ONE PENDING REQUEST PER DOCTOR.
 *
 * Enforced by a partial unique index rather than an application check, because
 * an application check is raceable by exactly the double-submit it is meant to
 * stop. PostgreSQL and SQLite both support partial indexes — proved on both in
 * this sprint's probes — so the invariant is identical on the production
 * driver and in the test suite. The predicate is `status = 'pending'`: decided
 * rows are unconstrained and accumulate as the audit trail.
 *
 * NO DRIVER GUARD ON THAT INDEX, ON PURPOSE. The sibling at
 * database/migrations/2026_07_19_100003:26-39 wraps its CREATE INDEX in
 * `Schema::hasTable`, a driver allow-list and `IF NOT EXISTS`, and degrades to
 * 'the service guard is the fallback'. Here the index IS the invariant, it is
 * created in the SAME migration as the table so nothing can rebuild the table
 * out from under it, and a driver that cannot enforce it must fail the
 * migration loudly rather than deploy a lock that silently is not one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_doctor_branch_lock_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('doctor_id')
                ->constrained('mst_doctors')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // WHO ASKED. Never an authority: nothing a doctor sends approves
            // their own move — the reading `requested_by` already carries at
            // 2026_09_03_110001:59-61.
            $table->foreignId('requester_user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // 'initial_assignment' | 'transfer'.
            $table->string('request_type', 24);

            // NULL for an initial assignment: there is no source. For a
            // transfer the approval re-asserts that the live lock still sits
            // here, so a request whose lock moved underneath it is refused as
            // stale rather than silently applied against a different starting
            // point (2026_08_29_100002:49-52).
            $table->foreignId('source_branch_id')
                ->nullable()
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('destination_branch_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->text('reason');
            $table->timestamp('requested_at');

            $table->string('status', 16)->default('pending');

            $table->foreignId('decided_by_user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            // Set in the same transaction that moves the lock AND releases the
            // doctor's live session lease. Its presence is the proof the
            // approval was consumed.
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('doctor_id');
            $table->index(
                ['status', 'requested_at'],
                'trx_doctor_branch_lock_req_status_requested_index'
            );
            $table->index('decided_by_user_id');
            $table->index('destination_branch_id');
        });

        // At most one PENDING request per doctor.
        DB::statement(
            'CREATE UNIQUE INDEX trx_doctor_branch_lock_req_pending_uq '
            ."ON trx_doctor_branch_lock_requests (doctor_id) WHERE status = 'pending'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_doctor_branch_lock_requests');
    }
};
