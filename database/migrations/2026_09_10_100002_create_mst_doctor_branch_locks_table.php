<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — HOME_LOCKED_BRANCH.
 *
 * THE ABSENCE OF A ROW IS THE UNSET STATE.
 *
 * Owner decision O1: every doctor starts UNSET and nothing is ever inferred
 * from a doctor code, a device placement, a last login, a room or history.
 * If UNSET were expressed as a row carrying a NULL branch, creating those rows
 * would itself be the backfill O1 forbids. So `home_branch_id` is NOT NULL and
 * a row exists only once an approved workflow put a branch there. A doctor with
 * no row keeps the pre-sprint branch-selection behaviour exactly.
 *
 * ONE HOME LOCK PER DOCTOR, AND THAT ROW IS ALSO THE MUTEX.
 *
 * `UNIQUE(doctor_id)` is an ordinary unique index, not a partial one: unlike a
 * pending request there is no lifecycle predicate to exclude — one doctor has
 * one home, forever, and a transfer MOVES it rather than adding a row.
 *
 * That guaranteed-single row is load-bearing a second time. A temporary cover
 * must not overlap another cover, and overlap is a RANGE predicate that no
 * partial unique index on either engine can express. The cover services
 * therefore serialise on THIS row with `lockForUpdate`. A `FOR UPDATE` guard
 * over a zero-row predicate locks no gap in PostgreSQL — the point
 * database/migrations/2026_07_19_100003:10-12 records — so the mutex has to be
 * a row that is certain to exist. This one is, and a cover is refused outright
 * for a doctor who has no home lock, which is coherent anyway: covering
 * somewhere else is meaningless without a home to come back to.
 *
 * KEYED ON `mst_doctors.id`, NOT `users.id`.
 *
 * The clinical authority belongs to the doctor master record. `mst_doctors.user_id`
 * is nullable and UNIQUE (database/migrations/2026_06_29_120002:15-22), so a
 * Doctor-role account with no link binds nobody and must be refused by the
 * request and approve paths rather than silently locked (red-team ruling P6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_doctor_branch_locks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('doctor_id')
                ->constrained('mst_doctors')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // THE AUTHORITY. Every operational list and every branch-scoped
            // write for a LOCKED doctor resolves through this value unless an
            // approved cover is currently in force.
            $table->foreignId('home_branch_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // The home this row held immediately before the last approved
            // transfer, kept for the audit trail after the move — the same
            // reason `initial_branch_id` survives a switch on
            // trx_daily_branch_contexts (2026_08_29_100001:50-55).
            $table->foreignId('previous_branch_id')
                ->nullable()
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Which of the two O1 workflows put the current value here.
            // Audit metadata; never an input to the resolver.
            $table->string('established_via', 24)->default('initial_assignment');

            $table->timestamp('established_at');

            $table->foreignId('established_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('last_transferred_at')->nullable();

            // How many APPROVED transfers have landed here. Each needed its own
            // approval; this is the count of them, not a budget — the same
            // reading as `change_count` at 2026_08_29_100001:67-69.
            $table->unsignedSmallInteger('transfer_count')->default(0);

            $table->timestamps();

            $table->unique('doctor_id', 'mst_doctor_branch_locks_doctor_uq');
            $table->index('home_branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_doctor_branch_locks');
    }
};
