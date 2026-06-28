<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 64.0 — Patient-Centric RM Workspace.
 *
 * Additive only. `trx_medical_records` stays the per-sheet table and keeps its
 * UNIQUE(clinic_visit_id) constraint (one sheet per visit). These nullable
 * columns record, for audit, which patient workspace a sheet belongs to:
 *  - canonical_visit_id : the workspace anchor (earliest non-cancelled RME
 *    visit with an MR). Cache only — the resolver is the live source of truth.
 *  - source_visit_id    : the visit a sheet was created from (== clinic_visit_id
 *    under one-sheet-per-visit; kept for forward-compat / audit clarity).
 *  - sheet_number       : per-patient sheet ordinal (display/audit).
 *
 * No drops, no NOT NULL, no data deletion, no backfill. Existing rows keep
 * working with NULLs (the resolver derives the anchor at runtime).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_medical_records', function (Blueprint $table) {
            $table->foreignId('canonical_visit_id')->nullable()->after('clinic_visit_id')
                ->constrained('trx_clinic_visits')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('source_visit_id')->nullable()->after('canonical_visit_id')
                ->constrained('trx_clinic_visits')->cascadeOnUpdate()->nullOnDelete();
            $table->unsignedInteger('sheet_number')->nullable()->after('source_visit_id');

            $table->index('sheet_number');
        });
    }

    public function down(): void
    {
        Schema::table('trx_medical_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('canonical_visit_id');
            $table->dropConstrainedForeignId('source_visit_id');
            $table->dropIndex(['sheet_number']);
            $table->dropColumn('sheet_number');
        });
    }
};
