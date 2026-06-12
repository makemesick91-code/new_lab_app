# Sprint 23 Phase 23.5 — Branch Scope Correction + Dashboard Renaming + RME Report Roles

- **Branch:** `feature/sprint-23-phase-23-5-branch-scope-dashboard-rename` (cut from tag `sprint-23-phase-23-3-owner-dashboard-rme-identity`)
- **Status:** PASS (local development phase — not deployed to VPS)
- **Scope:** Local only. No VPS work. No destructive DB commands. Additive migration only.

## 1. Business Rule Update

Owner decision after the Phase 23.4 smoke:

- **RME** is multi-branch.
- **Inventory** is multi-branch.
- **Lab / Laboratory** is single-branch / **global** — no branch filter, no branch enforcement, no branch-grouped KPI.
- **RME KPI and Lab KPI are separated.**
- Module dashboard labels renamed to the English, correctly-spelled **"Dashboard …"**.
- RME report access is split into separate **patient** and **payment** permissions/roles.

## 2. Branch Scope Architecture

| Module    | Branch Scope  | Notes                                            |
| --------- | ------------- | ------------------------------------------------ |
| RME       | Multi-branch  | Uses branch master; branch filter allowed        |
| Inventory | Multi-branch  | Uses branch master; branch filter allowed        |
| Lab       | Single/global | No branch enforcement/filter; legacy `branch_id` kept but unused for behavior |

Centralized rule:

- `config/module_branch_scope.php` — `['rme' => 'multi_branch', 'inventory' => 'multi_branch', 'lab' => 'single_branch']`.
- `App\Modules\Branch\Support\ModuleBranchScope` — `scope()`, `isMultiBranch()`, `isSingleBranch()`, `usesBranchFilter()`. Read the rule here instead of hard-coding module names.

## 3. Master Data Cabang

Master Data Cabang now serves **RME and Inventory only**.

- Existing `mst_branches` table reused — **no duplicate table created**.
- Additive migration `2026_06_15_100001_add_module_flags_to_mst_branches_table` adds:
  - `is_rme_enabled` (boolean, default `true`)
  - `is_inventory_enabled` (boolean, default `true`)
  - Existing branches default to enabled for both, so current pilot data keeps working.
- `Branch` model: added the two columns to `$fillable` + boolean casts, plus `scopeRmeEnabled()` and `scopeInventoryEnabled()`.
- Lab is **not** represented by a flag on purpose (Lab is global).
- **UI exposure deferred:** the `BranchController` CRUD has never been wired to routes (Sprint 9 left `settings.branches.*` unrouted). This phase does not expose an unwired CRUD; the flags + model + config carry the business rule. Wiring the Master Data Cabang screen is a follow-up item.

## 4. Lab Multi-branch Removal

- **Lab order list** (`LabOrderRepository`) is global: the branch filter is opt-in and no caller passes `branch_id`, so the active branch context never scopes the list. Comment updated to lock the Phase 23.5 intent; **no `BranchContext` enforcement wired**.
- **Owner Dashboard Lab metrics** (`OwnerDashboardRmeLabKpiService::metrics`) — the lab candidate / lab order counts are now computed **globally** (branch filter removed from those queries). The Owner branch filter now applies only to RME metrics.
- **Legacy `branch_id` columns retained** on lab tables (`trx_lab_orders`, `trx_lab_case_candidates`). **Not dropped.** Existing lab data with `branch_id` still displays; new lab order creation is unchanged.
- The `LabCaseCandidate` queue branch isolation (the RME→Lab handoff staging from Phases 21.3/21.4) is **left intact** — candidates are RME-sourced records and removing their isolation could leak RME data across branches. See Watch Items.

## 5. KPI Separation

Two dedicated services were added (existing combined service kept for the Owner dashboard view, with its Lab queries made global):

- `App\Modules\Reporting\Services\RmeDashboardKpiService` — **branch-aware**. `metrics(?int $branchId)` honours the selected RME branch (null = all active RME-enabled branches). Patient/visit/record/invoice/payment metrics only.
- `App\Modules\Reporting\Services\LabDashboardKpiService` — **global**. `metrics()` takes **no branch parameter**, so a branch filter can never leak into Lab KPIs. Lab order/candidate metrics across all data; `scope_label = "Laboratorium global"`.
- Owner Dashboard may show both RME and Lab summary cards; the branch filter applies to RME only.

## 6. Dashboard Renaming

| Dashboard | Menu label          | Page title             |
| --------- | ------------------- | ---------------------- |
| Owner     | Dashboard Owner¹    | Dashboard Owner        |
| Inventory | Dashboard Inventory | Dashboard Inventory    |
| RME       | Dashboard RME²      | (RME report pages)     |
| Lab       | Dashboard Lab³      | Dashboard Lab          |

¹ Sidebar home link shows **"Dashboard Owner"** when the user can `view_owner_dashboard`, otherwise the generic **"Dashboard"** (operational roles must not see an "Owner"-labelled link).
² The "Klinik / RME" sidebar group header was renamed to **"Dashboard RME"** (gated by `view_clinic_visits|manage_clinic_visits`).
³ The reporting-group dashboard (lab reporting) sub-item / page renamed to **"Dashboard Lab"**.

Spelling is the correct **"Dashboard"** everywhere (no "Dashbord"/"Dasboard"). Route **names were not changed** — only labels/titles — so existing links stay valid.

## 7. RME Report Roles

### Permissions (new)

- `view_rme_patient_reports`
- `view_rme_payment_reports`

### Roles (new) + grants

