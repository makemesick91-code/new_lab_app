<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QUEUE-1 — Outbox foundation (additive only).
 *
 * Governance-only foundation: no domain events are created in this sprint
 * and dispatch_enabled/external_dispatch_enabled stay false in
 * config/queue_governance.php. Payload must only ever contain safe,
 * non-PII, non-secret data — enforced by App\Services\Foundation\OutboxService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sys_outbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid');
            $table->string('aggregate_type', 128)->nullable();
            $table->string('aggregate_id', 64)->nullable();
            $table->string('event_type', 128);
            $table->string('event_version', 16)->default('v1');
            $table->string('status', 16)->default('pending');
            $table->json('payload');
            $table->string('payload_classification', 64);
            $table->string('idempotency_key_hash', 64)->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_summary')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique('event_uuid');
            $table->index(['status', 'available_at']);
            $table->index('event_type');
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index('idempotency_key_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_outbox_events');
    }
};
