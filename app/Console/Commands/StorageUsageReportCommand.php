<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Storage\StorageUsageReportService;
use Illuminate\Console\Command;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 PR9 (memo M6.2, rule 239) — read-only storage
 * capacity report. Sizes and counts only: no file name, object key, host path or
 * patient detail. Writes nothing; always exits 0. Run as the runtime user on the
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
        $report = $service->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Storage usage report (read-only) — '.$report['generated_at_utc']);

        $fs = $report['filesystem'];
        $this->line($fs['readable']
            ? sprintf('FILESYSTEM storage TOTAL=%s FREE=%s USED=%s%%', self::human($fs['total_bytes']), self::human($fs['free_bytes']), $fs['used_percent'])
            : 'FILESYSTEM storage UNREADABLE');

        foreach ($report['disks'] as $disk) {
            $this->line(sprintf(
                'DISK %s %s FILES=%s UNREADABLE_DIRS=%d — %s',
                $disk['disk'],
                $disk['readable'] ? 'SIZE='.self::human($disk['bytes']) : 'UNREADABLE',
                $disk['files'] ?? '-',
                $disk['unreadable_dirs'],
                $disk['description'],
            ));
        }

        foreach ($report['clinical_evidence_categories'] as $category) {
            $this->line(sprintf('CLINICAL %s SIZE=%s FILES=%d — %s', $category['category'], self::human($category['bytes']), $category['files'], $category['description']));
        }

        $db = $report['database'];
        $this->line('DATABASE '.$db['driver'].' '.($db['bytes'] !== null ? 'SIZE='.self::human($db['bytes']) : 'SIZE=n/a ('.($db['note'] ?? '').')'));

        $backups = $report['backups'];
        $this->line('BACKUPS '.(! $backups['configured'] ? 'NOT_CONFIGURED' : ($backups['readable'] ? 'SIZE='.self::human($backups['bytes']).' FILES='.$backups['files'] : 'UNREADABLE (run as the runtime user; root-owned backups need the owner)')));

        return self::SUCCESS;
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
