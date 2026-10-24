<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| HARDENING-RME-BILLING-DISPUTE-BRANCH-REVIEWER-CONTROL-PAYMENT-1 — owner decision
| OPS-OQ-1a (rule 204): an EXPLICITLY designated branch responsible person may
| review and resolve that branch's billing disputes.
|
| WHY A TABLE AND NOT A ROLE. Front Office is the exact union of Admin Klinik and
| Kasir (RoleSeeder / FrontOfficeRoleTest). Granting dispute review to a role would
| reach every front-desk account at once and let peers clear each other's
| disputes. A designation names ONE account for ONE branch.
|
| A DESIGNATION IS NOT A PERMISSION. It grants no Spatie permission and therefore
| no correction, price, refund, cancellation or payment power. It authorizes only
| reviewing, escalating, annotating and resolving that branch's disputes, and only
| while the account is usable and its live working scope reaches the branch.
|
| IMMUTABLE HISTORY. A row is written once at grant time and can change exactly
| once: its revocation (who, when, why). It is never deleted. Re-designating after
| a revocation inserts a NEW row, so the history is a list of grant/revoke pairs
| and never a row that was rewritten.
|
| ONE ACTIVE DESIGNATION per (account, branch) is a database invariant: the
| partial unique index below, not only a service check.
|
| Nothing is backfilled: no account is designated by this migration.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_rme_billing_dispute_reviewers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('mst_branches')->restrictOnDelete();

            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('granted_at');
            $table->text('grant_reason');

            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();

            $table->timestamps();

            // The hot path: "is this account designated for this branch right now?"
            $table->index(['user_id', 'branch_id', 'revoked_at'], 'mst_rme_bd_reviewers_active_idx');
            $table->index(['branch_id', 'revoked_at'], 'mst_rme_bd_reviewers_branch_idx');
        });

        // At most one ACTIVE designation per (account, branch).
        DB::statement(
            'CREATE UNIQUE INDEX mst_rme_bd_reviewers_one_active_uq '
            .'ON mst_rme_billing_dispute_reviewers (user_id, branch_id) WHERE revoked_at IS NULL'
        );

        // A revocation always carries who, when and why; an active row carries none
        // of it. PostgreSQL only — SQLite cannot add a constraint to an existing
        // table, and the service and model enforce the same rule on every engine.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE mst_rme_billing_dispute_reviewers ADD CONSTRAINT mst_rme_bd_reviewers_revocation_chk CHECK ((revoked_at IS NULL AND revoked_by IS NULL AND revoke_reason IS NULL) OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND revoke_reason IS NOT NULL))');
            DB::statement('ALTER TABLE mst_rme_billing_dispute_reviewers ADD CONSTRAINT mst_rme_bd_reviewers_not_self_chk CHECK (user_id <> granted_by)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_rme_billing_dispute_reviewers');
    }
};
