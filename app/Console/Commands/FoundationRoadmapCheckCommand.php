<?php

namespace App\Console\Commands;

use App\Services\Architecture\FoundationRoadmapService;
use App\Services\Foundation\FoundationRoadmapGovernanceService;
use Illuminate\Console\Command;

/**
 * ROADMAP-1 (Foundation Roadmap Canonicalization) — read-only roadmap
 * canonicalization check. Thin wrapper around the existing source-locked
 * FoundationRoadmapService (architecture:foundation-roadmap-check) that adds
 * canonicalization-specific fields: completed/current/next sprint,
 * stale-next detection, and missing-metadata detection — without
 * duplicating or mutating the underlying roadmap validator.
 *
 * Never mutates files/data, never calls the network or the GitHub API.
 *
 * Decision → exit code:
 *  - GO    → 0
 *  - WATCH → 0 by default; non-zero with --strict or --fail-on-warning
 *  - FAIL  → non-zero always
 */
class FoundationRoadmapCheckCommand extends Command
{
    protected $signature = 'foundation:roadmap-check
        {--json : Output JSON report}
        {--strict : Exit non-zero when next sprint is stale, metadata is missing, or roadmap decision is not GO}
        {--fail-on-warning : Exit non-zero on any warning, same as --strict for this command}';

    protected $description = 'Read-only foundation roadmap canonicalization check (completed/current/next, staleness, missing metadata).';

    public function handle(FoundationRoadmapService $roadmap, FoundationRoadmapGovernanceService $governance): int
    {
        $report = $roadmap->collect();
        $gov = $governance->collect();

        $sequence = $report['approved_sequence'] ?? [];
        $completed = array_values(array_filter($sequence, fn (array $i) => ($i['status'] ?? '') === 'completed'));
        $completedIds = array_map(fn (array $i) => $i['id'], $completed);
        $currentSprint = end($completed) ?: null;

        $warnings = [];
        if ($gov['stale_next_detected'] ?? false) {
            $warnings[] = 'next_recommended_sprint points at a sprint already marked completed.';
        }
        if (($gov['missing_metadata'] ?? []) !== []) {
            $warnings[] = 'Completed sprints missing GO-tag evidence: '.implode(', ', $gov['missing_metadata']);
        }

        $governanceSectionsExpected = [
            'storage_governance', 'stateless_governance', 'lb_governance',
            'database_replica_governance', 'cache_redis_governance', 'cache_governance',
            'observability_governance', 'observability_pipeline_governance', 'roadmap_governance',
        ];

        $payload = [
            'status' => $report['summary']['decision'] ?? 'FAIL',
            'decision' => $gov['decision'] ?? 'UNKNOWN',
            'completed_count' => count($completed),
            'completed_sprints' => $completedIds,
            'current_sprint' => $currentSprint['id'] ?? null,
            'next_recommended_sprint' => $report['next_recommended_sprint'] ?? null,
            'stale_next_detected' => $gov['stale_next_detected'] ?? false,
            'missing_metadata' => $gov['missing_metadata'] ?? [],
            'governance_sections_expected' => $governanceSectionsExpected,
            'governance_sections_known' => $governanceSectionsExpected,
            'warnings' => $warnings,
            'recommendations' => $warnings === []
                ? ['Roadmap is canonical. Proceed with '.($report['next_recommended_sprint'] ?? 'no sprint').' when ready.']
                : ['Resolve the warnings above before starting the next foundation sprint.'],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printConsole($payload);
        }

        if (($payload['status'] ?? 'FAIL') === 'FAIL') {
            return self::FAILURE;
        }

        if (($this->option('strict') || $this->option('fail-on-warning')) && $warnings !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function printConsole(array $payload): void
    {
        $this->info('Foundation Roadmap Canonicalization Check');
        $this->line('Status: '.$payload['status']);
        $this->line('Decision: '.$payload['decision']);
        $this->line('Completed sprints ('.$payload['completed_count'].'): '.implode(', ', $payload['completed_sprints']));
        $this->line('Current sprint: '.($payload['current_sprint'] ?? 'n/a'));
        $this->line('Next recommended sprint: '.($payload['next_recommended_sprint'] ?? 'n/a'));
        $this->line('Stale next detected: '.($payload['stale_next_detected'] ? 'yes' : 'no'));

        if ($payload['warnings'] !== []) {
            $this->newLine();
            $this->line('Warnings:');
            foreach ($payload['warnings'] as $warning) {
                $this->line('  - '.$warning);
            }
        }
    }
}
