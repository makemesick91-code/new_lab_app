# Production host identity and the frozen Hostinger host

**Status:** canonical since 2026-11-04 (`DMS-POST-MIGRATION-DEV-INFRA-ALIGNMENT-1`).
**Authority:** `deploy/production-host.conf`. This page explains it; the file is what checks and tests read.
**Enforced by:** `scripts/production-target-check.sh`, `tests/Feature/Cicd/ProductionHostIdentityGuardTest.php`.

Production moved from Hostinger to Biznet Gio on 2026-11-03. Older runbooks, sprint records and habits still say
"the VPS", `srv1730088` and `ssh daengtisiams-vps`. Those names now point at a frozen machine. Use this page
before any command that could touch production.

## 1. Which machine is which

| Role | Provider | Hostname | Address | How it is reached |
|---|---|---|---|---|
| **Production** (active) | Biznet Gio | `DaengtisiaMS` | `139.190.106.38` | `ssh daengtisiams-biznet-prod` as `dmsadmin`, privileged steps through `sudo -n` |
| **Frozen legacy** (write-frozen) | Hostinger | `srv1730088` | `145.79.13.224` | never, for any write — historical migration source only |
| Heavy CI runner (not production) | Biznet Gio | `daengtisia-ci-biznet` | private overlay address | GitHub Actions only — see `docs/runbooks/self-hosted-runner.md` |

- The application lives at `/var/www/asia-dental-lab-v2` and runs as `daengtisiams` on the PHP 8.3 FPM pool. On
  Biznet the host's CLI PHP is also 8.3; there is no co-tenant application.
- Canonical URL: `https://daengtisia.online`. DNS now resolves it to the Biznet address. The bare IP and
  `127.0.0.1` reach nginx's unknown-host default server (HTTP 404), so never verify the application there.
- "Biznet" alone does not mean production: the heavy CI runner is also a Biznet machine. Production is the host
  named `DaengtisiaMS` at `139.190.106.38` and nothing else.

## 2. SSH aliases

| Alias | Meaning | Use |
|---|---|---|
| `daengtisiams-biznet-prod` | Biznet production, `dmsadmin@139.190.106.38`, pinned host key | the only production alias |
| `daengtisiams-vps` | **frozen Hostinger**, `root@145.79.13.224` | never — the name misleads; it was re-pointed at Hostinger before the DNS flip |

Older documents that write `ssh daengtisiams-vps` describe the Hostinger era. Do not replay them. Translate them
through this page. A recommended workstation hygiene step (owner decision, not done by any sprint) is renaming the
legacy alias to something unmistakable, for example `daengtisiams-hostinger-frozen`.

Expected workstation entry for production (no key material shown):

```
Host daengtisiams-biznet-prod
  HostName 139.190.106.38
  User dmsadmin
  IdentitiesOnly yes
  StrictHostKeyChecking yes
  UserKnownHostsFile ~/.ssh/known_hosts.d/daengtisiams-biznet-prod
```

## 3. Prove the target before a sensitive operation

A sensitive operation is anything that writes, restarts, migrates, deploys, rolls back, or reads secrets or clinical
data. Before one, prove the target. Do not rely on the alias name.

```
bash scripts/production-target-check.sh            # static: alias, account, host-key pinning (no network)
bash scripts/production-target-check.sh --remote   # adds a read-only hostname + address check over SSH
```

Proceed only on `PRODUCTION_TARGET=VERIFIED`. The check refuses an alias that:

- resolves to a frozen legacy hostname, address or alias;
- resolves to a DNS name instead of the pinned address, because the target must not move with DNS;
- logs in as anything other than `dmsadmin`;
- relaxes `StrictHostKeyChecking`, enables `UpdateHostKeys` or `VerifyHostKeyDNS`, or uses `/dev/null` as its known-hosts file;
- has any known-hosts entry for the host other than the pinned key `SHA256:i3AsyL0oSwqSAwNfgv215Cl01i87buYtH5ugTe8O2bc` (ED25519).

Manual equivalent when the script is unavailable:

```
ssh -G daengtisiams-biznet-prod | grep -E '^(hostname|user|stricthostkeychecking|userknownhostsfile) '
ssh-keygen -l -F 139.190.106.38 -f ~/.ssh/known_hosts.d/daengtisiams-biznet-prod
ssh daengtisiams-biznet-prod 'hostname; hostname -I'      # expect DaengtisiaMS and 139.190.106.38
```

