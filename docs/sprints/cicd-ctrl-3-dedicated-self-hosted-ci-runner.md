# CICD-CTRL-3 — Dedicated Self-Hosted GitHub Actions Runner

**Branch:** `feature/cicd-ctrl-3-dedicated-self-hosted-ci-runner`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `0b37ce4` (do NOT target `main`)
**Type:** CI/CD control sprint — not a product feature sprint

**Status:**

```
CICD-CTRL-3:
IMPLEMENTED / RUNNER OPERATIONAL / EQUIVALENCE PROVEN
WATCH — PRE-EXISTING AUTHORITATIVE CI FAILURES
```

| Dimension | Verdict |
|---|---|
| **RUNNER RESULT EQUIVALENCE** | **PASS** — GitHub-hosted and self-hosted produce identical failure sets |
| **APPLICATION / TEST GATE** | **RED** — 62 pre-existing Vite-manifest failures, plus 3 files failing `pint --test` |

> **No GO tag. PR #267 not merged.** The runner is operational and its results
> are proven equivalent to the authoritative gate, but the application test gate
> is red for reasons that pre-date this sprint by eleven days. Those are handed
> to **CICD-FIX-1** (`docs/sprints/cicd-fix-1-vite-manifest-test-environment-recovery.md`)
> and are explicitly NOT fixed here. Closure resumes once CICD-FIX-1 is green.

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

## 2c. Application test gate — RED, pre-existing

Strict exit propagation made two bodies of long-standing CI debt visible. Both
pre-date this sprint and neither is fixed here.

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

The runner host is **`aishrunner`** (Dell Inspiron 14-3467 laptop, tailnet
`100.74.126.71`), reached via the dedicated key `~/.ssh/daengtisia_runner_ed25519`
(alias `daengtisia-runner`, user `runner_dms`). The production VPS key was
deliberately **not** reused and the private key is never committed.

**Hardware matches the approved specification:** Intel Core i3-7020U @2.30GHz
(2 cores / 4 threads), 14 GiB usable RAM, SSD with ~211 GB free, laptop chassis.

| Property | Verified |
|---|---|
| Runner | `daengtisia-ci-01` — online, labels `self-hosted, Linux, X64, daengtisia-ci` |
| Service | systemd, enabled + active, user `github-runner` (uid 1001), **never root** |
| Interactive `run.sh` | **none** — service cgroup holds only `runsvc.sh`, `RunnerService.js`, `Runner.Listener` |
| Privilege | `github-runner` groups = `github-runner users` — **docker and sudo removed** |
| Container engine | **rootless** Podman 5.7.0, user socket, no Docker involved |
| PHP runtime | **8.3.33** from a **digest-pinned** image, full extension set + Poppler, `memory_limit=-1` |
| CI database | pinned **`postgres:16`** (16.14), rootless, systemd `ci-pg16.service`, **loopback `127.0.0.1:5433` only** |
| Host PostgreSQL 18 | **untouched** on 5432 — a co-tenant project may depend on it |
| File ownership | `--userns=keep-id`; container writes owned by `github-runner`, **no root-owned residue** |
| Production isolation | no production environment file, database credential, SSH key, or application path |
| NSF-9 / NSF-10 | **executed** (not skipped) under self-hosted routing |
| Fallback | flipping `CI_RUNNER_MODE` reroutes with no code change; full GitHub-hosted run succeeded |

### Host findings during provisioning

- The pre-existing `github-runner` account was in **both the `docker` and `sudo`
  groups** — both root-equivalent. Nothing depended on them (no runner service,
  no processes, no crontab, no containers); both were removed.
- `runner_dms` requires interactive authentication for `sudo`, so provisioning
  uses a **temporary** `NOPASSWD` entry (`/etc/sudoers.d/99-cicd-ctrl-3-temp`),
  to be revoked at closure. `github-runner` gets **no** sudo at any point.
- The apt mirror `id.archive.ubuntu.com` served HTTP 403 for `libgpgmepp7` (a
  `libpoppler156` dependency), blocking installs. Switched to
  `archive.ubuntu.com`; backup kept as `ubuntu.sources.cicd-ctrl-3.bak`.

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

## 6. Benchmark — hardware capacity evidence only

Pest worker benchmark on `aishrunner`, identical workload, sequential authoritative
mode unchanged:

| Workers | Duration | Speedup | Peak swap | Min available RAM | Load after | Result |
|---|---|---|---|---|---|---|
| 1 | 326 s | 1.00× | 0 MB | 13994 MB | 1.14 | 69 failed / 256 passed / 1080 assertions |
| 2 | 206 s | 1.58× | 0 MB | 13896 MB | 2.48 | identical |
| 3 | 162 s | 2.01× | 0 MB | 13749 MB | 2.87 | identical |

