<?php

namespace App\Services\Foundation;

use App\Modules\Branch\Services\BranchService;
use App\Support\DeveloperConsole\SensitiveValueMasker;
use App\Support\Health\HealthCheckService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * ROLL-5-1 — Five Branch Controlled Production Rollout Readiness.
 *
 * Read-only. Aggregates readiness signals for a CONTROLLED, staged rollout to
 * five clinic branches (Stage 1: 1 branch, Stage 2: 3 total, Stage 3: 5 total)
 * into ONE explainable decision: GO | WATCH | FAIL | UNKNOWN.
 *
 * Guarantees:
 *  - REUSES existing foundations — it embeds MON-1's collected signals for the
 *    app-health / storage / backup / evidence / audit categories rather than
 *    re-implementing health probes, deploy evidence, or domain audits.
 *  - Never throws out of collect(): every signal is independently guarded and
 *    degrades to UNKNOWN on error (missing file/table/permission).
 *  - Never exposes secrets, env values, DB credentials, raw stack traces,
 *    KTP/NIK, raw patient notes, or raw failed-job payloads — only sanitized
 *    counts/metadata.
 *  - Never mutates runtime state (no chmod, no queue retry, no data write).
 *  - Never trusts a request branch_id — branch counts come from BranchService.
 *  - Runs no expensive command / capacity probe on a web request unless the
 *    caller explicitly opts in (include_audits / capacity_smoke — CLI only).
 *
 * This certifies CONTROLLED 5-branch rollout readiness only — NOT national
 * scale, HA cluster, external pentest, or full DR certification.
 */
class FiveBranchRolloutReadinessService
{
    public const GO = 'GO';

    public const WATCH = 'WATCH';

    public const FAIL = 'FAIL';

    public const UNKNOWN = 'UNKNOWN';

    public function __construct(
        private readonly FoundationMonitoringStatusService $monitoring,
        private readonly BranchService $branches,
        private readonly HealthCheckService $health,
        private readonly SensitiveValueMasker $masker,
    ) {}

    /**
     * Collect all rollout readiness signals and compute the consolidated
     * decision (optionally focused on a rollout stage).
     *
     * @param  array{include_audits?: bool, capacity_smoke?: bool, stage?: int|null}  $options
     */
    public function collect(array $options = []): array
    {
        $includeAudits = (bool) ($options['include_audits'] ?? false);
        $capacitySmoke = (bool) ($options['capacity_smoke'] ?? false);
        $stage = $this->normalizeStage($options['stage'] ?? null);

        // Reuse MON-1 as the monitoring source of truth (never re-implemented).
        $mon = $this->safeMonitoringCollect($includeAudits);

        $signals = [
            $this->appHealthSignal($mon),
            $this->branchDataSignal($stage),
            $this->rolePermissionSignal(),
            $this->routeSurfaceSignal('rme_readiness'),
            $this->routeSurfaceSignal('cashier_payment_readiness'),
            $this->inventoryProcurementSignal(),
            $this->backupEvidenceSignal($mon),
            $this->restoreDrillSignal(),
            $this->monitoringReadinessSignal($mon),
            $this->auditCommandReadinessSignal($mon),
            $this->deployRollbackSignal(),
            $this->capacitySmokeSignal($capacitySmoke),
        ];

        $decision = $this->decide($signals);
        $unsafe = collect($signals)->contains(fn ($s) => ! empty($s['unsafe']));

        return [
            'sprint' => 'ROLL-5-1',
            'decision' => $decision,
            'unsafe' => $unsafe,
            'stage' => $stage,
            'target_branch_count' => (int) config('rollout_readiness.target_branch_count', 5),
            'generated_at' => now()->toIso8601String(),
            'include_audits' => $includeAudits,
            'capacity_smoke' => $capacitySmoke,
            'summary' => $this->summaryCounts($signals),
            'reasons' => $this->reasons($signals),
            'categories' => $this->categorySummary($signals),
            'stages' => $this->stageReadiness(),
            'monitoring_decision' => (string) ($mon['decision'] ?? self::UNKNOWN),
            'signals' => $signals,
        ];
    }

