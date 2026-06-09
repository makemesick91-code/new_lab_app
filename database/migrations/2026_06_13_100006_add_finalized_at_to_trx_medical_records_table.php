<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_medical_records', function (Blueprint $table) {
            $table->timestamp('finalized_at')->nullable()->after('status');
            $table->index('finalized_at', 'trx_medical_records_finalized_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('trx_medical_records', function (Blueprint $table) {
            $table->dropIndex('trx_medical_records_finalized_at_index');
            $table->dropColumn('finalized_at');
        });
    }
};
