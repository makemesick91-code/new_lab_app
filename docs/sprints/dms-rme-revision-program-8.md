# DMS-RME-REVISION-PROGRAM-8 — end-to-end regression, security audit and final readiness (Stage A)

**Status: STAGE A COMPLETE — `PRE_FULL_SUITE_READINESS = NO-GO`.** Two release blockers (P8-N1, HIGH; P8-N3,
MEDIUM) need a separate corrective sprint before the final candidate can be frozen. No Full Suite was requested or
run. No application, test or CI semantics changed. No deploy. No GO tag.

> **Forward reference (2026-11-07, added by the corrective sprint; the verdict above is unchanged history).** P8-N1,
> P8-N3 and the §2.4 coverage gap are corrected in [[dms-rme-revision-program-8a]] (rule 226) — see that record for
> its own release status. Closing them does not turn this NO-GO into a GO: the owner decisions, the BK-2 off-server
> delivery, the restore drill and the dedicated-device test still stand, and final readiness must be re-run.

| Verdict | Value |
|---|---|
| Engineering programme verdict | **DMS-RME-REVISION-PROGRAM-8 — NO-GO** (readiness gate failed on a verified release blocker) |
| `PRE_FULL_SUITE_READINESS` | **NO-GO** |
| `READY_TO_REQUEST_NEW_AUTHORITATIVE_FULL_SUITE_AUTHORIZATION` | **false** |
| `RME_PROGRAM_FINAL_STATUS` | **NO_GO** (final closure not reachable until P8-N1 and P8-N3 are closed and the register is decided) |
| `CLINIC_REOPENING_AUTHORIZED` | **NO** |
| `FULL_SUITE_EXECUTION_COUNT` (this mission) | 0 — never dispatched (CG-18 ACTIVE; no authorisation requested while readiness is NO-GO) |
| `RUNTIME_APPLICATION_FILES_CHANGED` / `TEST_SEMANTICS_CHANGED` / `CI_SEMANTICS_CHANGED` | 0 / false / false |

Times are UTC; clinic time WITA = UTC+8. Run on 2026-11-06 from 14:40 UTC (22:40 WITA).

## 1. Scope and authority

Mission `DMS-RME-REVISION-PROGRAM-8` (owner brief, 2026-11-06): final readiness audit after Programs 1–7. Stage A
(readiness, read-only) is authorised; Stage B (authoritative Full Suite, merge, deploy, final GO tag) needs a
separate explicit owner authorisation (rules 107, 125, 126) and was not started. Rule 125 §1 governs: an audit that
finds a defect needing an application change stops, records it and returns NO-GO; the fix belongs to its own sprint.

