<?php

namespace App\Console\Commands;

use App\Support\Documentation\EnterpriseDocumentationScanner;
use Illuminate\Console\Command;

/**
 * ENT-15 — read-only enterprise runbook registry summary.
 *
 * Renders the mandatory runbook registry (runbooks, topics, coverage, per-file
 * presence) from config so operators can see documentation readiness at a
 * glance. Analysis-only: no deploy, database, queue, or filesystem mutation.
 */
class EnterpriseRunbookSummaryCommand extends Command
{
    protected $signature = 'docs:enterprise-runbook-summary
        {--json : Output JSON report}';

    protected $description = 'Read-only ENT-15 enterprise runbook registry summary.';

    public function handle(EnterpriseDocumentationScanner $scanner): int
    {
        $runbooks = (array) config('enterprise_documentation.mandatory_runbooks', []);
        $requiredTopics = (array) config('enterprise_documentation.required_topics', []);
        $files = $scanner->runbookFilesPosture();
        $registry = $scanner->registryPosture();

        $rows = [];
        foreach ($runbooks as $key => $runbook) {
            $rows[] = [
                'key' => $key,
                'title' => (string) ($runbook['title'] ?? $key),
                'path' => (string) ($runbook['path'] ?? ''),
                'topics' => array_values((array) ($runbook['topics'] ?? [])),
                'present' => (bool) ($files['present'][$key] ?? false),
            ];
        }

        $report = [
            'sprint' => 'ENT-15',
            'runbook_count' => count($runbooks),
            'required_topics' => array_values($requiredTopics),
            'covered_topics' => $registry['covered_topics'] ?? [],
            'missing_topics' => $registry['missing_topics'] ?? [],
            'all_files_present' => (bool) $files['ok'],
            'runbooks' => $rows,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Enterprise Runbook Registry (ENT-15)');
        $this->line('Runbooks: '.$report['runbook_count'].' | All files present: '.($report['all_files_present'] ? 'yes' : 'NO'));
        if (($report['missing_topics'] ?? []) !== []) {
            $this->line('Missing topics: '.implode(', ', $report['missing_topics']));
        }
        $this->newLine();
        foreach ($rows as $row) {
            $this->line(sprintf(
                '  [%s] %s (%s) — topics: %s',
                $row['present'] ? 'ok' : 'MISSING',
                $row['title'],
                $row['path'],
                implode(', ', $row['topics']),
            ));
        }

        return self::SUCCESS;
    }
}
