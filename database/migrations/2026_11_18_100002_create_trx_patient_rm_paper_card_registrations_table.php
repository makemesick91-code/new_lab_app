<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §4.3, PR-3b) — the
 * paper-card reason (owner decision 2026-11-17, "PAPER-CARD REASON: store text,
 * restricted").
 *
 * Additive only (CG-09): a new table, no existing row touched. One row per
 * paper-card registration, written in the same transaction as the Nomor RM
 * claim: the structured reason code (config `patient.paper_card_reasons`) and
 * the optional note (10–500 characters, required for LAINNYA). Immutable: no
 * `updated_at`, the model refuses update and delete, and the repository offers
 * neither; a future correction is a NEW row with its own audit. Kept for the
 * life of the patient record, so every foreign key RESTRICTs (patients and
 * users are soft-deleted; a hard delete must not erase the reason). Read only
 * through the restricted, branch-scoped surface (rule 244 §4.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_patient_rm_paper_card_registrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('branch_id');
            // The year printed on the card (the claimed number's year).
            $table->smallInteger('year');
            $table->string('reason_code', 40);
            $table->string('reason_note', 500)->nullable();
            $table->unsignedBigInteger('actor_user_id');
            $table->timestamp('created_at')->nullable();

            $table->foreign('patient_id', 'trx_prpcr_patient_fk')->references('id')->on('mst_patients')->restrictOnDelete();
            $table->foreign('branch_id', 'trx_prpcr_branch_fk')->references('id')->on('mst_branches')->restrictOnDelete();
            $table->foreign('actor_user_id', 'trx_prpcr_actor_fk')->references('id')->on('users')->restrictOnDelete();

            $table->index('patient_id', 'trx_prpcr_patient_idx');
            $table->index(['branch_id', 'created_at'], 'trx_prpcr_branch_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_patient_rm_paper_card_registrations');
    }
};
