<?php

namespace App\Services\Architecture;

use Illuminate\Foundation\Application;

/**
 * DMO-2 canonical Owner KPI registry with alias resolution.
 * Read-only metadata — no row-level or sample values.
 */
class OwnerKpiRegistryService
{
    /** @var list<string> */
    private const BLOCKED_METRICS = ['net_revenue', 'pod_count'];

    /**
     * @param  array{include_aliases?:bool, include_consumers?:bool, only?:string}  $options
     * @return array<string, mixed>
     */
    public function collect(array $options = []): array
    {
        $includeAliases = (bool) ($options['include_aliases'] ?? true);
        $includeConsumers = (bool) ($options['include_consumers'] ?? true);
        $only = $options['only'] ?? 'all';

        $ownerKpis = $this->ownerKpis($includeConsumers);
        $aliasMap = $this->aliasMap();
        $blocked = $this->blockedEntries();
        $needsReview = $this->needsReviewEntries();

        $filtered = match ($only) {
            'canonical' => ['owner_kpis' => $ownerKpis, 'alias_map' => [], 'blocked' => [], 'needs_review' => []],
            'aliases' => ['owner_kpis' => [], 'alias_map' => $aliasMap, 'blocked' => [], 'needs_review' => []],
            'blocked' => ['owner_kpis' => [], 'alias_map' => [], 'blocked' => $blocked, 'needs_review' => []],
            'needs_review' => ['owner_kpis' => [], 'alias_map' => [], 'blocked' => [], 'needs_review' => $needsReview],
            default => ['owner_kpis' => $ownerKpis, 'alias_map' => $includeAliases ? $aliasMap : [], 'blocked' => $blocked, 'needs_review' => $needsReview],
        };

        $duplicatesResolved = collect($aliasMap)->where('status', 'resolved')->count();

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'metadata' => [
                'app_name' => (string) config('app.name'),
                'laravel_version' => Application::VERSION,
                'php_version' => PHP_VERSION,
                'database_driver' => (string) config('database.default'),
                'sprint' => 'DMO-2',
                'dmo_m005_status' => 'closed',
                'dmo_m005_notes' => 'Owner KPI aliases unified in canonical registry; UI display keys unchanged',
            ],
            'summary' => [
                'canonical_owner_kpis' => count($ownerKpis),
                'aliases' => count($aliasMap),
                'duplicates_resolved' => $duplicatesResolved,
                'blocked' => count($blocked),
                'needs_review' => count($needsReview),
            ],
            'owner_kpis' => $filtered['owner_kpis'],
            'alias_map' => $filtered['alias_map'],
            'blocked' => $filtered['blocked'],
            'needs_review' => $filtered['needs_review'],
            'governance' => [
                'command' => 'architecture:dmo-governance-check',
                'rules' => collect(config('dmo.rules', []))->pluck('rule_id')->all(),
            ],
            'privacy' => [
                'row_level_data' => false,
                'patient_names' => false,
                'ktp_nik' => false,
                'clinical_content' => false,
                'financial_row_level' => false,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ownerKpiDefinitions(bool $includeConsumers = true): array
    {
        return $this->ownerKpis($includeConsumers);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function aliasMap(): array
    {
        return [
            ['alias' => 'total_revenue', 'alias_of' => 'owner_total_revenue', 'status' => 'resolved', 'context' => 'OwnerDashboardKpiService::metrics array key'],
            ['alias' => 'paid_amount', 'alias_of' => 'owner_total_revenue', 'status' => 'resolved', 'context' => 'Domain canonical metric; Owner KPI uses payment sum'],
            ['alias' => 'active_receivable', 'alias_of' => 'owner_receivable_total', 'status' => 'resolved', 'context' => 'OwnerDashboardKpiService::metrics array key'],
            ['alias' => 'remaining_receivable', 'alias_of' => 'owner_receivable_total', 'status' => 'resolved', 'context' => 'Canonical domain metric name'],
            ['alias' => 'total_visits', 'alias_of' => 'owner_visit_count', 'status' => 'resolved', 'context' => 'OwnerDashboardKpiService::metrics array key'],
            ['alias' => 'new_patients', 'alias_of' => 'owner_patient_count', 'status' => 'resolved', 'context' => 'OwnerDashboardKpiService::metrics array key'],
            ['alias' => 'low_stock_items', 'alias_of' => 'owner_low_stock_count', 'status' => 'resolved', 'context' => 'OwnerDashboardKpiService::metrics array key'],
            ['alias' => 'low_stock_count', 'alias_of' => 'owner_low_stock_count', 'status' => 'resolved', 'context' => 'Domain canonical metric name'],
            ['alias' => 'stock_value', 'alias_of' => 'owner_inventory_value', 'status' => 'resolved', 'context' => 'OwnerDashboardKpiService::metrics array key'],
            ['alias' => 'follow_up_due', 'alias_of' => 'owner_follow_up_count', 'status' => 'resolved', 'context' => 'OwnerDashboardKpiService::metrics array key'],
            ['alias' => 'follow_up_due_count', 'alias_of' => 'owner_follow_up_count', 'status' => 'resolved', 'context' => 'Domain canonical metric name'],
            ['alias' => 'lab_orders_active', 'alias_of' => 'owner_lab_order_count', 'status' => 'resolved', 'context' => 'OwnerDashboardKpiService::metrics array key'],
            ['alias' => 'unpaid_invoices', 'alias_of' => 'owner_receivable_invoice_count', 'status' => 'resolved', 'context' => 'Owner KPI receivable invoice count card'],
            ['alias' => 'receivable_count', 'alias_of' => 'owner_receivable_invoice_count', 'status' => 'resolved', 'context' => 'Domain canonical metric name'],
            ['alias' => 'collection_rate', 'alias_of' => 'owner_collection_rate', 'status' => 'resolved', 'context' => 'OwnerDashboardKpiService::metrics derived KPI'],
            ['alias' => 'owner_receivable_total', 'alias_of' => 'owner_receivable_total', 'status' => 'resolved', 'context' => 'Legacy owner_* metric registry entry — now canonical'],
            ['alias' => 'owner_total_revenue', 'alias_of' => 'owner_total_revenue', 'status' => 'resolved', 'context' => 'Legacy owner_* metric registry entry — now canonical'],
            ['alias' => 'owner_visit_count', 'alias_of' => 'owner_visit_count', 'status' => 'resolved', 'context' => 'Legacy owner_* metric registry entry — now canonical'],
            ['alias' => 'owner_patient_count', 'alias_of' => 'owner_patient_count', 'status' => 'resolved', 'context' => 'Legacy owner_* metric registry entry — now canonical'],
            ['alias' => 'owner_inventory_value', 'alias_of' => 'owner_inventory_value', 'status' => 'resolved', 'context' => 'Legacy owner_* metric registry entry — now canonical'],
            ['alias' => 'owner_low_stock_count', 'alias_of' => 'owner_low_stock_count', 'status' => 'resolved', 'context' => 'Legacy owner_* metric registry entry — now canonical'],
            ['alias' => 'owner_follow_up_count', 'alias_of' => 'owner_follow_up_count', 'status' => 'resolved', 'context' => 'Legacy owner_* metric registry entry — now canonical'],
        ];
    }

    /**
     * @return list<string>
     */
    public function canonicalOwnerKpiNames(): array
    {
        return collect($this->ownerKpiDefinitions(false))->pluck('canonical_kpi_name')->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ownerKpis(bool $includeConsumers): array
    {
        $consumers = $includeConsumers ? [
            'routes' => ['dashboard'],
            'controllers' => ['HomeDashboardController'],
            'services' => ['OwnerDashboardKpiService'],
            'views' => ['dashboards/owner-kpi.blade.php', 'dashboard.blade.php'],
            'reports' => [],
        ] : ['routes' => [], 'controllers' => [], 'services' => [], 'views' => [], 'reports' => []];

        $telemetryConsumers = $includeConsumers ? [
            'routes' => [],
            'controllers' => [],
            'services' => ['PerformanceRuntimeQueryObservabilityCommand', 'SlowQueryAuditCommand'],
            'views' => [],
            'reports' => ['storage/app/performance/dmo2-vps-runtime-query-observability.json'],
        ] : ['routes' => [], 'controllers' => [], 'services' => [], 'views' => [], 'reports' => []];

        return [
            $this->kpi(
                'owner_total_revenue',
                ['Pendapatan', 'Total Revenue', 'Pembayaran'],
                ['owner_total_revenue', 'total_revenue', 'paid_amount'],
                'paid_amount',
                ['RME Payment'],
                ['trx_rme_payments'],
                'derived',
                'SUM(trx_rme_payments.amount) WHERE paid_at in selected period',
                'per_branch_per_date_range',
                ['branch', 'date', 'payment_method'],
                ['branch' => 'Active branches or selected branch filter', 'date' => 'paid_at', 'status' => 'All successful payments in period'],
                ['financial'],
                $consumers,
                'canonical',
                'ready',
                ['DMO-R001', 'DMO-R002', 'DMO-R003', 'DMO-R004', 'DMO-R005', 'DMO-R009'],
            ),
            $this->kpi(
                'owner_paid_amount',
                ['Pembayaran Owner'],
                ['paid_amount'],
                'paid_amount',
                ['RME Payment'],
                ['trx_rme_payments'],
                'derived',
                'Alias of paid_amount — same as owner_total_revenue in pilot',
                'per_branch_per_date_range',
                ['branch', 'date', 'payment_method'],
                ['branch' => 'Active branches or selected branch', 'date' => 'paid_at', 'status' => 'Paid payments only'],
                ['financial'],
                $consumers,
                'alias_resolved',
                'ready',
                ['DMO-R009', 'DMO-R014'],
                ['Documented synonym of owner_total_revenue; gross_revenue (invoiced) deferred DMO-M001'],
            ),
            $this->kpi(
                'owner_receivable_total',
                ['Piutang Aktif', 'Active Receivable'],
                ['active_receivable', 'remaining_receivable', 'owner_receivable_total'],
                'remaining_receivable',
                ['RME Invoice', 'RME Payment', 'Receivable'],
                ['trx_rme_invoices', 'trx_rme_payments'],
                'derived',
                'SUM(max(0, grand_total - payments_sum)) for UNPAID/PARTIAL grand_total>0',
                'per_branch_snapshot',
                ['branch', 'status'],
                ['branch' => 'Active branches or selected branch', 'status' => 'UNPAID/PARTIAL only', 'soft_delete' => 'exclude zero remaining'],
                ['financial'],
                $consumers,
                'canonical',
                'ready',
                ['DMO-R005', 'DMO-R007', 'DMO-R009', 'DMO-R014'],
            ),
            $this->kpi(
                'owner_receivable_invoice_count',
                ['Invoice Belum Lunas', 'Unpaid Invoices'],
                ['unpaid_invoices', 'receivable_count'],
                'receivable_count',
                ['RME Invoice', 'Receivable'],
                ['trx_rme_invoices'],
                'derived',
                'COUNT UNPAID/PARTIAL invoices with remaining>0',
                'per_branch_snapshot',
                ['branch', 'status'],
                ['branch' => 'Active branches or selected branch', 'status' => 'UNPAID/PARTIAL with remaining>0'],
                ['financial'],
                $consumers,
                'canonical',
                'ready',
                ['DMO-R005', 'DMO-R007', 'DMO-R009'],
            ),
            $this->kpi(
                'owner_visit_count',
                ['Total Kunjungan', 'Kunjungan'],
                ['total_visits', 'owner_visit_count'],
                'total_visits',
                ['Clinic Visit'],
                ['trx_clinic_visits'],
                'derived',
                'COUNT WHERE status != cancelled AND visit_date in period',
                'per_branch_per_date_range',
                ['branch', 'date', 'status'],
                ['branch' => 'Active branches or selected branch', 'date' => 'visit_date', 'status' => 'exclude cancelled'],
                ['PII'],
                $consumers,
                'canonical',
                'ready',
                ['DMO-R002', 'DMO-R003', 'DMO-R004', 'DMO-R008', 'DMO-R009'],
            ),
            $this->kpi(
                'owner_patient_count',
                ['Pasien Baru'],
                ['new_patients', 'owner_patient_count'],
                'new_patients',
                ['Patient'],
                ['mst_patients'],
                'derived',
                'COUNT WHERE created_at BETWEEN from AND to',
                'per_branch_per_date_range',
                ['branch', 'date'],
                ['branch' => 'Active branches or selected branch', 'date' => 'created_at'],
                ['PII'],
                $consumers,
                'canonical',
                'ready',
                ['DMO-R002', 'DMO-R003', 'DMO-R004', 'DMO-R008', 'DMO-R009'],
            ),
            $this->kpi(
                'owner_inventory_value',
                ['Nilai Persediaan', 'Stock Value'],
                ['stock_value', 'owner_inventory_value'],
                'stock_value',
                ['Inventory Movement', 'Inventory Product'],
                ['trx_inventory_movements', 'inv_products'],
                'derived',
                'SUM getInventoryValue per active branch — ledger-derived',
                'per_branch_snapshot',
                ['branch', 'location', 'category'],
                ['branch' => 'Active branches aggregated', 'soft_delete' => 'ledger movements only'],
                ['financial'],
                $consumers,
                'canonical',
                'ready',
                ['DMO-R006', 'DMO-R009'],
            ),
            $this->kpi(
                'owner_low_stock_count',
                ['Stok Rendah', 'Low Stock'],
                ['low_stock_items', 'low_stock_count', 'owner_low_stock_count'],
                'low_stock_count',
                ['Inventory Product', 'Inventory Movement', 'Location Product Minimum'],
                ['inv_products', 'trx_inventory_movements', 'inv_location_product_minimums'],
                'computed',
                'SUM getLowStockCount per active branch',
                'per_branch_snapshot',
                ['branch', 'location', 'product'],
                ['branch' => 'Active branches aggregated'],
                ['internal'],
                $consumers,
                'canonical',
                'ready',
                ['DMO-R006', 'DMO-R009', 'DMO-R014'],
            ),
            $this->kpi(
                'owner_follow_up_count',
                ['Follow-up Jatuh Tempo'],
                ['follow_up_due', 'follow_up_due_count', 'owner_follow_up_count'],
                'follow_up_due_count',
                ['RME Invoice', 'Receivable Follow-up'],
                ['trx_rme_invoices', 'trx_rme_receivable_follow_ups'],
                'computed',
                'UNPAID/PARTIAL with latestFollowUp.next_follow_up_date <= today',
                'per_branch_snapshot',
                ['branch', 'date'],
                ['branch' => 'Active branches or selected branch', 'date' => 'next_follow_up_date <= today'],
                ['financial'],
                $consumers,
                'canonical',
                'ready',
                ['DMO-R005', 'DMO-R007', 'DMO-R009', 'DMO-R014'],
            ),
            $this->kpi(
                'owner_lab_order_count',
                ['Order Lab Aktif'],
                ['lab_orders_active', 'owner_lab_order_count'],
                'lab_orders_active',
                ['Lab Order'],
                ['trx_lab_orders'],
                'computed',
                'COUNT WHERE status NOT IN delivered, completed, cancelled — global lab',
                'global_snapshot',
                ['status'],
                ['status' => 'Exclude delivered/completed/cancelled', 'branch' => 'Lab global — not branch-grouped Sprint 23.5'],
                ['internal'],
                $consumers,
                'canonical',
                'ready',
                ['DMO-R009'],
            ),
            $this->kpi(
                'owner_collection_rate',
                ['Tingkat Penagihan', 'Collection Rate'],
                ['collection_rate'],
                'collection_rate',
                ['RME Payment', 'RME Invoice'],
                ['trx_rme_payments', 'trx_rme_invoices'],
                'computed',
                'paid_amount / billable_grand_total * 100 in period',
                'per_branch_per_date_range',
                ['branch', 'date'],
                ['branch' => 'Active branches or selected branch', 'date' => 'paid_at vs invoice created_at', 'status' => 'Exclude VOID/DRAFT invoices from billable'],
                ['financial'],
                $consumers,
                'canonical',
                'ready',
                ['DMO-R005', 'DMO-R009'],
            ),
            $this->kpi(
                'owner_runtime_query_risk',
                ['Runtime Query Risk', 'Slow Query Risk'],
                ['runtime_query_total_time', 'slow_query_count', 'pg_stat_top_query'],
                'runtime_query_total_time',
                ['Performance Runtime Evidence'],
                [],
                'telemetry',
                'Top queries by total_time from pg_stat_statements + slow-query-audit flags',
                'per_environment_snapshot',
                ['query_pattern', 'module'],
                ['branch' => 'N/A — system telemetry', 'date' => 'snapshot at command run'],
                ['telemetry'],
                $telemetryConsumers,
                'canonical',
                'ready',
                ['DMO-R008', 'DMO-R012'],
                ['No PHI in query text; privacy-safe aggregates only'],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blockedEntries(): array
    {
        return [
            [
                'metric' => 'net_revenue',
                'reason' => 'No canonical net revenue field in pilot; paid_amount used as revenue KPI',
                'backlog' => 'DMO-M001',
                'owner_kpi_eligible' => false,
            ],
            [
                'metric' => 'pod_count',
                'reason' => 'POD field not standardized for metrics',
                'backlog' => 'DMO-M007',
                'owner_kpi_eligible' => false,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function needsReviewEntries(): array
    {
        return [
            [
                'metric' => 'gross_revenue',
                'reason' => 'Invoiced amount differs from collected paid_amount in pilot',
                'backlog' => 'DMO-M001',
                'owner_kpi_eligible' => false,
            ],
            [
                'metric' => 'receivable_aging_bucket',
                'reason' => 'No persisted aging table',
                'backlog' => 'DMO-M003',
                'owner_kpi_eligible' => false,
            ],
            [
                'metric' => 'overdue_receivable_count',
                'reason' => 'Overlaps follow_up_due_count — unified under owner_follow_up_count',
                'backlog' => 'DMO-M005',
                'owner_kpi_eligible' => false,
            ],
        ];
    }

    /**
     * @param  list<string>  $displayLabels
     * @param  list<string>  $aliases
     * @param  list<string>  $sourceEntities
     * @param  list<string>  $sourceTables
     * @param  list<string>  $dimensions
     * @param  array<string, string>  $filters
     * @param  list<string>  $sensitivity
     * @param  array<string, list<string>>  $consumers
     * @param  list<string>  $dmoRuleCoverage
     * @param  list<string>  $notes
     */
    private function kpi(
        string $canonicalKpiName,
        array $displayLabels,
        array $aliases,
        string $sourceCanonicalMetric,
        array $sourceEntities,
        array $sourceTables,
        string $sourceType,
        string $formulaReference,
        string $grain,
        array $dimensions,
        array $filters,
        array $sensitivity,
        array $consumers,
        string $conflictStatus,
        string $dmoReadiness,
        array $dmoRuleCoverage,
        array $notes = [],
    ): array {
        return [
            'canonical_kpi_name' => $canonicalKpiName,
            'display_labels_current' => $displayLabels,
            'aliases' => $aliases,
            'source_canonical_metric' => $sourceCanonicalMetric,
            'source_entities' => $sourceEntities,
            'source_tables' => $sourceTables,
            'source_type' => $sourceType,
            'formula_reference' => $formulaReference,
            'grain' => $grain,
            'dimensions' => $dimensions,
            'filters' => $filters,
            'sensitivity' => $sensitivity,
            'owner_module' => 'Reporting',
            'consumers' => $consumers,
            'conflict_status' => $conflictStatus,
            'dmo_rule_coverage' => $dmoRuleCoverage,
            'dmo_readiness' => $dmoReadiness,
            'notes' => $notes,
        ];
    }
}
