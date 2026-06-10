<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_medical_record_handwritings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')->constrained('trx_medical_records')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('clinic_visit_id')->constrained('trx_clinic_visits')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('mst_doctors')->cascadeOnUpdate()->nullOnDelete();
            $table->string('handwriting_path');
            $table->string('handwriting_hash', 64);
            $table->timestamp('saved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index('medical_record_id');
            $table->index('clinic_visit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_medical_record_handwritings');
    }
};
