<?php

namespace App\Modules\LabCapacity\Services;

use App\Modules\LabCapacity\Interfaces\LabTechnicianCapacityRepositoryInterface;
use App\Modules\LabCapacity\Models\LabServiceWorkloadProfile;
use App\Modules\LabCapacity\Models\TechnicianAvailabilityOverride;
use App\Modules\LabCapacity\Models\TechnicianCapability;
use App\Modules\LabCapacity\Models\TechnicianCapacityProfile;
use App\Modules\LabOrder\Interfaces\LabOperationalAnalyticsRepositoryInterface;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\LabService\Models\LabService;
use App\Modules\Technician\Models\Technician;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * LAB-PROD-3 — Technician Capacity Planning readiness auditor.
 *
 * Read-only integrity + contract audit. Emits per-check PASS/WARN/FAIL and an
 * aggregate GO/WATCH/NO_GO. PII-free. Backs both the audit command and the
 * GO/NO-GO command so they can never disagree.
 *
 * Decision: NO_GO on any FAIL (missing config table, negative capacity/workload,
 * invalid/overlapping effective windows, orphan relations, unknown workflow
 * status in data, broken LAB-PROD-2 dependency). WATCH on WARN only (coverage:
 * technicians/services without profiles, permissions/routes not yet seeded,
 * invalid availability override). GO when clean.
 */
class LabTechnicianCapacityAuditService
{
    private const PASS = 'PASS';

    private const WARN = 'WARN';

    private const FAIL = 'FAIL';

    /** @var array<string, list<string>> */
    private const REQUIRED_SOURCES = [
        'mst_lab_technician_capacity_profiles' => ['technician_id', 'planning_unit', 'daily_capacity', 'effective_from', 'is_active'],
        'mst_lab_service_workload_profiles' => ['lab_service_id', 'planning_unit', 'planned_workload', 'effective_from', 'is_active'],
        'mst_lab_technician_capabilities' => ['technician_id', 'lab_service_id', 'is_eligible', 'effective_from'],
        'mst_lab_technician_availability_overrides' => ['technician_id', 'override_date', 'reason_category'],
    ];

    public function __construct(
        private LabTechnicianCapacityRepositoryInterface $repository,
    ) {}

    /**
     * @return array{decision: string, checks: list<array{key: string, status: string, detail: string, count: int}>, generated_at: Carbon}
     */
    public function audit(): array
    {
        $checks = [];
        $checks[] = $this->checkFeatureConfig();
        $checks = array_merge($checks, $this->checkSourceTables());

        // Only run data checks when the tables exist (avoid crashing pre-migration).
        if ($this->tablesPresent()) {
            $checks[] = $this->checkNegativeCapacity();
            $checks[] = $this->checkNegativeWorkload();
            $checks[] = $this->checkInvalidEffectiveDates();
            $checks[] = $this->checkOverlappingCapacityProfiles();
            $checks[] = $this->checkOverlappingWorkloadProfiles();
            $checks[] = $this->checkOrphanTechnicianRelations();
            $checks[] = $this->checkOrphanServiceRelations();
            $checks[] = $this->checkAvailabilityOverrides();
            $checks[] = $this->checkActiveTechniciansWithoutProfile();
            $checks[] = $this->checkActiveServicesWithoutWorkload();
            $checks[] = $this->checkInactiveTechnicianActiveProfile();
        }

        $checks[] = $this->checkUnknownStatus();
        $checks[] = $this->checkPermissions();
        $checks[] = $this->checkRoutes();
        $checks[] = $this->checkLabProd2Dependency();

        $hasFail = collect($checks)->contains(fn ($c) => $c['status'] === self::FAIL);
        $hasWarn = collect($checks)->contains(fn ($c) => $c['status'] === self::WARN);

        return [
            'decision' => $hasFail ? 'NO_GO' : ($hasWarn ? 'WATCH' : 'GO'),
            'checks' => $checks,
            'generated_at' => now(),
        ];
    }

