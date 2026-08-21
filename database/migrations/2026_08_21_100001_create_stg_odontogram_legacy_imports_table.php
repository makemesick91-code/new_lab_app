<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX-04b — staging table for a legacy (historical) ODONTOGRAM chart.
 *
 * ADDITIVE ONLY. One row = one historical paper odontogram chart, scanned to
 * PDF, being prepared for a single patient.
 *
 * DELIBERATELY ITS OWN TABLE. It does NOT reuse and does NOT extend
 * `stg_rme_legacy_imports`: adding a discriminator column there would couple
 * this capability to the Legacy RME wave/quota/admission machinery and to that
 * module's feature flag, so a rollback on one side would silently take the
 * other down with it. It equally does NOT write to `trx_odontograms`, which
 * requires a NOT NULL + UNIQUE `clinic_visit_id` and participates in the live
 * examination workflow — a legacy chart has no visit and must never create one.
 *
 * `selected_odontogram_date` is the date the operator READ on the document. It
 * is intentionally separate from `uploaded_at`, `created_at` and
 * `published_at`, and is validated against
 * `earliest_native_odontogram_date_snapshot` — the patient's earliest NATIVE
 * odontogram date captured server-side (never accepted from the client).
 *
 * `origin_branch_id` / `source_branch_code` are DERIVED from the branch-code
 * segment of the patient's Nomor RM (DG-{BRANCH}-{YEAR}-{NUMBER}); an operator
 * can never submit them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stg_odontogram_legacy_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('patient_id')
                ->constrained('mst_patients')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Derived from the patient's Nomor RM, never submitted. It IS an
            // authorization input here: the repository scopes row visibility on
            // it, which is precisely why it may not be operator-chosen.
            $table->foreignId('origin_branch_id')
                ->nullable()
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            // The branch code the resolution was made from, kept verbatim as
            // provenance (`TKM1`). An operational label, never patient data.
            $table->string('source_branch_code', 20)->nullable();

            // The patient's canonical Nomor RM at import time, so a later audit
            // can answer "which RM did this chart resolve through?" without
            // re-deriving it from a master record that may have moved on.
            $table->string('source_medical_record_number', 64)->nullable();

            $table->date('selected_odontogram_date');
            $table->date('earliest_native_odontogram_date_snapshot')->nullable();

            $table->string('original_filename', 255)->nullable();
            $table->string('source_disk', 50)->nullable();
            $table->string('source_pdf_path', 255)->nullable();
            $table->string('source_pdf_sha256', 64)->nullable();

            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedInteger('dpi')->nullable();

            $table->string('status', 30)->default('DRAFT');
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_completed_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['patient_id', 'selected_odontogram_date'], 'stg_odo_legacy_imports_patient_date_idx');
            $table->index(['patient_id', 'status'], 'stg_odo_legacy_imports_patient_status_idx');
            $table->index('status', 'stg_odo_legacy_imports_status_idx');
            $table->index('origin_branch_id', 'stg_odo_legacy_imports_branch_idx');
            $table->index('source_pdf_sha256', 'stg_odo_legacy_imports_pdf_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stg_odontogram_legacy_imports');
    }
};
