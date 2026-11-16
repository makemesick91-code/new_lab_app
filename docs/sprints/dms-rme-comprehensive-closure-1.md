# DMS-RME-COMPREHENSIVE-CLOSURE-1 — comprehensive RME audit, remediation and release

**Date:** 2026-11-16 (WITA = UTC+8). **Type:** SECURITY_FIX (Foundation-First Sprint Lock: allowed category security
fix / clinical correctness). **Base:** `a7b0bc99` (remote base tip, PR #645). **Rule:**
`.cursor/rules/237-rme-comprehensive-closure.mdc`. **Previous RME record:** [[dms-rme-revision-program-8d]] (GO,
`82f1eeba`), register source [[dms-rme-revision-sprint-0-evidence-recovery]].

Full Suite deferred under the global temporary policy (CG-18): **not run, not passed**.

## 0. Status

```text
PROGRAM_STATUS            = IN PROGRESS — implementation PR1 and PR2 written and tested locally (§5–§6)
RELEASE                   = NOT YET (needs merge, post-merge CI, owner-signed approval, deploy, verification)
GO tag                    = NOT CREATED (dms-rme-comprehensive-closure-1-go, declared in .sprint/current.yml)
FULL_SUITE_STATUS         = SKIPPED_BY_GLOBAL_TEMPORARY_POLICY (execution count 0)
BK-2                      = NOT RUN (manual-only directive, rule 232)
GLOBAL DOCTOR ENFORCEMENT = UNCHANGED (not touched; no flag, cohort or scope change)
```

## 1. Scope, authority, environment

The owner's brief (2026-11-16) orders a full audit of the RME module, an inventory of every RME pending item, the
remediation of every confirmed item that existing requirements and authorizations allow, and the full release
chain up to a GO tag, with explicit owner approval for new clinical product decisions, release signing and
rollback. It does not authorize global doctor enforcement, a pilot expansion, BK-2 or any production data change.

| Item | Measured |
|---|---|
| Base | `a7b0bc99c5450c4bfc6fb9d302e3ccef0fa9c4ec` (`devflow:base-ref-check --strict`: remote tracking ref; the local base ref is stale and not used) |
| RME application code since the 8D release | unchanged: `git diff 82f1eeba a7b0bc99 -- app resources routes config database` touches deploy/rollback code only |
| Worktrees | PR1 `fix/dms-rme-comprehensive-closure-1`, PR2 `fix/dms-rme-comprehensive-closure-1-pr2`, both cut from the remote base with `--no-track` (CG-02) |
| Test databases | PostgreSQL 16.15 (local socket), mission-owned schemas `rmecc1_pr1` / `rmecc1_pr2` in the test database; SQLite in memory for governance suites |
| Graphify | 0.9.99, baseline `graphify update .` on the base tree: 58,933 nodes, 143,245 edges, 2,421 communities (2 m 50 s); `graphify-out/` is git-ignored |
| Audit method | the Sprint 0 register re-checked against the code at `a7b0bc99`, plus three read-only audit passes (patient identity / branch context; device pilot, SATUSEHAT, queue, KTP timer; consent / completion / odontogram / print / prescription / cashier / handwriting / N+1). Every finding used below was re-verified in code by the author before it was classified |

## 2. Owner decisions (CG-22) — 2026-11-16, this program's session

Asked through the session's structured question tool; answers verbatim:

| # | Item | Question (short) | Owner answer |
|---|---|---|---|
| 1 | P8R-S6 | Invoice/payment (and RMECOR/RMEOVP/RMEDSP) month from UTC; fix now? | **Fix now** |
| 2 | OD-5-6 | New RM sheet on any consented visit, or only on the current encounter? | **Current encounter only** |
| 3 | Unsigned prescription print | Keep print before the named doctor signs, or refuse? | **Keep print allowed** |
| 4 | OD-7-1 | Kontrol without carry-over completes on a PARTIAL invoice; code or rule wins? | **Code wins, fix rule text** |
| 5 | Prescription edited after signing | Lock after signing / edit clears signature / keep | **Lock after signing** |
| 6 | OD-5-9 consent signature storage | Separate storage sprint or include now? | **Separate storage sprint** |
| 7 | Patient MRN override and patient-form branch | Measure first or restrict now? | **Measure first** |
| 8 | OPS-KTP timer and OPS-Q queue timing | Fix timing in code and give root commands, or leave? | **Fix timing in code + give commands** |

The brief itself orders the remediation of every confirmed RME pending item that existing requirements allow
("KERJAKAN SELURUH PENDING YANG TERKONFIRMASI DALAM CAKUPAN PROGRAM RME"). Sprint 0 had recommended FIX, with
acceptance criteria, for OD-7-6, OD-5-4 and OD-5-10(a), tightening-only changes that follow existing canonical rules
(the separate archive scopes of FIX-04b, rule 200's deactivation semantics, billing's "a cancelled parent is a label,
not a follow-up"). They are implemented under that brief; the owner is asked to confirm them explicitly together with
the merge approval of each PR, and the answer is recorded in §9.

## 3. Historical items in the brief, verified

| Brief statement | Verified state | Disposition |
|---|---|---|
| DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1: PR-B verification incomplete, ceremony pending, PR-C not started | Tag `doctor-access-single-session-branch-lock-1-go` → `d7d645cb` (2026-09-13); PR-B runtime `1010d9fb` is an ancestor of the base; PR-C (#406) merged and deployed (`docs/sprints/doctor-access-pr-c-production-evidence.md`); Half A activation tag `doctor-access-global-activation-half-a-1-go` → `4fec066c` (2026-09-18) | **ALREADY RESOLVED** (the brief's status is stale) |
| Consent signature 1 MB bound, pre-8C policy docblocks | closed by 8D (`dde7b9b6`, `82f1eeba`, tag `dms-rme-revision-program-8d-go`) | **ALREADY RESOLVED** |
| Legacy RME program | `LEGACY-RME-PROGRAM-CLOSURE-1` CLOSED / STEADY-STATE, inherited by the manifest | not reopened; no defect found that requires it |
| Global doctor enforcement, Android pilot | `global_permitted` literal false, cohort ceiling enforced, flags default OFF (audit pass 2) | **UNCHANGED** — not in scope without an owner APPLY decision |
| BK-2 | manual-only directive (rule 232) | not run, not a gate |

## 4. Canonical pending register

Severity: H high, M medium, L low, I info. Disposition codes: RESOLVED (this program), ALREADY RESOLVED, OWNER
DECISION (open), HUMAN OPERATION, OWNER DEFINITION (id defined only in the owner's private matrix), DEFERRED (a
documented, owner-visible follow-up with its trigger), BY DESIGN.

### 4.1 Resolved by this program

| Id | Module | Finding (root cause) | Sev | Fix | PR |
|---|---|---|---|---|---|
| P8R-S6 | RmeInvoice | invoice/payment month from UTC `now()` (CG-12); also RMECOR, RMEOVP, RMEDSP | M | `ClinicalClock::today()`; nothing renumbered | PR1 |
| CC1-NOFLASH (8D finding 2) | Consent, Prescription, MedicalRecord | a refused consent, prescription or RM page flashed signature/canvas data URIs into the session store (framework default flashes all non-password input) | M | five fields in `dontFlash` | PR1 |
| CC1-NOSTORE | RME prints | rendered clinical and patient-data documents (visit print/PDF, odontogram, prescription, consent, legacy archive, oversight, cashier receipt, patient/payment/receivable/patient-audit reports) had the framework default `no-cache, private` | M | `cache.headers:private;no_store;max_age=0` on 21 routes | PR1 |
| OPS-Q | Queue | `retry_after` 90 s and `TimeoutStopSec` 150 s below the 240 s legacy PDF render | M | `retry_after` 300 s, `TimeoutStopSec` 270 s (repo; host unit install is a root step) | PR1 |
| OD-7-6 | LegacyImport mass upload | odontogram batches scoped by the RME archive scope (list and per-batch) | M | scope chosen by `import_type` in one policy method | PR1 |
| OD-5-4 | User / online context | deactivation dropped a doctor's presence only on that doctor's next request | L | `markOffline()` in deactivate / inactive update / delete | PR1 |
| OD-7-1 | RmeInvoice (rule text) | AIR-RMB-04 said every PARTIAL Kontrol stays `cashier_pending`; the no-carry-over path completes it | L | owner: code wins; text narrowed; dangling "rule 207 §C7" reference removed | PR1 |
| Unsigned print | Prescription (rule text) | rule 228 §15 left the question open | I | owner: keep print allowed; recorded | PR1 |
| CC1-SEQTEST (8D audit finding 1) | tests | `createFromFormat('Ym')` keeps today's day and overflows on the 29th–31st (CG-20) | L | `!Ym`; boundary tests pinned | PR1 |
| OD-5-6 | MedicalRecord | a new RM sheet was accepted on any visit of the patient named in the URL once some consented encounter existed | M | owner: current encounter only; service refuses, navigation never offers it | PR2 |
| OD-5-10(a) | ClinicVisit | a cancelled visit was accepted (and offered) as a Kontrol prior visit | L | guard refuses (read FOR SHARE), picker excludes | PR2 |
| CC1-HWBOUND | MedicalRecord handwriting | the RM page canvas had no payload, declared-size or inflate bound (8C/8D bounded only prescription and consent canvases) | M | `HandwritingPayloadGuard`: request `max`, declared-size caps, one IHDR, bounded incremental inflate; all above the real 900x1273 canvas worst case | PR1 |
| CC1-RXSIGNED | Prescription | another clinician could change a prescription after the named doctor signed; the signature stayed on content the doctor did not see | M | owner: lock after signing; policy (after the scope check) + service (visit lock, fresh row) + rejection audit + UI | PR2 |
| CC1-RXSIGNRACE | Prescription | the reverse race: content changed after the doctor's page loaded, then the doctor signs without re-sending the canvas (independent review F6) | L | content version carried by the form; a signature over newer content is refused | PR2 |
| CC1-KONTROLBTN | ClinicVisit | "Buat Kontrol" offered from a cancelled visit, silently registering a NEW visit (independent review F7) | L | controller flag hides it | PR2 |
| CC1-DOC1 | rules | `rme-clinical.md` posture said a mistaken consent "is superseded by re-signing" (Sprint 0 OD-5-8: re-sign is unreachable) | I | corrected | PR1 |

### 4.2 Not resolved here — disposition and trigger

| Id | Finding | Sev | Disposition | Next step / trigger |
|---|---|---|---|---|
| OD-5-9 (+ P8R-S4b) | consent signatures on the generic `local` private disk, not `ClinicalEvidenceStorage` (rule 121) | M | OWNER DECISION 2026-11-16: separate storage sprint | new write path + key-preserving copy command (dry run, then apply on the host with owner approval); fold in CC1-ORPHAN |
| CC1-ORPHAN (8D finding 1) | a late failure inside `RmeVisitConsentService::sign()` (second object write, insert or commit) leaves the signature objects on the private disk | L | DEFERRED into the OD-5-9 storage sprint (same write path; no data lost, no exposure) | that sprint applies the rule 224 compensate-on-failure pattern |
| CC1-PAT-1 | the patient form's explicit Nomor RM override is stored as typed (bypasses AIR-PAT-11 composition; can disagree with the patient's branch) | M | OWNER DECISION 2026-11-16: measure first | owner runs the read-only count in §10.2; then a patient-identity sprint |
| CC1-PAT-2 | the patient form takes a request `branch_id` with no working-branch pinning for Front Office / Perawat / Admin Klinik | M | same decision | same sprint |
| CC1-PAT-3 | `BranchSeeder` creates MAIN without `is_rme_enabled=false` (column default true) on a fresh seed; many tests rely on it | L | DEFERRED to the same sprint | production value to be measured with §10.2 |
| CC1-PAT-4 | small patient validations: `registered_at` unbounded year, NIK not `digits:16` at registration, no `$hidden` for `ktp_number`, unique violation answers 500 | L | DEFERRED to the same sprint (needs the production measurement first so legacy rows are not locked out) | same sprint |
| CC1-SAT-1 | SATUSEHAT: branch pilot approval is not checked in prepare / queue / process; excluding a queued record does not stop it | L (latent: `satusehat.enabled` defaults false) | DEFERRED with a hard trigger, like OPS-SAT | must be fixed before `satusehat.enabled=true` anywhere |
| OPS-SAT | no SATUSEHAT retry sweep scheduled; worker does not read the `satusehat` queue | L (latent) | OWNER DEFINITION (id) + same trigger | before enabling SATUSEHAT |
| OPS-KTP | KTP temporary-scan prune timer not installed in production | M | HUMAN OPERATION (root): commands in §10.1 | owner runs them; verify with `systemctl list-timers` |
| CC1-ODO | print / cashier summary show an empty odontogram row as a blank chart instead of using `hasRecordedTeeth()` | L | DEFERRED (UX) | next RME UX sprint |
| CC1-PERF | per-row `@can('update', $visit)` on the visit and queue lists repeats the doctor lookup for doctor accounts (3–4 queries a row) | L | DEFERRED (performance; lists are already doctor-filtered) | measure on production read path before changing |
| CC1-CG08 | `AndroidReleaseGovernanceScanner` reads a flag's `default` straight from `config('feature_flags…')` (metadata, not the runtime value) | L | DEFERRED (governance tooling) | next doctor-access governance change |
| OD-5-5, OD-7-5, OD-7-7, R7-1, R8A-2 (+ 8D finding 3), RA-1, OD-6-1, OD-5-10(b) | Sprint 0 ACCEPT-AS-RESIDUAL proposals | L/I | OWNER DECISION (open; proposals unchanged, Sprint 0 §3) | owner disposition |
| OD-5-8 | consent correction flow (void/re-sign route; double submit creates two consents) | L | OWNER DECISION (design) | owner design, then one unit |
| OD-7-3 | doctors may print/export a published legacy archive | I | OWNER DECISION (allowed by rule 93 today); the over-broad `DoctorRoomScopeService` docblock was corrected | owner, only if "deny" is wanted |
| R7-F, P8-FIX1, P8-SEED, R8A-3, RA-4 | ids defined only in the owner's private matrix | ? | OWNER DEFINITION | one neutral line each |
| D3-NEW-3 / D3-NEW-5 | deferred with conditions (owner) | ? | OWNER DECISION | triggers as recorded in Sprint 0 §6 |
| RA-3, RA-5 | untracked runtime directory on the host; restore-drill evidence path | I | HUMAN OPERATION / owner register | — |
| D4 | dedicated real-device ceremony | — | BLOCKED_EXTERNAL / HUMAN OPERATION | owner provides a test device and a test doctor |
| D5 | legacy WAVE-4 branch confirmations (SPN4, TLK1, LDK2, ATG3) | — | HUMAN OPERATION | four branch confirmations |
| CC1-RXPRINT-WRITE | printing a prescription sets `printed_at` on a GET | — | BY DESIGN (rule 226 §1.7a) | — |
| CC1-LAB-FLASH | lab delivery POD signatures are flashed the same way | — | OUT OF SCOPE (lab module) — recorded for the lab owner | — |
| CC1-LAB-UTC | lab `InvoiceNumberGeneratorService` / `PaymentNumberGeneratorService` still use the UTC month | — | OUT OF SCOPE (lab module) — same class as P8R-S6, recorded for the lab owner | — |

## 5. Implementation

### 5.1 PR1 — `fix/dms-rme-comprehensive-closure-1` (10 `app/*.php` files, 5 modules)

- `RmeInvoiceNumberGeneratorService`, `RmePaymentNumberGeneratorService`: inject `ClinicalClock`; one clinic `$month`
  drives the lock key, the MAX read and the prefix.
- `RmeInvoiceCorrectionService` (RMECOR, RMEOVP), `RmeBillingDisputeService` (RMEDSP): `ClinicalClock::today()`.
- `bootstrap/app.php`: `dontFlash` for `consenter_signature`, `doctor_signature`, `prescription_canvas_data`,
  `doctor_signature_canvas_data`, `handwriting_data`.
- `routes/web.php`: the 21 clinical and patient-data document routes carry `cache.headers:private;no_store;max_age=0`.
- `HandwritingPayloadGuard` (new service) called by `MedicalRecordHandwritingController`: payload, declared-size,
  single-IHDR and bounded-inflate checks; the blank check's own inflate is bounded too. Moved from PR2 to PR1 after
  the independent review (thin controller, CG-04; the suite's CI token lives in PR1).
- `config/queue.php` (`retry_after` 300), `deploy/systemd/daengtisiams-queue-worker.service` (`TimeoutStopSec` 270),
  queue runbook paragraph.
- `LegacyMassUploadBatchPolicy::workspaceScopeFor()`; `LegacyMassUploadController::index` uses it.
- `UserService`: `markOffline()` on deactivate, inactive update and delete.
- `DoctorRoomScopeService`: docblock names rule 138 §6's three routes (comment only).
- Rules: new rule 237; rule 227 §4.21 (P8R-S6 closed); rule 228 §15 (unsigned print decision); AIR-RMB-04 narrowed;
  `rme-clinical.md` index line and posture correction (both domain files stay under the 30,000-character budget).
- CI: the three new suites are named so EXISTING critical-gate tokens select them in both variants
  (`RmeClinicalWriteAuthorityNoFlashTest`, `RmeClinicalWriteAuthorityHandwritingBoundTest` →
  `RmeClinicalWriteAuthority`; `RmeClinicalDocumentReadScopeNoStoreTest` → `RmeClinicalDocumentReadScope`) and are
  declared in `critical_gate_mandatory_suites` (CG-19). A new token was first added to the workflow, but this
  workstation's GitHub credential lacks the `workflow` scope, so the workflow file is unchanged.

### 5.2 PR2 — `fix/dms-rme-comprehensive-closure-1-pr2` (10 `app/*.php` files; rebased on PR1)

- `ClinicVisitRegistrationGuard` (cancelled prior visit refused), `ClinicVisitService::patientVisitOptions` (not offered).
- `MedicalRecordService::createDraftOn` (OD-5-6 refusal, `MESSAGE_SHEET_ONLY_ON_ENCOUNTER`), `MedicalRecordController`
  (offer "Tambah Lembar RM" only for the current encounter), `ClinicVisitController` (comment).
- `PrescriptionAuthoringAuthority::signedContentLocked()` (the doctor the edit path derives: the prescription's own,
  else the visit's), `RmePrescriptionPolicy::update` (scope first, then the lock), `RmePrescriptionService` (decided on
  the fresh row under the visit lock; `RME_PRESCRIPTION_SIGNED_EDIT_REJECTED` written after rollback;
  `contentVersion()` refuses a signature over content changed since the page loaded), `UpdateRmePrescriptionRequest`
  (`content_version`), `RmePrescriptionController` (no edit offer; HTTP refusal audited only when the policy's reason is
  the lock), view (notice, hidden version).
- `ClinicVisitController`: no "Buat Kontrol" from a cancelled visit.
- Factory state `RmePrescriptionFactory::unsigned()`. Tests that exercise what ANOTHER clinician may author now start
  from an unsigned prescription (their intent unchanged; OD-5-1 kept); I5-04 split into the unsigned and signed cases.

## 6. Tests (measured, local)

RED first: every new behavioural test was run against the untouched code and failed for its own reason, then passed
after the change. Keep-working controls pass both ways.

| Area | RED on the base code | GREEN (PostgreSQL 16) |
|---|---|---|
| Sequence lock + clinic month (`RmeDocumentNumberSequenceLockTest`) | 6 failed (the 00:00–07:59 WITA cases, the old-label case, the PostgreSQL lock-key cases) | 30 tests, 95 assertions, 0 failed |
| RMECOR / RMEOVP / RMEDSP | 3 failed | 127 tests (three lifecycle suites), 460 assertions, 0 failed |
| No flash (NF-01, NF-02, consent D-01) | 3 failed | 3 tests, 31 assertions |
| No-store prints (NS-01..04) | 2 failed (NS-01, NS-03) | 4 tests, 50 assertions |
| OPS-Q order | 1 failed | queue + ENT-5 + runtime-hardening suites: 54 tests, 377 assertions |
| OD-5-4 | 3 failed | `ActiveAccountSessionTest` 42 tests, 153 assertions |
| OD-7-6 | 4 failed | `LegacyMassUpload*` 120 tests, 828 assertions |
| OD-5-10(a) | 2 failed | registration suites 84 tests, 279 assertions |
| OD-5-6 | 3 failed | 5 tests; the ten medical-record / workspace / consent suites 257 tests, 677 assertions |
| Handwriting bounds (HW-01..08) | 6 failed (PR2 controller version, and again after the move to `HandwritingPayloadGuard` in PR1) | 8 tests; the 15 suites that post handwriting + the no-store suite (21 routes) 549 tests, 2,248 assertions (PR1) |
| Prescription lock (I7-01..07, I5-04b) | 6 failed | prescription + write-authority + read-scope suites 172 tests, 648 assertions |
| Review fixes (I7-08 version, I7-11 scope order, I7-09/10, Buat Kontrol, `DoctorPatientScopeTest`) | I7-08, I7-11 failed with each fix removed | 49 tests, 152 assertions (PR2) |
| Governance (SQLite): `tests/Feature/Cicd`, `DevflowAiInstructionSystemTest`, `ProductionShellCommandGuardTest`, queue, ENT-5 | — | 467 tests, 5,164 assertions; 1 skipped (Poppler, pre-existing); the one failure (domain rule size budget) fixed and re-run |

## 7. DEVFLOW gates (PR1, local)

`devflow:base-ref-check --strict` GO; `sprint:manifest-check` GO; `sprint:scope-audit --strict` GO (5 modules, 10
`app/*.php` files after the handwriting move); `foundation:devflow-check --strict` GO; Pint clean; `git diff --check`
clean. `sprint:test-plan`
escalates to the full required suite (unmatched rule/doc paths and the access-control / config categories): under
CG-18 the targeted suites in §6 run instead and the Full Suite is reported as deferred.

## 8. Independent review

A separate read-only `general-purpose` session reviewed both uncommitted change sets against `a7b0bc99`, given the
rules and the code, not the author's conclusions. It ran no tests. Result: 1 HIGH, 3 MEDIUM, 5 LOW, 2 INFO; it
confirmed every intended behaviour A1–A6 and B1–B4 in code.

| # | Sev | Finding | Resolution |
|---|---|---|---|
| F1 | HIGH | `DoctorPatientScopeTest` (critical-gate token `DoctorPatientScope`) edited a SIGNED prescription as another doctor; the lock made it 403 | fixture unsigned (the test is about scope) + an explicit assertion that the signed case is refused; suite green |
| F2 | MEDIUM | the lock was checked before the scope check: an out-of-scope doctor got the signed-lock message and an audit row | policy evaluates `view()` first; I7-11 |
| F3 | MEDIUM | rules did not describe PR2; comments cited a wrong rule-237 section | rule 237 §9–§11 and rule 228 §12.2 amended in PR2; references point at §9 |
| F4 | MEDIUM | the handwriting bound suite was not declared mandatory and relied on PR1's token | moved into PR1, declared, selected by an existing token |
| F5 | LOW | the lock used the raw `doctor_id`; the edit path falls back to the visit doctor | same derivation; I7-10 |
| F6 | LOW | reverse race: a signature over content changed after the doctor's page loaded | `contentVersion`; I7-08, I7-09 |
| F7 | LOW | "Buat Kontrol" still offered from a cancelled visit | hidden (controller flag); test added |
| F8 | LOW | PNG parsing in the controller (CG-04); misplaced docblock | `HandwritingPayloadGuard` service |
| F9 | LOW | OD-7-6, OD-5-4, OD-5-10(a) rely on the brief, not a recorded answer | §2 states the basis; owner confirmation requested with the merge approval (§9) |
| F10 | INFO | PR2 depends on PR1 (manifest, dontFlash, token) | PR2 rebased on the merged PR1 |
| F11 | INFO | receipt PDF and patient/payment reports lacked no-store; lab numbers use UTC; I7-05 tests the refresh | no-store extended to 7 more routes; lab item recorded out of scope (§4.2); I7-05 kept as the refresh test |

## 9. Release and production

Pending. Release flow: PR → exact-head CI → owner merge approval → post-merge push CI on the exact merge SHA → owner's
written approval of the full release SHA → owner signs locally (`scripts/release-approval-sign.sh`) →
`submit-approval` → `check-approval` GO → `start` on the Biznet host through the deploy operator → `follow` →
`verify <deploy-id>` (`DEPLOY RUNNER OK`) → read-only production verification → GO tag. Not inside 02:25–02:40 WITA.
Deploy before 2026-11-30 16:00 UTC so P8R-S6 is live for the 2026-12-01 boundary.

## 10. Owner actions prepared by this program

### 10.1 OPS-KTP — install the KTP temporary-scan prune timer (root, owner's admin password)

Run from the owner's own terminal on the production host, after this release is deployed (the units ship in
`deploy/systemd/`, rule 182 §13):

```bash
cd /var/www/asia-dental-lab-v2
sudo install -m 0644 -o root -g root deploy/systemd/daengtisiams-ktp-temp-prune.service /etc/systemd/system/
sudo install -m 0644 -o root -g root deploy/systemd/daengtisiams-ktp-temp-prune.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now daengtisiams-ktp-temp-prune.timer
systemctl list-timers daengtisiams-ktp-temp-prune.timer --no-pager
```

Evidence to keep: the `list-timers` line (next run time) and, after the first run,
`journalctl -u daengtisiams-ktp-temp-prune.service --since today --no-pager | tail -5` (counts only, no file names).

### 10.2 CC1-PAT — read-only measurement before the patient-identity sprint

Counts only; no identifier leaves the database. Run in a read-only transaction on the production database:

```sql
BEGIN READ ONLY;
SELECT
  count(*)                                                                      AS patients,
  count(*) FILTER (WHERE medical_record_number IS NULL)                          AS mrn_missing,
  count(*) FILTER (WHERE medical_record_number !~ '^DG-[A-Z0-9]{3,5}-[0-9]{4}-[0-9]+$') AS mrn_not_canonical,
  count(*) FILTER (WHERE p.medical_record_number ~ '^DG-'
                     AND split_part(p.medical_record_number, '-', 2) NOT IN (SELECT code FROM mst_branches)
                     AND split_part(p.medical_record_number, '-', 2) NOT IN ('TKM1', 'SUN4')) AS mrn_unknown_branch_code,
  count(*) FILTER (WHERE b.code IS NOT NULL AND p.medical_record_number ~ '^DG-'
                     AND split_part(p.medical_record_number, '-', 2) <> b.code
                     AND NOT (b.code = 'TLK1' AND split_part(p.medical_record_number, '-', 2) = 'TKM1')
                     AND NOT (b.code = 'SPN4' AND split_part(p.medical_record_number, '-', 2) = 'SUN4')) AS mrn_branch_mismatch,
  count(*) FILTER (WHERE ktp_number IS NOT NULL AND ktp_number !~ '^[0-9]{16}$')   AS nik_not_16_digits,
  count(*) FILTER (WHERE registered_at > CURRENT_DATE)                            AS registered_in_future
FROM mst_patients p LEFT JOIN mst_branches b ON b.id = p.branch_id
WHERE p.deleted_at IS NULL;
SELECT code, is_active, is_rme_enabled FROM mst_branches ORDER BY code;
ROLLBACK;
```

## 11. Graphify, Dataviz, Obsidian

- Graphify baseline recorded in §1; the post-change refresh is recorded at closure.
- Dataviz: the register (§4) and the test table (§6) are the data; the report is published at closure with counts
  only (no patient data).
- Obsidian: `docs/` is the knowledge base; this record and the module index are updated in place. The vault worktree
  is locked and is not edited (CG-02).
