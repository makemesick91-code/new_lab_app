<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 16.8.2 — read-only reporting summary tables for inventory analytics optimization.
 *
 * Source of truth remains trx_inventory_movements (ledger). These tables are derived caches only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rpt_inventory_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('summary_date');
            $table->decimal('quantity_in_total', 18, 4)->default(0);
            $table->decimal('quantity_out_total', 18, 4)->default(0);
            $table->decimal('inbound_value', 18, 2)->default(0);
            $table->decimal('outbound_value', 18, 2)->default(0);
            $table->decimal('purchase_inbound_value', 18, 2)->default(0);
            $table->decimal('adjustment_in_qty', 18, 4)->default(0);
            $table->decimal('adjustment_out_qty', 18, 4)->default(0);
            $table->decimal('transfer_in_qty', 18, 4)->default(0);
            $table->decimal('transfer_out_qty', 18, 4)->default(0);
            $table->integer('movement_count')->default(0);
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'summary_date'], 'rpt_inv_daily_summaries_branch_date_unique');
            $table->index('summary_date', 'rpt_inv_daily_summaries_summary_date_index');
            $table->index(['branch_id', 'summary_date'], 'rpt_inv_daily_summaries_branch_date_index');
            $table->index('refreshed_at', 'rpt_inv_daily_summaries_refreshed_at_index');
        });

        Schema::create('rpt_inventory_branch_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('snapshot_date');
            $table->decimal('inventory_value', 18, 2)->default(0);
            $table->integer('active_sku_count')->default(0);
            $table->integer('low_stock_count')->default(0);
            $table->integer('dead_stock_count')->default(0);
            $table->decimal('dead_stock_value', 18, 2)->default(0);
            $table->integer('out_of_stock_count')->default(0);
            $table->integer('batch_expiring_soon_count')->default(0);
            $table->integer('batch_expired_count')->default(0);
            $table->decimal('inventory_accuracy_pct', 5, 2)->nullable();
            $table->integer('open_pr_count')->default(0);
            $table->integer('open_po_count')->default(0);
            $table->decimal('open_po_outstanding_value', 18, 2)->default(0);
            $table->integer('pending_gr_count')->default(0);
            $table->integer('in_transit_transfer_count')->default(0);
            $table->decimal('total_quantity_on_hand', 18, 4)->default(0);
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'snapshot_date'], 'rpt_inv_branch_summaries_branch_snapshot_unique');
            $table->index(['snapshot_date', 'branch_id'], 'rpt_inv_branch_summaries_snapshot_branch_index');
            $table->index(['branch_id', 'refreshed_at'], 'rpt_inv_branch_summaries_branch_refreshed_index');
            $table->index('snapshot_date', 'rpt_inv_branch_summaries_snapshot_date_index');
            $table->index('refreshed_at', 'rpt_inv_branch_summaries_refreshed_at_index');
        });

        Schema::create('rpt_inventory_product_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('product_id')->constrained('inv_products')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('snapshot_date');
            $table->decimal('current_stock', 18, 4)->default(0);
            $table->decimal('stock_value', 18, 2)->default(0);
            $table->decimal('average_cost', 18, 4)->default(0);
            $table->foreignId('product_category_id')->nullable()->constrained('inv_product_categories')->cascadeOnUpdate()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('alert_enabled')->default(true);
            $table->decimal('effective_reorder_point', 18, 4)->default(0);
            $table->boolean('is_low_stock')->default(false);
            $table->boolean('is_dead_stock')->default(false);
            $table->date('last_in_date')->nullable();
            $table->date('last_out_date')->nullable();
            $table->unsignedInteger('age_days')->nullable();
            $table->string('age_bucket', 20)->nullable();
            $table->decimal('outbound_qty_7d', 18, 4)->default(0);
            $table->decimal('outbound_qty_30d', 18, 4)->default(0);
            $table->decimal('outbound_qty_90d', 18, 4)->default(0);
            $table->decimal('outbound_value_30d', 18, 2)->default(0);
            $table->decimal('avg_daily_consumption_30d', 18, 4)->default(0);
            $table->foreignId('preferred_supplier_id')->nullable()->constrained('inv_suppliers')->cascadeOnUpdate()->nullOnDelete();
            $table->integer('fast_moving_rank')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'product_id', 'snapshot_date'], 'rpt_inv_product_summaries_branch_product_snapshot_unique');
            $table->index('product_id', 'rpt_inv_product_summaries_product_id_index');
            $table->index(['branch_id', 'product_category_id', 'snapshot_date'], 'rpt_inv_product_summaries_branch_category_snapshot_index');
            $table->index(['branch_id', 'snapshot_date', 'outbound_qty_90d'], 'rpt_inv_product_summaries_branch_snapshot_outbound_90d_index');
            $table->index('refreshed_at', 'rpt_inv_product_summaries_refreshed_at_index');
        });

        Schema::create('rpt_procurement_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('mst_branches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('inv_suppliers')->cascadeOnUpdate()->nullOnDelete();
            $table->date('summary_date');
            $table->integer('po_created_count')->default(0);
            $table->decimal('po_created_value', 18, 2)->default(0);
            $table->integer('po_open_count')->default(0);
            $table->decimal('po_open_outstanding_value', 18, 2)->default(0);
            $table->integer('gr_posted_count')->default(0);
            $table->decimal('gr_received_value', 18, 2)->default(0);
            $table->decimal('ledger_purchase_value', 18, 2)->default(0);
            $table->integer('pr_submitted_count')->default(0);
            $table->integer('supplier_order_count')->default(0);
            $table->decimal('supplier_received_value', 18, 2)->default(0);
            $table->integer('supplier_on_time_count')->default(0);
            $table->integer('supplier_dated_po_count')->default(0);
            $table->decimal('supplier_fulfilled_qty', 18, 4)->default(0);
            $table->decimal('supplier_ordered_qty', 18, 4)->default(0);
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->index('summary_date', 'rpt_procurement_daily_summaries_summary_date_index');
            $table->index(['branch_id', 'summary_date'], 'rpt_procurement_daily_summaries_branch_date_index');
            $table->index(['branch_id', 'po_open_outstanding_value'], 'rpt_procurement_daily_summaries_branch_outstanding_index');
            $table->index('refreshed_at', 'rpt_procurement_daily_summaries_refreshed_at_index');
        });

        $this->createReportingPartialIndexes();
        $this->createAnalyticsCompositeIndexes();
    }

    public function down(): void
    {
        $this->dropAnalyticsCompositeIndexes();
        $this->dropReportingPartialIndexes();

        Schema::dropIfExists('rpt_procurement_daily_summaries');
        Schema::dropIfExists('rpt_inventory_product_summaries');
        Schema::dropIfExists('rpt_inventory_branch_summaries');
        Schema::dropIfExists('rpt_inventory_daily_summaries');
    }

    private function createReportingPartialIndexes(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            Schema::table('rpt_inventory_product_summaries', function (Blueprint $table) {
                $table->index(['branch_id', 'snapshot_date', 'is_low_stock'], 'rpt_inv_product_summaries_low_stock_index');
                $table->index(['branch_id', 'snapshot_date', 'is_dead_stock'], 'rpt_inv_product_summaries_dead_stock_index');
            });

            return;
        }

        DB::statement('CREATE INDEX rpt_inv_product_summaries_low_stock_partial_index ON rpt_inventory_product_summaries (branch_id, snapshot_date, is_low_stock) WHERE is_low_stock = true');
        DB::statement('CREATE INDEX rpt_inv_product_summaries_dead_stock_partial_index ON rpt_inventory_product_summaries (branch_id, snapshot_date, is_dead_stock) WHERE is_dead_stock = true');
        DB::statement('CREATE UNIQUE INDEX rpt_procurement_daily_summaries_branch_date_unique ON rpt_procurement_daily_summaries (branch_id, summary_date) WHERE supplier_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX rpt_procurement_daily_summaries_branch_supplier_date_unique ON rpt_procurement_daily_summaries (branch_id, supplier_id, summary_date) WHERE supplier_id IS NOT NULL');
        DB::statement('CREATE INDEX rpt_procurement_daily_summaries_supplier_date_partial_index ON rpt_procurement_daily_summaries (branch_id, supplier_id, summary_date) WHERE supplier_id IS NOT NULL');
    }

    private function dropReportingPartialIndexes(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            Schema::table('rpt_inventory_product_summaries', function (Blueprint $table) {
                $table->dropIndex('rpt_inv_product_summaries_dead_stock_index');
                $table->dropIndex('rpt_inv_product_summaries_low_stock_index');
            });

            return;
        }

        DB::statement('DROP INDEX IF EXISTS rpt_inv_product_summaries_low_stock_partial_index');
        DB::statement('DROP INDEX IF EXISTS rpt_inv_product_summaries_dead_stock_partial_index');
        DB::statement('DROP INDEX IF EXISTS rpt_procurement_daily_summaries_branch_date_unique');
        DB::statement('DROP INDEX IF EXISTS rpt_procurement_daily_summaries_branch_supplier_date_unique');
        DB::statement('DROP INDEX IF EXISTS rpt_procurement_daily_summaries_supplier_date_partial_index');
    }

    private function createAnalyticsCompositeIndexes(): void
    {
        Schema::table('trx_inventory_movements', function (Blueprint $table) {
            $table->index(['branch_id', 'movement_date'], 'trx_inv_movements_branch_date_index');
            $table->index(['branch_id', 'movement_type', 'movement_date'], 'trx_inv_movements_branch_type_date_index');
            $table->index(['branch_id', 'product_id'], 'trx_inv_movements_branch_product_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX trx_inv_movements_branch_product_covering_index ON trx_inventory_movements (branch_id, product_id) INCLUDE (quantity_in, quantity_out)');
        }

        Schema::table('trx_purchase_orders', function (Blueprint $table) {
            $table->index(['branch_id', 'supplier_id', 'status'], 'trx_purchase_orders_branch_supplier_status_index');
        });

        Schema::table('trx_goods_receipts', function (Blueprint $table) {
            $table->index(['branch_id', 'status', 'posted_at'], 'trx_goods_receipts_branch_status_posted_index');
        });

        Schema::table('inv_products', function (Blueprint $table) {
            $table->index(['branch_id', 'is_active', 'alert_enabled'], 'inv_products_branch_active_alert_index');
        });
    }

    private function dropAnalyticsCompositeIndexes(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS trx_inv_movements_branch_product_covering_index');
        }

        Schema::table('inv_products', function (Blueprint $table) {
            $table->dropIndex('inv_products_branch_active_alert_index');
        });

        Schema::table('trx_goods_receipts', function (Blueprint $table) {
            $table->dropIndex('trx_goods_receipts_branch_status_posted_index');
        });

        Schema::table('trx_purchase_orders', function (Blueprint $table) {
            $table->dropIndex('trx_purchase_orders_branch_supplier_status_index');
        });

        Schema::table('trx_inventory_movements', function (Blueprint $table) {
            $table->dropIndex('trx_inv_movements_branch_product_index');
            $table->dropIndex('trx_inv_movements_branch_type_date_index');
            $table->dropIndex('trx_inv_movements_branch_date_index');
        });
    }
};
