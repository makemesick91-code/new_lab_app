# DMS-RME-REVISION-PROGRAM-8A — corrective sprint for the Program 8 release blockers

- **Programme:** RME revision programme under owner policy `DMS-FULL-DEVELOPMENT-PRODUCTION-TESTING-20261104`
  (rule 221 §7), mission `DMS-RME-REVISION-PROGRAM-8A` (P0, SECURITY_FIX / CRITICAL_HOTFIX / CI_COVERAGE_HARDENING),
  2026-11-07. Corrective sprint for the NO-GO of [[dms-rme-revision-program-8]] (Stage A readiness audit). Continuation
  of [[dms-rme-revision-program-1]] … [[dms-rme-revision-program-7]].
- **Status:** IMPLEMENTATION COMPLETE, reviewed (0 blocking findings) and verified locally; release gated on two owner
  items (§6). Not merged, not deployed, no GO tag. Full Suite deferred under CG-18, not claimed.
- **Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `6eefa719` (PR #607, the
  Program 8 audit record, merged at the start of this sprint). Production ran `9352457e` (Program 7 GO, deploy
  `20261106-134517`), read from the Biznet host before work began.
- **Branch:** `feature/dms-rme-revision-program-8a`.
- **Canonical rule:** `.cursor/rules/226-rme-prescription-authoring-authority-and-blade-php-pairing.mdc` (new; extends
  rule 223 §3, which gains §16a and a reader-count correction in §12; supersedes nothing). Domain entries: AIR-RMC-45
  (`.claude/rules/rme-clinical.md`), AIR-UI-31 (`.claude/rules/ui.md`).
- **Work category under the Foundation-First Sprint Lock:** security fix / clinical correctness. The lock is unchanged.
  No new feature, no schema, migration, permission, role, route, JS/CSS or production package change.

## 1. Governance preflight

### 1.1 Owner approval and scope (CG-22)

| Item | Decision | Source |
|---|---|---|
| Corrective sprint | Approved: P8-N1, P8-N3, critical-gate coverage for Prescription and ClinicMasterData, their tests, rule/doc sync | System-owner mission brief, 2026-11-07 |
| PR #607 (Program 8 audit record) | Merge if permitted: merged `6eefa719` (docs-only, CI green, head pinned) | Same brief §1 |
| Production deploy of P8-N1 | Rule 221 §14 "significant security change" gate applies (it withdraws write capability from live production roles). The brief does NOT waive it. | Rule 221 §14; brief §0 |
| Not authorised | Full Suite, clinic reopening, queue worker / scheduler, global doctor-device enforcement, any open owner decision | Brief §0, CG-18 |

### 1.2 Authority read before any change

Remote base re-fetched (`devflow:base-ref-check --strict` GO; the local base ref was stale at `9eabff07`, the remote
won). Production HEAD `9352457e` / tree `2f607586` read from the host with an invocation-scoped `safe.directory`.

### 1.3 Skills, MCP and DEVFLOW

| Capability | Used for | Result |
|---|---|---|
| `superpowers-workflow`, `limit-saver` | 5-step plan, targeted reads, minimal patch | applied |
| Graphify | dependency lookup | the primary checkout's graph predates the worktree and returned only node locations; callers verified by `grep` (controller is the sole service/policy entry point) |
| `knowledge-base` / Obsidian conventions | this record, wiki links | applied; the locked vault worktree untouched |
| GitHub CLI, Docker (PHP 8.3.33 CI image, official PHP 8.3.6, PostgreSQL 16.15) | PR, CI, version matrix, PG regression | available |
| DEVFLOW | `devflow:base-ref-check --strict`, `sprint:manifest-check`, `sprint:scope-audit --strict`, `foundation:devflow-check --strict`, `foundation:shared-service-audit --strict` | all GO |
| `sprint:test-plan` | regression sizing | escalates to "full required suite" (the Prescription module matches no regression category). CG-18 forbids the Full Suite; the category was deliberately NOT added to dodge a fail-closed escalation. The dependency regression in §6 was derived from the callers instead. |

## 2. Findings corrected

### 2.1 P8-N1 (HIGH, security) — prescription authoring authority

Sanitised: the prescription write path (policy `create` / `update` and `RmePrescriptionService`) did not apply the
clinical authoring authority that rule 223 §3 already requires for the RME and the odontogram, and the service
re-asserted nothing. Mechanism, preconditions and reproduction stay in the private security record
(`~/.local/share/daengtisiams-private-security/`), not in this public repository. Program 8's read-only check of
production found no evidence of misuse.

