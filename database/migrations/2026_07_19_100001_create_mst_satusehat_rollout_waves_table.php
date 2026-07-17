<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4D — controlled multi-branch rollout waves.
 *
 * A wave groups RME-enabled branches for a staged internal-readiness rollout.
 * No wave is active by default (status starts 'draft'). A wave never enables
 * SATUSEHAT external send or production; it only governs INTERNAL readiness
 * operations. MAIN is never enrollable (enforced in the service layer).
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mst_satusehat_rollout_waves')) {
            return;
        }

        Schema::create('mst_satusehat_rollout_waves', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 20)->default('sandbox');
            $table->string('name', 150);
            $table->unsignedInteger('sequence')->default(1);

            // draft | profiling | approved | in_remediation | uat_scheduled |
            // uat_in_progress | rehearsal_ready | pilot_ready_internal |
            // blocked_external_credential | suspended | closed
            $table->string('status', 40)->default('draft');
            $table->string('scope', 500)->nullable();
            $table->unsignedInteger('threshold_version')->nullable();

            $table->foreignId('operational_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('clinical_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('technical_owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->date('target_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['environment', 'name'], 'mst_ss_wave_env_name_uq');
            $table->index(['environment', 'status'], 'mst_ss_wave_env_status_idx');
            $table->index(['environment', 'sequence'], 'mst_ss_wave_env_seq_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_satusehat_rollout_waves');
    }
};
