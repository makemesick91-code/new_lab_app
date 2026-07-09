<?php

namespace App\Console\Commands;

use App\Modules\RmeInvoice\Services\DoctorPerformanceReportService;
use Illuminate\Console\Command;

/**
 * HOTFIX-FIX-PRE-68-45-DOCTOR-PERFORMANCE-403 — read-only diagnostic for the
 * Doctor Performance report access setup.
 *
 * Surfaces the root causes of a 403 for a legitimate doctor (Doctor role /
 * own-report permission without a `mst_doctors.user_id` link) and permission
 * leakage to Kepala Cabang. Never mutates data, never auto-links accounts, and
 * never renders KTP/NIK/medical data. `--strict` exits non-zero on any anomaly.
 */
class DoctorPerformanceAccessAuditCommand extends Command
{
    protected $signature = 'rme:doctor-performance-access-audit
        {--json : Output the report as JSON}
        {--strict : Exit non-zero (2) when any anomaly is found}';

    protected $description = 'Read-only audit of Doctor Performance report access (unlinked doctors, permission leakage). Privacy-safe.';

    public function handle(DoctorPerformanceReportService $service): int
    {
        $report = $service->accessAudit();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->printConsole($report);
        }

        $anomalies = (int) ($report['summary']['anomalies'] ?? 0);

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

        $this->info('Doctor Performance Report — Access Audit');
        $this->line('Generated: '.$report['generated_at'].' | Env: '.$report['environment']);
        $this->line('Decision: '.$summary['decision'].' | Anomalies: '.$summary['anomalies']);
        $this->newLine();

        foreach ($report['permissions_exist'] as $permission => $exists) {
            $this->line(sprintf('  permission %-38s : %s', $permission, $exists ? 'present' : 'MISSING'));
        }
        $this->newLine();

        $this->reportRows(
            'Doctor role users NOT linked to a doctor record (mst_doctors.user_id)',
            $report['findings']['doctor_role_unlinked'],
            fn (array $row) => sprintf('    #%d %s <%s>', $row['user_id'], (string) $row['name'], (string) $row['email']),
        );

        $this->reportRows(
            'Doctor records without a user_id',
            $report['findings']['doctors_without_user'],
            fn (array $row) => sprintf('    doctor #%d %s%s', $row['doctor_id'], (string) $row['name'], $row['is_active'] ? '' : ' (inactive)'),
        );

        $this->reportRows(
            'Own-report permission holders with NO doctor link (will hit the clear 403)',
            $report['findings']['own_permission_unlinked'],
            fn (array $row) => sprintf('    #%d %s <%s>', $row['user_id'], (string) $row['name'], (string) $row['email']),
        );

        $this->reportRows(
            'Kepala Cabang users with a doctor-report permission (LEAK — must be none)',
            $report['findings']['kepala_cabang_permission_leak'],
            fn (array $row) => sprintf('    #%d %s <%s>', $row['user_id'], (string) $row['name'], (string) $row['email']),
        );

        if ($summary['kepala_cabang_role_permission_leak']) {
            $this->error('  The Kepala Cabang ROLE itself grants a doctor-report permission — remove it from RoleSeeder.');
        }

        $this->newLine();
        if ($summary['anomalies'] === 0) {
            $this->info('No anomalies detected. Doctor Performance access is correctly configured.');
        } else {
            $this->warn('Anomalies detected. Link the affected doctor accounts via master data (do NOT auto-link) and re-run.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): string  $formatter
     */
    private function reportRows(string $heading, array $rows, callable $formatter): void
    {
        $this->line($heading.': '.count($rows));

        foreach ($rows as $row) {
            $this->line($formatter($row));
        }
    }
}