- New roles: **Laporan Pasien RME** (patient permission only), **Laporan Pembayaran RME** (payment permission only).
- **Owner** granted both report permissions.
- **Kasir** granted `view_rme_payment_reports` only (payment, not patient).
- **Super Admin** has both (via `*`).
- **Doctor** granted neither.

### Routes / pages

- `rme.reports.patients` → `RmeReportController@patients` (middleware `view_rme_patient_reports`).
- `rme.reports.payments` → `RmeReportController@payments` (middleware `view_rme_payment_reports`).
- Both are branch-aware (RME-enabled branch filter). Views under `resources/views/rme/reports/`.

### Access matrix

| Role / permission            | Patient report | Payment report |
| ---------------------------- | :------------: | :------------: |
| `view_rme_patient_reports`   | ✅             | 403            |
| `view_rme_payment_reports`   | 403            | ✅             |
| Super Admin                  | ✅             | ✅             |
| Owner                        | ✅             | ✅             |
| Kasir                        | 403            | ✅             |
| Doctor / clinical only       | 403            | 403            |

## 8. Tests

New: `tests/Feature/BranchScope/BranchScopeDashboardRenameTest.php` (14 tests) — module scope rule, branch flags + scopes, RME KPI branch filter, Lab KPI global, sidebar dashboard labels, full RME report access matrix.

Updated existing tests to the new "Dashboard" convention: `SidebarPermissionVisibilityTest`, `OwnerDashboardUiTest`, `OwnerDashboardRmeLabKpiTest`, `OwnerDashboardBranchFilterDrilldownTest`, `BranchAdminDashboardUiTest`, `OwnerEnablementTest`, `InventoryUiTest`, `DashboardReportTest`.

Commands run (local):

- `php artisan test tests/Feature/BranchScope/...` → 14 passed.
- `php artisan test tests/Feature/Dashboard tests/Feature/RME tests/Feature/Reporting tests/Feature/Auth` → 487 passed.
- `php artisan test tests/Feature/LabOrder tests/Feature/RME/LabIntegrationTest.php tests/Feature/RME/LabCaseCandidateConversionTest.php tests/Feature/Navigation tests/Feature/Pilot tests/Feature/Dashboard/OwnerDashboardBranchFilterDrilldownTest.php` → 157 passed.
- `./vendor/bin/pint --dirty` → passed.
- `npm run build` → built OK.

Known issue: full `php artisan test` suite not run end-to-end in this phase due to runtime budget; affected areas (Dashboard, RME, Lab, Reporting, Navigation, Pilot, Inventory UI, Auth, BranchScope) were run focused and all passed.

## 9. Files Changed

**Config/service/support**
- `config/module_branch_scope.php` (new)
- `app/Modules/Branch/Support/ModuleBranchScope.php` (new)
- `app/Modules/Reporting/Services/RmeDashboardKpiService.php` (new)
- `app/Modules/Reporting/Services/LabDashboardKpiService.php` (new)
- `app/Modules/Reporting/Services/OwnerDashboardRmeLabKpiService.php` (lab metrics → global)
- `app/Modules/LabOrder/Repositories/LabOrderRepository.php` (intent comment)
- `app/Modules/Branch/Models/Branch.php` (flags + scopes)

**Migration**
- `database/migrations/2026_06_15_100001_add_module_flags_to_mst_branches_table.php` (additive)

**Controllers/routes**
- `app/Modules/RmeInvoice/Controllers/RmeReportController.php` (new)
- `routes/web.php` (RME report routes + import)

**Views/sidebar**
- `resources/views/layouts/partials/sidebar.blade.php` (label renames + RME report links)
- `resources/views/dashboard.blade.php` (header labels)
- `resources/views/inventory/dashboard.blade.php` (title)
- `resources/views/reports/dashboard.blade.php` (title)
- `resources/views/rme/reports/patients.blade.php`, `payments.blade.php` (new)

**Permissions/roles/seeders**
- `database/seeders/PermissionSeeder.php`, `database/seeders/RoleSeeder.php`

**Tests**
- `tests/Feature/BranchScope/BranchScopeDashboardRenameTest.php` (new) + 8 updated test files (see §8)

**Docs**
- `docs/sprint_23_phase_23_5_branch_scope_dashboard_rename.md` (this file), `docs/sprint_history.md`

## 10. Risks / Watch Items

- **Do not drop Lab `branch_id` columns yet.** They remain for backward compatibility; Lab behavior no longer depends on them.
- Existing Lab data with `branch_id` remains backward-compatible and still displays.
- `LabCaseCandidate` branch isolation (RME→Lab handoff) is intentionally retained; revisit only if the owner wants cross-branch candidate visibility.
- **Master Data Cabang UI is not yet exposed** (controller unrouted since Sprint 9). The branch flags are functional at the data/model layer; wiring the screen is a follow-up.
- **VPS deploy requires DB backup first**; use `php artisan migrate --force` only. **Never** `migrate:fresh` / `db:wipe` / `migrate:refresh` / `migrate:reset` on VPS. New seeded permissions/roles must be applied on VPS (run seeders) for the report roles to take effect.
- Patient code format still pending owner approval (carried from Phase 23.3) if not yet confirmed.

## 11. Next Recommended Phase

**Sprint 23 Phase 23.6 — VPS Deploy + Branch Scope/Dashboard/RME Report Smoke.** Deploy this branch after DB backup, run `PermissionSeeder`/`RoleSeeder`, and smoke-test: separated dashboards, Lab global KPI, RME branch filter, and the split RME report roles.
