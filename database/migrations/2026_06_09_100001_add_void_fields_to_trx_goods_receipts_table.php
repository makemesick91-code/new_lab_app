<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 16.4.3 — goods receipt void/cancel audit fields and reversal linkage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_goods_receipts', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable()->after('notes');
            $table->timestamp('voided_at')->nullable()->after('cancelled_at');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::table('trx_goods_receipt_items', function (Blueprint $table) {
            $table->foreignId('reversal_movement_id')
                ->nullable()
                ->after('inventory_movement_id')
                ->constrained('trx_inventory_movements')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trx_goods_receipt_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_movement_id');
        });

        Schema::table('trx_goods_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'cancellation_reason']);
        });
    }
};
