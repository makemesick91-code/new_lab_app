<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_clinic_visits', function (Blueprint $table) {
            $table->foreignId('initial_treatment_id')->nullable()->after('chief_complaint')
                ->constrained('mst_treatments')->cascadeOnUpdate()->nullOnDelete();
            $table->text('initial_service_note')->nullable()->after('initial_treatment_id');
        });
    }

    public function down(): void
    {
        Schema::table('trx_clinic_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('initial_treatment_id');
            $table->dropColumn('initial_service_note');
        });
    }
};
