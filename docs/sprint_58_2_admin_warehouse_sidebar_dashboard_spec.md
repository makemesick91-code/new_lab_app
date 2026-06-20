# Sprint 58.2 — Admin Warehouse Sidebar Dashboard Replacement Hotfix — Implementation Spec

## 1. Goal
For role `Admin Warehouse` only, the single main sidebar "Dashboard" entry must point to
`inventory.dashboard` (`/inventory/dashboard`) instead of `dashboard` (`/dashboard`),
keeping the label `Dashboard`, with no duplicate dashboard items.

## 2. Current sidebar behavior
- File: `resources/views/layouts/partials/sidebar.blade.php`.
- Top-level Dashboard item (lines 91-99): gated by `@canany(['view dashboard', 'view_owner_dashboard'])`,
  `href = route('dashboard')`, active = `request()->routeIs('dashboard')`,
  label `Dashboard` (or `Dashboard Owner` when `view_owner_dashboard`).
- Persediaan group (`$showInventoryGroup`, line 237+) contains a sub-item
  "Dashboard Inventory" (lines 252-253) → `route('inventory.dashboard')`, and
  "Dasbor Eksekutif" (lines 276-277) → `route('inventory.executive-dashboard')`.

## 3. Expected Admin Warehouse behavior
- Exactly ONE main Dashboard entry: label `Dashboard`, href `/inventory/dashboard`,
  route `inventory.dashboard`, active when `request()->routeIs('inventory.dashboard')`.
- No main Dashboard link to `/dashboard`.
- No duplicate "Dashboard Inventory" sub-item (it also targets `/inventory/dashboard`) → hidden for this role.
- "Dasbor Eksekutif" (`/inventory/executive-dashboard`) is a distinct item with distinct label/URL — left unchanged.

## 4. Expected non-warehouse behavior
- Completely unchanged. Top-level Dashboard still gated by `@canany`, href `/dashboard`,
  active on `dashboard`, label `Dashboard`/`Dashboard Owner`. "Dashboard Inventory" sub-item still shown.

## 5. Non-goals
- No login-redirect change (Sprint 58.1 keeps `inventory.executive-dashboard`).
- No controller/view/permission/role/policy/middleware/schema changes.
- No dependency changes. No Playwright file commits.

## 6. Files inspected
- `resources/views/layouts/partials/sidebar.blade.php` (lines 1-99, 237-286).
- `php artisan route:list` confirms `dashboard`, `inventory.dashboard`, `inventory.executive-dashboard` exist.
- `tests/Feature/Auth/AdminWarehouseRedirectTest.php`, `tests/Feature/Navigation/SidebarCollapseToggleTest.php`.

## 7. Files expected to change
- `resources/views/layouts/partials/sidebar.blade.php` (Blade only).
- `tests/Feature/Navigation/AdminWarehouseSidebarDashboardTest.php` (new test).
- `docs/sprint_58_2_admin_warehouse_sidebar_dashboard_spec.md` (this doc).

## 8. Sidebar rendering mechanism discovered
Single static Blade partial, permission-aware via Spatie `@can`/`@canany`/`@role` and
`$user->can(...)` PHP calls in a top `@php` block. Not DB-driven. Safe to edit.

## 9. Role-check design
Add `$isAdminWarehouse = $user?->hasRole('Admin Warehouse');` to the top `@php` block
(consistent with the existing `$user->can(...)` style in that same block). No role/permission renaming.

## 10. Route / active-state design
Top-level Dashboard becomes branch-by-role:
- Admin Warehouse → `route('inventory.dashboard')`, active `request()->routeIs('inventory.dashboard')`.
- Others → existing `@canany` block unchanged (`route('dashboard')`, active `routeIs('dashboard')`).
The Admin Warehouse branch renders by role (not permission gate) to guarantee exactly one Dashboard
even if the role lacks the `view dashboard` permission. Navigation only — no route authorization granted.

## 11. Duplicate prevention design
Wrap the "Dashboard Inventory" sub-item (lines 252-253) in `@unless ($isAdminWarehouse) ... @endunless`
so Admin Warehouse does not get a second link to `/inventory/dashboard`. All other inventory sub-items
and "Dasbor Eksekutif" remain unchanged.

## 12. Test plan
New `AdminWarehouseSidebarDashboardTest`:
- Admin Warehouse: sidebar contains a Dashboard link to `/inventory/dashboard`.
- Admin Warehouse: sidebar contains NO link to `/dashboard` href in the menu.
- Admin Warehouse: only one occurrence of `href="/inventory/dashboard"` (no duplicate).
- Non-warehouse role (e.g. Owner/Admin): sidebar Dashboard still links to `/dashboard`.
Plus run existing Navigation/AdminWarehouse suites + Pint + `git diff --check`.

## 13. Risk checklist
- No schema/migration. No permission slug rename. No role assignment change. No policy/middleware change.
- No login redirect change. Navigation-only Blade change + additive test. Branch isolation untouched.

## 14. Rollback plan
Revert the single Blade commit (`git revert <hash>`); spec + test are additive. No data/deploy to undo.
