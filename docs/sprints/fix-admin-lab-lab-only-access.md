# FIX-ADMIN-LAB-LAB-ONLY-ACCESS — Restrict Admin Lab Role to Lab Module & Lab Workflow Only

Branch: `feature/fix-admin-lab-lab-only-access`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target `main`)
Baseline: `fix-lab-technician-role-assignment-upload-compression-go` @ `32923d8`
GO tag (on merge): `fix-admin-lab-lab-only-access-go`

## Problem

The canonical `Admin Lab` role was massively over-privileged. It held RME
(`manage_rme_billing`, `view/manage_clinic_visits`), Inventory + Procurement
(`manage_inventory`, `approve_inventory_purchase_request/order`,
`view_inventory_executive_dashboard`, `view_inventory_cross_branch_analytics`,
`view_inventory_activity_log`, `download_stock_transfer_checklist`), master data
(`manage patients/doctors/clinics/master data`, clinic master data), and the
generic/branch dashboards (`view dashboard`, `view_branch_dashboard`). An
operational "Lab Admin" VPS account could additionally be running as `Super Admin`,
which bypasses every policy via `Gate::before`.

## Decision — Admin Lab is a LAB-ONLY role

Admin Lab now receives ONLY Lab-module + Lab Workflow V2 permissions plus the
legacy dental-lab billing + reporting module (all Lab-scoped). Shared patient /
doctor / branch labels are surfaced through Lab services on Lab detail pages, never
via CRUD grants to the source modules.

### Permissions KEPT (Lab-only) — single source of truth: `RoleSeeder::ROLE_PERMISSIONS['Admin Lab']`

- Lab master data: `manage lab services`, `manage technicians`
- Lab orders (legacy + V2): `manage lab orders`, `manage_lab_orders`, `view_lab_orders`,
  `create_lab_orders`, `update_lab_orders`, `cancel_lab_orders`
- Lab Workflow V2: `create_lab_branch_requests`, `manage_lab_pickups`
- Production: `manage_production`, `view_production`, `assign_technicians`,
  `reassign_technicians`, `start/pause/resume/complete_production_work`, `send_to_qc`
- QC: `manage_quality_control`, `view_quality_control`, `start_qc`, `pass_qc`,
  `reject_qc`, `request_remake`, `update_qc_checklist`, `upload_qc_evidence`
- Delivery: `manage_delivery`, `view_delivery`, `create_delivery`, `assign_courier`,
  `start_delivery`, `mark_delivered`, `complete_delivery`, `upload_pod`
- Legacy dental-lab billing (Lab-domain, NOT RME cashier): `manage_invoice`,
  `view_invoice`, `create_invoice`, `issue_invoice`, `void_invoice`, `manage_payment`,
  `view_payment`, `create_payment`
- Legacy dental-lab reporting (all Lab-scoped): `manage_report`, `view_dashboard`,
  `view_order_report`, `view_production_report`, `view_qc_report`, `view_delivery_report`,
  `view_invoice_report`, `view_payment_report`, `export_report`
- Legacy coarse Lab perms: `manage assignments`, `view reports`

### Permissions REVOKED (non-Lab) — single source of truth: `RoleSeeder::ADMIN_LAB_REVOKED_NON_LAB`

`view dashboard`, `view_branch_dashboard`, `manage master data`, `manage clinics`,
`manage doctors`, `manage patients`, `view_clinic_master_data`,
`manage_clinic_master_data`, `view_clinic_visits`, `manage_clinic_visits`,
`manage_rme_billing`, `manage_inventory`, `view_inventory`,
`view_inventory_executive_dashboard`, `view_inventory_cross_branch_analytics`,
`view_inventory_activity_log`, `download_stock_transfer_checklist`,
`approve_inventory_purchase_request`, `approve_inventory_purchase_order`.

The permission DEFINITIONS remain in `PermissionSeeder` — they are still used by
other roles; only Admin Lab's grant of them is removed.

## Enforcement (three layers, not sidebar-only)

- **Route middleware** — RME (`rme.*`), settings/master-data (`settings.*`), the
  generic dashboard (`view dashboard|view_owner_dashboard`), and the developer
  console are gated by `permission:` middleware → real 403 for Admin Lab.
