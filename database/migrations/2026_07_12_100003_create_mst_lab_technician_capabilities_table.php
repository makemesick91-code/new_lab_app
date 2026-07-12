<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAB-PROD-3 — Technician service capability (effective-dated).
 *
 * Maps which lab services a technician is eligible to produce. Used to filter
 * recommendation candidates (capability match). Effective-dated. Additive
 * only. Absence of any capability row for a technician means "no declared
 * capability" (recommendation excludes for unsupported services), never an
 * implicit all-services grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_lab_technician_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')
                ->constrained('mst_technicians')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('lab_service_id')
                ->constrained('mst_lab_services')->cascadeOnUpdate()->restrictOnDelete();
            $table->boolean('is_eligible')->default(true);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->foreignId('created_by')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();

            $table->index(['technician_id', 'is_eligible']);
            $table->index(['lab_service_id', 'is_eligible']);
            $table->index(['technician_id', 'lab_service_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_lab_technician_capabilities');
    }
};
