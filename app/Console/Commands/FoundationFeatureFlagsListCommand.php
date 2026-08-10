<?php

namespace App\Console\Commands;

use App\Services\Foundation\FeatureFlagService;
use Illuminate\Console\Command;

/**
 * NSF-9 — Read-only feature flag registry list/check command.
 *
 * Decision → exit code:
 *  - GO    → 0 (config valid, risky flags default false, no unsafe override)
 *  - WATCH → 0 (metadata gaps only)
 *  - FAIL  → non-zero (unsafe risky flag default detected)
 */
class FoundationFeatureFlagsListCommand extends Command
{
    protected $signature = 'foundation:feature-flags {--json : Output JSON report}';

    protected $description = 'Read-only feature flag registry list and governance check.';

    public function handle(FeatureFlagService $service): int
    {
        $flags = $service->all();
        $governance = $service->validateGovernance();

        $report = [
            'generated_at' => $governance['generated_at'],
            'flags' => $flags,
            'governance' => $governance,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printConsole($flags, $governance);
        }

        return $governance['summary']['decision'] === 'FAIL' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, array<string, mixed>>  $flags
     * @param  array<string, mixed>  $governance
     */
    private function printConsole(array $flags, array $governance): void
    {
        $this->info('Foundation Feature Flags (NSF-9)');
        $this->line('Generated: '.$governance['generated_at']);
        $this->newLine();

        foreach ($flags as $key => $flag) {
            $this->line(sprintf(
                '  %-55s enabled=%-5s default=%-5s risk=%-8s status=%-12s via=%-24s captured=%s',
                $key,
                $flag['enabled'] ? 'true' : 'false',
                $flag['default'] ? 'true' : 'false',
                $flag['risk_level'],
                $flag['rollout_status'],
                // LEGACY-RME-PDF-ROLL-1 — how the effective value was reached,
                // so a rollout can be debugged without guessing at the runtime.
                $flag['env_resolution'],
                $flag['env_captured'] ? 'yes' : 'no',
            ));
        }

        $this->newLine();
        $s = $governance['summary'];
        $this->line(sprintf(
            'Checks: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
            $s['checks'], $s['passed'], $s['warnings'], $s['errors'], $s['decision'],
        ));

        foreach ($governance['checks'] as $check) {
            if ($check['status'] !== 'passed') {
                $this->line(sprintf('  - [%s] %s: %s', $check['status'], $check['check_id'], $check['message']));
            }
        }
    }
}
