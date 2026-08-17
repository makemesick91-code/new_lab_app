# CICD-CTRL-3 — Dedicated Self-Hosted GitHub Actions Runner

**Branch:** `feature/cicd-ctrl-3-dedicated-self-hosted-ci-runner` (merged), closed out by
`feature/cicd-ctrl-3d-ci-self-test-gating-delivered-architecture-doc-closure`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target `main`)
**Type:** CI/CD control sprint — not a product feature sprint

**Status:**

```
CICD-CTRL-3:
IMPLEMENTED / RUNNER OPERATIONAL / EQUIVALENCE PROVEN
CI SELF-TESTS GATED / DELIVERED ARCHITECTURE DOCUMENTED
RESIDUAL: PRE-EXISTING FULL-SUITE APPLICATION DEBT (9 failures, out of scope)
```

| Dimension | Verdict |
|---|---|
| **RUNNER RESULT EQUIVALENCE** | **PASS** — GitHub-hosted and self-hosted produced identical results on the same commit |
| **CI SELF-TEST GATING** | **PASS** — `tests/Feature/Cicd` runs inside both required critical gate variants (CTRL-3D) |
| **REQUIRED PR GATES** | **PASS** — Classifier, Quality, Critical, Selective, NSF-9, NSF-10 |
| **FULL SUITE** | **RED — pre-existing** — 9 application failures that predate this sprint; 0 introduced by it |

> **SUPERSEDED — the residual is closed.** Everything below describes this
> sprint's own SHAs (`9484dd9` / `cbe9712`) and remains true of them. The nine
> residual failures were closed by **CICD-FIX-6** (`fe36f06`, run
> `31335720157` — 0 failed) and formally retired, per-failure, by
> **CICD-BASELINE-REVERIFY-1**. The current expected Full Suite failure
> baseline is **0**; any Full Suite failure today is a real regression and must
> not be attributed to this residual. See
> `docs/sprints/cicd-baseline-reverify-1-full-suite-baseline-stale-failure-debt-closure.md`.

> **The Full Suite red is this sprint working, not a regression.** Before
> CICD-CTRL-3, `php artisan test … | tee` reported *tee's* exit status, so the
> gate went green regardless. The last "successful" Full Suite before the merge
> (run `29592369812`, 2026-07-17) is recorded by GitHub as **success** while its
> own log reads `Tests: 1202 failed, 4223 warnings, 13 passed`. Strict exit
> propagation removed that mask. After CICD-FIX-1..5 the same gate now reports
> **10 failures**, of which **9 are proven pre-existing** and one — this
> sprint's own `SelfHostedHealthFailClosedTest` — was fixed in CTRL-3D.

---
## 1. Purpose

Route heavy CI to a dedicated self-hosted GitHub Actions runner without letting
any risky change bypass a quality, security, governance, or release-safety gate —
while keeping GitHub Actions as the authoritative control plane and keeping the
production VPS completely out of general CI.

---

## 2. What shipped in the repository

All of the following is implemented, tested, and green.

| Deliverable | Path |
|---|---|
| Runner contract | `config/ci_runner.php` |
| Production-database guard | `app/Console/Commands/CiAssertNonProductionDatabaseCommand.php` |
| Read-only posture scanner | `app/Support/Cicd/SelfHostedRunnerScanner.php` |
| Governance service (CICDCTRL3-R001..R012) | `app/Services/Foundation/SelfHostedRunnerGovernanceService.php` |
| Governance command | `foundation:self-hosted-runner-check` |
| Runner health check | `scripts/ci/self-hosted-runner-health.sh` |
| Hybrid routing | `.github/workflows/foundation-evidence-gates.yml` |
| Tests (27) | `tests/Feature/Cicd/DedicatedSelfHostedRunnerTest.php` |
| Runbook | `docs/runbooks/self-hosted-runner.md` |
| Rule mirror | `.cursor/rules/91-dedicated-self-hosted-ci-runner.mdc` |

