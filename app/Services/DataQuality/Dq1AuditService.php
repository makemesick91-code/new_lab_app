<?php

namespace App\Services\DataQuality;

use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Patient\Services\PatientDataCompletenessService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only DQ-1 ACID, constraint, and data quality audit.
 * Privacy-safe: no full KTP/NIK; aggregate counts only.
 */
class Dq1AuditService
{
    public function __construct(
        private readonly PatientDataCompletenessService $patientPrivacy,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function audit(array $options = []): array
    {
        $checks = array_merge(
            $this->auditAcid(),
            $this->auditConstraints(),
            $this->auditData(),
        );

        $passed = collect($checks)->where('status', 'PASS')->count();
        $warnings = collect($checks)->where('status', 'WARN')->count();
        $errors = collect($checks)->where('status', 'FAIL')->count();

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'metadata' => [
                'app_name' => (string) config('app.name'),
                'laravel_version' => Application::VERSION,
                'php_version' => PHP_VERSION,
                'database_driver' => (string) config('database.default'),
                'sprint' => config('dq1.sprint', 'DQ-1'),
                'version' => config('dq1.version', 'DQ-1'),
            ],
            'summary' => [
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
                'decision' => $this->decision($errors, $warnings),
            ],
            'checks' => $checks,
            'privacy' => [
                'privacy_safe' => true,
                'row_level_data' => false,
                'pii_masked' => true,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditAcid(): array
    {
        $results = [];

        foreach (config('dq1.acid_services', []) as $checkId => $definition) {
            $missing = [];
            $exceptions = [];

            foreach ($definition['services'] ?? [] as $serviceClass) {
                if (! class_exists($serviceClass)) {
                    $missing[] = $serviceClass.' (class missing)';

                    continue;
                }

                $path = (new \ReflectionClass($serviceClass))->getFileName();
                $contents = is_string($path) ? (string) file_get_contents($path) : '';

                if (! str_contains($contents, 'DB::transaction')) {
                    $missing[] = class_basename($serviceClass);
                }
            }

            foreach ($definition['documented_exceptions'] ?? [] as $serviceClass => $note) {
                if (class_exists($serviceClass)) {
                    $exceptions[] = class_basename($serviceClass).': '.$note;
                }
            }

            $results[] = $this->checkResult(
                $checkId,
                'ACID',
                (string) ($definition['title'] ?? $checkId),
                $missing === [] ? 'PASS' : 'FAIL',
                (string) ($definition['severity'] ?? 'error'),
                $missing === []
                    ? 'All listed critical services declare DB::transaction.'
                    : 'Services missing DB::transaction: '.implode(', ', $missing),
                [
                    'services_checked' => count($definition['services'] ?? []),
                    'missing' => $missing,
                    'documented_exceptions' => $exceptions,
                ],
            );
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditConstraints(): array
    {
        $results = [];

        $fkDefinition = config('dq1.constraint_checks.DQ1-CONSTRAINT-001', []);
        $missingFks = [];
        foreach ($fkDefinition['foreign_keys'] ?? [] as $fk) {
            if (! $this->foreignKeyExists(
                (string) $fk['table'],
                (string) $fk['column'],
                (string) $fk['references'],
            )) {
                $missingFks[] = $fk['table'].'.'.$fk['column'].' -> '.$fk['references'];
            }
        }
        $results[] = $this->checkResult(
            'DQ1-CONSTRAINT-001',
            'CONSTRAINT',
            (string) ($fkDefinition['title'] ?? 'Critical FK constraints'),
            $missingFks === [] ? 'PASS' : 'FAIL',
            'error',
            $missingFks === []
                ? 'Critical foreign keys are present.'
                : 'Missing foreign keys: '.implode('; ', $missingFks),
            ['missing' => $missingFks, 'checked' => count($fkDefinition['foreign_keys'] ?? [])],
        );

        foreach (['DQ1-CONSTRAINT-002', 'DQ1-CONSTRAINT-006'] as $checkId) {
            $definition = config("dq1.constraint_checks.{$checkId}", []);
            $table = (string) ($definition['table'] ?? '');
            $column = (string) ($definition['column'] ?? '');
            $hasUnique = $this->hasUniqueIndex($table, $column);

            $results[] = $this->checkResult(
                $checkId,
                'CONSTRAINT',
                (string) ($definition['title'] ?? $checkId),
                $hasUnique ? 'PASS' : 'FAIL',
                (string) ($definition['severity'] ?? 'error'),
                $hasUnique
                    ? "Unique index on {$table}.{$column} is present."
                    : "Unique index on {$table}.{$column} is missing.",
                ['table' => $table, 'column' => $column],
            );
        }

        $ktpDefinition = config('dq1.constraint_checks.DQ1-CONSTRAINT-003', []);
        $ktpTable = (string) ($ktpDefinition['table'] ?? 'mst_patients');
        $ktpColumn = (string) ($ktpDefinition['column'] ?? 'ktp_number');
        $hasKtpUnique = $this->hasUniqueIndex($ktpTable, $ktpColumn);

        $results[] = $this->checkResult(
            'DQ1-CONSTRAINT-003',
            'CONSTRAINT',
            (string) ($ktpDefinition['title'] ?? 'Unique KTP guard'),
            $hasKtpUnique ? 'PASS' : 'WARN',
            'warning',
            $hasKtpUnique
                ? 'Nullable unique KTP index is present.'
                : 'KTP uniqueness relies on application validation only.',
            [
                'table' => $ktpTable,
                'column' => $ktpColumn,
                'app_level_note' => $ktpDefinition['app_level_note'] ?? null,
            ],
        );

        $monetaryDefinition = config('dq1.constraint_checks.DQ1-CONSTRAINT-004', []);
        $negativeCounts = [];
        foreach ($monetaryDefinition['tables'] ?? [] as $tableDef) {
            $table = (string) $tableDef['table'];
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($tableDef['columns'] as $column) {
                $count = (int) DB::table($table)->where($column, '<', 0)->count();
                if ($count > 0) {
                    $negativeCounts[] = "{$table}.{$column}:{$count}";
                }
            }
        }

        $results[] = $this->checkResult(
            'DQ1-CONSTRAINT-004',
            'CONSTRAINT',
            (string) ($monetaryDefinition['title'] ?? 'Non-negative monetary fields'),
            $negativeCounts === [] ? 'PASS' : 'FAIL',
            'error',
            $negativeCounts === []
                ? 'No negative monetary values detected.'
                : 'Negative monetary values found: '.implode(', ', $negativeCounts),
            ['violations' => $negativeCounts],
        );

        $qtyDefinition = config('dq1.constraint_checks.DQ1-CONSTRAINT-005', []);
        $invalidQtyCount = $this->countInvalidInventoryMovements();

        $results[] = $this->checkResult(
            'DQ1-CONSTRAINT-005',
            'CONSTRAINT',
            (string) ($qtyDefinition['title'] ?? 'Inventory quantity direction'),
            $invalidQtyCount === 0 ? 'PASS' : 'WARN',
            'warning',
            $invalidQtyCount === 0
                ? 'No invalid inventory quantity direction rows detected.'
                : "{$invalidQtyCount} movement row(s) violate quantity direction rules.",
            ['invalid_count' => $invalidQtyCount, 'note' => $qtyDefinition['note'] ?? null],
        );

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditData(): array
    {
        $results = [];

        $duplicateRm = $this->countDuplicateColumn('mst_patients', 'medical_record_number');
        $results[] = $this->checkResult(
            'DQ1-DATA-001',
            'DATA',
            'Duplicate medical record numbers',
            $duplicateRm === 0 ? 'PASS' : 'FAIL',
            'error',
            $duplicateRm === 0
                ? 'No duplicate medical record numbers.'
                : "{$duplicateRm} duplicate RM group(s) detected.",
            ['duplicate_groups' => $duplicateRm],
        );

        $duplicateKtp = $this->duplicateKtpSummary();
        $results[] = $this->checkResult(
            'DQ1-DATA-002',
            'DATA',
            'Duplicate non-null KTP numbers (masked)',
            ($duplicateKtp['groups'] ?? 0) === 0 ? 'PASS' : 'FAIL',
            'error',
            ($duplicateKtp['groups'] ?? 0) === 0
                ? 'No duplicate KTP numbers.'
                : ($duplicateKtp['groups'] ?? 0).' duplicate KTP group(s) detected.',
            [
                'duplicate_groups' => $duplicateKtp['groups'] ?? 0,
                'masked_samples' => $duplicateKtp['masked_samples'] ?? [],
            ],
        );

        $orphanInvoices = $this->countOrphanRmeInvoices();
        $orphanPayments = $this->countOrphanRmePayments();
        $orphanItems = $this->countOrphanRmeInvoiceItems();
        $orphanTotal = $orphanInvoices + $orphanPayments + $orphanItems;

        $results[] = $this->checkResult(
            'DQ1-DATA-003',
            'DATA',
            'Orphan RME invoices, payments, or items',
            $orphanTotal === 0 ? 'PASS' : 'FAIL',
            'error',
            $orphanTotal === 0
                ? 'No orphan RME billing rows.'
                : "Orphans — invoices: {$orphanInvoices}, payments: {$orphanPayments}, items: {$orphanItems}.",
            [
                'orphan_invoices' => $orphanInvoices,
                'orphan_payments' => $orphanPayments,
                'orphan_items' => $orphanItems,
            ],
        );

        $invalidInvoiceCount = $this->countInvalidInvoiceStatusRemaining();
        $results[] = $this->checkResult(
            'DQ1-DATA-004',
            'DATA',
            'Invalid invoice status or remaining balance',
            $invalidInvoiceCount === 0 ? 'PASS' : 'FAIL',
            'error',
            $invalidInvoiceCount === 0
                ? 'RME invoice status/remaining pairs are consistent.'
                : "{$invalidInvoiceCount} invoice(s) have inconsistent status/remaining.",
            ['invalid_count' => $invalidInvoiceCount],
        );

        $orphanMr = $this->countOrphanMedicalRecords();
        $results[] = $this->checkResult(
            'DQ1-DATA-005',
            'DATA',
            'Orphan medical records or visit links',
            $orphanMr === 0 ? 'PASS' : 'FAIL',
            'error',
            $orphanMr === 0
                ? 'Medical records are linked to existing visits.'
                : "{$orphanMr} medical record(s) reference missing visits.",
            ['orphan_count' => $orphanMr],
        );

        $invalidMovements = $this->countInvalidInventoryMovements();
        $batchMissing = $this->countBatchTrackedMovementsMissingBatch();
        $movementStatus = 'PASS';
        $movementSeverity = 'error';
        $movementMessage = 'Inventory movements follow ledger direction rules.';

        if ($invalidMovements > 0) {
            $movementStatus = 'FAIL';
            $movementMessage = "Invalid direction movement rows: {$invalidMovements}.";
        } elseif ($batchMissing > 0) {
            $movementStatus = 'WARN';
            $movementSeverity = 'warning';
            $movementMessage = "{$batchMissing} batch-tracked movement(s) missing inventory_batch_id (report-only backlog).";
        }

        $results[] = $this->checkResult(
            'DQ1-DATA-006',
            'DATA',
            'Invalid inventory movements',
            $movementStatus,
            $movementSeverity,
            $movementMessage,
            [
                'invalid_direction' => $invalidMovements,
                'batch_missing' => $batchMissing,
            ],
        );

        $missingBranch = $this->countMissingBranchIds();
        $results[] = $this->checkResult(
            'DQ1-DATA-007',
            'DATA',
            'Branch-owned records missing branch_id',
            $missingBranch === 0 ? 'PASS' : 'FAIL',
            'error',
            $missingBranch === 0
                ? 'Required branch_id columns are populated.'
                : "{$missingBranch} row(s) missing required branch_id.",
            ['missing_count' => $missingBranch],
        );

        $labBillingOk = $this->labRmeBillingSeparationOk();
        $results[] = $this->checkResult(
            'DQ1-DATA-008',
            'DATA',
            'Lab and RME billing separation sanity',
            $labBillingOk ? 'PASS' : 'WARN',
            'warning',
            $labBillingOk
                ? 'Lab trx_payments and RME trx_rme_payments remain on separate tables.'
                : 'Lab/RME billing separation could not be fully verified.',
            [
                'trx_payments_exists' => Schema::hasTable('trx_payments'),
                'trx_rme_payments_exists' => Schema::hasTable('trx_rme_payments'),
            ],
        );

        $orphanFollowUps = $this->countOrphanReceivableFollowUps();
        $results[] = $this->checkResult(
            'DQ1-DATA-009',
            'DATA',
            'Receivable follow-up orphan check',
            $orphanFollowUps === 0 ? 'PASS' : 'WARN',
            'warning',
            $orphanFollowUps === 0
                ? 'Receivable follow-ups reference existing invoices.'
                : "{$orphanFollowUps} follow-up row(s) reference missing invoices.",
            ['orphan_count' => $orphanFollowUps],
        );

        $transferIssues = $this->countReceivedTransfersWithoutMovements();
        $results[] = $this->checkResult(
            'DQ1-DATA-010',
            'DATA',
            'Stock transfer movement sanity',
            $transferIssues === 0 ? 'PASS' : 'WARN',
            'warning',
            $transferIssues === 0
                ? 'Received stock transfers have expected movement references.'
                : "{$transferIssues} received transfer(s) lack expected movement pairs.",
            ['issue_count' => $transferIssues],
        );

        return $results;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function checkResult(
        string $checkId,
        string $category,
        string $title,
        string $status,
        string $severity,
        string $message,
        array $details = [],
    ): array {
        return [
            'check_id' => $checkId,
            'category' => $category,
            'title' => $title,
            'status' => $status,
            'severity' => $severity,
            'message' => $message,
            'details' => $details,
        ];
    }

    private function decision(int $errors, int $warnings): string
    {
        if ($errors > 0) {
            return 'NO-GO';
        }

        if ($warnings > 0) {
            return 'WATCH';
        }

        return 'GO';
    }

    private function foreignKeyExists(string $table, string $column, string $referencedTable): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable($referencedTable)) {
            return false;
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA foreign_key_list('".$table."')");

            foreach ($rows as $row) {
                $rowColumn = (string) ($row->from ?? '');
                $rowTable = (string) ($row->table ?? '');
                if ($rowColumn === $column && $rowTable === $referencedTable) {
                    return true;
                }
            }

            return false;
        }

        $result = DB::selectOne(
            'SELECT 1 AS ok
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name
              AND tc.table_schema = kcu.table_schema
             JOIN information_schema.constraint_column_usage ccu
               ON ccu.constraint_name = tc.constraint_name
              AND ccu.table_schema = tc.table_schema
             WHERE tc.constraint_type = ?
               AND tc.table_name = ?
               AND kcu.column_name = ?
               AND ccu.table_name = ?
             LIMIT 1',
            ['FOREIGN KEY', $table, $column, $referencedTable],
        );

        return $result !== null;
    }

    private function hasUniqueIndex(string $table, string $column): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return false;
        }

        foreach (Schema::getIndexes($table) as $index) {
            $columns = $index['columns'] ?? [];
            if (($index['unique'] ?? false) && $columns === [$column]) {
                return true;
            }
        }

        return false;
    }

    private function countDuplicateColumn(string $table, string $column): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        $row = DB::table($table)
            ->selectRaw("{$column} as value, COUNT(*) as aggregate")
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->limit(1000)
            ->get();

        return $row->count();
    }

    /**
     * @return array{groups: int, masked_samples: list<string>}
     */
    private function duplicateKtpSummary(): array
    {
        if (! Schema::hasTable('mst_patients') || ! Schema::hasColumn('mst_patients', 'ktp_number')) {
            return ['groups' => 0, 'masked_samples' => []];
        }

        $duplicates = DB::table('mst_patients')
            ->selectRaw('ktp_number, COUNT(*) as aggregate')
            ->whereNotNull('ktp_number')
            ->where('ktp_number', '!=', '')
            ->groupBy('ktp_number')
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->get();

        $masked = $duplicates
            ->map(fn ($row) => $this->patientPrivacy->maskKtp((string) $row->ktp_number))
            ->filter()
            ->values()
            ->all();

        $groupCount = (int) DB::table('mst_patients')
            ->selectRaw('ktp_number')
            ->whereNotNull('ktp_number')
            ->where('ktp_number', '!=', '')
            ->groupBy('ktp_number')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        return [
            'groups' => $groupCount,
            'masked_samples' => $masked,
        ];
    }

    private function countOrphanRmeInvoices(): int
    {
        if (! Schema::hasTable('trx_rme_invoices')) {
            return 0;
        }

        return (int) DB::table('trx_rme_invoices as i')
            ->leftJoin('trx_clinic_visits as v', 'v.id', '=', 'i.clinic_visit_id')
            ->whereNull('v.id')
            ->count();
    }

    private function countOrphanRmePayments(): int
    {
        if (! Schema::hasTable('trx_rme_payments')) {
            return 0;
        }

        return (int) DB::table('trx_rme_payments as p')
            ->leftJoin('trx_rme_invoices as i', 'i.id', '=', 'p.rme_invoice_id')
            ->whereNull('i.id')
            ->count();
    }

    private function countOrphanRmeInvoiceItems(): int
    {
        if (! Schema::hasTable('trx_rme_invoice_items')) {
            return 0;
        }

        return (int) DB::table('trx_rme_invoice_items as item')
            ->leftJoin('trx_rme_invoices as i', 'i.id', '=', 'item.rme_invoice_id')
            ->whereNull('i.id')
            ->count();
    }

    private function countInvalidInvoiceStatusRemaining(): int
    {
        if (! Schema::hasTable('trx_rme_invoices')) {
            return 0;
        }

        $validStatuses = config('dq1.rme_invoice_statuses', RmeInvoice::STATUSES);
        $statusList = "'".implode("','", array_map('addslashes', $validStatuses))."'";

        $invalidStatus = (int) DB::table('trx_rme_invoices')
            ->whereRaw("status NOT IN ({$statusList})")
            ->count();

        $paidWithBalanceQuery = DB::table('trx_rme_invoices as i')
            ->leftJoin('trx_rme_payments as p', 'p.rme_invoice_id', '=', 'i.id')
            ->where('i.status', RmeInvoice::STATUS_PAID)
            ->when(
                Schema::hasColumn('trx_rme_invoices', 'deleted_at'),
                fn ($query) => $query->whereNull('i.deleted_at'),
            )
            ->groupBy('i.id', 'i.grand_total')
            ->havingRaw('i.grand_total - COALESCE(SUM(p.amount), 0) > 0.01')
            ->select('i.id');

        $paidWithBalance = (int) DB::query()->fromSub($paidWithBalanceQuery, 'anomalies')->count();

        $negativeTotals = (int) DB::table('trx_rme_invoices')
            ->where('grand_total', '<', 0)
            ->count();

        return $invalidStatus + $paidWithBalance + $negativeTotals;
    }

    private function countOrphanMedicalRecords(): int
    {
        if (! Schema::hasTable('trx_medical_records')) {
            return 0;
        }

        return (int) DB::table('trx_medical_records as mr')
            ->leftJoin('trx_clinic_visits as v', 'v.id', '=', 'mr.clinic_visit_id')
            ->whereNull('v.id')
            ->count();
    }

    public function countInvalidInventoryMovements(): int
    {
        if (! Schema::hasTable('trx_inventory_movements')) {
            return 0;
        }

        return (int) DB::table('trx_inventory_movements')
            ->where(function ($query) {
                $query->where('quantity_in', '<', 0)
                    ->orWhere('quantity_out', '<', 0)
                    ->orWhere(function ($nested) {
                        $nested->where('quantity_in', '>', 0)
                            ->where('quantity_out', '>', 0);
                    });
            })
            ->count();
    }

    private function countBatchTrackedMovementsMissingBatch(): int
    {
        if (! Schema::hasTable('trx_inventory_movements')
            || ! Schema::hasTable('inv_products')
            || ! Schema::hasColumn('trx_inventory_movements', 'inventory_batch_id')
            || ! Schema::hasColumn('inv_products', 'requires_batch_tracking')) {
            return 0;
        }

        return (int) DB::table('trx_inventory_movements as m')
            ->join('inv_products as p', 'p.id', '=', 'm.product_id')
            ->where('p.requires_batch_tracking', true)
            ->whereNull('m.inventory_batch_id')
            ->where(function ($query) {
                $query->where('m.quantity_in', '>', 0)
                    ->orWhere('m.quantity_out', '>', 0);
            })
            ->count();
    }

    private function countMissingBranchIds(): int
    {
        $total = 0;

        foreach (config('dq1.branch_required_tables', []) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            $total += (int) DB::table($table)->whereNull('branch_id')->count();
        }

        return $total;
    }

    private function labRmeBillingSeparationOk(): bool
    {
        return Schema::hasTable('trx_payments')
            && Schema::hasTable('trx_rme_payments')
            && ! Schema::hasColumn('trx_payments', 'rme_invoice_id');
    }

    private function countOrphanReceivableFollowUps(): int
    {
        if (! Schema::hasTable('trx_rme_receivable_follow_ups')) {
            return 0;
        }

        return (int) DB::table('trx_rme_receivable_follow_ups as f')
            ->leftJoin('trx_rme_invoices as i', 'i.id', '=', 'f.rme_invoice_id')
            ->whereNull('i.id')
            ->count();
    }

    private function countReceivedTransfersWithoutMovements(): int
    {
        if (! Schema::hasTable('trx_stock_transfers') || ! Schema::hasTable('trx_inventory_movements')) {
            return 0;
        }

        $receivedStatuses = [
            StockTransfer::STATUS_RECEIVED,
            StockTransfer::STATUS_COMPLETED,
        ];

        return (int) DB::table('trx_stock_transfers as t')
            ->whereIn('t.status', $receivedStatuses)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('trx_inventory_movements as m')
                    ->whereColumn('m.reference_id', 't.id')
                    ->where('m.reference_type', 'trx_stock_transfers');
            })
            ->count();
    }
}