    /**
     * Pure aggregation of signal statuses into one decision. Unit-testable.
     *
     * @param  array<int, array{status?: string}>  $signals
     */
    public function decide(array $signals): string
    {
        $statuses = array_map(fn ($s) => (string) ($s['status'] ?? self::UNKNOWN), $signals);

        if (in_array(self::FAIL, $statuses, true)) {
            return self::FAIL;
        }
        if (in_array(self::WATCH, $statuses, true)) {
            return self::WATCH;
        }
        if ($statuses === [] || ! in_array(self::GO, $statuses, true)) {
            return self::UNKNOWN;
        }

        return self::GO;
    }

    // ------------------------------------------------------------------
    // Signals
    // ------------------------------------------------------------------

    /**
     * App health — derived from MON-1 runtime + health + storage signals.
     */
    private function appHealthSignal(array $mon): array
    {
        return $this->guard('app_health', 'app_health', 'Kesehatan Aplikasi', function () use ($mon) {
            $keys = ['app_runtime', 'health_live', 'health_ready', 'storage_cache_writable'];
            $picked = $this->pickMonitoringSignals($mon, $keys);

            // Fallback: if MON-1 provided no health signals (e.g. it failed to
            // collect), probe ENT-8 readiness directly so app_health is never
            // silently UNKNOWN when the app is actually reachable.
            if ($picked === []) {
                $overall = (string) ($this->health->readiness()['status'] ?? HealthCheckService::STATUS_DOWN);
                $status = match ($overall) {
                    HealthCheckService::STATUS_OK => self::GO,
                    HealthCheckService::STATUS_DEGRADED => self::WATCH,
                    default => self::FAIL,
                };

                return [
                    'status' => $status,
                    'unsafe' => $status === self::FAIL,
                    'summary' => 'health readiness (ENT-8 fallback): '.$overall,
                    'remediation' => $status === self::GO ? null : 'Periksa /health/ready dan proses PHP-FPM/DB.',
                    'details' => ['source' => 'health_check_fallback', 'overall' => $overall],
                ];
            }

            $status = $this->worstStatus(array_map(fn ($s) => (string) ($s['status'] ?? self::UNKNOWN), $picked));
            $unsafe = collect($picked)->contains(fn ($s) => ! empty($s['unsafe']));

            return [
                'status' => $status,
                'unsafe' => $unsafe,
                'summary' => 'runtime/health/storage: '.$this->statusPhrase($status),
                'remediation' => $status === self::GO
                    ? null
                    : 'Lihat detail di MON-1 (`php artisan foundation:monitoring-observability-check`).',
                'details' => ['from_monitoring' => array_map(fn ($s) => [
                    'key' => $s['key'] ?? null,
                    'status' => $s['status'] ?? null,
                    'summary' => $s['summary'] ?? null,
                ], $picked)],
            ];
        });
    }

    /**
     * Branch data readiness — RME-enabled active branch count vs stage / target.
     */
    private function branchDataSignal(?int $stage): array
    {
        return $this->guard('branch_data_readiness', 'branch_data_readiness', 'Kesiapan Data Cabang', function () use ($stage) {
            $rmeBranches = $this->branches->listRmeEnabled();
            $rmeCount = $rmeBranches->count();
            $activeCount = $this->branches->listActive()->count();
            $target = (int) config('rollout_readiness.target_branch_count', 5);

            $stageTarget = $stage !== null ? $this->stageBranchTarget($stage) : null;
            $need = $stageTarget ?? $target;

            $status = self::GO;
            $notes = [];

            if ($rmeCount <= 0) {
                $status = self::WATCH;
                $notes[] = 'Belum ada cabang RME aktif.';
            } elseif ($rmeCount < $need) {
                $status = self::WATCH;
                $notes[] = sprintf('Cabang RME aktif %d < kebutuhan %d.', $rmeCount, $need);
            }

            $label = $stage !== null
                ? sprintf('Stage %d butuh %d cabang; tersedia %d cabang RME aktif.', $stage, $need, $rmeCount)
                : sprintf('%d cabang RME aktif dari target %d.', $rmeCount, $target);

            return [
                'status' => $status,
                'unsafe' => false, // fewer branches is a WATCH, never an unsafe FAIL
                'summary' => $label,
                'remediation' => $status === self::GO
                    ? null
                    : 'Aktifkan/siapkan data cabang RME (is_active + is_rme_enabled) sebelum menaikkan stage.',
                'details' => [
                    'rme_enabled_active_branches' => $rmeCount,
                    'active_branches' => $activeCount,
                    'target_branch_count' => $target,
                    'stage' => $stage,
                    'stage_branch_target' => $stageTarget,
                    'notes' => $notes,
                ],
            ];
        });
    }