### 2.1 Hybrid routing design

The `classify` job (always GitHub-hosted) resolves a `runner_mode` output with
precedence **workflow_dispatch input → repository variable `CI_RUNNER_MODE` →
`github-hosted`**. An unrecognised value falls back to `github-hosted`.

The critical regression gate exists as two variants that share the check name
`NSF-R011 Critical Test Gate` and carry mutually exclusive conditions, so the
required check name is preserved and appears exactly once per run:

- `critical_test_gate` — `ubuntu-latest`, Docker `postgres:16` service container.
- `critical_test_gate_self_hosted` — `[self-hosted, linux, x64, daengtisia-ci]`,
  **no** service container, using the runner's local loopback PostgreSQL.

The two variants run an **identical** test filter; only infrastructure differs.

**Why no Docker service container on the runner:** using one would require
Docker on the runner and the service user in the `docker` group, which is
root-equivalent. The sprint contract forbids that, so the self-hosted variant
uses a local, loopback-only CI database instead. That is also why the
self-hosted variant does not use `setup-php`/`setup-node` (they need sudo) and
does not use `actions/cache` (the runner's own caches are persistent, and
round-tripping them through GitHub would defeat the purpose).

### 2.2 Routing invariant — proven exhaustively

A test enumerates all 15 combinations of `runner_mode` × event × fork and
asserts **exactly one** variant runs in every one:

| runner_mode | event | fork | github-hosted | self-hosted |
|---|---|---|---|---|
| github-hosted | any | any | runs | skipped |
| self-hosted | pull_request | **yes** | **runs** | skipped |
| self-hosted | pull_request / push / schedule / dispatch | no | skipped | runs |
| (unset) | any | any | runs | skipped |

Consequences, all deliberate:

- **Unset mode is fail-safe** — CI keeps working on GitHub-hosted with no code change.
- **Fork PRs are redirected, not skipped** — untrusted code never reaches the
  runner or its secrets, and the gate still runs.
- **Never zero variants** — there is no combination that silently passes.
- **Runner outage queues the job** — a required gate stays unsatisfied until an
  operator explicitly falls back. There is no automatic failover by design.

### 2.3 Production database guard

`php artisan ci:assert-non-production-database` runs **before migrations in all
six DB-heavy jobs**, on both runner types. It is rule-based rather than
denylist-based: a CI database must be **local** and carry a **CI/test name**,
which blocks every remote database instead of only enumerated ones. An explicit
production denylist sits on top, to catch a local restore of a production dump.

Verified behaviour (real exit codes, subprocess):

| Case | Result |
|---|---|
| `testing` @ `127.0.0.1`, `APP_ENV=testing` | exit 0 |
| `daengtisia_ci` @ `127.0.0.1`, `APP_ENV=testing` | exit 0 |
| `asia_dental_lab_pilot` | exit 1 |
| `asia_dental_lab` | exit 1 |
| remote host | exit 1 |
| `APP_ENV=production` / `pilot` | exit 1 |
| production-like name / empty name | exit 1 |

It never opens a connection and never prints a credential.

---

## 2b. Runner result equivalence — PASS

The load-bearing claim of this sprint is that a self-hosted run proves the same
thing an authoritative GitHub-hosted run proves. That is now measured, not
asserted.

### Equivalence conditions held identical

| Condition | Value |
|---|---|
| Commit SHA | identical |
| PHP | **8.3** both sides (`8.3.33` from the pinned image on self-hosted) |
| PostgreSQL | **16** both sides (`postgres:16` service container / pinned `postgres:16` on 16.14) |
| Composer lock | identical |
| Test filter | identical (asserted by test) |
| `APP_ENV` | `testing` both sides |
| Execution mode | sequential both sides — **Pest `--parallel` is NOT enabled** |
| Exit-code propagation | strict `pipefail` + `PIPESTATUS` both sides |

### Measured results

