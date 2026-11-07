# DMS-RME-REVISION-PROGRAM-8B — corrective sprint for owner decisions D1, D2, D6 and D7

- **Programme:** RME revision programme under owner policy `DMS-FULL-DEVELOPMENT-PRODUCTION-TESTING-20261104`
  (rule 221 §7), mission `DMS-RME-REVISION-PROGRAM-8B`, 2026-11-07. Corrective sprint for the owner decisions of
  [[dms-rme-revision-program-8-readiness-reaudit]] (readiness NO-GO). Continuation of [[dms-rme-revision-program-8a]].
- **Status:** IN PROGRESS — this record is completed with the release evidence in §7.
- **Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `88843b26` (PR #610, the
  re-audit record). Production ran `c0e12382` (Program 8A GO, deploy `20261106-230625`), read from the Biznet host
  before work began; `88843b26` differs from it by documentation only.
- **Branch:** `feature/dms-rme-revision-program-8b`.
- **Canonical rule:** `.cursor/rules/227-rme-clinical-document-read-scope-printed-identity-numbering.mdc` (new; extends
  rule 226, which gains a forward note; supersedes nothing else). Domain entries AIR-RMC-46 … AIR-RMC-49
  (`.claude/rules/rme-clinical.md`).
- **Work category under the Foundation-First Sprint Lock:** security fix / clinical and financial correctness. The lock
  is unchanged. No schema, migration, permission, role, route, JS/CSS or package change.

## 1. Governance preflight

### 1.1 Owner decisions (CG-22)

| Decision | Owner answer (2026-11-07) | What this sprint does |
|---|---|---|
| D1 — clinical document read scope (P8-N2) | FIX NOW, "sesuai otorisasi yang berlaku … jangan memperluas akses klinis tanpa izin eksplisit" | §2.1 (option (a) of the decision matrix) |
| D2 — invoice / payment / consent numbering | FIX NOW, keep every historical number, the audit trail and idempotency | §2.2 |
| D3 — the remaining register | REVIEW PER ITEM, no automatic acceptance; implementation outside D1/D2/D6/D7 needs separate approval | §3: per-item matrix, nothing implemented |
| D4 — dedicated test device | PROVIDE A DEDICATED DEVICE; never an operational clinic device while the freeze is active | §4: procedure only (no device exists yet) |
| D5 — Legacy WAVE-4 | RECONCILE: audit, then propose closure or a clear extension; no automatic extension, no queue worker | §5: read-only audit and proposal |
| D6 — printed prescription identity (P8R-S3) | FIX NOW | §2.3 |
| D7 — service-layer authorization (P8R-S1, P8R-S2) | FIX NOW, role-matrix tests, no accidental future-role authoring | §2.4 |
| Deployment | Approved: `deploy-vps-runner.sh` on the Biznet VPS after the security and backup requirements are met; wait for DEPLOY OK and DEPLOY RUNNER OK (rule 221 §14 specific approval for a significant security change) | §7 |
| Not authorised | Full Suite, clinic reopening, queue worker / scheduler, enforcement scope or cohort changes | — |

### 1.2 Authority read before any change

Remote base re-fetched; production HEAD read from the host with an invocation-scoped `safe.directory`. Read-only
production checks (booleans and counts only, `BEGIN READ ONLY`): zero direct grants of `manage_clinic_visits`; the
roles holding it are the seven seeded clinical / front-desk roles plus one manually created role with no holders; the
two existing prescriptions already print names identical to their visit's doctor and patient.

### 1.3 Delivered as three pull requests (DEVFLOW scope gate)

`sprint:scope-audit --strict` returned **NO-GO** for the combined change: `SECURITY_FIX` carried 21 changed
application files across 6 modules, which that profile reads as a refactor rather than a scoped fix. The owner chose
(2026-11-07) to split rather than relabel the sprint type or record a waiver — the same answer Programme 7 gave to the
same gate. Each pull request is its own reviewable fix, stacked so that every merge produces exactly the tree CI
tested:

