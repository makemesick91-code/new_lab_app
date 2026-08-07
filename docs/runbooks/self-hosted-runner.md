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

### 2.1 Operating system — must provide the CI PHP version natively

The self-hosted gate is only a valid substitute for the GitHub-hosted gate if it
runs the **same PHP version** (`config/ci_runner.php` → `required_php_version`,
currently **8.3**). `composer.lock` is resolved against that version, and a
green run on a different PHP does not prove the authoritative gate passes.

**Use Ubuntu 24.04 LTS**, which ships PHP 8.3 natively — no PPA, no source
build, no mixed-release packages.

Verified 2026-08-07, so nobody repeats the investigation:

| Option | Result |
|---|---|
| Ubuntu 24.04 LTS (`noble`) | **PHP 8.3 native.** Use this. |
| Ubuntu 26.04 (`resolute`) | Ships **only** PHP 8.5. No 8.3/8.4 in its repos. |
| `ondrej/php` PPA on 26.04 | **No `resolute` build** — the PPA stops at `noble` (HTTP 404 for `resolute`). |
| ondrej `noble` packages on 26.04 | Mixed-release install. Do **not** do this: conflicting `libssl`/`libc` dependencies risk destabilising the machine and are hard to unwind. |

If a future runner OS cannot supply the CI PHP version, do not silently run a
different one — either change the OS or raise it as an explicit divergence,
because it weakens rule CICDCTRL3-R009 (equivalent fallback).

### 2.2 Access and hardware

- A dedicated Linux machine that is not the production VPS.
- SSH access as a provisioning admin using a key in `~/.ssh` on the workstation.
  **Never** put an SSH password or private key in the application environment
  file, and never copy the production VPS key to the runner.
- Repository admin rights on GitHub (needed once, to mint a runner registration
  token).

Resource floor for heavy CI (enforced by the health script):

- **≥ 40 GB** free disk on the runner's work volume
- **≥ 4 GB** available RAM
- PHP version matching the CI gate (`config/ci_runner.php` → `required_php_version`)

---

## 3. Provisioning

### 3.1 Service user

The runner never executes CI as root and gets no unrestricted sudo.

```bash
sudo adduser --disabled-password --gecos "" github-runner
```

Do **not** add `github-runner` to the `docker` group — that group is
root-equivalent, and this runner deliberately has no Docker (see §3.4).

### 3.2 Runtime

Install the runtimes so CI jobs never need sudo at run time:

On Ubuntu 24.04 LTS every runtime comes from the distribution repositories:

```bash
sudo apt-get update
sudo apt-get install -y git curl unzip zip poppler-utils postgresql \
    php8.3-cli php8.3-dom php8.3-curl php8.3-xml php8.3-mbstring php8.3-zip \
    php8.3-pcntl php8.3-pgsql php8.3-bcmath php8.3-gd php8.3-sqlite3 \
    composer nodejs npm
```

The extension set mirrors the CI gate (`dom curl libxml mbstring zip pcntl pdo
pdo_pgsql bcmath gd exif`; `exif` is bundled with `php8.3-cli` on Ubuntu). Node
must match the workflow's `setup-node` version (currently 22).

Verify:

```bash
php -v && php -m && composer --version && node --version && npm --version
psql --version && pdfinfo -v && pdftoppm -v
```

### 3.3 Laptop power settings (if the runner is a laptop)

A CI runner that suspends is a CI runner that silently stops taking jobs.

```bash
systemctl status sleep.target suspend.target hibernate.target
# Back up /etc/systemd/logind.conf before changing lid-close behaviour.
```

Disable automatic suspend/sleep/hibernate and lid-close suspend. Prefer
Ethernet. Ensure PostgreSQL and the runner service are enabled at boot.

### 3.4 CI PostgreSQL (local, loopback only)

The self-hosted job uses the runner's **local** PostgreSQL, not a Docker service
container.

```bash
sudo -u postgres createuser --no-superuser --no-createrole --no-createdb daengtisia_ci_user
sudo -u postgres createdb --owner=daengtisia_ci_user daengtisia_ci
sudo -u postgres psql -c "ALTER USER daengtisia_ci_user WITH PASSWORD '<generated>';"
```

- The password goes into the GitHub Actions repository secret `CI_DB_PASSWORD`.
  Never commit it, never echo it, never put it in the application environment
  file.
- Confirm PostgreSQL listens on loopback only (`listen_addresses = 'localhost'`);
  the health script fails if it is published to the network.
- This database is disposable CI state. It must never hold production data.

### 3.5 Register the runner

Mint a **registration token** (short-lived, single-use) from
`Settings → Actions → Runners → New self-hosted runner`, or via the API with an
admin token. The token is never committed, never stored on the runner after
registration, and never printed into a report.

As `github-runner`, download the current official runner package, verify its
checksum, then:

```bash
./config.sh --url https://github.com/<owner>/<repo> \
            --token <REGISTRATION_TOKEN> \
            --name daengtisia-ci-01 \
            --labels daengtisia-ci \
            --work _work \
            --unattended
```

Built-in labels (`self-hosted`, `linux`, `x64`) are added automatically; the
custom `daengtisia-ci` label is what makes DaengtisiaMS jobs unambiguous.

### 3.6 Managed service

```bash
sudo ./svc.sh install github-runner
sudo ./svc.sh start
systemctl status 'actions.runner.*'
```

An interactive `./run.sh` is acceptable only while troubleshooting and must be
stopped afterwards. The permanent runner is always the systemd service.

### 3.7 Acceptance

Do not consider the runner ready until **all** hold:

1. GitHub shows `daengtisia-ci-01` as Online/Idle.
2. Labels include `self-hosted`, `linux`, `x64`, `daengtisia-ci`.
3. The service runs as `github-runner`, not root.
4. The systemd unit is enabled **and** active, and survives a restart.
5. `scripts/ci/self-hosted-runner-health.sh` reports **GO**.
6. A real GitHub Actions job is assigned to the runner and its hostname matches.
7. No interactive `run.sh` process remains.

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
