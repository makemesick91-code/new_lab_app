<?php

// SATUSEHAT-1 — Controlled submission candidate (one per eligible visit).
// Additive only. A completed visit with a FINAL medical record becomes a
// readiness CANDIDATE only — never an auto-send. One active candidate per
// clinic_visit_id (UNIQUE) keeps generation idempotent; source_version +
// source_hash track clinical drift so approval can be revoked on change.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_satusehat_candidates', function (Blueprint $table) {
            $table->id();

            $table->string('environment', 20)->default('sandbox');

            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();

            // UNIQUE → idempotent firstOrCreate; exactly one candidate per visit.
            $table->foreignId('clinic_visit_id')->unique()->constrained('trx_clinic_visits')->cascadeOnUpdate()->restrictOnDelete();

            $table->foreignId('patient_id')->constrained('mst_patients')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('mst_doctors')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('medical_record_id')->nullable()->constrained('trx_medical_records')->cascadeOnUpdate()->nullOnDelete();

            // Deterministic clinical fingerprint at generation / approval time.
            $table->unsignedInteger('source_version')->default(1);
            $table->string('source_hash', 64)->nullable();
            $table->string('approved_source_hash', 64)->nullable();

            // ready | incomplete | blocked | source_changed
            $table->string('readiness_status', 20)->default('incomplete');
            // Structured, machine + human readable, PII-free reason codes.
            $table->json('readiness_reasons')->nullable();

            // pending | approved | excluded | queued
            $table->string('review_status', 20)->default('pending');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('excluded_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('excluded_at')->nullable();
            $table->text('exclusion_reason')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // Encrypted-at-rest snapshots (model uses encrypted:array casts).
            // Never rendered raw; never contains full NIK in plaintext columns.
            $table->longText('eligibility_snapshot')->nullable();
            $table->longText('preview_snapshot')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'readiness_status']);
            $table->index(['branch_id', 'review_status']);
            $table->index('environment');
            $table->index('patient_id');
            $table->index('doctor_id');
            $table->index('medical_record_id');
            $table->index('reviewed_at');
            $table->index('approved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_satusehat_candidates');
    }
};
