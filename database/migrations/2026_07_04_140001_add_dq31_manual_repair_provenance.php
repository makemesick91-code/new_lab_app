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
            if (! Schema::hasColumn('trx_inventory_batch_backfill_logs', 'approval_reference')) {
                $table->string('approval_reference', 120)->nullable()->after('evidence');
            }
            if (! Schema::hasColumn('trx_inventory_batch_backfill_logs', 'approved_by')) {
                $table->string('approved_by', 120)->nullable()->after('approval_reference');
            }
            if (! Schema::hasColumn('trx_inventory_batch_backfill_logs', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('trx_inventory_batch_backfill_logs', 'approval_reason')) {
                $table->text('approval_reason')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('trx_inventory_batch_backfill_logs', 'old_inventory_batch_id')) {
                $table->unsignedBigInteger('old_inventory_batch_id')->nullable()->after('approval_reason');
            }
            if (! Schema::hasColumn('trx_inventory_batch_backfill_logs', 'dry_run')) {
                $table->boolean('dry_run')->default(false)->after('old_inventory_batch_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('trx_inventory_batch_backfill_logs')) {
            return;
        }

        Schema::table('trx_inventory_batch_backfill_logs', function (Blueprint $table) {
            foreach (['dry_run', 'old_inventory_batch_id', 'approval_reason', 'approved_at', 'approved_by', 'approval_reference'] as $column) {
                if (Schema::hasColumn('trx_inventory_batch_backfill_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