- **Controller policy** — the `inventory` route group has no route-level permission
  middleware; every inventory controller calls `$this->authorize('viewAny', Model)`,
  and the inventory policies key off `manage_inventory` / `view_inventory` /
  `manage master data`. Admin Lab holds none → `AuthorizationException` → 403.
- **Sidebar** is purely permission-driven, so removing the permissions auto-hides
  RME, Inventory, Procurement, and non-Lab master-data menus. The sidebar is NOT a
  security boundary; the 403s above are the real boundary.

## Landing page

`AuthenticatedSessionController::redirectPathFor()` now sends an `Admin Lab` user
(that is not also `Super Admin`) to `lab-v2-orders.index` (the Lab Workflow V2 orders
workspace), guarded by `Route::has`. Admin Lab no longer holds `view dashboard`, so
the generic cross-module dashboard is forbidden.

## Super Admin leakage on live accounts

`Gate::before` grants `Super Admin` a full bypass. A "Lab Admin" operational account
still carrying `Super Admin` cannot be restricted by trimming the Admin Lab role — it
must be demoted. This is handled by the audit/repair command below, with guards:

- Never removes the last `Super Admin` (refuses to leave zero).
- Only the `Super Admin` role is removed; other roles are preserved.
- Only runs on an explicitly targeted, operator-verified user id.
- The primary platform Super Admin is never modified.

## Tooling — `rbac:admin-lab-lab-only-audit`

Read-only by default; explicit repair flags:

- `--json`, `--strict` (exit 2 on any remaining anomaly)
- `--sync-role` — idempotently re-sync ONLY the Admin Lab role to its canonical set
- `--strip-direct` — revoke stray revoked non-Lab DIRECT permissions from Admin Lab accounts
- `--demote=<id>` — guarded Super Admin → Admin Lab demotion of a verified account

Backed by `App\Support\AccessControl\AdminLabLabOnlyAuditor` (read-only `audit()` +
`syncRole()`, `stripDirectRevokedFromAdminLabUsers()`, `demoteSuperAdminToAdminLab()`).
Privacy-safe: never prints passwords/tokens/KTP/NIK.

## QC segregation & Technician eligibility (unchanged)

Admin Lab keeps QC permissions; QC segregation of duty (a producing technician
cannot pass/fail their own QC) is enforced by the Lab Workflow V2 state machine, not
by withholding Admin Lab's QC permission. Technician assignment eligibility
(`TechnicianAssignmentEligibility`, role `Technician`) and Lab evidence
privacy/compression are untouched.

## Tests

- New: `tests/Feature/AccessControl/AdminLabLabOnlyAccessTest.php` (16) — role matrix,
  effective permissions / no Gate::before bypass, Lab routes 200, non-Lab routes 403,
  Lab-only sidebar, Lab landing redirect, auditor + command.
- Updated (old over-privileged contract → new Lab-only contract):
  `RolePermissionHardeningTest`, `RoleManagementTest`, `InventoryPermissionHardeningTest`,
  `PurchaseOrderPolicyTest`, `GoodsReceiptPolicyTest`,
  `InventoryBranchComparisonAuthorizationTest`, `StockTransferChecklistPdfTest`
  (positive inventory workflow moved to `Admin Warehouse`; Admin Lab asserted denied),
  `SidebarPermissionVisibilityTest` (Admin Lab sidebar + `/dashboard` 403),
  `RmeLabCandidateE2EValidationTest` (cashier pages moved to the Kasir actor).

## No migration

Permission changes are seeder/config only. No schema/migration, no new permission
definition, no permission definition removed.

## Deploy note

- `php artisan db:seed --class=RoleSeeder --force` (idempotent; syncs Admin Lab role).
- `php artisan permission:cache-reset`.
- Audit + guarded repair of the live Lab Admin account:
  `php artisan rbac:admin-lab-lab-only-audit` (verify), then `--strip-direct` and, only
  after confirming account identity and that another Super Admin exists,
  `--demote=<id>`. Never modify the primary Super Admin.

## Pre-existing (NOT this sprint)

`RmeSmokeTestRouteTest > Perawat` returns 302 (RME-BRANCH-SUN4 Perawat online-context
selector; the smoke seeder does not set Perawat online context). Verified failing
identically on the base branch with this sprint's changes stashed.
