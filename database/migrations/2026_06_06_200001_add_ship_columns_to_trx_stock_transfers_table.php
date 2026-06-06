<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 15.2 — additive ship audit columns for in_transit workflow.
 *
 * Receive timestamps remain on completed_at / approved_by until a follow-up
 * migration renames them to received_at / received_by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_stock_transfers', function (Blueprint $table) {
            $table->timestamp('shipped_at')->nullable()->after('approved_by');
            $table->foreignId('shipped_by')->nullable()->after('shipped_at')->constrained('users')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trx_stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipped_by');
            $table->dropColumn('shipped_at');
        });
    }
};
