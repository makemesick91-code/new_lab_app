<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Services\LegacyRmeMigrationOperationsService;
use App\Modules\LegacyRme\Services\LegacyRmeWaveBindingService;
use Illuminate\Console\Command;

/**
 * LEGACY-RME-PDF-ROLL-4 — the operational picture of a migration, from the CLI.
 *
 * The same read-only service the dashboard renders, so the terminal and the
 * browser can never disagree about whether a wave is running.
 *
 * IT CHANGES NOTHING. There is no flag to start a wave here, no retry, no
 * requeue. Admission is a deliberate configuration change followed by a
 * config-cache rebuild, and a wave transition is an audited governance action —
 * neither may be a side effect of asking for status.
 *
 * PII POLICY. Counts, branch codes, statuses, byte totals and timings. Never a
 * patient name, a Nomor RM, a KTP/NIK, a filename or a document path.
 *
 * `--strict` exits non-zero when the migration is in a state a human needs to
 * look at: a wave that is declared but unregistered, a governance record that
 * disagrees with the deployment's approval, a saturated pipeline, or books that
 * do not balance. It is safe in a deploy gate because it asserts, never repairs.
 */
class LegacyRmeMigrationStatusCommand extends Command
{
    protected $signature = 'legacy-rme:migration-status
        {--wave= : Inspect a specific wave code instead of the declared one}
        {--json : Emit the report as JSON}
        {--strict : Exit non-zero when the migration needs attention}';

    protected $description = 'Report the legacy RME migration operations: wave, branches, quota, queue, backlog and reconciliation';

    public function handle(
        LegacyRmeMigrationOperationsService $operations,
        LegacyRmeWaveBindingService $binding,
    ): int {
        $wave = $this->resolveWave($binding);

        $report = $operations->overview($wave);
        $findings = $this->findings($report);

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $report + ['findings' => $findings],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $this->exitCode($findings);
        }

        $this->render($report, $findings);

