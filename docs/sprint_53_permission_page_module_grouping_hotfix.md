# Sprint 53 — Permission Page Module Grouping Hotfix

## 1. Title

Sprint 53 — Permission Page Module Grouping Hotfix

- Branch: `feature/sprint-53-permission-page-module-grouping-hotfix`
- Feature tag: `sprint-53-permission-page-module-grouping-hotfix`
- Future GO tag (after PR merge only): `sprint-53-permission-page-module-grouping-hotfix-go`
- Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- Baseline: Sprint 52 GO / merge commit `cf29f45`
  (`sprint-52-inventory-operator-pilot-input-template-stock-opname-readiness-go`)

## 2. Status

Local permission UI hotfix implementation / pending PR. Not pushed, no PR, no GO tag.

## 3. Baseline

- Base branch HEAD: `cf29f45`
  (Merge pull request #48 — Sprint 52 inventory operator pilot input template / stock opname readiness).
- GO tag at baseline HEAD: `sprint-52-inventory-operator-pilot-input-template-stock-opname-readiness-go`.
- Working tree clean before Sprint 53 changes.

## 4. Purpose

Sprint 53 is a **permission page UI hotfix**. It makes it easier for admin/owner to classify and assign
permissions to users/roles by module by ensuring permissions are visually grouped per module on the role
permission assignment page.

The grouping infrastructure already exists
(`App\Modules\AccessControl\Services\PermissionGroupingService` + generic Blade in
`resources/views/settings/roles/_form.blade.php`). The gap fixed by Sprint 53 is that the **RME**
permissions were not in a dedicated module group and fell into **Other / Uncategorized**, which made RME
hard to classify alongside Inventory and Lab.

This sprint adds a dedicated **RME / Rekam Medis** group so that Inventory, Lab, and RME are clearly
separated.

## 5. Scope

- Add an `rme` module group to `PermissionGroupingService` referencing existing RME permission slugs.
- Add human-readable descriptions for the RME permissions.
- Documentation (`docs/sprint_53_permission_page_module_grouping_hotfix.md`).
- Sprint history update (`docs/sprint_history.md`).
- Targeted regression test
  (`tests/Feature/Sprint53/Sprint53PermissionPageModuleGroupingHotfixTest.php`).

No view or controller change is required — the permission page renders module groups generically from the
grouping service output.

## 6. Non-goals / forbidden actions

Sprint 53 is a permission page UI hotfix. Permissions are grouped by module for easier admin classification.
Inventory, Lab, and RME are required visible groups.

- No permission slug rename.
- No permission deletion.
- No authorization logic rewrite.
- Policies and middleware are not rewritten.
- Existing selected permissions remain checked.
- Existing form submission behavior is preserved.
- No production/VPS/server access.
- No production database/log/file access.
- No deployment.
- No production command execution.
- No production backup.
- No production restore.
- No rollback execution.
- No `.env` change.
- No dependency/package install.
- No migration/schema change.
- No financial logic rewrite.
- KTP / `ktp_number` remains hidden and is not part of this UI hotfix.
- WhatsApp remains manual-only and is not part of this sprint.
- Zero-remaining receivable rule remains preserved.
- Overpayment guard remains preserved.
- RME remains complete/closed for current planning.
- Pilot Health Check governance loop remains stopped.
- Inventory stock remains ledger-based and unrelated to this hotfix.

## 7. Current problem

The role create/edit permission page groups permissions per module, but the RME permissions
(`view_clinic_visits`, `manage_clinic_visits`, `manage_rme_billing`, `view_rme_patient_reports`,
`view_rme_payment_reports`) were not assigned to a dedicated group and therefore rendered under
**Other / Uncategorized**. Admins could not quickly identify and assign RME access by module, even though
Inventory and Lab already had clear module groups.

## 8. Permission grouping principle

- Grouping is **deterministic** and based on the **existing permission names/slugs** — no renaming.
- Grouping is performed in the service layer (`PermissionGroupingService::group()`), not in the
  authorization layer.
- Each defined group lists exact permission slugs. Any permission not matched by a defined group falls back
  to **Other / Uncategorized**.
- Grouping is presentation-only. It never changes which permissions exist, which permissions a role holds,
  or how the role permission form submits.

## 9. Module groups

Ordered module groups rendered on the permission page (a group is only shown when it has at least one
existing permission):

1. Dashboard
2. Access Control / Pengaturan
3. Master Data
4. **RME / Rekam Medis** (added in Sprint 53)
5. Lab Order
6. Production
7. QC
8. Delivery
9. Inventory / Persediaan
10. Finance
11. Reporting
12. HR (heuristic)
13. Other / Uncategorized (fallback)

Required visible separation for this hotfix:

- **Inventory** — `Inventory / Persediaan`.
- **Lab** — `Lab Order`.
- **RME** — `RME / Rekam Medis`.
- **Other / Uncategorized** — fallback for unmatched permissions.

## 10. Grouping rules

The new RME / Rekam Medis group contains exactly these existing slugs (no rename, no deletion):

- `view_clinic_visits`
- `manage_clinic_visits`
- `manage_rme_billing`
- `view_rme_patient_reports`
- `view_rme_payment_reports`

These slugs were previously uncategorized and are now classified under RME. The set of all grouped
permissions is unchanged — slugs only move from Other into RME.

Inventory and Lab grouping rules are unchanged from prior sprints. Production, QC, and Delivery remain
separate operational groups. Finance and Reporting remain separate. KTP / `ktp_number` is not a permission
and is not represented in the permission UI.

## 11. UI behavior

- The permission page renders one collapsible section per module group with a visible heading and a count.
- Each permission is a checkbox whose `name="permissions[]"` and `value` remain the original permission
  slug expected by the backend.
- A per-module "Pilih semua" (select-all) control and a permission search box continue to work generically
  across all groups, including the new RME group.
- No heavy/new JavaScript is introduced; the existing Alpine.js behavior covers the new group.

## 12. Selected permission preservation

The form pre-selects permissions from `old('permissions', $assignedPermissions)` and applies `@checked(...)`
per checkbox. Because the RME group reuses the same generic rendering path, RME permissions already held by
a role remain checked when editing, exactly like every other group.

## 13. Backward compatibility

- No permission slug renamed or deleted.
- No change to `PermissionSeeder`, migrations, or schema.
- No change to authorization (policies, middleware, gates) or to role permission sync behavior.
- Form submission payload and route behavior are unchanged.
- Existing AccessControl tests continue to pass, including the test asserting every seeded permission is
  grouped into a module bucket (the RME slugs simply move from Other to RME; the union is unchanged).

## 14. Test coverage

`tests/Feature/Sprint53/Sprint53PermissionPageModuleGroupingHotfixTest.php` covers:

- Documentation regression — this doc contains the Sprint 53 identity (title, branch, feature tag, future
  GO tag, base branch, Sprint 52 baseline), the UI-hotfix / grouped-by-module statements, the required
  Inventory/Lab/RME groups, the Other / Uncategorized fallback, and the full safety statements.
- Sprint history regression — `docs/sprint_history.md` contains the Sprint 53 summary.
- Grouping behavior —
  - `view_inventory` / `manage_inventory` group under Inventory.
  - Lab order permissions group under the Lab Order group.
  - RME permissions (`view_clinic_visits`, `manage_clinic_visits`, `manage_rme_billing`,
    `view_rme_patient_reports`, `view_rme_payment_reports`) group under RME.
  - `manage users` / `manage roles` / `manage permissions` group under Access Control.
  - An unknown permission falls back to Other / Uncategorized.
  - Grouping preserves original permission slugs and covers every seeded permission.

## 15. Safety confirmation

No production/VPS/server access. No production database/log/file access. No deployment. No production
command execution. No production backup/restore/rollback. No `.env` change. No dependency/package install.
No migration/schema change. No permission slug rename. No permission deletion. No authorization logic
rewrite. No policy/middleware rewrite. No financial logic rewrite. KTP / `ktp_number` remains hidden.
WhatsApp remains manual-only. Zero-remaining receivable rule preserved. Overpayment guard preserved. RME
remains complete/closed for current planning. Pilot Health Check governance loop remains stopped. Inventory
stock remains ledger-based and unrelated to this hotfix. No sensitive data, identifiers, secrets, tokens,
or credentials are exposed.

## 16. Validation commands

```bash
php artisan test --filter=Sprint53PermissionPageModuleGroupingHotfix
php artisan test tests/Feature/AccessControl
vendor/bin/pint --test
git diff --check
git status --short
```

## 17. AI agent memory summary

- Sprint 53 is a permission page UI grouping hotfix only.
- Permission grouping lives in `App\Modules\AccessControl\Services\PermissionGroupingService`; the role
  permission Blade (`resources/views/settings/roles/_form.blade.php`) renders groups generically.
- Sprint 53 added the `rme` group (`RME / Rekam Medis`) with the existing slugs `view_clinic_visits`,
  `manage_clinic_visits`, `manage_rme_billing`, `view_rme_patient_reports`, `view_rme_payment_reports`,
  which previously fell into Other / Uncategorized.
- No slugs renamed/deleted, no authorization/policy/middleware change, no migration/schema/seeder change,
  no deployment, no production access.
- Required visible groups: Inventory, Lab, RME, plus the Other / Uncategorized fallback.
