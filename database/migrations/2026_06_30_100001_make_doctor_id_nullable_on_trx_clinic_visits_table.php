<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 66.1.4 — Admin Klinik registers visits without a doctor; doctor_id is
 * resolved when a treatment room is assigned from the patient queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_clinic_visits', function (Blueprint $table) {
            $table->unsignedBigInteger('doctor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('trx_clinic_visits', function (Blueprint $table) {
            $table->unsignedBigInteger('doctor_id')->nullable(false)->change();
        });
    }
};
