# Runbook — Inventory Procurement Workflow Audit

**Command:** `php artisan inventory:procurement-workflow-audit`
**Introduced:** SPRINT-68.45
**Purpose:** Read-only consistency audit of the inventory procurement foundations (branch PR workflow, GR default batch, vendor provenance). Run before every GO/deploy of inventory/procurement work.

## When to run

- Before tagging GO on any inventory / procurement sprint.
- On the VPS immediately after deploy (alongside `rme:doctor-performance-access-audit`).
- Whenever the Kepala Cabang / Admin Warehouse roles or the PR/PO/GR flow changes.

## Usage

```bash
# Human-readable table
php artisan inventory:procurement-workflow-audit

# JSON (decision + summary counts + grouped checks)
php artisan inventory:procurement-workflow-audit --json

# CI/deploy gate — exits 2 on UNSAFE (FAIL) anomalies only
php artisan inventory:procurement-workflow-audit --strict
```

## Exit codes

- `0` — GO or WATCH (no UNSAFE anomaly). WARN data-quality notes do **not** fail `--strict`.
- `2` — NO-GO: at least one UNSAFE (FAIL) anomaly (a Kepala Cabang holding a PO-creation permission).

## Checks

- **FAIL (unsafe, fails `--strict`)**
  - `kepala_cabang_role_po_permission_leak` — the Kepala Cabang role grants `manage_purchase_order` / `manage_inventory` / `manage master data`. Fix: remove from `RoleSeeder`, re-seed.
  - `kepala_cabang_user_po_permission_leak` — a Kepala Cabang user can create a PO via another role. Fix: revoke the offending role/permission from that user.
- **WARN (data-quality, informational)**
  - `pr_missing_type` — set Reguler/Darurat on the affected active PRs.
  - `pr_invalid_branch_user` — reconcile `users.branch_id` vs the PR branch.
  - `pr_linked_to_po_without_approval` — review the approval chain for that PR/PO.
  - `gr_batch_tracked_missing_batch` — see `inventory:batch-governance-audit` (FAIL-level there).
  - `purchase_movement_missing_provenance` — PURCHASE movement without a supplier; vendor-filter gap.
  - `po_linked_gr_missing_supplier` — POSTED GR whose PO has no supplier.
- **PASS (informational)**
  - `shared_batch_number_across_products` — the GR default batch legitimately reuses one batch_number across products; each is a **distinct per-product batch** (never a global batch).

## Safety

Read-only. Never mutates data. Never renders KTP/NIK/medical data. Safe to run on production/VPS at any time.
