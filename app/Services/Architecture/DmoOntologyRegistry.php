<?php

namespace App\Services\Architecture;

/**
 * Static ontology, dimension, lineage, and governance metadata for DMO-1.
 * Read-only — no row-level data.
 */
class DmoOntologyRegistry
{
    /** @return list<array<string, mixed>> */
    public static function relationships(): array
    {
        return [
            self::rel('Branch', 'Clinic Visit', 'scopes', 'one_to_many', 'foundation', 'BranchContext scopes operational visits'),
            self::rel('Branch', 'Patient', 'scopes', 'one_to_many', 'foundation', 'Patient master branch-anchored'),
            self::rel('Branch', 'Inventory Movement', 'scopes', 'one_to_many', 'inventory', 'Ledger movements branch-scoped'),
            self::rel('Branch', 'RME Invoice', 'scopes', 'one_to_many', 'cashier', 'Billing branch isolation'),
            self::rel('Branch', 'RME Payment', 'scopes', 'one_to_many', 'cashier', 'Payments inherit invoice branch'),
            self::rel('Patient', 'Clinic Visit', 'has_many', 'one_to_many', 'rme', 'Patient-centric RM workspace'),
            self::rel('Clinic Visit', 'Medical Record', 'has_one', 'one_to_one', 'rme', 'UNIQUE(clinic_visit_id) per sheet'),
            self::rel('Clinic Visit', 'Odontogram', 'has_one', 'one_to_one', 'rme', 'Structured tooth_map_payload'),
            self::rel('Clinic Visit', 'Clinic Visit', 'belongs_to', 'many_to_one', 'rme', 'follow_up_parent_visit_id optional'),
            self::rel('Clinic Visit', 'RME Invoice', 'produces', 'one_to_many', 'cashier', 'After doctor cashier_pending handoff'),
            self::rel('RME Invoice', 'RME Payment', 'has_many', 'one_to_many', 'cashier', 'Full/partial payment; batch_uuid for carry-over'),
            self::rel('RME Invoice', 'Receivable', 'derives_from', 'one_to_one', 'cashier', 'UNPAID/PARTIAL with remaining>0 — no separate table'),
            self::rel('Treatment', 'RME Invoice Item', 'references', 'one_to_many', 'foundation', 'Nullable treatment_id; free-text description allowed'),
            self::rel('Tariff', 'RME Invoice Item', 'references', 'one_to_many', 'foundation', 'Multi-branch pricing boundary — DMO-006 backlog'),
            self::rel('Inventory Product', 'Inventory Movement', 'has_many', 'one_to_many', 'inventory', 'Ledger-only stock truth'),
            self::rel('Inventory Movement', 'Current Stock', 'derives_from', 'aggregates', 'inventory', 'SUM(quantity_in)-SUM(quantity_out) — no mutable stock column'),
            self::rel('Inventory Batch', 'Inventory Movement', 'attaches_to', 'one_to_many', 'inventory', 'Batch/lot on movements and GR'),
            self::rel('Purchase Request', 'Purchase Order', 'produces', 'one_to_many', 'inventory', 'Procurement chain'),
            self::rel('Purchase Order', 'Goods Receipt', 'produces', 'one_to_many', 'inventory', 'GR posts ledger in movements'),
            self::rel('Goods Receipt', 'Inventory Movement', 'produces', 'one_to_many', 'inventory', 'quantity_in on receipt'),
            self::rel('Stock Transfer', 'Inventory Movement', 'produces', 'one_to_many', 'inventory', 'Transfer out + transfer in pair'),
            self::rel('Stock Opname', 'Inventory Movement', 'produces', 'one_to_many', 'inventory', 'Variance adjustment movements'),
            self::rel('RME Invoice Item', 'Lab Case Candidate', 'produces', 'zero_to_one', 'lab', 'requires_lab treatments after payment'),
            self::rel('Lab Case Candidate', 'Lab Order', 'converts_to', 'zero_to_one', 'lab', 'Admin review conversion'),
            self::rel('Owner Dashboard KPI', 'Canonical Metrics', 'consumes', 'many_to_many', 'owner', 'OwnerDashboardKpiService aggregates domain metrics'),
            self::rel('Performance Runtime Evidence', 'pg_stat_statements', 'consumes', 'one_to_many', 'system', 'Read-only telemetry — no PHI in query text'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function dimensions(): array
    {
        return [
            self::dim('branch', 'Branch', 'mst_branches', 'BranchContext::requireId() for operator; Owner may aggregate active branches'),
            self::dim('region', 'Branch region', 'mst_branches', 'Deferred NDA national dimension — not materialized'),
            self::dim('date', 'Calendar date', 'varies', 'visit_date | created_at | paid_at | movement_date | expiry_date per metric'),
            self::dim('doctor', 'Doctor', 'mst_doctors', 'RME clinical and billing attribution'),
            self::dim('treatment', 'Treatment', 'mst_treatments', 'Clinical service catalog; multi-branch tariff boundary open'),
            self::dim('payment_method', 'Payment Method', 'mst_payment_methods', 'Cashier payment allocation'),
            self::dim('product', 'Inventory Product', 'inv_products', 'Stock and valuation reports'),
            self::dim('warehouse_location', 'Inventory Location', 'inv_inventory_locations', 'Room vs warehouse stock'),
            self::dim('batch', 'Inventory Batch', 'inv_inventory_batches', 'Lot/expiry governance'),
            self::dim('status', 'Domain status', 'varies', 'Visit, invoice, PO, lab, opname status enums'),
            self::dim('aging_bucket', 'Receivable aging', 'derived', 'Computed — no persisted bucket table (DMO-M003)'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function lineage(): array
    {
        return [
            self::lineageEntry('Patient', 'new_patients', 'Owner Dashboard', 'owner', 'Patient registration counts'),
            self::lineageEntry('Clinic Visit', 'total_visits', 'Owner Dashboard / RME reports', 'rme', 'Visit trends exclude cancelled where noted'),
            self::lineageEntry('RME Invoice', 'remaining_receivable', 'Receivable report / Owner KPI', 'cashier', 'Derived from invoice minus payments'),
            self::lineageEntry('RME Payment', 'paid_amount', 'Owner Dashboard / cashier', 'cashier', 'Collected revenue KPI'),
            self::lineageEntry('Inventory Movement', 'current_stock_qty', 'Inventory dashboard / reports', 'inventory', 'Ledger aggregation only'),
            self::lineageEntry('Inventory Movement', 'stock_value', 'Inventory reports / Owner KPI', 'inventory', 'Valuation from movement cost'),
            self::lineageEntry('Lab Order', 'lab_orders_active', 'Owner Dashboard / lab queue', 'lab', 'Production/QC status pipeline'),
            self::lineageEntry('Performance Runtime Evidence', 'pg_stat_top_query', 'NSF-3 observability command', 'system', 'Telemetry — privacy-safe aggregates'),
            self::lineageEntry('Owner Dashboard KPI', 'owner_* aliases', 'dashboard route', 'owner', 'Duplicate aliases until DMO-M005 unified'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function governanceRules(): array
    {
        return [
            ['id' => 'DMO-R001', 'rule' => 'One metric must have one canonical definition', 'enforcement' => 'CanonicalMetricRegistry canonical_metric_name uniqueness'],
            ['id' => 'DMO-R002', 'rule' => 'Every canonical metric must declare grain', 'enforcement' => 'grain field required in metric registry'],
            ['id' => 'DMO-R003', 'rule' => 'Every branch-scoped metric must declare branch filter', 'enforcement' => 'filters.branch in metric registry'],
            ['id' => 'DMO-R004', 'rule' => 'Every time-based metric must declare date field', 'enforcement' => 'filters.date explicit per metric'],
            ['id' => 'DMO-R005', 'rule' => 'Every financial metric must declare invoice/payment status rules', 'enforcement' => 'filters.status + reconciliation notes'],
            ['id' => 'DMO-R006', 'rule' => 'Every inventory stock metric must be ledger-derived', 'enforcement' => 'source_tables includes trx_inventory_movements'],
            ['id' => 'DMO-R007', 'rule' => 'Every receivable metric must specify derived or persisted source', 'enforcement' => 'Receivable entity marked derived; DMO-M003 for aging'],
            ['id' => 'DMO-R008', 'rule' => 'Every clinical metric must avoid PHI in aggregate reports', 'enforcement' => 'Counts only; no names/notes in command output'],
            ['id' => 'DMO-R009', 'rule' => 'Every owner dashboard KPI must map to canonical metric alias', 'enforcement' => 'OwnerKpiRegistryService + architecture:owner-kpi-registry'],
            ['id' => 'DMO-R010', 'rule' => 'Every report/export must map to canonical entity + canonical metric', 'enforcement' => 'Lineage registry + architecture:dmo-governance-check'],
            ['id' => 'DMO-R011', 'rule' => 'Every canonical entity must declare scope', 'enforcement' => 'CanonicalEntityRegistry scope field'],
            ['id' => 'DMO-R012', 'rule' => 'Every sensitive entity/metric must declare sensitivity', 'enforcement' => 'sensitivity arrays in entity/metric registries'],
            ['id' => 'DMO-R013', 'rule' => 'Blocked metrics must not be Owner KPI sources', 'enforcement' => 'OwnerKpiRegistryService blocked list + governance check'],
            ['id' => 'DMO-R014', 'rule' => 'Duplicate aliases must point to alias_of canonical KPI', 'enforcement' => 'OwnerKpiRegistryService alias_map'],
            ['id' => 'DMO-R015', 'rule' => 'Future metric changes must update registry, lineage, tests, docs', 'enforcement' => 'docs/architecture/dmo-application-rules.md workflow'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function sensitivityClassification(): array
    {
        return [
            ['class' => 'none', 'description' => 'Non-sensitive operational metadata', 'examples' => ['product categories', 'units']],
            ['class' => 'internal', 'description' => 'Staff/branch operational data', 'examples' => ['inventory movements', 'lab order status']],
            ['class' => 'PII', 'description' => 'Patient identity — mask in UI/export', 'examples' => ['Patient', 'visit counts with PII sensitivity']],
            ['class' => 'PHI', 'description' => 'Clinical content — never in aggregate exports', 'examples' => ['Medical Record', 'Odontogram', 'handwriting']],
            ['class' => 'financial', 'description' => 'Billing and stock valuation aggregates', 'examples' => ['paid_amount', 'remaining_receivable', 'stock_value']],
            ['class' => 'telemetry', 'description' => 'Performance evidence without row-level PHI', 'examples' => ['pg_stat_statements', 'slow_query_audit']],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function dmoBacklog(): array
    {
        $entityGaps = CanonicalEntityRegistry::gaps();
        $metricGaps = CanonicalMetricRegistry::gaps();

        return array_merge(
            array_map(fn (array $g) => array_merge($g, ['source' => 'NSF-4']), $entityGaps),
            array_map(fn (array $g) => array_merge($g, ['source' => 'NSF-5']), $metricGaps),
            [
                ['id' => 'DMO-B001', 'area' => 'owner', 'gap' => 'Owner KPI alias duplicate cleanup (10 aliases)', 'severity' => 'medium', 'source' => 'DMO-1'],
                ['id' => 'DMO-B002', 'area' => 'reporting', 'gap' => 'Report/export lineage automation', 'severity' => 'low', 'source' => 'DMO-1'],
                ['id' => 'DMO-B003', 'area' => 'nda', 'gap' => 'National-scale dimension standard (region, multi-site)', 'severity' => 'medium', 'source' => 'DMO-1'],
                ['id' => 'DMO-B004', 'area' => 'lab', 'gap' => 'pod_count blocked pending POD module/source confirmation', 'severity' => 'medium', 'source' => 'NSF-5'],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function rel(
        string $source,
        string $target,
        string $type,
        string $cardinality,
        string $domain,
        string $notes,
    ): array {
        return [
            'source_entity' => $source,
            'target_entity' => $target,
            'relationship_type' => $type,
            'cardinality' => $cardinality,
            'domain' => $domain,
            'notes' => $notes,
            'risk_or_gap' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function dim(string $name, string $label, string $source, string $notes): array
    {
        return [
            'dimension' => $name,
            'label' => $label,
            'source' => $source,
            'notes' => $notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function lineageEntry(
        string $entity,
        string $metric,
        string $consumer,
        string $domain,
        string $notes,
    ): array {
        return [
            'canonical_entity' => $entity,
            'canonical_metric' => $metric,
            'consumer' => $consumer,
            'domain' => $domain,
            'notes' => $notes,
        ];
    }
}
