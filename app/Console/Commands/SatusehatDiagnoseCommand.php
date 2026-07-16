<?php

namespace App\Console\Commands;

use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Gateways\SatusehatGatewayInterface;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Support\SatusehatProductionActivationGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SATUSEHAT-4A — one-shot operational diagnosis. Read-only, no network,
 * no secret value ever printed (booleans only).
 */
class SatusehatDiagnoseCommand extends Command
{
    protected $signature = 'satusehat:diagnose {--json : Machine-readable output}';

    protected $description = 'SATUSEHAT-4A read-only operational diagnosis (config posture, counts, queue, guard) — no network, no secrets';

    public function handle(
        SatusehatGatewayInterface $gateway,
        SatusehatProductionActivationGuard $guard,
        BranchService $branches,
    ): int {
        $branchIds = $branches->rmeEnabledIds();

        $report = [
            'environment' => (string) config('satusehat.environment'),
            'enabled' => (bool) config('satusehat.enabled'),
            'send_enabled' => (bool) config('satusehat.send_enabled'),
            'gateway' => class_basename($gateway),
            'gateway_enabled' => $gateway->isEnabled(),
            'production_blocked' => ! $guard->isProductionAllowed(),
            'credentials' => [
                'client_id_present' => filled(config('satusehat.client_id')),
                'client_secret_present' => filled(config('satusehat.client_secret')),
                'organization_id_present' => filled(config('satusehat.organization_id')),
                'location_id_present' => filled(config('satusehat.location_id')),
            ],
            'rme_branch_count' => count($branchIds),
            'candidates' => SatusehatCandidate::query()
                ->when($branchIds !== [], fn ($q) => $q->whereIn('branch_id', $branchIds))
                ->when($branchIds === [], fn ($q) => $q->whereRaw('1 = 0'))
                ->selectRaw('readiness_status, count(*) as total')
                ->groupBy('readiness_status')->pluck('total', 'readiness_status')->map(fn ($v) => (int) $v)->all(),
            'open_issues' => SatusehatDataQualityIssue::query()
                ->whereIn('status', SatusehatDataQualityIssue::OPEN_STATUSES)->count(),
            'queue' => [
                'pending_satusehat_jobs' => Schema::hasTable('jobs')
                    ? (int) DB::table('jobs')->where('queue', (string) config('satusehat.queue', 'satusehat'))->count()
                    : null,
                'failed_satusehat_jobs' => Schema::hasTable('failed_jobs')
                    ? (int) DB::table('failed_jobs')->where('queue', (string) config('satusehat.queue', 'satusehat'))->count()
                    : null,
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('SATUSEHAT Diagnose (read-only, credential-independent)');
        foreach (['environment', 'enabled', 'send_enabled', 'gateway', 'gateway_enabled', 'production_blocked'] as $key) {
            $this->line(sprintf('  %-20s %s', $key, var_export($report[$key], true)));
        }
        $this->line('  credentials          '.json_encode($report['credentials']));
        $this->line('  candidates           '.json_encode($report['candidates']));
        $this->line('  open_issues          '.$report['open_issues']);
        $this->line('  queue                '.json_encode($report['queue']));

        return self::SUCCESS;
    }
}
