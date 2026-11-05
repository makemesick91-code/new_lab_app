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

## 6. Deploying to Biznet — readiness (CLOSED; first deploy verified 2026-11-04)

Biznet is the production environment, and the deploy runner (`scripts/deploy-vps-runner.sh`) has run on it: the first
Biznet deploy, `20261104-151042`, released commit `6f22beb7` on 2026-11-04 and was proven with `verify-transition`
(§6a). The scripts were first written for the Hostinger host, where the deploy ran as root. Read-only inspection of
Biznet on 2026-11-04 found the blockers below; they had to be closed, with explicit owner approval, before that first
deploy. The table is kept as the record of how each one was closed. Every later deploy follows §6b.

| # | Blocker | Effect if ignored | Closure |
|---|---|---|---|
| D1 | Node.js / npm are not installed on Biznet | the deploy fails at `npm ci` **after** the checkout and `composer install`, leaving new PHP code with stale built assets and unmigrated schema | owner-approved install of Node 22 (the CI version; `package.json` needs ≥ 20), or a reviewed change in how assets are built. The deploy-chain change refuses before any mutation both when a tool is missing and when its version differs from CI (Node 22, npm ≥ 10, PHP CLI 8.3, Composer 2 — `dms_production_toolchain_versions`); Ubuntu 24.04's archive `nodejs` is 18 and is refused |
| D2 | Biznet's `storage/logs/deploy-runner/` holds the frozen host's last status and logs, copied in the migration — never evidence of a Biznet deploy | the pre-binding runner on Biznet reports **DEPLOY RUNNER OK** for a deploy that never ran on this host, and the first Biznet deploy (launched by that runner) writes no binding record | **closed in the deploy-chain change** — every run is proven by six facts (host, time, identifier, target, runner, result; `scripts/lib/deploy-runner-evidence.sh`), anything else exits `4`. The copied evidence is quarantined once before the first deploy and that deploy is proven with `verify-transition` — **§6a (owner-selected Option A)** |
| D3 | The deploy has no explicit root / production-identity / toolchain preflight | a non-root or wrong-host invocation fails mid-run instead of refusing cleanly before the first mutation | **closed in the deploy-chain change** (`scripts/lib/production-host-preflight.sh`, run by deploy and rollback before the secret file, the backup and the checkout; refuses the frozen host, any other host, non-root, and a missing tool) — effective once merged and deployed |
| D4 | `provision-runtime-identity.sh --apply` does `systemctl restart` on the queue worker | it would **start** the intentionally stopped worker (reopening condition C5) | **closed in the deploy-chain change** (`systemctl try-restart`: a running worker picks up the identity, a stopped one stays stopped) |
| D5 | The installed `daengtisiams-db-backup` units differ from `deploy/systemd/` (Biznet runs at 18:30 UTC, the repo says 19:00 UTC) | a reinstall from the repo would silently move the backup schedule | owner decision; backup services are not changed by this sprint |
| D6 | The deploy's evidence base URL is `http://127.0.0.1` | the loopback answers 404 (default server), as on Hostinger | known warning, not a blocker; verify over the canonical URL |

**Status after the first deploy (2026-11-04):** D1 closed by the owner-approved install of Node 22 and npm 10 (installed
outside the OS package manager, so its security updates are applied by hand); D2 closed by the §6a quarantine; D3 and
D4 closed by the deploy-chain change, which is now in the production tree; D5 remains an owner decision; D6 remains a
known warning — the deploy's own loopback smoke reports `404`, and the canonical URL is verified separately.

The privileged form of the canonical command on Biznet is
`ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh start'`.
It is written here so nobody improvises a different one. It is the only deploy command; run it only as part of §6b,
with the approval the owner's current release governance requires. A run whose preflight refuses (wrong host, non-root, a
missing tool or a toolchain version that differs from CI) stops before any mutation; that is the intended outcome,
not a fault.

## 6a. The first Biznet deploy — owner-selected Option A (EXECUTED 2026-11-04; historical record)

The owner chose **Option A** on 2026-11-04 (DMS-DEV-INFRA-REMEDIATION-2): quarantine the copied pre-binding
evidence before the first deploy, then prove that deploy with `verify-transition`. **This procedure ran once, on
2026-11-04 (execution record below). Do not run it again**: the production tree now holds the binding runner, so
`verify-transition` refuses every later run (`NOT-TRANSITION`) and every later deploy follows §6b.

**Why it is needed.** The Biznet checkout is at the pre-migration commit, whose runner reads `latest.status` raw. The
first deploy is launched by that runner, so it writes no binding record and the new runner reports it `UNBOUND` —
correctly, since nothing bound it. The quarantine removes the copied `exit=0` from where any runner looks, and
`verify-transition` proves the one unbound run from facts the deploy itself printed.

