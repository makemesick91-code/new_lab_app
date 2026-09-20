<?php

namespace App\Console\Commands;

use App\Support\AccessControl\FrontOfficeMigrationAuditor;
use Illuminate\Console\Command;
use Throwable;

/**
 * DAENGTISIAMS-SUNU-FINAL-RELEASE-CANDIDATE-1 / D2.
 *
 * Dry-run by default: without `--apply` this command reports and changes
 * nothing. `--strict` exits 2 on any anomaly so it can gate a deploy.
 */
class FrontOfficeMigrateCommand extends Command
{
    protected $signature = 'rbac:front-office-migrate
        {--user=* : Limit to these user ids (default: every eligible account)}
        {--apply : Actually move the accounts. Without this nothing is written}
        {--json}
        {--strict : Exit 2 when the role is not ready or an account is blocked}';

    protected $description = 'Report, and optionally apply, the Admin Klinik + Kasir merge onto Front Office.';

    public function handle(FrontOfficeMigrationAuditor $auditor): int
    {
        $report = $auditor->audit();
        $only = array_map('intval', (array) $this->option('user'));

        $candidates = $report['candidates'];
        if ($only !== []) {
            $candidates = array_values(array_filter($candidates, fn ($c) => in_array($c['id'], $only, true)));
        }

        $applied = [];
        $failed = [];

        if ($this->option('apply')) {
            if (! $report['role_ready']) {
                $this->error('Front Office is not seeded with the full permission union — run RoleSeeder first.');

                return self::FAILURE;
            }

            foreach ($candidates as $c) {
                if ($c['blocked_by'] !== null || $c['already_migrated']) {
                    continue;
                }

                try {
                    $applied[] = $auditor->migrate($c['id']);
                } catch (Throwable $e) {
                    $failed[] = ['id' => $c['id'], 'error' => $e->getMessage()];
                }
            }

            // Re-read so the printed state is what the database now holds,
            // never what we predicted it would hold.
            $report = $auditor->audit();
        }

        $payload = $report + ['applied' => $applied, 'failed' => $failed, 'dry_run' => ! $this->option('apply')];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($payload, $candidates);
        }

        if ($failed !== []) {
            return self::FAILURE;
        }

        if ($this->option('strict') && (! $report['role_ready'] || $report['blocked'] > 0)) {
            return 2;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  list<array<string, mixed>>  $candidates
     */
    private function render(array $p, array $candidates): void
    {
        $this->info($p['dry_run'] ? 'DRY RUN — nothing was written.' : 'APPLIED.');
        $this->line(sprintf(
            'Front Office role: %s (id %s) — %d/%d permissions%s',
            $p['role_exists'] ? 'present' : 'ABSENT',
            $p['role_id'] ?? '-',
            $p['actual_permission_count'],
            $p['expected_permission_count'],
            $p['role_ready'] ? '' : ' — NOT READY',
        ));

        if ($p['missing_permissions'] !== []) {
            $this->warn('  missing: '.implode(', ', $p['missing_permissions']));
        }

        $this->newLine();
        $this->table(
            ['id', 'name', 'branch', 'roles', 'state'],
            array_map(fn ($c) => [
                $c['id'],
                $c['name'],
                $c['branch_id'] ?? '—',
                implode(', ', $c['roles']),
                $c['blocked_by'] ?? ($c['already_migrated'] ? 'done' : 'ready'),
            ], $candidates),
        );

        foreach ($p['applied'] as $a) {
            $this->line(sprintf('  moved #%d %s: [%s] -> [%s]',
                $a['id'], $a['name'], implode(', ', $a['roles_before']), implode(', ', $a['roles_after'])));
        }

        foreach ($p['failed'] as $f) {
            $this->error(sprintf('  FAILED #%d: %s', $f['id'], $f['error']));
        }
    }
}
