<?php

namespace App\Console\Commands;

use App\Services\Inventory\SourceDocumentBatchAuditService;
use Illuminate\Console\Command;

class InventorySourceDocumentBatchAuditCommand extends Command
{
    protected $signature = 'inventory:source-document-batch-audit
        {--json : Output JSON report}
        {--export= : Export anomaly detail JSON to path under storage/app/architecture}
        {--fail-on= : Exit non-zero when findings meet threshold: error, warning, any}';

    protected $description = 'Read-only DQ-3 source-document batch linkage audit (privacy-safe).';

    public function handle(SourceDocumentBatchAuditService $service): int
    {
        $report = $service->audit();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printConsole($report);
        }

        $exportPath = $this->resolveExportPath();
        if ($this->option('export') && $exportPath === null) {
            return 10;
        }

        if ($exportPath !== null) {
            file_put_contents($exportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('Report written to: '.$exportPath);
        }

        return $this->exitForFailOn($report);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $s = $report['summary'];
        $this->info('DQ-3 Source Document Batch Linkage Audit');
        $this->line('Generated: '.$report['generated_at']);
        $this->line(sprintf(
            'Checks: %d | PASS: %d | WARN: %d | FAIL: %d | Decision: %s',
            $s['checks'],
            $s['passed'],
            $s['warnings'],
            $s['errors'],
            $s['decision'],
        ));
        $gr = $s['goods_receipt'] ?? [];
        $tr = $s['stock_transfer'] ?? [];
        $op = $s['stock_opname'] ?? [];
        $this->line(sprintf(
            'GR missing: %d | Transfer missing: %d | Opname missing: %d | Total missing: %d | Recoverable: %d | Ambiguous: %d',
            $gr['missing'] ?? 0,
            $tr['missing'] ?? 0,
            $op['missing'] ?? 0,
            $s['total_missing'] ?? 0,
            $s['deterministic_recoverable'] ?? 0,
            $s['ambiguous_manual'] ?? 0,
        ));
        $this->newLine();

        $rows = [];
        foreach ($report['checks'] as $check) {
            $rows[] = [
                $check['check_id'],
                $check['category'],
                $check['status'],
                $check['message'],
            ];
        }

        $this->table(['Check ID', 'Category', 'Status', 'Message'], $rows);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function exitForFailOn(array $report): int
    {
        $threshold = strtolower((string) ($this->option('fail-on') ?: ''));

        if ($threshold === '') {
            return self::SUCCESS;
        }

        $errors = (int) ($report['summary']['errors'] ?? 0);
        $warnings = (int) ($report['summary']['warnings'] ?? 0);

        return match ($threshold) {
            'error' => $errors > 0 ? self::FAILURE : self::SUCCESS,
            'warning', 'warn' => ($errors + $warnings) > 0 ? self::FAILURE : self::SUCCESS,
            'any' => ($errors + $warnings) > 0 ? self::FAILURE : self::SUCCESS,
            default => self::SUCCESS,
        };
    }

    private function resolveExportPath(): ?string
    {
        $raw = $this->option('export');

        if ($raw === null || $raw === '') {
            return null;
        }

        $architectureRoot = realpath(storage_path('app/architecture')) ?: storage_path('app/architecture');

        if (! is_dir($architectureRoot)) {
            mkdir($architectureRoot, 0775, true);
            $architectureRoot = realpath($architectureRoot) ?: $architectureRoot;
        }

        $candidate = (string) $raw;

        if (! str_starts_with($candidate, '/')) {
            $candidate = ltrim($candidate, '/');
            $prefix = 'storage/app/architecture/';
            if (str_starts_with($candidate, $prefix)) {
                $candidate = substr($candidate, strlen($prefix));
            }
            $candidate = $architectureRoot.'/'.ltrim($candidate, '/');
        }

        $parent = dirname($candidate);
        if (! is_dir($parent)) {
            mkdir($parent, 0755, true);
        }

        $normalizedRoot = rtrim($architectureRoot, DIRECTORY_SEPARATOR);
        $realParent = realpath($parent) ?: $parent;
        $normalizedParent = rtrim((string) $realParent, DIRECTORY_SEPARATOR);

        if ($normalizedParent !== $normalizedRoot && ! str_starts_with($normalizedParent.'/', $normalizedRoot.'/')) {
            $this->error('Export must be under storage/app/architecture.');

            return null;
        }

        return $candidate;
    }
}
