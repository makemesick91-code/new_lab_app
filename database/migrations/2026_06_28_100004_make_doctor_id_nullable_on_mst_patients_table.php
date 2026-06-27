<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 62.3 — Legacy RME Patient Batch Import.
 *
 * Legacy spreadsheets frequently carry a free-text doctor name that does not
 * resolve to an mst_doctors row. The import maps an unresolved doctor to
 * doctor_id = NULL (with a warning) rather than minting or guessing a doctor.
 *
 * This relaxes the NOT NULL constraint on mst_patients.doctor_id, mirroring the
 * Sprint 23.9.1 relaxation of clinic_id (RME patients are anchored by branch).
 * Additive + non-destructive: the column and its foreign key are kept; existing
 * rows are untouched. Only the NOT NULL constraint is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mst_patients', function (Blueprint $table): void {
            $table->unsignedBigInteger('doctor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverting to NOT NULL is only safe when no patient left doctor_id null.
        Schema::table('mst_patients', function (Blueprint $table): void {
            $table->unsignedBigInteger('doctor_id')->nullable(false)->change();
        });
    }
};
