<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAB-PROD-3 — Lab service workload profile (effective-dated).
 *
 * Declares the planned workload (in an explicit planning_unit) required to
 * produce one order-item of a given lab service. Effective-dated. Additive
 * only. A service with no active workload profile makes its demand
 * UNPLANNABLE (surfaced in data coverage), never a fake zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_lab_service_workload_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_service_id')
                ->constrained('mst_lab_services')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('planning_unit', 20)->default('minutes'); // minutes | units
            $table->decimal('planned_workload', 10, 2); // per order-item, in planning_unit
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
            $table->index(['lab_service_id', 'is_active']);
            $table->index(['lab_service_id', 'effective_from', 'effective_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_lab_service_workload_profiles');
    }
};
