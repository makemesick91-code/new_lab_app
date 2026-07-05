<?php

namespace App\Services\Foundation;

use App\Services\Architecture\FoundationRoadmapService;

/**
 * ROADMAP-1 (Foundation Roadmap Canonicalization) — read-only governance
 * rule catalog for keeping config/foundation_roadmap.php canonical.
 *
 * Publishes ROADMAP-R001..R010 into the foundation governance summary as
 * `roadmap_governance`. Kept as a separate service/section from the existing
 * `roadmap` section (backed by App\Services\Architecture\FoundationRoadmapService,
 * the original source-lock validator) so that section's existing tests are
 * never touched — mirrors the old-cache-governance vs cache-redis-governance
 * and OBS-1 vs OBS-2 separation pattern. Informational only — never wired
 * into the blocking combinedDecision.
 */
class FoundationRoadmapGovernanceService
{
    public function __construct(private readonly FoundationRoadmapService $roadmap) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'ROADMAP-R001',
                'title' => 'Every GO-tagged, deployed foundation sprint is in the canonical roadmap',
                'description' => 'Any foundation sprint that has been GO-tagged and deployed must have a corresponding entry in config/foundation_roadmap.php.',
            ],
            [
                'id' => 'ROADMAP-R002',
                'title' => 'next_recommended_sprint never points at a completed sprint',
                'description' => 'The computed next_recommended_sprint must never resolve to a sprint whose own status is already completed.',
            ],
            [
                'id' => 'ROADMAP-R003',
                'title' => 'Completed entries carry status, title and GO evidence',
                'description' => 'Every roadmap entry that opts into governance_section tracking and is marked completed must define a non-empty go_tag (or equivalent deploy evidence reference).',
            ],
            [
                'id' => 'ROADMAP-R004',
                'title' => 'Similarly named/duplicate sprints must be disambiguated',
                'description' => 'Sprints that share a short name with an earlier, distinct sprint (e.g. CACHE-1 vs the later Redis-readiness CACHE-1) must carry a disambiguation_note so governance never conflates them.',
            ],
            [
                'id' => 'ROADMAP-R005',
                'title' => 'New rule-adding sprints register a governance section',
                'description' => 'Any foundation sprint that adds new governance rules must register its own section in the foundation governance summary.',
            ],
            [
                'id' => 'ROADMAP-R006',
                'title' => 'Roadmap check command stays non-destructive',
                'description' => 'The roadmap check command must be safe to run in CI/VPS: no mutation, no network call, no GitHub API dependency.',
            ],
            [
                'id' => 'ROADMAP-R007',
                'title' => 'Roadmap is not the sole source of truth',
                'description' => 'The roadmap config documents intended sequencing; it must never override what code, migrations, routes, and tests actually prove.',
            ],
            [
                'id' => 'ROADMAP-R008',
                'title' => 'Roadmap updates stay governance/config/docs only',
                'description' => 'Updating the roadmap must never change a runtime driver, production data, security policy, or business workflow by itself.',
            ],
            [
                'id' => 'ROADMAP-R009',
                'title' => 'Deploy evidence records GO tag, commit, backup, smoke and rollback',
                'description' => 'Every foundation sprint entry represents a deploy that must have recorded its GO tag, commit, DB backup, smoke result, and rollback note in its evidence doc.',
            ],
            [
                'id' => 'ROADMAP-R010',
                'title' => 'Canonicalization preserves all sibling foundation governance GO',
                'description' => 'Canonicalizing the roadmap must never regress STORAGE/STATELESS/LB/REPLICA/CACHE/OBS-1/OBS-2 governance sections away from GO.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $report = $this->roadmap->collect();
        $summary = $report['summary'] ?? [];
        $decision = ($summary['decision'] ?? 'FAIL') === 'FAIL' ? 'WATCH' : 'GO';

        $sequence = $report['approved_sequence'] ?? [];
        $rawSequence = config('foundation_roadmap.approved_sequence', []);
        $byId = collect($rawSequence)->keyBy('id');

        $nextId = $report['next_recommended_sprint'] ?? null;
        $staleNext = $nextId !== null && (string) ($byId->get($nextId)['status'] ?? '') === 'completed';

        $missingMetadata = $byId
            ->filter(fn (array $item) => ($item['status'] ?? null) === 'completed' && array_key_exists('governance_section', $item))
            ->filter(fn (array $item) => empty($item['go_tag']))
            ->keys()
            ->values()
            ->all();

        if ($staleNext) {
            $decision = 'WATCH';
        }
        if ($missingMetadata !== []) {
            $decision = 'WATCH';
        }

        return [
            'decision' => $decision,
            'rules' => self::rules(),
            'next_recommended_sprint' => $nextId,
            'stale_next_detected' => $staleNext,
            'completed_count' => $byId->where('status', 'completed')->count(),
            'missing_metadata' => $missingMetadata,
            'total_planned_sprints' => count($sequence),
        ];
    }
}
