<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FIX-PO-MULTI-VENDOR — item-level supplier + estimated arrival date.
 *
 * Additive only. Makes the per-item supplier the canonical vendor for a
 * purchase order (one PO can now carry items from multiple suppliers) and
 * gives each line its own estimated arrival date.
 *
 * The legacy header column trx_purchase_orders.supplier_id is intentionally
 * NOT dropped here — it is retained as a deprecated compatibility snapshot
 * (kept in sync as the sole distinct item supplier, or NULL for multi-vendor).
 *
 * Backfill is deterministic and portable (per-PO loop, PG + SQLite safe):
 * legacy item.supplier_id  <- parent PO header supplier_id
 * legacy item.estimated_arrival_date <- parent PO expected_delivery_date
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_purchase_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('trx_purchase_order_items', 'supplier_id')) {
                $table->foreignId('supplier_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('inv_suppliers')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('trx_purchase_order_items', 'estimated_arrival_date')) {
                $table->date('estimated_arrival_date')
                    ->nullable()
                    ->after('unit_price');
            }
        });

        Schema::table('trx_purchase_order_items', function (Blueprint $table) {
            $this->addIndexIfMissing($table, 'supplier_id', 'trx_po_items_supplier_id_index');
            $this->addCompositeIndexIfMissing($table, ['purchase_order_id', 'supplier_id'], 'trx_po_items_order_supplier_index');
        });

        $this->backfillFromHeader();
    }

    public function down(): void
    {
        Schema::table('trx_purchase_order_items', function (Blueprint $table) {
            if ($this->indexExists('trx_po_items_order_supplier_index')) {
                $table->dropIndex('trx_po_items_order_supplier_index');
            }
        });

        // supplier_id foreign key + index are dropped together via dropConstrainedForeignId.
        if (Schema::hasColumn('trx_purchase_order_items', 'supplier_id')) {
            Schema::table('trx_purchase_order_items', function (Blueprint $table) {
                if ($this->indexExists('trx_po_items_supplier_id_index')) {
                    $table->dropIndex('trx_po_items_supplier_id_index');
                }
                $table->dropConstrainedForeignId('supplier_id');
            });
        }

        if (Schema::hasColumn('trx_purchase_order_items', 'estimated_arrival_date')) {
            Schema::table('trx_purchase_order_items', function (Blueprint $table) {
                $table->dropColumn('estimated_arrival_date');
            });
        }
    }

    /**
     * Deterministic, chunked, PG + SQLite safe backfill of legacy rows.
     */
    private function backfillFromHeader(): void
    {
        DB::table('trx_purchase_orders')
            ->select('id', 'supplier_id', 'expected_delivery_date')
            ->orderBy('id')
            ->chunk(500, function ($orders): void {
                foreach ($orders as $order) {
                    if ($order->supplier_id !== null) {
                        DB::table('trx_purchase_order_items')
                            ->where('purchase_order_id', $order->id)
                            ->whereNull('supplier_id')
                            ->update(['supplier_id' => $order->supplier_id]);
                    }

                    if ($order->expected_delivery_date !== null) {
                        DB::table('trx_purchase_order_items')
                            ->where('purchase_order_id', $order->id)
                            ->whereNull('estimated_arrival_date')
                            ->update(['estimated_arrival_date' => $order->expected_delivery_date]);
                    }
                }
            });
    }

    private function addIndexIfMissing(Blueprint $table, string $column, string $name): void
    {
        if (! $this->indexExists($name)) {
            $table->index($column, $name);
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addCompositeIndexIfMissing(Blueprint $table, array $columns, string $name): void
    {
        if (! $this->indexExists($name)) {
            $table->index($columns, $name);
        }
    }

    private function indexExists(string $name): bool
    {
        try {
            return Schema::hasIndex('trx_purchase_order_items', $name);
        } catch (Throwable) {
            return false;
        }
    }
};
