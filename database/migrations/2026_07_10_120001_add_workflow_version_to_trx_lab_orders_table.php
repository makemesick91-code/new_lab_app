<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAB-WORKFLOW-V2 (Phase 1) — Introduce a workflow-version discriminator on
 * trx_lab_orders so the legacy Sprint 3-7 Lab pipeline and the new
 * Lab Workflow V2 state machine can coexist safely.
 *
 * Strategy (parallel engine, legacy read-only):
 *  - ADDITIVE only. No column dropped, no data rewritten destructively.
 *  - workflow_version is nullable + indexed. Existing rows are backfilled to
 *    LEGACY (1) so their readable history + inline transitions are preserved.
 *  - New orders are stamped by LabOrderService/resolver at creation time
 *    (V2 = 2 only once the lab.workflow_v2 feature flag is enabled).
 *  - NULL is treated as LEGACY at the model layer, so factory/legacy rows that
 *    predate an explicit stamp keep working unchanged.
 *
 * PostgreSQL + SQLite safe. Never migrate:fresh / db:wipe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('trx_lab_orders', 'workflow_version')) {
            Schema::table('trx_lab_orders', function (Blueprint $table) {
                $table->unsignedTinyInteger('workflow_version')->nullable()->after('status');
                $table->index(['workflow_version', 'status'], 'trx_lab_orders_workflow_status_index');
            });
        }

        // Deterministic, chunked, PG+SQLite-safe backfill: every pre-existing
        // Lab order predates V2 and is LEGACY. Only touch NULL rows.
        DB::table('trx_lab_orders')
            ->whereNull('workflow_version')
            ->update(['workflow_version' => 1]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('trx_lab_orders', 'workflow_version')) {
            Schema::table('trx_lab_orders', function (Blueprint $table) {
                $table->dropIndex('trx_lab_orders_workflow_status_index');
                $table->dropColumn('workflow_version');
            });
        }
    }
};
