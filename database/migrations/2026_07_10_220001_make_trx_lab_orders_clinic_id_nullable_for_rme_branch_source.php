<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX-LAB-REQUEST-RME-BRANCH-SEARCHABLE-FIELDS — Klinik on the V2 branch lab
 * request now means "Cabang RME" (mst_branches with is_rme_enabled = true),
 * mirroring Sprint 23 Phase 23.9.1 which already made trx_clinic_visits and
 * mst_patients clinic_id nullable for the same reason.
 *
 * V2 branch-source lab orders carry the canonical branch_id and no longer
 * select a legacy mst_clinics row, so trx_lab_orders.clinic_id must be
 * allowed to be null. This also fixes a latent constraint violation in the
 * RME candidate conversion path, which already passes the (nullable)
 * clinic_id of the originating RME visit into createV2Draft().
 *
 * Additive + non-destructive:
 *   - mst_clinics is NOT dropped.
 *   - The clinic_id column, its index, and its foreign key are kept.
 *   - Existing rows (which already carry a clinic_id) are untouched.
 * Only the NOT NULL constraint is relaxed so V2 branch orders can omit it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_lab_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('clinic_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverting to NOT NULL is only safe when no V2 rows left clinic_id null.
        Schema::table('trx_lab_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('clinic_id')->nullable(false)->change();
        });
    }
};
