<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEATURE-RME-BRANCH-TARIFF-PROVISIONING-1 (owner D21 / D21h) — APPROVAL
 * PROVENANCE of a branch tariff.
 *
 *   approval_reference    non-PHI reference of the owner approval the price came
 *                         from (e.g. OWNER-TARIFF-SPN4-2026-10-20). It points at
 *                         evidence held outside the system; it is not the evidence.
 *   approval_recorded_by  the account that recorded it through the governed import
 *   approval_recorded_at  when it was recorded
 *
 * WHY. Production TLK1 held 11 tariffs byte-identical to the Sprint 19
 * TariffSeeder development defaults — one second, no audit row, no source —
 * and readiness counted them like an owner-approved list. Without a recorded
 * approval, "this branch is priced" and "this branch carries placeholder
 * prices" were indistinguishable.
 *
 * ADDITIVE ONLY. Every column is nullable and NOTHING IS BACKFILLED: a tariff
 * created before this release keeps NULL, which reads "approval not recorded".
 * Inventing an approval for history would turn an unknown into a false fact.
 * The columns are not mass-assignable; only the import writes them. Never
 * migrate:fresh / db:wipe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mst_tariffs', function (Blueprint $table) {
            $table->string('approval_reference', 120)->nullable()->after('notes');
            $table->foreignId('approval_recorded_by')->nullable()->after('approval_reference')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approval_recorded_at')->nullable()->after('approval_recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('mst_tariffs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_recorded_by');
            $table->dropColumn(['approval_reference', 'approval_recorded_at']);
        });
    }
};
