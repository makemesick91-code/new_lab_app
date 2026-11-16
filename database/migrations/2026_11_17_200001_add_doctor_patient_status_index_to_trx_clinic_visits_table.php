<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 (rule 243 §2, PR A1) — the
 * doctor encounter window lookup.
 *
 * With `rme.doctor_encounter_scope` ON, every doctor read asks "does this doctor
 * have an own in-service encounter with this patient?":
 *
 *   WHERE patient_id = ? AND doctor_id = ? AND deleted_at IS NULL
 *     AND (status IN (open statuses) OR (status = 'completed' AND completed_at in today))
 *
 * once per patient per request (memoised), and as a correlated EXISTS per row in
 * every doctor visit/patient list (keyed on the outer row's patient_id and the
 * doctor). The table only had single-column indexes on patient_id and
 * doctor_id, so each probe had to combine two indexes or scan all of one
 * patient's (or one doctor's) visits and filter. One composite index on
 * (doctor_id, patient_id, status) answers the probe from the index, with
 * completed_at checked only on the few rows of that doctor and patient.
 *
 * Additive only (CG-09): one index, no data touched. The table is small on the
 * current host, so a plain (non-concurrent) build holds its write lock briefly;
 * `down()` exists for local rollback only — production never runs it.
 */
return new class extends Migration
{
    private const INDEX = 'trx_clinic_visits_doctor_patient_status_index';

    public function up(): void
    {
        Schema::table('trx_clinic_visits', function (Blueprint $table) {
            $table->index(['doctor_id', 'patient_id', 'status'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('trx_clinic_visits', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
