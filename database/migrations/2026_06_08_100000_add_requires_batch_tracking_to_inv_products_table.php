<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 16.4.1 — product-level opt-in for batch/lot tracking on inbound operations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_products', function (Blueprint $table) {
            $table->boolean('requires_batch_tracking')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('inv_products', function (Blueprint $table) {
            $table->dropColumn('requires_batch_tracking');
        });
    }
};
