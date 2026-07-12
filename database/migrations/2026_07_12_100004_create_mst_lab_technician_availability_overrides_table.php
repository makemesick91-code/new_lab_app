<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAB-PROD-3 — Technician date-specific availability override.
 *
 * Reduces or overrides a technician's capacity on a specific date (leave,
 * training, half-day, etc). reason_category is a generic OPERATIONAL category
 * only — never a diagnosis or health information. If capacity_override is set
 * it wins (absolute value for that day); otherwise capacity_reduction is
 * subtracted from the profile's daily capacity. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_lab_technician_availability_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')
                ->constrained('mst_technicians')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('override_date');
            $table->decimal('capacity_override', 10, 2)->nullable(); // absolute capacity for the day (wins)
            $table->decimal('capacity_reduction', 10, 2)->nullable(); // amount subtracted from daily capacity
            $table->string('reason_category', 40)->default('unavailable'); // generic operational category
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')
                ->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['technician_id', 'override_date']); // one override per technician per day
            $table->index('override_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_lab_technician_availability_overrides');
    }
};
