<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §2.5; PR-2 review F8).
 *
 * "Is this Nomor RM taken?" compares case-insensitively: the patient side runs
 * `LOWER(medical_record_number) IN (...)` and, for a numeric number in any zero
 * padding, `LOWER(medical_record_number) LIKE 'dg-{code}-{year}-%'` plus a value
 * check (PatientRmSequenceRepository::anyNumberIssued/issuedNumbersFor); the merge
 * alias side runs `LOWER(alias_medical_record_number) = ?`
 * (PatientRmAliasRepository::findActiveByNumbers). The existing unique indexes are
 * on the raw columns, so none of these could use an index.
 *
 *  - mst_patients: an expression index on LOWER(medical_record_number) with
 *    `text_pattern_ops`, which serves both the equality list and the left-anchored
 *    LIKE under the non-C database collation.
 *  - mst_patient_rm_aliases: a plain expression index on the lowered alias.
 *
 * Additive only (CG-09): no column, constraint or row changes; the unique indexes
 * are kept. PostgreSQL only and CONCURRENTLY (no write lock on mst_patients during
 * the build, as for trx_clinic_visits_visit_number_pattern_idx); a no-op on SQLite.
 */
return new class extends Migration
{
    /** CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS mst_patients_mrn_lower_pattern_idx '
            .'ON mst_patients (LOWER(medical_record_number) text_pattern_ops)'
        );
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS mst_patient_rm_aliases_number_lower_idx '
            .'ON mst_patient_rm_aliases (LOWER(alias_medical_record_number))'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS mst_patient_rm_aliases_number_lower_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS mst_patients_mrn_lower_pattern_idx');
    }
};
