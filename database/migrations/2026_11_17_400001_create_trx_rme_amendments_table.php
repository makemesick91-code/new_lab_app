<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR4 (rule 240 §6) — the supervised
 * amendment of a final sheet or of a finalized odontogram.
 *
 * One row per amendment request: the subject (`medical_record` | `odontogram`,
 * by id), its branch, visit and patient (server-derived from the subject, for the
 * branch-scoped queue), the version the request was made against
 * (`base_version_id`, `base_version`), the written reason, the proposed change
 * (JSON) and the decision. Status `pending` → `cancelled` (the requester),
 * `rejected` (a checker, with a reason) or `applied` (M3.3 PR5).
 *
 * Backstops on PostgreSQL (the service refuses first, binding Super Admin):
 * maker ≠ checker (`decided_by <> requested_by`), the reason bounds, the shape of
 * each status. On both engines, a partial unique index keeps one `pending`
 * amendment per subject. Explicit constraint names (`trx_rme_amd_*`) — the
 * generated ones would exceed PostgreSQL's 63 characters. Additive only (CG-09);
 * down() refuses to drop the table while an amendment exists.
 */
return new class extends Migration
{
    private const TABLE = 'trx_rme_amendments';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('branch_id')->constrained('mst_branches', 'id', 'trx_rme_amd_branch_fk')->restrictOnDelete();
            $table->foreignId('clinic_visit_id')->constrained('trx_clinic_visits', 'id', 'trx_rme_amd_visit_fk')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('mst_patients', 'id', 'trx_rme_amd_patient_fk')->restrictOnDelete();
            $table->foreignId('base_version_id')->constrained('trx_rme_record_versions', 'id', 'trx_rme_amd_base_version_fk')->restrictOnDelete();
            $table->unsignedInteger('base_version');
            $table->string('status', 16);
            $table->text('reason');
            // `json`, not `jsonb`: the proposal is read back in the order it was written.
            $table->json('proposed_content');
            $table->foreignId('requested_by')->constrained('users', 'id', 'trx_rme_amd_requested_by_fk')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users', 'id', 'trx_rme_amd_decided_by_fk')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'trx_rme_amd_subject_idx');
            $table->index(['branch_id', 'status'], 'trx_rme_amd_branch_status_idx');
            $table->index('requested_by', 'trx_rme_amd_requested_by_idx');
        });

        // One pending amendment per subject — the service refuses first; this is
        // the backstop for a race. SQLite supports partial indexes too.
        DB::statement("CREATE UNIQUE INDEX trx_rme_amd_one_pending_uq ON trx_rme_amendments (subject_type, subject_id) WHERE status = 'pending'");

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            "trx_rme_amd_subject_chk CHECK (subject_type IN ('medical_record', 'odontogram'))",
            "trx_rme_amd_status_chk CHECK (status IN ('pending', 'cancelled', 'rejected', 'applied'))",
            'trx_rme_amd_base_version_chk CHECK (base_version >= 1)',
            'trx_rme_amd_reason_chk CHECK (char_length(btrim(reason)) BETWEEN 10 AND 1000)',
            // Maker ≠ checker (CG-07): the account that asked never decides.
            'trx_rme_amd_maker_checker_chk CHECK (decided_by IS NULL OR decided_by <> requested_by)',
            "trx_rme_amd_pending_chk CHECK (status <> 'pending' OR (decided_by IS NULL AND decided_at IS NULL AND cancelled_at IS NULL))",
            "trx_rme_amd_cancelled_chk CHECK (status <> 'cancelled' OR (cancelled_at IS NOT NULL AND decided_by IS NULL))",
            "trx_rme_amd_decided_chk CHECK (status NOT IN ('rejected', 'applied') OR (decided_by IS NOT NULL AND decided_at IS NOT NULL))",
            "trx_rme_amd_rejected_chk CHECK (status <> 'rejected' OR (decision_reason IS NOT NULL AND char_length(btrim(decision_reason)) BETWEEN 10 AND 1000))",
        ] as $constraint) {
            DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.$constraint);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Refusing to drop trx_rme_amendments: it holds amendment requests of medical records.');
        }

        Schema::dropIfExists(self::TABLE);
    }
};
