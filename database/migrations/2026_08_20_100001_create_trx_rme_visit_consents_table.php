<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — signed "PERSETUJUAN TINDAKAN
 * MEDIS" as the RME payment gate.
 *
 * Additive only. Before this table the entire consent feature was two booleans
 * on trx_clinic_visits which the payment request itself supplied, so the gate
 * authored its own evidence. This table holds the real signed document, and
 * the payment gate reads it instead.
 *
 * Design notes:
 *
 *  - NOT unique on clinic_visit_id. A signed consent is legal evidence and is
 *    never edited or overwritten; a correction is a VOID plus a new signature,
 *    so a visit legitimately accumulates a short history. "Valid consent" means
 *    a row for this visit with voided_at IS NULL.
 *
 *  - No status enum. A row exists only once it has actually been signed, so
 *    "selected but unsigned" is simply the absence of a row, and the only other
 *    state a consent can reach is voided — expressed by the nullable void
 *    columns rather than by a status vocabulary.
 *
 *  - content_snapshot freezes the exact template wording that was agreed to, so
 *    editing the consent template later can never retro-change what a patient
 *    already signed.
 *
 *  - documentation_consent (clause 8, publishing photos/video) is nullable on
 *    purpose at the storage level: it must be an explicit YA/TIDAK captured
 *    from the person signing, never a silent default. The service refuses to
 *    write a row without it.
 *
 *  - restrictOnDelete on branch/visit/patient: consent evidence must not vanish
 *    because a parent row was removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_visit_consents', function (Blueprint $table) {
            $table->id();
            $table->string('consent_number')->unique();

            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('clinic_visit_id')->constrained('trx_clinic_visits')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('mst_patients')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('mst_doctors')->cascadeOnUpdate()->nullOnDelete();

            // Which form was signed, and exactly what it said at that moment.
            $table->string('template_code');
            $table->string('template_version');
            $table->json('content_snapshot');

            // The person actually signing (the patient, or a family member).
            $table->string('consenter_relationship');
            $table->string('consenter_name');
            $table->string('consenter_age')->nullable();
            $table->string('consenter_gender')->nullable();
            $table->text('consenter_address')->nullable();
            $table->string('consenter_identity_number')->nullable();

            // The patient the treatment is for, snapshotted for the document.
            $table->string('patient_name_snapshot');
            $table->string('patient_age_snapshot')->nullable();
            $table->string('patient_gender_snapshot')->nullable();
            $table->text('patient_address_snapshot')->nullable();
            $table->string('patient_identity_number_snapshot')->nullable();
            $table->string('medical_record_number_snapshot')->nullable();

            // What is being consented to.
            $table->text('medical_action');
            $table->text('treatment_summary')->nullable();

            // Clause 8 — documentation/publication consent. Explicit, never defaulted.
            $table->boolean('documentation_consent')->nullable();

            // Evidence.
            $table->string('consenter_signature_path');
            $table->string('doctor_signature_path')->nullable();
            $table->string('doctor_name_snapshot')->nullable();
            $table->string('signed_location')->nullable();
            $table->timestamp('signed_at');
            $table->foreignId('signed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            // Correction path — void and re-sign; never mutate a signed consent.
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->text('void_reason')->nullable();

            $table->timestamps();

            // The payment gate's hot path: "is there a live consent for this visit?"
            $table->index(['clinic_visit_id', 'voided_at'], 'trx_rme_visit_consents_visit_live_index');
            $table->index(['branch_id', 'patient_id'], 'trx_rme_visit_consents_branch_patient_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_rme_visit_consents');
    }
};
