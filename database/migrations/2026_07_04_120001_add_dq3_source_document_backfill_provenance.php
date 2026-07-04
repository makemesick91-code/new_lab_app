<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('trx_inventory_batch_backfill_logs')) {
            return;
        }

        Schema::table('trx_inventory_batch_backfill_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('trx_inventory_batch_backfill_logs', 'source_document_type')) {
                $table->string('source_document_type', 80)->nullable()->after('command');
            }
            if (! Schema::hasColumn('trx_inventory_batch_backfill_logs', 'source_document_item_id')) {
                $table->unsignedBigInteger('source_document_item_id')->nullable()->after('source_document_type');
            }
        });

        // DQ-3 source-document logs may exist without a movement row reference.
        if (Schema::hasColumn('trx_inventory_batch_backfill_logs', 'inventory_movement_id')) {
            Schema::table('trx_inventory_batch_backfill_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('inventory_movement_id')->nullable()->change();
            });
        }

        $indexes = Schema::hasTable('trx_inventory_batch_backfill_logs')
            ? collect(Schema::getIndexes('trx_inventory_batch_backfill_logs'))->pluck('name')
            : collect();

        if (! $indexes->contains('trx_inv_batch_backfill_source_doc_unique')) {
            Schema::table('trx_inventory_batch_backfill_logs', function (Blueprint $table) {
                $table->unique(
                    ['source_document_type', 'source_document_item_id'],
                    'trx_inv_batch_backfill_source_doc_unique',
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('trx_inventory_batch_backfill_logs')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('trx_inventory_batch_backfill_logs'))->pluck('name');
        if ($indexes->contains('trx_inv_batch_backfill_source_doc_unique')) {
            Schema::table('trx_inventory_batch_backfill_logs', function (Blueprint $table) {
                $table->dropUnique('trx_inv_batch_backfill_source_doc_unique');
            });
        }

        Schema::table('trx_inventory_batch_backfill_logs', function (Blueprint $table) {
            if (Schema::hasColumn('trx_inventory_batch_backfill_logs', 'source_document_item_id')) {
                $table->dropColumn('source_document_item_id');
            }
            if (Schema::hasColumn('trx_inventory_batch_backfill_logs', 'source_document_type')) {
                $table->dropColumn('source_document_type');
            }
        });
    }
};
