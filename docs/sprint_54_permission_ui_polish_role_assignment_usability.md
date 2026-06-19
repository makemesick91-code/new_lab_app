# Sprint 54 — Permission UI Polish & Role Assignment Usability

## 1. Title

Sprint 54 — Permission UI Polish & Role Assignment Usability

- Branch: `feature/sprint-54-permission-ui-polish-role-assignment-usability`
- Feature tag: `sprint-54-permission-ui-polish-role-assignment-usability`
- Future GO tag: `sprint-54-permission-ui-polish-role-assignment-usability-go`
- Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- Baseline: Sprint 53 GO / merge commit `6b7e977`
  (`sprint-53-permission-page-module-grouping-hotfix-go`)

## 2. Status

Local permission UI polish implementation / pending PR. Not pushed. No GO tag created.

Sprint 54 is a permission UI polish and role assignment usability sprint.

## 3. Baseline

Built directly on top of the Sprint 53 module grouping hotfix:

- Sprint 53 GO / merge commit `6b7e977`
  (`sprint-53-permission-page-module-grouping-hotfix-go`).
- Permission grouping is provided by
  `App\Modules\AccessControl\Services\PermissionGroupingService`, rendered by the shared role form
  partial `resources/views/settings/roles/_form.blade.php` (used by both create and edit).

## 4. Purpose

Improve grouped permission UI readability and role assignment usability while preserving permission slugs,
authorization behavior, selected-state behavior, and the Sprint 53 module grouping. This is a **UI usability
polish**, not a permission logic rewrite.

## 5. Scope

- Polish the grouped permission UI layout on the role create/edit form.
- Improve module heading clarity and add per-module helper descriptions.
- Add role assignment helper text / guidance copy above the permission groups.
- Show a per-module selected/total permission count for clarity.
- Add an Other / Uncategorized warning explanation.
- Preserve the selected permission checked state on edit.
- Preserve existing permission form submission behavior.
- Add Sprint 54 documentation and update the sprint history.
- Add targeted regression tests.
- Run safe local validation.

The only non-view code change is an **additive `description` field** per module group on
`PermissionGroupingService` (and a constant for the Other bucket). No grouping order, slug, or membership
changes.

## 6. Non-goals / forbidden actions

- No permission slug rename.
- No permission deletion.
- No authorization logic rewrite.
- Policies and middleware are not rewritten.
- No seeder rewrite (`PermissionSeeder` unchanged).
- No deployment.
- No production/VPS/server access during Sprint 54.
- No production database/log/file access.
- No production command execution.
- No production backup.
- No production restore.
- No rollback execution.
- No `.env` change.
- No dependency/package install.
- No migration/schema change.
- No financial logic rewrite.
- No RME workflow work.
- No Inventory stock workflow work.
- No Pilot Health Check governance continuation.

## 7. Sprint 53 carry-forward

- Permission grouping from Sprint 53 is preserved.
- Inventory, Lab, and RME groups remain visible.
- Other / Uncategorized fallback remains available.
- The dedicated **RME / Rekam Medis** group (`view_clinic_visits`, `manage_clinic_visits`,
  `manage_rme_billing`, `view_rme_patient_reports`, `view_rme_payment_reports`) remains intact and separate
  from Inventory and Lab.

## 8. Current usability problem

After Sprint 53 the permission page is grouped per module, but the grouped UI still reads as a flat list of
slugs: module sections lack short descriptions explaining what each module covers, there is no top-level
guidance on how to assign role permissions, and admins cannot see at a glance how many permissions in a
module are currently selected when editing a role. This makes role assignment slower and more error-prone
for admins/operators.

## 9. UI polish principle

- Clear module headings.
- Readable module descriptions / helper text.
- Better spacing between permission groups.
- Consistent checkbox rows.
- Visible selected permissions on edit.
- Other / Uncategorized fallback explanation.
- Role assignment guidance.
- No permission slug change.
- No backend form input name/value change.
- Avoid heavy JavaScript and avoid dependency changes.

## 10. Role assignment usability improvements

Role assignment helper text added above the permission groups so admins/operators understand the workflow:

- A "Permission Role" section heading and short guidance copy above the permission groups:
  - "Kelompok permission berdasarkan modul agar admin lebih mudah memilih akses role."
  - "Centang permission yang ingin diberikan pada role ini."
  - "Permission yang sudah aktif akan tetap tercentang saat edit role."
  - "Other / Uncategorized berisi permission yang belum masuk klasifikasi modul utama."
- Each module group renders a short description under its heading.
- Each module group shows a "selected / total permission dipilih" count, computed server-side from the
  already-available selected permissions (no new authorization data, no extra query).
- The Other / Uncategorized group renders a review warning before its permissions.
- Checkbox input `name` (`permissions[]`) and `value` (original slug) are unchanged.
- Validation error rendering, Save/Cancel behavior, and submission semantics are unchanged.

