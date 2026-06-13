<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 23 Phase 23.10.2 — Odontogram Additional Conditions input fix.
 *
 * Additive only. Adds a general odontogram-level "additional conditions"
 * column so doctors/nurses can record clinical conditions that are not tied
 * to a single tooth (e.g. impaksi, mobilitas, kondisi jaringan lunak).
 *
 * The existing `summary_notes` column is reused for "Catatan Odontogram";
 * no notes column is added. No backfill, no data rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trx_odontograms', function (Blueprint $table) {
            if (! Schema::hasColumn('trx_odontograms', 'additional_conditions')) {
                $table->text('additional_conditions')->nullable()->after('summary_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trx_odontograms', function (Blueprint $table) {
            if (Schema::hasColumn('trx_odontograms', 'additional_conditions')) {
                $table->dropColumn('additional_conditions');
            }
        });
    }
};
