<?php

namespace App\Services\Architecture;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;

class CanonicalMetricReconciliationService
{
    /**
     * @param  array{domain?:string, include_consumers?:bool, include_entity_reference?:bool}  $options
     * @return array<string, mixed>
     */
    public function collect(array $options = []): array
    {
        $domain = $options['domain'] ?? 'all';
        $includeConsumers = (bool) ($options['include_consumers'] ?? true);
        $includeEntityReference = (bool) ($options['include_entity_reference'] ?? false);

        $metrics = $this->filterMetrics($domain);
        $metrics = $this->enrichMetrics($metrics, $includeConsumers, $includeEntityReference);

        $conflicts = CanonicalMetricRegistry::conflicts();
        $gaps = CanonicalMetricRegistry::gaps();

        $byDomain = collect($metrics)->groupBy('domain')->map(
            fn ($group, string $name) => ['domain' => $name, 'metric_count' => $group->count()],
        )->values()->all();

        $canonical = collect($metrics)->where('conflict_status', 'canonical')->count();
        $needsReview = collect($metrics)->where('dmo_readiness', 'needs_review')->count();
        $conflicting = collect($metrics)->where('conflict_status', 'conflicting')->count();
        $duplicate = collect($metrics)->where('conflict_status', 'duplicate')->count();
        $blocked = collect($metrics)->where('dmo_readiness', 'blocked')->count();
        $ready = collect($metrics)->where('dmo_readiness', 'ready')->count();

        $sourceTypes = collect($metrics)->groupBy('source_type')->map->count()->all();
        $sensitivityCounts = collect($metrics)
            ->flatMap(fn (array $m) => $m['sensitivity'])
            ->countBy()
            ->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'metadata' => [
                'app_name' => (string) config('app.name'),
                'laravel_version' => Application::VERSION,
                'php_version' => PHP_VERSION,
                'database_driver' => (string) config('database.default'),
                'nsf_sprint' => 'NSF-5',
                'entity_inventory_command' => 'architecture:canonical-entity-inventory',
            ],
            'summary' => [
                'metrics' => count($metrics),
                'domains' => count($byDomain),
                'canonical' => $canonical,
                'duplicate' => $duplicate,
                'needs_review' => $needsReview,
                'conflicting' => $conflicting,
                'blocked' => $blocked,
                'dmo_ready' => $ready,
                'conflict_groups' => count($conflicts),
                'gap_count' => count($gaps),
                'source_type_counts' => $sourceTypes,
                'sensitivity_counts' => $sensitivityCounts,
            ],
            'domains' => $byDomain,
            'privacy' => [
                'row_level_data' => false,
                'patient_names' => false,
                'ktp_nik' => false,
                'clinical_content' => false,
                'sample_values' => false,
                'financial_row_level' => false,
            ],
            'metrics' => $metrics,
            'conflicts' => $conflicts,
            'gaps' => $gaps,
            'dmo_recommendations' => CanonicalMetricRegistry::dmoRecommendations(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filterMetrics(string $domain): array
    {
        $all = CanonicalMetricRegistry::metrics();

        if ($domain === 'all') {
            return $all;
        }

        return array_values(array_filter($all, fn (array $m) => $m['domain'] === $domain));
    }

    /**
     * @param  list<array<string, mixed>>  $metrics
     * @return list<array<string, mixed>>
     */
    private function enrichMetrics(array $metrics, bool $includeConsumers, bool $includeEntityReference): array
    {
        $entityTables = collect(CanonicalEntityRegistry::entities())
            ->keyBy('canonical_name');

        return array_map(function (array $metric) use ($includeConsumers, $includeEntityReference, $entityTables): array {
            if (! $includeConsumers) {
                $metric['consumers'] = [
                    'routes' => [],
                    'controllers' => [],
                    'reports' => [],
                    'dashboards' => [],
                    'exports' => [],
                    'services' => [],
                ];
            }

            if ($includeEntityReference) {
                $refs = [];
                foreach ($metric['source_entities'] as $entityName) {
                    $entity = $entityTables->get($entityName);
                    if ($entity !== null) {
                        $refs[] = [
                            'canonical_name' => $entity['canonical_name'],
                            'primary_table' => $entity['primary_table'],
                            'source_type' => $entity['source_type'],
                            'dmo_readiness' => $entity['dmo_readiness'],
                        ];
                    }
                }
                $metric['entity_reference'] = $refs;
            }

            foreach ($metric['source_tables'] as $table) {
                if ($table === '' || $table === '0') {
                    continue;
                }
                try {
                    $metric['table_exists'][$table] = Schema::hasTable($table);
                } catch (\Throwable) {
                    $metric['table_exists'][$table] = null;
                }
            }

            return $metric;
        }, $metrics);
    }
}
