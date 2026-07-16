<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4A — Deterministic data-quality issues.
 *
 * One row per (candidate, rule, entity, field) fingerprint. Issues are
 * generated idempotently by the rule engine (re-scan updates last_detected_at,
 * never duplicates), auto-resolve when the underlying defect is fixed, and
 * carry a full remediation lifecycle. No PII: messages are structured and the
 * row stores foreign keys, never NIK/free-text clinical content.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trx_satusehat_data_quality_issues')) {
            return;
        }

        Schema::create('trx_satusehat_data_quality_issues', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 20)->default('sandbox');
            $table->foreignId('branch_id')->constrained('mst_branches')->restrictOnDelete();
            $table->foreignId('satusehat_candidate_id')->constrained('trx_satusehat_candidates')->cascadeOnDelete();
            $table->foreignId('clinic_visit_id')->nullable()->constrained('trx_clinic_visits')->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained('mst_patients')->nullOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('mst_doctors')->nullOnDelete();
            $table->string('rule_code', 60);
            // hard|soft|info — hard issues can never be waived or falsely resolved.
            $table->string('severity', 10)->index();
            $table->string('status', 30)->default('open');
            // sha256(environment|candidate|rule|entity_type|entity_id|field) —
            // the deterministic idempotency key for the rule engine.
            $table->string('fingerprint', 64)->unique();
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('field_path', 120)->nullable();
            $table->string('message', 500);
            $table->string('remediation_action', 500)->nullable();
            $table->string('owner_role', 60)->nullable()->index();
            $table->string('source_hash', 64)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('first_detected_at')->nullable();
            $table->timestamp('last_detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            // revalidated|manual|superseded
            $table->string('resolution_type', 40)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('waived_at')->nullable();
            $table->string('waiver_reason', 500)->nullable();
            $table->timestamp('waiver_expires_at')->nullable();
            // Sanitized structured metadata only (scalar reason codes etc.).
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status'], 'trx_ss_dq_branch_status_idx');
            $table->index(['rule_code', 'status'], 'trx_ss_dq_rule_status_idx');
            $table->index(['satusehat_candidate_id', 'status'], 'trx_ss_dq_candidate_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_satusehat_data_quality_issues');
    }
};
