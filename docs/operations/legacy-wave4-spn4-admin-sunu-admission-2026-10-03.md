# OPS — Admin Sunu admitted as a WAVE-4 migration operator for SPN4 (2026-10-03)

**Operational data assignment. No code change, no migration, no permission change, no
deploy, no new GO tag.** Production runtime stays at `db96df67` /
`bugfix-mass-upload-request-entity-too-large-1-go`.

## Authority

| Fact | Value |
|---|---|
| Canonical branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Canonical HEAD (origin) | `7da0178b8ba60eaf079521171ba9497d3a6f3962` |
| Production HEAD | `db96df67c3b1081192ea67f59ec27041288f98a7` |
| Production GO tag | `bugfix-mass-upload-request-entity-too-large-1-go` (exact match) |
| Environment | `pilot`, `APP_DEBUG=false`, maintenance OFF |

## The denial, measured

The owner's Mass Upload of legacy RME for Cabang Sunu was refused. This was not
inferred from a screenshot — it is recorded in production:

- `stg_legacy_mass_upload_batches` id 4, uuid `0dc11172-…`, `import_type=legacy_rme`,
  `origin_branch_id=5`, `created_by=29`, `COMPLETED_WITH_BLOCKED_ITEMS`
- `stg_legacy_mass_upload_items`: **8 rows, `BLOCKED`, `DOMAIN_REFUSED`**,
  `reason_message` = *"Anda belum ditugaskan untuk memigrasikan cabang SPN4 pada
  gelombang WAVE-4."*
- `sys_audit_logs` 1098 — `MASS_UPLOAD_COMPLETED`, `blocked_items: 8`,
  `dispatched_items: 0`, `performed_by: 29`

| Field | Value |
|---|---|
| Source | `app/Modules/LegacyRme/Services/LegacyRmeOperationsGateService.php:145` |
| Guard | `LegacyRmeOperationsGateService::decide()` → `operatorAssigned()` |
| Reason code | `CODE_OPERATOR_NOT_ASSIGNED` (surfaced to mass upload as `DOMAIN_REFUSED`) |
| Governed by | DB table `ops_rme_legacy_wave_operators` (not config, not a role) |
| Wave | `WAVE-4` (id 5), `ACTIVE`, approval `ROLL-4-WAVE-4-OWNER-APPROVAL-2026-08-28` |
| Branch | `SPN4` = `mst_branches` id 5, Cabang Sunu, active, RME-enabled |
| Actor | Admin Sunu = `users` id 29, `adminsunu@daengtisia.com`, role `Front Office` |

## Root cause — classification B

**SPN4 was admitted; Admin Sunu was not assigned.** The other two layers were
already correct, which is why the denial named the operator and not the branch:

1. **RBAC — already correct.** Role `Front Office` grants
   `create_legacy_rme_imports`, `create_legacy_odontogram_imports`,
   `view_legacy_*_imports`, `verify_legacy_dates_at_ingestion`. 0 direct
   permissions. No review/publish/VOID. Admin Sunu does **not** hold
   `manage_legacy_rme_migration_operations`, so the wave-governor self-assignment
   exemption correctly did not apply.
2. **Branch pin — already correct.** `FRONT_OFFICE_BRANCH_DEVICE_LOCK_COHORT=29:SPN4`
   with `FEATURE_FRONT_OFFICE_BRANCH_CONTEXT_LOCK=true`, so
   `FrontOfficeBranchPinResolver` pins user 29 to branch 5 **first and only**,
   ahead of the online context and `users.branch_id`.
3. **Wave operator assignment — the gap.** 0 rows in
   `ops_rme_legacy_wave_operators` for `(wave 5, user 29)`.

ROLL-3 branch admission, the approval binding, wave state, branch state, capacity
and quota were all already clear.

## Backup

`storage/app/backups/deploy/pre_wave4_spn4_admission_20261003-145500.sql`
(17,595 bytes) — scoped `pg_dump` of `ops_rme_legacy_migration_waves`,
`ops_rme_legacy_wave_branches`, `ops_rme_legacy_wave_operators`.

