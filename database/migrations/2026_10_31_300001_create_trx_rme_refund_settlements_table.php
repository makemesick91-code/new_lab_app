<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| FEATURE-RME-REFUND-SETTLEMENT-1 (Sprint 5/7, rule 216; owner RF-9 C, RF-10 B,
| RF-11) — the record that money owed back on an APPROVED refund request actually
| left the clinic, and its append-only events.
|
| A settlement is never a negative payment: incoming payments (trx_rme_payments) are
| not touched. The row moves DISBURSING (a settler claimed the payout) → SETTLED
| (verified, evidence attached) | OUTCOME_UNKNOWN (reconciliation required) |
| ABORTED (money did not move). Only SETTLED reduces net received (RFS-1).
|
| Invariants held by the database (backstops — the service decides first):
|   - at most one non-ABORTED settlement per request AND per obligation  (RFS-3)
|   - a bank reference / handover number and an evidence file settle at most once
|   - amount > 0; settler, escalator and aborter are never the approver   (RF-10)
|   - every terminal state carries its proof columns
|   - FKs RESTRICT; no soft delete; the model refuses deletes and rewrites
|
| Additive only. PostgreSQL CHECKs and partial UNIQUE indexes are driver-guarded like
| every financial migration since 2026-10-17. down() refuses while a row exists —
| a settlement is a financial fact.
*/
return new class extends Migration
{
    private const TABLE = 'trx_rme_refund_settlements';

    private const EVENTS = 'trx_rme_refund_settlement_events';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique('trx_rme_refund_stl_uuid_uq');
            $table->string('settlement_number', 40)->nullable()->unique('trx_rme_refund_stl_number_uq');
            $table->foreignId('branch_id')->constrained('mst_branches', 'id', 'trx_rme_refund_stl_branch_fk')->restrictOnDelete();
            $table->foreignId('rme_invoice_id')->constrained('trx_rme_invoices', 'id', 'trx_rme_refund_stl_invoice_fk')->restrictOnDelete();
            $table->foreignId('rme_overpayment_obligation_id')->constrained('trx_rme_overpayment_obligations', 'id', 'trx_rme_refund_stl_obligation_fk')->restrictOnDelete();
            $table->foreignId('rme_refund_request_id')->constrained('trx_rme_refund_requests', 'id', 'trx_rme_refund_stl_request_fk')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('method', 20);
            $table->string('status', 30);
            $table->char('intent_key', 32)->unique('trx_rme_refund_stl_intent_uq');
            $table->char('request_fingerprint', 64);
            $table->foreignId('approved_by')->constrained('users', 'id', 'trx_rme_refund_stl_approved_by_fk')->restrictOnDelete();
            $table->foreignId('started_by')->constrained('users', 'id', 'trx_rme_refund_stl_started_by_fk')->restrictOnDelete();
            $table->timestampTz('started_at');
            $table->foreignId('outcome_unknown_by')->nullable()->constrained('users', 'id', 'trx_rme_refund_stl_unknown_by_fk')->restrictOnDelete();
            $table->timestampTz('outcome_unknown_at')->nullable();
            $table->text('outcome_note')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users', 'id', 'trx_rme_refund_stl_settled_by_fk')->restrictOnDelete();
            $table->timestampTz('settled_at')->nullable();
            $table->timestampTz('settler_attested_at')->nullable();
            $table->string('disbursement_reference', 60)->nullable();
            // Letters and digits of the reference, upper case: the uniqueness key, so
            // "BRI-123456" and "bri 123456" are one payout, whatever the method.
            $table->string('disbursement_reference_key', 60)->nullable();
            $table->string('destination_bank_name', 60)->nullable();
            $table->char('destination_account_last4', 4)->nullable();
            $table->string('recipient_relation', 30)->nullable();
            $table->foreignId('aborted_by')->nullable()->constrained('users', 'id', 'trx_rme_refund_stl_aborted_by_fk')->restrictOnDelete();
            $table->timestampTz('aborted_at')->nullable();
            $table->text('abort_reason')->nullable();
            $table->string('evidence_path', 255)->nullable();
            $table->char('evidence_sha256', 64)->nullable();
            $table->string('evidence_mime', 60)->nullable();
            $table->unsignedBigInteger('evidence_size_bytes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status'], 'trx_rme_refund_stl_branch_status_idx');
            $table->index(['status', 'settled_at'], 'trx_rme_refund_stl_status_settled_idx');
            $table->index('rme_invoice_id', 'trx_rme_refund_stl_invoice_idx');
            $table->index('rme_refund_request_id', 'trx_rme_refund_stl_request_idx');
            $table->index('rme_overpayment_obligation_id', 'trx_rme_refund_stl_obligation_idx');
        });

        Schema::create(self::EVENTS, function (Blueprint $table) {
            $table->id();
            $table->foreignId('rme_refund_settlement_id')->constrained(self::TABLE, 'id', 'trx_rme_refund_stl_events_settlement_fk')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_id')->constrained('users', 'id', 'trx_rme_refund_stl_events_actor_fk')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['rme_refund_settlement_id', 'occurred_at'], 'trx_rme_refund_stl_events_settlement_idx');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            "trx_rme_refund_stl_status_chk CHECK (status IN ('DISBURSING','OUTCOME_UNKNOWN','SETTLED','ABORTED'))",
            "trx_rme_refund_stl_method_chk CHECK (method IN ('BANK_TRANSFER','CASH'))",
            'trx_rme_refund_stl_amount_chk CHECK (amount > 0)',
            // RF-10: nobody who acts on a settlement is the request's approver.
            'trx_rme_refund_stl_not_approver_chk CHECK (started_by <> approved_by'
                .' AND (settled_by IS NULL OR settled_by <> approved_by)'
                .' AND (outcome_unknown_by IS NULL OR outcome_unknown_by <> approved_by)'
                .' AND (aborted_by IS NULL OR aborted_by <> approved_by))',
            "trx_rme_refund_stl_settled_chk CHECK (status <> 'SETTLED' OR (settled_by IS NOT NULL AND settled_at IS NOT NULL"
                .' AND settler_attested_at IS NOT NULL AND disbursement_reference IS NOT NULL AND disbursement_reference_key IS NOT NULL AND evidence_path IS NOT NULL'
                .' AND evidence_sha256 IS NOT NULL AND evidence_mime IS NOT NULL AND evidence_size_bytes > 0))',
            "trx_rme_refund_stl_bank_chk CHECK (status <> 'SETTLED' OR method <> 'BANK_TRANSFER'"
                ." OR (length(btrim(destination_bank_name)) BETWEEN 2 AND 60 AND destination_account_last4 ~ '^[0-9]{4}$'))",
            "trx_rme_refund_stl_cash_chk CHECK (status <> 'SETTLED' OR method <> 'CASH' OR recipient_relation IN ('PATIENT','REPRESENTATIVE'))",
            "trx_rme_refund_stl_unknown_chk CHECK (status <> 'OUTCOME_UNKNOWN' OR (outcome_unknown_by IS NOT NULL AND outcome_unknown_at IS NOT NULL AND outcome_note IS NOT NULL))",
            "trx_rme_refund_stl_aborted_chk CHECK (status <> 'ABORTED' OR (aborted_by IS NOT NULL AND aborted_at IS NOT NULL AND abort_reason IS NOT NULL))",
            'trx_rme_refund_stl_reference_chk CHECK (disbursement_reference IS NULL OR length(btrim(disbursement_reference)) BETWEEN 6 AND 60)',
            "trx_rme_refund_stl_reference_key_chk CHECK (disbursement_reference_key IS NULL OR disbursement_reference_key ~ '^[A-Z0-9]{6,60}$')",
            "trx_rme_refund_stl_intent_chk CHECK (intent_key ~ '^[0-9a-f]{32}$')",
            "trx_rme_refund_stl_hashes_chk CHECK (request_fingerprint ~ '^[0-9a-f]{64}$' AND (evidence_sha256 IS NULL OR evidence_sha256 ~ '^[0-9a-f]{64}$'))",
        ] as $constraint) {
            DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.$constraint);
        }

        // RF-10 anchored in the database: approved_by is not a copied value the service
        // could get wrong — it must BE the request's decider (composite FK), so the
        // "never the approver" CHECK above compares against the real approver.
        DB::statement('ALTER TABLE trx_rme_refund_requests ADD CONSTRAINT trx_rme_refund_req_id_decider_uq UNIQUE (id, decided_by)');
        DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT trx_rme_refund_stl_approver_fk FOREIGN KEY (rme_refund_request_id, approved_by)'
            .' REFERENCES trx_rme_refund_requests (id, decided_by) ON DELETE RESTRICT ON UPDATE RESTRICT');

        // RFS-3: one live (non-ABORTED) settlement per request and per obligation.
        DB::statement("CREATE UNIQUE INDEX trx_rme_refund_stl_one_per_request_uq ON trx_rme_refund_settlements (rme_refund_request_id) WHERE status <> 'ABORTED'");
        DB::statement("CREATE UNIQUE INDEX trx_rme_refund_stl_one_per_obligation_uq ON trx_rme_refund_settlements (rme_overpayment_obligation_id) WHERE status <> 'ABORTED'");
        // One external payout proves one refund: a reference and an evidence file settle once.
        DB::statement("CREATE UNIQUE INDEX trx_rme_refund_stl_reference_uq ON trx_rme_refund_settlements (disbursement_reference_key) WHERE status = 'SETTLED'");
        DB::statement("CREATE UNIQUE INDEX trx_rme_refund_stl_evidence_uq ON trx_rme_refund_settlements (evidence_sha256) WHERE status = 'SETTLED'");
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Refusing to drop '.self::TABLE.': refund settlements are financial facts.');
        }

        Schema::dropIfExists(self::EVENTS);
        Schema::dropIfExists(self::TABLE);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE trx_rme_refund_requests DROP CONSTRAINT IF EXISTS trx_rme_refund_req_id_decider_uq');
        }
    }
};
