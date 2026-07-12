<?php

namespace App\Modules\LabOrder\Services;

use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * LAB-PROD-2 — Operational Analytics KPI readiness auditor.
 *
 * Read-only integrity + contract audit for the operational KPI registry and its
 * canonical data sources. Emits per-check PASS/WARN/FAIL and an aggregate
 * GO/WATCH/NO_GO decision, PII-free. Backs both the audit command and the
 * GO/NO-GO command so they can never disagree.
 *
 * Decision:
 *   NO_GO  — any FAIL (missing required source column, unknown workflow status
 *            present in data, impossible/negative durations, duplicate metric key).
 *   WATCH  — no FAIL but >=1 WARN (permission not yet seeded, orders missing a
 *            branch/timestamp, or no V2 data to analyse — optional/coverage).
 *   GO     — no FAIL, no WARN.
 */
class LabOperationalKpiAuditService
{
    private const PASS = 'PASS';

    private const WARN = 'WARN';

    private const FAIL = 'FAIL';

    /**
     * Required canonical sources: table => required columns.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_SOURCES = [
        'trx_lab_orders' => ['id', 'branch_id', 'status', 'workflow_version', 'order_date', 'due_date'],
        'trx_lab_order_status_logs' => ['lab_order_id', 'new_status', 'changed_at'],
        'trx_lab_order_assignments' => ['lab_order_id', 'technician_id', 'assigned_at', 'completed_at'],
        'trx_lab_external_dispatches' => ['lab_order_id', 'sent_at', 'returned_at'],
        'trx_lab_model_analyses' => ['lab_order_id', 'decision', 'analyzed_at'],
        'trx_lab_delivery_tasks' => ['lab_order_id', 'delivered_at'],
    ];

    /**
     * Run the full audit.
     *
     * @return array{decision: string, checks: list<array{key: string, status: string, detail: string, count: int}>, generated_at: Carbon}
     */
    public function audit(): array
    {
        $checks = [];

        $checks[] = $this->checkMetricRegistry();
        $checks[] = $this->checkNoDuplicateMetricKeys();
        $checks = array_merge($checks, $this->checkSourceColumns());
        $checks[] = $this->checkUnknownStatus();
        $checks[] = $this->checkNegativeAssignmentDurations();
        $checks[] = $this->checkNegativeDispatchDurations();
        $checks[] = $this->checkBranchScope();
        $checks[] = $this->checkStatusLogTimestamps();
        $checks[] = $this->checkPermissions();
        $checks[] = $this->checkV2DataPresence();

        $hasFail = collect($checks)->contains(fn ($c) => $c['status'] === self::FAIL);
        $hasWarn = collect($checks)->contains(fn ($c) => $c['status'] === self::WARN);

        $decision = $hasFail ? 'NO_GO' : ($hasWarn ? 'WATCH' : 'GO');

        return [
            'decision' => $decision,
            'checks' => $checks,
            'generated_at' => now(),
        ];
    }

    /**
     * @return array{key: string, status: string, detail: string, count: int}
     */
    private function checkMetricRegistry(): array
    {
        $metrics = config('lab_operational_analytics.metrics', []);
        $required = ['label', 'group', 'source', 'formula', 'denominator', 'exclusions', 'support'];

        $invalid = 0;
        foreach ($metrics as $def) {
            foreach ($required as $key) {
                if (! array_key_exists($key, $def)) {
                    $invalid++;
                    break;
                }
            }
        }

        return $this->result(
            'metric_registry_valid',
            $metrics !== [] && $invalid === 0 ? self::PASS : self::FAIL,
            $metrics === [] ? 'Registry KPI kosong.' : ($invalid === 0 ? count($metrics).' metrik terdaftar valid.' : "$invalid metrik kekurangan field wajib."),
            $invalid,
        );
    }

    private function checkNoDuplicateMetricKeys(): array
    {
        $metrics = config('lab_operational_analytics.metrics', []);
        $labels = array_map(fn ($m) => $m['label'] ?? '', $metrics);
        $dupes = count($labels) - count(array_unique($labels));

        return $this->result(
            'duplicate_metric_key',
            $dupes === 0 ? self::PASS : self::FAIL,
            $dupes === 0 ? 'Tidak ada label metrik duplikat.' : "$dupes label metrik duplikat.",
            $dupes,
        );
    }

    /**
     * @return list<array{key: string, status: string, detail: string, count: int}>
     */
    private function checkSourceColumns(): array
    {
        $results = [];
        foreach (self::REQUIRED_SOURCES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $results[] = $this->result("source_table_$table", self::FAIL, "Tabel sumber $table tidak ada.", 1);

                continue;
            }
            $missing = array_values(array_filter($columns, fn ($c) => ! Schema::hasColumn($table, $c)));
            $results[] = $this->result(
                "source_columns_$table",
                $missing === [] ? self::PASS : self::FAIL,
                $missing === [] ? "$table: semua kolom sumber ada." : "$table kolom hilang: ".implode(', ', $missing),
                count($missing),
            );
        }

