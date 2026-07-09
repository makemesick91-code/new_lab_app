<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX-PRE-68-45 Scope G — additive nullable `pr_type` on trx_purchase_requests to
 * distinguish a branch (Kepala Cabang) PR as Reguler vs Darurat. Additive only —
 * legacy PRs keep NULL (treated as unclassified / regular). No data change, no
 * destructive operation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_purchase_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('trx_purchase_requests', 'pr_type')) {
                $table->string('pr_type', 20)->nullable()->after('status')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('trx_purchase_requests', function (Blueprint $table) {
            if (Schema::hasColumn('trx_purchase_requests', 'pr_type')) {
                $table->dropColumn('pr_type');
            }
        });
    }
};
