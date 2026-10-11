<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SECURITY-PATIENT-KTP-ARCHIVE-BRANCH-ISOLATION-1 — record WHERE a patient
 * document was captured, so the KTP archive can be served per branch.
 *
 * Why a column, and not the patient's branch or Nomor RM: both are editable
 * through the unscoped patient master-data update form (UpdatePatientRequest
 * accepts `branch_id` and `medical_record_number`). Deriving document access
 * from either would let an operator re-home a patient to their own branch and
 * then open the identity card. Provenance has to live on the document, be set
 * by the server at capture time, and never move afterwards (the model refuses a
 * rewrite of a recorded value).
 *
 * Additive only: one nullable column, one index, nothing dropped or altered.
 * Existing rows keep NULL = "provenance unknown", which the access policy
 * treats as FAIL CLOSED for every branch-scoped role. They are filled, if at
 * all, only by `patient-documents:backfill-capture-branch`, which writes a value
 * solely where independent evidence agrees. Nothing is backfilled here.
 *
 * nullOnDelete: a branch row is never hard-deleted in practice; if one ever
 * were, losing the provenance makes the card fail closed rather than blocking
 * the delete or pointing at a branch that no longer exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mst_patient_documents', function (Blueprint $table): void {
            $table->foreignId('captured_branch_id')
                ->nullable()
                ->after('uploaded_by')
                ->constrained('mst_branches')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->index('captured_branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('mst_patient_documents', function (Blueprint $table): void {
            $table->dropForeign(['captured_branch_id']);
            $table->dropIndex(['captured_branch_id']);
            $table->dropColumn('captured_branch_id');
        });
    }
};
