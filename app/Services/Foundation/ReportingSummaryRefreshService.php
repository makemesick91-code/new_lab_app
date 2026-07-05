<?php

namespace App\Services\Foundation;

/**
 * RPT-1 refresh foundation.
 *
 * No physical owner/materialized reporting summary is created in RPT-1, so
 * this service is intentionally readiness-only. It proves the command surface
 * is dry-run by default and refuses writes unless a future sprint adds a safe
 * physical summary implementation with reconciliation tests.
 */
class ReportingSummaryRefreshService
{
    /**
     * @return array<string, mixed>
     */
    public function preview(?string $summaryKey = null, bool $execute = false, bool $confirm = false): array
    {
        $config = config('reporting_summary_governance', []);
        $requested = $summaryKey ?: 'all';
        $allowed = array_keys((array) ($config['allowed_summary_categories'] ?? []));

        $checks = [];
        $checks[] = $this->pass('RPT-REFRESH-DRY-RUN-DEFAULT', 'Refresh command supports dry-run/readiness output without writes.');

        if ($summaryKey !== null && ! in_array($summaryKey, $allowed, true)) {
            $checks[] = $this->fail('RPT-REFRESH-SUMMARY-KNOWN', "Unknown reporting summary key: {$summaryKey}");
        } else {
            $checks[] = $this->pass('RPT-REFRESH-SUMMARY-KNOWN', "Reporting summary selection is valid: {$requested}.");
        }

        if ($execute && ! $confirm) {
            $checks[] = $this->fail('RPT-REFRESH-CONFIRM-REQUIRED', 'Execute mode requires --confirm.');
        } elseif ($execute) {
            $checks[] = $this->warn('RPT-REFRESH-NO-PHYSICAL-SUMMARY', 'No physical RPT-1 summary object exists; execute is a no-op readiness result.');
        } else {
            $checks[] = $this->pass('RPT-REFRESH-NO-WRITE', 'Dry-run selected; no database writes will be performed.');
        }

        $errors = count(array_filter($checks, fn (array $c) => $c['status'] === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => $c['status'] === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => $c['status'] === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'sprint' => 'RPT-1',
            'summary_key' => $requested,
            'mode' => $execute ? 'execute_requested' : 'dry_run',
            'writes_performed' => false,
            'physical_summary_exists' => false,
            'affected_branch_count' => 0,
            'estimated_rows' => 0,
            'date_range' => null,
            'freshness_timestamp' => now()->toIso8601String(),
            'message' => 'RPT-1 is readiness-only; owner/materialized summaries are deferred until source-of-truth reconciliation is implemented.',
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    private function pass(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'passed', 'blocking' => false, 'message' => $message];
    }

    private function warn(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'warning', 'blocking' => false, 'message' => $message];
    }

    private function fail(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'failed', 'blocking' => true, 'message' => $message];
    }
}