        return $this->exitCode($findings);
    }

    private function resolveWave(LegacyRmeWaveBindingService $binding): ?LegacyRmeMigrationWave
    {
        $code = $this->option('wave');

        if (is_string($code) && trim($code) !== '') {
            return LegacyRmeMigrationWave::query()
                ->where('code', strtoupper(trim($code)))
                ->first();
        }

        return $binding->resolveWave();
    }

    /**
     * Conditions a human has to resolve. Deliberately NOT everything unusual:
     * a paused wave and an empty backlog are normal operating states, and a
     * gate that cries wolf gets ignored exactly when it matters.
     *
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function findings(array $report): array
    {
        $findings = [];

        $admitted = (array) ($report['admission']['admitted_branch_codes'] ?? []);
        $binding = (array) ($report['binding'] ?? []);

        // A branch cleared to migrate with no operational record behind it means
        // no operators, no quota and no completion path — uncontrolled by
        // construction, which is the one thing this layer exists to prevent.
        if ($admitted !== [] && ($report['wave'] ?? null) === null) {
            $findings[] = 'WAVE_NOT_REGISTERED';
        }

        if (($report['wave'] ?? null) !== null && ($binding['binding_matches'] ?? false) === false) {
            $findings[] = 'WAVE_BINDING_MISMATCH';
        }

        if (($report['admission']['unapproved_admitted'] ?? []) !== []) {
            $findings[] = 'ADMITTED_WITHOUT_APPROVAL';
        }

        if (($report['queue']['available'] ?? true) === false) {
            $findings[] = 'PIPELINE_SATURATED';
        }

        $reconciliation = $report['reconciliation'] ?? null;

        if (is_array($reconciliation)) {
            if ((int) ($reconciliation['unexplained'] ?? 0) !== 0) {
                $findings[] = 'UNEXPLAINED_RECORDS';
            }

            if ((int) ($reconciliation['quota_drift'] ?? 0) !== 0) {
                $findings[] = 'QUOTA_LEDGER_DRIFT';
            }

            if ((int) ($reconciliation['stale_processing'] ?? 0) > 0) {
                $findings[] = 'STALE_PROCESSING';
            }
        }

        if (($report['backlog']['review_backlog_warning'] ?? false) === true) {
            $findings[] = 'REVIEW_BACKLOG';
        }

        if (($report['operations']['enforced'] ?? true) === false) {
            $findings[] = 'OPERATIONS_NOT_ENFORCED';
        }

        return $findings;
    }

    /**
     * @param  list<string>  $findings
     */
    private function exitCode(array $findings): int
    {
        return ($this->option('strict') && $findings !== []) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<string>  $findings
     */
    private function render(array $report, array $findings): void
    {
        $wave = $report['wave'] ?? null;

        $this->components->info(sprintf(
            'Legacy RME migration operations — wave %s, status %s',
            $wave['code'] ?? '(none registered)',
            $wave['status'] ?? '-',
        ));

        $this->components->twoColumnDetail('Operations layer', ($report['operations']['enforced'] ?? true) ? 'enforced' : '<fg=red>NOT enforced</>');
        $this->components->twoColumnDetail('Accepting new documents', ($wave['ingesting'] ?? false) ? 'yes' : 'no');

        $binding = (array) ($report['binding'] ?? []);

        // "No wave registered" and "the wave disagrees with the approval" are
        // different situations with different remedies. Printing MISMATCH for
        // both would send an operator hunting for a drift that does not exist.
        $this->components->twoColumnDetail(
            'Approval binding',
            match (true) {
                ($binding['binding_matches'] ?? false) === true => 'matches deployment approval',
                $wave === null => 'n/a — no wave registered',
                default => '<fg=red>MISMATCH</>',
            },
        );
        $this->components->twoColumnDetail('Declared approval', $binding['declared_approval_reference'] ?? '(none recorded)');
        $this->components->twoColumnDetail(
            'Admitted branches (ROLL-3)',
            ($report['admission']['admitted_branch_codes'] ?? []) === []
                ? '(none — closed)'
                : implode(', ', (array) $report['admission']['admitted_branch_codes']),
        );

        $quota = (array) ($report['quota_today'] ?? []);
        if ($quota !== []) {
            $this->components->twoColumnDetail(
                sprintf('Quota %s (wave)', $quota['date'] ?? ''),
                sprintf(
                    '%d used / %s',
                    (int) ($quota['wave_consumed'] ?? 0),
                    $quota['wave_limit'] === null ? 'no ceiling declared' : (string) $quota['wave_limit'],
                ),
            );
        }

        foreach ((array) ($report['branches'] ?? []) as $branch) {
            $this->components->twoColumnDetail(
                sprintf('Branch · %s (%s)', $branch['branch_code'], $branch['status']),
                sprintf(
                    'accepted %d · published %d · in-flight %d · failed %d%s',
                    $branch['accepted'],
                    $branch['published'],
                    $branch['in_flight'],
                    $branch['failed_unresolved'],
                    $branch['completion_percent'] === null ? '' : sprintf(' · %d%%', $branch['completion_percent']),
                ),
            );
        }

        $this->components->twoColumnDetail(
            'Pipeline',
            ($report['queue']['available'] ?? true) ? 'has room' : sprintf('<fg=red>SATURATED (%s)</>', $report['queue']['code'] ?? '?'),
        );
        $this->components->twoColumnDetail(
            'Pending render jobs',
            (string) ($report['queue']['pending_jobs'] ?? 'not measurable'),
        );

        $storage = (array) ($report['storage'] ?? []);
        if (($storage['measurable'] ?? false) === true) {
            $this->components->twoColumnDetail(
                'Storage (measured)',
                sprintf(
                    '%d documents · %d bytes · avg %s bytes/doc',
                    (int) $storage['documents'],
                    (int) $storage['source_bytes'],
                    $storage['average_source_bytes'] === null ? 'n/a' : (string) $storage['average_source_bytes'],
                ),
            );
        }

        $reconciliation = (array) ($report['reconciliation'] ?? []);
        if ($reconciliation !== []) {
            $this->components->twoColumnDetail(
                'Reconciliation',
                sprintf(
                    'accepted %d = published %d + cancelled %d + failed %d + in-flight %d · unexplained %d · quota drift %d',
                    (int) $reconciliation['accepted'],
                    (int) $reconciliation['published'],
                    (int) $reconciliation['cancelled'],
                    (int) $reconciliation['failed_unresolved'],
                    (int) $reconciliation['in_flight'],
                    (int) $reconciliation['unexplained'],
                    (int) $reconciliation['quota_drift'],
                ),
            );
        }

        if ($findings === []) {
            $this->components->twoColumnDetail('Findings', 'none');

            return;
        }

        foreach ($findings as $finding) {
            $this->components->twoColumnDetail('<fg=yellow>Finding</>', $finding);
        }
    }
}
