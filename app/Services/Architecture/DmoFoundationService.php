<?php

namespace App\Services\Architecture;

use Illuminate\Foundation\Application;

class DmoFoundationService
{
    public function __construct(
        private readonly CanonicalEntityInventoryService $entityService,
        private readonly CanonicalMetricReconciliationService $metricService,
    ) {}

    /**
     * @param  array{domain?:string, include_lineage?:bool, include_backlog?:bool, include_references?:bool}  $options
     * @return array<string, mixed>
     */
    public function collect(array $options = []): array
    {
        $domain = $options['domain'] ?? 'all';
        $includeLineage = (bool) ($options['include_lineage'] ?? true);
        $includeBacklog = (bool) ($options['include_backlog'] ?? true);
        $includeReferences = (bool) ($options['include_references'] ?? false);

        $entityReport = $this->entityService->collect([
            'domain' => $this->mapEntityDomain($domain),
            'include_schema' => true,
            'include_workflows' => true,
        ]);

        $metricReport = $this->metricService->collect([
            'domain' => $this->mapMetricDomain($domain),
            'include_consumers' => true,
            'include_entity_reference' => $includeReferences,
        ]);

        $entities = $entityReport['entities'];
        $metrics = $metricReport['metrics'];
        $workflows = $entityReport['workflows'];
        $relationships = $this->filterRelationships($domain);
        $dimensions = DmoOntologyRegistry::dimensions();
        $lineage = $includeLineage ? $this->filterLineage($domain) : [];
        $backlog = $includeBacklog ? $this->filterBacklog($domain) : [];

        $entityGaps = $entityReport['gaps'];
        $metricGaps = $metricReport['gaps'];
        $totalGaps = count($entityGaps) + count($metricGaps);

        $blockedMetrics = collect($metrics)->where('dmo_readiness', 'blocked')->count();
        $dmoReadyEntities = collect($entities)->where('dmo_readiness', 'ready')->count();
        $dmoReadyMetrics = collect($metrics)->where('dmo_readiness', 'ready')->count();

        $allDomains = array_values(array_unique(array_merge(
            CanonicalEntityRegistry::domains(),
            CanonicalMetricRegistry::domains(),
        )));

        $readinessNotes = [];
        if ($blockedMetrics > 0) {
            $readinessNotes[] = "{$blockedMetrics} blocked metric(s) require DMO-2 resolution";
        }
        if (collect($metrics)->where('dmo_readiness', 'needs_review')->count() > 0) {
            $readinessNotes[] = 'Metrics with needs_review documented in backlog';
        }
        if (collect($entities)->where('dmo_readiness', 'needs_review')->count() > 0) {
            $readinessNotes[] = 'Entities with needs_review (Patient Document, RME Prescription)';
        }

        $decision = $blockedMetrics <= 2 && $dmoReadyEntities >= 50 ? 'GO' : 'WATCH';

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'metadata' => [
                'app_name' => (string) config('app.name'),
                'laravel_version' => Application::VERSION,
                'php_version' => PHP_VERSION,
                'database_driver' => (string) config('database.default'),
                'sprint' => 'DMO-1',
                'inputs' => [
                    'nsf4_command' => 'architecture:canonical-entity-inventory',
                    'nsf5_command' => 'architecture:canonical-metric-reconciliation',
                    'dmo_command' => 'architecture:dmo-foundation',
                ],
            ],
            'summary' => [
                'domains' => count($allDomains),
                'entities' => count($entities),
                'workflows' => count($workflows),
                'metrics' => count($metrics),
                'relationships' => count($relationships),
                'dimensions' => count($dimensions),
                'gaps' => $totalGaps,
                'dmo_ready_entities' => $dmoReadyEntities,
                'dmo_ready_metrics' => $dmoReadyMetrics,
                'blocked_metrics' => $blockedMetrics,
                'backlog_items' => count($backlog),
            ],
            'domains' => $allDomains,
            'canonical_entities' => $entities,
            'canonical_metrics' => $metrics,
            'ontology_relationships' => $relationships,
            'dimensions' => $dimensions,
            'lineage' => $lineage,
            'governance_rules' => DmoOntologyRegistry::governanceRules(),
            'sensitivity_classification' => DmoOntologyRegistry::sensitivityClassification(),
            'dmo_backlog' => $backlog,
            'entity_gaps' => $entityGaps,
            'metric_gaps' => $metricGaps,
            'workflows' => $workflows,
            'privacy' => [
                'row_level_data' => false,
                'patient_names' => false,
                'ktp_nik' => false,
                'clinical_content' => false,
                'sample_values' => false,
                'financial_row_level' => false,
            ],
            'readiness' => [
                'decision' => $decision,
                'notes' => $readinessNotes,
            ],
            'references' => $includeReferences ? [
                'entity_inventory_summary' => $entityReport['summary'],
                'metric_reconciliation_summary' => $metricReport['summary'],
            ] : null,
        ];
    }

    private function mapEntityDomain(string $domain): string
    {
        return match ($domain) {
            'cashier', 'rme', 'inventory', 'lab', 'owner', 'foundation', 'telemetry', 'system', 'all' => $domain === 'system' ? 'telemetry' : $domain,
            default => 'all',
        };
    }

    private function mapMetricDomain(string $domain): string
    {
        return match ($domain) {
            'foundation', 'telemetry' => 'all',
            default => $domain,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filterRelationships(string $domain): array
    {
        $all = DmoOntologyRegistry::relationships();

        if ($domain === 'all') {
            return $all;
        }

        $mapped = $this->mapEntityDomain($domain);

        return array_values(array_filter(
            $all,
            fn (array $r) => $r['domain'] === $mapped || $r['domain'] === $domain,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filterLineage(string $domain): array
    {
        $all = DmoOntologyRegistry::lineage();

        if ($domain === 'all') {
            return $all;
        }

        return array_values(array_filter($all, fn (array $l) => $l['domain'] === $domain));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filterBacklog(string $domain): array
    {
        $all = DmoOntologyRegistry::dmoBacklog();

        if ($domain === 'all') {
            return $all;
        }

        return array_values(array_filter($all, fn (array $b) => $b['area'] === $domain || $b['area'] === 'nda'));
    }
}
