# CICD-CTRL-3 — Dedicated Self-Hosted GitHub Actions Runner

**Branch:** `feature/cicd-ctrl-3-dedicated-self-hosted-ci-runner`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `0b37ce4` (do NOT target `main`)
**Type:** CI/CD control sprint — not a product feature sprint
**Status:** **WATCH — repository foundation complete and green; runner provisioning BLOCKED on host access.**

> **This sprint has no GO tag.** A GO tag requires a registered runner that is
> online, a real GitHub Actions job assigned to it, and a heavy CI run executed
> on it. None of that has happened, so claiming GO would be false. See §7.

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

## 4. Why this is safe to merge before a runner exists

`runner_mode` defaults to `github-hosted`, and the repository variable
`CI_RUNNER_MODE` is unset. Therefore:

- `critical_test_gate_self_hosted` **never runs** until an operator opts in.
- Every gate behaves exactly as it did before this sprint.
- The only behavioural change on GitHub-hosted runs is the **added** production
  database guard — a strictly stronger posture.

---

## 5. Runner host — verified, provisioning blocked on OS

The runner host is **`aishrunner`** (Dell Inspiron 14-3467, tailnet
`100.74.126.71`, LAN `192.168.1.21`), reached via the dedicated key
`~/.ssh/daengtisia_runner_ed25519` (host alias `daengtisia-runner`, user
`runner_dms`). The private key stays on the workstation, is never committed, and
the production VPS key was deliberately **not** reused.

**Hardware matches the approved specification:**

| Spec | Approved | Verified on `aishrunner` |
|---|---|---|
| CPU | i3-7020U, 2c/4t | Intel Core i3-7020U @ 2.30GHz, 2 cores / 4 threads ✓ |
| RAM | 16 GB | 14 GiB usable, 13 GiB free ✓ |
| Disk | SSD | SSD 238.5 GB, `/` 231 GB with **211 GB free (4% used)** ✓ |
| Chassis | Laptop | laptop ✓ |

It runs **no** GitHub Actions runner today (no service, no directory, no
process), so adopting it does not disturb the co-tenant *aish-pos* project. A
`github-runner` user (uid 1001) already exists. Production isolation is clean: no
DaengtisiaMS SSH key and no production application path.

### Blocker — operating system cannot supply the CI PHP version

The host runs **Ubuntu 26.04**, whose repositories ship **only PHP 8.5**. The CI
gate uses **PHP 8.3**, and `composer.lock` is resolved against it. Verified
2026-08-07:

- Ubuntu 26.04 (`resolute`): no `php8.3-*` or `php8.4-*` packages at all.
- `ondrej/php` PPA: **no `resolute` build** (HTTP 404); it stops at `noble`.
- Installing ondrej's `noble` packages on 26.04 would be a mixed-release install
  with conflicting `libssl`/`libc` dependencies — rejected as destabilising.

Running the self-hosted gate on PHP 8.5 was rejected because it breaks the
property that makes the gate worth having: a green self-hosted run would no
longer prove the authoritative 8.3 gate passes (rule CICDCTRL3-R009).

**Resolution chosen: reinstall the runner as Ubuntu 24.04 LTS**, which ships PHP
8.3 natively — no PPA, no source build, no mixed-release packages. The machine
holds almost nothing (8 GB used, no runner, no data), so the reinstall is cheap.

Secondary divergence to track once provisioned: the host offers PostgreSQL 18
while the GitHub-hosted gate uses `postgres:16`. Far lower risk than a PHP
mismatch, but it is a real difference and is recorded rather than ignored.

No machine was provisioned, no runner was registered, and no registration token
was minted.

### Privilege

`runner_dms` is in the `sudo` group but every invocation requires interactive
authentication, so no privileged provisioning step could run. The agreed
approach is a **temporary** `NOPASSWD` sudoers entry for `runner_dms`, removed as
the final provisioning step. The `github-runner` service user gets **no** sudo at
any point.

---

## 6. Remaining work (server-side)

1. Authorize the provisioning key on the runner host; verify hardware against the
   approved spec.
2. Provision per `docs/runbooks/self-hosted-runner.md` §3: service user, runtime,
   power settings, local CI PostgreSQL, registration as `daengtisia-ci-01`,
   systemd service.
3. Set the `CI_DB_PASSWORD` repository secret.
4. Real self-hosted smoke: a GitHub Actions job assigned to the runner, correct
   hostname and runtime user, health check GO.
5. Heavy CI validation on the runner (`CI_RUNNER_MODE=self-hosted`).
6. Outage validation: stop the service, confirm the job **queues** and the gate
   does not pass; restart and confirm it drains.
7. Fallback validation: flip to `github-hosted`, confirm an equivalent gate runs.
8. Benchmark Pest workers 1 / 2 / 3, recording duration, peak RAM, swap, load and
   temperature; set `pest_workers` from the measurement, not from core count.

---

## 7. GO criteria (none of which are met yet)

A GO tag `cicd-ctrl-3-dedicated-self-hosted-ci-runner-go` may be created only
when all of the following are true and evidenced:

- `daengtisia-ci-01` is registered and Online with all four labels.
- The runner service runs as `github-runner` under systemd, enabled and active,
  and survives a restart. No interactive `run.sh` remains.
- A real GitHub Actions job was assigned to and executed on that machine.
- Heavy CI passed on the runner.
- The CI database is local, non-production, and the guard passes there.
- The runner holds no production environment file, database credential, SSH key,
  or application path.
- Outage and fallback behaviour were both validated.
- A benchmark was recorded with real numbers.
- Authoritative CI passed on the exact candidate SHA.

---

## 8. Rollback

Set repository variable `CI_RUNNER_MODE` = `github-hosted` (or delete it). Heavy
CI returns to GitHub-hosted immediately with no code change and no redeploy.
Reverting this branch additionally removes the routing, the guard, and the
governance surface; the runner host, if provisioned, can be decommissioned
separately per the runbook §8.
