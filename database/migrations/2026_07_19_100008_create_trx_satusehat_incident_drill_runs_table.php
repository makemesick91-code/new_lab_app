<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4D — hermetic incident-drill run log.
 *
 * Records the outcome of hermetic (no-network) operational incident drills:
 * trigger, expected vs actual state, diagnostic command, rollback, and evidence
 * reference. Read/observe only — the drills never touch production or send
 * external traffic. Scalar/PII-free.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trx_satusehat_incident_drill_runs')) {
            return;
        }

        Schema::create('trx_satusehat_incident_drill_runs', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 20)->default('sandbox');
            $table->string('drill_code', 60);
            $table->string('title', 200);
            $table->string('trigger', 500);
            $table->string('expected_state', 300);
            $table->string('actual_state', 300)->nullable();
            // pass | fail | pending
            $table->string('outcome', 20)->default('pending');
            $table->string('diagnostic_command', 300)->nullable();
            $table->string('escalation_owner', 120)->nullable();
            $table->string('rollback', 500)->nullable();
            $table->string('evidence_reference', 500)->nullable();

            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['environment', 'drill_code'], 'trx_ss_drill_env_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_satusehat_incident_drill_runs');
    }
};