**Preconditions (all, or stop).** D1 closed (Node 22 + npm installed under a separate owner approval); the deploy-chain
change merged, with its merge SHA written down as `MERGE_SHA`; a written owner authorization for this deploy that
names `MERGE_SHA`; `bash scripts/production-target-check.sh --remote` → `PRODUCTION_TARGET=VERIFIED`; outside the
backup window 18:25–18:40 UTC (02:25–02:40 WITA), when both backup jobs fire; one operator, no other deploy, rollback
or quarantine running.

0. **Record the starting state (read-only).** HEAD, the runner's binding support and the evidence directory:
   ```bash
   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n git rev-parse HEAD && sudo -n grep -c DEPLOY_HOST_FILE scripts/deploy-vps-runner.sh; sudo -n ls -la storage/logs/deploy-runner/ | tail -n 5; sudo -n ls storage/logs/deploy-runner/ | wc -l'
   ```
   Expect the pre-migration HEAD, `0` (a pre-binding runner) and the copied files. Keep the output as evidence.
1. **Bring the quarantine tool to the host without touching the checkout.** It is not in the production tree yet, so
   copy it from the merged release object into a new root-only directory (the DEPLOY-HARDEN-1 transition pattern):
   ```bash
   git fetch origin && git cat-file -e "${MERGE_SHA}^{commit}"
   git archive "$MERGE_SHA" -- scripts/deploy-runner-quarantine-stale-status.sh scripts/lib/production-host-preflight.sh \
       scripts/lib/deploy-runner-evidence.sh deploy/production-host.conf \
     | ssh daengtisiams-biznet-prod 'sudo -n install -d -m 0700 /root/dms-quarantine-bundle && sudo -n tar -x --no-same-owner -C /root/dms-quarantine-bundle'
   ```
   If `/root/dms-quarantine-bundle` already exists from an earlier attempt, stop and inspect it; never reuse an
   unknown copy.
2. **Dry run.** Nothing moves:
   ```bash
   ssh daengtisiams-biznet-prod 'sudo -n bash /root/dms-quarantine-bundle/scripts/deploy-runner-quarantine-stale-status.sh'
   ```
   Expect `PRODUCTION HOST PREFLIGHT: GO`, one `would move:` line per copied file plus `latest.status` and
   `latest.log`, and `QUARANTINE DRY RUN`. The planned count must equal the step-0 entry count minus the entries
   outside the `deploy-*` / `latest.*` contract, which the tool leaves in place by design and no runner reads (no file
   is bound yet). On Biznet that was 510 − 2 (`post-ent.status`, `post-ent.log`) = **508**. Anything else: stop.
3. **Apply.** Same command with `--apply`. Expect `QUARANTINE COMPLETE: <n> entr(y/ies) preserved in
   …/quarantine/pre-binding-<UTC stamp>` (manifest verified, nothing deleted). The files keep their content and
   timestamps; `MANIFEST.tsv` lists every SHA-256 and `QUARANTINE.complete` is written last.
4. **Confirm the false OK is gone.** The pre-binding runner now has nothing to read:
   ```bash
   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh status'
   ```
   Expect `status pending (deploy still running or never started)` and a non-zero exit — never `DEPLOY RUNNER OK`.
5. **Start the first deploy** with the canonical command from §6, and write down the `log=…/deploy-<deploy-id>.log`
   it prints. Follow it with `… deploy-vps-runner.sh follow`; the pre-binding runner's own verdict is **not** the
   evidence.
6. **Prove it** with the new runner, which the deploy has just checked out:
   ```bash
   ssh daengtisiams-biznet-prod "cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh verify-transition <deploy-id> ${MERGE_SHA}"
   ```
   It must print `TRANSITION DEPLOY VERIFIED`: the run finished after the quarantine, its own log shows the
   production preflight passing on `DaengtisiaMS`, exactly one pinned target equal to `MERGE_SHA`, the deploy's own
   `HEAD == target` confirmation and `DEPLOY OK`, and the checkout is at `MERGE_SHA`. It refuses a different target
   (`MISMATCHED`), a copied log (`UNVERIFIED`), a run that predates the quarantine (`STALE`) and a bound run
   (`NOT-TRANSITION` — use `verify`).
7. **Smoke over the canonical URL** (`/login`, `/health/live`, `/health/ready`, `/health/lb` → 200) and
   `php artisan release:evidence-check --profile=vps` → GO, per CG-15/CG-16.

**If the deploy fails.** Before the checkout (for example the preflight refuses), the tree still holds the pre-binding
runner, which prints `DEPLOY RUNNER FAILED`: a failure report, nothing more. Fix the cause and start again; the
quarantine stays valid. After the checkout, `verify-transition` prints `DEPLOY RUNNER FAILED (exit=N)`; follow
`docs/runbooks/vps-deploy-rollback-runbook.md`. Once the tree holds the new runner, every later run (a retry
included) writes a binding record and is proven with `verify <deploy-id>` → `DEPLOY RUNNER OK`.

