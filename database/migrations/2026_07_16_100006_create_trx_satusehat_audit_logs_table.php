<?php

// SATUSEHAT-1 — Append-only SATUSEHAT audit trail. Dedicated table (separate
// from sys_audit_logs) capturing candidate/readiness/preview/approval/queue and
// mapping/identifier governance events. No updated_at, no soft delete, and no
// normal UI action ever updates or deletes a row — append only. Context is a
// sanitized, PII-free json (never NIK/token/raw payload). Additive only.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_satusehat_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->string('environment', 20)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('mst_branches')->cascadeOnUpdate()->nullOnDelete();

            $table->string('entity_type', 80);                 // satusehat_candidate | code_mapping | entity_identifier | submission_batch
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('event', 80);                       // candidate_created | readiness_calculated | approved | excluded | ...

            $table->foreignId('actor_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->string('actor_role', 80)->nullable();

            $table->string('summary', 191)->nullable();
            // Sanitized, PII-free structured context (reason codes, ids, hashes).
            $table->json('context')->nullable();

            $table->string('ip_address', 100)->nullable();
            $table->text('user_agent')->nullable();

            // Append-only: created_at only, no updated_at.
            $table->timestamp('created_at')->nullable();

            $table->index(['entity_type', 'entity_id']);
            $table->index('event');
            $table->index('actor_id');
            $table->index('branch_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_satusehat_audit_logs');
    }
};