| Run | Runner | Database | Failed | Warnings | Assertions | Distinct |
|---|---|---|---|---|---|---|
| `31143050283` | GitHub-hosted | `postgres:16` | **62** | 263 | 1106 | 61 |
| runner-local | self-hosted | host PostgreSQL **18** | 69 | 256 | 1080 | 68 |
| runner-local | self-hosted | pinned **`postgres:16`** | **62** | 263 | 1106 | 61 |

Failing-test-name sets between GitHub-hosted and self-hosted/PG16: **empty diff
in both directions**.

**Conclusion: RUNNER RESULT EQUIVALENCE = PASS.** The database major version was
the entire gap; on PostgreSQL 16 the runner is indistinguishable from the
authoritative gate.

## 2c. Application test gate — RED at the time, since resolved

Strict exit propagation made two bodies of long-standing CI debt visible. Both
pre-date this sprint and neither was fixed here.

> **Resolved by CICD-FIX-1..5.** Debt A and Debt B below are recorded as they
> stood when this sprint first went to WATCH. They were handed to the CICD-FIX
> series and closed there: the required Classifier, Quality, Critical,
> Selective, NSF-9 and NSF-10 gates are green on the merge commit. What remains
> is a separate residual — 9 pre-existing **Full Suite** application failures,
> catalogued in §10 and out of scope for CICD-CTRL-3.

### Proof they pre-date CICD-CTRL-3

| Run | Date | Branch | Runner | Failures | Distinct | CI verdict |
|---|---|---|---|---|---|---|
| `30189642130` | **2026-07-26** | base | GitHub-hosted | **62** | 61 | **`success`** |
| `31143050283` | 2026-08-07 | CICD-CTRL-3 | GitHub-hosted | 62 | 61 | same set |
| runner-local | 2026-08-07 | CICD-CTRL-3 | self-hosted + PG16 | 62 | 61 | same set |

The CICD-CTRL-3 branch was created 2026-08-06. The 2026-07-26 run pre-dates it by
eleven days, ran on GitHub-hosted, had the **identical** failing-test set — and
CI reported it **green**. That is the masking this sprint removed.

### Debt A — 62 Vite-manifest failures

```
Vite manifest not found at: .../public/build/manifest.json
  (View: resources/views/layouts/app.blade.php)
```

`critical_test_gate` installs Composer only — no `npm ci` / `npm run build` — so
no manifest exists when a test renders a view extending `layouts/app.blade.php`.
**Root cause analysis and fix belong to CICD-FIX-1; the fix is deliberately not
assumed to be "add npm build".**

### Debt B — 3 files failing `pint --test`

`routes/web.php` (`ordered_imports`),
`tests/Feature/Satusehat/Satusehat4dMultiBranchMatrixTest.php` (`no_unused_imports`),
`app/Console/Commands/StressSeedFoundationCommand.php` (5 fixers). None belong to
this sprint.

**Trap worth recording:** local pre-commit checks run `pint --dirty --test`
(changed files only) while CI runs `pint --test` (whole repository). A clean
`--dirty` run does not imply CI will pass.

## 3. Validation performed

| Check | Result |
|---|---|
| `tests/Feature/Cicd/DedicatedSelfHostedRunnerTest.php` | **27 passed / 83 assertions** |
| `SafeCiRuntimeControl` + `CicdEnterpriseGate` regression | **31 passed / 282 assertions** |
| `foundation:self-hosted-runner-check --strict` | **GO** (7/7) |
| `foundation:ci-runtime-control-check --strict` | **GO** (non-regression) |
| `foundation:cicd-enterprise-gate-check` | **GO** |
| `pint --dirty --test` | passed |
| `git diff --check` | clean |
| Workflow YAML parse + job/label/guard structure | verified |
| `bash -n scripts/ci/self-hosted-runner-health.sh` | clean |

The health script was executed on the development workstation and correctly
returned **NO-GO** there (production SSH keys present, PHP version mismatch,
insufficient free disk) — evidence that the isolation and resource checks
actually detect problems rather than rubber-stamping.

