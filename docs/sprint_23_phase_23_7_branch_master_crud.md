# Sprint 23 Phase 23.7 — Master Data Cabang CRUD UI for RME + Inventory

## 1. Summary

- **Status:** PASS (local only — no VPS deploy, no push).
- **Branch:** `feature/sprint-23-phase-23-7-branch-master-crud` (based on `sprint-23-phase-23-5-branch-scope-dashboard-rename`).
- **Commit:** see tag `sprint-23-phase-23-7-branch-master-crud`.
- **Scope:** Expose CRUD UI for Master Data Cabang so branch **code** and **name** can be entered manually. Branches drive the multi-branch modules (RME + Inventory) only. Lab remains single-branch / global. No new migration was required — all fields already existed.

## 2. Business Rule

- **RME** uses branch (multi-branch).
- **Inventory** uses branch (multi-branch).
- **Lab** is single-branch / global — no Lab checkbox, no Lab branch filter, no Lab branch enforcement. Legacy Lab `branch_id` columns are **not** dropped.
- Branch **code** and **name** are entered manually. Code is **not** auto-generated; it is trimmed + uppercased and kept unique.
- Branch code is reserved as a future patient-ID component. The final patient ID format is **NOT** finalized in this phase.

## 3. Data Model

Existing `mst_branches` columns were reused — **no new migration**:

| Column | Type | Notes |
| --- | --- | --- |
| `code` | string, unique | Manual, trimmed + uppercased, `regex:[A-Z0-9-]`, validated `max:20`. |
| `name` | string | Manual, validated `max:150`. |
| `is_active` | boolean (default true) | Active/inactive. |
| `is_rme_enabled` | boolean (default true) | Branch participates in RME (Phase 23.5 migration). |
| `is_inventory_enabled` | boolean (default true) | Branch participates in Inventory (Phase 23.5 migration). |

- The DB `code` column has no length constraint (legacy `string`); validation enforces `max:20`. No schema change was made to avoid a destructive/altering migration on existing pilot data.
- `Branch::MAIN_CODE = 'MAIN'` is the default head-office anchor for legacy/unscoped rows and is protected from deletion.

## 4. Permissions/Roles

New permissions (PermissionSeeder):

- `view_branch_master_data`
- `manage_branch_master_data`

Access matrix:

| Role | View | Manage |
| --- | --- | --- |
| Super Admin | ✅ (via `*`) | ✅ (via `*`) |
| Owner | ✅ | ✅ |
| Admin Klinik / Admin Lab / Finance | ❌ | ❌ |
| Doctor | ❌ | ❌ |
| Kasir | ❌ | ❌ |
| Perawat | ❌ | ❌ |
| Courier | ❌ | ❌ |

Conservative assignment per phase brief: Super Admin + Owner only. `BranchPolicy` gates read on `view_branch_master_data` (or manage), and all write abilities on `manage_branch_master_data`.

## 5. Routes

`Route::resource('branches')->except(['show'])` under the `settings` prefix, gated `permission:view_branch_master_data|manage_branch_master_data`:

- `settings.branches.index`
- `settings.branches.create`
- `settings.branches.store`
- `settings.branches.edit`
- `settings.branches.update`
- `settings.branches.destroy`

## 6. UI

- **Sidebar:** "Master Data Cabang" added to the master-data group, gated by `view_branch_master_data | manage_branch_master_data`. Hidden for Courier and other unauthorized roles. Existing labels (Dashboard Owner/Inventory/RME/Lab, Master Data Klinik, Pengadaan, Batch & Lot) untouched.
- **Index** (`settings/branches/index.blade.php`): columns Kode Cabang, Nama Cabang, Status, RME, Inventory, Aksi. Status/RME/Inventory rendered as badges. Search by name/code.
- **Create/Edit** (`create.blade.php`, `edit.blade.php`, `_form.blade.php`): fields Kode Cabang, Nama Cabang, Aktif, Digunakan untuk RME, Digunakan untuk Inventory. Helper text under Kode Cabang: *"Kode cabang diisi manual dan akan digunakan sebagai komponen format ID pasien."* No Lab checkbox.
- **Destroy:** soft delete (reversible). The default `MAIN` branch cannot be deleted (controller guard returns an error flash; the delete button is hidden for it).

## 7. Integration

- **RME branch source:** active + `is_rme_enabled` branches (`Branch::rmeEnabled()` scope). RME modules are branch-aware.
- **Inventory branch source:** active + `is_inventory_enabled` branches (`Branch::inventoryEnabled()` scope). Inventory is branch-aware.
- **Lab:** not branch-aware. No branch dropdown, no branch filter, no Lab checkbox in branch master. Rule is centralized in `config/module_branch_scope.php` + `App\Modules\Branch\Support\ModuleBranchScope` (`rme`/`inventory` = `multi_branch`, `lab` = `single_branch`). Legacy Lab `branch_id` columns retained.

