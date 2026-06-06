<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 16.3 — cumulative received quantity cache on purchase order lines.
 *
 * This column is a derived cache owned exclusively by GoodsReceiptService::post().
 * It must not be treated as stock or edited through controllers, forms, or UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('trx_purchase_order_items', 'quantity_received')) {
            return;
        }

        Schema::table('trx_purchase_order_items', function (Blueprint $table) {
            $table->decimal('quantity_received', 12, 2)->default(0)->after('quantity_ordered');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('trx_purchase_order_items', 'quantity_received')) {
            return;
        }

        Schema::table('trx_purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('quantity_received');
        });
    }
};
