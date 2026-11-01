<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219; owner decisions CB-Q1 and
| DPT-Q1) — the doctor's structured record of what was ACTUALLY performed at a
| visit ("Tindakan Dilakukan"), additively:
|
|   trx_rme_treatment_declarations   one row per visit (UNIQUE clinic_visit_id):
|                                    the doctor's EXPLICIT choice — has_treatment
|                                    or no_treatment — and the operational
|                                    category it implies (no_treatment => control,
|                                    DPT-Q1). An absent row is NOT_RECORDED, never
|                                    "no treatment".
|   trx_rme_performed_treatments     append-only performed rows: stable treatment
|                                    identity + snapshots, quantity, FDI teeth.
|                                    A mistake is VOIDED with a reason, never
|                                    updated or deleted, so the history stays
|                                    reconstructable.
|
| Neither table carries patient_id or branch_id: both are derived from the visit
| (which patient merge re-assigns), so no second copy can drift. No price, amount
| or payment column exists: a clinical row is never a financial record (D21).
|
| PostgreSQL CHECKs bind the vocabulary and the two invariants a row can state on
| its own: no_treatment <=> operational category control, and a voided row
| carries its voider, time and reason. "no_treatment never coexists with an
| active row" spans two tables and is enforced in the service under the visit
| row lock (rule 219 §5).
|
| No existing row changes; nothing is backfilled — a historical visit stays
| NOT_RECORDED. down() refuses while any clinical row exists.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_treatment_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_visit_id')
                ->constrained('trx_clinic_visits', 'id', 'trx_rme_tdecl_visit_fk')
                ->restrictOnDelete();
            $table->string('declaration_type', 32);
            $table->string('operational_visit_category', 32)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('declared_by')
                ->constrained('users', 'id', 'trx_rme_tdecl_declared_by_fk')
                ->restrictOnDelete();
            $table->foreignId('declared_by_doctor_id')
                ->constrained('mst_doctors', 'id', 'trx_rme_tdecl_doctor_fk')
                ->restrictOnDelete();
            $table->timestampTz('declared_at');
            $table->timestamps();

            $table->unique('clinic_visit_id', 'trx_rme_tdecl_visit_uq');
            $table->index('operational_visit_category', 'trx_rme_tdecl_category_idx');
        });

        Schema::create('trx_rme_performed_treatments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_visit_id')
                ->constrained('trx_clinic_visits', 'id', 'trx_rme_ptx_visit_fk')
                ->restrictOnDelete();
            $table->foreignId('treatment_id')
                ->constrained('mst_treatments', 'id', 'trx_rme_ptx_treatment_fk')
                ->restrictOnDelete();
            $table->string('treatment_code_snapshot', 64);
            $table->string('treatment_name_snapshot', 255);
            $table->string('treatment_category_code_snapshot', 64)->nullable();
            $table->boolean('requires_lab_snapshot')->default(false);
            $table->unsignedSmallInteger('quantity');
            $table->json('tooth_numbers')->nullable();
            $table->string('status', 16);
            $table->uuid('submission_token');
            $table->foreignId('recorded_by')
                ->constrained('users', 'id', 'trx_rme_ptx_recorded_by_fk')
                ->restrictOnDelete();
            $table->foreignId('recorded_by_doctor_id')
                ->constrained('mst_doctors', 'id', 'trx_rme_ptx_doctor_fk')
                ->restrictOnDelete();
            $table->timestampTz('recorded_at');
            $table->foreignId('voided_by')->nullable()
                ->constrained('users', 'id', 'trx_rme_ptx_voided_by_fk')
                ->restrictOnDelete();
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['clinic_visit_id', 'submission_token'], 'trx_rme_ptx_submission_uq');
            $table->index(['clinic_visit_id', 'status'], 'trx_rme_ptx_visit_status_idx');
            $table->index('treatment_id', 'trx_rme_ptx_treatment_idx');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE trx_rme_treatment_declarations ADD CONSTRAINT trx_rme_tdecl_type_chk CHECK (declaration_type IN ('has_treatment','no_treatment'))");
        DB::statement('ALTER TABLE trx_rme_treatment_declarations ADD CONSTRAINT trx_rme_tdecl_category_chk CHECK ('
            ."(declaration_type = 'no_treatment' AND operational_visit_category = 'control') "
            ."OR (declaration_type = 'has_treatment' AND operational_visit_category IS NULL))");
        DB::statement('ALTER TABLE trx_rme_treatment_declarations ADD CONSTRAINT trx_rme_tdecl_version_chk CHECK (version >= 1)');

        DB::statement("ALTER TABLE trx_rme_performed_treatments ADD CONSTRAINT trx_rme_ptx_status_chk CHECK (status IN ('performed','voided'))");
        DB::statement('ALTER TABLE trx_rme_performed_treatments ADD CONSTRAINT trx_rme_ptx_quantity_chk CHECK (quantity BETWEEN 1 AND 32)');
        DB::statement('ALTER TABLE trx_rme_performed_treatments ADD CONSTRAINT trx_rme_ptx_void_chk CHECK ('
            ."(status = 'voided') = (voided_by IS NOT NULL AND voided_at IS NOT NULL AND void_reason IS NOT NULL))");
    }

    public function down(): void
    {
        if (DB::table('trx_rme_performed_treatments')->exists() || DB::table('trx_rme_treatment_declarations')->exists()) {
            throw new RuntimeException('Cannot roll back: doctor-recorded performed treatments exist. They are clinical evidence and are never dropped.');
        }

        Schema::dropIfExists('trx_rme_performed_treatments');
        Schema::dropIfExists('trx_rme_treatment_declarations');
    }
};