    /**
     * Role / permission readiness — required roles exist + no critical leak.
     */
    private function rolePermissionSignal(): array
    {
        return $this->guard('role_permission_readiness', 'role_permission_readiness', 'Kesiapan Peran & Izin', function () {
            if (! Schema::hasTable('roles')) {
                return [
                    'status' => self::UNKNOWN,
                    'unsafe' => false,
                    'summary' => 'tabel roles tidak ada',
                    'remediation' => 'Jalankan seeder RBAC (PermissionSeeder + RoleSeeder).',
                    'details' => ['roles_table' => false],
                ];
            }

            $required = (array) config('rollout_readiness.required_roles', []);
            $existing = Role::query()->pluck('name')->all();
            $missing = array_values(array_diff($required, $existing));

            $leaks = [];
            foreach ((array) config('rollout_readiness.role_permission_leaks', []) as $role => $forbidden) {
                $model = Role::query()->where('name', $role)->first();
                if ($model === null) {
                    continue;
                }
                foreach ((array) $forbidden as $permission) {
                    try {
                        if ($model->hasPermissionTo($permission)) {
                            $leaks[] = sprintf('%s memiliki izin terlarang %s', $role, $permission);
                        }
                    } catch (Throwable) {
                        // Permission not registered — not a leak.
                    }
                }
            }

            $status = self::GO;
            if ($leaks !== []) {
                $status = self::FAIL; // security boundary breach
            } elseif ($missing !== []) {
                $status = self::WATCH;
            }

            return [
                'status' => $status,
                'unsafe' => $leaks !== [],
                'summary' => $leaks !== []
                    ? 'kebocoran izin peran terdeteksi'
                    : ($missing !== [] ? 'peran wajib belum lengkap: '.implode(', ', $missing) : 'semua peran wajib ada, tanpa kebocoran izin'),
                'remediation' => $status === self::GO
                    ? null
                    : ($leaks !== []
                        ? 'Cabut izin PO dari Kepala Cabang; jalankan `inventory:procurement-workflow-audit --strict` untuk detail.'
                        : 'Seed peran yang hilang (RoleSeeder) sebelum rollout.'),
                'details' => [
                    'required_count' => count($required),
                    'missing_roles' => $missing,
                    'leaks' => $leaks,
                ],
            ];
        });
    }

    /**
     * Route surface readiness for a category (RME / Cashier / App health).
     */
    private function routeSurfaceSignal(string $category): array
    {
        $label = (string) config('rollout_readiness.categories.'.$category, $category);

        return $this->guard($category, $category, $label, function () use ($category) {
            $surfaces = (array) config('rollout_readiness.route_surfaces.'.$category, []);
            $missing = [];
            $present = [];

            foreach (array_keys($surfaces) as $name) {
                if (Route::has($name)) {
                    $present[] = $name;
                } else {
                    $missing[] = $name;
                }
            }

            $status = $missing === [] ? self::GO : self::WATCH;

            return [
                'status' => $status,
                'unsafe' => false,
                'summary' => $missing === []
                    ? 'semua route surface terdaftar ('.count($present).')'
                    : 'route belum terdaftar: '.implode(', ', $missing),
                'remediation' => $status === self::GO ? null : 'Pastikan modul terkait aktif dan route terdaftar.',
                'details' => ['present' => $present, 'missing' => $missing],
            ];
        });
    }

