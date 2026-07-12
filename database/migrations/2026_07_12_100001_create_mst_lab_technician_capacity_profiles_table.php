<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAB-PROD-3 — Technician daily capacity profile (effective-dated).
 *
 * Configuration/master data for capacity planning. Declares how much work a
 * technician can absorb per working day, in an explicit planning_unit
 * ('minutes' or 'units'). Effective-dated so historical planning stays stable.
 * Additive only — no existing table touched. Technicians are lab-wide (no
 * branch column on mst_technicians), so capacity is lab-wide too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_lab_technician_capacity_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')
                ->constrained('mst_technicians')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('planning_unit', 20)->default('minutes'); // minutes | units
            $table->decimal('daily_capacity', 10, 2); // per working day, in planning_unit
            $table->json('working_days')->nullable(); // ISO-8601 weekday ints [1..7]; null => config default
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index('is_active');
            $table->index(['technician_id', 'is_active']);
            $table->index(['technician_id', 'effective_from', 'effective_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_lab_technician_capacity_profiles');
    }
};
