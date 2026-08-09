# Runbook — Dedicated Self-Hosted CI Runner (CICD-CTRL-3)

**Purpose.** Provision, operate, fall back from, and decommission the dedicated
DaengtisiaMS GitHub Actions self-hosted runner.

**Scope.** Heavy CI execution only. This runner is **not** a deployment machine.

**Owner.** Repository maintainer.
**Review cadence.** Each CICD-CTRL sprint, or after any runner hardware change.

---

## 1. Boundaries (read before touching anything)

| Machine | Role | May run CI? | May deploy? |
|---|---|---|---|
| Production VPS | DaengtisiaMS runtime + production PostgreSQL | **No** | Yes, via `scripts/deploy-vps-runner.sh` executed **on the VPS** |
| Dedicated CI runner | GitHub Actions heavy jobs + CI PostgreSQL | Yes | **No** |
| GitHub-hosted runners | Classifier, always-on gates, fallback | Yes | Deploy workflow only (`ubuntu-latest`, SSH to VPS) |

GitHub Actions remains the authoritative CI control plane. A self-hosted runner
adds capacity and control; it does **not** make CI independent of a GitHub
Actions outage.

---

## 2. Prerequisites

### 2.1 The authoritative runtime is whatever provides PHP parity

The contract is **semantic runtime equivalence** — the same PHP major.minor, the
same extension set and the same Poppler binaries as the GitHub-hosted critical
gate. *How* that runtime is supplied is a property of the host, and
`scripts/ci/self-hosted-php.sh` resolves it:

| Mode | When it applies | What executes the command |
|---|---|---|
| `native` | The host OS ships the pinned PHP (Ubuntu 24.04 ships 8.3) | Host PHP |
| `podman` | The host OS cannot supply it (Ubuntu 26.04 ships only 8.5) | Digest-pinned image, rootless |
| `auto` (default) | Prefers a matching host PHP, falls back to podman | Either of the above |

**The runner in service today resolves `native`.** Never assume the mode — ask
the resolver, which is also what CI evidence records:

```bash
bash scripts/ci/self-hosted-php.sh --print-runtime
# runtime_mode=native
# container_engine=none
# php_source=host
# php_version=8.3
```

Docker is forbidden in both modes: the `docker` group is root-equivalent.

### 2.2 The runner in service

| Property | Value |
|---|---|
| Provider / plan | Biznet Gio — NEO Lite MM 8.8 |
| Hostname | `daengtisia-ci-biznet` |
| Runner name | `daengtisia-ci-biznet-01` |
| OS | Ubuntu 24.04 LTS |
| CPU / RAM | 8 vCPU / ~8 GB |
| Runtime mode | `native` — host PHP 8.3.6 |
| Container engine | **none** — podman and docker are not installed |
| CI database | **native** PostgreSQL 16.14, loopback only, database `daengtisia_ci` |
| Labels | `self-hosted`, `Linux`, `X64`, `daengtisia-ci` |
| Actions user | `github-runner` — non-root, no sudo, no docker, no lxd |
| Admin user | `daengtisiams` — sudo via cloud-init, break-glass only |
| Administrative access | Tailscale `100.121.146.97` |
| Public IPv4 | `103.89.5.23` — SSH blocked by UFW |

Podman is **not required** on this host and installing it to satisfy an older
revision of this runbook would add a container stack nothing uses. `auto`
resolves `native`, and the health gate asserts real parity rather than taking
the mode's word for it.

---

## 3. Provisioning

These steps provision a **native** runner, which is the supported shape for any
host whose OS ships the pinned PHP. For a host that cannot, see Appendix A.

### 3.1 Service user

The runner never executes CI as root and gets no unrestricted sudo.

```bash
sudo adduser --disabled-password --gecos "" github-runner
```

Do **not** add `github-runner` to `docker`, `lxd`, `sudo`, `adm`, `wheel`,
`root`, `disk` or `shadow`. The health script fails closed on each of them.

### 3.2 Runtime — host PHP 8.3 and its parity set

Install everything CI needs so jobs never require sudo at run time. On Ubuntu
24.04 the distribution PHP is already 8.3, which is what makes `native` correct
here:

