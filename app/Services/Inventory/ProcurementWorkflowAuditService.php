<?php

namespace App\Services\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptItem;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequest;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * SPRINT-68.45 Scope D — read-only procurement workflow consistency audit.
 *
 * Protects the Sprint 68.45 procurement foundations (branch PR workflow, GR
 * default batch, vendor provenance) by surfacing anomalies. UNSAFE anomalies
 * (Kepala Cabang holding a PO-creation permission) are FAIL and fail --strict;
 * data-quality notes (missing pr_type, provenance gaps) are WARN and never fail
 * --strict. Never mutates data and never renders KTP/NIK/medical data.
 */
class ProcurementWorkflowAuditService
{
    /** The single server-side chokepoint permissions that let a user create a PO. */
    private const PO_CREATE_PERMISSIONS = [
        'manage_purchase_order',
        'manage_inventory',
        'manage master data',
    ];

    private const BRANCH_ROLE = 'Kepala Cabang';

    /**
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        $checks = [
            $this->checkKepalaCabangRolePoLeak(),
            $this->checkKepalaCabangUserPoLeak(),
            $this->checkPrMissingType(),
            $this->checkPrInvalidBranchUser(),
            $this->checkPrLinkedToPoWithoutApproval(),
            $this->checkGrBatchTrackedMissingBatch(),
            $this->checkPurchaseMovementMissingProvenance(),
            $this->checkPoLinkedGrMissingSupplier(),
            $this->checkSharedBatchAcrossProducts(),
        ];

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
                'sprint' => 'SPRINT-68.45',
            ],
            'summary' => [
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
                // Anomalies that must fail --strict = unsafe (FAIL) findings only.
                'anomalies' => $errors,
                'decision' => $this->decision($errors, $warnings),
            ],
            'checks' => $checks,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     * @return array<string, mixed>
     */
    private function result(string $checkId, string $category, string $status, string $message, array $details = []): array
    {
        return [
            'check_id' => $checkId,
            'category' => $category,
            'status' => $status,
            'message' => $message,
            'count' => count($details),
            'details' => $details,
        ];
    }

    /**
     * UNSAFE (FAIL): the Kepala Cabang role must never grant a PO-creation
     * permission, or the server-side chokepoint (PurchaseOrderPolicy::create)
     * silently unlocks.
     *
     * @return array<string, mixed>
     */
    private function checkKepalaCabangRolePoLeak(): array
    {
        $role = Role::query()->where('name', self::BRANCH_ROLE)->first();

        if ($role === null) {
            return $this->result(
                'kepala_cabang_role_po_permission_leak',
                'permission',
                'PASS',
                'Role "'.self::BRANCH_ROLE.'" not present (nothing to leak).',
            );
        }

        $leaked = array_values(array_intersect(
            $role->permissions->pluck('name')->all(),
            self::PO_CREATE_PERMISSIONS,
        ));

        if ($leaked === []) {
            return $this->result(
                'kepala_cabang_role_po_permission_leak',
                'permission',
                'PASS',
                'Kepala Cabang role grants no PO-creation permission (PR-only).',
            );
        }

        return $this->result(
            'kepala_cabang_role_po_permission_leak',
            'permission',
            'FAIL',
            'Kepala Cabang role grants PO-creation permission(s): '.implode(', ', $leaked).'. Remove from RoleSeeder.',
            [['role' => self::BRANCH_ROLE, 'leaked_permissions' => $leaked]],
        );
    }

    /**
     * UNSAFE (FAIL): any user holding the Kepala Cabang role that can ALSO create
     * a PO (via another role) breaks the PR-only invariant.
     *
     * @return array<string, mixed>
     */
    private function checkKepalaCabangUserPoLeak(): array
    {
        $users = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', self::BRANCH_ROLE))
            ->get();

        $leaks = $users
            ->filter(fn (User $user) => $user->canAny(self::PO_CREATE_PERMISSIONS))
            ->map(fn (User $user) => [
                'user_id' => $user->id,
                'name' => (string) $user->name,
            ])
            ->values()
            ->all();

        if ($leaks === []) {
            return $this->result(
                'kepala_cabang_user_po_permission_leak',
                'permission',
                'PASS',
                'No Kepala Cabang user can create a Purchase Order.',
            );
        }

        return $this->result(
            'kepala_cabang_user_po_permission_leak',
            'permission',
            'FAIL',
            count($leaks).' Kepala Cabang user(s) can create a Purchase Order via another role — revoke.',
            $leaks,
        );
    }

