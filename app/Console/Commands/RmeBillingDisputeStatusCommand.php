<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\RmeInvoice\Services\RmeBillingDisputeStatusService;
use Illuminate\Console\Command;

/**
 * IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 (rule 203) — read-only
 * status of billing disputes per RME branch: active counts by state, the age of
 * the oldest active one, reachable reviewers, and the last 24h of held payment
 * attempts, reports, resolutions, refusals and escalation failures.
 *
 * Never writes a row, never logs in, never prints a patient, a description or a
 * note. Exit: FAIL → 1 always; WATCH → 1 only with --strict; GO → 0.
 */
class RmeBillingDisputeStatusCommand extends Command
{
    protected $signature = 'rme:billing-dispute-status
        {--json : Print the report as JSON}
        {--strict : Also fail (exit 1) on WATCH}';

    protected $description = 'Report RME billing dispute status per branch (rule 203, OPS-OQ-1): active holds, oldest age, reachable reviewers. Read-only.';

    public function handle(RmeBillingDisputeStatusService $status): int
    {
        // Resolving reviewers evaluates effective permissions, which can rebuild
        // Spatie's file-store permission cache; as root that leaves a cache file
        // the runtime cannot overwrite. Refuse, fail closed (rule 195 precedent).
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->error('Refusing to run as root: run it as the runtime user, e.g. `runuser -u daengtisiams -- php artisan rme:billing-dispute-status`.');

            return self::FAILURE;
        }

        $report = $status->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('RME BILLING DISPUTES — OPS-OQ-1 (rule 203)');
            $this->line('VERDICT='.$report['verdict'].' ACTIVE_TOTAL='.$report['totals']['active']);
            $this->newLine();

            $this->table(
                ['Branch', 'Open', 'Under review', 'Escalated', 'Resolved', 'Oldest active (days)', 'Reachable reviewers'],
                array_map(fn (array $b) => [
                    $b['branch_code'],
                    $b['counts']['OPEN'],
                    $b['counts']['UNDER_REVIEW'],
                    $b['counts']['ESCALATED'],
                    $b['counts']['RESOLVED'],
                    $b['oldest_active_days'] ?? '-',
                    $b['reviewers'],
                ], $report['branches']),
            );

            $this->line('LAST_24H '.implode(' ', array_map(
                fn (string $action, int $count) => $action.'='.$count,
                array_keys($report['last_24h']),
                array_values($report['last_24h']),
            )));

            foreach ($report['findings'] as $finding) {
                $this->line(sprintf('%s %s branch=%s', $finding['level'], $finding['code'], $finding['branch']));
            }
        }

        return match ($report['verdict']) {
            'FAIL' => self::FAILURE,
            'WATCH' => $this->option('strict') ? self::FAILURE : self::SUCCESS,
            default => self::SUCCESS,
        };
    }
}
