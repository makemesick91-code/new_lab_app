<?php

namespace App\Console\Commands;

use App\Modules\Satusehat\Services\SatusehatDentalProfileAuditService;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-3 — read-only dental coverage / terminology audit. No network, no
 * PII. Exit non-zero on a NO_GO decision (and on WATCH only with --strict).
 */
class SatusehatDentalProfileAuditCommand extends Command
{
    protected $signature = 'satusehat:dental-profile-audit
        {--env= : Environment to audit (defaults to satusehat.environment)}
        {--json : Emit machine-readable JSON}
        {--strict : Treat WATCH as a failure}';

    protected $description = 'Audit the dental coverage matrix + terminology mappings (read-only, no network).';

    public function handle(SatusehatDentalProfileAuditService $service): int
    {
        $report = $service->audit($this->option('env') ?: null);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('SATUSEHAT-3 Dental Profile Audit — decision: '.$report['decision']);
            $this->line('Environment: '.$report['environment']);
            $this->line('Coverage: '.json_encode($report['coverage_summary']));
            $this->line('Mappings: '.json_encode($report['mapping_summary']));
            foreach ($report['errors'] as $e) {
                $this->error('  [ERROR] '.$e);
            }
            foreach ($report['warnings'] as $w) {
                $this->warn('  [WATCH] '.$w);
            }
        }

        if ($report['decision'] === 'NO_GO') {
            return self::FAILURE;
        }
        if ($report['decision'] === 'WATCH' && $this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
