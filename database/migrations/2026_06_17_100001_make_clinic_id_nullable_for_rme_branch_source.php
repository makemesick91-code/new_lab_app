<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 23 Phase 23.9.1 — RME Clinic Source from Branch Master.
 *
 * "Klinik" for RME now means "Cabang RME" (a branch with is_rme_enabled = true).
 * New RME visits and the patients created inside the new-visit flow no longer
 * select a legacy mst_clinics row, so clinic_id must be allowed to be null.
 *
 * Additive + non-destructive:
 *   - mst_clinics is NOT dropped.
 *   - The clinic_id columns and their foreign keys are kept.
 *   - Existing rows (which already carry a clinic_id) are untouched.
 * Only the NOT NULL constraint is relaxed so RME records can omit it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_clinic_visits', function (Blueprint $table): void {
            $table->unsignedBigInteger('clinic_id')->nullable()->change();
        });

        Schema::table('mst_patients', function (Blueprint $table): void {
            $table->unsignedBigInteger('clinic_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverting to NOT NULL is only safe when no RME rows left clinic_id null.
        Schema::table('trx_clinic_visits', function (Blueprint $table): void {
            $table->unsignedBigInteger('clinic_id')->nullable(false)->change();
        });

        Schema::table('mst_patients', function (Blueprint $table): void {
            $table->unsignedBigInteger('clinic_id')->nullable(false)->change();
        });
    }
};
