<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier consent verification checklist for Surat Persetujuan Tindakan.
 * Additive only — existing visits default to unsigned until cashier confirms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_clinic_visits', function (Blueprint $table): void {
            if (! Schema::hasColumn('trx_clinic_visits', 'consent_signed_by_patient')) {
                $table->boolean('consent_signed_by_patient')->default(false)->after('initial_service_note');
            }

            if (! Schema::hasColumn('trx_clinic_visits', 'consent_signed_by_doctor')) {
                $table->boolean('consent_signed_by_doctor')->default(false)->after('consent_signed_by_patient');
            }

            if (! Schema::hasColumn('trx_clinic_visits', 'consent_verified_at')) {
                $table->timestamp('consent_verified_at')->nullable()->after('consent_signed_by_doctor');
            }

            if (! Schema::hasColumn('trx_clinic_visits', 'consent_verified_by')) {
                $table->foreignId('consent_verified_by')
                    ->nullable()
                    ->after('consent_verified_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('trx_clinic_visits', function (Blueprint $table): void {
            if (Schema::hasColumn('trx_clinic_visits', 'consent_verified_by')) {
                $table->dropConstrainedForeignId('consent_verified_by');
            }

            foreach (['consent_verified_at', 'consent_signed_by_doctor', 'consent_signed_by_patient'] as $column) {
                if (Schema::hasColumn('trx_clinic_visits', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
