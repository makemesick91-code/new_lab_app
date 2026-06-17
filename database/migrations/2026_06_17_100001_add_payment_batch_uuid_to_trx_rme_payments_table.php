<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 27 Phase 27.4 — group split control carry-over payments for receipt/audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_rme_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('trx_rme_payments', 'payment_batch_uuid')) {
                $table->uuid('payment_batch_uuid')->nullable()->after('notes');
                $table->index('payment_batch_uuid', 'trx_rme_payments_batch_uuid_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trx_rme_payments', function (Blueprint $table) {
            if (Schema::hasColumn('trx_rme_payments', 'payment_batch_uuid')) {
                $table->dropIndex('trx_rme_payments_batch_uuid_index');
                $table->dropColumn('payment_batch_uuid');
            }
        });
    }
};