| PR | Decision | Application files | Scope audit (against its parent) |
|---|---|---|---|
| #611 PR1 | D2 numbering | 10 | GO — Consent, RmeInvoice |
| #614 PR2 (replaces #612) | D1 read scope | 7 | GO — MedicalRecord, Odontogram, Prescription, RmeOnlineContext |
| PR3 | D6 printed identity, D7 authoring allowlist, rule 227, records | 5 | GO — Consent, Prescription |

#612 and #613 were the first stacked heads. Squash-merging #611 rewrote lines that PR2 also edits, so #612 could
no longer merge onto the tree CI tested. Each commit was replayed unchanged as #614 (PR2) and PR3, with trees
byte-identical to the heads that had already passed CI. #614 merges with a merge commit, so PR3 stays a descendant
of the base and merges onto exactly its tested tree. The three trees together are the change set that ran the PostgreSQL regression in §7. One deploy follows the last
merge, and the single GO tag is created only after it.

## 2. Findings corrected

Every fix was reproduced RED against the untouched base first: the four new suites were run with the base versions of
all changed application files restored, and 28 of their tests failed; the tests that still passed are the positive
controls (behaviour that must not change) and the key pins. Mechanism detail stays in the private security record
(`~/.local/share/daengtisiams-private-security/`), not in this public repository.

### 2.1 D1 — clinical documents have their own read scope (P8-N2, MEDIUM)

Sanitised: the read abilities of the medical record, the prescription and the odontogram accepted any RME-enabled
branch, while the visit itself is read only in the working branch for the branch-pinned roles. New
`RmeWorkingBranchScope::clinicalDocumentBranchIdsFor()` / `::allowsClinicalDocument()`: a context-bound account holding
no clinical role (Kasir, Admin Klinik, Front Office) reads clinical documents only in its working branch and none
without one; clinicians and Owner keep the RME set; the answer is always a subset of the RME set. Applied to
`MedicalRecordPolicy::view` (which also authorises the handwriting image streams), `RmePrescriptionPolicy::view` /
`::viewForVisit` (page, canvases, print, WhatsApp send), `OdontogramPolicy::view` / `::print` / `::create` (the read
page), the prescription history list (the viewer is now a required argument) and the unified medical-record index.
Write abilities are unchanged. Accepted consequence: a front-desk account cannot open the RM workspace of a patient
whose canonical record belongs to another branch. Limits recorded for D3 (independent review): handwriting is scoped by the
branch that owns the patient's canonical record book, because pages carry no encounter branch; the RM workspace still
lists other branches' sheet metadata (never their bytes); and a non-context-bound role created at runtime keeps the
previous RME-wide read.

### 2.2 D2 — invoice, payment and consent numbers are serialised (P8-N4, OD-7-8, OD-5-8)

New `App\Support\Database\DocumentNumberSequenceLock`: `pg_advisory_xact_lock(namespace, YYYYMM)` with fixed
namespaces 92_002 (invoice), 92_003 (payment), 92_004 (consent), taken through each repository's
`lockNumberSequence($month)` before the month's highest number is read (rule 225 pattern). Outside a transaction it is
a `LogicException`; on non-PostgreSQL drivers a no-op. Invoice and payment MAX reads count soft-deleted rows. Nothing
is renumbered; prefixes unchanged. Every caller already holds its row locks before the generator runs, so the lock
order is unchanged.

### 2.3 D6 — printed prescription identity (P8R-S3, MEDIUM)

The printed dentist and patient were free-text request fields. Both FormRequests no longer accept them; the service
derives them from the visit on create and from the prescription's own doctor and patient on update (so the printed
name and `doctor_id` stay the same person after a room re-assignment); a prescription with no doctor is refused before
any canvas is written; the form shows both names read-only. Archived (soft-deleted) doctor and patient records still name their past
visits' prescriptions. Recorded for D3: D6 fixes who is named, not who draws the signature canvas.

