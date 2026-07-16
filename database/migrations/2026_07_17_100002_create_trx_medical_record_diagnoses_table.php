<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4A — Structured diagnosis foundation (per medical record).
 *
 * Structured diagnoses entered by the doctor for a medical record. Legacy
 * medical records are NEVER backfilled with fabricated diagnoses — a record
 * without rows here simply reports MISSING_STRUCTURED_DIAGNOSIS in the
 * SATUSEHAT readiness workspace. Free-text/handwriting RM stays the primary
 * clinical input and is never auto-converted into codes.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trx_medical_record_diagnoses')) {
            return;
        }

        Schema::create('trx_medical_record_diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')->constrained('trx_medical_records')->cascadeOnDelete();
            $table->foreignId('clinic_visit_id')->constrained('trx_clinic_visits')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('mst_branches')->restrictOnDelete();
            $table->foreignId('clinical_diagnosis_id')->constrained('mst_clinical_diagnoses')->restrictOnDelete();
            // primary|secondary — at most one non-deleted primary per record is
            // enforced in the service layer (soft deletes make a DB unique unsafe).
            $table->string('diagnosis_role', 20)->default('primary')->index();
            $table->string('clinical_status', 30)->nullable();
            $table->string('verification_status', 30)->nullable();
            $table->foreignId('diagnosed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diagnosed_at')->nullable();
            // Short structured note only — never a raw clinical narrative dump.
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['medical_record_id', 'diagnosis_role'], 'trx_mr_dx_record_role_idx');
            $table->index(['branch_id', 'clinic_visit_id'], 'trx_mr_dx_branch_visit_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_medical_record_diagnoses');
    }
};
