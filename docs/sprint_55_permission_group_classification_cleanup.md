# Sprint 55 — Permission Group Classification Cleanup

## 1. Title

Sprint 55 — Permission Group Classification Cleanup

## 2. Status

Local permission grouping cleanup — pending PR. Not pushed, not merged, not deployed.

- Branch: `feature/sprint-55-permission-group-classification-cleanup`
- Feature tag: `sprint-55-permission-group-classification-cleanup`
- Future GO tag (after PR merge only): `sprint-55-permission-group-classification-cleanup-go`

## 3. Baseline

- Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- Baseline: Sprint 54 GO / merge commit `0501e77`
- GO tag at baseline HEAD: `sprint-54-permission-ui-polish-role-assignment-usability-go`

## 4. Purpose

Clean up **permission group classification only** so operational permissions no longer
appear unnecessarily under **Other / Uncategorized** on the role permission assignment
page. This is a UI classification/display improvement — it touches grouping logic in
`PermissionGroupingService` and nothing else.

This is a **permission group classification cleanup**.

## 5. Scope

- Inspect the Sprint 53/54 permission grouping service and role form UI.
- Move known operational permissions out of **Other / Uncategorized** into clearer module groups.
- Add Sprint 55 documentation and sprint history entry.
- Add targeted regression tests.
- Run safe local validation only.

## 6. Non-goals / forbidden actions

- Role permission assignments are not changed.
- Permission slugs are not renamed.
- Permissions are not deleted.
- Authorization logic is not rewritten.
- Policies and middleware are not rewritten.
- Policies/middleware are not rewritten.
- No production/VPS/server access during local implementation.
- No deployment.
- No production database/log/file access.
- No production command execution.
- No `.env` change.
- No dependency/package install.
- No migration/schema change.

## 7. Sprint 53–54 carry-forward

- Sprint 53 introduced module grouping on the permission page (dedicated RME group).
- Sprint 54 polished the permission role assignment UI (per-group descriptions, usability).
- Sprint 54 UI polish is preserved. Sprint 53 module grouping is preserved.
- Other / Uncategorized fallback remains available for truly unknown slugs.

## 8. VPS audit finding

A VPS role permission audit confirmed that the only outstanding issue is **UI
classification**: several operational permissions (delivery, technician/assignment,
quality control, purchase request, branch master data, master data) appeared under
**Other / Uncategorized** even though they clearly belong to operational modules. No
authorization defect was found — actual role access is business-valid.

## 9. Business approval note for Doctor, Kasir, Perawat

Confirmed business-valid by the VPS role permission audit:

- Doctor `manage_clinic_visits` business-approved and unchanged.
- Kasir `manage_rme_billing` business-approved and unchanged.
- Perawat `manage_clinic_visits` business-approved and unchanged.

## 10. Current classification problem

Before Sprint 55, several operational permissions were not assigned to a dedicated
module group and therefore fell into **Other / Uncategorized**, or were grouped under a
label that did not clearly describe the module:

- Branch master data permissions (`view_branch_master_data`, `manage_branch_master_data`)
  fell into Other / Uncategorized.
- Delivery, technician/assignment, quality control, and purchase request permissions
  lacked clear, dedicated module labels.

## 11. New classification map

| Permission slug (unchanged)   | New group label           |
| ----------------------------- | ------------------------- |
| `assign_courier`              | Delivery / Pengiriman     |
| `manage deliveries`           | Delivery / Pengiriman     |
| `mark_delivered`              | Delivery / Pengiriman     |
| `assign_technicians`          | Technician / Assignment   |
| `reassign_technicians`        | Technician / Assignment   |
| `manage technicians`          | Technician / Assignment   |
| `manage assignments`          | Technician / Assignment   |
| `manage_quality_control`      | Quality Control           |
| `view_quality_control`        | Quality Control           |
| `request_remake`              | Quality Control           |
| `manage_purchase_request`     | Purchase / Procurement    |
| `view_purchase_request`       | Purchase / Procurement    |
| `manage_branch_master_data`   | Branch Master Data        |
| `view_branch_master_data`     | Branch Master Data        |
| `manage master data`          | Master Data               |

Group descriptions:

- Delivery / Pengiriman — Akses pengaturan kurir, proses pengiriman, POD, dan status delivery.
- Technician / Assignment — Akses penugasan teknisi, assignment pekerjaan, dan pengelolaan teknisi.
- Quality Control — Akses pemeriksaan kualitas, remake request, dan kontrol hasil produksi.
- Purchase / Procurement — Akses permintaan pembelian, review procurement, dan alur pengadaan.
- Branch Master Data — Akses master data cabang dan konfigurasi operasional cabang.
- Master Data — Akses pengelolaan master data umum.

Preserved groups (unchanged):

- `view_inventory` remains Inventory / Persediaan.
- `view_lab_orders` remains Lab Order.
- `view_rme_patient_reports` remains RME / Rekam Medis.

## 12. Backward compatibility

- Only classification/grouping display logic changed.
- Permission slugs are preserved exactly as seeded.
- Other / Uncategorized fallback remains available for unknown/future slugs.
- Existing selected permissions remain checked; role form submission behavior is preserved.

## 13. Role assignment preservation

Role permission assignments are not changed. No seeded permission records changed. No
role → permission grant or revoke was performed. The cleanup only affects how
permissions are displayed/grouped in the role assignment UI.

## 14. Safety confirmation

- Sprint 55 only cleans permission grouping/classification.
- Doctor `manage_clinic_visits` is business-approved and unchanged.
- Kasir `manage_rme_billing` is business-approved and unchanged.
- Perawat `manage_clinic_visits` is business-approved and unchanged.
- Role permission assignments are not changed.
- Permission slugs are not renamed.
- Permissions are not deleted.
- Authorization logic is not rewritten.
- Policies and middleware are not rewritten.
- Sprint 54 UI polish is preserved.
- Other / Uncategorized fallback remains available.
- No production/VPS/server access during local implementation.
- No deployment during local implementation.
- No production database/log/file access.
- No production command execution.
- No `.env` change.
- No dependency/package install.
- No migration/schema change.
- KTP / `ktp_number` remains hidden (KTP hidden).
- WhatsApp remains manual-only (WhatsApp manual-only).
- Zero-remaining receivable rule remains preserved (zero-remaining receivable preserved).
- Overpayment guard remains preserved (overpayment guard preserved).
- RME remains complete/closed.
- Pilot Health Check governance loop remains stopped.
- Inventory stock remains ledger-based and unrelated.

## 15. Validation commands

```bash
php artisan test --filter=Sprint55PermissionGroupClassificationCleanup
php artisan test --filter=Sprint54PermissionUiPolishRoleAssignmentUsability
php artisan test tests/Feature/AccessControl
vendor/bin/pint --test
git diff --check
git status --short
```

## 16. AI agent memory summary

- Sprint 55 = permission group classification cleanup only (UI grouping/display).
- Baseline: Sprint 54 GO / `0501e77`.
- Only `app/Modules/AccessControl/Services/PermissionGroupingService.php` grouping logic changed (plus docs/tests).
- New groups: Technician / Assignment, Purchase / Procurement, Branch Master Data; relabeled Quality Control, Delivery / Pengiriman.
- Doctor/Kasir/Perawat flagged permissions are business-approved and unchanged.
- No slug rename, no permission deletion, no role assignment change, no authorization/policy/middleware rewrite, no migration, no deployment, no VPS access.
- Other / Uncategorized fallback preserved.
