<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-0203 — mst_patients. Patient belongsTo Clinic and Doctor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('mst_clinics')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('mst_doctors')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('medical_record_number', 50)->nullable()->unique();
            $table->string('name', 150);
            $table->string('gender', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('clinic_id');
            $table->index('doctor_id');
            $table->index('name');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_patients');
    }
};
