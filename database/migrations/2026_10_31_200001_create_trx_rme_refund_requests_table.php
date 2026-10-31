<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (Sprint 4/7, rule 215) — the refund
| REQUEST and its decision. A request decides WHETHER an OPEN overpayment obligation
| will be paid back; it never moves money (settlement is Sprint 5 and has no table,
| route, permission or state here). Migration plan §2.
|
| Additive only. No backfill (there are no refund rows; production holds 0
| obligations). The obligation, invoice and payment tables are not altered.
| PostgreSQL CHECKs and partial UNIQUE indexes are driver-guarded like every
| financial migration since 2026-10-17; SQLite carries the column rules only.
| down() refuses while a row exists — a refund request is a financial fact.
*/
return new class extends Migration
{
    private const TABLE = 'trx_rme_refund_requests';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique('trx_rme_refund_req_uuid_uq');
            $table->string('request_number', 40)->nullable()->unique('trx_rme_refund_req_number_uq');
            $table->foreignId('branch_id')->constrained('mst_branches', 'id', 'trx_rme_refund_req_branch_fk')->restrictOnDelete();
            $table->foreignId('rme_invoice_id')->constrained('trx_rme_invoices', 'id', 'trx_rme_refund_req_invoice_fk')->restrictOnDelete();
            $table->foreignId('rme_overpayment_obligation_id')->constrained('trx_rme_overpayment_obligations', 'id', 'trx_rme_refund_req_obligation_fk')->restrictOnDelete();
            $table->foreignId('clinic_visit_id')->constrained('trx_clinic_visits', 'id', 'trx_rme_refund_req_visit_fk')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('mst_patients', 'id', 'trx_rme_refund_req_patient_fk')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('obligation_amount_snapshot', 15, 2);
            $table->unsignedInteger('invoice_version');
            $table->jsonb('request_snapshot');
            $table->char('request_snapshot_sha256', 64);
            $table->string('reason_code', 40);
            $table->text('reason_note');
            $table->string('patient_request_reference', 120);
            $table->string('status', 30);
            $table->char('intent_key', 32)->unique('trx_rme_refund_req_intent_uq');
            $table->char('request_fingerprint', 64);
            $table->foreignId('requested_by')->constrained('users', 'id', 'trx_rme_refund_req_requested_by_fk')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->foreignId('decided_by')->nullable()->constrained('users', 'id', 'trx_rme_refund_req_decided_by_fk')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->string('owner_approval_reference', 120);
            $table->foreignId('cancelled_by')->nullable()->constrained('users', 'id', 'trx_rme_refund_req_cancelled_by_fk')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->string('stale_reason_code', 60)->nullable();
            $table->timestampTz('staled_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status'], 'trx_rme_refund_req_branch_status_idx');
            $table->index(['status', 'requested_at'], 'trx_rme_refund_req_status_requested_idx');
            $table->index('rme_invoice_id', 'trx_rme_refund_req_invoice_idx');
            $table->index('rme_overpayment_obligation_id', 'trx_rme_refund_req_obligation_idx');
            $table->index('requested_by', 'trx_rme_refund_req_requested_by_idx');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            "trx_rme_refund_req_status_chk CHECK (status IN ('REQUESTED','APPROVED','REJECTED','CANCELLED','STALE'))",
            'trx_rme_refund_req_amount_chk CHECK (amount > 0)',
            'trx_rme_refund_req_amount_snapshot_chk CHECK (amount = obligation_amount_snapshot)',
            'trx_rme_refund_req_maker_checker_chk CHECK (decided_by IS NULL OR decided_by <> requested_by)',
            "trx_rme_refund_req_decided_chk CHECK (status NOT IN ('APPROVED','REJECTED') OR (decided_by IS NOT NULL AND decided_at IS NOT NULL))",
            'trx_rme_refund_req_owner_ref_chk CHECK (length(btrim(owner_approval_reference)) BETWEEN 6 AND 120)',
            'trx_rme_refund_req_patient_ref_chk CHECK (length(btrim(patient_request_reference)) BETWEEN 6 AND 120)',
            "trx_rme_refund_req_rejected_chk CHECK (status <> 'REJECTED' OR decision_note IS NOT NULL)",
            "trx_rme_refund_req_cancelled_chk CHECK (status <> 'CANCELLED' OR (cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL))",
            "trx_rme_refund_req_stale_chk CHECK (status <> 'STALE' OR (stale_reason_code IS NOT NULL AND staled_at IS NOT NULL))",
            "trx_rme_refund_req_intent_chk CHECK (intent_key ~ '^[0-9a-f]{32}$')",
            "trx_rme_refund_req_hashes_chk CHECK (request_fingerprint ~ '^[0-9a-f]{64}$' AND request_snapshot_sha256 ~ '^[0-9a-f]{64}$')",
        ] as $constraint) {
            DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.$constraint);
        }

        // One active request per obligation (the service refuses first with
        // REFUND_ACTIVE_REQUEST_EXISTS; this is the backstop).
        DB::statement("CREATE UNIQUE INDEX trx_rme_refund_req_one_active_uq ON trx_rme_refund_requests (rme_overpayment_obligation_id) WHERE status IN ('REQUESTED','APPROVED')");
        // One Owner approval per refund request, compared case-folded like every
        // other consumer of an Owner reference (rule 214).
        DB::statement('CREATE UNIQUE INDEX trx_rme_refund_req_owner_ref_uq ON trx_rme_refund_requests (lower(owner_approval_reference))');
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Refusing to drop trx_rme_refund_requests: it holds refund requests.');
        }

        Schema::dropIfExists(self::TABLE);
    }
};
