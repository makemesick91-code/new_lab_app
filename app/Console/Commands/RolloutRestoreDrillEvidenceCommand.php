<?php

namespace App\Console\Commands;

use App\Services\Foundation\RestoreDrillEvidenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * ROLL-5-1A — validate the staging restore-drill evidence.
 *
 * Read-only. Reads the canonical restore-drill evidence JSON and prints a
 * GO | WATCH | FAIL decision with sanitized reasons. This command VALIDATES
 * evidence — it NEVER performs a restore or any destructive operation. The
 * actual disposable-DB drill is `scripts/rollout-restore-drill.sh`, run by an
 * operator on a staging/test host only.
 *
 * --create-template writes a blank, NON-GO template (never runs a restore).
 */
class RolloutRestoreDrillEvidenceCommand extends Command
{
    protected $signature = 'rollout:restore-drill-evidence
        {--json : Output the evidence evaluation as JSON}
        {--strict : Exit non-zero on a FAIL (unsafe/invalid) evidence state}
        {--fail-on-warning : Also exit non-zero on WATCH (stricter than --strict)}
        {--path= : Validate a specific evidence file instead of the canonical path}
        {--create-template : Write a blank, non-GO evidence template (no restore is performed)}';

    protected $description = 'ROLL-5-1A read-only validator for staging restore-drill evidence (GO/WATCH/FAIL). Validates evidence only — never performs a restore.';

    public function handle(RestoreDrillEvidenceService $service): int
    {
        if ($this->option('create-template')) {
            return $this->createTemplate($service);
        }

        $path = $this->option('path') ? (string) $this->option('path') : null;
        $result = $service->evaluate($path);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderConsole($result);
        }

        $status = (string) ($result['status'] ?? RestoreDrillEvidenceService::UNKNOWN);

        if ($this->option('strict') && $status === RestoreDrillEvidenceService::FAIL) {
            return self::FAILURE;
        }
        if ($this->option('fail-on-warning')
            && in_array($status, [RestoreDrillEvidenceService::FAIL, RestoreDrillEvidenceService::WATCH], true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function createTemplate(RestoreDrillEvidenceService $service): int
    {
        $rel = $this->option('path')
            ? (string) $this->option('path')
            : (string) config('rollout_readiness.restore_drill.canonical_evidence_path', 'storage/app/readiness/restore-drills/latest.json');

        $abs = str_starts_with($rel, '/') ? $rel : base_path($rel);
        File::ensureDirectoryExists(dirname($abs));
        File::put($abs, (string) json_encode($service->templatePayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info('Template bukti uji restore dibuat (NON-GO, placeholder): '.$rel);
        $this->line('  Isi bukti dari drill nyata di staging/disposable, lalu jalankan:');
        $this->line('  php artisan rollout:restore-drill-evidence --strict');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderConsole(array $result): void
    {
        $status = (string) ($result['status'] ?? 'UNKNOWN');

        $this->newLine();
        $this->line('  ROLL-5-1A Restore-Drill Evidence');
        $this->line('  Decision: '.$this->decorate($status));
        $this->line('  Summary : '.(string) ($result['summary'] ?? ''));

        if (! empty($result['remediation'])) {
            $this->line('  Action  : '.(string) $result['remediation']);
        }

        $details = (array) ($result['details'] ?? []);
        if (! empty($details)) {
            $this->newLine();
            $this->line('  Details:');
            foreach (['evidence_present', 'evidence_file', 'environment', 'restore_target', 'production_overwrite', 'source_backup_file', 'source_backup_size_bytes', 'age_hours', 'timestamp_status', 'stale', 'evidence_decision'] as $k) {
                if (array_key_exists($k, $details)) {
                    $val = $details[$k];
                    $this->line(sprintf('   - %s: %s', $k, is_bool($val) ? ($val ? 'true' : 'false') : (string) $val));
                }
            }
            if (! empty($details['issues'])) {
                $this->line('   - issues: '.implode(', ', (array) $details['issues']));
            }
        }
    }

    private function decorate(string $status): string
    {
        return match ($status) {
            'GO' => '<info>GO</info>',
            'WATCH' => '<comment>WATCH</comment>',
            'FAIL' => '<error>FAIL</error>',
            default => '<comment>UNKNOWN</comment>',
        };
    }
}
