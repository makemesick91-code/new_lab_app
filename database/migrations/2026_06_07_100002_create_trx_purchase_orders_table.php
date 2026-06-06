<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 16.2 — trx_purchase_orders (purchase order document header).
 *
 * This table records purchase order identity and approval workflow only.
 * It does not store stock balances, totals, or receiving state and must not
 * create trx_inventory_movements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('purchase_order_number', 50)->unique();
            $table->date('order_date');
            $table->string('status', 30)->default('draft');
            $table->foreignId('supplier_id')->nullable()->constrained('inv_suppliers')->cascadeOnUpdate()->nullOnDelete();
            $table->string('supplier_snapshot_name')->nullable();
            $table->string('supplier_reference_number')->nullable();
            $table->string('currency', 10)->default('IDR');
            $table->foreignId('purchase_request_id')->nullable()->constrained('trx_purchase_requests')->cascadeOnUpdate()->nullOnDelete();
            $table->date('expected_delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index('branch_id', 'trx_purchase_orders_branch_id_index');
            $table->index('status', 'trx_purchase_orders_status_index');
            $table->index('order_date', 'trx_purchase_orders_order_date_index');
            $table->index('supplier_id', 'trx_purchase_orders_supplier_id_index');
            $table->index('purchase_request_id', 'trx_purchase_orders_purchase_request_id_index');
            $table->index('purchase_order_number', 'trx_purchase_orders_number_index');
            $table->index(['branch_id', 'status'], 'trx_purchase_orders_branch_status_index');
            $table->index(['branch_id', 'order_date'], 'trx_purchase_orders_branch_date_index');
            $table->index(['branch_id', 'supplier_id'], 'trx_purchase_orders_branch_supplier_index');
            $table->index(['branch_id', 'purchase_request_id'], 'trx_purchase_orders_branch_pr_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_purchase_orders');
    }
};
