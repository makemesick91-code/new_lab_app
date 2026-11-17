<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 (rule 243 §2.12, PR A4) — why an
 * automatic patient-doctor assignment was ended.
 *
 * With `rme.doctor_encounter_scope` ON, a queue room move that changes the
 * visit's doctor, or a cancel, ends the former doctor's `auto_visit` row for
 * that patient. The row records the reason as a short machine code
 * (`visit_doctor_changed`, `visit_cancelled`), never free text, so it can never
 * carry patient data. The existing `reason` column is the reason the row was
 * CREATED (a share or reassignment) and is not reused for its end.
 *
 * Additive only (CG-09): one nullable column, no data touched; rows ended before
 * this column, or by `rme:doctor-assignments-reconcile` (which sets
 * `unassigned_at` only and names its rows in its audit row), keep NULL.
 * `down()` exists for local rollback only — production never runs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('trx_rme_patient_doctor_assignments', 'unassigned_reason')) {
            return;
        }

        Schema::table('trx_rme_patient_doctor_assignments', function (Blueprint $table) {
            $table->string('unassigned_reason', 64)->nullable()->after('unassigned_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('trx_rme_patient_doctor_assignments', 'unassigned_reason')) {
            return;
        }

        Schema::table('trx_rme_patient_doctor_assignments', function (Blueprint $table) {
            $table->dropColumn('unassigned_reason');
        });
    }
};
