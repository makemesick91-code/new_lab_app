<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3 — trx_lab_order_status_logs (immutable status timeline).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_lab_order_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')->constrained('trx_lab_orders')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('old_status', 50)->nullable();
            $table->string('new_status', 50);
            $table->text('notes')->nullable();
            $table->foreignId('changed_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->timestamp('created_at')->nullable();

            $table->index('lab_order_id');
            $table->index('changed_by');
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_lab_order_status_logs');
    }
};
