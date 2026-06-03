<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 — trx_lab_production_steps (operational production progress).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_lab_production_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')->constrained('trx_lab_orders')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('step_name', 100);
            $table->string('status', 50)->default('PENDING');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('lab_order_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_lab_production_steps');
    }
};
