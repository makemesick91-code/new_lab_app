# DMS-RME-REVISION-PROGRAM-3 — RME route-key hardening: diagnosis, receipt snapshot, oversight patient, handwriting, legacy archive

- **Programme:** RME revision programme under owner policy `DMS-FULL-DEVELOPMENT-PRODUCTION-TESTING-20261104`
  (rule 221 §7), mission `DMS-RME-REVISION-PROGRAM-3` (P0, SECURITY_FIX), 2026-11-05. Continuation of
  [[dms-rme-revision-program-1]] and [[dms-rme-revision-program-2]].
- **Status:** **FINAL GO** — merged (PR #597, `c7531932`), deployed on Biznet (deploy `20261105-131831`),
  production verified, GO tag `dms-rme-revision-program-3-go` on the deployed commit (§8). Full Suite deferred
  (CG-18), not passed.
- **Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `e48fc774` (verified from
  `origin` after fetch; `devflow:base-ref-check --strict` resolved the canonical remote; the local base ref was stale
  at `9eabff07` and was not used). Production runs `e4766cbd` (Program 2 GO); the base is one docs-only commit (#596)
  ahead.
- **Branch:** `feature/dms-rme-revision-program-3`.
- **Canonical rule:** `.cursor/rules/222-rme-route-key-constraint-and-failure-log-redaction.mdc` (extended in place:
  it owns this invariant, so no parallel rule was created).
- **Work category under the Foundation-First Sprint Lock:** security fix (allowed before Foundation GO). The lock is
  unchanged. No feature work, no clinical or financial behaviour change.

## 1. Governance preflight

### 1.1 Owner approval (recorded per CG-22)

| Id | Decision | Source |
|---|---|---|
| OD-P3 | Implement R-09..R-15 — `{diagnosis}`, `{receiptSnapshot}`, oversight `{patient}`, `{handwriting}`, `{handwritingPage}`, `{record}`, `{page}` — the seven follow-ups Program 2 recorded in rule 222 §1.10, end to end through production GO | Owner mission brief `DMS-RME-REVISION-PROGRAM-3`, 2026-11-05 |

The approval names the parameter groups. It explicitly excludes `{candidate}`, `{mapping}`, `{issue}`,
`{identifier}` and `{doctor}`, and does not approve other parameters found unconstrained (§9).

### 1.2 Policy

- Rule 221: development, production deploys and controlled production testing allowed; operational clinic devices
  frozen; clinic reopening not authorised. The Biznet database is a testing database by owner declaration but may
  hold real personal data, so every privacy rule applies.
- CG-18: the GLOBAL TEMPORARY FULL-SUITE POLICY is ACTIVE (`.github/ci-policy/full-suite-policy.json` →
  `status: ACTIVE`); the Full Suite is deferred, not passed.

### 1.3 DEVFLOW (isolated worktree, throwaway PostgreSQL 16.15 container)

| Command | Exit | Result |
|---|---|---|
| `devflow:base-ref-check --strict` | 0 | canonical base `e48fc774`; local base stale (`9eabff07`), canonical remote wins |
| `sprint:manifest-check` | 0 | GO — manifest valid and consistent |
| `sprint:scope-audit --strict` | 0 | GO |
| `sprint:test-plan` | 0 | fails closed to FULL REQUIRED SUITE (12 files match no category; `ci_cd`/`config` force it). Under CG-18 the Full Suite is deferred; a dependency-derived regression ran instead (§6) |
| `foundation:devflow-check --strict` | 0 | GO (no destructive markers; CICD invariant; base authority; shared registry) |
| `foundation:shared-service-audit --strict` | 0 | 10 canonical services, 0 errors, 0 warnings |

### 1.4 Tooling

- Skills: `limit-saver` (targeted reads only), `graphify-navigator` + Graphify 0.8.35 (`graphify update .` in the
  mission worktree — 43,232 nodes; confirmed `DatabaseRouteKey` has one registration point,
  `AppServiceProvider::boot()`), the `superpowers` discipline (TDD red→green, verification before claims),
  `knowledge-base` conventions for this doc. `graphify-out/` is not gitignored here and was never staged.
- MCP: none needed beyond the CLI. The GitHub, PostgreSQL and filesystem work used `gh`, `docker`/`psql` and git
  directly. An independent review sub-agent could not be launched: auto mode refused the call twice because a
  plugin hook (context-mode) rewrites the Agent input after review. The review in §5 was done in the main session.
- Dataviz: used only for the private evidence dashboard (synthetic counts, no patient data).

## 2. Inventory (measured from the booted router)

Every route carrying one of the seven names, with how its identifier reaches the database. None had an explicit
`Route::bind` and no model involved overrides its route key.

| Parameter | Routes | Identifier | Reaches the DB as | Constraint before | Now |
|---|---|---|---|---|---|
| `diagnosis` | 7 | `trx_medical_record_diagnoses.id` (2 medical-record routes), `mst_clinical_diagnoses.id` (5 SATUSEHAT terminology routes), both bigint `id()` | implicit binding | none | global `PATTERN` |
| `receiptSnapshot` | 1 | `trx_rme_receipt_snapshots.id` (bigint) | `int $receiptSnapshot` → `whereKey()` | `whereNumber` | global `PATTERN` (override removed) |
| `patient` (oversight) | 1 | `mst_patients.id` (bigint) | `int $patient` → visible-patient lookup | `whereNumber` | per route `PATTERN` |
| `handwriting` | 2 | `trx_medical_record_handwritings.id` (bigint) | implicit binding | none / `whereNumber` (oversight) | global `PATTERN` (override removed) |
| `handwritingPage` | 2 | `trx_medical_record_handwriting_pages.id` (bigint; the page ROW's key, not a page index) | implicit binding | none / `whereNumber` (oversight) | global `PATTERN` (override removed) |
| `record` | 19 | `trx_rme_legacy_records.id` / `trx_odontogram_legacy_records.id` (bigint) | `int $record` → scoped lookup | `whereNumber` | per route `PATTERN` |
| `page` | 6 | `page_number` (`unsignedInteger` = PostgreSQL 4-byte `integer`), 1-based | `int $page` → `where('page_number', …)` | `whereNumber` | per route `PAGE_NUMBER_PATTERN` |

Program 2 counted the oversight `{patient}` as "3"; the measured oversight route is one — the other two were the
`settings.patients.documents.*` routes, which are master data and stay out of scope (§9).

Why `{page}` is different: it is not a key. Its column is int4 (ceiling 2,147,483,647) and pages are numbered from 1
by the rasterizer; configured caps are 200 (RME) and 50 (odontogram) pages per document. The 18-digit key pattern
would still let an 11-to-18-digit page reach an int4 comparison. A new constant, `PAGE_NUMBER_PATTERN`
`[1-9][0-9]{0,8}` (1..999,999,999), fits int4 and PHP's integer; `0` and leading zeros are refused.

Compatibility: Laravel's URL generator does not validate `wheres`; every `route()` call for these names builds from a
real row id or a stored `page_number`; no JavaScript builds these URLs by string concatenation.

## 3. Red evidence (untouched code, PostgreSQL 16.15)

The four new suites were written first and run against the base routes (the unused `PAGE_NUMBER_PATTERN` constant
was added beforehand only so the suites could load). 166 of 420 cases failed, each for a routing reason:

- **R-09, R-12, R-13 (RME side):** on all six representative routes, 8 of 13 non-key segment classes answered
  **HTTP 500** to a signed-in user (`abc`, placeholder, `12abc`, `1.5`, `1e3`, fullwidth digit, `%ZZ`, beyond bigint) —
  48 cases. A user holding **no permission** also got 500 for `abc` (binding runs before the permission middleware),
  and `0<id>` of a real record reached binding and met the permission gate (403) instead of 404.
- **R-10:** the signed-in cashier got **HTTP 500** for a number beyond PHP's integer range, and `0<id>` of a real
  issued snapshot **reprinted the real receipt (HTTP 200)**. A genuine guest got the login redirect (302), not 500.
- **R-11, R-14 (oversight + archive viewers):** a number beyond PHP's integer range answered **HTTP 500** on every
  `int`-argument route (12 oversight routes, 8 archive-viewer GET routes; the two void POST routes redirected through
  their FormRequest, 302, for every segment `whereNumber` let through); `0<id>` opened — and audited — a real patient
  history and real archives (HTTP 200).
- **R-15:** on all six page routes, ten, eleven and eighteen digits answered **HTTP 500** — PostgreSQL SQLSTATE
  22003 (`value "99999999999" is out of range for type integer`, reproduced directly with a prepared `integer`
  parameter) — and beyond PHP's integer range answered 500 (TypeError). `01` opened the real page 1 (HTTP 200) on the
  four record page routes.

Correction recorded, not hidden: an early reading of the receipt suite claimed a guest got 500. The fixture signs
the cashier in while recording the payment, so that case was not a guest. Re-measured with the session dropped, a
guest gets 302. The suite now drops the session before its guest assertions and asserts `auth()->check()` is false.

## 4. Implementation

### 4.1 R-09, R-10, R-12, R-13 — global constraint

`diagnosis`, `receiptSnapshot`, `handwriting` and `handwritingPage` were added to
`DatabaseRouteKey::CONSTRAINED_PARAMETERS`. The per-route `whereNumber` on the receipt reprint route and on the two
oversight image routes was **removed**: a route-level constraint overrides the global pattern (rule 222 §1.4).

### 4.2 R-11, R-14, R-15 — per-route constraint, deliberately not global

`patient`, `record` and `page` are generic names. Each `whereNumber` was replaced on its route only:
`->where('patient'|'record', DatabaseRouteKey::PATTERN)` and `->where('page', DatabaseRouteKey::PAGE_NUMBER_PATTERN)`.
`{import}` on the two settings import page routes keeps its `whereNumber` (out of scope, §9).

### 4.3 What did not change

No controller, service, repository, policy, middleware, migration, permission or view changed. Authorization, branch
scope (`RmeWorkingBranchScope`, the legacy workspace scopes, the oversight RME-branch scope), parent-child ownership
checks, the receipt policy, VOID handling, private storage and audit writes are byte-identical.

## 5. Security review

Done in the main session against the code, not the comments (§1.4):

- Every route carrying each globally constrained name binds a bigint `id()` (migrations checked); no
  `getRouteKeyName`/`resolveRouteBinding` override on those models; no remaining per-route constraint for them (the
  structural tests assert it, live and compiled).
- No authorization or branch behaviour changed; no route lost a middleware or a name (diff is constraint-only).
- The permission order is preserved: model-bound route, real record, no permission → 403; well-formed key with no
  record → 404; non-key segment → 404. `int`-argument routes answer their permission middleware first, as before.
- The leading-zero alias is closed for every parameter, so a non-canonical URL can no longer open or audit a record.
- No new information disclosure: every refusal is a plain 404 from the router.

Findings: none open. The receipt-guest misreading in §3 was a test-harness error, corrected.

## 6. Tests

New suites (PostgreSQL 16.15 and SQLite):

| Suite | Cases | Covers |
|---|---|---|
| `tests/Feature/RME/RmeChildRecordRouteKeyConstraintTest.php` | 115 | R-09, R-12, R-13 RME side; global list (no generic name); compiled collection; cached matcher; guest + signed-in 404 with no ERROR; permission order incl. real-key alias; diagnosis parent-child ownership |
| `tests/Feature/RmeInvoiceCorrection/RmeReceiptSnapshotRouteKeyConstraintTest.php` | 6 | R-10 on a real paid invoice's snapshot: canonical reprints, `0<id>`/`00<id>` 404, policy, cross-invoice 404, snapshot digest untouched |
| `tests/Feature/ClinicOversight/OversightRecordRouteKeyConstraintTest.php` | 149 | R-11..R-15 oversight: right pattern per route (live, compiled); guest + reader 404 with no ERROR; 10 page classes; canonical opens, aliases 404; reader gate; MAIN branch 404 |
| `tests/Feature/LegacyRme/LegacyArchiveRouteKeyConstraintTest.php` | 150 | R-14, R-15: the 19 `{record}` and 6 `{page}` routes by name; page pattern inside int4; guest + signed-in 404 incl. import page routes; canonical opens, aliases 404; VOID and permission unchanged |

`tests/Feature/RME/RmeRecordRouteKeyConstraintTest.php` (Program 2): its exact-list pin was **extended** to the ten
global names; nothing was relaxed.

Results:

| Run | Database | Cases | Passed | Failed | Skipped |
|---|---|---|---|---|---|
| Red (base routes) | PostgreSQL 16.15 | 420 | 254 | 166 | 0 |
| Green, new + Program 2 record suite | PostgreSQL 16.15 | 625 | 625 | 0 | 0 |
| Green, same | SQLite | 625 | 625 | 0 | 0 |
| Dependency regression: 43 dependent files + all Program 1/2 route-key and redaction suites (51 files) | PostgreSQL 16.15 | 1540 | 1535 | 0 | 5 |
| Focused governance: `tests/Feature/{Architecture,Cicd,Foundation}` + legacy closure contract + runbook command guard | PostgreSQL 16.15 | 1347 | 1343 | 1 → 0 | 3 |

- Provenance of the PostgreSQL green figure: the first green run (19:40 WITA) had 1 failure — the receipt "guest"
  test, which was not a guest (§3). After that test was corrected, the dependency-regression run (19:47 WITA) contains
  all 625 cases (115 + 6 + 149 + 150 + 205) with 0 failures; that run is the PostgreSQL evidence for the 625/625 row.
  CI then ran the same suites on PostgreSQL inside the critical gate (§8).
- The 5 regression skips are `LegacyRmeClinicalReadPrintTest` PDF-export cases guarded by `extension_loaded('gd')`
  (no GD locally; CI installs it). The 3 focused skips are environment guards (Redis runtime disabled; two
  non-pgsql-connection cases that skip on PostgreSQL). One test was marked risky in the focused run; it is not a
  failure.
- The one focused failure was real and is fixed: the first `AIR-RMB-47` line pushed `.claude/rules/rme-billing.md`
  to 30,286 characters, over the 30,000-character on-demand truncation budget `DevflowAiInstructionSystemTest`
  enforces. It was compressed to a pointer (29,960); the test passes 16/16.
- `route:cache` round trip: the compiled collection carries every new constraint (checked by name), then cleared.
- `vendor/bin/pint --test` passes on every changed PHP file.
- Every test count above comes from JUnit, not Pest's summary line, which marks every test as a warning in a
  worktree with no environment file.

CI selection (CG-19): tokens `RmeChildRecordRouteKey`, `RmeReceiptSnapshotRouteKey`, `OversightRecordRouteKey` and
`LegacyArchiveRouteKey` were added to BOTH critical-gate variants (still byte-identical), and the four suites are
declared in `config/ci_runner.php` `critical_gate_mandatory_suites`. Proven by `pest --filter=<the exact CI filter>
--list-tests`: all 420 new tests are selected (115 + 6 + 149 + 150), and the critical selection grew from 8,092 to
8,512 — exactly +420. `CriticalGateSuiteCoverageTest` passes.

## 7. Foundation rule synchronisation

- `.cursor/rules/222-…mdc` extended: description and globs; §1.1 table (+4 global names with their binding); §1.4
  (three overrides removed); §1.5 (a name may bind more than one model if each is bigint); §1.6 (pin a generic name on
  explicit route names); §1.8 note on `int`-argument routes; §1.9 URL generation; new §1.10 (Program 3 per-route
  constraints), §1.11 (a page number is not a key — check the column type), §1.12 (residuals); §3 (eight suites).
- `.claude/rules/rme-clinical.md` AIR-RMC-37 and index row; `.claude/rules/rme-billing.md` new AIR-RMB-47 (pointer)
  and index row; `.claude/rules/legacy-rme.md` new AIR-LRM-39 and index row.
- `.sprint/current.yml` → `DMS-RME-REVISION-PROGRAM-3`, `go_tag: dms-rme-revision-program-3-go`,
  `inherits_closure: LEGACY-RME-PROGRAM-CLOSURE-1` kept verbatim.
- `CLAUDE.md` unchanged (CG-24: no global rule changed).

FOUNDATION RULE SYNC — GO: `DevflowAiInstructionSystemTest` 16/16, `LegacyRmeProgramClosureContractTest` and
`ProductionShellCommandGuardTest` pass.

## 8. CI, merge, deploy and production verification

Recorded after release (docs-only evidence update; this commit is not deployed and the GO tag does not move).

**CI** — run `42024573797` on the exact candidate `7058279637fe878c4eaeaaec32a9299e5fd9ed15`: CICD-CTRL Classifier,
NSF-R012 Quality, NSF-R011 Critical Test Gate, CICD-CTRL Selective Module, NSF-9 Release Safety & Automated Smoke,
NSF-10 Release Evidence and Phase 3 Android Clinic App Gate all **success**. Critical gate: `8512 passed (44626
assertions)` in 3570.96s, read from the step log. The self-hosted critical variant was skipped by design (exactly one
variant runs). **Full Suite: DEFERRED, not passed** — classifier `temporary_full_suite_policy_active=true`,
`full_suite_authorized=false`, `full_suite_defer_reason=FULL_SUITE_NOT_ENABLED_FOR_EVENT`; `run_full_suite=required`
is a risk signal, not an authorisation (CG-18). The post-merge push run `42034369872` defers it again with
`TEMPORARY_FULL_SUITE_POLICY_ACTIVE`.

**Merge** — PR #597 squash-merged with `--match-head-commit` as `c753193275a5d982bac07ea7347fecfe52e3019d`; merge tree
`f42949ac6c98b48792d035a4310cba9e318a3b53` equals the candidate tree; parent `e48fc774`. No other open PR against the
base; both peer sessions offline.

**Pre-deploy** (21:17–21:19 WITA, outside the 02:25–02:40 backup window): `production-target-check.sh --remote` →
`PRODUCTION_TARGET=VERIFIED` (Biznet 139.190.106.38, pinned host key); on-host `ls-remote` of the base = merge SHA;
production HEAD `e4766cbd` with a clean tracked tree; no deploy process; BK-2 package and db-backup last
`Result=success`; off-server pull `ON_SERVER=OK OFF_SERVER=OK pending=0`; log baseline 1,467,295 bytes / 166 ERROR.

**Deploy** — on the Biznet VPS via `sudo -n bash scripts/deploy-vps-runner.sh start`, deploy id `20261105-131831`
(13:18:31–13:20:07 UTC): lock acquired, target pinned to the merge SHA, snapshot trusted, pre-deploy backup
`pre_auto_deploy_20261105-131832.sql` (NSF-10 backup verify 9/9 GO), `Nothing to migrate.`, runtime isolation
107 GO / 0 FAIL, `DEPLOY_HEAD_TARGET_MATCH=YES`, `exit=0`, `DEPLOY OK`, snapshot cleaned; `verify 20261105-131831`
→ `DEPLOY RUNNER OK`. The four deploy warnings are byte-for-byte the same set as Program 2's deploy `20261105-102909`
(risky flags enabled by deliberate env override, PgBouncer not running, local-profile evidence chain, loopback
`127.0.0.1/login` 404 from the co-tenant default server); none is new.

**Production verification** (guest, over `https://daengtisia.online`):

| Probe set | Before | After |
|---|---|---|
| 20 malformed segments across R-09..R-15 | matched routes: 302 to login, 405, 419 | **404, 0 cookies** (router refusal before any session) |
| 5 well-formed controls | 302 to login | 302 to login (unchanged) |
| `/login`, `/health/live`, `/health/ready`, `/health/lb` | 200 | 200 |
| `/storage/<synthetic>` | 403 | 403 |
| `laravel.log` | 1,467,295 bytes / 166 ERROR | 1,467,295 bytes / 166 ERROR (zero new) |

Production HEAD/tree = merge SHA/tree; `No pending migrations`; environment `pilot`, debug OFF, maintenance OFF;
nginx, php8.3-fpm and postgresql active; queue worker inactive/disabled as before; backup timers unchanged; no deploy
process left behind. No authenticated request, no test suite and no write was run against production.

**GO tag** — annotated `dms-rme-revision-program-3-go` on `c753193275a5d982bac07ea7347fecfe52e3019d`; from the
production host the remote tag peels to the production HEAD (`REMOTE GO TAG TARGET = VERIFIED PRODUCTION HEAD`).

## 9. Residuals and follow-ups (not approved, not done)

Measured 2026-11-05; each needs its identifier type verified and its own owner approval:

| Parameter | Routes | Constraint today | Note |
|---|---|---|---|
| `import` | legacy import routes (incl. the two page routes) | `whereNumber` + `int $import` | same overflow/alias class as R-14 |
| `patient` (settings) | 5 + 2 | none / `whereNumber` | master data, generic name: per route |
| `document` | 2 | `whereNumber` | patient documents |
| `candidate`, `mapping`, `issue`, `identifier`, `doctor` | 7, 5, 8, 2, 7 | none | excluded by OD-P3; type unverified |

The Full Suite is deferred under CG-18. No Dusk browser suite was run (no browser leg in this change).