**What never happens.** No evidence file is deleted or edited; the quarantine is not reversed by hand; nobody copies
an old `exit=0` back into place; the frozen host is never contacted.

**Execution record (2026-11-04, DMS-BIZNET-FIRST-PRODUCTION-DEPLOYMENT-GO-1).** Step 0: 510 entries (508 files and 2
links). Step 2: plan 508, with both `post-ent.*` excluded. Step 3: `QUARANTINE COMPLETE: 508` at 15:09:05 UTC into
`storage/logs/deploy-runner/quarantine/pre-binding-20261104T150905Z/`; each moved entry was checked against an
independent pre-move listing (identical content and metadata), and both `post-ent.*` files stayed unchanged in
place. Step 4: `status pending`, rc 3. Step 5: deploy `20261104-151042`, pinned target `6f22beb7`, `Nothing to
migrate.`, status `exit=0` and `DEPLOY OK` at 15:12:28 UTC. Step 6: `TRANSITION DEPLOY VERIFIED`. Step 7: canonical
URL `200` on `/login` and the three health checks, VPS release evidence GO. GO tag
`dms-biznet-first-production-deploy-infra-1-go` (an owner-named release tag, registered in
`docs/governance/go-tag-exceptions.md`). The deploy's ownership normalization later re-owned the root-created
quarantine directory to the runtime user, with modes unchanged; the manifest hash was re-verified as unchanged.

## 6b. Every Biznet deploy after the first (current procedure)

**Preconditions (all, or stop).**
- The approval the owner's current release governance requires, and the exact commit it approves. Step 2 compares
  that commit with what the runner will deploy.
- Required CI green on that exact commit. A skipped Full Suite is reported as deferred, never as passed (CG-17, CG-18).
- Outside the backup window 18:25–18:40 UTC (02:25–02:40 WITA).
- One operator, with no other deploy, rollback or quarantine running.

1. **Prove the target:** `bash scripts/production-target-check.sh --remote` → `PRODUCTION_TARGET=VERIFIED` (§3).
   Never deploy on the strength of an alias name alone.
2. **Confirm what will be deployed.** The runner pins the **tip of the base branch at launch**, not a commit you pass
   in. Confirm that tip from the host itself, and push nothing to the base between the authorization and `start`:
   ```bash
   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n git -c safe.directory=/var/www/asia-dental-lab-v2 ls-remote origin refs/heads/feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report'
   ```
   It must print the authorized commit. Otherwise stop.
3. **Check backup protection (read-only):** the host-only BK-2 status check in the owner's private backup runbook must
   report both the on-server and the off-server copy healthy. The deploy takes and verifies its own database dump
   before the checkout. Never delete BK-1, BK-2,
   the historical Hostinger backups or the rollback database as part of a deploy.
4. **Start** with the canonical command (§6), and write down the `deploy_id` from `log=…/deploy-<deploy-id>.log`. The
   launcher returning is not completion; wait for the status file (`… deploy-vps-runner.sh follow`, or poll the log).
5. **Prove it by its identifier:**
   ```bash
   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh verify <deploy-id>'
   ```
   It must print `DEPLOY RUNNER OK`. Any other verdict (exit `4`) is not GO. Never read `latest.status` raw, and
   never use `verify-transition` again (§6a).
6. **Smoke over the canonical URL** (`/login`, `/health/live`, `/health/ready`, `/health/lb` → 200; `/storage/…` →
   403). The deploy's own loopback smoke reporting `404` is D6, not a result.
7. **Confirm nothing else moved:** the queue worker, the scheduler and the KTP cleanup timer keep their owner-set state
   (the deploy restarts only `php8.3-fpm` and reloads only `nginx`), and the backup timers stay enabled.
8. **GO tag** per CG-16, after production verification; fetch tags explicitly first.

**Rollback** is `ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/rollback-vps.sh <commit>'`,
following `docs/runbooks/vps-deploy-rollback-runbook.md`. It runs the same host preflight, takes a verified database
dump first and moves code only. It never restores data. A data restore is a separate, explicitly owner-approved
operation, and an older dump is never restored over newer clinical data.

## 7. What stays true from the Hostinger era

- CG-15: deploy only through the runner, executed on the production host, complete only at `exit=0` plus `DEPLOY OK`.
- Backup before migrate; `migrate --force` only; never `migrate:fresh`, `db:wipe`, `schema:drop` or `migrate:reset` -- never.
- Historical sprint records keep their Hostinger names (`srv1730088`, `ssh daengtisiams-vps`). They record what was
  true when they were written, and they are not rewritten.
