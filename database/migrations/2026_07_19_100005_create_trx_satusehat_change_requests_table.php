<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4D — governance change-control requests.
 *
 * Records controlled changes to readiness thresholds, scoring weights, wave
 * membership, pilot status, branch suspension, terminology activation, rollout
 * modes, and the production guard. No change request may enable production or
 * external send during SATUSEHAT-4D. Payload is scalar/PII-free.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trx_satusehat_change_requests')) {
            return;
        }

        Schema::create('trx_satusehat_change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 20)->default('sandbox');
            $table->string('category', 60);
            $table->string('reason', 1000);
            $table->string('scope', 500);
            $table->string('risk', 500)->nullable();

            // pending | reviewed | approved | rejected | applied | rolled_back
            $table->string('status', 30)->default('pending');

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->date('effective_date')->nullable();
            $table->string('rollback_plan', 1000)->nullable();
            $table->string('audit_reference', 120)->nullable();
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['environment', 'status'], 'trx_ss_chg_env_status_idx');
            $table->index(['environment', 'category'], 'trx_ss_chg_env_category_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_satusehat_change_requests');
    }
};
