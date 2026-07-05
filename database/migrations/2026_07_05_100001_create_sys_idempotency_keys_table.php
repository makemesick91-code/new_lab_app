<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QUEUE-1 — Idempotency foundation (additive only).
 *
 * Stores only a SHA-256 hash of the caller-supplied idempotency key —
 * never the raw key. No PII/business payload is stored, only a safe
 * JSON metadata blob and fingerprint hashes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sys_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key_hash', 64);
            $table->string('scope', 64);
            $table->string('owner_type', 64)->nullable();
            $table->string('owner_id', 64)->nullable();
            $table->string('status', 16)->default('reserved');
            $table->string('request_fingerprint_hash', 64)->nullable();
            $table->string('response_fingerprint_hash', 64)->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['key_hash', 'scope']);
            $table->index('status');
            $table->index('expires_at');
            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_idempotency_keys');
    }
};