## The change

Canonical mechanism only — the same `assignOperator()` the UI calls, dry-run first:

```
php artisan legacy-rme:wave-admin assign --wave=WAVE-4 --branch=SPN4 \
    --operator=29 --actor=1 --apply --json
```

`--actor=1` (IT Support) is the sole holder of
`manage_legacy_rme_migration_operations`; the CLI checks the actor through the
same policy as the browser.

Exactly one row written — `ops_rme_legacy_wave_operators` id 8:
`wave_id=5, user_id=29, branch_id=5, branch_code=SPN4, assigned_by=1,
assigned_at=2026-10-03 14:58:28, revoked_at=NULL`. Total rows 7 → 8.
Audited as `sys_audit_logs` 1099 `LEGACY_RME_WAVE_OPERATOR_ASSIGNED`.

Nothing else changed: no permission, no role, no wave/branch state, no quota, no
approval, no other operator's assignment, no code, no config.

## After — fail-closed matrix (user 29, WAVE-4)

| Branch | Operator gate |
|---|---|
| SPN4 | **ALLOWED** |
| TLK1 | DENIED — no active assignment |
| LDK2 | DENIED — no active assignment |
| ATG3 | DENIED — no active assignment |
| MAIN | DENIED — no active assignment (and never enrolled; non-RME) |

Cross-branch is denied **twice over**, independently: by the missing operator row
*and* by `LegacyRmeWorkspaceScope`, which pins user 29 to `[5]`. The archive branch
is derived from the patient's RM (`DG-KODECABANG-TAHUN-NOMOR`) and never from the
manifest or a request field, so a crafted `branch_id` cannot reach either gate.

## What is NOT changed

`publish`, `review`, VOID, separation of duties, cross-branch patient access,
single-active-document-per-patient-per-type, the human date verification, the
native-RME boundary, server-side patient resolution, the hard RM/patient mismatch
block, and the no-daily-business-quota decision all stand. Admission means only
*"this operator may attempt migration"* — never *"this document is eligible"*.

## Open governance item — the owner must decide

`legacy-rme:ops-readiness` on production: **Decision WATCH, "Ready for a routine
batch: NO"**. SPN4 itself is `READY` with no blockers, but:

- `batch_window` **WATCH** — WAVE-4's planned window was 2026-08-28 → **2026-09-30**
  and today is 2026-10-03. Remediation as printed: *"Close the batch out, or record
  a fresh approval extending it. Do not keep migrating against a lapsed window."*
- `batch_size_policy` WATCH — pre-existing and previously accepted by the owner (no
  wave-level daily quota, which is what preserves 100 **per branch**).

A lapsed window is deliberately **not** a write refusal
(`LegacyRmeBatchWindowRule`: *"It does not compare the window against today… a
lapsed window is a readiness finding, not a reason to refuse the write"*), so the
upload will now proceed. Extending or closing the window is an owner approval and
was **not** performed here. There is no post-registration setter for the wave-level
quota — cancel plus re-register under a new code is the only route.

## Expected next result

The operator message is gone. A retry may still be legitimately refused per item by
`ALREADY_PUBLISHED`, `ACTIVE_IMPORT_EXISTS`, a date rule, `PATIENT_NOT_FOUND`, or a
branch/patient mismatch. Those are the gates working. Batch 4 is terminal; a retry
means a fresh package.

## Verification

- 32/32 `LegacyRmeMigrationOperationsGateTest` (incl. assigned-clears,
  no-assignment-refused, confined-to-assigned-branch, revoked-refused,
  unauthenticated-refused)
- 1065 passed / 12 skipped / 0 failed across `tests/Feature/LegacyRme` +
  `tests/Feature/LegacyMassUpload`
- Production: `/health/live` 200, `/health/ready` 200, `/login` 200; no Laravel log
  file for today (zero new errors); `operations_layer_enforced`,
  `separation_of_duties`, `admission_approval`, `batch_binding` all GO