---

## 4. Runner — provisioned and operational

The runner in service is **`daengtisia-ci-biznet-01`** on host
**`daengtisia-ci-biznet`**, a Biznet Gio **NEO Lite MM 8.8** instance reached
over Tailscale at `100.121.146.97` (ssh alias `daengtisia-ci-biznet`, admin user
`daengtisiams`). Its public IPv4 is `103.89.5.23` with SSH blocked by UFW. The
production VPS key is deliberately **not** reused and no private key is
committed.

It is a **native** runner: Ubuntu 24.04 ships PHP 8.3 and PostgreSQL 16, so the
host itself provides the authoritative runtime and no container engine is
installed. This is a supported mode of `scripts/ci/self-hosted-php.sh`, not a
deviation — the contract is semantic runtime equivalence, not a particular
engine.

| Property | Verified |
|---|---|
| Runner | `daengtisia-ci-biznet-01` — online, labels `self-hosted, Linux, X64, daengtisia-ci` |
| Hardware | 8 vCPU / ~8 GB RAM, Ubuntu 24.04 LTS |
| Service | systemd, enabled + active, user `github-runner` (uid 1002), **never root** |
| Privilege | `github-runner` groups = `github-runner` only — no sudo, docker, lxd, adm, wheel, root, disk or shadow |
| Runtime mode | **`native`** — `runtime_mode=native / container_engine=none / php_source=host / php_version=8.3` |
| Container engine | **none** — podman and docker are not installed on the host |
| PHP runtime | host **8.3.6**, full extension set + Poppler, `memory_limit=-1` |
| CI database | **native** PostgreSQL **16.14**, database `daengtisia_ci`, **loopback only** |
| Production isolation | no production environment file, database credential, SSH key, or application path |
| Network | Tailscale active and enabled at boot; UFW active, default deny in, SSH only on `tailscale0`; public `103.89.5.23:22` blocked |
| NSF-9 / NSF-10 | **executed** (not skipped) under self-hosted routing |
| Fallback | flipping `CI_RUNNER_MODE` reroutes with no code change; full GitHub-hosted run succeeded |

The health gate on this host reports `passed=32 warnings=0 failed=0`,
`DECISION: GO`, including `ci_database … (PostgreSQL 16)` probed through the
real CI connection path and `postgres_exposure` confined to loopback.

### Host findings during provisioning

- The apt mirror `id.archive.ubuntu.com` served HTTP 403 for `libgpgmepp7` (a
  `libpoppler156` dependency), blocking installs. Switched to
  `archive.ubuntu.com`; backup kept as `ubuntu.sources.cicd-ctrl-3.bak`.
- No temporary CICD sudo grant exists on this host: `/etc/sudoers.d/` contains
  only `90-cloud-init-users` (the `daengtisiams` administrative break-glass
  account) and the stock README. No sudoers entry names `github-runner`.
- The first runner instance ran on a laptop (`aishrunner`) under rootless Podman
  with a containerised PostgreSQL 16; it was retired for being ~1.34× slower
  than GitHub-hosted. That host, and the container-mode provisioning it
  required, are documented as history in Appendix A of
  `docs/runbooks/self-hosted-runner.md`. Do not follow it for this runner.

## 5. Defects found and fixed during this sprint

### 5.1 NSF-9 / NSF-10 silently skipped under self-hosted routing

`release_safety_gate` depended only on `critical_test_gate`. Exactly one variant
runs and the other is **skipped**, and GitHub skips any job whose `needs` were
skipped — so routing heavy CI to the self-hosted runner silently skipped the
release-safety gate and, transitively, the evidence gate. Precisely the
silent-pass class this sprint exists to prevent.

Fixed: both depend on **both** variants and require that **one actually
succeeded**, so a failing gate still blocks while a skipped sibling does not.
Guarded by a test over every dependent of the critical gate, plus a scanner check.

**Found by executing the routing, not by inspecting it.**

### 5.2 A green gate that ran zero tests

