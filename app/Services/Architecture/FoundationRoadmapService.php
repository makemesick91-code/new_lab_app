<?php

namespace App\Services\Architecture;

/**
 * ROADMAP-1 — read-only validator for the source-locked national foundation
 * expansion roadmap (config/foundation_roadmap.php).
 *
 * Emits a GO / WATCH / FAIL decision:
 *  - FAIL : roadmap config missing OR unsafe order / guardrail violation detected.
 *  - WATCH: roadmap present and safely ordered but planned items miss required detail.
 *  - GO   : roadmap present, complete, safely ordered, guardrails intact.
 *
 * This validator never mutates state and never emits row-level or PII data.
 */
class FoundationRoadmapService
{
    /** Fields every planned sprint card must define. */
    private const REQUIRED_FIELDS = [
        'id',
        'title',
        'priority',
        'status',
        'depends_on',
        'objective',
        'go_criteria',
        'out_of_scope',
    ];

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $config = config('foundation_roadmap');
        $checks = [];

        if (! is_array($config) || $config === []) {
            return [
                'generated_at' => now()->toIso8601String(),
                'roadmap_id' => null,
                'source_locked' => false,
                'config_exists' => false,
                'active_track' => null,
                'total_planned_sprints' => 0,
                'next_recommended_sprint' => null,
                'rc_locked_after_expansion' => false,
                'approved_sequence' => [],
                'checks' => [[
                    'check_id' => 'ROADMAP-CONFIG-EXISTS',
                    'status' => 'failed',
                    'blocking' => true,
                    'message' => 'config/foundation_roadmap.php is missing or empty.',
                ]],
                'summary' => [
                    'decision' => 'FAIL',
                    'checks' => 1,
                    'passed' => 0,
                    'warnings' => 0,
                    'errors' => 1,
                ],
                'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
            ];
        }

        $sequence = $config['approved_sequence'] ?? [];
        $checks[] = $this->pass('ROADMAP-CONFIG-EXISTS', 'Roadmap config present and non-empty.');

        // Every planned sprint has required fields.
        $missing = $this->itemsMissingFields($sequence);
        $checks[] = $missing === []
            ? $this->pass('ROADMAP-ITEM-FIELDS', 'All roadmap items define required fields.')
            : $this->warn('ROADMAP-ITEM-FIELDS', 'Roadmap items missing required detail: '.implode('; ', $missing));

        // Priority sequence is stable (strictly ascending, unique).
        $checks[] = $this->priorityStable($sequence)
            ? $this->pass('ROADMAP-SEQUENCE-STABLE', 'Sprint priority order is stable (ascending, unique).')
            : $this->fail('ROADMAP-SEQUENCE-STABLE', 'Sprint priority order is not stable/unique.');

        // RC is the last item after all expansion sprints.
        $rcLast = $this->rcIsLast($sequence);
        $checks[] = $rcLast
            ? $this->pass('ROADMAP-RC-LAST', 'RC-1 is the final item after all expansion sprints.')
            : $this->fail('ROADMAP-RC-LAST', 'RC-1 must be the last item after all planned expansion sprints.');

        // depends_on references are resolvable (each referenced id exists in sequence or baseline).
        $checks[] = $this->dependenciesResolvable($sequence)
            ? $this->pass('ROADMAP-DEPENDENCIES-RESOLVABLE', 'All depends_on references resolve to known sprints.')
            : $this->fail('ROADMAP-DEPENDENCIES-RESOLVABLE', 'Unresolvable depends_on reference detected.');

