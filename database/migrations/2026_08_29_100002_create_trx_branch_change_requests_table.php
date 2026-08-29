<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — the Super Admin approval record for a
 * mid-day working-branch change.
 *
 * AN APPROVAL IS NOT A TOKEN.
 *
 * The switch is applied INSIDE the approval transaction, so there is never a
 * reusable "approved" credential sitting around for the requester to replay.
 * Once the transaction commits the row is APPROVED and `applied_at` is set; a
 * second attempt finds a row that is no longer PENDING and is refused. Every
 * binding the security contract asks for — user, source, destination, clinical
 * day — is a column on this row that the approval re-asserts under a lock, not
 * a value the client may supply.
 *
 * ONE PENDING REQUEST PER USER PER CLINICAL DAY.
 *
 * Enforced by a partial unique index rather than an application check, because
 * an application check is raceable by exactly the double-submit it is meant to
 * stop. PostgreSQL and SQLite both support partial indexes, so the invariant is
 * identical on the production driver and in the test suite. The predicate is
 * `status = 'pending'`: decided rows are unconstrained and accumulate as the
 * audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_branch_change_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('requester_user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // The clinical day the request belongs to. A request never authorises
            // a switch on any other day.
            $table->date('clinical_date');

            $table->string('role_context', 32);

            // Both branches are bound on the row. The approval re-asserts that
            // the live daily context still sits on `source_branch_id`, so a
            // request whose context has moved underneath it is refused as stale
            // rather than silently applied against a different starting point.
            $table->foreignId('source_branch_id')
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

            // Set in the same transaction that moves the daily context. Its
            // presence is the proof the approval was consumed.
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('clinical_date');
            $table->index('requester_user_id');
            $table->index(['status', 'clinical_date']);
            $table->index('decided_by_user_id');
        });

        // At most one PENDING request per requester per clinical day.
        DB::statement(
            'CREATE UNIQUE INDEX trx_branch_change_req_pending_uq '
            ."ON trx_branch_change_requests (requester_user_id, clinical_date) WHERE status = 'pending'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_branch_change_requests');
    }
};
