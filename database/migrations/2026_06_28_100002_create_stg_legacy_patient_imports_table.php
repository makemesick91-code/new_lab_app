<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 62.3 — Legacy RME Patient Batch Import.
 *
 * Staging detail table: one row per data row in the uploaded CSV. Holds the
 * verbatim raw payload (audit), the normalized/mapped values, validation
 * outcome (valid|warning|error|committed|skipped|rolled_back) and the trace to
 * the created mst_patients row after commit.
 *
 * Full KTP/NIK lives only inside raw_payload / normalized_payload (JSON, never
 * rendered); UI reads ktp_masked. Advisory columns (Ruangan, Tindakan Awal,
 * Keluhan Utama, TTD) are staged only and never written to patient master.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stg_legacy_patient_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')
                ->constrained('stg_legacy_patient_import_batches')
                ->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_payload');
            $table->json('normalized_payload')->nullable();
            // valid | warning | error | committed | skipped | rolled_back
            $table->string('status', 20)->default('error');
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();

            $table->string('generated_medical_record_number', 80)->nullable();
            $table->unsignedBigInteger('matched_branch_id')->nullable();
            $table->unsignedBigInteger('matched_room_id')->nullable();
            $table->unsignedBigInteger('matched_doctor_id')->nullable();
            $table->unsignedBigInteger('committed_patient_id')->nullable();

            // Display-safe projections (NEVER the full KTP).
            $table->string('ktp_masked', 32)->nullable();
            $table->string('patient_name', 200)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('wa_number', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('gender', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->text('address')->nullable();
            $table->string('occupation', 150)->nullable();
            $table->string('manual_rm_number', 50)->nullable();
            $table->string('legacy_patient_id', 100)->nullable();
            $table->string('legacy_timestamp', 100)->nullable();

            // Advisory only — staged, never promoted to RME/visit records.
            $table->string('advisory_initial_treatment', 500)->nullable();
            $table->text('advisory_chief_complaint')->nullable();
            $table->string('advisory_doctor_signature', 255)->nullable();
            $table->string('advisory_patient_signature', 255)->nullable();

            $table->timestamps();

            $table->index(['batch_id', 'status']);
            $table->index('generated_medical_record_number');
            $table->index('committed_patient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stg_legacy_patient_imports');
    }
};
