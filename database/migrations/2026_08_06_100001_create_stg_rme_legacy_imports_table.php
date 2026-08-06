<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-RME-PDF-1A — staging table for a legacy (historical) RME document.
 *
 * ADDITIVE ONLY. One row = one legacy RME document being prepared for a single
 * patient. It never becomes a clinic visit and never carries billing state.
 *
 * `selected_rme_date` is the date the operator READ on the document. It is
 * intentionally separate from `uploaded_at`, `created_at` and `published_at`,
 * and is validated against `earliest_native_rme_date_snapshot` — the patient's
 * earliest NATIVE RME date captured server-side at the moment the draft was
 * created (never accepted from the client).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stg_rme_legacy_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('patient_id')
                ->constrained('mst_patients')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Origin branch is provenance metadata for the paper archive.
            // It is NEVER an authorization source — access is resolved from
            // BranchContext / the RME-enabled branch set.
            $table->foreignId('origin_branch_id')
                ->nullable()
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            // The manually chosen historical RME date and the server-side cutoff
            // snapshot it was validated against.
            $table->date('selected_rme_date');
            $table->date('earliest_native_rme_date_snapshot')->nullable();

            // Source file. Nullable until the upload runtime (follow-up sprint)
            // actually stores a file for this draft.
            $table->string('original_filename', 255)->nullable();
            $table->string('source_disk', 50)->nullable();
            $table->string('source_pdf_path', 255)->nullable();
            $table->string('source_pdf_sha256', 64)->nullable();
            $table->string('normalized_content_hash', 64)->nullable();

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

            $table->index(['patient_id', 'selected_rme_date'], 'stg_rme_legacy_imports_patient_date_index');
            $table->index(['patient_id', 'status'], 'stg_rme_legacy_imports_patient_status_index');
            $table->index('status', 'stg_rme_legacy_imports_status_index');
            $table->index('origin_branch_id', 'stg_rme_legacy_imports_branch_index');
            $table->index('source_pdf_sha256', 'stg_rme_legacy_imports_pdf_hash_index');
            $table->index('normalized_content_hash', 'stg_rme_legacy_imports_content_hash_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stg_rme_legacy_imports');
    }
};