The first self-hosted run reported the critical gate successful in two seconds.
It had not run: the official `php:8.3-cli` image defaults to `memory_limit=128M`
while `setup-php` runs unlimited, so Pest died in `TestSuiteLoader` — and
`| tee` hid the exit status.

Fixed: the image sets `memory_limit=-1` and the **build fails** if it is not
unlimited; strict exit propagation now applies on both runners.

### 5.3 Swallowed exit status (the masking itself)

Ten gate steps across both runners now use `shell: bash`
(`bash --noprofile --norc -eo pipefail`), with `PIPESTATUS` captured so evidence
is written before the status propagates. A Pest failure, an OOM, or a zero-test
run can no longer render green.

This is what exposed the pre-existing debt in §2c. It is **not** rolled back to
obtain a green gate, and the self-hosted variant is **not** made stricter than
the GitHub-hosted one.

## 6. Measured performance and the routing decision

Benchmarks here are **capacity evidence**, not a gate. Pest `--parallel` is
deliberately **not** enabled on any gate.

### Critical-gate duration — the valid like-for-like comparison

Same commit (`a1c51aa`), both green, sequential Pest on both sides, PHP 8.3 and
PostgreSQL 16 on both sides:

| Runner | NSF-R011 Critical Test Gate | Result | Evidence |
|---|---|---|---|
| GitHub-hosted `ubuntu-latest` | **18m52s** | success | run `31268973659` |
| Biznet `daengtisia-ci-biznet-01` | **57m16s** | success | run `31270364926` |

**≈3.04× slower on Biznet for that comparison.** Treat it as one measured data
point rather than a constant: GitHub-hosted wall-clock varies run to run, so the
ratio moves. The conclusion that holds, and the one acted on, is directional:

> Biznet is **consistently slower** than GitHub-hosted for the current
> sequential Pest workload. GitHub-hosted stays primary heavy CI; Biznet is
> secondary and manual-overflow capacity.

Its 8 vCPU do not change this — a single sequential Pest process is bound by
per-core speed, not core count.

### Retired host — historical only

The first runner, `daengtisia-ci-01` on the laptop `aishrunner` (i3-7020U, 2
cores), measured **1808 s vs 1347 s GitHub-hosted, ≈1.34× slower**, at equal
work. That figure describes `aishrunner` alone and **must not** be quoted for
Biznet. Full history in Appendix A of `docs/runbooks/self-hosted-runner.md`.

A retracted figure worth remembering: an earlier "307 s on the runner" claim was
**invalid** — measured while that host still ran PostgreSQL 18, where governance
tests short-circuited on aborted transactions and the suite simply did less
work. Never trust a wall-clock without checking the work done.

### Environment comparison status

The production VPS is **production** (real clinical data) and is never a CI
runner; any VPS figure is historical evidence about deployment, never CI
performance. Queue wait, assignment delay and cache benefit remain
`N/A — not measured`.

---

## 7. Closure sequence — completed

1. CICD-FIX-1..5 — the pre-existing Vite-manifest and PostgreSQL-portability
   debt that blocked the required gates. **Done.**
2. CTRL-3A — health gate fails closed; runtime evidence tells the truth.
3. CTRL-3B — NSF-9 / NSF-10 fail-closed exit propagation; dependency fix.
4. CTRL-3C — runtime evidence truthfulness, deterministic detection under
   `pipefail`.
5. Authoritative CI on the exact candidate `a1c51aa` — PR-event run
   `31268973659`, all six required gates green.
6. Outage-queueing validation — dispatch `31280434071` with the runner stopped:
   the self-hosted variant **queued** and its dependents were cancelled. No
   automatic failover, no false green.
7. Merge PR #267 → squash commit `9484dd9`. Candidate tree proven identical to
   the merge tree (`git diff` empty) — under this repository's squash convention
   a candidate is never a literal git ancestor of its merge commit.
8. Post-merge validation — required gates green; runner online, non-root,
   loopback-only PostgreSQL 16, public SSH blocked, Tailscale up.
