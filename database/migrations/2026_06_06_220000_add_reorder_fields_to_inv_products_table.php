<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_products', function (Blueprint $table) {
            $table->decimal('reorder_point', 12, 2)->nullable()->default(0)->after('minimum_stock');
            $table->decimal('reorder_quantity', 12, 2)->nullable()->default(0)->after('reorder_point');
            $table->boolean('alert_enabled')->default(true)->after('reorder_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('inv_products', function (Blueprint $table) {
            $table->dropColumn(['reorder_point', 'reorder_quantity', 'alert_enabled']);
        });
    }
};
