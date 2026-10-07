<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — the merged state on a patient.
 *
 * A merged source patient is NEVER deleted. It keeps its row, its Nomor RM and
 * its history pointers; these columns say where it went. All nullable, no
 * backfill: NULL means "not merged", which is every existing patient.
 *
 * The two plain indexes support duplicate DETECTION, which blocks candidate
 * pairs by birth date and phone instead of scanning names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mst_patients', function (Blueprint $table) {
            $table->foreignId('merged_into_patient_id')->nullable()->constrained('mst_patients')->restrictOnDelete();
            $table->timestamp('merged_at')->nullable();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('merge_case_id')->nullable()->constrained('trx_patient_merge_cases')->restrictOnDelete();

            $table->index('merged_into_patient_id', 'mst_patients_merged_into_index');
            $table->index('date_of_birth', 'mst_patients_date_of_birth_index');
            $table->index('phone', 'mst_patients_phone_index');
        });
    }

    public function down(): void
    {
        Schema::table('mst_patients', function (Blueprint $table) {
            $table->dropIndex('mst_patients_phone_index');
            $table->dropIndex('mst_patients_date_of_birth_index');
            $table->dropIndex('mst_patients_merged_into_index');
            $table->dropConstrainedForeignId('merge_case_id');
            $table->dropConstrainedForeignId('merged_by');
            $table->dropConstrainedForeignId('merged_into_patient_id');
            $table->dropColumn('merged_at');
        });
    }
};
