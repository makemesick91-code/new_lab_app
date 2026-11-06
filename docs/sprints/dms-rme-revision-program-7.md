# DMS-RME-REVISION-PROGRAM-7 — doctor access, cashier integration and legacy RME audit

- **Programme:** RME revision programme under owner policy `DMS-FULL-DEVELOPMENT-PRODUCTION-TESTING-20261104`
  (rule 221 §7), mission `DMS-RME-REVISION-PROGRAM-7` (P0, SECURITY_FIX / CLINICAL_INTEGRITY / RELIABILITY_AUDIT),
  2026-11-06. Continuation of [[dms-rme-revision-program-1]] … [[dms-rme-revision-program-6]].
- **Status:** IN PROGRESS — implementation and regression on the branch; CI, merge, deploy, production verification
  and the GO tag are recorded in §8 once they happen. Full Suite deferred under CG-18, not claimed.
- **Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `daace3df` (resolved from
  `origin` after fetch). Production ran `91131671` (Program 6 GO, deploy `20261106-052449`), read from the Biznet host
  before work began; the base was one docs-only commit (#604) ahead of it.
- **Branch:** `feature/dms-rme-revision-program-7`.
- **Canonical rule:** `.cursor/rules/225-rme-concurrent-first-write-and-void-staging-integrity.mdc` (new; extends
  rules 223 and 224; rules 93 §7 and 174 §6 gain a staging cross-reference; supersedes nothing). Domain entries:
  AIR-RMC-44 (`.claude/rules/rme-clinical.md`), AIR-LRM-20 extended (`legacy-rme.md`), AIR-DAC-40 extended
  (`doctor-access.md`).
- **Work category under the Foundation-First Sprint Lock:** clinical correctness / security fix. The lock is
  unchanged. No new feature, no schema, migration, permission, route, JS/CSS or production package change.

## 1. Governance preflight

### 1.1 Owner approval (recorded per CG-22)

| Id | Decision | Source |
|---|---|---|
| OD-P7 | Audit doctor access (DA-01..04), the cashier integration (CI-01..04), the legacy archives (LR-01..04); close the reliability residuals R5-1, R5-2, R6-1 (REL-01..03); report queue/scheduler readiness without activating anything (OPS-01) | Mission brief 2026-11-06 |
| OD-P7-scope | Correct VERIFIED defects only; record anything that needs a product decision; do not enable global device enforcement, the branch lock, the queue worker, the scheduler or any production flag | Same brief |

### 1.2 Preflight evidence (production, read-only, 2026-11-06 08:23 UTC = 16:23 WITA)

- Host `daengtisiams-biznet-prod`, HEAD `91131671…`, last deploy `20261106-052449`, pending migrations 0, failed jobs 0.
- Flags: `doctor.trusted_device_enforcement=true`, `doctor.pwa_webauthn_device_login=true`,
  `doctor.single_active_session=true`, `doctor.branch_lock=false`, `front_office.branch_device_lock=false`.
- Enforcement scope: `pilot`, cohort `9,15,18`, `PILOT_COHORT_MAXIMUM=12`, `GLOBAL_ENFORCEMENT_ACTIVE=false`,
  `SCOPE_VERDICT=GO`.
- Legacy: WAVE-4 ACTIVE; SPN4 20 accepted / 20 published; pending render 0; `legacy-rme:migration-status` findings
  none.
- Queue: `daengtisiams-queue-worker.service` inactive / disabled; `daengtisiams-db-backup.timer` active / enabled;
  `ktp-temp-prune.timer` not installed; `daengtisiams-pilot-snapshot.timer` inactive / disabled; no crontab for the
  deploy or runtime account. `/usr/bin/php` resolves to `php8.3` (8.3.6) on this host.
- Row counts (before): patients 1522, visits 11, doctor assignments 9, session leases 79, device authorizations 122,
  devices 12, WebAuthn credentials 9, medical records 10, handwritings 6, handwriting pages 1, invoices 10,
  payments 10, lab candidates 3, lab orders 0, legacy RME imports/records 20/20, legacy odontogram imports/records
  2/2, odontograms 9, consents 11, prescriptions 2, inventory movements 56, audit rows 1658, jobs 0, failed jobs 0.
- `storage/logs/laravel.log` 1,467,295 bytes / 166 `ERROR` lines.

### 1.3 DEVFLOW gates (branch, before CI)

- `devflow:base-ref-check --strict` → `BASE_SOURCE=remote_tracking_ref`, `BASE_SHA=daace3df` (the local base ref was
  stale at `9eabff07` and was not used); `sprint:manifest-check` GO; `foundation:devflow-check --strict` GO;
  `foundation:shared-service-audit --strict` 10 entries, 0 errors, 0 warnings.
- `sprint:scope-audit --strict` first answered **NO-GO**: "Type SECURITY_FIX carries 12 changed code files — looks like
  a refactor, not a scoped fix" (`allow_refactor=false`, limit 10). The type was NOT relabelled and the threshold was
  NOT changed. The change was made smaller instead: the two single-use import-model helpers were removed and both
  staging controllers ask the produced record's own `isVoided()` directly (the canonical predicate already existed).
  Re-run: **GO**, 10 app files across 5 modules (ClinicVisit, LegacyOdontogram, LegacyRme, MedicalRecord, RME).

## 2. Architecture map (what was audited)

| Area | Authority read |
|---|---|
| Doctor identity, device, lease | `DoctorIdentityResolver`, `DoctorAppLoginGate`, `DoctorSessionLeaseService`, `IncumbentSessionProbe`, `EnsureDoctorSessionLease` |
| Room and patient scope | `DoctorRoomScopeService`, `DoctorPatientScopeService`, `ClinicVisitPolicy`, `MedicalRecordPolicy`, `OdontogramPolicy`, `RmePrescriptionPolicy` |
| Assignment and numbering | `PatientDoctorAssignmentService`, `ClinicVisitService::create()/assignRoom()`, `ClinicVisitRepository` |
| Cashier | `ClinicVisitService::transitionStatus()`, `RmeInvoiceService`, `RmePaymentService`, `RmeControlReceivableService`, `RmeLabIntegrationService` |
| Legacy | `LegacyRmeImportController`, `LegacyRmeRecordController`, `LegacyRmePublishService`, `LegacyRmeVoidService`, `LegacyOdontogramImportController`, the two workspace scopes |
| Queue / scheduler | `config/queue.php`, the six `ShouldQueue` classes, `deploy/systemd/*.service`, `routes/console.php`, `docs/runbooks/queue-worker-activation-runbook.md` |

## 3. Findings

### 3.1 Verified defects (corrected) — every one RED on PostgreSQL 16 against the untouched base `daace3df`

| Id | Defect | Effect | Fix |
|---|---|---|---|
| P7-REL-01 (R5-1) | `PatientDoctorAssignmentService` caught the active-assignment unique violation and re-read the winner inside the aborted transaction | 25P02 → HTTP 500 on room assignment when two assignments of one doctor/patient raced | Insert in a SAVEPOINT; catch `UniqueConstraintViolationException` outside it; re-read the winner (`?? throw`); every other failure still surfaces |
| P7-REL-03 (R6-1) | Two first handwriting saves raced `UNIQUE(clinic_visit_id)` on the draft insert | Wrong refusal ("record already exists") or 23505 → HTTP 500; the page was lost | `createDraftOn()`: insert in a SAVEPOINT; the canonical-book path adopts the winner, `createDraft()` keeps its refusal |
| P7-REL-02 (R5-2) | `MAX + 1` numbering read under a `LIMIT 1 FOR UPDATE`, which serialises nothing on the day's first registration and hands PostgreSQL waiters the same top row | Two registrations at one branch computed the same queue/visit number → HTTP 500 | `lockVisitSequence($branchId)`: `pg_advisory_xact_lock(92_001, branch_id)` before either read; the useless row locks removed |
| P7-LR-01 | A VOIDed legacy archive kept streaming its source PDF and pages through the STAGING routes (publishing moves no bytes) | Retracted clinical evidence still downloadable by Super Admin, Supervisor RME and in-branch operators (rule 93 §7, rule 174 §6) | Staging `source()`/`page()` answer 404 when the produced record's `isVoided()` holds; the staging screen shows a VOID notice instead of the links |
| P7-REL-02b (latent, found by review) | The numbering reads skipped soft-deleted visits, but both unique indexes are full and still hold their numbers | After a visit is soft-deleted, the next registration at that branch that day is handed its number → HTTP 500 with no concurrency (no application delete path exists; data repair only) | `withTrashed()` on `nextQueueNumber()` and on `generateUniqueVisitNumber()` (MAX and its `exists()` loop) |
| P7-DA-02 (latent) | `applyVisitScopeForDoctor()`'s `own_visit` subquery counted soft-deleted visits | A list could offer a visit whose detail answers 403 (no delete path exists today; reachable only via data repair) | `whereNull('own_visit.deleted_at')`, matching its two siblings fixed in `4f69d8d7` |

### 3.2 Verified safe (no code change)

- **DA-01** identity only through `mst_doctors.user_id`; inactive accounts refused at login and on every request;
  the Android and WebAuthn proofs stay separate code paths.
- **DA-02** single session refused-not-evicted (except D1 below); browser denial for the armed cohort only;
  `global_permitted=false` untouched.
- **DA-03** room isolation for ACTIVE visits on visit, RME and odontogram routes; historical visits not hidden;
  doctor RME/PDF/odontogram print denied; prescription print preserved.
- **DA-04** treating relationship required (assignment or visit); same branch alone never authorizes.
- **CI-01** only the explicit completion path moves `in_progress → cashier_pending`, decided on the locked visit
  row FOR NO KEY UPDATE, requires signed consent; finalize/consent/odontogram never auto-complete.
- **CI-02** invoice needs `cashier_pending` + this visit's FINAL record; manual cashier pricing; server-side amounts;
  payment idempotency (rule 205); RME and lab billing never mixed.
- **CI-03** ordinary visit completes on any payment > 0; carry-over is the server-side intersection, FIFO, never
  merged into the new invoice.
- **CI-04** lab candidates generated after commit, PAID-only, idempotent, never a `LabOrder`; failure log redacted.
- **LR-02..04** lifecycle, separate publisher, single-active document, RM-derived branch, workspace scopes.
- **25P02 elsewhere in billing:** no path found (the payment unique catch sits outside the transaction).

### 3.3 Requires an owner decision (recorded, not changed — CG-22)

| Id | Item | Why it is a decision |
|---|---|---|
| OD-7-1 | Kontrol completion depends on the payment path: after the parent receivable is cleared, a later small payment on a Kontrol visit goes through `pay()` and completes the visit with its own invoice still PARTIAL | AIR-RMB-04's wording limits the exception to the Kontrol paths (code complies); its stated consequence does not hold. No money is lost (balance stays a receivable) |
| OD-7-2 | Prescription show/store/update on an ACTIVE visit in another room is allowed for a doctor with a treating relationship | Rule 138 lists visit/RME/odontogram; prescriptions were never named. Distinct from OD-5-7 |
| OD-7-3 | Doctor may print/export a published legacy RME archive | Rule 138 §6 names three routes; the `DoctorRoomScopeService` docblock says a doctor "may never print, export or download them" |
| OD-7-4 | Doctor cannot read a published Legacy Odontogram although comments describe the treating doctor reading it | Rule 174 §4 is conditional ("when granted"); granting is a permission decision |
| OD-7-5 | A failed lab-candidate generation has no retry or backfill (the redacted log line is the only trace) | New operational capability |
| OD-7-6 | The mass-upload batch policy uses `LegacyRmeWorkspaceScope` for odontogram batches too | Latent (no seeded role scopes differently; batch metadata only, no bytes) |
| OD-7-7 | D1 lease in-flight race (§3.4) — grace period for a fresh lease, or deny on the lost-race branch | Changes the rule 153 refused-not-evicted contract |
| OD-7-8 | BCF-07: invoice and payment numbers `MAX + 1` without a lock | Known open finding (rule 188, "optional"); the loser answers 500 and persists nothing |

Carried, unchanged: OD-5-1 … OD-5-10 (Program 5 §3.3), OD-6-1 (page-level optimistic concurrency — not
implemented, as instructed), OD-6-2 (consent stream `Cache-Control`), R6-2 (`FORBIDDEN_DISKS` runtime enforcement;
sha256 not verified on read).

### 3.4 Residuals

- **D1 (LOW, verified by reading, not reproduced).** During `POST /login`, `SessionGuard::updateSession()` deletes
  the old session row and the `Login` event mints the lease, but the new `sessions` row carrying `user_id` is only
  written when the request finishes. A second login in that instant finds no row, judges the fresh lease dead
  (`IncumbentSessionProbe`), releases it as `dead_session_reclaimed` and wins; the first browser is evicted on its
  next request. Needs two concurrent logins (or a double submit); recorded as OD-7-7.
- **R7-1 (LOW, from review).** R6-1 is closed for two first saves on the same canonical visit. A patient with no
  book has no canonical visit, so each save falls back to its own context visit: two simultaneous first saves
  opened from two different visits write two books. Needs per-patient serialisation and a lock-order review.
- **R7-2 (latent, from review).** A soft-deleted medical record still occupies `UNIQUE(clinic_visit_id)`; a first
  save on that visit answers HTTP 500 (nothing persisted). No delete path exists; restore-versus-refuse is a product
  decision.
- `RmePaymentService::closeZeroValueInvoice()` locks the visit `FOR UPDATE` where rule 223 §1a prescribes FOR NO KEY
  UPDATE. No lock cycle found; behind `rme.zero_value_closure` (OFF).
- Authoring is still checked outside any lock (rule 224, INFO-6); R6-1 was closed by savepoint-adopt, not by a visit
  lock, so that interleaving stays recorded.

### 3.5 Documentation drift corrected

- Rule 223 §19 said the lock test asserts `FOR UPDATE`; it asserts `FOR NO KEY UPDATE` (CW5-01f).
- `.claude/rules/rme-billing.md` said the lab-candidate failure log carries the raw exception message; R-02 redacted it.
- `LegacyRmePublishService` (two comments) said the staging row "is soft-deleted"; nothing soft-deletes an import.
- Programme 5 and 6 records and rule 224 gain forward references closing R5-1, R5-2 and R6-1.

### 3.6 OPS-01 — queue worker and scheduler readiness (report only; nothing started)

| Item | Finding | Classification |
|---|---|---|
| Worker | `daengtisiams-queue-worker.service` inactive and disabled on Biznet; deploy never starts it | VERIFIED SAFE (posture as intended) |
| Clinical dependency | None of the six queued classes is required for clinical correctness; both legacy PDF renders and the three SATUSEHAT jobs are | VERIFIED SAFE |
| Silent stall | A legacy import sits at `QUEUED` while the worker is off, and occupies the patient's single-active-document slot; mass-upload items read `DISPATCHED` | DEFERRED (owner: enable the worker, or surface "stalled") |
| `retry_after` | `DB_QUEUE_RETRY_AFTER` unset on Biznet → 90 s, below the legacy job timeout (240 s); a crashed render can be re-reserved while still `PROCESSING` | DEFERRED (set ≥ 300 before enabling the worker) |
| `TimeoutStopSec=150` | Below 240 s; stopping the service can kill a render partway | DEFERRED (raise to ≥ 270) |
| Restart signal | No `queue:restart` after deploy; an enabled worker would run old code up to `--max-time` | DEFERRED (policy needed before enabling) |
| Runbook | `docs/runbooks/queue-worker-activation-runbook.md` stale in three places (no business jobs; `systemctl restart` starts a stopped unit; smoke without `--process`) | DOCUMENTATION DRIFT (recorded) |
| SATUSEHAT sweep | `SubmitSatusehatItemJob` relies on a sweep that is not registered | DEFERRED (matters only once SATUSEHAT is enabled) |
| KTP prune timer | Not installed; stale temporary KTP scans are never pruned | OWNER DECISION (privacy retention) |
| `/usr/bin/php` | Resolves to `php8.3` on Biznet | VERIFIED SAFE on this host (the unit should still pin `php8.3`) |

## 4. Implementation

| File | Change |
|---|---|
| `app/Modules/RME/Services/PatientDoctorAssignmentService.php` | `createOrAdoptActive()` + `activeAssignment()`; both assignment paths use them; catch narrowed to `UniqueConstraintViolationException` |
| `app/Modules/MedicalRecord/Services/MedicalRecordService.php` | `createDraftOn($visit, $by, $data, adoptExisting)`: savepoint insert; adopt on the canonical path, refuse on `createDraft()`; `draftAlreadyExists()` |
| `app/Modules/ClinicVisit/Support/ClinicVisitSequenceLock.php` | **New.** Namespace `92_001`, `keyFor(branchId)` |
| `app/Modules/ClinicVisit/Interfaces/ClinicVisitRepositoryInterface.php`, `Repositories/ClinicVisitRepository.php` | `lockVisitSequence()`; `nextQueueNumber()` row lock removed and reads `withTrashed()` |
| `app/Modules/ClinicVisit/Services/ClinicVisitService.php` | `create()` takes the numbering lock first; `generateUniqueVisitNumber()` row lock removed; MAX and `exists()` read `withTrashed()` |
| `app/Modules/LegacyRme/Controllers/LegacyRmeImportController.php`, `app/Modules/LegacyOdontogram/Controllers/LegacyOdontogramImportController.php` | Staging byte routes answer 404 for a VOIDed record; `show()` passes `bytesRetracted` |
| `resources/views/settings/rme/legacy-imports/show.blade.php`, `resources/views/settings/legacy-odontograms/show.blade.php` | VOID notice replaces the source link and page grid |
| `app/Modules/LegacyRme/Services/LegacyRmePublishService.php` | Two false comments corrected (no behaviour change) |
| `app/Modules/RME/Services/DoctorPatientScopeService.php` | `own_visit` subquery excludes soft-deleted visits |
| `.github/workflows/foundation-evidence-gates.yml`, `config/ci_runner.php` | `RmeConcurrentFirstWrite` and `DoctorPatientScope` tokens in both critical variants; three suites declared mandatory |

## 5. Security and clinical review

The independent-review subagent could not be launched: auto mode refused the call twice because a hook rewrites the
call's input after review, and the refusal instructs not to retry. An adversarial self-review against the same
checklist was performed instead (PostgreSQL transaction semantics, lock order, regressions, IDOR / existence
oracles, other byte paths, sibling subqueries, test vacuity, PII). Result: **no CRITICAL, HIGH or MEDIUM**.

| Id | Finding | Disposition |
|---|---|---|
| LOW-1 | The R5-1 end-to-end test never asserted that its rival row was injected, so it could pass vacuously if the hook stopped firing | **Fixed**; mutation M13 proves it now fails without the injection |
| LOW-2 | The VOID tests asserted the staging screen loads, not that it shows the notice and stops linking the refused bytes | **Fixed** (`assertSee`/`assertDontSee`); mutations M11/M12 killed |
| INFO-1 | Staging guards test `isVoided()`; the record routes test `isPublished()`. Equivalent today (a record is PUBLISHED or VOID) | Recorded |
| INFO-2 | `UnifiedMedicalRecordIndexRepository`'s date filter subquery over `trx_clinic_visits` has no `deleted_at` clause; it filters a record's own visit by date and is not an authorization scope | Recorded |
| INFO-3 | A loser whose winner is unassigned between the failed insert and the re-read rethrows the unique violation (HTTP 500, nothing persisted) | Recorded; needs three concurrent requests |

**Independent review (the `code-review` skill, high effort, against PR #605 at `baba5b01`)** — run as a fork with
none of this sprint's conclusions. Nine findings; each was re-checked against the code before acting:

| # | Finding | Verified | Disposition |
|---|---|---|---|
| 1 | The three R5-1 race tests fail on SQLite: the partial active-assignment index is created only on pgsql; the file header claimed both drivers | Yes (migration creates it under `getDriverName() === 'pgsql'`). CI and production run pgsql, so CI was not affected | **Fixed**: those three tests skip off PostgreSQL with the reason; header corrected |
| 2 | Numbering MAX skipped soft-deleted visits while both unique indexes are full | Yes — reproduced RED (`UniqueConstraintViolationException`) | **Fixed** (P7-REL-02b, `withTrashed()`), new test |
| 3 | A soft-deleted medical record blocks the draft insert and the catch rethrows (HTTP 500) | Yes, latent (no delete path) | **Recorded** R7-2 (product decision: restore vs refuse) |
| 4 | Two first saves from two different visits of a book-less patient create two books | Yes (`resolveCanonicalWorkspaceVisit() ?? $contextVisit`) | **Recorded** R7-1; R6-1's closure scoped to the same canonical visit in rules 224/225 and here |
| 5 | Advisory-lock acquisition duplicated (ClinicVisit and legacy slot) | Yes | **Recorded** follow-up (a shared helper adds an app file; the SECURITY_FIX scope gate is at its limit) |
| 6 | Savepoint-adopt pattern now in three services | Yes | **Recorded** follow-up, same reason |
| 7 | `PatientDoctorAssignmentService` queries its model directly (CG-04) | Yes, pre-existing (base did the same; this change consolidated it) | **Recorded** follow-up |
| 8 | `ClinicVisitSequenceLock` imported a LegacyImport class only for a docblock `@see` | Yes (pint had turned the FQCN into an import) | **Fixed**: plain-text reference, import removed |
| 9 | The canonical path looks the draft up twice | Yes (micro-cost, one indexed query) | **Recorded** INFO |

Checked SAFE: savepoint nesting is always inside an outer transaction; the catch runs after `ROLLBACK TO SAVEPOINT`;
READ COMMITTED makes the committed winner visible; the active-assignment index is the partial unique the catch
expects; `create()` is always in a transaction; no cycle between the numbering lock and the merge guard, the
follow-up parent lock, the visit row lock, the handwriting lock or the legacy slot lock; the only numbering path is
`ClinicVisitService::create()`; the VOID check runs after scope resolution and authorization (out-of-scope stays
404); record viewer, print, export and batch adapters already refuse or never reach a VOIDed record; no new log line
or PII.

## 6. Tests

### 6.1 RED → GREEN (PostgreSQL 16, PHP 8.3 container)

| Suite | RED on base `daace3df` | GREEN |
|---|---|---|
| `tests/Feature/RME/RmeConcurrentFirstWriteIntegrityTest.php` (8) + `RmeConcurrentFirstWriteLockTest.php` (4, PostgreSQL only) | 9 of 12 failed for the intended reasons (2× 25P02 `QueryException`, R5-1 end to end, R6-1 ×3, three lock proofs); the 3 passes are the controls (non-unique failure surfaces, sequential numbering, another branch never waits) | 12 passed |
| New cases in `LegacyRmeRecordViewerTest`, `LegacyOdontogramAccessTest`, `DoctorPatientScopeTest` | 3 of 3 failed | 3 passed |
| Combined targeted run (new suites + their host files) | — | 78 passed, 0 failed, 0 skipped (JUnit) |

### 6.2 Regression

| Run | Result |
|---|---|
| 22 directories (`RME`, `DoctorAccess`, `AccessControl`, all seven `Legacy*`, `PatientMerge`, `Patient`, `ClinicOversight`, `RmePerformedTreatment`, `Pilot`, `Branch`, `DoctorDeviceWebAuthn`, `Satusehat`, `RmePaymentIdempotency`, `RmeManualPricing`, `RmeInvoiceCorrection`, `Auth`), PostgreSQL 16, PHP 8.3 container, 2026-11-06 08:22–09:09 UTC | **7,561 passed, 0 failed, 0 skipped** (31,946 assertions, 2,819 s, JUnit) |
| Final tree (after the scope reduction and the two test hardenings): `LegacyRme`, `LegacyOdontogram`, the two new suites, `DoctorPatientScopeTest` | **1,380 passed, 0 failed, 0 skipped** (3,941 assertions) |
| Governance on the host (git available): `DevflowAiInstructionSystemTest`, `CriticalGateSuiteCoverageTest`, `LegacyRmeProgramClosureContractTest` | 16 + 14 + 10 passed, 0 failed |
| `pest --list-tests` under the exact critical filter (2,300 characters; both variants identical) | 9,433 tests listed; selects 8 + 4 new concurrency tests, 32 `DoctorPatientScopeTest`, and the new legacy staging cases |
| After the independent review's fixes: every test file that registers a visit or asserts numbering (93 files) + the lock suite, PostgreSQL 16 | **1,730 passed, 0 failed, 0 skipped** (7,311 assertions) |
| The two new suites on SQLite (host) | 6 passed, 7 skipped with a stated reason (the 3 R5-1 race tests need the pgsql-only partial index; the 4 lock tests need advisory locks) |
| pint (changed PHP), `git diff --check`, `view:cache` | pass, clean, cached |

Counts come from JUnit: the worktree has no environment file, so Pest prints every test as a warning (the
missing-file notice), never as a pass.

### 6.3 Mutation matrix

Each mutant applied as a literal replacement, verified to have changed the file, run against its suite, reverted by
copy and checked byte-identical by sha256.

| Id | Mutant | Result |
|---|---|---|
| M1 | R5-1 insert without savepoint | KILLED |
| M2 | R5-1 adopt without asserting the winner exists (`?? null`) | SURVIVED — equivalent: the method's non-nullable return type turns `null` into a `TypeError`, which is still an error, exactly as the rethrow is |
| M3 | R6-1 refuse at the check on the canonical path | KILLED |
| M4 | R6-1 draft insert without savepoint | KILLED |
| M5 | R5-2 no numbering lock | KILLED |
| M6 | R5-2 lock taken after the numbers are read | KILLED |
| M7 | Legacy RME staging source not guarded | KILLED |
| M8 | Legacy RME staging page not guarded | KILLED |
| M9 | Legacy odontogram staging page not guarded | KILLED |
| M10 | Scope counts a soft-deleted visit again | KILLED |
| M7–M9 (re-run) | The same three staging guards after the scope reduction (record `isVoided()` read in the controllers) | KILLED |
| M11 | Legacy RME staging view keeps the page grid after VOID | KILLED |
| M12 | Legacy odontogram staging view keeps the page grid after VOID | KILLED |
| M13 | Test mutant: the R5-1 end-to-end race never injects its rival | KILLED — proves that test cannot pass vacuously |

| M14 | Queue-number MAX skips soft-deleted visits again | KILLED |
| M15 | Visit-number MAX skips soft-deleted visits (its `exists()` loop still sees them) | SURVIVED — equivalent: the loop independently steps past a soft-deleted number; deliberate redundancy |
| M16 | Visit-number MAX **and** its `exists()` loop both skip soft-deleted visits | KILLED — so the protection is covered |

Final: 16 distinct mutants (M7–M9 run twice), 14 killed, 2 equivalent (M2, M15), 0 real survivors. Blade mutants clear the compiled views on apply
and stamp the restored file's mtime (a `copy2` restore keeps the old mtime and Blade would keep serving the mutant).

## 7. Foundation rule synchronisation

New rule 225; cross-references in rules 93 §7, 174 §6, 223 §19 (drift), 224 (R6-1 closed); domain files
`rme-clinical.md` (AIR-RMC-44 + index row), `legacy-rme.md` (AIR-LRM-20 + index row), `doctor-access.md` (AIR-DAC-40
+ index row), `rme-billing.md` (stale residual); knowledge-base module index. Governance tests green (§6.2):
**FOUNDATION RULE SYNC — GO**.

## 8. CI, merge, deploy and production verification

### 8.1 CI

| Run | SHA | Result |
|---|---|---|
| 42194218143 | `baba5b01` | Superseded by the review fixes and cancelled (Classifier, Quality, Selective Module and Android gates had passed) |
| 42198790347 | `6bf1865e` | **FAILED** — NSF-R011 Critical Test Gate: 2 failed, 9,429 passed. Both failures were `CriticalGateControlledParallelismContractTest` ("appends the resolved arguments after the untouched filter in both variants"): it pins that the critical filter ENDS with `InventoryClinicCalendarBoundary'`, and this sprint had appended its two tokens after it. No product test failed. Fixed by moving the tokens before the trailing calendar-boundary tokens (filter content unchanged otherwise); all of `tests/Feature/Cicd` then passed locally (386 passed, 0 failed). New domain rule AIR-CIT-34 records the trap |

Recorded as the run completes.

## 9. Cleanup

Recorded at the end.
