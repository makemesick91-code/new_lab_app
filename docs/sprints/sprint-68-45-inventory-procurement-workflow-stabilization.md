# SPRINT-68.45 — Inventory Procurement Workflow Stabilization, GR Batch Default Hardening & Vendor Report Governance

**Branch:** `feature/sprint-68-45-inventory-procurement-workflow-stabilization-gr-batch-vendor-governance`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target `main`)
**Baseline before:** `hotfix-fix-pre-68-45-doctor-performance-treatment-date-go` (`882c7a6`)
**GO tag after merge:** `sprint-68-45-inventory-procurement-workflow-stabilization-gr-batch-vendor-governance-go`

Hardens, tests, and polishes the inventory procurement foundations introduced in the FIX-PRE-68-45 sprints. **Implementation-heavy. No migration.** Ledger-only stock, branch isolation, KTP/NIK privacy, full-payment-only, room gate, and the doctor→cashier gate are all preserved. CICD-CTRL-1 gates unchanged.

## Scope A — GR default batch/lot hardening

The FIX-PRE-68-45 Scope E header-level default batch/lot already worked; this sprint hardens and proves it.

- `resources/views/inventory/goods-receipts/_form.blade.php`: added the two explicit hints — *"Batch default akan diterapkan ke item yang wajib batch tracking."* and *"Item tetap bisa memakai batch berbeda melalui override."*
- No logic change to `ValidatesGoodsReceiptBatchInput::applyDefaultBatchToItems()` or `GoodsReceiptService::post()` / `InventoryStockService::resolveOrCreateBatch()` — the existing behavior was verified correct: header default expands into a **DISTINCT batch per product** (UNIQUE `branch_id,product_id,batch_number,lot_number`), item-level batch always overrides, non-batch-tracked products keep batch empty, `post()` is a single `DB::transaction`, PURCHASE movement carries the per-product `inventory_batch_id`, and default expiry is retained (FEFO).
- New tests prove: default applies to ALL batch-tracked items; item override; non-batch item not required; missing default blocks; distinct per-product batch + correct ledger `batch_id`; **default expiry retained (FEFO)**; **posting transaction rollback on one-item collision leaves no partial movement/batch writes**; created batch scoped to the acting branch.

## Scope B — Branch PR workflow stabilization

Kepala Cabang → Admin Warehouse workflow board (`inventory.purchase-requests.workflow`).

- `PurchaseRequestService::branchWorkflowBoard()` now `->withCount('purchaseOrders')` (read-only provenance for the "Terhubung PO" badge). PO creation stays Admin Warehouse only.
- `resources/views/inventory/purchase-requests/workflow.blade.php`: added KPI cards (`data-workflow-kpis`) — PR Darurat antrian, PR Reguler antrian, Menunggu Warehouse, Draf PR Cabang — computed from the board collections.
- `partials/workflow-list.blade.php`: added a **Status** column with a truthful badge mapped to real PR statuses (no invented status): Draf, Menunggu Warehouse (submitted), Ditolak, Dibatalkan, Selesai / **Terhubung PO** (approved + linked PO). Type badge (Reguler/Darurat) preserved.
- Status transitions remain service-only (`submit`/`approve`/`reject`/`cancel`); createDraft already logs activity.
- Tests: Kepala Cabang creates PR Reguler + Darurat; **cannot create a PO by route, policy, or direct well-formed store request** (403, 0 POs created); Admin Warehouse views/processes; branch isolation; unauthorized 403; KPI cards + status badges render; existing PR/PO/GR index routes still 200.

## Scope C — Vendor filter & procurement spend governance

Inventory reports (`inventory.reports.index`).

- `InventoryAnalyticsRepository::getSupplierPerformance()` row gains two **additive** GR-truth keys: `received_gr_item_count` and `received_gr_quantity` (POSTED Goods Receipt `accepted_qty` + item count, joined to the PO supplier). `received_value` already sources POSTED GR `line_total` — procurement truth, never the ledger.
- `resources/views/inventory/reports/index.blade.php`:
  - Provenance note under the vendor select: *"Filter vendor memakai provenance pembelian/Goods Receipt (bukan kepemilikan stok bersih)."*
  - A clear explanation banner (`data-report-note="vendor-filter-inapplicable"`) when a vendor is selected on **Kartu Stok** or **Stok per Ruangan** (tabs without per-row vendor provenance) — no wrong data, no 500.
  - Vendor spend summary extended with **Item Diterima** and **Qty Diterima** columns + "Total Belanja (GR)".
