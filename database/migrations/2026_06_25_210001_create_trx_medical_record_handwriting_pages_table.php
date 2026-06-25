<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 60 — Multi-page RME handwriting canvas (1 canvas = 1 RM page).
 *
 * Additive only. The legacy `trx_medical_record_handwritings` table is left
 * untouched and stays authoritative for Page 1 (read-through, see spec §5.1).
 * This table holds Page 2 and later. It mirrors the legacy columns and adds
 * `page_number` plus a UNIQUE(medical_record_id, page_number) guard so two
 * saves can never collide on the same page or silently overwrite a sibling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_medical_record_handwriting_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')->constrained('trx_medical_records')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('clinic_visit_id')->constrained('trx_clinic_visits')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('mst_doctors')->cascadeOnUpdate()->nullOnDelete();
            $table->unsignedInteger('page_number');
            $table->string('handwriting_path');
            $table->string('handwriting_hash', 64);
            $table->timestamp('saved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->unique(['medical_record_id', 'page_number']);
            $table->index('medical_record_id');
            $table->index('clinic_visit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_medical_record_handwriting_pages');
    }
};