9. **CTRL-3D** — the post-merge Full Suite exposed that this sprint's own
   `SelfHostedHealthFailClosedTest` was environment-dependent, and that
   `tests/Feature/Cicd` was in **no** required gate. Both fixed; see §9.
10. Immutable GO tag on the CTRL-3D closure commit.

No temporary CICD sudo grant exists on the Biznet host, so there is nothing to
revoke at closure.

---

## 8. Rollback

Set repository variable `CI_RUNNER_MODE` = `github-hosted` (or delete it). Heavy
CI returns to GitHub-hosted immediately with no code change and no redeploy.
Reverting this branch additionally removes the routing, the guard, and the
governance surface; the runner host, if provisioned, can be decommissioned
separately per the runbook §8.

---

## 9. CTRL-3D — CI self-test gating and delivered-architecture documentation

### 9.1 The gate could not fail on its own tooling

`SelfHostedHealthFailClosedTest` asserted that an unsuitable host PHP with no
container fallback reports `runtime_mode=unsatisfied` and exits non-zero. It
established "no container fallback" by setting `PATH=/usr/bin:/bin` — the host's
real bin directories. GitHub-hosted images ship `/usr/bin/podman`, so `auto`
correctly resolved **podman**, printed `runtime_mode=container` and exited 0.

The resolver was right; the test's environment assumption was wrong. It passed
on the dedicated runner and on a developer machine — neither has podman — and
failed only on GitHub-hosted.

The fix is a deterministic PATH seam (`stubRuntimeBin()`): a throwaway directory
containing only the commands the probe may reach, with `bash` and `php`
symlinked to the real binaries. Both halves of the contract are now stated
explicitly and tested as a pair, so making the fail-closed branch deterministic
cannot silently disable the legitimate container fallback:

| Condition | Expected |
|---|---|
| Host PHP unsuitable, **no** engine reachable | `runtime_mode=unsatisfied`, exit ≠ 0 |
| Host PHP unsuitable, podman reachable | `runtime_mode=container`, exit 0 |

Verified with podman genuinely reachable on PATH: the old probe returns
`runtime_mode=container` / exit 0 (the merged bug reproduced), the new probe
returns `runtime_mode=unsatisfied` / exit 1 on the same host.

### 9.2 The CI system's own tests were gated by nothing

The critical filter is a fixed allowlist and the selective gate covers only
Inventory / Lab / Ui / Permission / AccessControl. Neither matched
`tests/Feature/Cicd`, so this sprint's regression tests ran **only** in the
~3.5-hour Full Suite. A broken CI self-test could therefore reach the base
branch with every required gate green — which is exactly what happened.

`Cicd` is now a declared member of `ci_runner.critical_gate_required_filters`
and appears in the filter of **both** critical gate variants, so the coverage
holds whichever runner the job routes to. `SelfHostedRunnerScanner::criticalGateSelfTestPosture()`
enforces it, and the contract is tested positively *and* negatively — a workflow
where one variant drops the token must fail the check.

Proven end-to-end: with a deliberately failing CICD test present, the exact
critical filter selected it and the gate exited **1**. Removed immediately; no
failing test is committed.


---

## 10. Residual Full Suite debt — PRE-EXISTING, OUT OF SCOPE

> **CLOSED.** This section is retained as the historical catalogue of the nine —
> it is the authoritative record of what was failing at `cbe9712`. All nine were
> fixed by CICD-FIX-6 (`fe36f06`) and reconciled one by one in
> CICD-BASELINE-REVERIFY-1; the expected Full Suite failure baseline is now
> **0**. The recommendation at the end of this section ("open `CICD-FIX-6`") was
> carried out. Do not cite this list as current debt.
>
> **One claim below is WRONG and must not be reused:** the paragraph attributing
> the warning count to an absent `public/build/manifest.json` downgrading every
> layout-rendering test. `tests/TestCase.php` calls `withoutVite()`, which
> shipped **in this sprint's own merge `9484dd9`** — the very run those warnings
> were measured on — so `@vite` renders nothing and locally the same tests report
> `PASS`, not warning. The real cause of the warning count is **unidentified**;
> see CICD-BASELINE-REVERIFY-1 §4. What remains true here: warnings are not
> skips, and warnings never mask failures.

