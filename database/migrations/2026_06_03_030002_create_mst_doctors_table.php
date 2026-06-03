<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-0202 — mst_doctors. Doctor belongsTo Clinic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('mst_clinics')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->string('phone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('clinic_id');
            $table->index('name');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_doctors');
    }
};
