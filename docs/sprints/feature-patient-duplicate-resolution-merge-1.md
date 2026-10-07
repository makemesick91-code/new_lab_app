# FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — Duplikasi Pasien

**Goal:** 1 manusia → 1 canonical patient → 1 RM aktif → seluruh histori klinis tetap utuh. No silent data loss.

Durable contract: `docs/architecture/patient-duplicate-resolution-merge.md` · rules: `.cursor/rules/172-patient-duplicate-resolution-merge.mdc` (cross-referenced from rules 101 and 170).

## Delivered

| Capability | Where |
|---|---|
| Sidebar **Duplikasi Pasien** (Dashboard, Deteksi Duplikat, Pilih Pasien Manual, Pengajuan Merge, Review & Approval, Riwayat Merge, RM Alias) | `layouts/partials/sidebar.blade.php`, permission-gated per entry |
| Candidate detection (bounded blocks on DOB / phone / WhatsApp, never auto-merge) | `PatientDuplicateDetectionService`, `PatientDuplicateCandidateRepository` |
| Manual patient selection (two server-side searches, branch-scoped, merged excluded, old RM → canonical) | `PatientMergeSelectionService`, `patient-merge/manual` |
| Field-level identity reconciliation with structured provenance (A / B / manual+reason / matched / empty) | `PatientMergeCaseService::resolve`, `trx_patient_merge_field_resolutions` |
| Canonical patient chosen separately from final identity values | `canonical_patient_id` on the case |
| Blockers: unresolved critical conflict, Patient C NIK collision, legacy slot conflict, in-flight legacy import, live encounter, SATUSEHAT identifier on the source, legacy date rule on the combined timeline, merged/deleted/same patient, open case, identity changed after submission, unregistered patient FK in the live schema | `PatientMergeBlockerService` |
| Maker-checker approve & merge in ONE atomic transaction with row locks + conservation proof | `PatientMergeExecutionService` |
| RM alias (old Nomor RM kept & searchable; follows chain merges; revoked not deleted on reversal) | `mst_patient_rm_aliases`, `PatientRmAliasService`, New Visit selector |
| Merged source = read-only pointer, no new activity (service-level guard) | `PatientMergeGuard` in ClinicVisitService (FOR SHARE), PatientService, legacy RME/odontogram intake; edit + RME workspace redirects |
| Merge Reversal Review (safe only when provable, different executor; else supervised) | `PatientMergeReversalService` |
| Registration duplicate prevention (strong matches only; reason required to continue; audited) | `PatientRegistrationDuplicateCheck` in StorePatientRequest + StoreClinicVisitRequest |
| Audit trail (sys_audit_logs, entity `patient_merge_case`; NIK never written) | AuditLogService |

## Database (additive)

- `2026_10_07_100001_create_patient_merge_tables` — `trx_patient_merge_cases`, `trx_patient_merge_field_resolutions`, `mst_patient_rm_aliases` (+ indexes, UNIQUE alias number, UNIQUE case/field).
- `2026_10_07_100002_add_merge_state_to_mst_patients_table` — nullable `merged_into_patient_id`, `merged_at`, `merged_by`, `merge_case_id` + indexes on `merged_into_patient_id`, `date_of_birth`, `phone`. No backfill; no column altered or dropped; `down()` reverses cleanly.

## Permissions

`view_patient_duplicate_resolution`, `request_patient_merge` (Admin Klinik, Front Office, Supervisor RME); `approve_patient_merge` (Supervisor RME). Super Admin via `Gate::before`. Owner / Doctor / Kasir / Perawat / Admin Lab: none. Deploy requires `PermissionSeeder` → `RoleSeeder` → `permission:cache-reset`.

## Findings during the sprint (fixed)

- **PostgreSQL-only defects caught by running the suite on PG 16.15** (SQLite was green): a non-UUID `{patientMergeCase}` reached PostgreSQL as a uuid comparison and produced a 500 → routes now `whereUuid`; candidate detection compared a `date` column to `''`, which PostgreSQL rejects → the empty-string guard applies to text columns only.
- **Reversal false positive:** rows the merge itself moved, created in the same second as the merge, were counted as "new activity" → activity now excludes manifest ids.
- **N+1 avoided:** open-case lookup for candidate pairs is one query, and block members are fetched in one query per key.

## Review hardening

A security review and an adversarial data-integrity review ran on the implementation before merge. **CRITICAL: none.** Every HIGH and MEDIUM finding was fixed and is pinned by `PatientMergeHardeningTest` (16 tests) — see the table in the contract doc §10. The most consequential: a reviewer could rewrite another's draft and then approve it (maker-checker now covers every author); a concurrent legacy upload could break the single-active-document rule (merge takes the slot locks first); writers that read `patient_id` from a route-bound visit could write onto the source after the merge (live-encounter blocker); and a reversal could be assessed SAFE while post-merge work existed (registry-driven assessment with an id high-water mark and a parent-reference probe).

## Evidence

Filled in at closure (tests, CI run, deploy, production verification).
