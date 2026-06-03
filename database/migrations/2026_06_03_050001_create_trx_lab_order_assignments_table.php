<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 — trx_lab_order_assignments (technician assignment history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_lab_order_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')->constrained('trx_lab_orders')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('mst_technicians')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 50)->default('ASSIGNED');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('lab_order_id');
            $table->index('technician_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_lab_order_assignments');
    }
};
