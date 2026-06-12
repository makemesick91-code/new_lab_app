<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 23 Phase 23.5 — Master Data Cabang serves RME and Inventory only.
 *
 * Additive only. Adds enablement flags so the branch master can declare which
 * multi-branch module(s) a branch participates in. Lab is global and is NOT
 * represented here on purpose. Existing branches default to enabled for both
 * RME and Inventory so current pilot data keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mst_branches', function (Blueprint $table): void {
            if (! Schema::hasColumn('mst_branches', 'is_rme_enabled')) {
                $table->boolean('is_rme_enabled')->default(true)->after('is_active');
            }
            if (! Schema::hasColumn('mst_branches', 'is_inventory_enabled')) {
                $table->boolean('is_inventory_enabled')->default(true)->after('is_rme_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mst_branches', function (Blueprint $table): void {
            foreach (['is_rme_enabled', 'is_inventory_enabled'] as $column) {
                if (Schema::hasColumn('mst_branches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