    /**
     * Inventory / procurement readiness — routes + room gate + audit command.
     */
    private function inventoryProcurementSignal(): array
    {
        return $this->guard('inventory_procurement_readiness', 'inventory_procurement_readiness', 'Kesiapan Inventory & Pengadaan', function () {
            $surfaces = (array) config('rollout_readiness.route_surfaces.inventory_procurement_readiness', []);
            $missing = [];
            foreach (array_keys($surfaces) as $name) {
                if (! Route::has($name)) {
                    $missing[] = $name;
                }
            }

            $auditRegistered = $this->commandRegistered('inventory:procurement-workflow-audit');
            $roomGate = $this->middlewareAliasRegistered((string) config('rollout_readiness.room_gate_middleware_alias', 'visit.room'));

            $status = ($missing === [] && $auditRegistered) ? self::GO : self::WATCH;

            $notes = [];
            if ($missing !== []) {
                $notes[] = 'route belum terdaftar: '.implode(', ', $missing);
            }
            if (! $auditRegistered) {
                $notes[] = 'perintah inventory:procurement-workflow-audit tidak terdaftar';
            }
            if (! $roomGate) {
                $notes[] = 'alias middleware visit.room tidak terdaftar (gate ruangan)';
            }

            return [
                'status' => $status,
                'unsafe' => false,
                'summary' => $notes === [] ? 'route inventory + audit pengadaan siap' : implode('; ', $notes),
                'remediation' => $status === self::GO ? null : 'Jalankan `inventory:procurement-workflow-audit --strict` dan pastikan route pengadaan aktif.',
                'details' => [
                    'missing_routes' => $missing,
                    'procurement_audit_registered' => $auditRegistered,
                    'room_gate_alias_registered' => $roomGate,
                ],
            ];
        });
    }

    /**
     * Backup evidence — reuse MON-1 deploy_backup signal.
     */
    private function backupEvidenceSignal(array $mon): array
    {
        return $this->guard('backup_evidence', 'backup_evidence', 'Bukti Backup', function () use ($mon) {
            $picked = $this->pickMonitoringSignals($mon, ['deploy_backup']);
            $inner = $picked[0] ?? null;

            if ($inner === null) {
                return [
                    'status' => self::WATCH,
                    'unsafe' => false,
                    'summary' => 'sinyal backup MON-1 tidak tersedia',
                    'remediation' => 'Jalankan MON-1 untuk memverifikasi backup.',
                    'details' => [],
                ];
            }

            return [
                'status' => (string) ($inner['status'] ?? self::WATCH),
                'unsafe' => false,
                'summary' => (string) ($inner['summary'] ?? 'status backup tidak diketahui'),
                'remediation' => $inner['remediation'] ?? null,
                'details' => (array) ($inner['details'] ?? []),
            ];
        });
    }

    /**
     * Restore-drill evidence — WATCH until a drill has been performed.
     */
    private function restoreDrillSignal(): array
    {
        return $this->guard('restore_drill_evidence', 'restore_drill_evidence', 'Bukti Uji Restore', function () {
            $candidates = (array) config('rollout_readiness.paths.restore_drill_evidence', []);
            $found = null;
            foreach ($candidates as $rel) {
                $abs = base_path($rel);
                if (is_file($abs) && filesize($abs) > 0) {
                    $found = $abs;
                    break;
                }
            }

            $runbook = base_path((string) config('rollout_readiness.paths.restore_drill_runbook'));
            $runbookPresent = is_file($runbook);

            if ($found === null) {
                return [
                    'status' => self::WATCH,
                    'unsafe' => false,
                    'summary' => $runbookPresent
                        ? 'belum ada bukti uji restore — jalankan drill sesuai runbook'
                        : 'belum ada bukti uji restore dan runbook belum tersedia',
                    'remediation' => 'Lakukan restore drill ke DB staging/test (bukan produksi) sesuai `'.config('rollout_readiness.paths.restore_drill_runbook').'`.',
                    'details' => ['evidence_present' => false, 'runbook_present' => $runbookPresent],
                ];
            }

            $ageHours = (time() - (int) filemtime($found)) / 3600;
            $staleHours = (float) config('rollout_readiness.thresholds.restore_drill_stale_hours', 720);
            $stale = $ageHours > $staleHours;

            return [
                'status' => $stale ? self::WATCH : self::GO,
                'unsafe' => false,
                'summary' => sprintf('bukti uji restore ada (%.1f jam lalu)%s', $ageHours, $stale ? ', sudah kedaluwarsa' : ''),
                'remediation' => $stale ? 'Ulangi restore drill; bukti terakhir sudah lama.' : null,
                'details' => [
                    'evidence_present' => true,
                    'evidence_file' => basename($found),
                    'evidence_age_hours' => round($ageHours, 1),
                    'runbook_present' => $runbookPresent,
                ],
            ];
        });
    }

