<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_lab_orders', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained('mst_branches')
                ->nullOnDelete();
        });

        Schema::table('trx_lab_deliveries', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained('mst_branches')
                ->nullOnDelete();
        });

        Schema::table('trx_invoices', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained('mst_branches')
                ->nullOnDelete();
        });

        Schema::table('trx_payments', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained('mst_branches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trx_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('trx_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('trx_lab_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('trx_lab_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
