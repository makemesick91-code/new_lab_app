<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 23 Phase 23.8 — Patient ID Format Finalization + New Patient Registration.
 *
 * Additive only. Stores the components that compose the finalized medical
 * record number format `RM DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}`:
 *   - branch_id        : the RME branch selected manually during registration
 *   - registered_at    : the patient registration date (year drives TAHUN_DAFTAR)
 *   - manual_rm_number : the manual RM number entered by the admin
 *
 * `medical_record_number` keeps the final composed value. All columns are
 * nullable so legacy patients (created before this phase) keep working without
 * a destructive backfill. No existing rows are rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mst_patients', function (Blueprint $table): void {
            if (! Schema::hasColumn('mst_patients', 'branch_id')) {
                $table->foreignId('branch_id')
                    ->nullable()
                    ->after('doctor_id')
                    ->constrained('mst_branches')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('mst_patients', 'registered_at')) {
                $table->date('registered_at')->nullable()->after('medical_record_number');
            }

            if (! Schema::hasColumn('mst_patients', 'manual_rm_number')) {
                $table->string('manual_rm_number', 50)->nullable()->after('registered_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mst_patients', function (Blueprint $table): void {
            if (Schema::hasColumn('mst_patients', 'branch_id')) {
                $table->dropConstrainedForeignId('branch_id');
            }

            foreach (['registered_at', 'manual_rm_number'] as $column) {
                if (Schema::hasColumn('mst_patients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