    /**
     * Monitoring readiness — MON-1 command available + overall decision.
     */
    private function monitoringReadinessSignal(array $mon): array
    {
        return $this->guard('monitoring_readiness', 'monitoring_readiness', 'Kesiapan Monitoring (MON-1)', function () use ($mon) {
            $registered = $this->commandRegistered('foundation:monitoring-observability-check');
            $monDecision = strtoupper((string) ($mon['decision'] ?? self::UNKNOWN));

            $status = match (true) {
                ! $registered => self::WATCH,
                $monDecision === self::FAIL => self::FAIL,
                $monDecision === self::WATCH => self::WATCH,
                $monDecision === self::GO => self::GO,
                default => self::UNKNOWN,
            };

            return [
                'status' => $status,
                'unsafe' => $status === self::FAIL,
                'summary' => $registered
                    ? 'MON-1 tersedia; keputusan konsolidasi: '.$monDecision
                    : 'perintah MON-1 tidak terdaftar',
                'remediation' => $status === self::GO ? null : 'Jalankan `php artisan foundation:monitoring-observability-check --strict`.',
                'details' => ['command_registered' => $registered, 'monitoring_decision' => $monDecision],
            ];
        });
    }

    /**
     * Audit command readiness — reuse MON-1 audit signals (evidence or exec).
     */
    private function auditCommandReadinessSignal(array $mon): array
    {
        return $this->guard('audit_command_readiness', 'audit_command_readiness', 'Kesiapan Perintah Audit', function () use ($mon) {
            $auditKeys = ['audit_ci_runtime_control', 'audit_security_compliance', 'audit_inventory_procurement', 'audit_doctor_performance_access'];
            $picked = $this->pickMonitoringSignals($mon, $auditKeys);

            $registered = [
                'inventory:procurement-workflow-audit' => $this->commandRegistered('inventory:procurement-workflow-audit'),
                'rme:doctor-performance-access-audit' => $this->commandRegistered('rme:doctor-performance-access-audit'),
                'foundation:ci-runtime-control-check' => $this->commandRegistered('foundation:ci-runtime-control-check'),
                'foundation:security-compliance-check' => $this->commandRegistered('foundation:security-compliance-check'),
            ];
            $allRegistered = ! in_array(false, $registered, true);

            $statuses = array_map(fn ($s) => (string) ($s['status'] ?? self::UNKNOWN), $picked);
            $worst = $this->worstStatus($statuses);

            // Missing an audit command is a WATCH; an executed audit FAIL is a FAIL.
            $status = ! $allRegistered ? $this->escalate($worst, self::WATCH) : $worst;

            return [
                'status' => $status,
                'unsafe' => collect($picked)->contains(fn ($s) => ! empty($s['unsafe'])),
                'summary' => $allRegistered
                    ? 'semua perintah audit terdaftar; status: '.$this->statusPhrase($worst)
                    : 'sebagian perintah audit tidak terdaftar',
                'remediation' => $status === self::GO ? null : 'Jalankan perintah audit terkait dengan --strict untuk detail.',
                'details' => [
                    'commands_registered' => $registered,
                    'from_monitoring' => array_map(fn ($s) => [
                        'key' => $s['key'] ?? null,
                        'status' => $s['status'] ?? null,
                    ], $picked),
                ],
            ];
        });
    }

    /**
     * Deploy / rollback readiness — required scripts present.
     */
    private function deployRollbackSignal(): array
    {
        return $this->guard('deploy_rollback_readiness', 'deploy_rollback_readiness', 'Kesiapan Deploy & Rollback', function () {
            $scripts = (array) config('rollout_readiness.paths.deploy_scripts', []);
            $missing = [];
            foreach ($scripts as $rel) {
                if (! is_file(base_path($rel))) {
                    $missing[] = $rel;
                }
            }

            $rollbackCheck = $this->commandRegistered('foundation:deployment-rollback-check');
            $status = ($missing === [] && $rollbackCheck) ? self::GO : self::WATCH;

            return [
                'status' => $status,
                'unsafe' => false,
                'summary' => $missing === []
                    ? 'skrip deploy/rollback/backup lengkap'
                    : 'skrip belum ada: '.implode(', ', $missing),
                'remediation' => $status === self::GO ? null : 'Pastikan skrip ENT-11/12 tersedia dan gate rollback aktif.',
                'details' => ['missing_scripts' => $missing, 'rollback_check_registered' => $rollbackCheck],
            ];
        });
    }

