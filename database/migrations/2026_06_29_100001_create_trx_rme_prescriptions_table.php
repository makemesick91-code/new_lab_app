<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('clinic_visit_id')->unique()->constrained('trx_clinic_visits')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('medical_record_id')->nullable()->constrained('trx_medical_records')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('patient_id')->constrained('mst_patients')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('mst_doctors')->cascadeOnUpdate()->nullOnDelete();
            $table->string('prescribed_by_name');
            $table->date('prescription_date');
            $table->string('patient_name_snapshot');
            $table->string('patient_age_snapshot')->nullable();
            $table->text('allergy_note')->nullable();
            $table->string('pregnant_or_breastfeeding')->nullable();
            $table->string('renal_function_issue')->nullable();
            $table->string('prescription_canvas_path')->nullable();
            $table->string('doctor_signature_canvas_path')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('printed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'patient_id'], 'trx_rme_prescriptions_branch_patient_index');
            $table->index('medical_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_rme_prescriptions');
    }
};
