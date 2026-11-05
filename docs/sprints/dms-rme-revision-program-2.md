# DMS-RME-REVISION-PROGRAM-2 — RME route-key hardening: invoice, medical record, prescription, consent, odontogram, oversight

- **Programme:** RME revision programme under owner policy `DMS-FULL-DEVELOPMENT-PRODUCTION-TESTING-20261104`
  (rule 221 §7), mission `DMS-RME-REVISION-PROGRAM-2` (P0, SECURITY_FIX), 2026-11-05. Continuation of
  [[dms-rme-revision-program-1]].
- **Status:** **RME REVISION GO** for R-03..R-08 (policy §7) — merged, deployed to Biznet production and
  production-verified on 2026-11-05; GO tag `dms-rme-revision-program-2-go`. Full Suite deferred (CG-18), not passed.
- **Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `c8c056e4` (verified from
  `origin` after fetch; `devflow:base-ref-check --strict` resolved the canonical remote). Production was at
  `db425155` (Program 1 GO); the base is one docs-only commit (#594) ahead.
- **Branch:** `security/dms-rme-revision-program-2`.
- **Canonical rule:** `.cursor/rules/222-rme-route-key-constraint-and-failure-log-redaction.mdc` (updated in place;
  it owns this invariant, so no parallel rule was created).
- **Work category under the Foundation-First Sprint Lock:** security fix (allowed before Foundation GO). The lock is
  unchanged. No feature work.

## 1. Governance preflight

### 1.1 Owner approval (recorded per CG-22)

| Id | Decision | Source |
|---|---|---|
| OD-P2 | Implement R-03..R-08, the six route-key follow-ups Program 1 recorded in rule 222 §1.6, end to end through production GO | Owner mission brief `DMS-RME-REVISION-PROGRAM-2`, 2026-11-05 |

The approval names the parameter groups; it does not approve other parameters found unconstrained (§9).

### 1.2 Policy

- Rule 221: development, production deploys and controlled production testing allowed; operational clinic devices
  frozen; clinic reopening not authorised. The Biznet database is a testing database by owner declaration but may
  hold real personal data, so every privacy rule applies. No test suite, seeder or factory was pointed at it.
- CG-18: the GLOBAL TEMPORARY FULL-SUITE POLICY is ACTIVE; the Full Suite is deferred, not passed.

### 1.3 DEVFLOW (isolated worktree, throwaway PostgreSQL 16.15 container)

| Command | Exit | Result |
|---|---|---|
| `devflow:base-ref-check --strict` | 0 | canonical base `c8c056e4`; the stale local base ref (`9eabff07`) correctly ignored |
| `foundation:devflow-check --strict` | 0 | 6/6 checks GO |
| `foundation:shared-service-audit --strict` | 0 | 10 entries, 0 errors, 0 warnings |
| `sprint:manifest-check` | 0 | GO |
| `sprint:scope-audit --strict` | 0 | GO; 0 modules touched (changes are in `app/Support/Routing` and `routes/`) |
| `sprint:test-plan` | 0 | fails closed to FULL REQUIRED SUITE (5 files match no category). Under CG-18 the Full Suite is deferred; a dependency-derived regression ran instead (§6) |

### 1.4 Tooling

Repo skills used: `limit-saver`, `superpowers-workflow`, `graphify-navigator`, `knowledge-base`, `sprint-doc-writer`,
`diff-review`. `dataviz` was already available as an installed skill, so nothing was installed. No GitHub, Obsidian,
PostgreSQL or Graphify MCP server is configured in this environment; the `gh`, `graphify` and `docker` CLIs were used
instead. Graphify's graph was dated 2026-11-01 and predates Program 1, so every finding was verified against source;
it did surface one dependent worth recording (§4.3).

## 2. Inventory (measured from the booted router, 831 routes)

| Group | Parameter | Routes | Bound how | Key | Before |
|---|---|---|---|---|---|
| R-03 | `rmeInvoice` | 15 | implicit, `RmeInvoice` | `trx_rme_invoices.id` bigint | unconstrained |
| R-04 | `medicalRecord` | 7 | implicit, `MedicalRecord` | `trx_medical_records.id` bigint | unconstrained |
| R-05 | `rmePrescription` | 4 | implicit, `RmePrescription` | `trx_rme_prescriptions.id` bigint | unconstrained |
| R-06 | `consent` | 3 | implicit, `RmeVisitConsent` | `trx_rme_visit_consents.id` bigint | unconstrained |
| R-07 | `odontogram` | 3 | implicit, `Odontogram` | `trx_odontograms.id` bigint | unconstrained |
| R-08 | `visit` | 5 | none: `int $visit` controller argument | `trx_clinic_visits.id` bigint | `whereNumber` (`[0-9]+`) |

No model among the five overrides `getRouteKeyName()`/`resolveRouteBinding()`; there is no explicit `Route::bind`;
no route outside `routes/` registers these names; no per-route override existed on R-03..R-07. Every route sits
behind `auth` plus a named permission (`manage_rme_billing`, the correction/refund/dispute permissions,
`view_clinic_visits|manage_clinic_visits`, the consent permissions, `view_clinical_records_readonly`,
`export_oversight_data`); medical-record, prescription-update and odontogram-write routes also carry `visit.room`.

## 3. Red evidence (untouched code, PostgreSQL 16.15)

One segment per test for signed-in cases: a failed statement aborts the whole PostgreSQL transaction (25P02), so an
earlier looped version reported every segment as 500 and was rewritten before it was trusted.

| Group | Measured |
|---|---|
| R-03..R-07, signed-in (Super Admin) | 8 of 13 segment classes answered **HTTP 500 with an ERROR line** on all 11 representative routes: `abc`, a pasted `{INVOICE_ID}`, `12abc`, `1.5`, `1e3`, a fullwidth digit, `%ZZ`, a number beyond bigint. The other five (`-1`, `0`, `007`, ` 1`, 19 digits) are castable and answered 404. |
| R-03..R-07, user with **no permission** | `abc` answered **500**: implicit binding runs before the permission middleware, so any authenticated account could write these ERROR lines. |
| R-03..R-07, guest | all 13 matched the route (302 to login) instead of 404. |
| R-08, signed-in reader | a number beyond PHP's integer range answered **500** (the `int $visit` conversion fails). |
| R-08, leading-zero alias | `0<id>` **opened the real visit (200) on all five pages** — detail, print, PDF, odontogram print, prescription print. |
| Structural / compiled / cached matcher | every route lacked the pattern; Symfony's compiled matcher matched every non-key segment. |

Totals: first red run 94 tests, 66 failed; after isolating segments, 266 tests, 145 failed, 121 passed. The 121
passing on untouched code are the protections that already existed and must not change: a well-formed key naming no
record is 404 without an ERROR, a guest with a well-formed key is redirected to login, an in-branch visit opens, an
out-of-branch visit is 404, the reader permission gate holds.

Authorization order, verified on the untouched code by reverting the two production files by copy (not
`git checkout`) and checksum-restoring them afterwards: for a user without the permission, a **real record** already
answered 403 and a **nonexistent** well-formed key already answered 404; only `abc` was 500. The fix leaves the first
two exactly as they were.

## 4. Implementation

### 4.1 R-03..R-07 — global constraint

`App\Support\Routing\DatabaseRouteKey::CONSTRAINED_PARAMETERS` gains `rmeInvoice`, `medicalRecord`,
`rmePrescription`, `consent`, `odontogram` — the one-line extension rule 222 §1.5 prescribes. `register()` already
runs first in `AppServiceProvider::boot()`, so every route defined afterwards, and the compiled collection
`route:cache` writes, carries `[1-9][0-9]{0,17}`.

### 4.2 R-08 — per-route constraint, deliberately not global

`visit` is a generic name, and the oversight lookup already resolves visits by a **string** visit number
(`findVisitByNumberInBranches`). A global numeric `visit` pattern could silently 404 a future route keyed that way.
So the five routes replace `->whereNumber('visit')` with `->where('visit', DatabaseRouteKey::PATTERN)`, reusing the
canonical constant (no copied regex). 18 digits always fits a PHP integer, so `int $visit` can no longer overflow,
and the leading-zero alias no longer matches. The controller is unchanged.

### 4.3 Dependents checked

- `PostAuthenticationRedirectService::matchGetRoute()` (found through Graphify) matches the intended URL against the
  route table, so a malformed intended URL now falls back to the default landing. Intended direction.
- Behaviour change, stated: a leading-zero alias (`/rme/consents/007`) used to reach record 7 on PostgreSQL, which
  casts `'007'` to 7. It is now 404. `route()` never generates such a URL.
- Not changed: controllers, services, policies, permissions, middleware, binding order, schema, views.

Files: `app/Support/Routing/DatabaseRouteKey.php`, `routes/web.php` (5 routes + one import). Diff: 2 production
files.

## 5. Security review

- Authorization: unchanged and pinned (§3, §6). The route permission middleware, `visit.room`, policies and the
  oversight `clinic-oversight.view-visit` gate all still run for every key that can match. A non-key segment can
  name no record, so answering it 404 before the permission check discloses nothing.
- Branch isolation: unchanged. An out-of-branch oversight visit is still 404 (never a 403 that would confirm it
  exists); RME branch and doctor-scope suites ran in the regression (§6).
- No 500 is replaced by a 200 or a redirect to another record; malformed URLs are a plain 404.
- No log content added. No KTP/NIK or clinical value touched.
- R-02 (redacted lab-candidate failure log) unchanged; its suite re-ran green.

## 6. Tests

All runs on a throwaway PostgreSQL 16.15 container; counts from JUnit (the console shows every test as a "warning"
because the worktree has no environment file by design — JUnit's failure/error/skip counts are authoritative).

| Run | Tests | Failed | Errors | Skipped | Assertions |
|---|---|---|---|---|---|
| Red, untouched code (isolated) | 266 | **145** | 0 | 0 | — |
| New suites + Program 1 suites after the fix | 295 | 0 | 0 | 0 | 709 |
| Dependency-derived regression (275 files: RME, every `Rme*` billing module, ClinicOversight, Ui, Pilot, Auth, Cicd, AccessControl, Branch, and every other file referencing the 37 routes) | 5,006 | 0 | 0 | 0 | 24,702 |
| Governance suites re-run on the final rule, CI and manifest text (`tests/Feature/Cicd` + 64 files scanning rules, `ci_runner`, the workflow, the manifest or sprint docs) | 1,146 | 0 | 0 | 2 | 9,127 |

The two skips are environment guards that run only on a non-PostgreSQL connection
(`DbPerformanceGovernanceTest`, `PostgresRuntimeGovernanceTest`), correct on this engine.

CI selection proven with `pest --list-tests` using each exact critical-gate filter string: both variants select
205/205 and 61/61 of the new tests; the selected total moves from Program 1's 7,826 to 8,092 (+266, exactly the two
new suites).

New suites: `tests/Feature/RME/RmeRecordRouteKeyConstraintTest.php` (205 tests),
`tests/Feature/ClinicOversight/OversightVisitRouteKeyConstraintTest.php` (61). Program 1's
`ClinicVisitRouteKeyConstraintTest` (26) and `RmePaymentLabCandidateFailureLogPrivacyTest` (3) unchanged and green.

Mutation evidence (each applied by copy, verified to have changed the file, reverted, checksum-verified):

| Mutation | Result |
|---|---|
| M0 constraint removed entirely (the red run) | killed, 145 failures |
| M1 `consent` dropped from the global list | killed, 25 failures |
| M3 a looser `->whereNumber('odontogram')` added to one route | killed, 5 failures |
| M4 one oversight route back to `whereNumber('visit')` | killed, 5 failures |

## 7. Foundation rule synchronisation

- Rule 222 updated in place: governed-parameter table, the generic-name rule (§1.6), R-08 (§1.7), the
  binding-before-permission finding (§1.8), the intended-URL dependent (§1.9), the measured open inventory (§1.10),
  and the regression protection list.
- Domain index lines: `AIR-RMC-37` (`.claude/rules/rme-clinical.md`) extended to the four clinical parameters;
  new `AIR-RMB-46` (`.claude/rules/rme-billing.md`, which covers `ClinicOversight`) for `rmeInvoice` and the
  oversight visit routes; both canonical-rules table rows updated.
- `CLAUDE.md` unchanged: no critical global rule changed (CG-24).
- CI: tokens `RmeRecordRouteKey` and `OversightVisitRouteKey` added to BOTH critical-gate filter variants; both
  suites declared in `config/ci_runner.php` `critical_gate_mandatory_suites`.

## 8. CI, merge, deploy and production verification

Times are UTC with WITA (UTC+8) where the runbook uses it.

### 8.1 CI and merge

| Step | Evidence |
|---|---|
| PR | #595, head `358c3464` (tree `75c9afce`) |
| PR CI | run `41995243993`, conclusion success. Critical gate step: `Tests: 8092 passed (43651 assertions)`, `critical_test_exit_status=0` (Program 1 baseline 7,826 + the 266 new tests, as the local selection proof predicted). Selective module gate: Inventory 1,608; Lab 700 (1 skipped, as in Program 1); UI 983; Permission 708 (696 + the 12 new tests named for permission). Quality, Android, NSF-9, NSF-10 success. Classifier `unknown_high_risk`: every gate ran. The second critical variant is the inactive runner and skips by design. |
| Merge | squash 2026-11-05 09:34:57 UTC (17:34:57 WITA), `--match-head-commit 358c3464` → `e4766cbd4323557735b3dda74b740a1bdd1c0c54`, parent `c8c056e4`; merge tree `75c9afce` equals the tested candidate tree |
| Post-merge CI | push run `42003564110` on `e4766cbd`, conclusion success; critical gate again `8092 passed (43651 assertions)`, exit 0 |
| Full Suite | skipped on both runs: `full_suite_authorized=false`, `full_suite_defer_reason` `FULL_SUITE_NOT_ENABLED_FOR_EVENT` (PR) and `TEMPORARY_FULL_SUITE_POLICY_ACTIVE` (push). **Deferred, not passed** (CG-18). |

### 8.2 Production deployment (runbook §6b)

| Step | Evidence |
|---|---|
| Target proof | `production-target-check.sh --remote` → `PRODUCTION_TARGET=VERIFIED` (pinned host key, hostname `DaengtisiaMS`, `139.190.106.38`, admin `dmsadmin`), re-proved at 18:28 WITA |
| Base tip from the host | `ls-remote` on the production host printed `e4766cbd`, the CI-green release commit |
| Backup preflight | `dms-bk2-status`: newest package `BK2-20261104T183002Z`, `package_last_result=OK`, `db_backup_last_result=success`; off-server pull `ON_SERVER=OK OFF_SERVER=OK pending=0`; deploy at 18:29 WITA, outside the 02:25–02:40 window |
| Concurrency | no deploy or rollback process on the host; both peer sessions offline; no open PR against the base |
| Runner | `deploy-vps-runner.sh start` executed **on the VPS**: deploy id `20261105-102909`, 10:29:09 → 10:30:47 UTC |
| Result | `exit=0`; lock acquired; target pinned `e4766cbd`; immutable snapshot trusted and cleaned; pre-deploy dump `pre_auto_deploy_20261105-102910.sql` NSF-10-verified before migrate; `Nothing to migrate`; runtime isolation 107 GO / 0 FAIL; NSF-9 smoke 6 passed, 1 warning (the known loopback `127.0.0.1/login` 404, D6); `DEPLOY_HEAD_TARGET_MATCH=YES`; `DEPLOY OK` |
| Deploy-id verify | `verify 20261105-102909` → `target_commit=e4766cbd (checkout HEAD matches)`, `final exit=0`, **`DEPLOY RUNNER OK`** |
| Rollback reference | previous production commit `db425155` (code only, `scripts/rollback-vps.sh`) |

### 8.3 Production verification (read-only, canonical URL, guest)

A route that does not match answers 404 before any middleware runs, so guest requests prove the fix directly: no
test identity was used, no patient record was opened, no clinical or financial row was touched.

| Check | Result |
|---|---|
| Production HEAD / tree | `e4766cbd` / `75c9afce`, clean |
| `/login`, `/health/live`, `/health/ready`, `/health/lb` | 200 |
| `/storage/…` | 403 |
| R-03 `/rme/cashier/1/billing/{abc, %7BINVOICE_ID%7D, 99999999999999999999}`, `/rme/cashier/receivables/abc/follow-ups/create`, `POST …/billing/1.5/payment` | 404, no session cookie |
| R-04 `PATCH /rme/visits/1/medical-record/abc`, `POST …/medical-record/1e3/finalize` | 404, no session cookie |
| R-05 `/rme/prescriptions/abc/print` | 404, no session cookie |
| R-06 `/rme/consents/{abc, 007}`, `/rme/consents/abc/signature/patient` | 404, no session cookie |
| R-07 `/rme/odontograms/{abc, 99999999999999999999}/print` | 404, no session cookie |
| R-08 `/oversight/rme/visits/{99999999999999999999, 0123, 0}`, `/oversight/rme/visits/abc/pdf` | 404, no session cookie |
| R-01 regression `/rme/visits/abc` | 404 |
| Well-formed controls `/rme/cashier/1/billing/42`, `/rme/consents/42`, `/rme/prescriptions/42/print`, `/rme/odontograms/42/print`, `/oversight/rme/visits/42`, `/rme/visits/42` | 302 to login: the routes still match real keys |
| Invalid percent-encoding `%ZZ` | **400 from nginx**, before PHP. Shown by nginx's static page (no charset, nginx footer), identical for a path the application never served. The application's own answer for `%ZZ` is 404 (proven in the suite, which bypasses nginx). Recorded as an edge rejection, not as the 404 originally expected. |
| Application log | 1,467,295 bytes / 166 ERROR lines before the deploy, after the deploy, and after every probe: **0 new bytes** |
| Services | php8.3-fpm, nginx, PostgreSQL active; queue worker inactive and disabled (owner state); BK-2 and db-backup timers enabled; no scheduler unit; no deploy process left |

### 8.4 GO tag

Annotated `dms-rme-revision-program-2-go`, tag object `73dea54a`, peels to `e4766cbd`; pushed, and the remote tag
read from the production host peels to the production HEAD: **GO TAG TARGET = VERIFIED PRODUCTION RELEASE SHA**.
This evidence commit is docs-only and is not deployed; the tag does not move.

### 8.5 RME REVISION GO criteria (policy §7)

| Criterion | Result |
|---|---|
| Approved scope implemented | R-03..R-08 all implemented (none needed "no code change") |
| Tests first, red then green | red 266 / 145 failed on untouched code; green 295 / 0 |
| No regression | 5,006 regression + 1,146 governance tests, 0 failed |
| Authorization and branch isolation unchanged | pinned by test and by the untouched-code probe (§3) |
| CI on the exact SHA | PR and post-merge runs success; Full Suite deferred |
| Deployed by the runner on the VPS | `20261105-102909`, `DEPLOY RUNNER OK` |
| Production verified | §8.3 |
| Rules synchronised | §7 |

## 9. Residuals and follow-ups (not approved, not done)

Measured 2026-11-05 from the booted router; each needs its identifier type verified and its own owner approval:

| Parameter | Routes | Constraint today | Note |
|---|---|---|---|
| `diagnosis` | 7 | none | child of the medical-record routes |
| `receiptSnapshot` | 1 | `whereNumber` + `int` argument | same overflow class as R-08 |
| `patient` (oversight) | 3 + 5 elsewhere | `whereNumber` / none | generic name: per route |
| `handwriting`, `handwritingPage` | 1 + 1 each | `whereNumber` / none | oversight images |
| `record`, `page` | 19, 6 | `whereNumber` | legacy archive oversight |
| `candidate`, `mapping`, `issue`, `identifier`, `doctor` | 7, 5, 8, 2, 7 | none | outside the RME clinical path; type unverified |

The Full Suite is deferred under CG-18. Dusk browser suites that reference these routes were not run (no browser
leg in this change).
