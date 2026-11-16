<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Storage\StorageUsageReportService;
use Illuminate\Console\Command;
use Throwable;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 PR9 (memo M6.2, rule 239) — read-only storage
 * capacity report. Sizes and counts only: no file name, object key, host path or
 * patient detail. Writes nothing; always exits 0 — an unexpected failure prints
 * only the exception class, never its message. Run as the runtime user on the
 * host and keep the JSON output dated: the growth projection is the trend of
 * those monthly measurements, and a cost estimate needs the owner's verified
 * storage rate (never assumed here).
 */
class StorageUsageReportCommand extends Command
{
    protected $signature = 'storage:usage-report {--json : Output JSON}';

    protected $description = 'Read-only storage capacity report: free space, private disks, clinical evidence by category, database and backups (sizes and counts only).';

    public function handle(StorageUsageReportService $service): int
    {
        try {
            $this->render($service->report());
        } catch (Throwable $e) {
            // Rule 239: an exception message can carry a host path or a file name,
            // so only the exception class is printed — and nothing is logged.
            $class = get_debug_type($e);

            $this->option('json')
                ? $this->line((string) json_encode(['report_failed' => true, 'exception' => $class], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                : $this->line('STORAGE USAGE REPORT FAILED ('.$class.') — nothing was measured or written.');
        }

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $report */
    private function render(array $report): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return;
        }

        $this->info('Storage usage report (read-only) — '.$report['generated_at_utc']);

        $fs = $report['filesystem'];
        $this->line($fs['readable']
            ? sprintf('FILESYSTEM storage TOTAL=%s FREE=%s USED=%s%%', self::human($fs['total_bytes']), self::human($fs['free_bytes']), $fs['used_percent'])
            : 'FILESYSTEM storage UNREADABLE');

        foreach ($report['disks'] as $disk) {
            $this->line(sprintf('DISK %s %s — %s', $disk['disk'], self::measurement($disk), $disk['description']));
        }

        foreach ($report['clinical_evidence_categories'] as $category) {
            $this->line(sprintf('CLINICAL %s %s — %s', $category['category'], self::measurement($category), $category['description']));
        }

        $db = $report['database'];
        $this->line('DATABASE '.$db['driver'].' '.($db['bytes'] !== null ? 'SIZE='.self::human($db['bytes']) : 'SIZE=n/a ('.($db['note'] ?? '').')'));

        $backups = $report['backups'];
        $this->line('BACKUPS '.(! $backups['configured'] ? 'NOT_CONFIGURED' : ($backups['readable'] ? 'SIZE='.self::human($backups['bytes']).' FILES='.$backups['files'] : 'UNREADABLE (run as the runtime user; root-owned backups need the owner)')));
    }

    /**
     * NOT_MEASURABLE (not a local disk), UNREADABLE (null figures, never 0) or
     * the size, followed by the file count and the unreadable counts.
     *
     * @param  array<string, mixed>  $row
     */
    private static function measurement(array $row): string
    {
        if (! ($row['measurable'] ?? true)) {
            return 'NOT_MEASURABLE '.(isset($row['driver']) ? '(driver '.$row['driver'].')' : '(the disk is not local)');
        }

        return sprintf(
            '%s FILES=%s UNREADABLE_DIRS=%d UNREADABLE_ENTRIES=%d',
            $row['readable'] ? 'SIZE='.self::human($row['bytes']) : 'UNREADABLE',
            $row['files'] ?? '-',
            $row['unreadable_dirs'],
            $row['unreadable_entries'],
        );
    }

    private static function human(?int $bytes): string
    {
        if ($bytes === null) {
            return 'n/a';
        }

        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return $unit === 0
            ? $bytes.' B'
            : sprintf('%s %s (%d B)', number_format($value, 2, '.', ''), $units[$unit], $bytes);
    }
}