- The supplier filter continues to apply to Stok Saat Ini, Low Stock, Mutasi Stok, and Nilai Persediaan (purchase provenance via `trx_inventory_movements.supplier_id`); it is intentionally NOT applied to Kartu Stok / Stok per Ruangan (running-balance / per-room views).
- Branch isolation via `InventoryReportService::sanitizeReportFilters()` unchanged (cross-branch supplier dropped — IDOR boundary).
- Tests: vendor-provenance narrowing; vendor spend from procurement truth (value + item count + qty); branch isolation; new summary columns render; no 500 on all six tabs with vendor + date range; explanation banner on Kartu Stok / Stok per Ruangan; no 500 with no vendor data.

## Scope D — Procurement workflow audit command

`php artisan inventory:procurement-workflow-audit [--json] [--strict]` → `App\Services\Inventory\ProcurementWorkflowAuditService` + `App\Console\Commands\InventoryProcurementWorkflowAuditCommand`. Read-only, privacy-safe (no KTP/NIK).

9 checks:

| Check | Class | Notes |
|---|---|---|
| `kepala_cabang_role_po_permission_leak` | **FAIL** | Kepala Cabang role granting `manage_purchase_order` / `manage_inventory` / `manage master data` |
| `kepala_cabang_user_po_permission_leak` | **FAIL** | Any Kepala Cabang user that can create a PO via another role |
| `pr_missing_type` | WARN | Active PRs (draft/submitted) with null `pr_type` |
| `pr_invalid_branch_user` | WARN | Requester pinned (`users.branch_id`) to a different branch than the PR |
| `pr_linked_to_po_without_approval` | WARN | Non-approved PR linked to an active PO |
| `gr_batch_tracked_missing_batch` | WARN | POSTED batch-tracked GR item without a batch (FAIL-level in `inventory:batch-governance-audit`) |
| `purchase_movement_missing_provenance` | WARN | PURCHASE movement without `supplier_id` |
| `po_linked_gr_missing_supplier` | WARN | POSTED GR whose PO has no supplier |
| `shared_batch_number_across_products` | PASS (informational) | GR default reuses a batch_number across products = one distinct batch per product (never a global batch) |

**`--strict` exits 2 only on UNSAFE (FAIL) anomalies**; WARN data-quality notes never fail `--strict` (VPS-safe). Decision: `NO-GO` (any FAIL) / `WATCH` (any WARN) / `GO`.

## Scope E — UI/UX polish

Delivered via the Scope A/B/C view edits (GR hints, workflow KPI cards + status badges, vendor filter provenance note + explanation banner + richer summary). No broad layout refactor; `x-ui.*` + Tailwind + Alpine only.

## Scope F — Tests

- `tests/Feature/Inventory/Sprint6845GoodsReceiptDefaultBatchHardeningTest.php` (7)
- `tests/Feature/Inventory/Sprint6845BranchPurchaseRequestWorkflowTest.php` (7)
- `tests/Feature/Inventory/Sprint6845VendorReportGovernanceTest.php` (7)
- `tests/Feature/Inventory/Sprint6845ProcurementWorkflowAuditCommandTest.php` (5)

Plus the FIX-PRE-68-45 GoodsReceipt / PurchaseRequest / PurchaseOrder / InventoryReport / Inventory / DoctorPerformance suites as regression.

## Scope G — Durable rules (also added to CLAUDE.md)

1. GR-level default batch/lot is a **header convenience only**.
2. Batch identity resolves **per product/item** — never one global batch across products.
3. PURCHASE movement retains the correct per-product `batch_id`.
4. **Kepala Cabang is PR-only** and can never create a PO (server-side chokepoint `PurchaseOrderPolicy::create`).
5. PR Reguler / PR Darurat are preserved.
6. Admin Warehouse owns PO / vendor continuation.
7. Vendor report filters are **procurement/GR provenance filters**, not net-stock ownership.
8. Vendor spend comes from procurement truth (POSTED GR `line_total`), never the ledger.
9. Run `inventory:procurement-workflow-audit --strict` before GO/deploy.
10. Ledger-only inventory (`trx_inventory_movements`) remains non-negotiable.

## Not done / risks

- No migration (all columns already exist).
- On the VPS the audit may report `WATCH` from legacy WARN data (e.g. null `pr_type` on pre-migration PRs); this is expected and does not fail `--strict`.
- The `supplier_id` filter deliberately does not narrow Kartu Stok / Stok per Ruangan (explained in-view).

## Rollback

Revert the squash-merge commit or roll back to `hotfix-fix-pre-68-45-doctor-performance-treatment-date-go`.