    private function tablesPresent(): bool
    {
        foreach (array_keys(self::REQUIRED_SOURCES) as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private function checkFeatureConfig(): array
    {
        $cfg = config('lab_technician_capacity');
        $problems = [];
        if (! is_bool($cfg['enabled'] ?? null)) {
            $problems[] = 'enabled bukan boolean';
        }
        if (! in_array($cfg['planning_unit'] ?? null, $cfg['allowed_planning_units'] ?? [], true)) {
            $problems[] = 'planning_unit tidak valid';
        }
        if (($cfg['utilization']['watch_at'] ?? 0) > ($cfg['utilization']['over_at'] ?? 0)) {
            $problems[] = 'ambang utilisasi tidak berurutan (watch_at > over_at)';
        }
        if (empty($cfg['horizons']) || ($cfg['max_horizon_days'] ?? 0) <= 0) {
            $problems[] = 'horizon tidak valid';
        }

        return $this->result('feature_config_valid', $problems === [] ? self::PASS : self::FAIL,
            $problems === [] ? 'Konfigurasi fitur valid.' : implode('; ', $problems), count($problems));
    }

    private function checkSourceTables(): array
    {
        $results = [];
        foreach (self::REQUIRED_SOURCES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $results[] = $this->result("source_table_$table", self::FAIL, "Tabel $table tidak ada.", 1);

                continue;
            }
            $missing = array_values(array_filter($columns, fn ($c) => ! Schema::hasColumn($table, $c)));
            $results[] = $this->result("source_columns_$table", $missing === [] ? self::PASS : self::FAIL,
                $missing === [] ? "$table: kolom lengkap." : "$table kolom hilang: ".implode(', ', $missing), count($missing));
        }

        return $results;
    }

    private function checkNegativeCapacity(): array
    {
        $count = TechnicianCapacityProfile::query()->where('daily_capacity', '<', 0)->count();

        return $this->result('negative_capacity', $count === 0 ? self::PASS : self::FAIL,
            $count === 0 ? 'Tidak ada kapasitas negatif.' : "$count profil kapasitas negatif.", $count);
    }

    private function checkNegativeWorkload(): array
    {
        $count = LabServiceWorkloadProfile::query()->where('planned_workload', '<', 0)->count();

        return $this->result('negative_workload', $count === 0 ? self::PASS : self::FAIL,
            $count === 0 ? 'Tidak ada workload negatif.' : "$count profil workload negatif.", $count);
    }

    private function checkInvalidEffectiveDates(): array
    {
        $count = TechnicianCapacityProfile::query()->whereNotNull('effective_until')->whereColumn('effective_until', '<', 'effective_from')->count()
            + LabServiceWorkloadProfile::query()->whereNotNull('effective_until')->whereColumn('effective_until', '<', 'effective_from')->count()
            + TechnicianCapability::query()->whereNotNull('effective_until')->whereColumn('effective_until', '<', 'effective_from')->count();

        return $this->result('invalid_effective_dates', $count === 0 ? self::PASS : self::FAIL,
            $count === 0 ? 'Rentang efektif valid.' : "$count baris effective_until < effective_from.", $count);
    }

    private function checkOverlappingCapacityProfiles(): array
    {
        $count = $this->countOverlaps(
            TechnicianCapacityProfile::query()->where('is_active', true)->get(),
            fn ($p) => $p->technician_id.'|'.$p->planning_unit
        );

        return $this->result('overlapping_capacity_profiles', $count === 0 ? self::PASS : self::FAIL,
            $count === 0 ? 'Tidak ada profil kapasitas tumpang tindih.' : "$count pasangan profil kapasitas tumpang tindih.", $count);
    }

    private function checkOverlappingWorkloadProfiles(): array
    {
        $count = $this->countOverlaps(
            LabServiceWorkloadProfile::query()->where('is_active', true)->get(),
            fn ($p) => $p->lab_service_id.'|'.$p->planning_unit
        );

        return $this->result('overlapping_workload_profiles', $count === 0 ? self::PASS : self::FAIL,
            $count === 0 ? 'Tidak ada profil workload tumpang tindih.' : "$count pasangan profil workload tumpang tindih.", $count);
    }

    private function countOverlaps($rows, callable $keyFn): int
    {
        $count = 0;
        foreach ($rows->groupBy($keyFn) as $group) {
            $items = $group->values();
            for ($i = 0; $i < $items->count(); $i++) {
                for ($j = $i + 1; $j < $items->count(); $j++) {
                    if ($this->windowsOverlap($items[$i], $items[$j])) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    private function windowsOverlap($a, $b): bool
    {
        $aFrom = Carbon::parse($a->effective_from);
        $bFrom = Carbon::parse($b->effective_from);
        $aUntil = $a->effective_until ? Carbon::parse($a->effective_until) : null;
        $bUntil = $b->effective_until ? Carbon::parse($b->effective_until) : null;

        $aBeforeB = $aUntil !== null && $aUntil->lt($bFrom);
        $bBeforeA = $bUntil !== null && $bUntil->lt($aFrom);

        return ! ($aBeforeB || $bBeforeA);
    }

    private function checkOrphanTechnicianRelations(): array
    {
        $existing = Technician::query()->pluck('id')->all();
        $count = TechnicianCapacityProfile::query()->whereNotIn('technician_id', $existing)->count()
            + TechnicianCapability::query()->whereNotIn('technician_id', $existing)->count()
            + TechnicianAvailabilityOverride::query()->whereNotIn('technician_id', $existing)->count();

        return $this->result('orphan_technician_relations', $count === 0 ? self::PASS : self::FAIL,
            $count === 0 ? 'Tidak ada relasi teknisi yatim.' : "$count baris merujuk teknisi tidak ada.", $count);
    }

    private function checkOrphanServiceRelations(): array
    {
        $existing = LabService::query()->pluck('id')->all();
        $count = LabServiceWorkloadProfile::query()->whereNotIn('lab_service_id', $existing)->count()
            + TechnicianCapability::query()->whereNotIn('lab_service_id', $existing)->count();

        return $this->result('orphan_service_relations', $count === 0 ? self::PASS : self::FAIL,
            $count === 0 ? 'Tidak ada relasi layanan yatim.' : "$count baris merujuk layanan tidak ada.", $count);
    }

    private function checkAvailabilityOverrides(): array
    {
        $allowed = config('lab_technician_capacity.availability_reason_categories', []);
        $invalid = TechnicianAvailabilityOverride::query()
            ->where(function ($q) {
                $q->whereNull('capacity_override')->whereNull('capacity_reduction');
            })
            ->orWhere('capacity_reduction', '<', 0)
            ->orWhereNotIn('reason_category', $allowed)
            ->count();

        return $this->result('availability_overrides_valid', $invalid === 0 ? self::PASS : self::WARN,
            $invalid === 0 ? 'Override ketersediaan valid.' : "$invalid override tidak valid (nilai kosong/negatif/kategori).", $invalid);
    }

    private function checkActiveTechniciansWithoutProfile(): array
    {
        $techIds = Technician::query()->where('is_active', true)->pluck('id')->all();
        $withProfile = TechnicianCapacityProfile::query()->where('is_active', true)->distinct()->pluck('technician_id')->all();
        $missing = count(array_diff($techIds, $withProfile));

        return $this->result('active_technicians_without_profile', $missing === 0 ? self::PASS : self::WARN,
            $missing === 0 ? 'Semua teknisi aktif punya profil kapasitas.' : "$missing teknisi aktif tanpa profil kapasitas (UNCONFIGURED).", $missing);
    }

    private function checkActiveServicesWithoutWorkload(): array
    {
        $serviceIds = LabService::query()->where('is_active', true)->pluck('id')->all();
        $withProfile = LabServiceWorkloadProfile::query()->where('is_active', true)->distinct()->pluck('lab_service_id')->all();
        $missing = count(array_diff($serviceIds, $withProfile));

        return $this->result('active_services_without_workload', $missing === 0 ? self::PASS : self::WARN,
            $missing === 0 ? 'Semua layanan aktif punya profil workload.' : "$missing layanan aktif tanpa profil workload (UNPLANNABLE).", $missing);
    }

    private function checkInactiveTechnicianActiveProfile(): array
    {
        $inactive = Technician::query()->where('is_active', false)->pluck('id')->all();
        $count = TechnicianCapacityProfile::query()->where('is_active', true)->whereIn('technician_id', $inactive)->count();

        return $this->result('inactive_technician_active_profile', $count === 0 ? self::PASS : self::WARN,
            $count === 0 ? 'Tidak ada teknisi nonaktif dengan profil aktif.' : "$count profil aktif milik teknisi nonaktif.", $count);
    }

    private function checkUnknownStatus(): array
    {
        if (! Schema::hasTable('trx_lab_orders')) {
            return $this->result('unknown_workflow_status', self::WARN, 'Tabel order lab tidak ada.', 0);
        }
        $known = LabWorkflowState::all();
        $count = LabOrder::query()->where('workflow_version', LabOrder::WORKFLOW_V2)
            ->whereNotIn('status', $known)->count();

        return $this->result('unknown_workflow_status', $count === 0 ? self::PASS : self::FAIL,
            $count === 0 ? 'Semua status order V2 dikenali.' : "$count order V2 berstatus tidak dikenal.", $count);
    }

    private function checkPermissions(): array
    {
        $perms = array_values(config('lab_technician_capacity.permissions', []));
        $seeded = Permission::query()->whereIn('name', $perms)->pluck('name')->all();
        $missing = array_values(array_diff($perms, $seeded));

        return $this->result('permissions_seeded', $missing === [] ? self::PASS : self::WARN,
            $missing === [] ? 'Semua permission kapasitas terdaftar.' : 'Permission belum di-seed: '.implode(', ', $missing), count($missing));
    }

    private function checkRoutes(): array
    {
        $required = ['lab-capacity-planning.index', 'lab-capacity-planning.export'];
        $missing = array_values(array_filter($required, fn ($r) => ! Route::has($r)));

        return $this->result('routes_registered', $missing === [] ? self::PASS : self::WARN,
            $missing === [] ? 'Rute capacity planning terdaftar.' : 'Rute belum terdaftar: '.implode(', ', $missing), count($missing));
    }

    private function checkLabProd2Dependency(): array
    {
        $ok = config('lab_operational_analytics') !== null
            && app()->bound(LabOperationalAnalyticsRepositoryInterface::class);

        return $this->result('lab_prod_2_dependency', $ok ? self::PASS : self::FAIL,
            $ok ? 'Dependensi LAB-PROD-2 siap.' : 'Dependensi analytics LAB-PROD-2 tidak tersedia.', $ok ? 0 : 1);
    }

    /**
     * @return array{key: string, status: string, detail: string, count: int}
     */
    private function result(string $key, string $status, string $detail, int $count): array
    {
        return ['key' => $key, 'status' => $status, 'detail' => $detail, 'count' => $count];
    }
}
