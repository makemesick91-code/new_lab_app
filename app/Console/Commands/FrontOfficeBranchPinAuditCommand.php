<?php

namespace App\Console\Commands;

use App\Support\AccessControl\FrontOfficeBranchPinAuditor;
use Illuminate\Console\Command;

/**
 * SUNU-GO-LIVE-READINESS-1 — read-only audit of the Front Office branch pin.
 *
 * READ-ONLY, ALWAYS. There is deliberately no `--apply`, no `--arm` and no
 * `--disarm`: arming a front desk is an environment change made under a
 * supervised ceremony, and a command that could do it from a shell would make
 * that ceremony optional. This command can only ever tell you what is true.
 *
 * `--strict` exits 2 when any anomaly remains, so a deploy or go-live gate can
 * depend on it the way it depends on the other access-control audits.
 */
class FrontOfficeBranchPinAuditCommand extends Command
{
    protected $signature = 'rbac:front-office-branch-pin-audit
        {--json : Output the report as JSON}
        {--strict : Exit non-zero (2) when any anomaly remains}';

    protected $description = 'Audit which Front Office accounts are branch-pinned, to which branch, and whether every branch authority agrees. Read-only.';

    public function handle(FrontOfficeBranchPinAuditor $auditor): int
    {
        $report = $auditor->audit();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->exitCode($report);
        }

        $this->info('Front Office branch pin — '.$report['decision']);
        $this->newLine();

        $pinning = $report['pinning'];
        $this->line('  context flag ......... '.$this->yesNo($pinning['context_flag']));
        $this->line('  device flag .......... '.$this->yesNo($pinning['device_flag']));
        $this->line('  pinning enabled ...... '.$this->yesNo($pinning['pinning_enabled']));
        $this->line('  device proof required  '.$this->yesNo($pinning['device_proof_required_for_armed']));
        $this->newLine();

        $cohort = $report['cohort'];
        $this->line('  armed ................ '.$cohort['armed_count'].' / '.$cohort['max_cohort_size']
            .($cohort['oversized'] ? '  OVERSIZED' : ''));

        foreach ($cohort['armed'] as $userId => $code) {
            $this->line('    - '.$userId.' => '.$code);
        }

        $this->newLine();

        $rows = [];

        foreach ($report['accounts'] as $account) {
            $rows[] = [
                $account['user_id'],
                $account['name'],
                $account['armed'] ? 'yes' : '-',
                $account['pinned_branch_code'] ?? '-',
                $this->branchLabel($account['branch_context_branch_id']),
                $this->branchLabel($account['operational_branch_id']),
                $account['branch_context_agrees'] && $account['operational_branch_agrees'] ? 'ok' : 'MISMATCH',
            ];
        }

        $this->table(
            ['id', 'name', 'armed', 'pinned', 'context', 'registration', 'agree'],
            $rows,
        );

        if ($report['anomalies'] !== []) {
            $this->newLine();
            $this->error('Anomalies:');

            foreach ($report['anomalies'] as $anomaly) {
                $this->line('  - '.$anomaly);
            }
        }

        return $this->exitCode($report);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function exitCode(array $report): int
    {
        if ($this->option('strict') && $report['decision'] !== 'GO') {
            return 2;
        }

        return self::SUCCESS;
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'ON' : 'off';
    }

    private function branchLabel(?int $branchId): string
    {
        return $branchId === null ? '-' : (string) $branchId;
    }
}