## 4. Host-key trust rules

- Keep `StrictHostKeyChecking yes` and the dedicated pinned known-hosts file for production.
- Never disable host-key checking, never point known-hosts at `/dev/null`, and never use `accept-new` for production -- never.
- Never refresh or seed a production host key with `ssh-keyscan`; it trusts whatever answers -- never.
- A host-key mismatch for `139.190.106.38` is a **stop-and-escalate** event. Do not edit known-hosts to make the
  warning go away. Confirm the new fingerprint out of band with the owner, for example from the Biznet console. Then
  update `deploy/production-host.conf` and the pinned file in the same reviewed change.
- No workflow under `.github/workflows/` may open SSH to production. The retired `deploy-vps.yml` now fails closed.

## 5. Privilege model on Biznet

- `dmsadmin` is the administrative login. Root has no SSH login. Commands that need root run as `sudo -n …`.
- The application runtime identity is `daengtisiams` (`deploy/runtime-identity.conf`). Laravel cache rebuilds run as that
  account (`runuser -u daengtisiams -- php artisan …`). They never run as `dmsadmin`, and never as root.
- `php artisan tinker` and psysh stay forbidden on production -- never (CG-14).

## 6. Deploying to Biznet — readiness (NOT YET PROVEN)

The deploy runner (`scripts/deploy-vps-runner.sh`) has **never run on Biznet**. The scripts were written for the
Hostinger host, where the deploy ran as root. Read-only inspection of Biznet on 2026-11-04 found these blockers.
All of them must be closed, with explicit owner approval, before the first Biznet deploy.

| # | Blocker | Effect if ignored | Closure |
|---|---|---|---|
| D1 | Node.js / npm are not installed on Biznet | the deploy fails at `npm ci` **after** the checkout and `composer install`, leaving new PHP code with stale built assets and unmigrated schema | owner-approved install of Node 22 (the CI version; `package.json` needs ≥ 20), or a reviewed change in how assets are built |
| D2 | `storage/logs/deploy-runner/latest.status` on Biznet is Hostinger's `exit=0` from 2026-11-02 | `deploy-vps-runner.sh status` reports **DEPLOY RUNNER OK** for a deploy that never ran on this host | **closed in the deploy-chain change** (`deploy-vps-runner.sh` binds every status to the host that wrote it; an unbound or foreign status returns `4`, never OK) — effective once merged and deployed |
| D3 | The deploy has no explicit root / production-identity / toolchain preflight | a non-root or wrong-host invocation fails mid-run instead of refusing cleanly before the first mutation | **closed in the deploy-chain change** (`scripts/lib/production-host-preflight.sh`, run by deploy and rollback before the secret file, the backup and the checkout; refuses the frozen host, any other host, non-root, and a missing tool) — effective once merged and deployed |
| D4 | `provision-runtime-identity.sh --apply` does `systemctl restart` on the queue worker | it would **start** the intentionally stopped worker (reopening condition C5) | **closed in the deploy-chain change** (`systemctl try-restart`: a running worker picks up the identity, a stopped one stays stopped) |
| D5 | The installed `daengtisiams-db-backup` units differ from `deploy/systemd/` (Biznet runs at 18:30 UTC, the repo says 19:00 UTC) | a reinstall from the repo would silently move the backup schedule | owner decision; backup services are not changed by this sprint |
| D6 | The deploy's evidence base URL is `http://127.0.0.1` | the loopback answers 404 (default server), as on Hostinger | known warning, not a blocker; verify over the canonical URL |

The privileged form of the canonical command on Biznet is
`ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh start'`.
It is written here so nobody improvises a different one. Do not run it until D1 is closed, the deploy-chain change
(D2–D4) is merged, and the owner has approved the deploy. Once the preflight is in place, a run with D1 still open
refuses before any mutation with `required tool 'node' is not installed`; that is the intended outcome, not a fault.

## 7. What stays true from the Hostinger era

- CG-15: deploy only through the runner, executed on the production host, complete only at `exit=0` plus `DEPLOY OK`.
- Backup before migrate; `migrate --force` only; never `migrate:fresh`, `db:wipe`, `schema:drop` or `migrate:reset` -- never.
- Historical sprint records keep their Hostinger names (`srv1730088`, `ssh daengtisiams-vps`). They record what was
  true when they were written, and they are not rewritten.
