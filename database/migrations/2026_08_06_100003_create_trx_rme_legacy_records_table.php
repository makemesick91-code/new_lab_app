<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-RME-PDF-1A — published legacy RME record.
 *
 * ADDITIVE ONLY. Deliberately NOT stored in trx_medical_records: a native
 * medical record requires a clinic_visit_id (NOT NULL + UNIQUE) and drives the
 * visit/cashier workflow. A legacy record is an archived historical document
 * with no visit, no billing, no consent and no workflow side effects.
 *
 * Immutability contract: a published record is never edited and never
 * hard-deleted (hence no soft-delete escape hatch either). A correction is a
 * VOID with a reason plus a fresh import. UNIQUE(source_import_id) makes the
 * publish step idempotent — one staging batch can produce at most one record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_legacy_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('patient_id')
                ->constrained('mst_patients')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Provenance metadata only — never an authorization source.
            $table->foreignId('origin_branch_id')
                ->nullable()
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            // The historical RME date read from the document by the operator.
            $table->date('rme_date');
            $table->string('title', 150)->nullable();
            $table->text('description')->nullable();

            $table->string('source_disk', 50);
            $table->string('source_pdf_path', 255);
            $table->string('source_pdf_sha256', 64);
            $table->string('normalized_content_hash', 64)->nullable();

            $table->unsignedInteger('page_count')->default(0);
            $table->string('status', 30)->default('PUBLISHED');

            $table->foreignId('source_import_id')
                ->nullable()
                ->constrained('stg_rme_legacy_imports')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreignId('imported_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('published_at')->nullable();

            $table->foreignId('voided_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->timestamps();

            $table->unique('source_import_id', 'trx_rme_legacy_records_source_import_uq');
            $table->index(['patient_id', 'rme_date'], 'trx_rme_legacy_records_patient_date_index');
            $table->index(['patient_id', 'status'], 'trx_rme_legacy_records_patient_status_index');
            $table->index('origin_branch_id', 'trx_rme_legacy_records_branch_index');
            $table->index('source_pdf_sha256', 'trx_rme_legacy_records_pdf_hash_index');
            $table->index('normalized_content_hash', 'trx_rme_legacy_records_content_hash_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_rme_legacy_records');
    }
};
