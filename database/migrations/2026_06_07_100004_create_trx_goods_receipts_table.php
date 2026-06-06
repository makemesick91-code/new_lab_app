<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 16.3 — trx_goods_receipts (goods receipt document header).
 *
 * This table records goods receipt identity and workflow state only.
 * Stock remains ledger-derived from trx_inventory_movements and is posted
 * in a later service workflow slice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('trx_purchase_orders')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('receipt_number', 50)->unique();
            $table->date('receipt_date');
            $table->string('supplier_delivery_number')->nullable();
            $table->string('supplier_invoice_number')->nullable();
            $table->string('status', 30)->default('draft');
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index('branch_id', 'trx_goods_receipts_branch_id_index');
            $table->index('purchase_order_id', 'trx_goods_receipts_purchase_order_id_index');
            $table->index('receipt_number', 'trx_goods_receipts_receipt_number_index');
            $table->index('status', 'trx_goods_receipts_status_index');
            $table->index('receipt_date', 'trx_goods_receipts_receipt_date_index');
            $table->index('posted_at', 'trx_goods_receipts_posted_at_index');
            $table->index(['branch_id', 'status'], 'trx_goods_receipts_branch_status_index');
            $table->index(['branch_id', 'receipt_date'], 'trx_goods_receipts_branch_date_index');
            $table->index(['branch_id', 'purchase_order_id'], 'trx_goods_receipts_branch_po_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_goods_receipts');
    }
};
