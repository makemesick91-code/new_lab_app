<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — one row per correction line.
|
| `operation` is DERIVED by the server (KEEP / CHANGE / ADD / REMOVE), never
| accepted from a request. Every money column is computed server-side from qty,
| unit price and discount — a submitted subtotal or total is never stored.
|
| `original_item_id` deliberately has NO foreign key: applying a correction may
| delete the invoice item it names (REMOVE), and the immutable correction record
| must survive that. The item's full original values are kept on this row and
| in the correction's `original_snapshot`, so nothing is lost when the item row
| goes.
|
| Rows are immutable once written (model guard).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_rme_invoice_correction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rme_invoice_correction_id')
                ->constrained('trx_rme_invoice_corrections')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('operation', 10);

            $table->unsignedBigInteger('original_item_id')->nullable();
            $table->unsignedBigInteger('original_treatment_id')->nullable();
            $table->string('original_description', 255)->nullable();
            $table->unsignedInteger('original_qty')->nullable();
            $table->decimal('original_unit_price', 15, 2)->nullable();
            $table->decimal('original_discount', 15, 2)->nullable();
            $table->decimal('original_subtotal', 15, 2)->nullable();
            $table->unsignedBigInteger('original_doctor_id')->nullable();

            $table->foreignId('proposed_treatment_id')->nullable()->constrained('mst_treatments')->restrictOnDelete();
            $table->string('proposed_description', 255)->nullable();
            $table->unsignedInteger('proposed_qty')->nullable();
            $table->decimal('proposed_unit_price', 15, 2)->nullable();
            $table->decimal('proposed_discount', 15, 2)->nullable();
            $table->decimal('proposed_subtotal', 15, 2)->nullable();
            $table->foreignId('proposed_doctor_id')->nullable()->constrained('mst_doctors')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['rme_invoice_correction_id', 'line_no'], 'trx_rme_inv_corr_lines_line_uq');
            $table->index('original_item_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE trx_rme_invoice_correction_lines ADD CONSTRAINT trx_rme_inv_corr_lines_op_chk CHECK (operation IN ('KEEP', 'CHANGE', 'ADD', 'REMOVE'))");
            DB::statement('ALTER TABLE trx_rme_invoice_correction_lines ADD CONSTRAINT trx_rme_inv_corr_lines_money_chk CHECK ((proposed_subtotal IS NULL OR proposed_subtotal >= 0) AND (proposed_unit_price IS NULL OR proposed_unit_price >= 0) AND (proposed_discount IS NULL OR proposed_discount >= 0))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_rme_invoice_correction_lines');
    }
};
