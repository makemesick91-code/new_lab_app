<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Devflow\SharedFoundationScanner;
use Illuminate\Console\Command;

/**
 * DEVFLOW-1 — shared foundation registry audit.
 */
final class FoundationSharedServiceAuditCommand extends Command
{
    protected $signature = 'foundation:shared-service-audit
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as NO-GO}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Verify every canonical shared foundation exists and is reused (config/shared_foundations.php).';

    public function handle(): int
    {
        $scanner = new SharedFoundationScanner(base_path());
        $report = $scanner->scan();
        $decision = (string) $report['decision'];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Shared foundation audit: '.$decision);
            foreach ($report['entries'] as $entry) {
                $mark = $entry['ok'] ? '✓' : ($entry['status'] === 'canonical' && ! $entry['class_exists'] ? '✗' : '!');
                $this->line("  {$mark} {$entry['concern']} [{$entry['status']}] -> {$entry['canonical_class']}");
                foreach ($entry['issues'] as $issue) {
                    $this->warn('      '.$issue);
                }
            }
            $s = $report['summary'];
            $this->line("Total {$s['total']}, errors {$s['errors']}, warnings {$s['warnings']}.");
        }

        if ($decision === 'NO-GO') {
            return self::FAILURE;
        }
        if ($decision === 'WATCH' && ($this->option('strict') || $this->option('fail-on-warning'))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
