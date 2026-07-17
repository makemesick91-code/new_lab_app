<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4B — clinical terminology review lifecycle metadata.
 *
 * Extends the SATUSEHAT-4A clinical diagnosis master with the operational
 * review lifecycle (draft → under_review → approved → active →
 * deprecated/rejected): who submitted/approved/deprecated, why, when, and an
 * optional replacement pointer for deprecated codes. Search aliases fold into
 * the existing normalized_search column — official display text never changes.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS). Every
 * column is nullable; legacy rows keep NULLs and remain fully readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mst_clinical_diagnoses', function (Blueprint $table) {
            if (! Schema::hasColumn('mst_clinical_diagnoses', 'submitted_by')) {
                $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('mst_clinical_diagnoses', 'submitted_for_review_at')) {
                $table->timestamp('submitted_for_review_at')->nullable();
            }
            if (! Schema::hasColumn('mst_clinical_diagnoses', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('mst_clinical_diagnoses', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (! Schema::hasColumn('mst_clinical_diagnoses', 'approval_reason')) {
                $table->string('approval_reason', 500)->nullable();
            }
            if (! Schema::hasColumn('mst_clinical_diagnoses', 'rejected_reason')) {
                $table->string('rejected_reason', 500)->nullable();
            }
            if (! Schema::hasColumn('mst_clinical_diagnoses', 'replacement_diagnosis_id')) {
                $table->foreignId('replacement_diagnosis_id')->nullable()
                    ->constrained('mst_clinical_diagnoses')->nullOnDelete();
            }
            if (! Schema::hasColumn('mst_clinical_diagnoses', 'deprecated_by')) {
                $table->foreignId('deprecated_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('mst_clinical_diagnoses', 'deprecated_at')) {
                $table->timestamp('deprecated_at')->nullable();
            }
            if (! Schema::hasColumn('mst_clinical_diagnoses', 'source_version')) {
                $table->string('source_version', 100)->nullable();
            }
            if (! Schema::hasColumn('mst_clinical_diagnoses', 'aliases')) {
                // Comma-separated search aliases folded into normalized_search;
                // never alters the official display.
                $table->string('aliases', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('mst_clinical_diagnoses', function (Blueprint $table) {
            foreach ([
                'submitted_by', 'approved_by', 'replacement_diagnosis_id', 'deprecated_by',
            ] as $fk) {
                if (Schema::hasColumn('mst_clinical_diagnoses', $fk)) {
                    $table->dropConstrainedForeignId($fk);
                }
            }
            foreach ([
                'submitted_for_review_at', 'approved_at', 'approval_reason',
                'rejected_reason', 'deprecated_at', 'source_version', 'aliases',
            ] as $column) {
                if (Schema::hasColumn('mst_clinical_diagnoses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
