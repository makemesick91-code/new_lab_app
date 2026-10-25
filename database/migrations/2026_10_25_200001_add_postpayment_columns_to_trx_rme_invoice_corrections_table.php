<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — post-payment columns on the
| ONE correction table (rule 206 §4: one engine, no parallel table).
|--------------------------------------------------------------------------
|
| ADDITIVE ONLY: every column is nullable, nothing is backfilled, no existing row
| changes. The PRE_PAYMENT correction already in production (RMECOR-202610-000001)
| keeps NULL in all of them.
|
|   invoice_status_at_request      — the invoice status the maker saw (PARTIAL/PAID)
|   paid_payment_count_at_request  — how many payment rows the ledger held then
|   outstanding_after, overpaid_after, invoice_status_after
|                                  — written once, at APPLIED, from the ledger
|   owner_approval_reference       — owner decision PP-2 / PP-7: a case-specific
|                                    Owner approval reference is MANDATORY for a
|                                    POST_PAYMENT correction (rule 206 §F25). The
|                                    database refuses a POST_PAYMENT row without it.
|
| PostgreSQL CHECKs (driver-guarded like every financial migration since 2026-10-17):
|   - outstanding_after and overpaid_after are never negative and never BOTH > 0;
|   - a POST_PAYMENT row carries an owner approval reference.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_rme_invoice_corrections', function (Blueprint $table) {
            $table->string('invoice_status_at_request', 20)->nullable();
            $table->unsignedInteger('paid_payment_count_at_request')->nullable();
            $table->decimal('outstanding_after', 15, 2)->nullable();
            $table->decimal('overpaid_after', 15, 2)->nullable();
            $table->string('invoice_status_after', 20)->nullable();
            $table->string('owner_approval_reference', 120)->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE trx_rme_invoice_corrections ADD CONSTRAINT trx_rme_inv_corr_ledger_after_chk CHECK ((outstanding_after IS NULL OR outstanding_after >= 0) AND (overpaid_after IS NULL OR overpaid_after >= 0) AND NOT (COALESCE(outstanding_after, 0) > 0 AND COALESCE(overpaid_after, 0) > 0))');
            DB::statement("ALTER TABLE trx_rme_invoice_corrections ADD CONSTRAINT trx_rme_inv_corr_postpay_reference_chk CHECK (correction_type <> 'POST_PAYMENT' OR (owner_approval_reference IS NOT NULL AND length(btrim(owner_approval_reference)) >= 6))");
        }
    }

    public function down(): void
    {
        // Refuses while any POST_PAYMENT row exists: those columns are the only
        // record of what a post-payment correction did to the ledger.
        if (DB::table('trx_rme_invoice_corrections')->where('correction_type', 'POST_PAYMENT')->exists()) {
            throw new RuntimeException('Refusing to drop post-payment correction columns: POST_PAYMENT corrections exist.');
        }

        // Every applied correction (pre-payment too) records its ledger outcome
        // and possibly an owner approval reference here; never drop them silently.
        $recorded = DB::table('trx_rme_invoice_corrections')
            ->where(fn ($q) => $q->whereNotNull('invoice_status_after')
                ->orWhereNotNull('outstanding_after')
                ->orWhereNotNull('overpaid_after')
                ->orWhereNotNull('owner_approval_reference'))
            ->exists();

        if ($recorded) {
            throw new RuntimeException('Refusing to drop post-payment correction columns: corrections already record their ledger outcome in them.');
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE trx_rme_invoice_corrections DROP CONSTRAINT IF EXISTS trx_rme_inv_corr_ledger_after_chk');
            DB::statement('ALTER TABLE trx_rme_invoice_corrections DROP CONSTRAINT IF EXISTS trx_rme_inv_corr_postpay_reference_chk');
        }

        Schema::table('trx_rme_invoice_corrections', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_status_at_request',
                'paid_payment_count_at_request',
                'outstanding_after',
                'overpaid_after',
                'invoice_status_after',
                'owner_approval_reference',
            ]);
        });
    }
};
