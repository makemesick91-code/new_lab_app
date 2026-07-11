<?php

namespace App\Console\Commands;

use App\Support\AccessControl\AdminLabLabOnlyAuditor;
use Illuminate\Console\Command;

/**
 * FIX-ADMIN-LAB-LAB-ONLY-ACCESS — audit + guarded repair for the Lab-only
 * "Admin Lab" role.
 *
 * Default run is READ-ONLY (audit). Repairs are explicit opt-in flags:
 *   --sync-role    re-sync the Admin Lab role to its canonical Lab-only grant
 *   --strip-direct revoke stray revoked non-Lab DIRECT permissions from Admin Lab users
 *   --demote=<id>  demote a verified operational Lab account from Super Admin → Admin Lab
 *
 * The demote is guarded: it refuses to leave zero Super Admins and never touches the
 * primary platform Super Admin unless explicitly targeted (and even then only when
 * another Super Admin exists). `--strict` exits 2 on any anomaly for CI/deploy gates.
 */
class AdminLabLabOnlyAuditCommand extends Command
{
    protected $signature = 'rbac:admin-lab-lab-only-audit
        {--json : Output the report as JSON}
        {--strict : Exit non-zero (2) when any anomaly remains}
        {--sync-role : Re-sync the Admin Lab role to its canonical Lab-only permission set}
        {--strip-direct : Revoke revoked non-Lab direct permissions from Admin Lab accounts}
        {--demote= : Demote the given user id from Super Admin to Admin Lab (guarded)}';

    protected $description = 'Audit and safely repair the Lab-only Admin Lab role (role drift, stray direct permissions, Super Admin leakage). Privacy-safe.';

    public function handle(AdminLabLabOnlyAuditor $auditor): int
    {
        $actions = [];

        if ($this->option('sync-role')) {
            $actions['sync_role'] = $auditor->syncRole();
        }

        if ($this->option('strip-direct')) {
            $actions['strip_direct'] = $auditor->stripDirectRevokedFromAdminLabUsers();
        }

        if ($this->option('demote') !== null && $this->option('demote') !== '') {
            try {
                $actions['demote'] = $auditor->demoteSuperAdminToAdminLab((int) $this->option('demote'));
            } catch (\RuntimeException $e) {
                $actions['demote_error'] = $e->getMessage();
                if (! $this->option('json')) {
                    $this->error('Demote refused: '.$e->getMessage());
                }
            }
        }

        $report = $auditor->audit();
        $report['actions'] = $actions;

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->printConsole($report);
        }

        $anomalies = (int) ($report['summary']['anomalies'] ?? 0);

        if (isset($actions['demote_error'])) {
            return self::FAILURE;
        }

        if ($this->option('strict') && $anomalies > 0) {
            return 2;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $summary = $report['summary'];

        $this->info('Admin Lab — Lab-Only Access Audit');
        $this->line('Generated: '.$report['generated_at'].' | Env: '.$report['environment']);
        $this->line('Decision: '.$summary['decision'].' | Anomalies: '.$summary['anomalies']);
        $this->line('Role exists: '.($report['role_exists'] ? 'yes' : 'NO')
            .' | Role permissions: '.$report['role_permission_count']
            .' / canonical '.$report['canonical_lab_permission_count']);
        $this->line('Super Admin accounts: '.$report['super_admin_count']);
        $this->newLine();

        if ($report['role_extra_non_lab'] !== []) {
            $this->error('Role still holds REVOKED non-Lab permissions: '.implode(', ', $report['role_extra_non_lab']));
        }
        if ($report['role_extra_other'] !== []) {
            $this->warn('Role holds unrecognised (non-canonical) permissions: '.implode(', ', $report['role_extra_other']));
        }
        if ($report['role_missing_lab'] !== []) {
            $this->error('Role MISSING canonical Lab permissions: '.implode(', ', $report['role_missing_lab']));
        }

        foreach ($report['admin_lab_users'] as $user) {
            $this->line(sprintf(
                '  user #%d %s <%s> roles=[%s]%s%s',
                $user['id'],
                $user['name'],
                $user['email'],
                implode(', ', $user['roles']),
                $user['has_super_admin'] ? ' [SUPER ADMIN LEAK]' : '',
                $user['revoked_direct_permissions'] !== [] ? ' direct_revoked=['.implode(', ', $user['revoked_direct_permissions']).']' : '',
            ));
        }

        foreach ($report['named_lab_admin_accounts'] as $user) {
            $this->line(sprintf(
                '  named "Lab Admin" #%d <%s> roles=[%s]%s',
                $user['id'],
                $user['email'],
                implode(', ', $user['roles']),
                $user['has_super_admin'] ? ' [SUPER ADMIN — needs guarded demote]' : '',
            ));
        }

        if ($report['actions'] !== []) {
            $this->newLine();
            $this->info('Actions applied:');
            $this->line((string) json_encode($report['actions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }
}
