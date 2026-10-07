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

**Status: GO** — tag `feature-patient-duplicate-resolution-merge-1-go` → `df9bb48a` (PR #460, squash). Merge tree `6f075915` is identical to the CI-tested candidate `504ac147`.

| Gate | Result |
|---|---|
| PatientMerge suite | 74 passed — SQLite and PostgreSQL 16.15 (incl. PG-only concurrency) |
| Local regression | 3312 tests across Patient, RME, Legacy*, AccessControl, Auth, ClinicVisit, MedicalRecord, PatientMerge; the only 4 failures were the Front Office permission-count pins (23 → 25), repinned with the reason; `FrontOffice\|Permission\|SupervisorRme\|Sidebar\|DoctorDeviceWebAuthn` reran green (781) |
| CI run `37576227912` | all required gates green; NSF-R011 Critical **5216 passed / 0 failed**; Selective Module, NSF-9, NSF-10, Quality, Android gate green. Full Suite **skipped** by standing policy (not a pass) |
| Governance | pint, `git diff --check`, `view:cache`, `sprint:manifest-check`, `sprint:scope-audit`, `foundation:devflow-check --strict`, `foundation:shared-service-audit --strict`, ci-runtime-control, security-compliance, ui-governance, roadmap — all GO |

**Production (`srv1730088`, `/var/www/asia-dental-lab-v2`, 2026-10-07 UTC):**

- Pre-deploy backup `auto_backup_20261007-072856.sql` (31.6 MB, backup-verify 9/9 GO). It was written `0644`; tightened to `0640` immediately (the deploy also re-hardens backups). **Follow-up:** `scripts/backup-vps.sh` itself should create dumps `0640`.
- `scripts/deploy-vps-runner.sh start` run ON the VPS: `exit=0`, `DEPLOY OK: 20261007-072916`, `DEPLOY_HEAD_TARGET_MATCH=YES`, snapshot cleaned; HEAD/tree == merge; tracked tree clean.
- Migrations `2026_10_07_100001` and `2026_10_07_100002` ran (batch 79).
- `PermissionSeeder` → `RoleSeeder` → `permission:cache-reset` as `daengtisiams`. Verified matrix: view + request → Admin Klinik, Front Office, Supervisor RME, Super Admin; approve → Supervisor RME, Super Admin.
- Smoke over `https://daengtisia.online`: `/login` + `/health/{live,ready,lb}` 200; all seven Duplikasi Pasien pages 302 (auth); non-UUID case id 404; `/storage/*` 403; 18 `patient-merge` routes registered.
- Read-only DB check: 0 merge cases, 0 aliases, 0 merged patients, 1519 patients — **no real patient was merged**.
- `laravel.log` byte-identical across the deploy (1434927 bytes) → 0 new errors; no failed jobs; php8.3-fpm / nginx / queue worker active; env pilot, debug OFF, maintenance OFF; Legacy RME rollout readiness GO.

**Not exercised in production (by design):** an end-to-end merge on real data. Merge behaviour is proven by the automated suites on SQLite and PostgreSQL 16; a first production merge should be a genuine, owner-confirmed duplicate pair.
