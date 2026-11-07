# DMS-RME-REVISION-PROGRAM-8 — final readiness re-audit after Program 8A (Stage A)

**Status: STAGE A COMPLETE — `PRE_FULL_SUITE_READINESS = NO-GO`.** No code release blocker remains: P8-N1, P8-N3 and
the critical-gate coverage gap are re-verified closed on the deployed 8A release. Readiness still cannot be GO because
the owner decisions that gate it are unanswered (P8-N2, the numbering class, the remaining register) and the
dedicated-device test cannot run (no dedicated device). No Full Suite was requested or run. No application, test or
CI semantics changed. No deploy. No GO tag.

This record re-runs [[dms-rme-revision-program-8]] (initial readiness, NO-GO — unchanged history) on the corrective
release [[dms-rme-revision-program-8a]] (GO, `dms-rme-revision-program-8a-go`). A Program 8A GO is not
`dms-rme-final-readiness-go`, and neither is this record.

| Verdict | Value |
|---|---|
| `PRE_FULL_SUITE_READINESS` | **NO-GO** |
| `READY_TO_REQUEST_NEW_AUTHORITATIVE_FULL_SUITE_AUTHORIZATION` | **false** |
| `RME_PROGRAM_FINAL_STATUS` | **NO_GO** (Stage B not reachable) |
| Code release blockers | **0** (Program 8: 2) |
| Owner decisions open | 29 rows in a 55-row register; P8-N2, the numbering class and three new MEDIUM items gate readiness (D1, D2, D6, D7) |
| `DEDICATED_DEVICE_VERIFICATION` | **BLOCKED** (`LIVE_ASSERTION_PROOF=NEVER_PROVEN`) |
| `CLINIC_REOPENING_AUTHORIZED` | **NO** (separate owner decision) |
| `FULL_SUITE_EXECUTION_COUNT` (this mission) | 0 — never dispatched (CG-18 ACTIVE; no authorisation requested while readiness is NO-GO) |
| `RUNTIME_APPLICATION_FILES_CHANGED` / `TEST_SEMANTICS_CHANGED` / `CI_SEMANTICS_CHANGED` | 0 / false / false |

Times are UTC; clinic time WITA = UTC+8. Run on 2026-11-07 from 00:13 UTC (08:13 WITA).

## 1. Scope and authority

Mission `DMS-RME-REVISION-PROGRAM-8-READINESS-REAUDIT` (owner brief, 2026-11-07). Stage A (readiness, measurement)
is authorised. Stage B (authoritative Full Suite, merge, deploy, final GO tag) needs a separate explicit owner
authorisation (rules 107, 125, 126) and was not started. Rule 125 §1 governs: this audit measures and repairs no
application code.

