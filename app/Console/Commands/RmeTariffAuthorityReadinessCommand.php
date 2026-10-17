<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\RmeInvoice\Services\RmeTariffAuthorityReadinessService;
use Illuminate\Console\Command;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — read-only branch tariff authority report
 * (owner D21): mode, tariff coverage of the active treatment master, missing
 * tariffs and how billed lines were priced, per active RME branch.
 *
 * Exit: FAIL → 1 always; WATCH → 1 only with --strict; GO → 0. Writes nothing,
 * never changes a mode, needs no network.
 */
class RmeTariffAuthorityReadinessCommand extends Command
{
    protected $signature = 'rme:tariff-authority-readiness
        {--json : Print the report as JSON}
        {--strict : Also fail (exit 1) on WATCH}';

    protected $description = 'Report branch tariff authority readiness (D21): mode, coverage and price provenance per RME branch. Read-only.';

    public function handle(RmeTariffAuthorityReadinessService $readiness): int
    {
        $report = $readiness->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('RME TARIFF AUTHORITY (D21) — clinical date '.$report['clinical_date']);
            $this->line('STRICT_BRANCH_CODES='.($report['strict_branch_codes'] === [] ? '(none)' : implode(',', $report['strict_branch_codes'])));
            $this->line('PRICE_EXCEPTIONS_ENABLED='.($report['price_exceptions_enabled'] ? 'true' : 'false'));
            $this->newLine();

            $this->table(
                ['Branch', 'Mode', 'Readiness', 'Tariffed', 'Approved', 'Provenance', 'Strict-eligible', 'Missing', 'Billed', 'Verified', 'Transitional', 'Not recorded'],
                array_map(fn (array $b) => [
                    $b['code'],
                    $b['mode'],
                    $b['readiness'],
                    $b['tariffed_treatments'].'/'.$b['active_treatments'],
                    $b['approved_tariffs'].'/'.$b['tariffed_treatments'],
                    $b['provenance'],
                    $b['strict_eligible'] ? 'yes' : 'no',
                    $b['missing_tariff_codes'] === [] ? '-' : implode(',', $b['missing_tariff_codes']),
                    $b['billed_lines'],
                    $b['billed_lines_tariff_verified'],
                    $b['billed_lines_transitional'],
                    $b['billed_lines_not_recorded'],
                ], $report['branches']),
            );

            foreach ($report['findings'] as $finding) {
                $this->line(sprintf('[%s] %s %s', $finding['level'], $finding['code'], $finding['branch']));
            }

            $this->newLine();
            $this->line('Decision: '.$report['decision']);
            $this->line('Note: the treatment master is global, so every active treatment is billable in every branch. No tariff is invented or copied.');
            $this->line('Note: coverage is not approval. A branch is strict-eligible only when every tariff in force carries an owner-approval reference.');
        }

        return match ($report['decision']) {
            RmeTariffAuthorityReadinessService::FAIL => self::FAILURE,
            RmeTariffAuthorityReadinessService::WATCH => $this->option('strict') ? self::FAILURE : self::SUCCESS,
            default => self::SUCCESS,
        };
    }
}
