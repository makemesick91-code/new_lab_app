<?php

namespace App\Console\Commands;

use App\Modules\MedicalRecord\Services\DiagnosisRolloutService;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4B — per-branch structured diagnosis rollout status. Read-only,
 * PII-free, no external HTTP. Also proves the safety invariant: the default
 * for unconfigured branches is non-blocking (global hard enforcement is
 * impossible by design).
 */
class SatusehatDiagnosisRolloutStatusCommand extends Command
{
    protected $signature = 'satusehat:diagnosis-rollout-status {--json : Keluaran JSON}';

    protected $description = 'Status mode rollout diagnosis terstruktur per cabang RME (read-only)';

    public function handle(DiagnosisRolloutService $rollout): int
    {
        $rows = $rollout->board()->map(fn (array $row) => [
            'branch_id' => (int) $row['branch']->id,
            'branch_name' => (string) $row['branch']->name,
            'mode' => $row['mode'],
            'explicit' => $row['setting'] !== null,
            'reason' => $row['setting']?->reason,
            'configured_by' => $row['setting']?->configuredBy?->name,
            'updated_at' => $row['setting']?->updated_at?->toIso8601String(),
        ])->values();

        $report = [
            'default_mode' => $rollout->defaultMode(),
            'global_hard_enforcement' => false,
            'branches' => $rows->all(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('SATUSEHAT-4B — Status Rollout Diagnosis Terstruktur');
        $this->line('Default (cabang tanpa konfigurasi): '.$report['default_mode']);
        $this->line('Global hard enforcement           : TIDAK ADA (by design)');
        $this->table(
            ['Cabang', 'Mode', 'Eksplisit', 'Alasan'],
            $rows->map(fn ($r) => [
                $r['branch_name'],
                $r['mode'],
                $r['explicit'] ? 'ya' : 'default',
                $r['reason'] ?? '-',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
