<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — merge cases, field-level
 * identity provenance, and the medical-record-number alias registry.
 *
 * ADDITIVE ONLY. Nothing existing is altered or dropped here; the patient
 * columns that record the merged state live in the next migration so this
 * one can be rolled back on its own.
 *
 * A merge is a reassignment of OWNERSHIP, never a deletion of history, so
 * every table below is a record of a decision — none of them holds clinical
 * content. Values that would identify a person (the chosen KTP/NIK, the
 * pre-merge identity of both patients) are stored ENCRYPTED; the columns a
 * screen or an audit row may read carry masked text only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_patient_merge_cases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('case_number', 40)->unique();

            $table->foreignId('patient_a_id')->constrained('mst_patients')->restrictOnDelete();
            $table->foreignId('patient_b_id')->constrained('mst_patients')->restrictOnDelete();
            // Chosen by a human; never derived from age, visit count or KTP.
            $table->foreignId('canonical_patient_id')->nullable()->constrained('mst_patients')->restrictOnDelete();
            $table->foreignId('source_patient_id')->nullable()->constrained('mst_patients')->restrictOnDelete();

            $table->string('status', 32)->default('draft');
            $table->string('risk_level', 16)->default('normal');
            $table->json('risk_flags')->nullable();
            $table->boolean('cross_branch')->default(false);

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->text('request_reason');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            // sha256 of both patients' identity at submission. The merge refuses
            // to run when it no longer matches: a reviewer approves what they saw.
            $table->string('identity_fingerprint', 64)->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();

            $table->timestamp('merged_at')->nullable();
            $table->json('before_summary')->nullable();
            $table->json('after_summary')->nullable();
            // table => [ids] actually moved, so a reversal moves back exactly
            // those rows and never guesses ownership.
            $table->json('moved_records')->nullable();
            // Encrypted at rest: full pre-merge identity of BOTH patients, kept
            // only so a supervised reversal can restore it.
            $table->text('identity_snapshot_encrypted')->nullable();

            $table->foreignId('reversal_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversal_requested_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->json('reversal_assessment')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();

            $table->timestamps();

            $table->index('status', 'trx_pmc_status_index');
            $table->index(['patient_a_id', 'status'], 'trx_pmc_patient_a_status_index');
            $table->index(['patient_b_id', 'status'], 'trx_pmc_patient_b_status_index');
            $table->index('canonical_patient_id', 'trx_pmc_canonical_index');
            $table->index('requested_by', 'trx_pmc_requested_by_index');
            $table->index('reviewed_by', 'trx_pmc_reviewed_by_index');
            $table->index('merged_at', 'trx_pmc_merged_at_index');
            $table->index('created_at', 'trx_pmc_created_at_index');
        });

        Schema::create('trx_patient_merge_field_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merge_case_id')->constrained('trx_patient_merge_cases')->cascadeOnDelete();
            $table->string('field', 40);
            $table->string('comparison_status', 16); // match | conflict | missing
            $table->string('source', 24)->nullable(); // patient_a | patient_b | manual | matched | empty
            // Encrypted: the value the canonical patient will carry. NIK-safe.
            $table->text('final_value_encrypted')->nullable();
            // What a screen or audit row may show — masked for KTP/NIK.
            $table->text('final_value_display')->nullable();
            $table->text('manual_reason')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['merge_case_id', 'field'], 'trx_pmfr_case_field_unique');
        });

        Schema::create('mst_patient_rm_aliases', function (Blueprint $table) {
            $table->id();
            // Globally unique: an old Nomor RM resolves to exactly one patient.
            $table->string('alias_medical_record_number', 50)->unique();
            // The merged (source) patient that was ISSUED this number. Immutable.
            $table->foreignId('source_patient_id')->constrained('mst_patients')->restrictOnDelete();
            // Where the number resolves to now. Follows a later merge of the
            // canonical patient so lookup never has to walk a chain.
            $table->foreignId('canonical_patient_id')->constrained('mst_patients')->restrictOnDelete();
            $table->string('alias_type', 24)->default('merged');
            $table->foreignId('merge_case_id')->constrained('trx_patient_merge_cases')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('merged_at');
            // A reversed merge revokes its alias instead of deleting it.
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('canonical_patient_id', 'mst_pra_canonical_index');
            $table->index('source_patient_id', 'mst_pra_source_index');
            $table->index('merge_case_id', 'mst_pra_case_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_patient_rm_aliases');
        Schema::dropIfExists('trx_patient_merge_field_resolutions');
        Schema::dropIfExists('trx_patient_merge_cases');
    }
};
