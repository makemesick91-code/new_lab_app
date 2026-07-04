<?php

namespace App\Services\Architecture;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CanonicalEntityInventoryService
{
    /** @var array<string, list<string>> */
    private const DOMAIN_ROUTE_PREFIXES = [
        'foundation' => ['settings.', 'profile.'],
        'rme' => ['rme.'],
        'cashier' => ['rme.', 'invoices.'],
        'inventory' => ['inventory.'],
        'lab' => ['lab-orders.', 'lab-case-candidates.', 'production.', 'quality-control.', 'deliveries.'],
        'owner' => ['dashboard', 'reports.'],
        'telemetry' => ['performance:', 'architecture:'],
    ];

    /** @var array<string, list<string>> */
    private const LIFECYCLE_MAP = [
        'trx_clinic_visits' => ['status'],
        'trx_medical_records' => ['status'],
        'trx_rme_invoices' => ['status'],
        'trx_rme_receivable_follow_ups' => ['status'],
        'trx_lab_orders' => ['status'],
        'trx_lab_case_candidates' => ['status'],
        'trx_purchase_requests' => ['status'],
        'trx_purchase_orders' => ['status'],
        'trx_goods_receipts' => ['status'],
        'trx_stock_transfers' => ['status'],
        'trx_stock_opnames' => ['status'],
        'trx_inventory_batch_disposal_requests' => ['status'],
        'trx_invoices' => ['status'],
    ];

    /** @var array<string, list<string>> */
    private const DOWNSTREAM_MAP = [
        'mst_patients' => ['rme.patients.audit', 'Owner KPI patient counts'],
        'trx_clinic_visits' => ['rme.visits', 'Owner KPI visit trends'],
        'trx_rme_invoices' => ['rme.receivables', 'Owner KPI revenue', 'cashier billing'],
        'trx_inventory_movements' => ['inventory.dashboard', 'inventory.reports', 'rpt_inventory_*'],
        'trx_lab_orders' => ['lab-orders.index', 'Owner RME/Lab KPI'],
        'inv_products' => ['inventory.reports', 'low-stock alerts'],
    ];

    /**
     * @param  array{domain?:string, include_schema?:bool, include_routes?:bool, include_workflows?:bool}  $options
     * @return array<string, mixed>
     */
    public function collect(array $options = []): array
    {
        $domain = $options['domain'] ?? 'all';
        $includeSchema = (bool) ($options['include_schema'] ?? true);
        $includeRoutes = (bool) ($options['include_routes'] ?? false);
        $includeWorkflows = (bool) ($options['include_workflows'] ?? true);

        $entities = $this->enrichEntities($domain, $includeSchema, $includeRoutes);
        $workflows = $includeWorkflows ? $this->filterWorkflows($domain) : [];

        $gaps = CanonicalEntityRegistry::gaps();
        $ready = collect($entities)->where('dmo_readiness', 'ready')->count();
        $needsReview = collect($entities)->where('dmo_readiness', 'needs_review')->count();

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'metadata' => [
                'app_name' => (string) config('app.name'),
                'laravel_version' => Application::VERSION,
                'php_version' => PHP_VERSION,
                'database_driver' => (string) config('database.default'),
            ],
            'domains' => CanonicalEntityRegistry::domains(),
            'summary' => [
                'entity_count' => count($entities),
                'workflow_count' => count($workflows),
                'gap_count' => count($gaps),
                'dmo_ready_count' => $ready,
                'dmo_needs_review_count' => $needsReview,
            ],
            'privacy' => [
                'row_level_data' => false,
                'patient_names' => false,
                'ktp_nik' => false,
                'clinical_content' => false,
                'sample_values' => false,
            ],
            'entities' => $entities,
            'workflows' => $workflows,
            'gaps' => $gaps,
            'dmo_recommendations' => CanonicalEntityRegistry::dmoRecommendations(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function enrichEntities(string $domain, bool $includeSchema, bool $includeRoutes): array
    {
        $entities = [];

        foreach (CanonicalEntityRegistry::entities() as $entity) {
            if ($domain !== 'all' && $entity['domain'] !== $domain) {
                continue;
            }

            $table = $entity['primary_table'];
            if ($table !== null) {
                if ($includeSchema) {
                    $entity['table_exists'] = $this->safeTableExists($table);
                } else {
                    $entity['table_exists'] = null;
                }
                $entity['lifecycle_status_fields'] = self::LIFECYCLE_MAP[$table] ?? [];
                $entity['downstream_consumers'] = self::DOWNSTREAM_MAP[$table] ?? $entity['downstream_consumers'];
            } else {
                $entity['table_exists'] = null;
            }

            if ($includeRoutes) {
                $entity['route_count_hint'] = $this->countRoutesForDomain($entity['domain']);
            }

            if ($entity['model'] !== null && ! class_exists($entity['model'])) {
                $entity['gaps'][] = 'Model class not autoloadable: '.$entity['model'];
            }

            if ($includeSchema && $table !== null && $entity['table_exists'] === false) {
                $entity['gaps'][] = 'Primary table missing: '.$table;
            }

            $entities[] = $entity;
        }

        usort($entities, fn (array $a, array $b) => strcmp($a['canonical_name'], $b['canonical_name']));

        return $entities;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filterWorkflows(string $domain): array
    {
        if ($domain === 'all') {
            return CanonicalEntityRegistry::workflows();
        }

        return array_values(array_filter(
            CanonicalEntityRegistry::workflows(),
            fn (array $w) => $w['domain'] === $domain
        ));
    }

    private function countRoutesForDomain(string $domain): int
    {
        $prefixes = self::DOMAIN_ROUTE_PREFIXES[$domain] ?? [];
        $count = 0;

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName() ?? '';
            foreach ($prefixes as $prefix) {
                if (str_starts_with($name, $prefix) || $name === rtrim($prefix, '.')) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    private function safeTableExists(string $table): ?bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return null;
        }
    }
}
