# DMS-RME-REVISION-PROGRAM-8A — corrective sprint for the Program 8 release blockers

- **Programme:** RME revision programme under owner policy `DMS-FULL-DEVELOPMENT-PRODUCTION-TESTING-20261104`
  (rule 221 §7), mission `DMS-RME-REVISION-PROGRAM-8A` (P0, SECURITY_FIX / CRITICAL_HOTFIX / CI_COVERAGE_HARDENING),
  2026-11-07. Corrective sprint for the NO-GO of [[dms-rme-revision-program-8]] (Stage A readiness audit). Continuation
  of [[dms-rme-revision-program-1]] … [[dms-rme-revision-program-7]].
- **Status:** **GO** — merged (PR #608 → `c0e12382`, tree `e6baa722`, identical to the CI-validated candidate
  `adbd0122`), deployed to Biznet (`20261106-230625`, `DEPLOY RUNNER OK`), verified in production, and tagged
  `dms-rme-revision-program-8a-go` (§6). Full Suite deferred under CG-18, not claimed. **This is not final readiness
  GO**: Program 8 must be re-audited, and P8-N2, the numbering class and the owner-decision register remain open.
- **Forward reference (2026-11-07):** the Program 8 re-audit ran on this release —
  [[dms-rme-revision-program-8-readiness-reaudit]]. It re-verified P8-N1, P8-N3 and the coverage fix as closed; its
  readiness verdict is recorded there, not here.
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
| Production deploy of P8-N1 | Rule 221 §14 "significant security change" gate applies (it withdraws write capability from live production roles); the brief did not waive it, so it was asked separately. **Approved: deploy.** | Owner answer, 2026-11-07 (session question "Deploy P8-N1") |
| Publication timing (review F6) | **Hold the PR until the BK-2 off-server copy is healthy**, so publication and deploy are back-to-back | Owner answer, 2026-11-07 |
| BK-2 custodian action | Owner unlocked and mounted the BK-2 HDD (re-opened under the canonical mapping name after the first attempt used a non-canonical one); then instructed: publish, CI, merge, deploy on the VPS, verify, tag, cleanup; no backup-gate bypass, no Full Suite | Owner answers and message, 2026-11-07 |
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
| P8-N1 suite after the review fixes (RX-06, RX-25 policy-only dataset, RX-26 added), with the strengthened P8-N3 guard | PHP 8.5.4 / SQLite | GREEN: prescription suite 33/33; strengthened suites together 45/45 |
| P8A-RX-17 corrected assertion, base code | PHP 8.5.4 / SQLite | RED (re-proven after the first marker proved to be a test defect: the store route shares its URL with the page's own GET route) |
| P8-N3 suites, base template | PHP 8.5.4 / SQLite | **RED: 5 failed** (the old `ClinicRoomTest` passes on 8.5; the new version-independent assertions do not) |
| P8-N3 suites, base template | official PHP 8.3.6 | RED: 7 failed incl. the original `ClinicRoomTest` create page (ParseError, HTTP 500) |
| `ClinicRoomTest`, base template | CI image PHP 8.3.33 | RED: create page HTTP 500, `ParseError: unexpected token "@"` |
| P8-N3 suites, fixed | PHP 8.5.4, official 8.3.6, CI 8.3.33 | GREEN on all three |
| ClinicMasterData dir + Blade guard + both prescription suites | CI image PHP 8.3.33 / SQLite | 178 cases, 0 failed |
| New suites + evidence-write integrity + PG-only evidence-lock concurrency | **PostgreSQL 16.15 + PHP 8.3.33** | 67 cases, 0 failed, 0 skipped |
| Dependency regression: `tests/Feature/{RME,ClinicMasterData,AccessControl,ClinicOversight,Storage,Ui,Sprint41,Branch}` + `SupervisorRmeRolePermissionTest` | **PostgreSQL 16.15 + PHP 8.3.33** | **4,283 cases, 0 failed, 0 skipped** (14,124 assertions) |
| `tests/Feature/Cicd` + `DevflowAiInstructionSystemTest` (after the workflow and registry edits) | host PHP 8.5.4 | 386 cases, 0 failed, 0 skipped |
| Critical-gate selection, exact filter string from the workflow, both variants | `pest --list-tests` | 9,609 each (Program 8: 9,431; +178 = 39 + 136 + 3); every declared suite fully selected in both. Measured before the review added test cases; CI then ran 9,615 (next row) |
| Required CI on the merged candidate `adbd0122`, run 42301244552 | GitHub-hosted, NSF-R011 critical gate | **9,615 passed (47,545 assertions), 0 failed**, `critical_test_exit_status=0`; all six new or declared suites appear in the log. Classifier, NSF-R012, Selective Module, NSF-9, NSF-10 and the Android gate green; Full Suite gate skipped (CG-18) |
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

**Resolution (2026-11-07):**

- Item 1: the owner approved the deploy (§1.1).
- Item 2: the custodian unlocked the HDD. The first mount used a non-canonical dm-crypt name, so the identity chain
  refused it; the check was NOT loosened. The custodian re-opened the volume under the canonical name, and then:
  - `hdd-identity-check.sh` reported `IDENTITY OK`, every link from disk serial to mount point verified.
  - The canonical pull (`dms-bk2-pull.service`, 21:58:49 UTC) reported `ON_SERVER=OK OFF_SERVER=OK pending=0
    synced=2 failed=0`. Both packages (`BK2-20261105T183000Z`, `BK2-20261106T183001Z`) passed the manifest
    size+SHA-256 check, the O_DIRECT platter re-read (10/10 each) and the decrypt-verify (8/8 each, plaintext
    SHA-256 matching the server).
  - Host `dms-bk2-status` (21:59:25 UTC): `offsite_packages_acked=6`, `offsite_packages_pending=0`,
    `offsite_newest_acked=BK2-20261106T183001Z`, which equals `package_newest`. §6b step 3 is satisfied.

**Release evidence (2026-11-06 UTC; 2026-11-07 WITA).**

| Step | Result |
|---|---|
| Merge | PR #608 squash-merged as `c0e12382`, tree `e6baa722` = candidate tree; the merge's push run deferred the Full Suite (`full_suite_authorized=false`, `TEMPORARY_FULL_SUITE_POLICY_ACTIVE`) |
| Preconditions (§6b 1–3), 23:06 UTC | `PRODUCTION_TARGET=VERIFIED`; host `ls-remote` of the base = `c0e12382`; `ON_SERVER=OK`, `OFF_SERVER=OK`, pending 0, alerts none; no other deploy running; outside 18:25–18:40 UTC |
| Deploy, run on the Biznet host | `deploy-vps-runner.sh start` → `20261106-230625`; status `exit=0`; log `DEPLOY_HEAD_TARGET_MATCH=YES (c0e12382…)`, `DEPLOY OK`; `verify 20261106-230625` → `DEPLOY RUNNER OK` (target matches checkout HEAD, final exit 0). Deploy smoke 6 passed, 1 warning: the known loopback `404` (D6) |
| Canonical smoke | `/login`, `/health/live`, `/health/ready`, `/health/lb` → 200; `/storage/…` → 403; `/settings/clinic-rooms/create` unauthenticated → 302 |
| Code on the host | HEAD/tree `c0e12382`/`e6baa722`, clean; `PrescriptionAuthoringAuthority` present and called by the policy and the service; the clinic-room form has no inline PHP directive |
| Compiled views (`php8.3 -l`, `short_open_tag` Off as in production) | 537 views, **0 glued open tags** (1 before the deploy), 0 lint failures |
| Nothing else moved | no pending migrations; queue worker inactive/disabled, scheduler and KTP timers not installed, backup timers enabled — all as before |
| Data integrity (read-only transaction) | the 20-table count baseline, the newest prescription and room rows and the migration batch are **identical** before and after the deploy |
| Log | `laravel.log` byte-identical (166 ERROR lines, last written 2026-11-03): 0 new errors |
| GO tag | `dms-rme-revision-program-8a-go`, annotated, object `4a74734a` → `c0e12382`, created after production verification with tags fetched first |

**Security verification bound.** The behavioural proof of P8-N1 is the CI run on the identical tree. Production was
not used to test it: no prescription was written, no Pest ran against production, and no REPL was used. What
production proves is that the deployed tree is the tested tree, and that the authority is wired into both
enforcement points.

## 7. Cleanup

Mission-owned resources only. Done after the GO tag: the PostgreSQL 16 container and its network (which held the
disposable Dusk database), the isolated PHP 8.3.6 tree copy, the scratch scripts and JUnit copies, and the Dusk
server container. Kept: the private evidence package, and the worktree until this evidence PR merges. The protected
vault, other worktrees and stashes were not touched. No production resource was created, so none was removed.
