<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGACY-RME-PDF-ROLL-4 — attribute a staged document to the wave that accepted
 * it.
 *
 * WHY THIS COLUMN IS NECESSARY. Reconciliation asks "of everything this wave
 * accepted, what happened to it?". Without an explicit attribution the only way
 * to answer is to guess from a branch plus a date window — and a date window is
 * wrong at exactly the moments it matters: a wave that spans midnight, two waves
 * that touch the same branch, a document uploaded on the day a wave was drained.
 * A migration that cannot say which documents belonged to it cannot be signed
 * off, so the attribution is recorded rather than inferred.
 *
 * ADDITIVE AND NULLABLE. Every ROLL-1/2/3 row keeps NULL, and NULL means
 * "accepted before wave attribution existed" — never "belongs to the current
 * wave". Reconciliation for a wave counts only rows carrying that wave's id, so
 * a historical import can never be pulled into a later wave's balance.
 *
 * NOT AN AUTHORIZATION FIELD. Unlike `origin_branch_id` (which decides row
 * visibility and is therefore derived from the patient's Nomor RM), this column
 * is operational provenance. No policy and no repository scope reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stg_rme_legacy_imports')) {
            return;
        }

        if (Schema::hasColumn('stg_rme_legacy_imports', 'migration_wave_id')) {
            return;
        }

        Schema::table('stg_rme_legacy_imports', function (Blueprint $table): void {
            $table->foreignId('migration_wave_id')
                ->nullable()
                ->after('origin_branch_id')
                ->constrained('ops_rme_legacy_migration_waves')
                ->cascadeOnUpdate()
                // A wave is deleted only if it never accepted anything; a wave
                // that did is evidence and stays. Restricting here makes that a
                // database guarantee rather than a convention.
                ->restrictOnDelete();

            $table->index(['migration_wave_id', 'status'], 'stg_rme_legacy_imports_wave_status_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stg_rme_legacy_imports')) {
            return;
        }

        if (! Schema::hasColumn('stg_rme_legacy_imports', 'migration_wave_id')) {
            return;
        }

        Schema::table('stg_rme_legacy_imports', function (Blueprint $table): void {
            $table->dropIndex('stg_rme_legacy_imports_wave_status_idx');
            $table->dropConstrainedForeignId('migration_wave_id');
        });
    }
};