    /**
     * DATA-QUALITY (WARN): active (draft/submitted) PRs missing a pr_type. Legacy
     * PRs are informational; never fails --strict.
     *
     * @return array<string, mixed>
     */
    private function checkPrMissingType(): array
    {
        $rows = PurchaseRequest::query()
            ->whereIn('status', [PurchaseRequest::STATUS_DRAFT, PurchaseRequest::STATUS_SUBMITTED])
            ->whereNull('pr_type')
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'purchase_request_number', 'branch_id', 'status'])
            ->map(fn (PurchaseRequest $pr) => [
                'purchase_request_id' => $pr->id,
                'number' => (string) $pr->purchase_request_number,
                'branch_id' => $pr->branch_id,
                'status' => $pr->status,
            ])
            ->all();

        if ($rows === []) {
            return $this->result('pr_missing_type', 'workflow', 'PASS', 'All active PRs have a pr_type (Reguler/Darurat).');
        }

        return $this->result(
            'pr_missing_type',
            'workflow',
            'WARN',
            count($rows).' active PR(s) missing a pr_type — set Reguler/Darurat.',
            $rows,
        );
    }

    /**
     * DATA-QUALITY (WARN): a PR whose requester is pinned to a different branch
     * than the PR (users.branch_id mismatch). Informational.
     *
     * @return array<string, mixed>
     */
    private function checkPrInvalidBranchUser(): array
    {
        $rows = PurchaseRequest::query()
            ->join('users', 'users.id', '=', 'trx_purchase_requests.requested_by')
            ->whereNotNull('users.branch_id')
            ->whereColumn('users.branch_id', '!=', 'trx_purchase_requests.branch_id')
            ->orderBy('trx_purchase_requests.id')
            ->limit(100)
            ->get([
                'trx_purchase_requests.id as pr_id',
                'trx_purchase_requests.purchase_request_number as number',
                'trx_purchase_requests.branch_id as pr_branch_id',
                'users.id as user_id',
                'users.branch_id as user_branch_id',
            ])
            ->map(fn ($row) => [
                'purchase_request_id' => (int) $row->pr_id,
                'number' => (string) $row->number,
                'pr_branch_id' => (int) $row->pr_branch_id,
                'requester_user_id' => (int) $row->user_id,
                'requester_branch_id' => (int) $row->user_branch_id,
            ])
            ->all();

        if ($rows === []) {
            return $this->result('pr_invalid_branch_user', 'workflow', 'PASS', 'No PR requester/branch mismatch.');
        }

        return $this->result(
            'pr_invalid_branch_user',
            'workflow',
            'WARN',
            count($rows).' PR(s) requested by a user pinned to a different branch.',
            $rows,
        );
    }

    /**
     * DATA-QUALITY (WARN): a PR linked to a non-cancelled PO while the PR itself
     * is not approved — suggests a PO created outside the approval flow.
     *
     * @return array<string, mixed>
     */
    private function checkPrLinkedToPoWithoutApproval(): array
    {
        $rows = PurchaseRequest::query()
            ->whereIn('status', [PurchaseRequest::STATUS_DRAFT, PurchaseRequest::STATUS_SUBMITTED, PurchaseRequest::STATUS_REJECTED, PurchaseRequest::STATUS_CANCELLED])
            ->whereHas('purchaseOrders', fn ($q) => $q->where('status', '!=', PurchaseOrder::STATUS_CANCELLED))
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'purchase_request_number', 'branch_id', 'status'])
            ->map(fn (PurchaseRequest $pr) => [
                'purchase_request_id' => $pr->id,
                'number' => (string) $pr->purchase_request_number,
                'branch_id' => $pr->branch_id,
                'status' => $pr->status,
            ])
            ->all();

        if ($rows === []) {
            return $this->result('pr_linked_to_po_without_approval', 'workflow', 'PASS', 'No PR linked to a PO outside approval.');
        }

        return $this->result(
            'pr_linked_to_po_without_approval',
            'workflow',
            'WARN',
            count($rows).' non-approved PR(s) linked to an active PO — review the approval chain.',
            $rows,
        );
    }

    /**
     * DATA-QUALITY (WARN): a POSTED GR item for a batch-tracked product with no
     * inventory_batch_id (ledger provenance). FAIL-level integrity is covered by
     * inventory:batch-governance-audit; here it is surfaced non-blocking.
     *
     * @return array<string, mixed>
     */
    private function checkGrBatchTrackedMissingBatch(): array
    {
        $rows = GoodsReceiptItem::query()
            ->join('trx_goods_receipts', 'trx_goods_receipts.id', '=', 'trx_goods_receipt_items.goods_receipt_id')
            ->join('inv_products', 'inv_products.id', '=', 'trx_goods_receipt_items.product_id')
            ->where('trx_goods_receipts.status', GoodsReceipt::STATUS_POSTED)
            ->where('inv_products.requires_batch_tracking', true)
            ->whereNull('trx_goods_receipt_items.inventory_batch_id')
            ->orderBy('trx_goods_receipt_items.id')
            ->limit(100)
            ->get([
                'trx_goods_receipt_items.id as item_id',
                'trx_goods_receipts.id as gr_id',
                'trx_goods_receipts.branch_id as branch_id',
                'trx_goods_receipt_items.product_id as product_id',
            ])
            ->map(fn ($row) => [
                'goods_receipt_item_id' => (int) $row->item_id,
                'goods_receipt_id' => (int) $row->gr_id,
                'branch_id' => (int) $row->branch_id,
                'product_id' => (int) $row->product_id,
            ])
            ->all();

        if ($rows === []) {
            return $this->result('gr_batch_tracked_missing_batch', 'ledger', 'PASS', 'All posted batch-tracked GR items carry a batch.');
        }

        return $this->result(
            'gr_batch_tracked_missing_batch',
            'ledger',
            'WARN',
            count($rows).' posted batch-tracked GR item(s) missing a batch — see inventory:batch-governance-audit.',
            $rows,
        );
    }

    /**
     * DATA-QUALITY (WARN): a PURCHASE ledger movement without a supplier_id — a
     * vendor-provenance gap for the vendor report filter.
     *
     * @return array<string, mixed>
     */
    private function checkPurchaseMovementMissingProvenance(): array
    {
        $rows = InventoryMovement::query()
            ->where('movement_type', InventoryMovement::TYPE_PURCHASE)
            ->whereNull('supplier_id')
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'branch_id', 'product_id', 'reference_type', 'reference_id'])
            ->map(fn (InventoryMovement $m) => [
                'movement_id' => $m->id,
                'branch_id' => $m->branch_id,
                'product_id' => $m->product_id,
                'reference_type' => $m->reference_type,
                'reference_id' => $m->reference_id,
            ])
            ->all();

        if ($rows === []) {
            return $this->result('purchase_movement_missing_provenance', 'provenance', 'PASS', 'All PURCHASE movements carry a supplier_id.');
        }

        return $this->result(
            'purchase_movement_missing_provenance',
            'provenance',
            'WARN',
            count($rows).' PURCHASE movement(s) without a supplier_id — vendor filter provenance gap.',
            $rows,
        );
    }

    /**
     * DATA-QUALITY (WARN): a POSTED GR whose PO has no supplier_id.
     *
     * @return array<string, mixed>
     */
    private function checkPoLinkedGrMissingSupplier(): array
    {
        $rows = GoodsReceipt::query()
            ->join('trx_purchase_orders', 'trx_purchase_orders.id', '=', 'trx_goods_receipts.purchase_order_id')
            ->where('trx_goods_receipts.status', GoodsReceipt::STATUS_POSTED)
            ->whereNull('trx_purchase_orders.supplier_id')
            ->orderBy('trx_goods_receipts.id')
            ->limit(100)
            ->get([
                'trx_goods_receipts.id as gr_id',
                'trx_goods_receipts.branch_id as branch_id',
                'trx_purchase_orders.id as po_id',
            ])
            ->map(fn ($row) => [
                'goods_receipt_id' => (int) $row->gr_id,
                'branch_id' => (int) $row->branch_id,
                'purchase_order_id' => (int) $row->po_id,
            ])
            ->all();

        if ($rows === []) {
            return $this->result('po_linked_gr_missing_supplier', 'provenance', 'PASS', 'All posted PO-linked GRs have a supplier.');
        }

        return $this->result(
            'po_linked_gr_missing_supplier',
            'provenance',
            'WARN',
            count($rows).' posted GR(s) whose PO has no supplier reference.',
            $rows,
        );
    }

    /**
     * INFORMATIONAL (PASS): the GR default batch legitimately reuses one
     * batch_number across many products (a DISTINCT batch row per product due to
     * the UNIQUE(branch,product,batch_number,lot) key). This can never be "one
     * global batch across products" structurally — reported for transparency only,
     * never an anomaly.
     *
     * @return array<string, mixed>
     */
    private function checkSharedBatchAcrossProducts(): array
    {
        $groups = DB::table('inv_inventory_batches')
            ->selectRaw('branch_id, batch_number, COUNT(DISTINCT product_id) as product_count')
            ->whereNotNull('batch_number')
            ->groupBy('branch_id', 'batch_number')
            ->havingRaw('COUNT(DISTINCT product_id) > 1')
            ->get();

        $maxShared = (int) ($groups->max('product_count') ?? 0);

        return $this->result(
            'shared_batch_number_across_products',
            'informational',
            'PASS',
            $groups->count().' batch number(s) reused across products (expected from GR default batch; each is a distinct per-product batch). Max products sharing one number: '.$maxShared.'.',
            [],
        );
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
}
