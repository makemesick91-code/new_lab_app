<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4D — link branch pilot profiles to a wave + UAT posture.
 *
 * Additive, nullable, default-safe columns. Legacy 4C rows keep NULLs; no
 * backfill, no NOT NULL, no drop. active_wave_id is a convenience pointer to the
 * branch's current active wave membership; the membership table remains the
 * source of truth.
 *
 * Additive only (`migrate` — never migrate:fresh/db:wipe on the VPS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mst_satusehat_branch_pilot_profiles')) {
            return;
        }

        Schema::table('mst_satusehat_branch_pilot_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('mst_satusehat_branch_pilot_profiles', 'active_wave_id')) {
                $table->foreignId('active_wave_id')->nullable()->after('readiness_stage')
                    ->constrained('mst_satusehat_rollout_waves')->nullOnDelete();
            }
            if (! Schema::hasColumn('mst_satusehat_branch_pilot_profiles', 'uat_status')) {
                // not_started | scheduled | in_progress | passed | failed
                $table->string('uat_status', 30)->nullable()->after('active_wave_id');
            }
            if (! Schema::hasColumn('mst_satusehat_branch_pilot_profiles', 'last_uat_signed_off_at')) {
                $table->timestamp('last_uat_signed_off_at')->nullable()->after('uat_status');
            }
            if (! Schema::hasColumn('mst_satusehat_branch_pilot_profiles', 'last_transition_at')) {
                $table->timestamp('last_transition_at')->nullable()->after('last_uat_signed_off_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mst_satusehat_branch_pilot_profiles')) {
            return;
        }

        Schema::table('mst_satusehat_branch_pilot_profiles', function (Blueprint $table) {
            foreach (['last_transition_at', 'last_uat_signed_off_at', 'uat_status', 'active_wave_id'] as $col) {
                if (Schema::hasColumn('mst_satusehat_branch_pilot_profiles', $col)) {
                    if ($col === 'active_wave_id') {
                        $table->dropConstrainedForeignId('active_wave_id');
                    } else {
                        $table->dropColumn($col);
                    }
                }
            }
        });
    }
};