## 8. Tests

Commands run:

- `php artisan test tests/Feature/Branch/BranchMasterDataTest.php tests/Feature/BranchScope/BranchModuleScopeTest.php tests/Feature/Navigation/SidebarPermissionVisibilityTest.php` → 21 passed.
- `php artisan test --filter=Branch` → 293 passed.
- `php artisan test --filter=Permission` → 155 passed.
- `php artisan test --filter=Dashboard` → 120 passed.
- `php artisan test --filter=Sidebar` → 42 passed.
- `php artisan test --filter=Lab` → 195 passed.
- `php artisan test --filter=Inventory` + `--filter=Rme` → 1056 passed.
- `./vendor/bin/pint --dirty` → OK (import ordering on `routes/web.php`).
- `npm run build` → OK.

New/updated tests:

- `tests/Feature/Branch/BranchMasterDataTest.php` — list view, manual create, code uppercase normalization, duplicate rejection, RME flag, Inventory flag, no Lab flag in form, MAIN-delete guard, unauthorized role forbidden, Courier forbidden, view-only cannot create.
- `tests/Feature/BranchScope/BranchModuleScopeTest.php` — `rmeEnabled`/`inventoryEnabled` scopes filter by flag; RME+Inventory multi-branch, Lab single-branch.
- `tests/Feature/Navigation/SidebarPermissionVisibilityTest.php` — "Master Data Cabang" visible for Owner, hidden for Courier.

Known limitations: full end-to-end suite not executed (runtime budget); focused suites covering the touched areas all passed.

## 9. Files Changed

**Models/migrations**
- (none new) — reused `app/Modules/Branch/Models/Branch.php` and existing `mst_branches` schema.

**Requests/controllers/services/repositories/policies**
- `app/Modules/Branch/Controllers/BranchController.php` — docblock + MAIN-delete guard.
- `app/Modules/Branch/Requests/StoreBranchRequest.php` — normalization, module flags, `max:20` + regex.
- `app/Modules/Branch/Requests/UpdateBranchRequest.php` — same.
- `app/Modules/Branch/Policies/BranchPolicy.php` — new permission gates.
- (Service/Repository/Interface unchanged — already complete from Sprint 9 skeleton.)

**Views/sidebar**
- `resources/views/settings/branches/index.blade.php` (new)
- `resources/views/settings/branches/create.blade.php` (new)
- `resources/views/settings/branches/edit.blade.php` (new)
- `resources/views/settings/branches/_form.blade.php` (new)
- `resources/views/layouts/partials/sidebar.blade.php` — menu item + route-open + group canany.

**Routes**
- `routes/web.php` — `BranchController` import + `settings.branches` resource group.

**Seeders**
- `database/seeders/PermissionSeeder.php` — two new permissions.
- `database/seeders/RoleSeeder.php` — Owner gets view + manage.

**Tests**
- `tests/Feature/Branch/BranchMasterDataTest.php` (new)
- `tests/Feature/BranchScope/BranchModuleScopeTest.php` (new)
- `tests/Feature/Navigation/SidebarPermissionVisibilityTest.php` (updated)

**Docs**
- `docs/sprint_23_phase_23_7_branch_master_crud.md` (this file)
- `docs/sprint_history.md` (Phase 23.7 entry)

## 10. Watch Items

- **Patient ID final format still pending owner approval.** Branch code is reserved as a `{BRANCH_CODE}` token only.
- Existing branch data must be reviewed on VPS before enabling the final patient code (codes were created before normalization; verify they match `[A-Z0-9-]` / `max:20`).
- Lab legacy `branch_id` columns are **not** dropped and must remain.
- VPS deploy requires DB backup first and `php artisan migrate --force` only. **Never** `migrate:fresh`, `db:wipe`, `migrate:refresh`, or `migrate:reset` on VPS.
- After deploy, re-run `PermissionSeeder` + `RoleSeeder` so the two new permissions and Owner assignment exist; reset `storage`/`bootstrap/cache` permissions.

## 11. Patient ID Preparation (documentation only)

The manually entered branch code can later serve as a `{BRANCH_CODE}` token in the patient ID. **Possible** examples only — final format is NOT locked in this phase:

- `RM-{BRANCH_CODE}-{YYYYMM}-{SEQ6}`
- `RM-{BRANCH_CODE}-{YY}-{SEQ5}`
- `{BRANCH_CODE}-RM-{SEQ6}`

## 12. Next Recommended Phase

Sprint 23 Phase 23.8 — Patient ID Format Finalization + New Patient Registration Flow.