The post-merge Full Suite on `9484dd9` reported
`10 failed, 5637 warnings, 1 risky, 13 passed (26587 assertions)`. One
(`SelfHostedHealthFailClosedTest`) belonged to this sprint and is fixed in
CTRL-3D. The other **nine are pre-existing** and are deliberately **not**
touched here — not repaired, not weakened, not skipped.

**Attribution method.** Each failing test name was grepped in the log of the
last "successful" Full Suite before the merge (run `29592369812`, 2026-07-17 —
recorded `success` while reporting 1202 failures). All nine were already failing
there; `SelfHostedHealthFailClosedTest` scored zero hits because it did not yet
exist. Their test files are also **unchanged** by the merge diff.

| # | Test | Failure | Root cause | Present 2026-07-17 |
|---|---|---|---|---|
| 1 | `Architecture\Cache1GovernanceIntegrationTest` | `'GO'` vs `'WATCH'` | `release:evidence-check --profile=ci` returns WATCH — the Full Suite job never captures evidence artifacts | yes |
| 2 | `Architecture\Dbperf1GovernanceIntegrationTest` | same | same | yes |
| 3 | `Architecture\Dbperf2GovernanceIntegrationTest` | same | same | yes |
| 4 | `Architecture\Queue1GovernanceIntegrationTest` | same | same | yes |
| 5 | `Architecture\Rpt1GovernanceIntegrationTest` | same | same | yes |
| 6 | `Foundation\ReleaseSafetyEvidenceClosureTest` | same | same | yes (×2) |
| 7 | `Foundation\ReleaseSafetyEvidenceClosureTest` | same | same | yes |
| 8 | `Performance\Nsf21SqliteMigrationCompatibilityTest` | `'sqlite'` vs `'pgsql'` | the test assumes SQLite; the Full Suite job runs against the PostgreSQL service | yes |
| 9 | `Pilot\RmeSmokeTestRouteTest` | 302 vs 200 | Sprint 66 `EnsureRmeOnlineContext` redirect; the smoke seeder sets no Perawat context | yes (4 → now 1) |

Two job-shape gaps sit behind most of these and belong to a separate sprint:

- **Only `quality_gate` builds frontend assets.** `critical_test_gate`,
  `selective_module_gate` and `full_suite_gate` run no `npm ci` / `npm run
  build`, so `public/build/manifest.json` is absent and every test that renders
  a Blade layout emits a `file_get_contents` warning (~1143 in the full suite).
  PHPUnit downgrades those tests from *passed* to *warning*, which is why the
  critical gate's summary line reads `479 warnings (2014 assertions)` with a
  **zero passed count**. That is a reporting artifact, not a skip: the tests
  execute and assert.
- The Full Suite job captures **no** foundation evidence artifacts, so any test
  asserting `release:evidence-check … === 'GO'` sees `WATCH` by construction.

**Warnings do not mask failures.** A genuinely failing test is still counted and
still reddens the gate — the post-merge full suite reported `10 failed` beside
its 5637 warnings and its conclusion was `failure`, and one of those ten was
`SelfHostedHealthFailClosedTest`, a `tests/Feature/Cicd` test. Gating is
therefore unaffected by the downgrade; only the readability of the summary line
suffers. Restoring a build step to the test gates belongs to the same follow-up
sprint.

**Recommendation.** Open a dedicated corrective sprint (`CICD-FIX-6`) to close
the Full Suite job shape and then re-attribute anything that survives. Do not
convert these to green by weakening, skipping or deleting the tests: the whole
value CICD-CTRL-3 delivered is that this gate now reports the truth.
