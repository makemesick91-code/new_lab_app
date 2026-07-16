<?php

namespace App\Console\Commands;

use App\Modules\Satusehat\Services\DataQuality\SatusehatDataQualityScanService;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4A — bounded data-quality scan. DRY-RUN BY DEFAULT (counts only);
 * pass --apply to persist issue rows. Never network, never PII.
 */
class SatusehatDataQualityScanCommand extends Command
{
    protected $signature = 'satusehat:data-quality-scan
        {--branch= : Limit to one RME branch id}
        {--from= : Visit date lower bound (Y-m-d)}
        {--to= : Visit date upper bound (Y-m-d)}
        {--limit= : Max candidates this run (bounded by config)}
        {--apply : Persist issues (default is dry-run)}
        {--json}
        {--strict : Exit non-zero when any candidate errored}';

    protected $description = 'SATUSEHAT-4A bounded data-quality rule scan over candidates (dry-run default)';

    public function handle(SatusehatDataQualityScanService $scan): int
    {
        $apply = (bool) $this->option('apply');

        $summary = $scan->scan(
            branchId: is_numeric($this->option('branch')) ? (int) $this->option('branch') : null,
            from: is_string($this->option('from')) ? $this->option('from') : null,
            to: is_string($this->option('to')) ? $this->option('to') : null,
            limit: is_numeric($this->option('limit')) ? (int) $this->option('limit') : null,
            apply: $apply,
        );

        $report = ['mode' => $apply ? 'apply' : 'dry-run'] + $summary;

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(($apply ? 'APPLY' : 'DRY-RUN').' — '.json_encode($summary));
            if (! $apply) {
                $this->line('Gunakan --apply untuk menuliskan isu.');
            }
        }

        return ($this->option('strict') && $summary['errors'] > 0) ? self::FAILURE : self::SUCCESS;
    }
}