All three produced **identical results**, so parallelism does not perturb
outcomes on this hardware. No swap, no OOM.

> **`--parallel` is NOT enabled on the authoritative gate** and will not be while
> the GitHub-hosted gate is sequential. These numbers are capacity evidence only.

> **THERMAL METRIC = UNRELIABLE.** The probe returned exactly 25.0 °C in every
> configuration, which is not a credible CPU package reading. `lm-sensors` is not
> installed. No no-throttling claim is made from this data.

### Critical-gate duration — valid comparison

Both sides doing **identical work** (same commit, PHP 8.3, PostgreSQL 16,
identical filter, sequential, identical 62-failure result):

| Environment | Database | Critical-gate duration | Work done |
|---|---|---|---|
| GitHub-hosted | `postgres:16` service container | **1347 s** (22m27s) | 62 failed / 1106 assertions |
| `aishrunner` | pinned `postgres:16` | **1808 s** (30m08s) | 62 failed / 1106 assertions |
| ~~`aishrunner`~~ | ~~host PostgreSQL 18~~ | ~~307 s~~ | **INVALID — 69 failed / 1080 assertions** |

**`aishrunner` is 1.34× SLOWER than GitHub-hosted** for the same work
(−34.2% time change). That is expected: an i3-7020U with 2 physical cores at
2.3 GHz against GitHub's cloud runners.

> **Retracted measurement.** The 307 s figure was reported earlier in this sprint
> as the runner's heavy-CI duration. It is **not valid**: on the host's
> PostgreSQL 18 the expensive governance tests short-circuited on aborted
> transactions — `fg1 ci check` took **0.21 s** there versus **68.37 s** on
> GitHub-hosted — so the suite was doing materially less work. A faster wall
> clock there measured broken behaviour, not speed. Only the PostgreSQL 16
> figure is comparable.
>
> Database performance is **not** the cause of the 1808 s: a direct probe shows
> the PG16 container is marginally *faster* than the host PG18 (bulk insert 84 ms
> vs 95 ms; connect+commit 459 ms vs 632 ms), and every relevant PostgreSQL
> setting is identical between them. The difference is CPU-bound test execution.

Queue wait, runner assignment delay, persistent-cache benefit, and the
setup/composer/npm/cleanup breakdown are **not yet measured** and are marked
`N/A — not measured`. They matter for the final decision, because a self-hosted
runner can reduce queue delay without being faster at compute.

### Environment comparison status

A full GitHub-hosted vs `aishrunner` vs VPS timing table is **deferred** until
CICD-FIX-1 is green, because a performance comparison is only meaningful once
both runners produce the same result — and today both are red for the same
pre-existing reason.

**The VPS cannot be a live comparison point.** `srv1730088` is production
(`APP_ENV=pilot`, `asia_dental_lab_pilot`, **32 patients / 26 clinic visits of
real clinical data**, 2 vCPU, 7.8 GiB). It gets no heavy CI, no full Pest, no
DB-heavy tests, no CPU benchmark. Any figure from it is
`SOURCE = HISTORICAL EVIDENCE`, never a live identical benchmark. There is **no
separate dedicated CI VPS**. It also runs **PHP 8.5.8**, so even hypothetically
it would be a `NON-EQUIVALENT PERFORMANCE REFERENCE` for a gate pinned to 8.3.

Deployment timing (`scripts/deploy-vps-runner.sh`) is
**PRODUCTION DEPLOYMENT PERFORMANCE** and must never be reported as CI runner
performance.

## 7. Closure sequence — blocked on CICD-FIX-1

Nothing below may proceed until **CICD-FIX-1** proves 62 failures → 0 without
weakening coverage:

1. Authoritative CI on the exact candidate SHA
2. Outage-queueing validation (stop the runner service; the job must **queue**,
   never pass)
3. Final GitHub-hosted vs `aishrunner` timing comparison
4. Historical VPS timing comparison where valid, clearly labelled
5. Merge PR #267
6. Post-merge runner validation
7. Revoke the temporary `NOPASSWD` sudoers entry
8. Clean temporary state (containers, scratch scripts, evidence staging)
9. Immutable GO tag

Until then the temporary sudo entry and all runtime evidence are **deliberately
preserved** so the environment stays reproducible for CICD-FIX-1.


## 8. Rollback

Set repository variable `CI_RUNNER_MODE` = `github-hosted` (or delete it). Heavy
CI returns to GitHub-hosted immediately with no code change and no redeploy.
Reverting this branch additionally removes the routing, the guard, and the
governance surface; the runner host, if provisioned, can be decommissioned
separately per the runbook §8.