    /**
     * Scope F — lightweight capacity smoke. Bounded read-only COUNT probes.
     * Skipped (UNKNOWN, non-blocking) unless the caller opts in.
     */
    private function capacitySmokeSignal(bool $enabled): array
    {
        return $this->guard('capacity_smoke', 'capacity_smoke', 'Uji Kapasitas Ringan', function () use ($enabled) {
            if (! $enabled) {
                return [
                    'status' => self::UNKNOWN,
                    'unsafe' => false,
                    'summary' => 'uji kapasitas ringan tidak dijalankan (opt-in via --capacity-smoke)',
                    'remediation' => 'Jalankan `php artisan rollout:five-branch-readiness --capacity-smoke` untuk sinyal kapasitas.',
                    'details' => ['executed' => false],
                ];
            }

            $probes = (array) config('rollout_readiness.capacity_probes', []);
            $watchMs = (float) config('rollout_readiness.thresholds.capacity_query_watch_ms', 1500);
            $failMs = (float) config('rollout_readiness.thresholds.capacity_query_fail_ms', 8000);

            $results = [];
            $status = self::GO;

            foreach ($probes as $probe) {
                $table = (string) ($probe['table'] ?? '');
                $probeLabel = (string) ($probe['label'] ?? $table);

                if ($table === '' || ! Schema::hasTable($table)) {
                    $results[] = ['label' => $probeLabel, 'table' => $table, 'status' => self::UNKNOWN, 'reason' => 'table missing'];

                    continue;
                }

                try {
                    $start = microtime(true);
                    $count = (int) DB::table($table)->count();
                    $ms = (microtime(true) - $start) * 1000;

                    $probeStatus = match (true) {
                        $ms >= $failMs => self::FAIL,
                        $ms >= $watchMs => self::WATCH,
                        default => self::GO,
                    };
                    $status = $this->worstStatus([$status, $probeStatus]);

                    $results[] = [
                        'label' => $probeLabel,
                        'table' => $table,
                        'status' => $probeStatus,
                        'rows' => $count,
                        'ms' => round($ms, 1),
                    ];
                } catch (Throwable) {
                    // A broken/timed-out query is a real capacity FAIL.
                    $status = self::FAIL;
                    $results[] = ['label' => $probeLabel, 'table' => $table, 'status' => self::FAIL, 'reason' => 'query error'];
                }
            }

            $unsafe = $status === self::FAIL;

            return [
                'status' => $status,
                'unsafe' => $unsafe,
                'summary' => 'uji kapasitas ringan: '.$this->statusPhrase($status).' ('.count($results).' probe)',
                'remediation' => $status === self::GO
                    ? null
                    : 'Query read lambat/gagal — periksa index (ENT-2), koneksi DB, dan beban. Ini uji ringan, bukan load test skala nasional.',
                'details' => ['executed' => true, 'note' => 'lightweight capacity smoke (bukan load test skala nasional)', 'probes' => $results],
            ];
        });
    }

