<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — invoice document version.
|
| ADDITIVE ONLY. `correction_version` counts the corrections APPLIED to an
| invoice: 0 = the document as created (every existing row, no backfill), N = the
| Nth revision. It is the optimistic-concurrency token a correction request pins
| at submission, and it is part of the invoice fingerprint, so a correction can
| never be applied over a document it was not reviewed against.
|
| Nothing writes it except RmeInvoiceCorrectionService::apply(), which is
| unreachable while the `rme.invoice_correction` flag is off.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_rme_invoices', function (Blueprint $table) {
            $table->unsignedInteger('correction_version')->default(0)->after('grand_total');
        });
    }

    public function down(): void
    {
        Schema::table('trx_rme_invoices', function (Blueprint $table) {
            $table->dropColumn('correction_version');
        });
    }
};
