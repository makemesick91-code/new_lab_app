<?php

namespace App\Console\Commands;

use App\Support\Cicd\CriticalGateWarningContract;
use Illuminate\Console\Command;

/**
 * CICD-CRITICAL-GATE-FILE-GET-CONTENTS-WARN-1 — assert the Critical Gate
 * warning contract.
 *
 * The NSF-R011 Critical Test Gate reported failures correctly but had no
 * opinion about warnings, so a run could conclude `success` while 128 of its
 * 129 test files were marked WARN. This command gives the gate that opinion:
 * the expected warning count is DECLARED in configuration, and anything above
 * it is UNEXPLAINED and fails the gate.
 *
 * It suppresses nothing. It reads the evidence the test step already wrote and
 * fails closed when that evidence is missing, unreadable, empty, or has no
 * summary — a gate whose evidence cannot be read is never reported as clean.
 *
 * The test step's own exit status keeps strict precedence: the workflow exits
 * on a failing suite BEFORE this command runs, so the contract can never turn
 * a red gate green. Read-only; prints counts and a repository-relative path,
 * never a credential or a value read out of the environment.
 */
class CiAssertCriticalGateWarningContractCommand extends Command
{
    protected $signature = 'ci:assert-critical-gate-warning-contract
        {--log= : Path to the Critical Gate evidence log (defaults to the configured path)}
        {--expected= : Override the declared expected warning count}
        {--json : Emit the verdict as JSON}';

    protected $description = 'CICD: fail closed unless the Critical Gate produced zero unexplained warnings.';

    public function handle(CriticalGateWarningContract $contract): int
    {
        $config = (array) config('ci_runner.critical_gate_warning_contract', []);

        $logPath = (string) ($this->option('log') ?: ($config['log'] ?? ''));

        if ($logPath === '') {
            $this->error('No Critical Gate evidence log configured or supplied.');

            return self::FAILURE;
        }

        $expectedOption = $this->option('expected');
        $expected = $expectedOption === null
            ? (int) ($config['expected_warning_count'] ?? 0)
            : (int) $expectedOption;

        $result = $contract->evaluate($logPath, $expected);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('CICD Critical Gate warning contract');
            $this->line('  log_path='.$result['log_path']);
            $this->line('  log_state='.$result['log_state']);
            $this->line('  summary='.($result['summary_line'] ?? '(not found)'));
            $this->line('  expected_warning_count='.$result['expected_warning_count']);
            $this->line('  observed_warning_count='.($result['observed_warning_count'] ?? 'unknown'));
            $this->line('  unexplained_warning_count='.($result['unexplained_warning_count'] ?? 'unknown'));
            $this->line('  observed_failure_count='.($result['observed_failure_count'] ?? 'unknown'));
            $this->line('  decision='.$result['decision']);

            foreach ($result['reasons'] as $reason) {
                $this->line('  reason: '.$reason);
            }

            foreach ($result['remediation'] as $step) {
                $this->line('  remediation: '.$step);
            }
        }

        if ($result['decision'] !== CriticalGateWarningContract::DECISION_GO) {
            foreach ($result['reasons'] as $reason) {
                $this->line('::error::'.$reason);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
