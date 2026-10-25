<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — immutable receipt snapshots
| (owner decision PP-5: D7 extends to issued receipts).
|--------------------------------------------------------------------------
|
| Until now a receipt (kwitansi) was rendered from LIVE rows, so correcting a paid
| invoice would silently change a receipt already handed to the patient. Every
| payment row now issues ONE receipt snapshot inside the payment transaction:
| the invoice exactly as it stood when that payment was recorded (items, totals,
| payments up to and including this one, status, correction version), hashed.
| A reprint is rendered from the snapshot, never from the live invoice.
|
| capture_basis
|   ISSUED_AT_PAYMENT                         — written in the payment transaction
|   RECONSTRUCTED_BEFORE_POSTPAYMENT_CORRECTION — a payment recorded before this
|       release has no snapshot; the first post-payment correction of its invoice
|       writes one from the verified pre-correction state, BEFORE mutating it, and
|       labels it a reconstruction. Valid because, before this release, no code
|       path could change a paid invoice's items or totals. It is never presented
|       as an identical reprint of the paper receipt.
|
| One snapshot per payment (UNIQUE payment id). No soft delete; the model refuses
| update and delete. FKs RESTRICT.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_receipt_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('rme_payment_id')->unique()->constrained('trx_rme_payments')->restrictOnDelete();
            $table->foreignId('rme_invoice_id')->constrained('trx_rme_invoices')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('mst_branches')->restrictOnDelete();
            $table->string('receipt_number', 40);
            $table->unsignedInteger('invoice_version');
            $table->string('invoice_status', 20);
            $table->string('capture_basis', 60);
            $table->unsignedSmallInteger('snapshot_schema_version');
            $table->jsonb('snapshot');
            $table->char('snapshot_sha256', 64);
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->index(['rme_invoice_id', 'invoice_version']);
            $table->index(['branch_id', 'issued_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE trx_rme_receipt_snapshots ADD CONSTRAINT trx_rme_receipt_snap_basis_chk CHECK (capture_basis IN ('ISSUED_AT_PAYMENT', 'RECONSTRUCTED_BEFORE_POSTPAYMENT_CORRECTION'))");
            DB::statement("ALTER TABLE trx_rme_receipt_snapshots ADD CONSTRAINT trx_rme_receipt_snap_status_chk CHECK (invoice_status IN ('PARTIAL', 'PAID'))");
        }
    }

    public function down(): void
    {
        if (DB::table('trx_rme_receipt_snapshots')->exists()) {
            throw new RuntimeException('Refusing to drop trx_rme_receipt_snapshots: issued receipt snapshots exist (they are the only true copy of an issued receipt).');
        }

        Schema::dropIfExists('trx_rme_receipt_snapshots');
    }
};