| Item | Measured |
|---|---|
| Base tip (remote, `devflow:base-ref-check --strict`) | `2d445996` (PR #609); local base ref stale at `9eabff07`, not used |
| Production | Biznet `DaengtisiaMS`, `PRODUCTION_TARGET=VERIFIED`; HEAD `c0e12382`, tree `e6baa722`; 0 tracked dirty files (one pre-existing untracked runtime directory, INFO RA-3) |
| Base vs production | base = production + one docs-only commit; `app`, `tests`, `config`, `routes`, `resources`, `database`, `bootstrap`, `composer.lock`, `.github` tree objects identical |
| Last deploy | `20261106-230625`: `verify` → `final exit=0`, `DEPLOY RUNNER OK` |
| GO tags 1–7 and 8A | all eight annotated, each peels to its runtime commit, all ancestors of the base; `dms-rme-final-readiness-go` absent (correct) |
| Owner decisions recorded since Program 8A | none (repository and private records searched) |

DEVFLOW (canonical remote base): `devflow:base-ref-check --strict`, `sprint:manifest-check`,
`sprint:scope-audit --strict`, `foundation:devflow-check --strict`, `foundation:shared-service-audit --strict` all GO;
`sprint:test-plan` → no impacted category, no escalation. `.sprint/current.yml` is unchanged (still Program 8A): it
belongs to the frozen final candidate, which does not exist while readiness is NO-GO (same as Program 8).

## 2. Program 8A corrections re-verified

| Item | Re-verification | Result |
|---|---|---|
| P8-N1 prescription authoring authority | PG 16 + PHP 8.3.33 regression incl. `RmePrescriptionAuthoringAuthorityTest`; independent review (§6); production code markers (8A deploy evidence) | **CLOSED** |
| P8-N3 clinic-room form | `BladePhpDirectivePairingTest` (every template, real parse) + ClinicMasterData suites on PHP 8.3.33 | **CLOSED** |
| Critical-gate coverage (CG-19) | tokens `RmePrescription`, `ClinicMasterData`, `BladePhpDirectivePairing` in both runner variants (filters byte-identical); `--list-tests` on the exact CI filter selects 9,615 tests, incl. 33 + 10 prescription, 136 ClinicMasterData, 5 pairing-guard tests | **INTACT** |
| 8A CI history | candidate run `42301244552` on `adbd0122` (tree = merged tree): every required gate success, critical gate 9,615 passed. Post-merge push run `42309524995` was cancelled by the workflow's own `concurrency: cancel-in-progress` when the docs commit was pushed 13 min later; its Full Suite job has zero duration (never executed). Not a gate gap, never counted as PASS (RA-1) | **PASS** |

## 3. Findings

### 3.1 Code release blockers

None.

### 3.2 Owner decisions that gate readiness

| Id | Severity | Summary | Recommended |
|---|---|---|---|
| **D1 — P8-N2** | MEDIUM | Clinical document read-scope hardening (detail in the private security record; unchanged by 8A, which touched writes only) | fix now in a small corrective sprint; or accept as intended same-organisation read |
| **D2 — numbering class** (OD-7-8 + OD-5-8 + P8-N4) | LOW (availability; latent high blast radius) | RME invoice, RME payment and consent numbers: month-wide `MAX + 1` / `orderByDesc` without serialisation (concurrent loser: HTTP 500, full rollback, resubmit works); invoice/payment `MAX` ignores soft-deleted rows while the unique index counts them (a trashed top number would refuse every later number that month). Production: 0 trashed invoices, 0 trashed payments, no application delete path. Code unchanged since Program 8 | fix all three generators with the rule 225 advisory-lock pattern and count trashed rows (one sprint; also clears the Program 8 browser failure) |
| **D3 — remaining register** | LOW / INFO | further owner-decision rows (OD-5-*, OD-6-*, OD-7-1…OD-7-4, OD-8A-1, OD-8A-2, R8A-1, R8A-3, OPS-KTP, two new LOW hardening items) | accept as documented residuals in one decision, folding OD-7-2/R8A-1 into D1's sprint |
| **D6 — printed prescription identity** (new) | MEDIUM | Printed prescription identity is not fully server-derived, although the stored record identity is (rule 226 §6). Detail in the private record | derive the printed identity from the visit server-side; or accept with an audit row (OD-8A-2) |
| **D7 — service-layer hardening** (new, latent) | MEDIUM | Defence-in-depth gap in the service-layer re-assertion of the clinical authoring authority, plus a missing CI guard for future roles. Not reachable today (route and policy enforce it); rule 226 §1.3 states the stronger guarantee, so it is reported as rule drift. Detail in the private record | close it in code with a role-matrix test (no behaviour change for any current role) |

Classification of D2 under the brief's four classes: **OWNER DECISION REQUIRED**. It is not declared acceptable
merely because the unique indexes prevent a duplicate committed number.

### 3.3 Operational readiness

| Id | Finding | Class |
|---|---|---|
| OPS-BK2 | `ON_SERVER=OK`, `OFF_SERVER=OK`, pending 0, alerts none (pulls through 01:03:55 UTC); HDD identity chain verified. The workstation rebooted at 05:41 UTC and the backup HDD is locked again: nothing is pending now, but the next nightly package will wait for the custodian, and a Stage B deploy preflight must re-check BK-2 | **CLOSED** for the measured window · custodian unlock needed before the next pull |
| OPS-RD | Isolated restore drill of the newest off-server package with the documented BK-2 procedure (workstation only, throwaway PostgreSQL 16, no network, tmpfs, removed afterwards): **PASS** — row counts and content fingerprints identical for all 180 tables, documents 221/221. Production `rollout:restore-drill-evidence` still WATCH: that artifact is written only by a staging drill script that refuses production (RA-5) | **CLOSED** (DR capability) · WATCH (artifact) |
| OPS-DEV | No dedicated, normally-enrolled test device; `LIVE_ASSERTION_PROOF=NEVER_PROVEN` (no WebAuthn assertion on this host yet). One registered device named as a test tablet is unenrolled (0 credentials, 0 authorizations) | `DEDICATED_DEVICE_VERIFICATION = BLOCKED` (owner decision D4) |
| OPS-Q | Worker inactive/disabled by policy; legacy flags ON, so a new legacy PDF upload would stall at QUEUED (0 queued today). Timing residuals unchanged (`retry_after` 90 s < worker `--timeout` 120 s; `TimeoutStopSec=150`) | operational blocker for legacy uploads only |
| RA-2 | Legacy WAVE-4 planned window ended 2026-10-31 while the batch is still in progress (`legacy-rme:ops-readiness` WATCH) | owner/operator decision D5 |
| OPS-KTP | KTP prune timer not installed | owner decision (privacy retention) |
| Performance | 15 sequential samples (not load): `/login` p50 156 ms / p90 194 ms; `/health/ready` p50 159 ms / p90 178 ms; host idle (load 0.00, 17 % disk) | NOT ESTABLISHED — no approved load target |

## 4. Production verification (read-only, 2026-11-07 00:20–00:33 UTC)

| Check | Result |
|---|---|
| `/login`, `/health/live`, `/health/ready`, `/health/lb` over `https://daengtisia.online` | 200 ×4 |
| `/storage/<synthetic>` | 403 |
| Guest GET `/rme/visits`, `/settings/clinic-rooms/create` | 302 → login |
| Pending migrations, failed jobs, jobs | none, none, 0 |
| 20 table counts + newest prescription / room rows + last migration batch | **identical** to the 8A post-deploy baseline |
| `laravel.log` | 1,467,295 bytes / 166 `ERROR`, last written 2026-11-03 (pre-Biznet) — unchanged before and after every read |
| Environment | `pilot`, debug OFF, maintenance OFF, PHP 8.3.6 |
| Flags | doctor enforcement, WebAuthn device login, single session, front-office branch context lock, legacy archives, performed treatments ON; branch lock, front-office device lock, SATUSEHAT OFF |
| Doctor enforcement scope | `pilot`, 3 covered, `GLOBAL_ENFORCEMENT_ACTIVE=false`, `SCOPE_VERDICT=GO` |
| Services / timers | nginx, php8.3-fpm, postgresql active; worker inactive/disabled; db-backup and BK-2 package timers active, last runs `success` |

Not verified in production: authenticated clinical workflows (no approved test identity or dedicated device in this
audit; clinic devices frozen). No production data was written.

## 5. Tests

Counts come from JUnit: the worktree has no environment file, so Pest prints every test as a warning. "Pass" below
means no failure, no error and no skip in JUnit. Each run used a process-scoped disposable APP_KEY and a disposable
PostgreSQL container on the workstation; nothing pointed at production.

| Run | Environment | Scope | Result |
|---|---|---|---|
| 1 — dependency regression | PostgreSQL 16.15 + PHP 8.3.33 (CI image) | `RME`, `ClinicMasterData`, `AccessControl`, `LegacyRme`, `LegacyOdontogram`, `Storage`, `DoctorAccess`, `Ui/BladePhpDirectivePairingTest` | **5,462 tests, 21,884 assertions, 0 failed, 0 errors, 0 skipped** (incl. `RmePrescriptionAuthoringAuthorityTest` 33, `RmePrescriptionTest` 10, `BladePhpDirectivePairingTest` 5, ClinicMasterData 136; the PostgreSQL-only concurrency suites ran, none skipped) |
| 2 — billing, oversight, patient, legacy batch, branch, device | same | `RmeBillingDispute`, `RmeInvoiceCorrection`, `RmeManualPricing`, `RmePaymentIdempotency`, `RmePerformedTreatment`, `RmeRefundRequest`, `RmeRefundSettlement`, `RmeTariffAuthority`, `RmeZeroValueClosure`, `ClinicOversight`, `Patient`, `PatientMerge`, `LegacyBatchReview`, `LegacyBatchPublish`, `LegacyMassUpload`, `LegacyImportHub`, `Clinical`, `Branch`, `BranchScope`, `DoctorDeviceWebAuthn`, `FrontOfficeDevice` | **2,664 tests, 11,726 assertions, 0 failed, 0 errors, 0 skipped** (first attempt was killed by a workstation reboot at 05:41 UTC; re-run in full) |
| 3 — governance and documentation scanners, after the doc change | host PHP 8.5.4 | `tests/Feature/Cicd` (incl. `CriticalGateSuiteCoverageTest` 14), `DevflowAiInstructionSystemTest` 16, `ProductionShellCommandGuardTest` 26, `Ent15EnterpriseDocumentationRunbookTest` 16 | **428 tests, 4,864 assertions, 0 failed** |
| Critical-gate selection | `--list-tests` with the exact CI filter | both runner variants (identical) | 9,615 tests selected |

Total: 8,554 tests, 0 failures. Not run: the Full Suite (CG-18, not authorised; the bounded runs above cover the RME
programme's dependency surface, not the whole repository); the Dusk browser suite (not re-run: the three Program 8
browser failures sit in code 8A did not change — two test-fixture, one a reproduction of P8-N4, see D2); load
testing (no approved target).

## 6. Independent review

A separate read-only `general-purpose` subagent reviewed the prescription write path, the clinical read scope, the
numbering generators and the Blade pairing guard. It received file pointers, the canonical requirements and the
recorded residuals, but not the auditor's conclusions or verdict. The `context-mode` hook that rewrites `Agent` calls
was handled as in Programs 8/8A (re-issue the rewritten input once so auto mode reviews what runs); no hook was
disabled. Every finding was then verified against source by the auditor.

Result: **no CRITICAL, no HIGH.** The HTTP write boundary for prescriptions holds (P8-N1 closed); the template guard
covers every app-owned view (P8-N3 closed). New: three MEDIUM items (D6, D7 — two of them in D7) and two LOW
hardening items, all recorded in the private register; P8-N2's scope is confirmed to extend to odontogram view/print.
The reviewer would classify the concurrent-numbering race as an acceptable documented residual; this audit keeps it
as an open owner decision (D2) because the owner was asked to decide it and has not. Self-review was not substituted
for this review.

## 7. Foundation rule synchronisation

Rule 226 is the newest canonical rule; rules 221–226 and their `.claude/rules` index lines were re-read against the
code. No two rules contradict each other. One drift: rule 226 §1.3 states that a caller skipping the policy "is
refused the same way", which the service predicate does not fully deliver (D7, latent). It is **not** corrected in
place: rewriting the sentence to match the code would record a weaker invariant than the rule intends, and the real fix
is code, which a readiness audit may not make (rule 125 §1). `FOUNDATION RULE SYNC = WATCH` until D7 is closed. No rule
changed. Documentation changes are limited to this record, forward references in the Program 8 and 8A records, and
module-index links; the governance and documentation scanners pass on them (§5 run 3).

## 8. Checkpoint — what the owner must decide

1. **D1** P8-N2 — fix now (corrective sprint, RED tests first) or accept. The same read pattern covers the
   odontogram view/print, so the decision should name it too.
2. **D2** numbering class — fix now (one sprint for all three generators) or accept.
3. **D3** remaining register — accept as documented residuals in one decision, or name rows to fix.
4. **D4** dedicated-device test — provide one dedicated test tablet and test identity, enrolled normally; or accept
   untested hardware as a recorded risk (it then closes as ACCEPTED RISK, never as PASS).
5. **D5** Legacy WAVE-4 — close the batch or record a fresh approval extending its window.
6. **D6** printed prescription identity and **D7** service-layer hardening — fix now (they fit one corrective sprint
   with D1) or accept.
7. Only after 1–4 and 6 are answered (and any "fix now" sprint is merged, deployed and verified), re-run this readiness
   audit; only if it returns GO, authorise exactly one authoritative Full Suite for the frozen candidate.

## 9. Files changed

`docs/sprints/dms-rme-revision-program-8-readiness-reaudit.md` (this record),
`docs/sprints/dms-rme-revision-program-8.md` and `docs/sprints/dms-rme-revision-program-8a.md` (forward references),
`docs/knowledge-base/module-index.md` (links). Private evidence: `~/DaengtisiaMS-Backup/dms-rme-revision-program-8-reaudit/`.
