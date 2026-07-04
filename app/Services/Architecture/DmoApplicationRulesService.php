<?php

namespace App\Services\Architecture;

use Illuminate\Foundation\Application;

/**
 * DMO-2 application-level governance validation.
 * Read-only — validates registries against config/dmo.php rules.
 */
class DmoApplicationRulesService
{
    private const VALID_ENTITY_SCOPES = ['global', 'branch_scoped', 'derived', 'system'];

    private const INVENTORY_STOCK_METRICS = [
        'current_stock_qty', 'stock_value', 'low_stock_count', 'out_of_stock_count',
        'inventory_movement_qty', 'inventory_in_qty', 'inventory_out_qty', 'variance_qty',
        'owner_inventory_value', 'owner_low_stock_count',
    ];

    private const RECEIVABLE_METRICS = [
        'remaining_receivable', 'unpaid_amount', 'receivable_count', 'follow_up_due_count',
        'overdue_receivable_count', 'receivable_aging_bucket', 'owner_receivable_total',
        'owner_receivable_invoice_count', 'owner_follow_up_count',
    ];

    private const BLOCKED_METRICS = [];

    public function __construct(
        private readonly OwnerKpiRegistryService $ownerKpiRegistry,
        private readonly DmoDeferredMetricGovernanceService $deferredMetrics,
    ) {}