```bash
sudo apt-get update
sudo apt-get install -y --no-install-recommends \
    git curl unzip zip ca-certificates \
    nodejs npm poppler-utils \
    php-cli php-dom php-curl php-xml php-mbstring php-zip php-pcntl \
    php-pgsql php-bcmath php-gd \
    postgresql postgresql-client
```

Composer is installed separately (official installer, checksum-verified) to
`/usr/local/bin/composer`. Node must match the workflow's `setup-node` version
(currently 22).

`memory_limit` must be unlimited for the CLI, or Pest exhausts the default 128M
inside `TestSuiteLoader` before a single test runs:

```bash
echo 'memory_limit = -1' | sudo tee /etc/php/8.3/cli/conf.d/99-ci.ini
```

> **Mirror gotcha (hit 2026-08-07):** `id.archive.ubuntu.com` served HTTP 403 for
> `libgpgmepp7`, a dependency of `libpoppler156`, which blocked the whole
> install. Fixed by pointing `/etc/apt/sources.list.d/ubuntu.sources` at
> `archive.ubuntu.com` (backup kept as `ubuntu.sources.cicd-ctrl-3.bak`). If
> package installs start failing with 403, check the mirror before anything else.

Verify — the health script checks all of this, but check it by hand once:

```bash
php -v && php -m && composer --version && node --version && npm --version
psql --version && pdfinfo -v && pdftoppm -v
bash scripts/ci/self-hosted-php.sh --print-runtime   # expect runtime_mode=native
```

### 3.3 CI PostgreSQL — native, loopback only

The CI database major version **must** match the authoritative gate. A mismatch
does not merely differ, it produces different results: a host running
PostgreSQL 18 against a gate pinned to 16 caused seven self-hosted-only
failures (`SQLSTATE[25P02]` cascades from aborted transactions).

Ubuntu 24.04 ships PostgreSQL 16, so the distribution package is the CI
database. No container is involved.

```bash
sudo apt-get install -y postgresql postgresql-client
psql --version                       # client
sudo -u postgres psql -tAc 'SHOW server_version'   # server — this is the one that matters
```

Create the CI role and database. The name must stay non-production; the
`ci:assert-non-production-database` guard rejects anything that looks like the
production or pilot database:

```bash
sudo -u postgres createuser --pwprompt daengtisia_ci_user
sudo -u postgres createdb --owner=daengtisia_ci_user daengtisia_ci
```

Keep the server bound to loopback (the default). The health script fails closed
if PostgreSQL is reachable beyond `127.0.0.1`:

```bash
ss -ltn | grep 5432        # expect 127.0.0.1:5432 and [::1]:5432 only
```

The password is supplied to CI as the repository secret `CI_DB_PASSWORD` and is
never written into the workflow, this runbook, or any evidence file.

### 3.4 Register the runner

Mint a **registration token** (short-lived, single-use) from
`Settings → Actions → Runners → New self-hosted runner`, or via the API with an
admin token. The token is never committed, never stored on the runner after
registration, and never printed into a report.

As `github-runner`, download the current official runner package, verify its
checksum, then:

```bash
./config.sh --url https://github.com/<owner>/<repo> \
            --token <REGISTRATION_TOKEN> \
            --name daengtisia-ci-biznet-01 \
            --labels daengtisia-ci \
            --work _work \
            --unattended
```

Built-in labels (`self-hosted`, `linux`, `x64`) are added automatically; the
custom `daengtisia-ci` label is what makes DaengtisiaMS jobs unambiguous.

### 3.5 Managed service

```bash
sudo ./svc.sh install github-runner
sudo ./svc.sh start
systemctl status 'actions.runner.*'
```

An interactive `./run.sh` is acceptable only while troubleshooting and must be
stopped afterwards. The permanent runner is always the systemd service.

### 3.6 Network posture

Administrative access is over Tailscale; public SSH stays closed.

```bash
sudo ufw default deny incoming
sudo ufw allow in on tailscale0 to any port 22 proto tcp comment 'SSH via Tailscale'
sudo ufw allow 41641/udp comment 'Tailscale direct connections'
sudo ufw enable
sudo systemctl enable --now tailscaled
```

Verify from outside the tailnet that `103.89.5.23:22` does **not** answer.

