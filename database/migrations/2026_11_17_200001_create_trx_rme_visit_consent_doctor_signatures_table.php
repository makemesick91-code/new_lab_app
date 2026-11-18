<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| DMS memo M1.1–M1.3, PR1 (rule 241 §1; owner decisions 2026-11-17) — the treating
| doctor's countersignature on a PERSETUJUAN TINDAKAN MEDIS.
|
| Additive only. A signed consent is immutable evidence (AIR-RMC-12): the
| doctor's step is therefore its OWN insert-only row, never new columns written
| onto the consent. One row per consent (UNIQUE), so a second countersignature —
| including a racing one — is refused by the database as well as by the service,
| which decides it under the visit row lock.
|
|  - doctor_id   the mst_doctors row whose linked account signed (= the visit's
|                and the consent's doctor at signing time, decided in the service);
|  - signed_by   that account;
|  - signature_path / signature_sha256   the private object on the clinical
|                evidence disk (rule 121) and the SHA-256 of its bytes;
|  - signed_at   clinic time (ClinicalClock), as the consent's own signed_at.
|
| restrictOnDelete everywhere: evidence never vanishes with a parent row.
| No existing row is touched; nothing is backfilled — a historical consent stays
| without a countersignature, and the legacy optional doctor drawing on
| trx_rme_visit_consents.doctor_signature_path is display history only.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_visit_consent_doctor_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rme_visit_consent_id')
                ->constrained('trx_rme_visit_consents', 'id', 'trx_rme_vcds_consent_fk')
                ->restrictOnDelete();
            $table->foreignId('doctor_id')
                ->constrained('mst_doctors', 'id', 'trx_rme_vcds_doctor_fk')
                ->restrictOnDelete();
            $table->foreignId('signed_by')
                ->constrained('users', 'id', 'trx_rme_vcds_signed_by_fk')
                ->restrictOnDelete();
            $table->string('signature_path');
            $table->string('signature_sha256', 64);
            $table->timestamp('signed_at');
            $table->timestamp('created_at')->nullable();

            $table->unique('rme_visit_consent_id', 'trx_rme_vcds_consent_uq');
        });
    }

    public function down(): void
    {
        // A countersignature is clinical evidence: never dropped with rows in it.
        if (Schema::hasTable('trx_rme_visit_consent_doctor_signatures')
            && DB::table('trx_rme_visit_consent_doctor_signatures')->exists()) {
            throw new RuntimeException('trx_rme_visit_consent_doctor_signatures holds countersignatures; refusing to drop it.');
        }

        Schema::dropIfExists('trx_rme_visit_consent_doctor_signatures');
    }
};
