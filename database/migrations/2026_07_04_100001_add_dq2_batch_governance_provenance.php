<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inv_inventory_batches')) {
            Schema::table('inv_inventory_batches', function (Blueprint $table) {
                if (! Schema::hasColumn('inv_inventory_batches', 'backfill_source')) {
                    $table->string('backfill_source', 100)->nullable()->after('notes');
                }
                if (! Schema::hasColumn('inv_inventory_batches', 'backfilled_at')) {
                    $table->timestamp('backfilled_at')->nullable()->after('backfill_source');
                }
            });
        }

        if (! Schema::hasTable('trx_inventory_batch_backfill_logs')) {
            Schema::create('trx_inventory_batch_backfill_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('inventory_movement_id')
                    ->constrained('trx_inventory_movements')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
                $table->foreignId('inventory_batch_id')
                    ->nullable()
                    ->constrained('inv_inventory_batches')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
                $table->string('strategy', 80);
                $table->string('command', 120)->default('inventory:backfill-missing-batches');
                $table->json('evidence')->nullable();
                $table->timestamp('executed_at');
                $table->timestamps();

                $table->unique('inventory_movement_id', 'trx_inv_batch_backfill_movement_unique');
                $table->index('inventory_batch_id');
            });
        }

        if (Schema::hasTable('trx_inventory_movements') && Schema::hasColumn('trx_inventory_movements', 'inventory_batch_id')) {
            $indexes = collect(Schema::getIndexes('trx_inventory_movements'))->pluck('name');
            if (! $indexes->contains('trx_inventory_movements_batch_product_index')) {
                Schema::table('trx_inventory_movements', function (Blueprint $table) {
                    $table->index(
                        ['inventory_batch_id', 'product_id'],
                        'trx_inventory_movements_batch_product_index',
                    );
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_inventory_batch_backfill_logs');

        if (Schema::hasTable('inv_inventory_batches')) {
            Schema::table('inv_inventory_batches', function (Blueprint $table) {
                if (Schema::hasColumn('inv_inventory_batches', 'backfilled_at')) {
                    $table->dropColumn('backfilled_at');
                }
                if (Schema::hasColumn('inv_inventory_batches', 'backfill_source')) {
                    $table->dropColumn('backfill_source');
                }
            });
        }

        if (Schema::hasTable('trx_inventory_movements')) {
            $indexes = collect(Schema::getIndexes('trx_inventory_movements'))->pluck('name');
            if ($indexes->contains('trx_inventory_movements_batch_product_index')) {
                Schema::table('trx_inventory_movements', function (Blueprint $table) {
                    $table->dropIndex('trx_inventory_movements_batch_product_index');
                });
            }
        }
    }
};
