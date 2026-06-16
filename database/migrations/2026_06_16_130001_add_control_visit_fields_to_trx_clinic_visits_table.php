<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 27 Phase 27.3 — Control / follow-up visit linkage.
 * Additive only — existing visits default to visit_type = new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_clinic_visits', function (Blueprint $table): void {
            if (! Schema::hasColumn('trx_clinic_visits', 'visit_type')) {
                $table->string('visit_type', 30)->default('new')->after('status');
            }

            if (! Schema::hasColumn('trx_clinic_visits', 'follow_up_of_visit_id')) {
                $table->foreignId('follow_up_of_visit_id')
                    ->nullable()
                    ->after('visit_type')
                    ->constrained('trx_clinic_visits')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('trx_clinic_visits', function (Blueprint $table): void {
            if (Schema::hasColumn('trx_clinic_visits', 'follow_up_of_visit_id')) {
                $table->dropConstrainedForeignId('follow_up_of_visit_id');
            }

            if (Schema::hasColumn('trx_clinic_visits', 'visit_type')) {
                $table->dropColumn('visit_type');
            }
        });
    }
};
