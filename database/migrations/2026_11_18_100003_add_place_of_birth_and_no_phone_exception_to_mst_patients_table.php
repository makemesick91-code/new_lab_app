<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.2 (rule 244 §5, RM PR-4) — the
 * place of birth and the no-phone exception of a patient.
 *
 * Additive only (CG-09): four nullable columns, no default, no existing row
 * touched. The production patients (1,522 measured on 2026-11-17) keep NULL in
 * all four: the core fields bind NEW registrations only and an edit keeps every
 * field optional.
 *
 *  - `place_of_birth` (≤100): required at a new registration on both forms,
 *    optional on an edit (it stops being display-only, AIR-PAT-21).
 *  - `no_phone_reason_code` / `no_phone_reason_note` / `no_phone_recorded_at`:
 *    the OPEN no-phone exception (config `patient.no_phone_reasons`, plan
 *    default 2), written only by PatientService at registration under
 *    `register_patient_without_phone` and cleared (all three) when an edit
 *    records a phone or WhatsApp number. The history lives in the audit trail
 *    (ids and the reason code only); the note never leaves the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mst_patients', function (Blueprint $table) {
            $table->string('place_of_birth', 100)->nullable();
            $table->string('no_phone_reason_code', 40)->nullable();
            $table->string('no_phone_reason_note', 500)->nullable();
            $table->timestamp('no_phone_recorded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mst_patients', function (Blueprint $table) {
            $table->dropColumn(['place_of_birth', 'no_phone_reason_code', 'no_phone_reason_note', 'no_phone_recorded_at']);
        });
    }
};