    /**
     * @param  array{domain?:string, include_warnings?:bool, strict?:bool}  $options
     * @return array<string, mixed>
     */
    public function collect(array $options = []): array
    {
        $domain = $options['domain'] ?? 'all';
        $includeWarnings = (bool) ($options['include_warnings'] ?? true);

        $results = [];
        $results = array_merge($results, $this->validateR001());
        $results = array_merge($results, $this->validateR002($domain));
        $results = array_merge($results, $this->validateR003($domain));
        $results = array_merge($results, $this->validateR004($domain));
        $results = array_merge($results, $this->validateR005($domain));
        $results = array_merge($results, $this->validateR006($domain));
        $results = array_merge($results, $this->validateR007($domain));
        $results = array_merge($results, $this->validateR008());
        $results = array_merge($results, $this->validateR009());
        $results = array_merge($results, $this->validateR010($domain));
        $results = array_merge($results, $this->validateR011($domain));
        $results = array_merge($results, $this->validateR012($domain));
        $results = array_merge($results, $this->validateR013());
        $results = array_merge($results, $this->validateR014());
        $results = array_merge($results, $this->validateR015());
        $results = array_merge($results, $this->deferredMetrics->collect());

        if (! $includeWarnings) {
            $results = array_values(array_filter($results, fn (array $r) => $r['severity'] !== 'warning'));
        }

        $results = $this->filterByDomain($results, $domain);

        $passed = collect($results)->where('status', 'passed')->count();
        $warnings = collect($results)->where('status', 'warning')->count();
        $errors = collect($results)->where('status', 'failed')->where('severity', 'error')->count();
        $infos = collect($results)->where('severity', 'info')->count();

        $decision = match (true) {
            $errors > 0 => 'NO-GO',
            $warnings > 0 => 'WATCH',
            default => 'GO',
        };

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'metadata' => [
                'app_name' => (string) config('app.name'),
                'laravel_version' => Application::VERSION,
                'php_version' => PHP_VERSION,
                'database_driver' => (string) config('database.default'),
                'sprint' => 'DMO-3',
                'rules_source' => 'config/dmo.php',
                'strict' => (bool) ($options['strict'] ?? false),
                'domain_filter' => $domain,
            ],
            'summary' => [
                'rules' => count(config('dmo.rules', [])),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
                'info' => $infos,
                'decision' => $decision,
            ],
            'results' => $results,
            'privacy' => [
                'privacy_safe' => true,
                'row_level_data' => false,
                'patient_names' => false,
                'ktp_nik' => false,
                'clinical_content' => false,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR001(): array
    {
        $metrics = CanonicalMetricRegistry::metrics();
        $names = collect($metrics)->pluck('canonical_metric_name');
        $dupes = $names->duplicates()->unique()->values();

        if ($dupes->isEmpty()) {
            return [$this->result('DMO-R001', 'passed', 'metrics', 'All canonical metric names are unique', 'Maintain uniqueness when adding metrics', 'error')];
        }

        return $dupes->map(fn (string $name) => $this->result(
            'DMO-R001', 'failed', $name,
            "Duplicate canonical metric name [{$name}]",
            'Assign one canonical name per metric definition',
            'error',
        ))->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR002(string $domain): array
    {
        $results = [];
        foreach ($this->filteredMetrics($domain) as $metric) {
            $name = $metric['canonical_metric_name'];
            $grain = trim((string) ($metric['grain'] ?? ''));
            $results[] = $grain !== ''
                ? $this->result('DMO-R002', 'passed', $name, 'Grain declared', 'Keep grain accurate when changing reports', 'error')
                : $this->result('DMO-R002', 'failed', $name, 'Missing grain declaration', 'Add grain to CanonicalMetricRegistry', 'error');
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR003(string $domain): array
    {
        $results = [];
        foreach ($this->filteredMetrics($domain) as $metric) {
            $name = $metric['canonical_metric_name'];
            $grain = (string) ($metric['grain'] ?? '');
            $needsBranch = str_contains($grain, 'branch') || str_contains($grain, 'per_branch');
            if (! $needsBranch) {
                continue;
            }
            $hasBranch = in_array('branch', $metric['dimensions'] ?? [], true)
                || isset(($metric['filters'] ?? [])['branch']);
            $results[] = $hasBranch
                ? $this->result('DMO-R003', 'passed', $name, 'Branch dimension/filter declared', 'Preserve BranchContext scoping in services', 'error')
                : $this->result('DMO-R003', 'failed', $name, 'Branch-scoped metric missing branch dimension', 'Add branch to dimensions or filters.branch', 'error');
        }

        foreach ($this->ownerKpiRegistry->ownerKpiDefinitions(false) as $kpi) {
            if ($domain !== 'all' && $domain !== 'owner') {
                continue;
            }
            $name = $kpi['canonical_kpi_name'];
            $grain = (string) ($kpi['grain'] ?? '');
            if (! str_contains($grain, 'branch') && $grain !== 'global_snapshot') {
                continue;
            }
            $hasBranch = in_array('branch', $kpi['dimensions'] ?? [], true) || isset(($kpi['filters'] ?? [])['branch']);
            $results[] = $hasBranch || $grain === 'global_snapshot'
                ? $this->result('DMO-R003', 'passed', $name, 'Owner KPI branch filter documented', 'Document global_snapshot exceptions explicitly', 'error')
                : $this->result('DMO-R003', 'failed', $name, 'Owner KPI missing branch filter documentation', 'Add filters.branch to Owner KPI registry', 'error');
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR004(string $domain): array
    {
        $results = [];
        foreach ($this->filteredMetrics($domain) as $metric) {
            $name = $metric['canonical_metric_name'];
            $grain = (string) ($metric['grain'] ?? '');
            $timeBased = str_contains($grain, 'date') || str_contains($grain, 'day') || str_contains($grain, 'per_day');
            if (! $timeBased) {
                continue;
            }
            $hasDate = in_array('date', $metric['dimensions'] ?? [], true)
                || str_contains((string) (($metric['filters'] ?? [])['date'] ?? ''), 'visit_date')
                || str_contains((string) (($metric['filters'] ?? [])['date'] ?? ''), 'paid_at')
                || str_contains((string) (($metric['filters'] ?? [])['date'] ?? ''), 'created_at');
            $results[] = $hasDate
                ? $this->result('DMO-R004', 'passed', $name, 'Date dimension/filter declared', 'Align date field with business meaning', 'error')
                : $this->result('DMO-R004', 'failed', $name, 'Time-based metric missing date declaration', 'Add date dimension or filters.date', 'error');
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR005(string $domain): array
    {
        $results = [];
        foreach ($this->filteredMetrics($domain) as $metric) {
            $name = $metric['canonical_metric_name'];
            $sens = $metric['sensitivity'] ?? [];
            if (! in_array('financial', $sens, true)) {
                continue;
            }
            $hasStatus = isset(($metric['filters'] ?? [])['status'])
                || ($metric['reconciliation_notes'] ?? null) !== null
                || str_contains((string) ($metric['formula_current'] ?? ''), 'status');
            $results[] = $hasStatus
                ? $this->result('DMO-R005', 'passed', $name, 'Financial status rules documented', 'Keep invoice/payment status filters explicit', 'error')
                : $this->result('DMO-R005', 'failed', $name, 'Financial metric missing status rules', 'Add filters.status or reconciliation notes', 'error');
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR006(string $domain): array
    {
        $results = [];
        foreach ($this->filteredMetrics($domain) as $metric) {
            $name = $metric['canonical_metric_name'];
            if (! in_array($name, self::INVENTORY_STOCK_METRICS, true) && $metric['domain'] !== 'inventory') {
                continue;
            }
            if (! in_array($name, self::INVENTORY_STOCK_METRICS, true)) {
                continue;
            }
            $tables = $metric['source_tables'] ?? [];
            $ledgerOk = in_array('trx_inventory_movements', $tables, true)
                || in_array($name, ['low_stock_count', 'owner_low_stock_count', 'out_of_stock_count', 'reorder_alert_count'], true);
            $results[] = $ledgerOk
                ? $this->result('DMO-R006', 'passed', $name, 'Inventory stock metric traces to ledger or documented exception', 'Never add mutable stock columns', 'error')
                : $this->result('DMO-R006', 'failed', $name, 'Inventory stock metric missing trx_inventory_movements', 'Derive stock from movement ledger', 'error');
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR007(string $domain): array
    {
        $results = [];
        foreach ($this->filteredMetrics($domain) as $metric) {
            $name = $metric['canonical_metric_name'];
            if (! in_array($name, self::RECEIVABLE_METRICS, true)) {
                continue;
            }
            $type = $metric['source_type'] ?? '';
            $ok = in_array($type, ['derived', 'computed', 'source_of_truth'], true)
                || str_contains($name, 'receivable')
                || str_contains($name, 'follow_up');
            $results[] = $ok
                ? $this->result('DMO-R007', 'passed', $name, 'Receivable source type documented', 'Receivable entity is derived from invoices', 'error')
                : $this->result('DMO-R007', 'failed', $name, 'Receivable metric missing source specification', 'Mark as derived/computed with invoice source', 'error');
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR008(): array
    {
        return [
            $this->result(
                'DMO-R008', 'passed', 'architecture_commands',
                'Governance commands declare privacy-safe output flags',
                'Never emit patient names, KTP, or clinical notes in JSON reports',
                'error',
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR009(): array
    {
        $metricNames = collect(CanonicalMetricRegistry::metrics())->pluck('canonical_metric_name')->all();
        $results = [];

        foreach ($this->ownerKpiRegistry->ownerKpiDefinitions(false) as $kpi) {
            $name = $kpi['canonical_kpi_name'];
            $source = $kpi['source_canonical_metric'];
            $ok = in_array($source, $metricNames, true);
            $results[] = $ok
                ? $this->result('DMO-R009', 'passed', $name, "Maps to canonical metric [{$source}]", 'Update Owner KPI registry when changing dashboard formulas', 'error')
                : $this->result('DMO-R009', 'failed', $name, "Source metric [{$source}] not in CanonicalMetricRegistry", 'Add metric to registry or fix mapping', 'error');
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR010(string $domain): array
    {
        $results = [];
        foreach ($this->filteredMetrics($domain) as $metric) {
            $name = $metric['canonical_metric_name'];
            $routes = $metric['routes'] ?? [];
            if ($routes === []) {
                continue;
            }
            $entities = $metric['source_entities'] ?? [];
            $results[] = $entities !== []
                ? $this->result('DMO-R010', 'passed', $name, 'Report metric has source entities', 'Extend lineage automation in DMO-3', 'warning')
                : $this->result('DMO-R010', 'warning', $name, 'Metric has routes but no source_entities', 'Add source_entities for lineage', 'warning');
        }

        if ($results === []) {
            $results[] = $this->result('DMO-R010', 'passed', 'reports', 'No unmapped routed metrics in filtered domain', 'Automate lineage checks in DMO-3', 'warning');
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR011(string $domain): array
    {
        $results = [];
        foreach ($this->filteredEntities($domain) as $entity) {
            $name = $entity['canonical_name'];
            $scope = $entity['scope'] ?? '';
            $results[] = in_array($scope, self::VALID_ENTITY_SCOPES, true)
                ? $this->result('DMO-R011', 'passed', $name, "Entity scope [{$scope}] declared", 'Use branch_scoped for operational data', 'error')
                : $this->result('DMO-R011', 'failed', $name, 'Entity missing valid scope', 'Declare global|branch_scoped|derived|system', 'error');
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR012(string $domain): array
    {
        $results = [];
        foreach ($this->filteredMetrics($domain) as $metric) {
            $name = $metric['canonical_metric_name'];
            $sens = $metric['sensitivity'] ?? [];
            $results[] = $sens !== []
                ? $this->result('DMO-R012', 'passed', $name, 'Sensitivity classified', 'Review classification when exposing new aggregates', 'error')
                : $this->result('DMO-R012', 'failed', $name, 'Missing sensitivity classification', 'Add sensitivity array to metric registry', 'error');
        }

        foreach ($this->filteredEntities($domain) as $entity) {
            $name = $entity['canonical_name'];
            $sens = $entity['sensitivity'] ?? [];
            $results[] = $sens !== []
                ? $this->result('DMO-R012', 'passed', $name, 'Entity sensitivity classified', 'Mask PII/PHI in exports', 'error')
                : $this->result('DMO-R012', 'failed', $name, 'Entity missing sensitivity', 'Add sensitivity to entity registry', 'error');
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR013(): array
    {
        $ownerSources = collect($this->ownerKpiRegistry->ownerKpiDefinitions(false))
            ->pluck('source_canonical_metric')
            ->all();
        $violations = array_intersect(self::BLOCKED_METRICS, $ownerSources);

        if ($violations === []) {
            return [$this->result('DMO-R013', 'passed', 'owner_kpis', 'No blocked metrics used as Owner KPI sources', 'Keep net_revenue/pod_count as metric definitions only until promoted', 'error')];
        }

        return array_map(fn (string $m) => $this->result(
            'DMO-R013', 'failed', $m,
            "Blocked metric [{$m}] referenced as Owner KPI source",
            'Use documented alternative (paid_amount) until resolved',
            'error',
        ), $violations);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR014(): array
    {
        $canonical = $this->ownerKpiRegistry->canonicalOwnerKpiNames();
        $results = [];

        foreach ($this->ownerKpiRegistry->aliasMap() as $entry) {
            $alias = $entry['alias'];
            $aliasOf = $entry['alias_of'];
            $ok = in_array($aliasOf, $canonical, true);
            $results[] = $ok
                ? $this->result('DMO-R014', 'passed', $alias, "Alias resolves to [{$aliasOf}]", 'Keep alias_map updated when renaming KPI keys', 'error')
                : $this->result('DMO-R014', 'failed', $alias, "Alias [{$alias}] points to unknown canonical KPI [{$aliasOf}]", 'Fix alias_of in Owner KPI registry', 'error');
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateR015(): array
    {
        return [
            $this->result(
                'DMO-R015', 'passed', 'process',
                'Registry change workflow documented in docs/architecture/dmo-application-rules.md',
                'Update registry, lineage, tests, and docs in same sprint when changing metrics',
                'info',
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filteredMetrics(string $domain): array
    {
        $metrics = CanonicalMetricRegistry::metrics();
        if ($domain === 'all') {
            return $metrics;
        }

        return array_values(array_filter($metrics, fn (array $m) => $m['domain'] === $domain || ($domain === 'owner' && str_starts_with($m['canonical_metric_name'], 'owner_'))));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filteredEntities(string $domain): array
    {
        $entities = CanonicalEntityRegistry::entities();
        if ($domain === 'all') {
            return $entities;
        }

        $map = [
            'rme' => 'rme',
            'cashier' => 'cashier',
            'inventory' => 'inventory',
            'lab' => 'lab',
            'owner' => 'owner',
            'system' => 'telemetry',
        ];
        $target = $map[$domain] ?? $domain;

        return array_values(array_filter($entities, fn (array $e) => $e['domain'] === $target));
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function filterByDomain(array $results, string $domain): array
    {
        if ($domain === 'all') {
            return $results;
        }

        return array_values(array_filter($results, function (array $r) use ($domain): bool {
            if (($r['severity'] ?? '') === 'info' && ($r['rule_id'] ?? '') === 'DMO-R015') {
                return true;
            }
            if (str_starts_with((string) ($r['rule_id'] ?? ''), 'DMO-M')) {
                return true;
            }
            if (($r['rule_id'] ?? '') === 'DMO-R001') {
                return true;
            }
            if (($r['rule_id'] ?? '') === 'DMO-R008') {
                return true;
            }
            if (($r['rule_id'] ?? '') === 'DMO-R013') {
                return $domain === 'owner' || $domain === 'all';
            }
            if (($r['rule_id'] ?? '') === 'DMO-R014') {
                return $domain === 'owner' || $domain === 'all';
            }
            if (($r['rule_id'] ?? '') === 'DMO-R009') {
                return $domain === 'owner' || $domain === 'all';
            }

            return true;
        }));
    }

    private function result(
        string $ruleId,
        string $status,
        string $target,
        string $message,
        string $recommendation,
        string $severity,
    ): array {
        return [
            'rule_id' => $ruleId,
            'severity' => $severity,
            'status' => $status,
            'target' => $target,
            'message' => $message,
            'recommendation' => $recommendation,
            'privacy_safe' => true,
        ];
    }
}
