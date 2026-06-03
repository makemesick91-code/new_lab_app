<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3 — sys_audit_logs (polymorphic, immutable audit trail).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sys_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 150)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('action', 100);
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('performed_at');
            $table->string('ip_address', 100)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('entity_type');
            $table->index('entity_id');
            $table->index('performed_by');
            $table->index('performed_at');
            $table->index('action');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_audit_logs');
    }
};
