# SECURITY-PATIENT-KTP-ARCHIVE-BRANCH-ISOLATION-1

Branch `security/patient-ktp-archive-branch-isolation-1`, base
`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
@ `6c87d597` (production runtime `bbf200d0`, tag
`audit-patient-ktp-archive-persistence-1-go`, verified exact on the VPS before
work began). Do NOT target main. Rule `.cursor/rules/183-patient-ktp-archive-branch-isolation.mdc`;
runbook `docs/runbooks/patient-ktp-archive-access-runbook.md`.

## Problem (measured, not assumed)

`PatientDocumentController::show()` authorized with `PatientPolicy::view`,
which is `$user->can('manage patients')` and nothing else. Any front-office
account at any branch could open any patient's stored KTP image by id. Rule 182
§10 had recorded this honestly as an open owner decision; the owner has now
decided (branch-scoped, Super Admin cross-branch, Supervisor RME only with an
explicit grant).

## Read-only production baseline (2026-10-11, aggregates only)

| Fact | Value |
|---|---|
| PostgreSQL | 16.15 |
| Live KTP documents | **1** (non-legacy patient, SPN4) |
| …uploaded by | Front Office, via the visit flow, within 2 min of the patient row |
| …patient visits | only at SPN4 |
| Patients | 1522 live (1519 legacy import, 0 without branch) |
| Legacy patients with a KTP document | 0 |
| `manage patients` holders | Front Office 8, Perawat 1, Supervisor RME 1, Super Admin 1 (0 direct grants) |
| KTP/document-specific permission | none existed |

## Access paths audited

Exactly one file endpoint serves a stored card: `GET settings/patients/{patient}/documents/{document}`
(inline; "preview" and "download" are the same response), plus
`DELETE` on the same URI. The only UI reference is the "Dokumen KTP Tersimpan"
panel on the patient edit page. Temp uploads have no read endpoint. Graphify
shows no other consumer of `PatientDocument`. No API returns document
metadata. The PatientMerge preview shows a COUNT of owned documents
(governance surface, no link) — recorded, not changed.

## Why a schema change was required

`mst_patient_documents` had no provenance column. The obvious substitutes are
both unsafe: `UpdatePatientRequest` accepts `branch_id` AND
`medical_record_number`, and patient master data is unscoped, so an operator at
branch B could re-home a patient to B and then read a card captured at A. A
merge also re-points `patient_id` at a survivor that may belong to another
branch. Provenance therefore lives on the document: one additive nullable
column `captured_branch_id` (FK `mst_branches`, nullOnDelete, indexed),
written by the server at archive time, write-once (the model refuses a rewrite).

## What changed

- `PatientDocumentAccessService::decide()` — the single decision (rule 183).
  `authorize()` decides, audits, and throws a 404 on refusal.
- `PatientDocumentPolicy` (view/delete) — thin adapter for `@can`; never audits.
- `PatientDocumentController::show()/destroy()` — `manage patients` + ownership
  as before, then the audited per-document decision.
- `PatientDocumentCaptureBranchResolver` — visit flow → visit branch; Master
  Data → the context-bound operator's working branch, else the patient's
  validated branch for a governance account. Both registration controllers pass it.
- `KtpScanService::attachTempToPatient(..., ?int $capturedBranchId = null)` —
  NULL means unknown provenance (fail closed), so a caller that forgets it can
  only under-share.
- Permission `view_cross_branch_patient_ktp_documents` (PermissionSeeder,
  master-data group) granted to **no role**; Super Admin holds it via `'*'`.
- `rbac:patient-ktp-cross-branch-access grant|revoke|status` — governed
  per-account assignment (dry run by default, Super Admin actor, Supervisor RME
  target only, reason required, audited by length). There is no UI that assigns
  a permission to ONE account, so without this the only path would be a
  production REPL. Only a DIRECT grant counts; a role-level grant is ignored.
  Not run on production: a grant is an owner decision.
- `patient-documents:backfill-capture-branch` — dry run by default; `--apply`
  requires a named Super Admin `--actor`; records a capture branch only for a
  registration-time card whose server-pinned registration visit, patient branch
  and Nomor RM branch all agree; aggregate output only.
- Named limiter `patient-document` (30/min/account/route) on both routes.
- Edit page panel wrapped in `@can('view', $ktpDocument)`.
- CI: suite selected by the existing `PatientKtp` critical token and declared in
  `config/ci_runner.php` `critical_gate_mandatory_suites`.

## Independent security review (adversarial, read-only)

No CRITICAL. Findings and dispositions:

| # | Sev | Finding | Disposition |
|---|---|---|---|
| 1 | HIGH | A Perawat (no daily branch lock, by design) could re-select its working branch at will and read each branch's cards, audited as `same_branch`. | **Fixed.** Access now also requires the working branch to equal the operator's daily-committed branch (`DailyBranchContextService::lockedBranchIdFor`); Perawat is refused (`uncommitted_working_branch`). Test pins the A→B→A hop. |
| 2 | MEDIUM | Backfill evidence E1/E2 (patient branch, RM) is editable through the unscoped patient form; a master-data card had no immutable anchor. | **Fixed.** Backfill requires a server-pinned registration visit at the same branch; master-data cards stay unknown. `--apply` requires a named Super Admin `--actor`, recorded on each audit row. |
| 3 | LOW (pre-existing) | `local` disk has `serve => true`; a future `temporaryUrl()` on a KTP path would bypass the decision. | Recorded (rule 183 Known limits). No caller exists; nginx denies `/storage/`. Structural fix = dedicated disk + file move (separate sprint). |
| 4 | LOW | Write-once is an Eloquent `updating` guard; query-builder writes bypass it. | Recorded. Verified no production path writes the column that way. |
| 5 | LOW | A role-level grant via the role editor would be honoured, invisible to the command, and unrevokable by it. | **Fixed.** Only Super Admin or a DIRECT grant counts; a role-level grant is ignored and `status` reports `role_level_grant_ignored`. |
| 6 | LOW | "Viewed" was audited before the file-exists check. | **Fixed.** Recorded only after the file is found. |

Claims the reviewer tried and failed to refute: no other surface serves the
card; no existence oracle; no MAIN/first-branch fallback; no request input
reaches the decision; provenance is server-written; merge leaves it alone;
dry run writes nothing; audit privacy; Supervisor RME/Kasir excluded; grant
command checks; headers; limiter key; panel gating; Super Admin consistency.

## PostgreSQL parity (found a real defect)

The suite ran green on SQLite, then **failed once on PostgreSQL 16.15** (the
production engine, PHP 8.3 container): `GET /settings/patients/{Nomor RM}/documents/{id}`
made route-model binding run `where "id" = 'DG-SPN4-…'`, which PostgreSQL
rejects (bigint cast) → **HTTP 500, with the medical record number written into
the error log**. SQLite coerces and answered 404, so the defect was invisible
locally. Fixed by `->whereNumber(['patient', 'document'])` on both document
routes; final PostgreSQL run: **101 passed / 0 failed** across the five KTP
suites, and the migration's FK + index verified after rollback + re-migrate.
(The same binding shape exists on other `{patient}` routes — pre-existing, out
of scope, recorded.)

## Decisions recorded

- **Supervisor RME** is not context-bound, so without the explicit permission it
  reads no KTP at all (the one production Supervisor RME account loses the open
  access it had). Granting is an owner action through the governed command.
- **Perawat** holds `manage patients`, but its working branch is self-selected
  and never committed for the day, so it reads **no** stored card (ambiguous
  authority → deny). It still registers patients and captures cards, which are
  stamped with its working branch and readable by that branch's committed staff.
- **Delete** uses the same decision. This narrows an existing write; it grants
  nothing new.
- **Legacy patients** are not treated as belonging to every branch; their
  documents (none today) would carry unknown provenance and fail closed.
- **Multi-branch patients**: a visit, a patient-search hit or patient-list
  visibility never opens a card captured elsewhere.

## Known limits (not built here)

- Patient master data remains unscoped by an existing product decision: another
  branch's `manage patients` holder can still open the patient edit form and
  read the typed NIK input there. Only the KTP **image** archive is isolated.
- An unpinned Front Office / Admin Klinik account chooses its branch once per
  clinical day; it can commit to any RME branch at the start of a day and read
  that branch's cards that day (the same authority that scopes all its
  operational data). Hard per-person binding needs the Front Office pin or an
  owner-assigned branch for every front-desk account.
- No UI for per-account permission assignment; the governed CLI is the path.

## Tests

`tests/Feature/RME/PatientKtpArchiveBranchIsolationTest.php` — written first;
**44/44 failed** against the unmodified code; final suite **54 tests / 203
assertions**, green. Mutation testing (copy-based revert, every mutant verified
to apply, tree checksum-verified after each run): **27/27 killed**. The first
pass left two survivors that were real coverage gaps (the reason code for
unknown provenance; the service's own `manage patients` check, unreachable over
HTTP but load-bearing for `@can`) and the review fixes briefly orphaned two
backfill checks behind the new visit requirement — all four now pinned. Five existing tests
encoded the old open contract (a role-less `manage patients` reader opening any
card) and were re-anchored on an explicitly privileged reader with an inline
note; one gained an assertion that the visit flow records the visit branch.

Evidence for CI, merge, deploy and production verification is recorded in the
follow-up evidence commit.
