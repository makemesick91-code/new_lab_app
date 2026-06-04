# Sprint 9 — Multi Branch Foundation

> Status: **Foundation only.** This sprint prepares the system for multiple
> laboratory branches without enforcing branch isolation. No routes, UI, or
> existing behavior change. Branch-scoped data visibility is deferred to a
> later sprint.

## 1. Goal

Asia Dental Lab plans to operate multiple branches (head office + satellite
labs). Sprint 9 lays the data + module foundation so a future sprint can turn on
per-branch scoping with minimal, low-risk change. The guiding principle is
**additive and inert**: every transaction row gains an optional branch owner,
but nothing yet filters by it.

## 2. Architecture

```
mst_branches (id, code, name, address, phone, is_active, timestamps, soft delete)
      ▲ belongsTo (branch_id, nullable, nullOnDelete)
      │
      ├── trx_lab_orders.branch_id
      ├── trx_lab_deliveries.branch_id
      ├── trx_invoices.branch_id
      └── trx_payments.branch_id
```

Module layout (`app/Modules/Branch/`), following the project's
Controller → Request → Service → Repository → Model pattern:

| Layer       | Class                                                        |
|-------------|-------------------------------------------------------------|
| Model       | `Models\Branch` (HasFactory, SoftDeletes, `MAIN_CODE`)      |
| Repository  | `Repositories\BranchRepository` ⟶ `Interfaces\BranchRepositoryInterface` |
| Service     | `Services\BranchService`                                    |
| Requests    | `Requests\StoreBranchRequest`, `Requests\UpdateBranchRequest` |
| Policy      | `Policies\BranchPolicy` (skeleton, `manage branches`)       |
| Controller  | `Controllers\BranchController` (skeleton, **not routed**)   |
| Factory     | `Database\Factories\BranchFactory` (`main()`, `inactive()`) |
| Seeder      | `Database\Seeders\BranchSeeder` (default MAIN branch)       |

Wiring is registered in `app/Providers/RepositoryServiceProvider.php`:
- `BranchRepositoryInterface → BranchRepository` binding,
- `Branch → BranchPolicy` policy registration (inert: nothing authorizes against
  it yet; Super Admin bypasses via `Gate::before`).

## 3. Data flow

1. **Schema** — `mst_branches` is created; `branch_id` (nullable FK,
   `nullOnDelete`) is added to the four core transaction tables.
2. **Seeding** — `BranchSeeder` runs **first** in `DatabaseSeeder` and
   `firstOrCreate`s the default branch (`code = MAIN`,
   "Asia Dental Lab Pusat"). Idempotent.
3. **Backfill** — the `backfill_default_branch` data migration resolves the MAIN
   branch by its business code and sets `branch_id = MAIN.id` on any row where it
   is `NULL`. It is a no-op if MAIN is not yet seeded, and only touches NULL rows
   (see §5).
4. **Runtime** — models expose `branch()` (belongsTo) and `Branch` exposes the
   inverse `labOrders/deliveries/invoices/payments` (hasMany). `branch_id` is
   mass-assignable on all four models. No query filters by branch yet.

## 4. Branch ownership model

- Every Lab Order, Delivery, Invoice, and Payment **may** belong to exactly one
  branch via `branch_id`.
- `branch_id` is **nullable** by design during the foundation phase so existing
  records and existing write paths keep working unchanged.
- The **MAIN** branch (head office) is the canonical anchor for any record that
  has no explicit branch — both legacy rows (via backfill) and any future row
  created without a branch context.
- `Branch::MAIN_CODE` is the single source of truth for that business code;
  resolve the branch by code, never by a hard-coded id.
- Deleting a branch sets dependent `branch_id` back to `NULL`
  (`nullOnDelete`) — transactions are never cascade-deleted with a branch.

## 5. Branch filtering strategy (NOT enforced this sprint)

The transaction repositories carry an **opt-in** branch filter:

```php
->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
```

This applies **only** when a caller explicitly passes `branch_id`. No caller
does today, so listings are unchanged. `PaymentRepository` (listed per-invoice)
documents the same future hook in a class-level TODO.

When scoping is turned on (future sprint), the plan is:
1. Resolve the active branch from the authenticated user (a `branch_id` on the
   user, or a branch switcher in session).
2. Inject that branch into the repository filters by default.
3. Grant **Super Admin** (and any "all branches" role) a bypass so they can see
   every branch — mirroring the existing `Gate::before` Super Admin pattern.
4. Enforce on writes too: stamp `branch_id` on create from the active branch.

Each opt-in filter is marked with a `TODO(branch-scope):` comment at its call
site for discoverability.

## 6. Migration summary

| Migration                              | Type   | Effect                                                        |
|----------------------------------------|--------|--------------------------------------------------------------|
| `create_mst_branches_table`            | schema | Creates `mst_branches` (pre-existing).                       |
| `add_branch_id_to_core_transaction_tables` | schema | Adds nullable `branch_id` FK to the 4 trx tables (pre-existing). |
| `backfill_default_branch`              | data   | Fills NULL `branch_id` with MAIN id. **Idempotent, no data loss.** Implemented this sprint. |

Safety properties of the backfill:
- Only updates rows where `branch_id IS NULL` (already-scoped rows untouched).
- No-ops gracefully if the MAIN branch is not yet seeded.
- `down()` is intentionally a no-op (reverting would lose the original NULL
  state and risk data loss).

## 7. Testing

- `tests/Feature/Branch/BranchRelationshipTest.php` — `belongsTo Branch` for
  LabOrder/Delivery/Invoice/Payment, the inverse `hasMany` on Branch, and the
  null-branch (non-enforcement) case.
- `tests/Feature/Branch/BranchSeederTest.php` — MAIN branch is seeded, seeding
  is idempotent, the repository resolves the default branch, and the factory
  `main()` state.

## 8. Future multi-tenant roadmap

1. **Sprint 10 (proposed) — Branch context & user assignment**
   - Add `branch_id` to `users` (nullable; null = head office / all-branch).
   - Resolve the active branch in middleware/session; add a branch switcher for
     multi-branch users.
   - Add `manage branches` permission to `PermissionSeeder` and wire the
     `BranchController` routes + views (Settings › Branches CRUD).
2. **Sprint 11 (proposed) — Enforce scoping**
   - Default the repository `branch_id` filter from the active branch.
   - Stamp `branch_id` on create across LabOrder/Delivery/Invoice/Payment.
   - Super-Admin / "all branches" bypass.
   - Branch-aware reporting & dashboard filters.
3. **Later — Full multi-tenant**
   - Per-branch numbering sequences, per-branch settings, cross-branch transfer
     workflow, and (if needed) row-level tenancy guarantees.

## 9. Constraints honored

- No route changes. No UI changes. No data loss.
- Existing behavior and tests preserved (branch filtering not enforced).
- PostgreSQL; Controller → Request → Service → Repository → Model pattern;
  existing module structure reused.