### 2.4 D7 — service-layer authorization (P8R-S1, P8R-S2, MEDIUM)

`PrescriptionAuthoringAuthority` now requires, in the service as well as the policy: the write permission itself, not
the front-desk tier, a role in `AUTHORING_ROLES` (an allowlist equal to the clinical tier) and the working branch. A
role created at runtime and given the write permission no longer inherits prescription authoring; a role-less account
is refused. The RME/odontogram authoring predicate re-asserts the write permission too. The role matrix is pinned over
every seeded role, a runtime role, role-less accounts and direct service calls.

## 3. D3 — per-item review of the register

The full 55-row matrix (recommendation, risk, priority) is in the private evidence
(`DMS_RME_PROGRAM_8B_D3_REGISTER_MATRIX.md`). Summary: 6 rows were already closed, 8B closes 9 more, 21 owner decisions
remain open (was 29), 2 operational readiness blockers (dedicated device, queue worker), 14 documented residuals and 3
verified-safe / watch rows. Recommended next corrective sprint, if approved: one room-scope and prescription-hardening
pass (room scope on prescription and canonical-URL writes, examination start by a clinical role, freezing the visit
doctor after the examination, prescription audit trail, canvas size bounds, `no-store` on the consent signature stream,
and the clinical-role allowlist for RME/odontogram authoring). Nothing outside D1/D2/D6/D7 was implemented.

## 4. D4 — dedicated test device

No dedicated, normally enrolled test device exists. The procedure (enrolment through the existing approval workflow,
the check, pass/fail rules, what the owner must provide) is in the private evidence
(`DMS_RME_PROGRAM_8B_D4_DEDICATED_DEVICE.md`). `DEDICATED_DEVICE_VERIFICATION` stays **BLOCKED**; operational clinic
devices were not touched.

## 5. D5 — Legacy WAVE-4 reconciliation

Read-only audit (production log byte-identical before and after): WAVE-4 is ACTIVE seven days past its 2026-10-31
window; the ledger balances (accepted = published, nothing in flight, failed, unexplained or drifted); only one of the
four admitted branches ever received documents; the queue worker is OFF by policy. Every measurable closure condition
is met; whether further archives remain is an owner fact. Proposal: official closure through the canonical
`legacy-rme:wave-admin` path, or — only if archives remain — an extension to a stated date together with a separate
queue-worker decision. Nothing was executed (`DMS_RME_PROGRAM_8B_D5_WAVE4_RECONCILIATION.md`).

## 6. Tests

| Suite | Cases |
|---|---|
| `RmeClinicalDocumentReadScopeTest` (D1) | refused for Kasir / Admin Klinik / Front Office in another branch, allowed in their own, fail closed without a context, stream/print routes, prescription history, medical-record index, clinicians / Owner / hybrid unchanged, never wider than the RME set |
| `RmeDocumentNumberSequenceLockTest` (D2) | key pins and month validation, refusal outside a transaction, soft-deleted invoice and payment numbers never reissued, voided consent continues, issued numbers untouched; PostgreSQL: lock held during the read, waits for a concurrent holder, independent sequences and months |
| `RmePrescriptionPrintedIdentityTest` (D6) | submitted names ignored on create and update, print shows the clinical identity, prescription's own doctor kept after a visit doctor change, no-doctor refusal with nothing written, read-only form |
| `RmePrescriptionAuthoringRoleMatrixTest` (D7) | full matrix over every seeded role, runtime role, role-less account, clinical role without the permission, direct service calls, RoleSeeder classification guard, RME/odontogram predicate permission re-check |

Existing suites that encoded the latent behaviour were updated, not weakened: prescription authors in tests now hold a
clinical role (`rmeClinicalAuthor()`), and the one test that asserted the dentist name was editable now asserts it is
ignored. Regression results, CI and release evidence: §7.

## 7. Release evidence

Filled in at release.
