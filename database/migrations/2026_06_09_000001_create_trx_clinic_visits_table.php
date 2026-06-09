<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_clinic_visits', function (Blueprint $table) {
            $table->id();
            $table->string('visit_number', 30)->unique();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('clinic_id')->constrained('mst_clinics')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('mst_patients')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('mst_doctors')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('clinic_room_id')->nullable()->constrained('mst_clinic_rooms')->cascadeOnUpdate()->nullOnDelete();
            $table->date('visit_date');
            $table->unsignedSmallInteger('queue_number');
            $table->string('status', 30)->default('registered');
            $table->text('chief_complaint')->nullable();
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'visit_date', 'queue_number'], 'trx_clinic_visits_branch_date_queue_unique');
            $table->index(['branch_id', 'visit_date', 'status'], 'trx_clinic_visits_branch_date_status_index');
            $table->index('patient_id');
            $table->index('doctor_id');
            $table->index('clinic_room_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_clinic_visits');
    }
};