Correction: one predicate, `App\Modules\Prescription\Services\PrescriptionAuthoringAuthority`, mirroring
`RmeVisitConsentService::actorMayWorkOnEncounter()`:

- the front-desk-only tier (`FrontOfficeRole::isVisitFrontDeskOnly()`) never writes a prescription or its canvases;
- writes are scoped to the actor's working branch (`RmeWorkingBranchScope::allows()`), the per-record guard the
  visit's own write authority already uses — narrowing only;
- the doctor-patient scope still binds a Doctor; the actor is a usable account.

The policy reads it for `create` / `update`; the service re-asserts it before any lock is taken or any canvas is
decoded or written (CG-06). Read abilities are unchanged (P8-N2 is an owner decision). No consent prerequisite and no
room rule were added (OD-7-2 stays open).

### 2.2 P8-N3 (MEDIUM) — clinic-room form

`resources/views/settings/clinic-rooms/_form.blade.php` opened with the inline PHP directive above a block PHP
directive. Blade stores raw PHP blocks first (and before stripping comments), pairing the inline opener with the
block's closer; the compiled view began with an open tag glued to `(` and carried a literal directive inside.

| Build | `short_open_tag` | Base template | After the fix |
|---|---|---|---|
| CI image PHP 8.3.33 (no php.ini) | On | ParseError, HTTP 500 (`ClinicRoomTest` create page fails) | renders |
| Official PHP 8.3.6 image (no php.ini) | On | ParseError, HTTP 500 | renders |
| Production Ubuntu `8.3.6-0ubuntu0.24.04.13` (read-only probe of the compiled shape, `/tmp` file removed) | Off (FPM and CLI php.ini) | `php -l` clean; source printed as text, HTTP 200 | renders |
| PHP 8.5.4 (workstation) | Off | source printed as text, HTTP 200 (old suite passed) | renders |

The deciding factor is `short_open_tag`, not the PHP release: the same PHP 8.5 binary reports a ParseError with
`-d short_open_tag=1` and "no syntax errors" with `=0` (found by the independent reviewer, F4, and re-measured). The
first draft of this record and of rule 226 attributed it to the patch release; both were corrected before merge.

Correction: one PHP block. A first draft added an explanatory Blade comment naming the directives; it was reworded
before any test because the raw-block pass would have paired the tokens inside the comment and re-created the defect.

Bounded static scan: every `.blade.php` under `resources/views` compiled with the real compiler and checked two ways
(a glued open tag, and a real `token_get_all(TOKEN_PARSE)` parse of the compiled view) — **517 templates; 1 hit on the
base (this file), 0 after the fix, under both `short_open_tag` settings.** No other template was touched. Three
templates mix the two directive forms safely (block first, inline after); they are not affected.

### 2.3 CI-8A — critical-gate coverage

The NSF-R011 critical gate selected no prescription and no ClinicMasterData test (Program 8 §2.4). Tokens added to
BOTH runner variants (identical, before the pinned calendar-boundary tail):

| Token | Selects (measured with `pest --list-tests`) |
|---|---|
| `RmePrescription` | exactly `RmePrescriptionTest` + `RmePrescriptionAuthoringAuthorityTest` (39 tests) |
| `ClinicMasterData` | exactly the 8 suites of `tests/Feature/ClinicMasterData` (136 tests), zero unrelated files |
| `BladePhpDirectivePairing` | exactly `BladePhpDirectivePairingTest` (3 tests) |

Declared in `config/ci_runner.php`: the three tokens in `critical_gate_required_filters`; six suites in
`critical_gate_mandatory_suites` (`RmePrescriptionAuthoringAuthorityTest`, `RmePrescriptionTest`, `ClinicRoomTest`,
`ClinicRoomFormTemplateTest`, `ClinicMasterDataPermissionTest`, `BladePhpDirectivePairingTest`).

Required CI on the candidate SHA is recorded in §6.

## 3. Owner decisions recorded, not inferred

| Id | Question | Current behaviour (pinned by a test, unchanged) |
|---|---|---|
| OD-8A-1 | May a prescription be amended after its visit completed? | A clinician may (P8A-RX-50); the front desk may not (P8A-RX-16) |
| OD-8A-2 | Should prescription create/update write an audit row? | No audit row; `created_by` / `updated_by` only. The private fix design proposed one. The handwriting RME, odontogram and consent writes do not audit either, while structured diagnosis and performed treatments do; which clinical documents carry an audit trail is a forensic policy decision, not this fix |
| OD-7-2 | Prescription room isolation | Not added (P8A-RX-51) |
| rule 223 §16 | May Perawat / Supervisor RME author? | Perawat keeps authoring in its working branch (P8A-RX-05) |
| P8-N2 | Clinical document read-scope hardening (detail in the private security record) | Unchanged; read abilities untouched |

