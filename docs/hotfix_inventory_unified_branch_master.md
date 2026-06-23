# Hotfix — Inventory Unified Branch Master Guardrail

Status: **Governance hotfix.** Tests + documentation only. No schema, data, or business-flow change.
Last updated: June 2026

---

## Background

DaengtisiaMS / ADLMS is a Laravel modular monolith. The RME multi-branch work established
`mst_branches` (model `App\Modules\Branch\Models\Branch`) as the branch master. As more modules
gained branch awareness (Inventory, Lab, Cashier, Receivables, Owner Dashboard, Access Control),
there is a standing risk that a future contributor introduces a *module-specific* branch master
table — e.g. `inventory_branches` — duplicating branch identity data and fracturing the single
source of truth.

An audit of the current Inventory module was performed as part of this hotfix. **Result: the
Inventory module is already fully unified.** Every branch-scoped Inventory table declares
`branch_id` as a foreign key to `mst_branches`, every Inventory Eloquent `branch()` relation
resolves to the shared `Branch` model, every branch filter/dropdown resolves through `Branch` /
`BranchContext`, and the `InventorySeeder` reuses the existing `MAIN` branch rather than minting a
new one. No redundant or duplicated branch master was found.

Because the architecture is already correct, this hotfix does **not** change behavior. It locks the
invariant in place with automated guardrail tests so a regression fails loudly in CI.

## Decision

`mst_branches` is the **single source of truth** for branch identity across **all** modules. Every
module — including Inventory, Lab, Cashier, Receivables, Owner Dashboard, and Access Control —
references the same branch master table that RME uses: `mst_branches` (`Branch` model).

The following module-specific branch master tables are **forbidden** and must never be created:

- `inventory_branches`
- `inv_branches`
- `lab_branches`
- `cashier_branches`
- `rme_branches`

## Allowed pattern

- Inventory tables store `branch_id` as a foreign key to `mst_branches.id`
  (`$table->foreignId('branch_id')->constrained('mst_branches')`).
- Inventory Eloquent models expose a `branch()` `belongsTo(Branch::class)` relation that resolves
  to `mst_branches`.
- Branch selectors, filters, dashboards, reports, and validation rules resolve branch data through
  `App\Modules\Branch\Models\Branch` / `App\Modules\Branch\Services\BranchContext`, and validate
  with `exists:mst_branches,id`.
- Inventory warehouse / location / room data (`inv_inventory_locations`) may exist as
  branch-scoped child records, but must reference `mst_branches` for branch identity and must not
  duplicate branch master fields.
- Module enablement flags may live on `mst_branches` only (e.g. `is_rme_enabled`,
  `is_inventory_enabled`), and these already exist. Do not add new branch columns without need.

## Forbidden pattern

- Creating any `*_branches` module-specific branch master table (see the forbidden list above).
- Duplicating branch master identity columns (`branch_name`, `branch_code`, etc.) into Inventory
  tables instead of joining to `mst_branches`.
- Hardcoding branch names/codes in Inventory seeders, controllers, services, requests, policies,
  views, factories, or tests.
- Inventory dropdowns/filters sourcing branch data from anything other than `Branch` /
  `mst_branches`.
- Seeders creating new branch master rows from inside the Inventory module.

## Module impact

| Module | Branch source after hotfix |
|---|---|
| RME | `mst_branches` (unchanged — already canonical) |
| Inventory | `mst_branches` via `Branch` / `BranchContext` (confirmed; now guarded) |
| Lab | `mst_branches` (Lab is global; no `lab_branches`) |
| Cashier | `mst_branches` |
| Receivables | `mst_branches` |
| Owner Dashboard | `mst_branches` |
| Access Control | `mst_branches` |

No runtime behavior changed. The only code added is a test file; the only docs added/updated are
this file and `docs/sprint_history.md`.

## Migration safety note

- **No migration was added in this hotfix.**
- No table was created, altered, or dropped. No column was added or removed. No data was migrated
  or backfilled.
- Per project VPS rules, never run `migrate:fresh` or `db:wipe` on the VPS; use
  `php artisan migrate --force` only. This hotfix introduces nothing to migrate.
- If a redundant branch column/table is ever discovered in the future, do **not** drop it in a
  hotfix. Instead: add compatibility/backfill only if needed, document the redundancy as
  deprecated, route new code paths through `mst_branches`, and rely on these guardrail tests to
  block new redundancy.

## Testing evidence

Guardrail test: `tests/Feature/Inventory/InventoryUnifiedBranchMasterHotfixTest.php`. It verifies:

- The unified `mst_branches` table exists and the `Branch` model is bound to it.
- None of the forbidden module-specific branch master tables exist in the schema.
- No migration file creates any forbidden branch master table.
- Every branch-scoped Inventory table has a `branch_id` foreign key referencing `mst_branches`
  (SQLite `PRAGMA foreign_key_list` introspection).
- Every Inventory model's `branch()` relation resolves to the `Branch` model / `mst_branches`.
- Inventory branch filter requests validate `branch_id` against `mst_branches` (never a module
  table).
- No Inventory module source file references a forbidden branch master table.
- Inventory resolves branch data through the unified `Branch` model / `BranchContext`.
- `InventorySeeder` reuses the existing `mst_branches` row and never creates a branch master record.
- A persisted Inventory stock movement carries a `branch_id` that ties to a real `mst_branches` row.

Commands run:

```
php artisan test --filter=InventoryUnifiedBranchMasterHotfixTest   # 11 passed (1339 assertions)
php artisan test --filter=Inventory                                # 1189 passed (6096 assertions)
vendor/bin/pint --test                                             # (new file formatted to pass)
git diff --check                                                   # clean
```

## Future sprint rule

Any future module that becomes branch-aware **must** reference `mst_branches` via the shared
`Branch` model / `BranchContext`. Introducing a module-specific branch master table is prohibited
and will fail the guardrail test. Extend
`tests/Feature/Inventory/InventoryUnifiedBranchMasterHotfixTest.php` (and add equivalent guardrails
for new modules) when new branch-scoped Inventory tables are created so the foreign-key invariant
continues to be enforced.