        // Risky-item guardrails.
        foreach ($this->guardrailChecks($sequence) as $check) {
            $checks[] = $check;
        }

        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));

        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'roadmap_id' => (string) ($config['roadmap_id'] ?? 'ROADMAP-1'),
            'title' => (string) ($config['title'] ?? ''),
            'source_locked' => (bool) ($config['source_locked'] ?? false),
            'config_exists' => true,
            'active_track' => (string) ($config['active_track'] ?? ''),
            'current_baseline' => $config['current_baseline'] ?? [],
            'principles' => $config['principles'] ?? [],
            'rules' => $config['rules'] ?? [],
            'dependency_map' => $config['dependency_map'] ?? [],
            'guardrails' => $config['guardrails'] ?? [],
            'total_planned_sprints' => count($sequence),
            'next_recommended_sprint' => $this->nextRecommendedSprint($sequence),
            'rc_locked_after_expansion' => $rcLast,
            'approved_sequence' => $this->normalizeSequence($sequence),
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

    /**
     * @param  list<array<string, mixed>>  $sequence
     * @return list<string>
     */
    private function itemsMissingFields(array $sequence): array
    {
        $missing = [];
        foreach ($sequence as $item) {
            $id = (string) ($item['id'] ?? 'UNKNOWN');
            $gaps = [];
            foreach (self::REQUIRED_FIELDS as $field) {
                $value = $item[$field] ?? null;
                if ($value === null || $value === '' || $value === []) {
                    $gaps[] = $field;
                }
            }
            if ($gaps !== []) {
                $missing[] = sprintf('%s(%s)', $id, implode(',', $gaps));
            }
        }

        return $missing;
    }

    /**
     * @param  list<array<string, mixed>>  $sequence
     */
    private function priorityStable(array $sequence): bool
    {
        $priorities = array_map(static fn (array $i) => (int) ($i['priority'] ?? 0), $sequence);

        if ($priorities === [] || count($priorities) !== count(array_unique($priorities))) {
            return false;
        }

        $sorted = $priorities;
        sort($sorted);

        return $priorities === $sorted;
    }

    /**
     * @param  list<array<string, mixed>>  $sequence
     */
    private function rcIsLast(array $sequence): bool
    {
        if ($sequence === []) {
            return false;
        }

        // Highest-priority item must be the release candidate.
        $ordered = $this->orderedByPriority($sequence);
        $last = end($ordered);

        if (! ($last['is_release_candidate'] ?? false)) {
            return false;
        }

        // No other item may be flagged as the release candidate.
        $rcCount = count(array_filter($sequence, static fn (array $i) => (bool) ($i['is_release_candidate'] ?? false)));

        return $rcCount === 1;
    }

    /**
     * @param  list<array<string, mixed>>  $sequence
     */
    private function dependenciesResolvable(array $sequence): bool
    {
        $known = array_map(static fn (array $i) => (string) ($i['id'] ?? ''), $sequence);
        // Baseline sprint id is an allowed external dependency anchor.
        $known[] = (string) config('foundation_roadmap.current_baseline.baseline_sprint', 'NSF-8');

        foreach ($sequence as $item) {
            foreach ((array) ($item['depends_on'] ?? []) as $dep) {
                if (! in_array((string) $dep, $known, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Risky-item / guardrail validation.
     *
     * @param  list<array<string, mixed>>  $sequence
     * @return list<array<string, mixed>>
     */
    private function guardrailChecks(array $sequence): array
    {
        $byId = [];
        foreach ($sequence as $item) {
            $byId[(string) ($item['id'] ?? '')] = $item;
        }

        $checks = [];

        // PART-1 must be design-only / not production.
        $part = $byId['PART-1'] ?? null;
        $checks[] = ($part && ($part['production_forbidden'] ?? false) && ($part['requires_design_first'] ?? false))
            ? $this->pass('ROADMAP-PART1-DESIGN-ONLY', 'PART-1 is design-only and production is forbidden.')
            : $this->fail('ROADMAP-PART1-DESIGN-ONLY', 'PART-1 must be design-only / not production.');

        // REPLICA-1 must be readiness-only unless explicitly approved.
        $replica = $byId['REPLICA-1'] ?? null;
        $checks[] = ($replica && ($replica['readiness_only'] ?? false) && ($replica['may_touch_production_infra'] ?? true) === false)
            ? $this->pass('ROADMAP-REPLICA1-READINESS', 'REPLICA-1 is readiness-only (no production read routing).')
            : $this->fail('ROADMAP-REPLICA1-READINESS', 'REPLICA-1 must be readiness-only, not production routing.');

        // LB-1 must depend on STATELESS-1.
        $lb = $byId['LB-1'] ?? null;
        $checks[] = ($lb && in_array('STATELESS-1', (array) ($lb['depends_on'] ?? []), true))
            ? $this->pass('ROADMAP-LB1-DEPENDS-STATELESS', 'LB-1 depends on STATELESS-1.')
            : $this->fail('ROADMAP-LB1-DEPENDS-STATELESS', 'LB-1 must depend on STATELESS-1.');

        // PgBouncer (DBPERF-2) must have a rollback plan.
        $pgb = $byId['DBPERF-2'] ?? null;
        $checks[] = ($pgb && ($pgb['requires_rollback_plan'] ?? false))
            ? $this->pass('ROADMAP-PGBOUNCER-ROLLBACK', 'DBPERF-2 (PgBouncer) requires a rollback plan.')
            : $this->fail('ROADMAP-PGBOUNCER-ROLLBACK', 'DBPERF-2 (PgBouncer) must require a connection-pool rollback plan.');

        // CACHE-1 must require invalidation rules.
        $cache = $byId['CACHE-1'] ?? null;
        $checks[] = ($cache && ($cache['requires_invalidation_rules'] ?? false))
            ? $this->pass('ROADMAP-CACHE-INVALIDATION', 'CACHE-1 requires invalidation governance.')
            : $this->fail('ROADMAP-CACHE-INVALIDATION', 'CACHE-1 must require invalidation governance.');

        // QUEUE-1 must require idempotency + outbox rules.
        $queue = $byId['QUEUE-1'] ?? null;
        $checks[] = ($queue && ($queue['requires_idempotency_rules'] ?? false) && ($queue['requires_outbox_rules'] ?? false))
            ? $this->pass('ROADMAP-QUEUE-IDEMPOTENCY-OUTBOX', 'QUEUE-1 requires idempotency + outbox governance.')
            : $this->fail('ROADMAP-QUEUE-IDEMPOTENCY-OUTBOX', 'QUEUE-1 must require idempotency + outbox governance.');

        // STORAGE-1 (object storage) must require rollback plan.
        $storage = $byId['STORAGE-1'] ?? null;
        $checks[] = ($storage && ($storage['requires_rollback_plan'] ?? false))
            ? $this->pass('ROADMAP-STORAGE-ROLLBACK', 'STORAGE-1 requires a local backup + rollback plan.')
            : $this->fail('ROADMAP-STORAGE-ROLLBACK', 'STORAGE-1 must require a local backup + rollback plan.');

        return $checks;
    }

    /**
     * @param  list<array<string, mixed>>  $sequence
     */
    private function nextRecommendedSprint(array $sequence): ?string
    {
        $ordered = $this->orderedByPriority($sequence);

        foreach ($ordered as $item) {
            if ((string) ($item['status'] ?? '') === 'planned') {
                return (string) ($item['id'] ?? '');
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $sequence
     * @return list<array<string, mixed>>
     */
    private function normalizeSequence(array $sequence): array
    {
        return array_map(static fn (array $i) => [
            'id' => (string) ($i['id'] ?? ''),
            'title' => (string) ($i['title'] ?? ''),
            'priority' => (int) ($i['priority'] ?? 0),
            'status' => (string) ($i['status'] ?? ''),
            'category' => (string) ($i['category'] ?? ''),
            'depends_on' => array_values((array) ($i['depends_on'] ?? [])),
            'may_touch_production_infra' => (bool) ($i['may_touch_production_infra'] ?? false),
            'requires_design_first' => (bool) ($i['requires_design_first'] ?? false),
            'is_release_candidate' => (bool) ($i['is_release_candidate'] ?? false),
        ], $this->orderedByPriority($sequence));
    }

    /**
     * @param  list<array<string, mixed>>  $sequence
     * @return list<array<string, mixed>>
     */
    private function orderedByPriority(array $sequence): array
    {
        $ordered = $sequence;
        usort($ordered, static fn (array $a, array $b) => ((int) ($a['priority'] ?? 0)) <=> ((int) ($b['priority'] ?? 0)));

        return array_values($ordered);
    }

    /**
     * @return array<string, mixed>
     */
    private function pass(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'passed', 'blocking' => false, 'message' => $message];
    }

    /**
     * @return array<string, mixed>
     */
    private function warn(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'warning', 'blocking' => false, 'message' => $message];
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'failed', 'blocking' => true, 'message' => $message];
    }
}
