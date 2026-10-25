<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — overpayment obligations
| (owner decision PP-3, option A).
|--------------------------------------------------------------------------
|
| A post-payment correction that brings an invoice's total BELOW the money already
| received does not move money: it records, in the same transaction as the
| correction, an OPEN obligation for the difference (amount derived by the server,
| never submitted). No refund, settlement, reversal or patient credit exists in
| this release — the obligation is the input those later sprints will consume
| (Sprint 4/7 refund request & approval, Sprint 5/7 settlement).
|
| Invariants held by the database (backstops — the service decides first):
|   - one obligation per correction        UNIQUE(source_correction_id)
|   - at most one OPEN obligation per invoice  partial UNIQUE (PostgreSQL)
|   - amount > 0; status IN ('OPEN') this release (widened additively later)
|   - FKs RESTRICT; no soft delete; the model refuses update and delete
|
| The event table is append-only (no updated_at).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_overpayment_obligations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('obligation_number', 40)->nullable()->unique();
            $table->foreignId('branch_id')->constrained('mst_branches')->restrictOnDelete();
            $table->foreignId('rme_invoice_id')->constrained('trx_rme_invoices')->restrictOnDelete();
            $table->foreignId('clinic_visit_id')->constrained('trx_clinic_visits')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('mst_patients')->restrictOnDelete();
            $table->foreignId('source_correction_id')->unique()->constrained('trx_rme_invoice_corrections')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->unsignedInteger('invoice_version');
            $table->string('status', 30);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('rme_invoice_id');
        });

        Schema::create('trx_rme_overpayment_obligation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rme_overpayment_obligation_id')->constrained('trx_rme_overpayment_obligations', 'id', 'trx_rme_ovp_events_obligation_fk')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['rme_overpayment_obligation_id', 'occurred_at'], 'trx_rme_ovp_events_obligation_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE trx_rme_overpayment_obligations ADD CONSTRAINT trx_rme_ovp_status_chk CHECK (status IN ('OPEN'))");
            DB::statement('ALTER TABLE trx_rme_overpayment_obligations ADD CONSTRAINT trx_rme_ovp_amount_chk CHECK (amount > 0)');
            DB::statement("CREATE UNIQUE INDEX trx_rme_ovp_one_open_uq ON trx_rme_overpayment_obligations (rme_invoice_id) WHERE status = 'OPEN'");
        }
    }

    public function down(): void
    {
        if (DB::table('trx_rme_overpayment_obligations')->exists()) {
            throw new RuntimeException('Refusing to drop trx_rme_overpayment_obligations: obligations exist (they are the only record of money owed back).');
        }

        Schema::dropIfExists('trx_rme_overpayment_obligation_events');
        Schema::dropIfExists('trx_rme_overpayment_obligations');
    }
};
