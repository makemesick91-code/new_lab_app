<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REVISION-LEGACY-PATIENT-STAGED-VERIFICATION-CANCEL-1.
 *
 * Sprint 62.3 gave the Legacy Patient importer a staging header that could
 * record who COMMITTED a batch and who ROLLED ONE BACK. It could not record the
 * two things this revision makes load-bearing:
 *
 *   CANCELLATION, which is not rollback. A cancelled batch created zero
 *   patients; a rolled-back batch created patients and then soft-deleted them.
 *   Writing a cancellation into `rolled_back_by` / `rolled_back_at` would make
 *   the audit trail claim patients had been created and withdrawn, which is a
 *   false statement about the patient estate. They are separate columns because
 *   they are separate events.
 *
 *   REVALIDATION, which is the evidence that the confirm path re-checked the
 *   batch against the database as it stood at confirmation rather than trusting
 *   the preview. Without a durable marker, "it was revalidated" is an inference
 *   from the absence of an error, and an inference is exactly what a later edit
 *   can silently remove.
 *
 * ADDITIVE ONLY. Every column is nullable or defaulted, nothing is dropped, no
 * column is made NOT NULL and no backfill is required: this is a staging table,
 * and an existing batch legitimately has no cancellation and no revalidation.
 *
 * The review states (REVIEW_REQUIRED / READY_TO_IMPORT) are deliberately NOT
 * columns. Both are a pure function of `error_rows`, so storing them would
 * create a second source of truth that can disagree with the counters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stg_legacy_patient_import_batches', function (Blueprint $table): void {
            // Cancellation evidence — a distinct event from rollback.
            if (! Schema::hasColumn('stg_legacy_patient_import_batches', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('stg_legacy_patient_import_batches', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }

            /*
             * Optional, and optional on purpose. Legacy Patient import has no
             * business concept of a cancellation reason, so requiring one would
             * only teach operators to type a placeholder — a field whose every
             * value is "n/a" is worse than no field, because it looks like
             * evidence.
             */
            if (! Schema::hasColumn('stg_legacy_patient_import_batches', 'cancel_reason')) {
                $table->string('cancel_reason', 500)->nullable();
            }

            // Revalidation evidence.
            if (! Schema::hasColumn('stg_legacy_patient_import_batches', 'revalidation_attempts')) {
                $table->unsignedInteger('revalidation_attempts')->default(0);
            }

            if (! Schema::hasColumn('stg_legacy_patient_import_batches', 'revalidated_at')) {
                $table->timestamp('revalidated_at')->nullable();
            }

            /*
             * Set only when the stored source file was re-hashed and matched the
             * hash recorded at upload. It is the durable answer to "was the file
             * the operator reviewed the file that was imported".
             */
            if (! Schema::hasColumn('stg_legacy_patient_import_batches', 'source_verified_at')) {
                $table->timestamp('source_verified_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stg_legacy_patient_import_batches', function (Blueprint $table): void {
            if (Schema::hasColumn('stg_legacy_patient_import_batches', 'cancelled_by')) {
                $table->dropConstrainedForeignId('cancelled_by');
            }

            foreach (['cancelled_at', 'cancel_reason', 'revalidation_attempts', 'revalidated_at', 'source_verified_at'] as $column) {
                if (Schema::hasColumn('stg_legacy_patient_import_batches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
