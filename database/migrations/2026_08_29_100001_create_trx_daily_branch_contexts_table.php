<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — the durable daily working-branch
 * authority for the branch-locked roles (Kasir, Admin Klinik).
 *
 * WHY A TABLE AND NOT THE EXISTING CONTEXT ROW.
 *
 * `trx_user_online_contexts` holds exactly ONE row per user and is rewritten by
 * every session start, so it cannot express "which branch did this user commit
 * to for THIS clinical day". It is a session representation. Overloading it
 * would make the lock evaporate the moment the operator went offline and back
 * on — which is precisely the bypass this feature exists to close.
 *
 * THE UNIQUENESS KEY IS (user_id, clinical_date) AND NOTHING ELSE.
 *
 * Deliberately NOT keyed on role_context. A user who holds both `Kasir` and
 * `Admin Klinik` would otherwise be able to open an admin-clinic context at one
 * branch and a cashier context at another on the same day, and neither row
 * would collide. One human, one clinical day, one branch. `role_context`
 * records which constrained role established the day, for audit only.
 *
 * `clinical_date` is a CALENDAR DATE on the clinic's own wall clock
 * (ClinicalClock / Asia/Makassar), never a UTC-derived one. Deriving it from a
 * UTC instant would move the lock boundary eight hours away from the day the
 * clinic is actually living through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_daily_branch_contexts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->date('clinical_date');

            // Which constrained role opened the day. Audit metadata, never part
            // of the identity of the lock.
            $table->string('role_context', 32);

            // The branch freely chosen once, kept for the audit trail even after
            // an approved switch moves `current_branch_id` away from it.
            $table->foreignId('initial_branch_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // THE AUTHORITY. Every branch-scoped read and every payment mutation
            // for a locked role resolves to this value for the rest of the day.
            $table->foreignId('current_branch_id')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamp('first_selected_at');
            $table->timestamp('last_changed_at')->nullable();

            // How many APPROVED switches have been applied today. Each one needed
            // its own approval; this is the count of them, not a budget.
            $table->unsignedSmallInteger('change_count')->default(0);

            $table->timestamps();

            // The concurrency invariant. Two sessions racing to make the first
            // selection cannot both win: the loser's INSERT is refused by the
            // database, not by an application check that can be interleaved.
            $table->unique(['user_id', 'clinical_date'], 'trx_daily_branch_ctx_user_date_uq');

            $table->index('clinical_date');
            $table->index('current_branch_id');
            $table->index(['clinical_date', 'current_branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_daily_branch_contexts');
    }
};