## 11. Permission group descriptions

Descriptions are stored as an additive `description` field on each group definition in
`PermissionGroupingService` and surfaced by the role form. Representative copy:

- Inventory / Persediaan: Akses pengelolaan produk, supplier, lokasi, stok, dan pergerakan persediaan.
- Lab Order: Akses workflow order lab, produksi, QC, delivery, dan POD.
- RME / Rekam Medis: Akses workflow kunjungan klinik, rekam medis, billing RME, dan laporan RME.
- Access Control / Pengaturan: Akses pengelolaan user, role, dan permission.
- Finance: Akses pembayaran, invoice, kasir, piutang, dan laporan keuangan.
- Reporting: Akses dashboard laporan, report, export, dan print.
- Other / Uncategorized: Permission yang belum masuk klasifikasi modul utama. Review sebelum diberikan ke role.

## 12. Selected permission state preservation

Selected permissions remain checked. The form continues to read the selected set from
`old('permissions', $assignedPermissions)`, and each checkbox keeps `@checked(in_array(...))` against the
original slug value. The new per-module count is derived from the same selected set and does not alter
which boxes are checked.

## 13. Backward compatibility

Existing form submission behavior is preserved. The controller data contract (`permissionGroups`,
`assignedPermissions`) is unchanged aside from the additive `description` key on each group. Route names
(`settings.roles.create`, `settings.roles.edit`, `settings.roles.store`, `settings.roles.update`) are
unchanged. Permission slugs are not renamed. Permissions are not deleted. Authorization logic is not
rewritten.

## 14. VPS update boundary

VPS update is not executed in this sprint. No production/VPS/server access during Sprint 54 local
implementation. No deployment during Sprint 54 local implementation. VPS update must be performed only
after Sprint 54 is merged and GO tagged through a separate supervised deployment workflow that deploys the
latest base branch and verifies the permission UI on the pilot VPS.

## 15. Test coverage

`tests/Feature/Sprint54/Sprint54PermissionUiPolishRoleAssignmentUsabilityTest.php`:

- Documentation regression: asserts this doc and the sprint history contain the Sprint 54 identity,
  preservation guarantees, and safety statements.
- Grouping behavior: asserts each group exposes a non-empty `description`, RME/Inventory/Lab groups remain
  present and separate, the Other fallback still catches unknown permissions, and original slugs are
  preserved.
- Rendered UI: asserts the role create page renders the "Permission Role" heading, helper copy, and module
  descriptions; asserts an assigned permission remains checked on the edit page.

## 16. Safety confirmation

- Sprint 54 is a permission UI polish and role assignment usability sprint.
- Permission grouping from Sprint 53 is preserved.
- Inventory, Lab, and RME groups remain visible.
- Other / Uncategorized fallback remains available.
- Permission slugs are not renamed.
- Permissions are not deleted.
- Authorization logic is not rewritten.
- Policies and middleware are not rewritten.
- Selected permissions remain checked.
- Existing form submission behavior is preserved.
- No production/VPS/server access during Sprint 54 local implementation.
- No deployment during Sprint 54 local implementation.
- VPS update must be performed only after Sprint 54 is merged and GO tagged through a separate supervised
  deployment workflow.
- No production database/log/file access.
- No production command execution.
- No production backup.
- No production restore.
- No rollback execution.
- No `.env` change.
- No dependency/package install.
- No migration/schema change.
- No financial logic rewrite.
- KTP / `ktp_number` remains hidden and is not part of this UI polish.
- WhatsApp remains manual-only and is not part of this sprint.
- Zero-remaining receivable rule remains preserved.
- Overpayment guard remains preserved.
- RME remains complete/closed for current planning.
- Pilot Health Check governance loop remains stopped.
- Inventory stock remains ledger-based and unrelated to this sprint.

## 17. Validation commands

```bash
php artisan test --filter=Sprint54PermissionUiPolishRoleAssignmentUsability
php artisan test tests/Feature/AccessControl
vendor/bin/pint --test
git diff --check
git status --short
```

## 18. AI agent memory summary

Sprint 54 polished the grouped role permission assignment UI for usability: added per-module descriptions
(additive `description` field on `PermissionGroupingService`), a "Permission Role" heading with guidance
copy, a per-module selected/total count, and an Other / Uncategorized review warning — all in
`resources/views/settings/roles/_form.blade.php`. Sprint 53 module grouping (Inventory, Lab, RME, Other
fallback) is preserved. No slug rename, no permission deletion, no authorization/policy/middleware rewrite,
no schema/seeder/dependency/.env change. Selected checkbox state and existing form submission behavior are
preserved. No deployment and no VPS/production access during the local sprint; VPS update is a separate
supervised post-merge workflow after merge and GO tag.
