<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class PruneInventoryAnalyticsSummaryCommand extends Command
{
    protected $signature = 'inventory:analytics-summary:prune
                            {--days= : Override retention days}
                            {--dry-run : Show count only, do not delete}';

    protected $description = 'Prune old daily/procurement analytics summary rows beyond retention policy (rpt_* read models only)';

    public function handle(): int
    {
        try {
            $days = $this->resolveRetentionDays();
            $cutoff = Carbon::today()->subDays($days)->toDateString();
            $dryRun = (bool) $this->option('dry-run');

            $dailyCount = DB::table('rpt_inventory_daily_summaries')
                ->where('summary_date', '<', $cutoff)
                ->count();

            $procurementCount = DB::table('rpt_procurement_daily_summaries')
                ->where('summary_date', '<', $cutoff)
                ->count();

            $this->info("Retention policy: {$days} days (cutoff date: {$cutoff})");
            $this->line("rpt_inventory_daily_summaries rows eligible: {$dailyCount}");
            $this->line("rpt_procurement_daily_summaries rows eligible: {$procurementCount}");

            if ($dryRun) {
                $this->info('Dry run — no rows deleted.');

                return Command::SUCCESS;
            }

            if ($dailyCount === 0 && $procurementCount === 0) {
                $this->info('Nothing to prune.');

                return Command::SUCCESS;
            }

            DB::transaction(function () use ($cutoff) {
                DB::table('rpt_inventory_daily_summaries')
                    ->where('summary_date', '<', $cutoff)
                    ->delete();

                DB::table('rpt_procurement_daily_summaries')
                    ->where('summary_date', '<', $cutoff)
                    ->delete();
            });

            $this->info("Pruned {$dailyCount} daily and {$procurementCount} procurement summary rows.");

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Inventory analytics summary prune failed: '.$exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function resolveRetentionDays(): int
    {
        $daysOption = $this->option('days');

        if ($daysOption !== null && $daysOption !== '') {
            if (! is_numeric($daysOption)) {
                throw new \InvalidArgumentException('Invalid --days. Must be a numeric value.');
            }

            $days = (int) $daysOption;
        } else {
            $days = (int) config('inventory.analytics_summary_retention_days', 730);
        }

        if ($days < 30) {
            throw new \InvalidArgumentException('Retention days must be at least 30 to prevent accidental mass deletion.');
        }

        return $days;
    }
}