| Item | Measured |
|---|---|
| Base tip (remote, `devflow:base-ref-check --strict`) | `63c15ac7`, tree `8a50a110` (local base ref stale at `9eabff07`, not used) |
| Production | Biznet `DaengtisiaMS`, `PRODUCTION_TARGET=VERIFIED`; HEAD `9352457e`, tree `2f607586`, 0 tracked dirty files |
| Base vs production | base = production runtime + one docs-only commit (PR #606) |
| Last deploy | `20261106-134517`: `verify` → `final exit=0`, `DEPLOY RUNNER OK` |
| Programmes 1–7 | all seven GO tags annotated, peel to their runtime commits, ancestors of the base; each runtime commit's push run green, and on `9352457e` the critical step `Run critical regression tests` actually executed |
| Last Full Suite PASS | run `40981123068` (2026-10-30) — predates every Program 1–7 change; no Full Suite covers the current runtime |

DEVFLOW (canonical remote base): `devflow:base-ref-check --strict` GO, `sprint:manifest-check` GO,
`sprint:scope-audit --strict` GO, `foundation:devflow-check --strict` GO, `foundation:shared-service-audit --strict`
GO (0 errors, 0 warnings). `sprint:test-plan` escalated only because of this audit's own untracked test artifacts,
which were moved out of the worktree.

## 2. Findings

### 2.1 Release blockers (corrective sprint required)

| Id | Severity | Summary | Disposition |
|---|---|---|---|
| **P8-N1** | **HIGH** | Prescription authoring authority hardening: the prescription write path does not yet enforce an authorisation invariant that the canonical clinical-workflow rule already requires for the other clinical documents. Found by the independent reviewer, verified against source, reproduced on PostgreSQL 16 (§4). A read-only check of production found no evidence of misuse. | **RELEASE BLOCKER.** Corrective sprint (proposed `DMS-RME-REVISION-PROGRAM-8A`; needs owner approval as a security change under rule 221). Mechanism, preconditions and fix design are held in the private security record, not in this public repository. |
| **P8-N3** | **MEDIUM** | `resources/views/settings/clinic-rooms/_form.blade.php` opens with an inline `@php(...)` followed by a block `@php … @endphp`. Blade's block extractor (`/(?<!@)@php(.*?)@endphp/s`) pairs the inline opener with the block's `@endphp`, so the compiled template starts with `<?php(` (no whitespace). PHP 8.3.6 (production) and 8.5 do not lex that as an open tag: the block is printed as literal text at the top of the clinic-room create/edit form and the label arrays are never defined (dropdowns fall back to raw codes). PHP 8.3.33 — what CI's `setup-php '8.3'` installs — lexes it as an open tag and fails with `ParseError`, HTTP 500. Measured: all 537 compiled templates linted under PHP 8.3.33 → exactly this one fails; `<?php(` occurs in exactly this one; `ClinicRoomTest › lets a manager open the create page` fails deterministically on PHP 8.3 + PostgreSQL 16. `view:cache` reports success because it compiles without linting. | **RELEASE BLOCKER**: the authoritative Full Suite would fail deterministically, and production shows raw template text on two master-data screens. Fix belongs in the same corrective sprint (one template change plus a guard that lints compiled views). Not a security issue. |

### 2.2 Hardening item needing an owner decision

| Id | Severity | Summary | Recommended |
|---|---|---|---|
| **P8-N2** | MEDIUM | Clinical document read-scope hardening: the branch scope applied to some clinical document reads was never explicitly decided for every role. Read-only; no write is possible through it. | Owner decision; recommended to settle it inside the same corrective sprint. Detail in the private security record. |

### 2.3 Re-measured residuals (no new code; classifications from the independent review, each verified)

| Id | Result | Class |
|---|---|---|
| R7-1 | Rule 225's description was **wrong**: `resolveCanonicalWorkspaceVisit()` exists whether or not a book does, so the described race converges; residual narrowed to the earliest visit changing between two requests, or a patient merge leaving the book on a later visit. Rule 225 corrected in place; Program 7 record gains a forward reference. | ACCEPTABLE DOCUMENTED RESIDUAL (LOW→INFO) |
| R7-2 | No application path soft-deletes a medical record (grep, FK `restrictOnDelete`, patient merge re-points, never deletes) | VERIFIED SAFE (latent) |
| OD-7-7 | Newest-wins for the overlap of one in-flight request; no persistent dual session, no authentication bypass | OWNER DECISION (LOW) |
| OD-7-8 + OD-5-8 | One defect class: month-wide `MAX + 1` numbering without serialisation in three generators (RME invoice, RME payment, consent). Unique indexes refuse duplicates; the loser gets HTTP 500, the whole transaction rolls back, the signed submission intent is not consumed, a resubmit succeeds. Numbers are estate-wide, not per branch. Visit numbering was fixed in Program 7 (rule 225 pattern). | OWNER DECISION (LOW, availability only) — recommended to serialise with the rule 225 advisory-lock pattern before multi-branch cashier operation |
| **P8-N4** (new, latent) | Same generators, sequential variant — the R5-2b defect Program 7 fixed for visits. `RmeInvoice` and `RmePayment` use `SoftDeletes`; `latestInvoiceNumberForMonth()` / `latestPaymentNumberForMonth()` ignore trashed rows while both unique indexes are full. One soft-deleted top-numbered row makes every later invoice (or payment) in that month collide, estate-wide. Reproduced in this audit's browser run: a smoke test's cleanup soft-deleted `RME-202611-000001` and the next invoice failed with `UniqueConstraintViolationException`. No application path soft-deletes an invoice or payment today. | OWNER DECISION (LOW likelihood, high blast radius) — recommended to fix with OD-7-8 (`withTrashed()` + serialisation) |
| OD-6-2 | Consent signature stream has no explicit `no-store` (framework `no-cache, private`). The reviewer also named the prescription canvas stream; **that was wrong** — it already sends `private, no-store, max-age=0`. | OWNER DECISION (LOW) |
| R6-2 | Evidence disk is not configurable from the environment; a public disk would need a code change | VERIFIED SAFE (INFO) |
| Patient MRN | No generator to race: the sequence is operator-supplied and the composed number is globally unique | VERIFIED SAFE |

All other carried owner decisions (OD-5-1…OD-5-10, OD-6-1, OD-7-1…OD-7-6) were re-read against the runtime and are
unchanged; the full register is in the private evidence package.

### 2.4 Coverage gap (CG-19)

The required NSF-R011 critical gate selects 9,431 tests; it selects **no** prescription behaviour test and **no**
`ClinicMasterData` test. Those suites run only in the Full Suite, which has not run since 2026-10-30. The corrective
sprint must add a critical-gate token for the prescription suite (and declare it in
`config/ci_runner.php` `critical_gate_mandatory_suites`).

### 2.5 Operational readiness (not code; blocks Stage B or clinic operations)

| Id | Finding | Class |
|---|---|---|
| OPS-BK2 | BK-2 packaging and the nightly `pg_dump` succeed on the host, but the off-server pull reports `OFF_SERVER=PENDING` since 2026-11-05 18:59 UTC: the encrypted backup HDD is locked (the pull never unlocks it by design). Last off-server copy 2026-11-04 18:59 UTC. | OPERATIONAL BLOCKER for any Stage B deploy preflight — custodian action |
| OPS-RD | `rollout:restore-drill-evidence` → WATCH, `evidence_absent`. No restore was run to manufacture it. | DR WATCH |
| OPS-Q | Queue worker inactive/disabled by owner policy; a new legacy upload would stay `QUEUED`. Program 7 OPS-01 residuals unchanged (`retry_after` 90 s and `TimeoutStopSec=150` below the 180 s render timeout; unit runs unpinned `/usr/bin/php`). | OPERATIONAL BLOCKER for legacy archive operations only; nothing started |
| OPS-DEV | No dedicated test device was available to this audit; `webauthn:readiness` reports `LIVE_ASSERTION_PROOF=NEVER_PROVEN` within the 7-day window. | `DEDICATED_DEVICE_VERIFICATION = BLOCKED` (policy §7 criterion 5) |
| OPS-KTP | KTP temporary-scan prune timer not installed | OWNER DECISION (privacy retention) |

## 3. Production verification (read-only, 2026-11-06 14:44–15:00 UTC)

| Check | Result |
|---|---|
| `/login`, `/health/live`, `/health/ready`, `/health/lb` over `https://daengtisia.online` | 200 ×4; `/health/ready` all components ok |
| `/storage/<synthetic>` | 403 |
| Guest GET `/rme/visits`, `/rme/patient-queue`, `/rme/cashier`, `/settings/rme/legacy-imports` | 302 → `/login` |
| Pending migrations, failed jobs, jobs | 0, 0, 0 |
| 24 table counts (`db:show --counts`) | identical to the Program 7 post-deploy fingerprint |
| `laravel.log` | 1,467,295 bytes / 166 `ERROR`, unchanged before and after every read; newest `ERROR` 2026-11-03 06:40:01 (pre-Biznet, Hostinger era) — **zero ERROR lines since Biznet go-live** |
| Flags | unchanged (doctor enforcement, WebAuthn login, single session, front-office branch context lock, legacy archives, invoice correction, performed treatments ON; branch lock, post-payment correction, refunds, zero-value closure, SATUSEHAT OFF) |
| Doctor enforcement scope | `pilot`, cohort 3, `GLOBAL_ENFORCEMENT_ACTIVE=false`, `SCOPE_VERDICT=GO` |
| Legacy | WAVE-4 ACTIVE, accepting, 0 pending render jobs, findings none |
| Worker / scheduler / timers | worker inactive/disabled; pilot snapshot timer inactive/disabled; KTP timer not installed; db-backup and BK-2 timers active, last runs `Result=success` |
| Public latency baseline (15 sequential samples, not load) | `/login` p50 166 ms / p90 175 ms; `/health/ready` p50 173 ms / p90 195 ms |

Not verified in production: authenticated clinical workflows (no approved test identity in this audit; clinic devices
frozen). No production data was written.

## 4. Tests

Counts come from JUnit: the worktree has no environment file, so Pest prints every test as a warning. "Pass"
below means no failure, no error and no skip in JUnit.

| Run | Environment | Result |
|---|---|---|
| Bounded RME domain regression — 37 directories: `RME`, `Patient`, `PatientMerge`, `Branch`, `BranchScope`, `Clinical`, `ClinicMasterData`, `AccessControl`, `Auth`, `DoctorAccess`, `DoctorDevice`, `DoctorDeviceWebAuthn`, `FrontOfficeDevice`, `Pilot`, `ClinicOversight`, `RmePerformedTreatment`, the nine `Rme*` billing directories, `Invoice`, the seven `Legacy*`, `Storage`, `Security`, `Queue`, `Satusehat` | PostgreSQL 16.15 + PHP 8.3.33 container, 14:42–15:51 UTC | **9,402 tests, 39,555 assertions, 0 skipped, 10 failed** |
| — 9 of those failures | DoctorDevice Android release-governance suites read the git index; the container cannot resolve the worktree's `.git` link | **Environment artifact**: the six files re-run on the host (git available): **182 tests, 808 assertions, 0 failed, 0 skipped** |
| — 1 failure | `ClinicRoomTest › lets a manager open the create page` (`ParseError`, HTTP 500) | **Real defect P8-N3**; deterministic on PHP 8.3.33 in isolation; passes on PHP 8.5 |
| Mandatory-suite reconciliation (rule 125 §8): the 40 registry suites outside the domain scope (they include the governance, production shell-command guard, host-identity and deploy-evidence doc scanners) + 4 more doc/governance suites (enterprise runbooks, runtime hardening, critical-gate suite coverage, legacy programme closure contract) | host PHP 8.5, in-memory SQLite (git available) | **832 tests, 9,237 assertions, 0 failed, 0 skipped** |
| P8-N1 / P8-N2 reproduction probe (temporary, uncommitted, removed by exact path) | PostgreSQL 16 + PHP 8.3 | **4 of 4 reproduce** |
| Browser (Dusk 18 files, Chrome 149 headless) against an isolated server and a disposable seeded database | PHP 8.3 server, PostgreSQL 16 | 30 tests: **27 pass, 0 skip, 3 fail** after giving the 5 visit-dependent smoke tests synthetic local fixtures (first pass: 23 pass, 5 skip, 2 fail) |
| — 2 browser failures | `SunuBranchCodeLookupBrowserTest` ×2: the fixture's operator gets no branch scope, so on this database the screen correctly refuses SPN4 ("Akun Anda tidak memiliki akses ke cabang tersebut") | **Test-fixture defect** (environment-dependent `BranchContext` fallback); product behaviour correct, fail-closed |
| — 1 browser failure | `RmeControlCashierSmokeTest`: invoice-number collision after the previous smoke test soft-deleted its invoice | **Reproduction of latent defect P8-N4** (and a test-order dependency in the smoke suite) |
| Whole compiled view tree | PHP 8.3.33 `view:cache` + `php -l` on every compiled file | 537 templates, **1 parse error** (P8-N3) |

Not run: the Full Suite (not authorised); authenticated production workflows; load testing (no authorised
staging target). Also recorded: the development `DatabaseSeeder` chain stops at `ProductionSeeder` (dental-lab demo
data refused by technician eligibility) — development-only, INFO.

## 5. Independent review

Program 7 could not launch a separate reviewer. Cause identified: the `context-mode` plugin (v1.0.162) registers a
`PreToolUse` hook on `Agent` that returns `updatedInput`, rewriting a subagent's prompt after auto mode reviewed it.
Mechanism used here: the auto-mode refusal returns the rewritten input; re-issuing exactly that input once lets auto
mode review what actually runs. No hook was disabled. A separate read-only general-purpose subagent, given file
pointers and questions but none of the auditor's conclusions, reviewed six recorded residuals and hunted for
unrecorded issues. Each of its findings was then verified against source by the auditor; one was wrong (the
prescription canvas cache header) and is recorded as such. Result: 1 HIGH (P8-N1), 1 MEDIUM (P8-N2), the rest as in
§2.3. Cashier, invoice and payment screens: no issue found.

## 6. Foundation rule synchronisation

- Rule 225 R7-1 corrected in place (wrong description, see §2.3); Program 7 record gains a forward reference.
- `docs/runbooks/queue-worker-activation-runbook.md`: three stale statements corrected (the deploy runs no
  `queue:restart`; re-installing the unit now uses `try-restart`, because a plain `restart` starts a deliberately
  stopped worker; "no business jobs" replaced by the legacy render and SATUSEHAT jobs and the stalled-import
  consequence). No policy changed: the worker stays OFF.
- No new durable rule. Rules 221–225 re-read: no contradiction found. The P8-N1 fix will need a canonical rule
  amendment in its own sprint.

## 7. Checkpoint — what the owner must decide

1. Approve corrective sprint `DMS-RME-REVISION-PROGRAM-8A` for the two release blockers: P8-N1 (security change,
   rule 221) and P8-N3 (template defect), plus critical-gate coverage for the prescription and master-data suites.
2. Decide P8-N2 (clinical document read scope) — include in that sprint or defer.
3. Decide the numbering class (OD-7-8 + OD-5-8 + P8-N4) — fix now or accept as a documented residual.
4. Decide the remaining register (private package) — accept, fix later, or fix now.
5. Custodian: unlock the BK-2 backup HDD so the pending off-server copy completes.
6. Only after 1–4 are closed and readiness is re-run to GO: authorise exactly one authoritative Full Suite.

## 8. Files changed

`docs/sprints/dms-rme-revision-program-8.md` (this record), `docs/runbooks/queue-worker-activation-runbook.md`,
`.cursor/rules/225-rme-concurrent-first-write-and-void-staging-integrity.mdc` (R7-1 correction),
`docs/sprints/dms-rme-revision-program-7.md` (forward reference), `docs/knowledge-base/module-index.md` (link).
`.sprint/current.yml` is unchanged: it belongs to the frozen final candidate, which does not exist while readiness
is NO-GO.
