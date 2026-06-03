<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 — trx_lab_work_logs (immutable production work events).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_lab_work_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('trx_lab_order_assignments')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('event_type', 50);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('duration_minutes')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('assignment_id');
            $table->index('event_type');
            $table->index('performed_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_lab_work_logs');
    }
};