### 3.7 Acceptance

Do not consider the runner ready until **all** hold:

1. GitHub shows `daengtisia-ci-biznet-01` as Online/Idle.
2. Labels include `self-hosted`, `linux`, `x64`, `daengtisia-ci`.
3. The service runs as `github-runner`, not root, and is enabled **and** active.
4. `scripts/ci/self-hosted-php.sh --print-runtime` reports the mode the host
   actually provides — `native` here — and never names an engine it lacks.
5. `sudo -u postgres psql -tAc 'SHOW server_version'` reports major 16.
6. `scripts/ci/self-hosted-runner-health.sh` reports **GO** with `failed=0`.
7. A real GitHub Actions job is assigned to the runner and its hostname matches.
8. No interactive `run.sh` process remains.

---
## 4. Routing and fallback

Routing is decided by the `classify` job, which always runs GitHub-hosted.

Precedence: **workflow_dispatch input → repository variable → `github-hosted`**.

| Action | How |
|---|---|
| Send heavy CI to the runner | Set repository variable `CI_RUNNER_MODE` = `self-hosted` |
| Fall back to GitHub-hosted | Set `CI_RUNNER_MODE` = `github-hosted`, or delete the variable |
| One-off override | Run the workflow manually and pick `runner_mode` |

The fallback runs the **same** test filter and the **same** guards. It is not a
weaker gate and it does not rename the required check.

**Outage behaviour is intentional:** if routing targets the runner and the runner
is offline, the job **queues** and the gate stays unsatisfied. There is no
automatic failover, because a required check must never resolve without
executing. Flip the variable to fall back explicitly.

---

## 5. Routine operations

```bash
# Status
systemctl status 'actions.runner.*'
sudo -u github-runner scripts/ci/self-hosted-runner-health.sh

# Restart (safe; a running job is finished first by the service manager)
sudo systemctl restart 'actions.runner.*'

# Upgrade the runner package: stop the service, replace the package as
# github-runner, reinstall the service, start it. The runner also self-updates
# for minor releases.
```

Verify governance after any change:

```bash
php artisan foundation:self-hosted-runner-check --strict
php artisan foundation:ci-runtime-control-check --strict
```

---

## 6. Troubleshooting

| Symptom | Cause | Action |
|---|---|---|
| Job stays queued | Runner offline, or labels do not match | `systemctl status 'actions.runner.*'`; confirm all four labels; fall back explicitly if the runner is down |
| Health check NO-GO on disk | Work volume filled by old workspaces | Remove stale `_work` subdirectories; keep package caches |
| Health check NO-GO on production isolation | Production key or path present on the runner | Remove it. Production material must not exist on a CI machine |
| `ci:assert-non-production-database` fails | Environment points at a non-local or production-named database | Fix the job environment. **Never** weaken the guard |
| Composer/npm resolves unexpected versions | Runner runtime drifted from the CI gate | Match `required_php_version`; `composer.lock` is resolved for the CI PHP version |
| Job fails only on self-hosted | Missing host tooling | Health script names the missing tool; install it, do not add sudo to the job |

---

## 7. Forbidden on this runner

- `scripts/deploy-vps-runner.sh`, `scripts/deploy-vps.sh`, `scripts/rollback-vps.sh`
- Any production database connection, dump, or restore
- Storing the production environment file or a production SSH private key
- Adding the service user to the `docker` group
- Running the runner service as root
- Weakening or skipping a gate to make CI faster

---

## 8. Decommissioning

Workflow rollback first — that is the fast, low-risk path:

1. Set `CI_RUNNER_MODE` = `github-hosted`. Heavy CI returns to GitHub-hosted
   immediately, with no code change.
2. Only then, if the machine is being retired:

```bash
sudo ./svc.sh stop
sudo ./svc.sh uninstall
./config.sh remove --token <REMOVAL_TOKEN>   # mint from GitHub, single use
```

3. Remove the runner directory. Dropping the CI database is a separate, explicit
   decision; nothing here deletes data automatically.

---

## 9. Measured performance and why GitHub-hosted stays primary

Like-for-like, on the **same commit**, both green, sequential Pest on both
sides, PHP 8.3 and PostgreSQL 16 on both sides:

