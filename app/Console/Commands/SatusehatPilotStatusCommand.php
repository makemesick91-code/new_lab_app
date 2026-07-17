<?php

namespace App\Console\Commands;

use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Models\SatusehatBranchPilotProfile;
use App\Modules\Satusehat\Support\SatusehatProductionActivationGuard;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4C — read-only pilot status across RME branches. Shows pilot status,
 * readiness stage, and cached score. No external request; PII-free.
 */
class SatusehatPilotStatusCommand extends Command
{
    protected $signature = 'satusehat:pilot-status {--json}';

    protected $description = 'SATUSEHAT-4C internal pilot status overview (read-only)';

    public function handle(BranchService $branches): int
    {
        $env = (string) config('satusehat.environment');
        $rmeIds = $branches->rmeEnabledIds();

        $profiles = SatusehatBranchPilotProfile::query()
            ->where('environment', $env)
            ->whereIn('branch_id', $rmeIds === [] ? [0] : $rmeIds)
            ->get()
            ->keyBy('branch_id');

        $rows = $branches->listRmeEnabled()->map(function ($branch) use ($profiles) {
            $p = $profiles->get($branch->id);

            return [
                'branch_id' => (int) $branch->id,
                'code' => (string) $branch->code,
                'pilot_status' => $p?->pilot_status ?? SatusehatBranchPilotProfile::STATUS_NONE,
                'readiness_stage' => $p?->readiness_stage ?? SatusehatBranchPilotProfile::STAGE_NOT_STARTED,
                'score' => $p?->internal_readiness_score,
                'last_recalculated_at' => optional($p?->last_recalculated_at)->toIso8601String(),
            ];
        })->values()->all();

        $report = [
            'primary_pilot' => collect($rows)->firstWhere('pilot_status', SatusehatBranchPilotProfile::STATUS_APPROVED),
            'branches' => $rows,
            'production_blocked' => ! app(SatusehatProductionActivationGuard::class)->isProductionAllowed(),
            'satusehat2_watch' => config('satusehat.sandbox_verified') !== true,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($rows as $r) {
            $this->line(sprintf('branch=%d (%s) pilot=%s stage=%s score=%s',
                $r['branch_id'], $r['code'], $r['pilot_status'], $r['readiness_stage'], $r['score'] ?? 'N/A'));
        }

        return self::SUCCESS;
    }
}