## 4. Tests

Every runtime defect was reproduced RED on the untouched base first (production code stashed, the new test kept).
Counts are read from JUnit, never from the Pest summary (the worktree has no environment file by design, so Pest
labels passing tests "warnings").

| Run | Environment | Result |
|---|---|---|
| P8-N1 suite, base code | PHP 8.5.4 / SQLite | **RED: 29 cases, 16 failed** — every authorisation and scope assertion; all 13 positive controls and neutrality pins passed |
| P8-N1 suite, fixed | PHP 8.5.4 / SQLite | GREEN 29/29 (85 assertions) |
| P8A-RX-17 corrected assertion, base code | PHP 8.5.4 / SQLite | RED (re-proven after the first marker proved to be a test defect: the store route shares its URL with the page's own GET route) |
| P8-N3 suites, base template | PHP 8.5.4 / SQLite | **RED: 5 failed** (the old `ClinicRoomTest` passes on 8.5; the new version-independent assertions do not) |
| P8-N3 suites, base template | official PHP 8.3.6 | RED: 7 failed incl. the original `ClinicRoomTest` create page (ParseError, HTTP 500) |
| `ClinicRoomTest`, base template | CI image PHP 8.3.33 | RED: create page HTTP 500, `ParseError: unexpected token "@"` |
| P8-N3 suites, fixed | PHP 8.5.4, official 8.3.6, CI 8.3.33 | GREEN on all three |
| ClinicMasterData dir + Blade guard + both prescription suites | CI image PHP 8.3.33 / SQLite | 178 cases, 0 failed |
| New suites + evidence-write integrity + PG-only evidence-lock concurrency | **PostgreSQL 16.15 + PHP 8.3.33** | 67 cases, 0 failed, 0 skipped |
| Dependency regression: `tests/Feature/{RME,ClinicMasterData,AccessControl,ClinicOversight,Storage,Ui,Sprint41,Branch}` + `SupervisorRmeRolePermissionTest` | **PostgreSQL 16.15 + PHP 8.3.33** | **4,283 cases, 0 failed, 0 skipped** (14,124 assertions) |
| `tests/Feature/Cicd` + `DevflowAiInstructionSystemTest` (after the workflow and registry edits) | host PHP 8.5.4 | 386 cases, 0 failed, 0 skipped |
| Critical-gate selection, exact filter string from the workflow, both variants | `pest --list-tests` | 9,609 each (Program 8: 9,431; +178 = 39 + 136 + 3); every declared suite fully selected in both |
| P8-N3 suites, base template, **`short_open_tag` Off** (ini file mounted into the CI image's scan dir) | PHP 8.3.33 | **RED: 5 failed — and the original `ClinicRoomTest` PASSES** (HTTP 200 with leaked source: the old suite is blind to the production symptom; the new ini-independent assertions are not) |
| Same, fixed template | PHP 8.3.33, Off | GREEN 26/26 |
| Mutation matrix (11 mutants: policy, service, authority, tier constant, both guard checks), restore by copy, tree verified identical | PHP 8.5.4 | **11 killed, 0 survived** |
| Browser (`tests/Browser/ClinicRoomFormBrowserTest`): create, save, reopen for edit; asserts labels, selected values, no template source, no error page | Chrome 149 headless + ChromeDriver 149; app served by the PHP 8.3.33 image on a disposable PostgreSQL 16 database (never production) | `short_open_tag` On: base RED (page 500, title never renders), fixed GREEN 33/33. `short_open_tag` Off: base RED (page renders, the leak assertion fires on `@php`), fixed GREEN 33/33 |

The same `Cicd` run inside the PHP container reported 12 failures, all in tests that shell out to runner-host tooling
(podman/self-hosted health scripts, a git execute-bit check whose `.git` worktree pointer resolves outside the
container mount). The host run is authoritative for those; nothing was changed to make them pass.

Neutral by test, not by assertion: Perawat authoring in its working branch (P8A-RX-05), clinician amendment after
completion (P8A-RX-50), no prescription room rule (P8A-RX-51), front-desk reading (P8A-RX-18), Super Admin
(P8A-RX-03) and hybrid clinician + front-desk accounts (P8A-RX-04) all keep today's behaviour.

Measurement correction recorded honestly: `artisan test` and `artisan serve` start child PHP processes, so a
`-d short_open_tag=0` flag on the parent never reached them; the first "Off" runs therefore ran with On and were
discarded. The rows above marked Off used an ini file mounted into the image's scan directory (verified visible to
a child process). The repo-wide scan of all 517 compiled templates under both settings was in-process and valid.

Not run: the Full Suite (CG-18, deferred, not passed). Only the new browser test was run, not the whole Dusk suite:
the unrelated Dusk failures Program 8 recorded (invoice numbering after soft delete, the environment-dependent Sunu
fixture) remain outside this sprint and still bear on final readiness.

## 5. Independent review

A separate read-only reviewer (subagent, given the diff, the canonical rules, the private P8-N1 record and the open
owner decisions — not the author's conclusions) reviewed the change with no test process running in the worktree.
Verdict: **P8-N1 closed in code for every reproduced case; P8-N3 fix correct; `BLOCKING_FINDINGS=0`.** Every
finding was addressed before the commit; the detail of each is in the private review report.

| Id | Severity | Finding | Disposition |
|---|---|---|---|
| F1 | MEDIUM | The test helper made every "Front Office" actor an Admin Klinik hybrid, so dropping Front Office from the tier was undetectable | Fixed: helper builds a pure single-role account and asserts its role set; mutation M3 now killed by 4 tests |
| F2 | LOW | Policy layer not tested on its own (service re-assertion masked a policy regression as the same 403) | Fixed: P8A-RX-25/26 assert `Gate::forUser()` directly; mutations M1/M2 killed |
| F3 | LOW | Glued-tag regex misses a spaced opener and a closer hidden in a Blade comment | Fixed: guard also parses each compiled view (`TOKEN_PARSE`); positive controls for all three shapes; 0 false positives on 517 templates under both ini settings |
| F4 | LOW | Root cause attributed to the PHP release instead of `short_open_tag` | Confirmed by measurement and corrected in rule 226, AIR-UI-31, test headers, manifest, this record |
| F5 | LOW | Docs ahead of code (registry not yet written); Supervisor RME not pinned; doctor branch wording; audit claim too broad | Registry written; RX-06 added; wording corrected; OD-8A-2 narrowed |
| F6 | LOW | Public wording of P8-N2 more specific than Program 8's; publication timing vs. the deploy gate | P8-N2 reverted to Program 8's wording; timing put to the owner with the deploy approval (§6) |
| F7 | LOW/INFO | `markPrinted()` (first print stamps `printed_at`) is a write outside the authority | Listed in rule 226 §1.7a as the known non-authoring write; stays with the read abilities (P8-N2) |
| F8 | INFO | The authority is a denylist; a future non-clinical holder of `manage_clinic_visits` would fail open | Production census (read-only, counts only): 18 clinical holders keep authority, 8 Front Office accounts lose prescription writes, **0 non-clinical holders outside the tier** |
| F9 | INFO | A stray JUnit file in `storage/` | Gone before staging; files staged by explicit path |
| F10 | INFO, pre-existing | The PATCH route's room middleware cannot resolve a visit from `{rmePrescription}` (no room gate on update); FormRequest validation runs before the controller's `authorize()`, so a refused actor with an invalid payload sees 422 before 403 | Recorded as residuals R8A-1 (part of OD-7-2) and R8A-2 (no write occurs; information-free); not changed |

## 6. Release

Two preconditions stand before production, and neither is waived by a green build:

1. **Rule 221 §14 — significant security change.** This change withdraws prescription-write capability from live
   production accounts (8 Front Office accounts, measured read-only) and narrows the Perawat write scope to its
   working branch. That is a significant security change, so the deploy needs a specific owner approval; the mission
   brief explicitly does not waive it.
2. **Deploy runbook §6b step 3 — BK-2 off-server copy.** `dms-bk2-status` on the host (read-only, 2026-11-06
   18:10 UTC): on-server package and database dump healthy (23 h), `offsite_packages_pending=1`, newest acknowledged
   off-server package `BK2-20261104T183002Z`. The workstation pull (`dms-bk2-pull.timer`) has reported
   `OFF_SERVER=PENDING hdd=locked-or-not-mounted` on every run since 2026-11-05 18:59 UTC. Mounting the encrypted HDD
   is a custodian action; it was not attempted here.

Publication timing (review F6) goes to the owner with item 1: the public pull request describes the corrected
invariant, so the window between publication and deployment should be as short as the required CI allows.

## 7. Cleanup

Pending (the release is not complete).
