<?php

namespace App\Modules\Observability\Console;

use App\Modules\Observability\Services\ObservabilityTelemetryRetention;
use Illuminate\Console\Command;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — on-demand, idempotent retention prune.
 * Deletes telemetry only (sys_obs_*); never a clinical, financial or audit row.
 */
class PruneObservabilityTelemetryCommand extends Command
{
    protected $signature = 'observability:prune
        {--batch= : Max rows deleted per retention class per pass}
        {--json : Emit the result as JSON}';

    protected $description = 'Delete expired Observability Console telemetry (bounded, idempotent).';

    public function handle(ObservabilityTelemetryRetention $retention): int
    {
        $batch = $this->option('batch') !== null ? max(1, (int) $this->option('batch')) : null;
        $deleted = $retention->prune($batch);

        if ($this->option('json')) {
            $this->line((string) json_encode(['deleted' => $deleted]));

            return self::SUCCESS;
        }

        foreach ($deleted as $class => $count) {
            $this->line(sprintf('%-14s %d', $class, $count));
        }

        return self::SUCCESS;
    }
}
