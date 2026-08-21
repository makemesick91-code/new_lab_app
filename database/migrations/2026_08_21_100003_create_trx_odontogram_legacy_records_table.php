<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX-04b — a PUBLISHED legacy odontogram record.
 *
 * ADDITIVE ONLY, and deliberately NOT stored in `trx_odontograms`. A native
 * odontogram is keyed to a clinic visit (NOT NULL + UNIQUE `clinic_visit_id`),
 * carries a structured `tooth_map_payload` the examination UI edits, and takes
 * part in the finalize/print pipeline. A legacy chart is an archived IMAGE of a
 * paper document: it has no visit, no structured tooth map, no billing and no
 * workflow side effect, and inventing a visit to hold one would corrupt the
 * queue, the cashier handoff and every visit-based report.
 *
 * IMMUTABILITY CONTRACT. A published record is never edited and never
 * hard-deleted (hence no soft-delete escape hatch either). A correction is a
 * VOID with a reason plus a fresh import. UNIQUE(source_import_id) makes
 * publishing idempotent — one staging batch produces at most one record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_odontogram_legacy_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('patient_id')
                ->constrained('mst_patients')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Derived from the patient's Nomor RM at import time and carried
            // forward verbatim. Authorization scopes on it; nothing may edit it.
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->string('source_branch_code', 20)->nullable();
            $table->string('source_medical_record_number', 64)->nullable();

            $table->date('odontogram_date');
            $table->string('title', 150)->nullable();
            $table->text('description')->nullable();

            $table->string('source_disk', 50);
            $table->string('source_pdf_path', 255);
            $table->string('source_pdf_sha256', 64);

            $table->unsignedInteger('page_count')->default(0);
            $table->string('status', 30)->default('PUBLISHED');

            $table->foreignId('source_import_id')
                ->nullable()
                ->constrained('stg_odontogram_legacy_imports')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreignId('imported_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('published_at')->nullable();

            $table->foreignId('voided_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->timestamps();

            $table->unique('source_import_id', 'trx_odo_legacy_records_source_import_uq');
            $table->index(['patient_id', 'odontogram_date'], 'trx_odo_legacy_records_patient_date_idx');
            $table->index(['patient_id', 'status'], 'trx_odo_legacy_records_patient_status_idx');
            $table->index('branch_id', 'trx_odo_legacy_records_branch_idx');
            $table->index('source_pdf_sha256', 'trx_odo_legacy_records_pdf_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_odontogram_legacy_records');
    }
};
