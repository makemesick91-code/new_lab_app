# DMS-RME-REVISION-PROGRAM-4 — remaining route-key audit: legacy import, patient master, patient document, candidate, mapping, issue, identifier, doctor

- **Programme:** RME revision programme under owner policy `DMS-FULL-DEVELOPMENT-PRODUCTION-TESTING-20261104`
  (rule 221 §7), mission `DMS-RME-REVISION-PROGRAM-4` (P0, SECURITY_AUDIT / SECURITY_FIX), 2026-11-05. Continuation of
  [[dms-rme-revision-program-1]], [[dms-rme-revision-program-2]] and [[dms-rme-revision-program-3]].
- **Status:** **FINAL GO** — merged (#599 → `bb82475e`), deployed to Biznet production (deploy `20261105-164743`)
  and verified; GO tag `dms-rme-revision-program-4-go` → `bb82475e`. Evidence in §8. Full Suite DEFERRED (CG-18).
- **Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `545e8c05` (verified from
  `origin` after fetch; `devflow:base-ref-check --strict` resolved the canonical remote; the local base ref was stale
  at `9eabff07` and was not used). Production ran `c7531932` (Program 3 GO), read from the Biznet host before work
  began; the base was one docs-only commit (#598) ahead.
- **Branch:** `feature/dms-rme-revision-program-4`.
- **Canonical rule:** `.cursor/rules/222-rme-route-key-constraint-and-failure-log-redaction.mdc` §1.12–§1.15
  (extended in place; no parallel rule).
- **Work category under the Foundation-First Sprint Lock:** security fix (allowed before Foundation GO). The lock is
  unchanged. No feature work, no clinical, financial, permission or doctor-device behaviour change.

## 1. Governance preflight

### 1.1 Owner approval (recorded per CG-22)

| Id | Decision | Source |
|---|---|---|
| OD-P4 | Audit R-16..R-23 — `{import}`, settings `{patient}`, `{document}`, `{candidate}`, `{mapping}`, `{issue}`, `{identifier}`, `{doctor}` — the residuals Program 3 recorded in rule 222 §1.12; correct each only where a defect is verified; release end to end through production GO | Owner mission brief `DMS-RME-REVISION-PROGRAM-4`, 2026-11-05 |
| OD-P4-scope | The eight names are audit targets, not presumed defects; a parameter found safe is recorded as `VERIFIED — NO CODE CHANGE REQUIRED` | Same brief, §0 |

Not authorised and not done: any other route parameter (§9), any change to doctor-device enforcement, the pilot
cohort, `global_permitted`, branch lock, cover, transfer or session rules, any schema, permission or policy change.

### 1.2 Preflight evidence

| Check | Result |
|---|---|
| Canonical base | `545e8c05`, = `origin` tip; no open PR, no run in progress, no peer session online |
| Production target | `scripts/production-target-check.sh --remote` → `PRODUCTION_TARGET=VERIFIED` (alias, account, pinned host key, hostname, address) |
| Production HEAD | `c753193275a5d982bac07ea7347fecfe52e3019d` (= `dms-rme-revision-program-3-go`), no deploy runner active |
| `devflow:base-ref-check --strict` | exit 0, `BASE_SOURCE=remote_tracking_ref`, `BASE_SHA=545e8c05` |
| `sprint:manifest-check` | exit 0, Decision GO |
| `sprint:scope-audit --strict` | exit 0, Scope decision GO |
| `foundation:devflow-check --strict` | exit 0, GO |
| `foundation:shared-service-audit --strict` | exit 0, GO (10 foundations, 0 errors, 0 warnings) |
| `sprint:test-plan` | Cicd / Foundation / Architecture suites; FULL REQUIRED SUITE escalation → deferred under CG-18 (§6) |

### 1.3 Tooling

| Capability | State |
|---|---|
| Obsidian knowledge base | `docs/` read through targeted navigation; the protected vault worktree was not touched; no `knowledge-base` skill is installed in this session, so the repository workflow was used directly |
| Limit Saver | `limit-saver` skill loaded and applied (targeted reads, minimal patch) |
| Graphify | `graphify-navigator` loaded; `graphify query` confirmed the five controllers sit in isolated communities, so the change stays in `routes/web.php` |
| Superpowers | `superpowers-workflow` loaded; test-first, small steps |
| Dataviz | installed (`dataviz` skill present); not needed for a 1-file runtime change |
| MCP | context-mode (sandboxed analysis); GitHub via `gh`; PostgreSQL via a throwaway `postgres:16` container; no production-database MCP used |

## 2. Inventory (measured from the booted router)

Every route carrying one of the eight names, read from `Router::getRoutes()` with its `wheres` and the controller
argument type (57 parameter uses on 55 routes; the two document routes carry both `{patient}` and `{document}`):

| Rev | Parameter | Routes | Binding | Key column (migration) | Constraint before |
|---|---|---|---|---|---|
| R-16 | `{import}` | 14 (`settings.rme.legacy-imports.*` ×8, `settings.rme.legacy-odontograms.*` ×6) | `int $import`, looked up by key | `stg_rme_legacy_imports.id`, `stg_odontogram_legacy_imports.id` — `id()` bigint | `whereNumber` |
| R-17 | `{patient}` | 7 settings (`edit`, `update`, `destroy`, `activate`, `deactivate`, `documents.show`, `documents.destroy`) + `oversight.rme.patients.show` (R-11, already done) | implicit `Patient` | `mst_patients.id` — bigint | none (5), `whereNumber` (2) |
| R-18 | `{document}` | 2 (`settings.patients.documents.show/destroy`) | implicit `PatientDocument` | `mst_patient_documents.id` — bigint | `whereNumber` |
| R-19 | `{candidate}` | 5 `satusehat.submissions.*` + 2 `lab-case-candidates.*` | implicit `SatusehatCandidate`, `LabCaseCandidate` (two models) | `trx_satusehat_candidates.id`, `trx_lab_case_candidates.id` — bigint | none |
| R-20 | `{mapping}` | 5 `satusehat.mappings.*` | implicit `SatusehatCodeMapping` | `mst_satusehat_code_mappings.id` — bigint | none |
| R-21 | `{issue}` | 8 `satusehat.readiness.issues.*` + 3 `satusehat.branches.issues.*` | `int $issue`, looked up by key | `trx_satusehat_data_quality_issues.id` — bigint | none (8), `whereNumber` (3) |
| R-22 | `{identifier}` | 2 `satusehat.identifiers.*` | implicit `SatusehatEntityIdentifier` | `mst_satusehat_entity_identifiers.id` — bigint; a database key, not the external IHS identifier | none |
| R-23 | `{doctor}` | 7 `settings.doctors.*` (incl. 2 account-link routes) + `rme.doctor-branch-locks.release-session` | implicit `Doctor` | `mst_doctors.id` — bigint | none (7), `whereNumber` (1) |

No model in scope overrides `getRouteKeyName()` or `resolveRouteBinding()`, uses UUIDs or ULIDs, or has an explicit
`Route::bind`/`Route::model`. So every one of the 57 uses is a positive bigint key, and `DatabaseRouteKey::PATTERN`
(`[1-9][0-9]{0,17}`) is the matching contract. None of the eight is a page number, so `PAGE_NUMBER_PATTERN` is not
used. Every name is GENERIC (`candidate` already binds two models; `patient`, `issue`, `identifier` are ordinary
nouns), so each is constrained per route and pinned on route names; `DatabaseRouteKey::CONSTRAINED_PARAMETERS` stays
at its ten names.

Two facts shaped the risk:

- **Implicit binding runs before `can:`.** The SATUSEHAT group is gated by `can:satusehat.access` (Super Admin only),
  but Laravel sorts `SubstituteBindings` ahead of `Authorize`, so an unconstrained model-bound segment reached the
  database for any signed-in account.
- **Several `whereNumber` constraints were also static-segment disambiguators** (`create` before `{import}`,
  `requests` before `{doctor}`). `PATTERN` also rejects letters, so they keep working.

## 3. Red evidence (unmodified routes, PostgreSQL 16.15)

The regression suite `tests/Feature/RME/RemainingRouteKeyConstraintTest.php` was written first and run against the
base `routes/web.php` on a throwaway `postgres:16` container (16.15):

| Case | Result on the base routes |
|---|---|
| Contract (live + compiled) | RED — e.g. `settings.rme.legacy-imports.show {import}` = `[0-9]+` |
| Guest × 9 segment classes × 56 route templates | RED on all 56 (302 to login instead of 404) |
| Super Admin × 9 segment classes × 56 | 190 RED: **172 × HTTP 500**, 18 × 302 (FormRequest redirect) |
| No-permission account × 2 segments × 56 | 88 RED: **55 × HTTP 500**, 33 × 403 |
| Log | 390 × `SQLSTATE[22P02]`, 174 × `SQLSTATE[22003]`, 39 × `TypeError` lines |
| Leading-zero alias on a real record, every domain (R-16..R-23) | RED: the alias OPENED the record (HTTP 200) for import, patient, document, SATUSEHAT candidate, lab candidate, mapping, issue and doctor; it DEACTIVATED a real SATUSEHAT identifier (302) |

A throwaway proof (deleted, never committed) measured the destructive aliases separately on the base routes:
`DELETE /settings/patients/{p}/documents/0{d}` → 302 and **the KTP document was deleted**;
`PATCH /settings/patients/0{p}/deactivate` → 302 and **the patient was deactivated**;
`PATCH /settings/doctors/0{d}/deactivate` → 302 and **the doctor was deactivated**. On the fixed routes all three
are 404 and nothing changes.

One test error in the first red run was my fixture (`ssReadyCandidate()` is local to another file), not a finding; it
was inlined and the R-19 red case was re-run against the base routes (copy-reverted, checksum-restored): 11 of 11
contract and alias cases RED.

## 4. Implementation

`routes/web.php` only (plus the `DatabaseRouteKey` docblock). Every one of the 57 uses now carries
`->where('<name>', DatabaseRouteKey::PATTERN)`:

- R-16: the 14 `->whereNumber('import')` replaced (count verified 14 → 0).
- R-17: `Route::resource('patients', …)->where(['patient' => …])` plus `activate`/`deactivate`.
- R-17/R-18: the two document routes' `whereNumber(['patient', 'document'])` replaced; the comment that claimed the
  numeric constraint already prevented the 500 is corrected (it still admitted overflow and the alias).
- R-19..R-22: the 23 SATUSEHAT routes and the 2 lab candidate routes (counts 7/5/2/11 verified by the edit script).
- R-23: `Route::resource('doctors', …)->where(['doctor' => …])`, `activate`/`deactivate`, both account-link routes,
  and the release-session route's `whereNumber` replaced; the ordering-trap comment updated.

No controller, service, repository, policy, permission, middleware, migration or view changed. URL generation is
unaffected (`route()` does not validate `wheres`), so every link built from a real id still resolves.

## 5. Security review

- **Authorization order (rule 222 §1.8, §1.13).** Real record without permission → 403 (patient, doctor, lab
  candidate) and the Super Admin gate still denies a SATUSEHAT viewer at the canonical key → 403; no record → 404;
  non-key segment → 404 for everyone, including an account with no permission. Pinned by tests.
- **Branch isolation.** Unchanged: no scope code changed. The document branch-isolation suite
  (`PatientKtpArchiveBranchIsolationTest`) and the legacy, SATUSEHAT and lab suites pass (§6).
- **Privacy.** Before the fix a malformed `{patient}` such as a pasted Nomor RM reached the SQL error, which the log
  records; now it never reaches a query. Private KTP storage is unchanged; the canonical document still streams only
  through `PatientDocumentAccessService`.
- **Doctor security.** Only the route pattern changed. Device enforcement, pilot cohort, `global_permitted`, branch
  lock, cover, transfer and session invalidation are untouched; the release-session route still 404s while the
  capability is disarmed.
- **Independent review.** Recorded in §5.1.

### 5.1 Independent review

**Mechanism.** A separate `code-review-limited` reviewer agent with its own context, given the commit and the
questions but not this document's conclusions. Program 3's attempt was refused by auto mode; the cause was found
this time: the context-mode plugin's PreToolUse hook appends a `<context_window_protection>` block to the Agent prompt
*after* auto mode has classified the call, so the classifier declines to cover an input it did not see. Re-issuing the
call with the hook-rewritten input lets auto mode review exactly what runs. No hook or protection was disabled.

**Scope.** Commit `c919658a`, read-only. The reviewer loaded the old and new `routes/web.php` into separate route
collections and compared them route by route, including 5,963 probe URIs × 5 methods.

**Result: no CRITICAL, HIGH or MEDIUM finding; not release-blocking.**

| # | Severity | Finding | Disposition |
|---|---|---|---|
| F1 | LOW | The R-23 release-session alias request cannot fail on the old code: the disarmed branch-lock capability answers 404 for every key | Fixed: a router-level test now proves, for EVERY route, that `01`, `007` and an 18-digit leading-zero alias match no route while the canonical key does (56 cases, RED 56/56 on the base routes); the release-session request is kept and commented as not a proof |
| F2 | LOW | Alias-with-a-real-record coverage is one or two routes per family | Fixed by the same router-level test (all 56 templates) |
| F3 | LOW | `toBeIn([404, 302])` accepted a 302 on every route | Fixed: 302 only on the two readiness actions whose FormRequest validates first (measured), 404 everywhere else |
| F4 | INFO | `Route::resource(...)->where()` also stamps index/create/store, which carry no such parameter | No effect (confirmed by the probe comparison); kept |
| F5 | INFO | A malformed key is now 404 before `auth`/permission, so a guest gets 404 instead of a login redirect | Intended (rule 222 §1.8) |
| F6 | INFO | 89 `whereNumber` uses remain on other parameters | Out of scope; §9 |

Also confirmed by the reviewer: every one of the 75 `route()` call sites for these routes passes a model or an integer
key; no hardcoded URL in `resources/js`; 818 routes before and after with identical names, actions and middleware; no
probe resolves to a different named route; the critical-gate filter only gains a token.

## 6. Tests

| Run | Engine | Result |
|---|---|---|
| `RemainingRouteKeyConstraintTest` (new, after review) | PostgreSQL 16.15 | 797 passed, 0 failed, 0 skipped |
| Router-level alias test (added after review) on the BASE routes | PostgreSQL 16.15 | RED 56 of 56 (a `01` alias routed on every template) |
| `RemainingRouteKeyConstraintTest` (before review) | PostgreSQL 16.15 | 741 passed, 0 failed, 0 skipped (1,582 assertions) |
| Same | SQLite (phpunit default) | 741 passed, 0 failed, 0 skipped |
| All nine rule-222 suites in ONE process (the critical gate's shape) | PostgreSQL 16.15 | 1,456 passed, 0 failed, 0 skipped |
| Dependency regression: every feature test file that names a changed route or URI (75 files, Browser suites excluded — no browser leg) | PostgreSQL 16.15 | 1,979 passed, 0 failed, 0 skipped (8,701 assertions) |
| `CriticalGateSuiteCoverageTest` | SQLite | 14 passed |
| `route:cache` round trip | — | `routesAreCached=true`, all 57 uses carry `[1-9][0-9]{0,17}` from the cache; cache cleared afterwards |

**A defect caught by the selection check, not by the suite.** My first helper names (`rrkNonKeySegments()` and
siblings) collided with Program 2's `RmeRecordRouteKeyConstraintTest`. Each file passed on its own; loaded together —
which is exactly what the critical gate does — PHP stops with a fatal redeclare and the whole gate dies. Running the
critical filter through `--list-tests` exposed it; the helpers now use the `p4rk` prefix and rule 222 §3 records the
trap.

**CI selection (CG-19).** The suite is declared in `config/ci_runner.php` `critical_gate_mandatory_suites` and selected
by the `RemainingRouteKey` token, added to BOTH critical variants (the two filter strings are byte-identical).
`pest --list-tests` with the exact CI filter listed all 741 cases among 9,256 (before the review's 56 additions).

**Full Suite.** `sprint:test-plan` escalates to the full required suite. The temporary Full-Suite policy (CG-18) is
ACTIVE, so it is **DEFERRED**, not passed.

## 7. Foundation rule synchronisation

| File | Change |
|---|---|
| `.cursor/rules/222-rme-route-key-constraint-and-failure-log-redaction.mdc` | Status and description cover Program 4; §1.12 Program 4 table; §1.13 binding before `can:`; §1.14 disambiguators kept; §1.15 residuals; §3 new suite, nine mandatory suites, the redeclare trap |
| `.claude/rules/legacy-rme.md` | AIR-LRM-39 no longer says `{import}` keeps `whereNumber` (it was stale the moment R-16 landed); index row |
| `.claude/rules/satusehat.md` | AIR-SSH-38 + index row |
| `.claude/rules/patient-identity.md` | AIR-PAT-33 + index row |
| `.claude/rules/lab.md` | AIR-LAB-34 + index row |
| `.claude/rules/doctor-access.md` | AIR-DAC-41 + index row |
| `app/Support/Routing/DatabaseRouteKey.php` | docblock lists R-16..R-23 among the per-route generic names |

`.claude/rules/rme-billing.md` was deliberately not touched (it sits at its 30,000-character budget) and needs no
change: no billing route is in scope. `CLAUDE.md` is unchanged: no global rule changed.

## 8. CI, merge, deploy and production verification

Recorded after the release in a docs-only evidence commit. This commit is not deployed, and the GO tag does not
point at it.

**CI (exact candidate `d90426aa`, run 42057100879).** Every required gate succeeded:

| Gate | Result |
|---|---|
| NSF-R011 Critical Test Gate | `Tests: 9309 passed (46442 assertions)`, `critical_test_exit_status=0`, `expected_warning_count=0`. `RemainingRouteKeyConstraintTest` ran 797 cases, 0 failed, counted from the job log. The second Critical row is the inactive runner variant and is skipped by design |
| NSF-R012 Quality, CICD-CTRL Classifier, CICD-CTRL Selective Module | success |
| NSF-9 Release Safety, NSF-10 Release Evidence | success |
| Phase 3 Android Clinic App Gate | Attempt 1 failed before any test ran: the Gradle 9.1.0 distribution download from github.com returned HTTP 504. The diff touches no Android file. A re-run of the failed job on the same SHA passed every step, including "Assert the security contracts actually ran" |

The Full Suite was not run. **FULL SUITE — DEFERRED** under CG-18; it is not reported as passed.

**Merge.** PR #599 was squash-merged with `--match-head-commit d90426aa`. The merge commit is `bb82475e`, with parent
`545e8c05` (the base did not move). Its tree `29e1c2c0` is identical to the tested candidate's tree.

**Pre-deploy gate (2026-11-05 16:47 UTC, outside the 18:25–18:40 UTC backup window).**

- `production-target-check.sh --remote` returned `PRODUCTION_TARGET=VERIFIED`, re-run immediately before the start.
- The host's `ls-remote` showed the base at `bb82475e`. Production HEAD was `c7531932`, the checkout was clean and no
  deploy process was running.
- `dms-bk2-status` reported `ON_SERVER=OK`, `OFF_SERVER=OK`, 4 of 4 packages acknowledged and no alerts.
- `laravel.log` measured 1,467,295 bytes with 166 ERROR lines.

**Deploy.** `deploy-vps-runner.sh start` ran on the Biznet host and was assigned deploy ID `20261105-164743`.

- The run finished at 16:49:20 UTC with `exit=0`, `DEPLOY_HEAD_TARGET_MATCH=YES (bb82475e)`, `DEPLOY OK` and
  `immutable-exec rc=0`.
- Migrations reported "Nothing to migrate", with backup before migrate and `migrate --force` only.
- The pre-deploy backup is `pre_auto_deploy_20261105-164744.sql` (32,746,151 bytes); NSF-10 backup verify returned
  9/9 GO.
- Runtime isolation was 107 GO / 0 FAIL, and secret file permissions were GO.
- `verify 20261105-164743` reported **DEPLOY RUNNER OK**: target commit `bb82475e`, checkout HEAD matching, final
  `exit=0`.

The `DEPLOY OK` line carries `deploy-vps.sh`'s own stamp (`164744`, taken one second after the runner's). It is in
the runner's log for this deploy ID; it is one deploy.

Two deploy gates reported WATCH. Both appear in Program 3's deploy log as well, so neither is new to this release:

- `SMOKE-HTTP-HEALTH`: a loopback probe of `127.0.0.1/login` returns 404 on this host, while `/login` returns 200
  over the canonical domain.
- `PGRUNTIME-PGBOUNCER-PROBE`: PgBouncer is not installed, which the check marks non-blocking because routing cutover
  is disabled.

**Production verification (over `https://daengtisia.online`).**

- **Release:** HEAD and tree equal the release (`bb82475e` / `29e1c2c0`), and the checkout is clean.
- **GO tag:** `git describe --exact-match HEAD` returns `dms-rme-revision-program-4-go`.
- **Guest probe matrix: 57 of 57 pass.** Before the deploy, 36 of the 44 malformed or alias probes matched a route
  (302/419). After it, all 44 answer 404 and set no cookie, because the router rejects them before a session
  starts. All 13 controls still match: a well-formed key redirects to login, POST answers 419, and static siblings
  are reachable.
- **Health:** `/login`, `/health/live`, `/health/ready` and `/health/lb` all return 200, and every readiness
  component reports `ok`. `/storage/<probe>` returns 403.
- **Log:** `laravel.log` is byte-identical (1,467,295 bytes, 166 ERROR), so the deploy and the 57 probes wrote no
  log line.
- **Services:** nginx, php8.3-fpm, postgresql and both backup timers are active. 0 migrations are pending and no
  deploy process remains. The environment is pilot with debug OFF and maintenance OFF.
- **Queue worker:** the unit is disabled and has never been active on this host. That was already the posture before
  this release, and it is unchanged.

**GO tag.** The annotated tag `dms-rme-revision-program-4-go` (tag object `727f5624`) targets `bb82475e`, the
verified production release. It exists on origin and resolves on the production host. No other tag was moved.

## 9. Residuals and follow-ups (not approved, not done)

Every other route parameter is outside this programme's approved scope. Measured 2026-11-05 on the booted router
(route uses that are unconstrained or under `whereNumber`):

| Area | Parameters (uses) |
|---|---|
| Lab | `labOrder` 22, `labV2Order` 15, `labService` 5, `delivery` 8, `deliveryTask` 6, `pickupTask` 5, `labWorkflowRequest` 3, `technician` 5, `assignment` 1, `attachment` 2, `evidence` 1, capacity/workload/availability profiles |
| Inventory | `product` 13, `batch` 17, `purchaseOrder`/`purchase_order` 8, `purchaseRequest`/`purchase_request` 7, `goodsReceipt`/`goods_receipt` 7, `stockOpname`/`stock_opname` 6, `stockTransfer`/`stock_transfer` 8, `supplier` 5, `location` 4, categories/units, `inventoryBatch` 3 |
| Master data / RME | `branch` 17, `clinic` 5, `clinicRoom` 3, `treatment` 3, `treatmentCategory` 3, `tariff` 3, `paymentMethod` 3, `invoice` 4, `user` 5, `role` 3 |
| Billing and refund | `correction` 8, `refundRequest` 7, `settlement` 6, `dispute` 5, `obligation`, `designation`, `version` (`whereNumber`) |
| Doctor access and devices | `doctorDevice` 11, `authorization` 5, `registration` 10, `branchChangeRequest` 3, `doctorBranchCover` 3, `doctorBranchLockRequest` 3, `wave` 16, `grant`, `credential`, `enrollment` |

Each needs its key type verified — UUID-keyed parameters (`patientMergeCase`, `session`, `event`) already carry UUID
patterns — and its own owner approval. The Program 3 residual list (rule 222 §1.12) is fully closed by this programme.
