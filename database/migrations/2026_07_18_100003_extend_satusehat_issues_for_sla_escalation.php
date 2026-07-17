<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4C — issue SLA, priority, escalation, and review columns.
 *
 * Additive to the SATUSEHAT-4A data-quality issue table. Nullable/default-safe:
 * legacy rows keep NULLs and behave exactly as before. `owner_role` (the rule's
 * default owner, S4A) is preserved; `assigned_role` is the role an issue is
 * explicitly routed to at assignment. No PII added.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('trx_satusehat_data_quality_issues')) {
            return;
        }

        Schema::table('trx_satusehat_data_quality_issues', function (Blueprint $table) {
            if (! Schema::hasColumn('trx_satusehat_data_quality_issues', 'priority')) {
                $table->string('priority', 20)->nullable()->index();
            }
            if (! Schema::hasColumn('trx_satusehat_data_quality_issues', 'assigned_role')) {
                $table->string('assigned_role', 60)->nullable();
            }
            if (! Schema::hasColumn('trx_satusehat_data_quality_issues', 'due_at')) {
                $table->timestamp('due_at')->nullable()->index();
            }
            if (! Schema::hasColumn('trx_satusehat_data_quality_issues', 'escalation_level')) {
                $table->string('escalation_level', 40)->nullable()->default('none');
            }
            if (! Schema::hasColumn('trx_satusehat_data_quality_issues', 'escalated_at')) {
                $table->timestamp('escalated_at')->nullable();
            }
            if (! Schema::hasColumn('trx_satusehat_data_quality_issues', 'resolution_evidence')) {
                $table->string('resolution_evidence', 500)->nullable();
            }
            if (! Schema::hasColumn('trx_satusehat_data_quality_issues', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('trx_satusehat_data_quality_issues', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('trx_satusehat_data_quality_issues')) {
            return;
        }

        Schema::table('trx_satusehat_data_quality_issues', function (Blueprint $table) {
            foreach (['reviewed_by'] as $fk) {
                if (Schema::hasColumn('trx_satusehat_data_quality_issues', $fk)) {
                    $table->dropConstrainedForeignId($fk);
                }
            }
            foreach (['priority', 'assigned_role', 'due_at', 'escalation_level', 'escalated_at', 'resolution_evidence', 'reviewed_at'] as $col) {
                if (Schema::hasColumn('trx_satusehat_data_quality_issues', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
