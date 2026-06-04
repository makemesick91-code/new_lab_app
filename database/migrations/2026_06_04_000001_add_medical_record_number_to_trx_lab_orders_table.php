<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_lab_orders', function (Blueprint $table) {
            $table->string('medical_record_number', 100)->nullable();
            $table->index('medical_record_number');
        });
    }

    public function down(): void
    {
        Schema::table('trx_lab_orders', function (Blueprint $table) {
            $table->dropIndex(['medical_record_number']);
            $table->dropColumn('medical_record_number');
        });
    }
};