    // ------------------------------------------------------------------
    // Stage readiness (branch-count based, WATCH when not yet met)
    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stageReadiness(): array
    {
        $out = [];

        try {
            $rmeCount = $this->branches->listRmeEnabled()->count();
        } catch (Throwable) {
            $rmeCount = null;
        }

        foreach ((array) config('rollout_readiness.stages', []) as $stage) {
            $target = (int) ($stage['branch_target'] ?? 0);
            if ($rmeCount === null) {
                $status = self::UNKNOWN;
            } elseif ($rmeCount >= $target) {
                $status = self::GO;
            } else {
                $status = self::WATCH;
            }

            $out[] = [
                'key' => $stage['key'] ?? null,
                'label' => $stage['label'] ?? null,
                'branch_target' => $target,
                'available_branches' => $rmeCount,
                'status' => $status,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function safeMonitoringCollect(bool $includeAudits): array
    {
        try {
            return $this->monitoring->collect(['include_audits' => $includeAudits]);
        } catch (Throwable) {
            return ['decision' => self::UNKNOWN, 'signals' => []];
        }
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, array<string, mixed>>
     */
    private function pickMonitoringSignals(array $mon, array $keys): array
    {
        $byKey = collect((array) ($mon['signals'] ?? []))->keyBy('key');

        $out = [];
        foreach ($keys as $key) {
            if ($byKey->has($key)) {
                $out[] = (array) $byKey->get($key);
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function worstStatus(array $statuses): string
    {
        if (in_array(self::FAIL, $statuses, true)) {
            return self::FAIL;
        }
        if (in_array(self::WATCH, $statuses, true)) {
            return self::WATCH;
        }
        if ($statuses !== [] && in_array(self::GO, $statuses, true)) {
            return self::GO;
        }

        return self::UNKNOWN;
    }

    /**
     * Escalate a status to at least $floor (FAIL > WATCH > GO/UNKNOWN order).
     */
    private function escalate(string $status, string $floor): string
    {
        if ($status === self::FAIL) {
            return self::FAIL;
        }
        if ($floor === self::WATCH && $status !== self::FAIL) {
            return self::WATCH;
        }

        return $status;
    }

    private function statusPhrase(string $status): string
    {
        return match ($status) {
            self::GO => 'siap',
            self::WATCH => 'perlu perhatian',
            self::FAIL => 'gagal',
            default => 'tidak diketahui',
        };
    }

    private function commandRegistered(string $name): bool
    {
        try {
            return array_key_exists($name, Artisan::all());
        } catch (Throwable) {
            return false;
        }
    }

    private function middlewareAliasRegistered(string $alias): bool
    {
        try {
            // Resolve the HTTP Kernel first — in console/Artisan context the
            // router's middleware aliases (registered in bootstrap/app.php via
            // $middleware->alias()) are not populated until the HTTP Kernel is
            // built. Without this the alias check false-negatives on the CLI.
            app(Kernel::class);

            $router = app('router');
            $aliases = method_exists($router, 'getMiddleware') ? (array) $router->getMiddleware() : [];

            return array_key_exists($alias, $aliases);
        } catch (Throwable) {
            return false;
        }
    }

    private function normalizeStage(mixed $stage): ?int
    {
        if ($stage === null || $stage === '') {
            return null;
        }
        $n = (int) $stage;

        return in_array($n, [1, 2, 3], true) ? $n : null;
    }

    private function stageBranchTarget(int $stage): ?int
    {
        $target = config('rollout_readiness.stages.stage_'.$stage.'.branch_target');

        return $target !== null ? (int) $target : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @return array<string, array<string, mixed>>
     */
    private function categorySummary(array $signals): array
    {
        $out = [];
        foreach ($signals as $signal) {
            $category = (string) ($signal['category'] ?? 'lainnya');
            $out[$category] = [
                'label' => (string) config('rollout_readiness.categories.'.$category, $category),
                'status' => (string) ($signal['status'] ?? self::UNKNOWN),
                'summary' => (string) ($signal['summary'] ?? ''),
            ];
        }

        return $out;
    }

    private function guard(string $key, string $category, string $label, callable $producer): array
    {
        try {
            $signal = $producer();
        } catch (Throwable $e) {
            $signal = [
                'status' => self::UNKNOWN,
                'unsafe' => false,
                'summary' => 'signal tidak dapat dievaluasi',
                'remediation' => 'Periksa log; signal ini gagal dievaluasi dengan aman.',
                'details' => ['error_class' => $this->masker->mask(get_class($e))],
            ];
        }

        return array_merge([
            'key' => $key,
            'category' => $category,
            'label' => $label,
            'status' => self::UNKNOWN,
            'unsafe' => false,
            'summary' => '',
            'remediation' => null,
            'details' => [],
        ], $signal, ['key' => $key, 'category' => $category, 'label' => $label]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     */
    private function summaryCounts(array $signals): array
    {
        $counts = [self::GO => 0, self::WATCH => 0, self::FAIL => 0, self::UNKNOWN => 0];
        foreach ($signals as $signal) {
            $status = (string) ($signal['status'] ?? self::UNKNOWN);
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $counts['total'] = count($signals);

        return $counts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @return array<int, string>
     */
    private function reasons(array $signals): array
    {
        $reasons = [];
        foreach ($signals as $signal) {
            $status = (string) ($signal['status'] ?? self::UNKNOWN);
            if (in_array($status, [self::FAIL, self::WATCH], true)) {
                $reasons[] = sprintf('[%s] %s — %s', $status, $signal['label'] ?? $signal['key'] ?? '?', $signal['summary'] ?? '');
            }
        }

        return $reasons;
    }
}