| Runner | NSF-R011 Critical Test Gate | Result |
|---|---|---|
| GitHub-hosted `ubuntu-latest` | **18m52s** | success |
| Biznet `daengtisia-ci-biznet-01` | **57m16s** | success |

That is **≈3.04× slower** on Biznet *for that comparison*. Treat it as one
measured data point, not a constant: GitHub-hosted wall-clock varies run to run,
so the ratio moves. The conclusion that does hold, and the one this project acts
on, is directional and repeatable:

> **Biznet is consistently slower than GitHub-hosted for the current sequential
> Pest workload.** GitHub-hosted therefore remains primary heavy CI; Biznet is
> secondary and manual-overflow capacity.

The 8 vCPU count does not change this. The workload is a single sequential Pest
process, so it is bound by per-core speed rather than core count, and
`--parallel` is deliberately **not** enabled on the gate — parallel workers were
benchmarked as hardware capacity evidence only.

Do not quote the retired laptop's 1.34× figure for this host (Appendix A).

---

## Appendix A — Historical: the containerised runner on `aishrunner` (RETIRED)

**Status: RETIRED / HISTORICAL. Do not follow this section to provision or
operate the runner in service.** It is kept because the container path remains a
supported mode of `scripts/ci/self-hosted-php.sh` and will be the correct choice
again on any host whose OS cannot ship the pinned PHP.

The first runner instance was `daengtisia-ci-01` on **`aishrunner`**, a Dell
Inspiron 14-3467 laptop (Intel Core i3-7020U, 2 cores / 4 threads, 14 GiB RAM),
reached over Tailscale at `100.74.126.71`. It ran **Ubuntu 26.04**, which ships
only PHP 8.5 — there is no 8.3 build in its repositories and `ondrej/php` has no
`resolute` build either. The host therefore could not provide the authoritative
runtime, so the wrapper ran every php/composer/artisan call inside a
**digest-pinned PHP 8.3 image under rootless Podman** built from
`.github/ci-runtime/Containerfile.php83`, with `--userns=keep-id` so container
writes stayed owned by `github-runner` and left no root-owned residue.

Its CI database was a pinned **`postgres:16` container** under rootless Podman,
managed as the systemd Quadlet unit `ci-pg16.service` and bound to
`127.0.0.1:5433`, because the host's own PostgreSQL was 18 and a major-version
mismatch produced seven self-hosted-only failures.

Because it was a laptop, it also needed lid-close behaviour changed in
`/etc/systemd/logind.conf` so closing it did not suspend CI.

Lessons carried forward from that host, all still enforced in code:

- The `php:8.3-cli` base image defaults to `memory_limit=128M`; Pest exhausted it
  inside `TestSuiteLoader` before running a single test. The image build now
  fails unless the limit is unlimited, and §3.2 sets the same for a native host.
- Poppler must be present or the LegacyRme suite silently **skips** rather than
  fails, which would make the self-hosted gate weaker than the authoritative one.
- `POSTGRES_HOST_AUTH_METHOD` alone leaves initdb's `127.0.0.1/32 trust` rule
  matching first, so a wrong password still connects. Use `POSTGRES_INITDB_ARGS`
  with `--auth-host`/`--auth-local`, quoted, since systemd splits `Environment=`
  on whitespace. Both apply only at initdb.
- The service user had been in both `docker` and `sudo`, which is
  root-equivalent. Nothing depended on either; both were removed. The health
  script now fails closed on those groups.

**Why it was retired.** At equal work — same commit, same PHP 8.3, same
PostgreSQL 16, same filter, sequential both sides — the laptop measured
**1808 s against GitHub-hosted's 1347 s (≈1.34× slower)**, which is expected of
two physical cores. That figure describes `aishrunner` only and must never be
quoted as the current runner's number; see §9 for the Biznet measurement.

A retracted measurement worth remembering: an earlier "307 s on the runner"
figure was **invalid**. It was taken while the host still used PostgreSQL 18,
where governance tests short-circuited on aborted transactions and the suite
simply did less work (`fg1 ci check` took 0.21 s there versus 68.37 s
GitHub-hosted). Never trust a wall-clock without checking the work done.