        return $results;
    }

    private function checkUnknownStatus(): array
    {
        if (! $this->columns('trx_lab_orders', ['status', 'workflow_version'])) {
            return $this->result('unknown_status', self::WARN, 'Kolom order belum tersedia (schema lama).', 0);
        }

        $known = LabWorkflowState::all();
        $unknown = LabOrder::query()
            ->where('workflow_version', LabOrder::WORKFLOW_V2)
            ->whereNotIn('status', $known)
            ->count();

        return $this->result(
            'unknown_status',
            $unknown === 0 ? self::PASS : self::FAIL,
            $unknown === 0 ? 'Semua status order V2 dikenal.' : "$unknown order V2 memiliki status tak dikenal.",
            $unknown,
        );
    }

    private function checkNegativeAssignmentDurations(): array
    {
        if (! $this->columns('trx_lab_order_assignments', ['completed_at', 'assigned_at'])) {
            return $this->result('negative_assignment_duration', self::WARN, 'Kolom assignment belum tersedia.', 0);
        }

        $negative = DB::table('trx_lab_order_assignments')
            ->whereNotNull('completed_at')
            ->whereNotNull('assigned_at')
            ->whereColumn('completed_at', '<', 'assigned_at')
            ->count();

        return $this->result(
            'negative_assignment_duration',
            $negative === 0 ? self::PASS : self::FAIL,
            $negative === 0 ? 'Tidak ada durasi assignment negatif.' : "$negative assignment selesai sebelum ditugaskan.",
            $negative,
        );
    }

    private function checkNegativeDispatchDurations(): array
    {
        if (! $this->columns('trx_lab_external_dispatches', ['returned_at', 'sent_at'])) {
            return $this->result('negative_dispatch_duration', self::WARN, 'Kolom dispatch belum tersedia.', 0);
        }

        $negative = DB::table('trx_lab_external_dispatches')
            ->whereNotNull('returned_at')
            ->whereNotNull('sent_at')
            ->whereColumn('returned_at', '<', 'sent_at')
            ->count();

        return $this->result(
            'negative_dispatch_duration',
            $negative === 0 ? self::PASS : self::FAIL,
            $negative === 0 ? 'Tidak ada turnaround eksternal negatif.' : "$negative dispatch kembali sebelum dikirim.",
            $negative,
        );
    }

    private function checkBranchScope(): array
    {
        if (! $this->columns('trx_lab_orders', ['branch_id', 'workflow_version'])) {
            return $this->result('branch_scope', self::WARN, 'Kolom order belum tersedia.', 0);
        }

        $missing = LabOrder::query()
            ->where('workflow_version', LabOrder::WORKFLOW_V2)
            ->whereNull('branch_id')
            ->count();

        return $this->result(
            'branch_scope',
            $missing === 0 ? self::PASS : self::WARN,
            $missing === 0 ? 'Semua order V2 punya branch (dapat di-scope).' : "$missing order V2 tanpa branch_id (dikecualikan dari scope cabang).",
            $missing,
        );
    }

    private function checkStatusLogTimestamps(): array
    {
        if (! $this->columns('trx_lab_order_status_logs', ['changed_at'])) {
            return $this->result('status_log_timestamps', self::WARN, 'Kolom status log belum tersedia.', 0);
        }

        $missing = DB::table('trx_lab_order_status_logs')->whereNull('changed_at')->count();

        return $this->result(
            'status_log_timestamps',
            $missing === 0 ? self::PASS : self::WARN,
            $missing === 0 ? 'Semua status log punya changed_at.' : "$missing status log tanpa changed_at (dikecualikan dari cycle time).",
            $missing,
        );
    }

    private function checkPermissions(): array
    {
        $perms = config('lab_operational_analytics.permissions');
        $names = [$perms['full'], $perms['own']];

        try {
            $found = Permission::whereIn('name', $names)->count();
        } catch (\Throwable) {
            return $this->result('permissions_registered', self::WARN, 'Tabel permission tidak dapat dibaca.', 0);
        }

        $expected = count($names);

        return $this->result(
            'permissions_registered',
            $found === $expected ? self::PASS : self::WARN,
            $found === $expected ? 'Permission analitik terdaftar.' : ($expected - $found).' permission analitik belum di-seed (jalankan PermissionSeeder).',
            $expected - $found,
        );
    }

    private function checkV2DataPresence(): array
    {
        if (! $this->columns('trx_lab_orders', ['workflow_version'])) {
            return $this->result('v2_data_presence', self::WARN, 'Kolom order belum tersedia.', 0);
        }

        $count = LabOrder::query()->where('workflow_version', LabOrder::WORKFLOW_V2)->count();

        return $this->result(
            'v2_data_presence',
            $count > 0 ? self::PASS : self::WARN,
            $count > 0 ? "$count order V2 tersedia untuk analitik." : 'Belum ada order V2 (KPI akan kosong sampai ada data — bukan angka palsu).',
            $count,
        );
    }

    /**
     * @param  list<string>  $columns
     */
    private function columns(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{key: string, status: string, detail: string, count: int}
     */
    private function result(string $key, string $status, string $detail, int $count): array
    {
        return ['key' => $key, 'status' => $status, 'detail' => $detail, 'count' => $count];
    }
}
