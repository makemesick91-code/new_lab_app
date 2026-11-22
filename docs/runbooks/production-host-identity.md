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
| `daengtisiams-biznet-deploy` | Biznet production, the restricted deploy operator (rule 235), same address and pinned host key | deploys and read-only checks: its key runs only the operator entrypoint's allowlisted actions |
| `daengtisiams-biznet-prod` | Biznet production, `dmsadmin@139.190.106.38`, pinned host key | the administrator (break-glass): its sudo needs the owner's password since rule 235 |
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

The deploy operator's entry has the same `HostName`, `StrictHostKeyChecking` and known-hosts lines; its `User` and
`IdentityFile` name the operator account and its own key (kept on the workstation, never in this repository). Prove
it with `bash scripts/production-target-check.sh --operator --remote`.

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

- Rule 235 (`.cursor/rules/235-deploy-least-privilege-sudo.mdc`): no account holds passwordless sudo.
- The **deploy operator** (`daengtisiams-biznet-deploy`) runs one program: its key is forced onto the root-owned
  `/usr/local/sbin/dms-deploy-operator`, and sudo lets it run only that, with these requests:
  `start --release-id <id> --approved-sha <sha>`, `follow`, `status`, `verify <deploy-id>`, `trust-check`,
  `drift-check`, `sudo-policy-check`, `posture` (e.g. `ssh daengtisiams-biznet-deploy posture`). Nothing else —
  no shell, no rollback, no restore.
- `dmsadmin` is the administrative login (break-glass). Root has no SSH login and its password is locked. Since
  rule 235 its sudo needs its password, which only the owner holds: run such steps in an interactive session
  (`ssh -t daengtisiams-biznet-prod 'sudo …'`), never with `sudo -n` from a script, and never type the password into
  a chat, a file or a command line. If SSH is unavailable, the provider's web console takes the same account.
- composer and npm run as the build identity `daengtisiams-build` in a sandboxed transient service (rule 235), never
  as root.
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
`ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh start --release-id <release-id> --approved-sha <APPROVED_SHA>'`.
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

Rule 229 (`.cursor/rules/229-exact-approved-release-sha-guard.mdc`, 2026-11-14): a deploy lands only on the exact
commit a release approval record merged on the base branch approves. The runner never deploys the branch tip.

**Preconditions (all, or stop).**
- The release commit is merged and its required CI is green on that exact commit. A skipped Full Suite is reported
  as deferred, never as passed (CG-17, CG-18). Write its full SHA down as `APPROVED_SHA`.
- The owner's written approval of `APPROVED_SHA` for production (CG-22), recorded in the sprint document, and the
  release approval record `deploy/release-approvals/<release-id>.approval` (format: `deploy/release-approvals/README.md`)
  merged on the base after it. Nothing but approval records and `docs/**/*.md` may be merged after `APPROVED_SHA`.
- The record's window (`DMS_APPROVAL_NOT_AFTER`, at most 72 hours) covers the deploy.
- Outside the backup window 18:25–18:40 UTC (02:25–02:40 WITA).
- One operator, with no other deploy, rollback or quarantine running.
- The production checkout already carries the guard (`grep -c approve_or_deny scripts/deploy-vps-runner.sh` on the
  host prints a non-zero count). If it prints `0`, follow §6c instead.
- Rule 231 (`.cursor/rules/231-release-guard-trust-hardening.mdc`): the record is contract 2, names the host key
  (`DMS_APPROVAL_HOST_KEY_SHA256`) and was **signed by the owner** — `deploy/release-approvals/<release-id>.approval.sig`
  is merged with it (§6d step C). The host's trust anchor is installed and `trust-check` reports
  `RELEASE GUARD TRUST: READY`. The production checkout already carries rule 231
  (`sudo -n grep -c 'DMS_RELEASE_APPROVAL_CONTRACT=2' scripts/lib/release-approval.sh` prints a non-zero count); if it
  prints `0`, follow §6d instead. An approval deploys once: a re-deploy needs a new signed record.

1. **Prove the target:** `bash scripts/production-target-check.sh --remote` → `PRODUCTION_TARGET=VERIFIED` (§3), and
   since rule 235 (§6f) the operator alias too: `bash scripts/production-target-check.sh --operator --remote`. Never
   deploy on the strength of an alias name alone.
   Since rule 235, steps 2–7 run through the operator alias; the `sudo -n` forms below are the pre-cutover history and
   now need the administrator's password.
2. **Read the approval from the host (read-only).** Refs only; the working tree is not touched:
   ```bash
   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n git fetch --no-tags origin feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report && sudo -n git show FETCH_HEAD:deploy/release-approvals/<release-id>.approval'
   ```
   It must name `APPROVED_SHA`, this host and the base branch. Otherwise stop. With rule 231 in force, also confirm
   the signature and the host's readiness (both read-only):
   ```bash
   # One sudo for the whole pipeline: sudo closes inherited descriptors, so a <(…) made outside it is unreadable.
   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash -c "git show FETCH_HEAD:deploy/release-approvals/<release-id>.approval | ssh-keygen -Y verify -f /etc/daengtisiams/release-approvers.allowed_signers -I owner -n dms-release-approval -s <(git show FETCH_HEAD:deploy/release-approvals/<release-id>.approval.sig)"'
   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh trust-check'
   ```
   With the operator (rule 235): verify the merged record's signature on the workstation against the owner's public
   key (`ssh-keygen -Y verify … -I owner -n dms-release-approval`, as the sprint docs record), then
   `ssh daengtisiams-biznet-deploy trust-check`. The runner re-verifies the record on the host before it launches
   anything.
3. **Check backup protection (read-only):** the deploy takes and verifies its own database dump before the checkout;
   that dump is the required pre-deploy backup, and a failed dump stops the deploy before the checkout. BK-2 is
   **not** a deploy gate (rule 232, owner directive `DMS-BK2-MANUAL-ONLY-1`, 2026-11-14): do not wait on the host BK-2
   status check or the workstation verdict, and do not ask the owner to attach the backup HDD. Only an explicit owner
   order brings a BK-2 check into a deploy (then follow `docs/runbooks/bk2-offserver-workstation-recovery.md` §7);
   "deploy", "merge" or "GO" is not that order. The BK-2 timers keep running; never disable them. Never delete BK-1,
   BK-2, the historical Hostinger backups or the rollback database as part of a deploy.
4. **Start** with the approved release and commit, and write down the `deploy_id`:
   ```bash
   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh start --release-id <release-id> --approved-sha <APPROVED_SHA>'
   ```
   With the operator (rule 235): `ssh daengtisiams-biznet-deploy start --release-id <release-id> --approved-sha <APPROVED_SHA>`
   (then `follow`). The entrypoint runs exactly the in-tree runner above, as root, on the VPS.
   The runner verifies the approval before it writes or launches anything and prints `RELEASE APPROVAL: GO`. A
   refusal prints `DEPLOYMENT=DENIED`, exits `77` and leaves nothing behind: do not retry with a different commit;
   fix the cause or get a new approval. The launcher returning is not completion; wait for the status file
   (`… deploy-vps-runner.sh follow`, or poll the log). If the SSH pipe drops, reconnect and follow the same
   `deploy_id`; never start a second deploy because a connection closed.
5. **Prove it by its identifier:**
   ```bash
   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/deploy-vps-runner.sh verify <deploy-id>'
   ```
   It must print `DEPLOY_APPROVED_TARGET_MATCH=YES (<APPROVED_SHA>)` and `DEPLOY RUNNER OK` (with rule 231 also the
   signer key, the host key and the evidence directory `/var/lib/daengtisiams-deploy/runner`). Any other verdict (exit
   `4`: `UNTRUSTED`, `TAMPERED`, `FOREIGN`, `INTERRUPTED`, …) is not GO. Never read `latest.status` raw, and never use
   `verify-transition` again (§6a).

   **Prove privilege separation (rule 233, read-only).** The run's own log carries
   `DEPLOY_PRIVSEP_RUNTIME_FOREIGN_ENTRIES=0`, `DEPLOY_PRIVSEP_SOURCE_FOREIGN_ENTRIES=0` and `DEPLOY_PRIVSEP=ENFORCED`
   before `DEPLOY OK`, and the drift check agrees afterwards:
   ```bash
   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/privsep-drift-check.sh'
   ```
   It must print `PRIVSEP_DRIFT=NONE`. It changes nothing and runs no PHP; its summaries never print a file name. A
   runtime write failure is never fixed by running a step as root or widening a mode (rule 233 §3).
   With the operator (rule 235): `ssh daengtisiams-biznet-deploy verify <deploy-id>`, `… drift-check` and
   `… sudo-policy-check` (must print `SUDO_POLICY=LEAST_PRIVILEGE`); the run's log also shows composer and npm as the
   build identity. `… posture` gives HEAD, units, timers, locks and the application log's ERROR count for step 7.
6. **Smoke over the canonical URL** (`/login`, `/health/live`, `/health/ready`, `/health/lb` → 200; `/storage/…` →
   403). The deploy's own loopback smoke reporting `404` is D6, not a result.
7. **Confirm nothing else moved:** the queue worker, the scheduler and the KTP cleanup timer keep their owner-set state
   (the deploy restarts only `php8.3-fpm` and reloads only `nginx`), and the backup timers stay enabled.
8. **GO tag** per CG-16 on `APPROVED_SHA` itself, after production verification; fetch tags explicitly first.

**Rollback** is `ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash scripts/rollback-vps.sh <commit>'`,
following `docs/runbooks/vps-deploy-rollback-runbook.md`. Since rule 235 it is the administrator's break-glass step
on the owner's explicit decision: `ssh -t daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo bash scripts/rollback-vps.sh <full-sha>'`
with the owner typing the password; the deploy operator cannot roll back. The target must be an ancestor of the running checkout
(rule 229); anything else is refused before the backup. It runs the same host preflight, takes a verified database
dump first and moves code only. It never restores data. A data restore is a separate, explicitly owner-approved
operation, and an older dump is never restored over newer clinical data. Since rule 233 a rollback, like a deploy, runs
every artisan command (and the dump) as the runtime user and ends with the same privilege-drift gate; its snapshot is
the CURRENT code, so a rollback to a release older than rule 233 still runs separated.

## 6c. The first deploy that carries the approved-SHA guard (one-time bootstrap)

**Why.** A production checkout older than rule 229 holds a runner without the guard. That runner must not launch the
release that installs the guard, and nothing in the live checkout may be edited by hand. Instead, the approved
commit's own deploy chain is exported from the git object into a new root-only directory and **that** runner is
started. It runs every rule 229 check; there is no transitional bypass. This is used once per host; afterwards §6b
applies.

**Status on Biznet `DaengtisiaMS`: spent.** It ran once on 2026-11-14 (release `dms-rme-revision-program-8d`, deploy
`20261114-011345`, `DEPLOY RUNNER OK`; `docs/sprints/dms-rme-revision-program-8d.md` §12). The in-tree runner there
now carries the guard, so every later Biznet deploy follows §6b. §6c applies only to a host whose runner still prints
`0` for the guard.

**Preconditions.** All of §6b's, except the last one (the checkout does not carry the guard yet).

0. **Record the starting state (read-only):** HEAD, a clean tracked tree, and `0` for the guard in the live runner:
   ```bash
   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n git rev-parse HEAD && sudo -n git status --porcelain --untracked-files=no | wc -l && sudo -n grep -c approve_or_deny scripts/deploy-vps-runner.sh'
   ```
1. **Prove the target and read the approval** (§6b steps 1–3).
2. **Export the approved chain from the object.** The directory must not exist yet; if it does, stop and inspect it,
   never reuse an unknown copy:
   ```bash
   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n git cat-file -e <APPROVED_SHA>^{commit} && sudo -n install -d -m 0700 /root/dms-release-<APPROVED_SHA> && sudo -n git archive <APPROVED_SHA> -- scripts deploy | sudo -n tar -x --no-same-owner -C /root/dms-release-<APPROVED_SHA>'
   ```
3. **Prove its provenance:** the exported runner is byte-identical to the commit object's:
   ```bash
   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n sha256sum /root/dms-release-<APPROVED_SHA>/scripts/deploy-vps-runner.sh && sudo -n git cat-file blob <APPROVED_SHA>:scripts/deploy-vps-runner.sh | sha256sum'
   ```
   The two hashes must be equal. Otherwise stop.
4. **Start the exported runner from the app directory:**
   ```bash
   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash /root/dms-release-<APPROVED_SHA>/scripts/deploy-vps-runner.sh start --release-id <release-id> --approved-sha <APPROVED_SHA>'
   ```
   It launches the deploy program beside itself (the export), which hands over to the exported bootstrap; the
   snapshot comes from `APPROVED_SHA`. Follow the `deploy_id` it prints (§6b step 4).
5. **Prove it with the deployed in-tree runner**, which is now the guarded one: §6b step 5 (`verify <deploy-id>` →
   `DEPLOY_APPROVED_TARGET_MATCH=YES` and `DEPLOY RUNNER OK`). The record's `program=` names the export.
6. **Smoke, confirm nothing else moved, GO tag:** §6b steps 6–8.
7. **Reconcile the export** only after step 5 reports `DEPLOY RUNNER OK` and no runner process remains
   (`pgrep -af dms-deploy-runner` prints nothing). Run that check as the only remote command, or write the pattern as
   `[d]ms-deploy-runner`: inside a compound remote command, `pgrep -f` also matches the shell running it, because the
   shell's own command line contains the text. Remove exactly that directory, nothing else:
   ```bash
   ssh daengtisiams-biznet-prod 'sudo -n rm -rf -- /root/dms-release-<APPROVED_SHA>'
   ```
   If the deploy failed, keep the export until the failure is understood.

**What never happens.** The live runner is not edited or replaced by hand; no `git pull` or hard reset is run in the
production tree; the export is never taken from a workstation's object store; evidence files are never edited.

## 6d. The first deploy that carries rule 231 (one-time bootstrap)

**Why.** Rule 231 makes an approval count only with the owner's SSH signature, verified against a trust anchor on the
host, puts the runner's evidence in a root-only directory outside the checkout, and gives deploy and rollback one
lock. A production checkout older than rule 231 verifies only unsigned contract-1 records and locks per role: its
runner refuses the signed contract-2 record, and the new snapshot stage refuses its lock — both before any mutation.
So the release that installs rule 231 is started, once, from its own deploy chain exported from the approved commit
object, exactly as in §6c. Every rule 231 check runs; there is no transitional bypass and no unsigned record is ever
accepted. Afterwards §6b applies. (Rolling back below this release restores the old guard; the next forward deploy then
uses §6d again.)

**Detect it (read-only):** `sudo -n grep -c 'DMS_RELEASE_APPROVAL_CONTRACT=2' scripts/lib/release-approval.sh` in the
production checkout prints `0`. **Spent on Biznet:** deploy `20261114-225508` (2026-11-15, release `dms-release-guard-trust-hardening-1-r2`,
`d555c7dc`) installed rule 231 there, and the marker now prints `1`: use §6b.

**Owner-only steps, once, before the release is approved.** These are the owner's decisions and actions; an assistant
prepares commands and records, it never holds or uses the signing key.

- **A. The signing key** — on the owner's own workstation, never on the server and never in the repository, protected
  by a passphrase: `ssh-keygen -t ed25519 -C dms-release-approval -f <a private path of the owner's choosing>`. Keep its
  public half; note its fingerprint (`ssh-keygen -l -E sha256 -f <public key>`). Losing the private key means
  re-provisioning the anchor (step B) with a new key; a leaked key means removing its line from the anchor at once.
- **B. The trust anchor on the host** — an owner-authorised production change (root; outside the checkout; no
  application, database or service is touched). Build the line from the PUBLIC key only, then install it:
  ```bash
  printf 'owner namespaces="dms-release-approval" %s\n' "$(cat <public key>)" \
    | ssh daengtisiams-biznet-prod 'sudo -n install -d -m 0755 -o root -g root /etc/daengtisiams && sudo -n tee /etc/daengtisiams/release-approvers.allowed_signers.new >/dev/null && sudo -n chown root:root /etc/daengtisiams/release-approvers.allowed_signers.new && sudo -n chmod 0644 /etc/daengtisiams/release-approvers.allowed_signers.new && sudo -n mv -f /etc/daengtisiams/release-approvers.allowed_signers.new /etc/daengtisiams/release-approvers.allowed_signers'
  ssh daengtisiams-biznet-prod 'sudo -n stat -c "%U:%G %a %n" /etc/daengtisiams /etc/daengtisiams/release-approvers.allowed_signers && sudo -n cut -d" " -f3- /etc/daengtisiams/release-approvers.allowed_signers | ssh-keygen -l -E sha256 -f -'
  ```
  It must print `root:root 755` for the directory, `root:root 644` for the file, and the fingerprint noted in step A
  (`ssh-keygen -l` reads keys, not allowed-signers lines, hence the `cut` to the key part of the line built above).
  The anchor holds public keys only; it is not a secret, but it is never committed (the repository is public and the
  anchor must not be replaceable by a commit).
- **C. The signed approval** — after the release commit is merged and its CI is green, and after the owner's written
  approval of the full SHA (CG-22, recorded in the evidence document), the record
  `deploy/release-approvals/<release-id>.approval` is prepared with `DMS_APPROVAL_CONTRACT=2` and
  `DMS_APPROVAL_HOST_KEY_SHA256` equal to `DMS_PRODUCTION_HOST_KEY_SHA256` (format: `deploy/release-approvals/README.md`)
  and committed on the approval branch. The owner reviews it and signs the **committed blob** (exactly the bytes the
  host will read; an editor's line endings in the working tree cannot creep in):
  ```bash
  git show HEAD:deploy/release-approvals/<release-id>.approval \
    | ssh-keygen -Y sign -f <private key> -n dms-release-approval > deploy/release-approvals/<release-id>.approval.sig
  ```
  Record, signature and evidence document are merged together through a pull request. Any change to the record after
  signing breaks the signature: sign again. Anyone holding the public key can check it independently:
  `ssh-keygen -Y verify -f <allowed-signers file> -I owner -n dms-release-approval -s <release-id>.approval.sig < <release-id>.approval`.

**Preconditions.** All of §6b's (backup window, one operator, the approval window), except the last two (the
checkout does not carry rule 231 yet), plus steps A–C done.

0. **Record the starting state (read-only):** HEAD, a clean tracked tree, `0` for the rule 231 marker, the lock free:
   ```bash
   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n git rev-parse HEAD && sudo -n git status --porcelain --untracked-files=no | wc -l && sudo -n grep -c "DMS_RELEASE_APPROVAL_CONTRACT=2" scripts/lib/release-approval.sh'
   ```
1. **Prove the target and read the approval** (§6b steps 1–3, including the signature check of §6b step 2). The
   in-tree runner predates rule 231 and has no `trust-check` action; skip that line here — step 3 runs it from the export.
2. **Export the approved chain from the object**, exactly as §6c step 2 (a new root-only `/root/dms-release-<APPROVED_SHA>`
   that does not exist yet).
3. **Prove its provenance** (§6c step 3) and the host's readiness from the export:
   ```bash
   ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash /root/dms-release-<APPROVED_SHA>/scripts/deploy-vps-runner.sh trust-check'
   ```
   It must print `TRUST_ANCHOR=OK`, `HOST_KEY=OK`, `EVIDENCE_DIR=ABSENT` (created on start) and
   `RELEASE GUARD TRUST: READY`. Otherwise stop.
4. **Start the exported runner from the app directory** (§6c step 4, same command). It writes its evidence to
   `/var/lib/daengtisiams-deploy/runner/`; follow the `deploy_id` it prints with the exported runner's `follow`.
5. **Prove it with the deployed in-tree runner**, which now carries rule 231: §6b step 5.
6. **Smoke, confirm nothing else moved, GO tag:** §6b steps 6–8.
7. **Reconcile the export** as §6c step 7.

**What never happens.** No unsigned or contract-1 record is accepted; the anchor never comes from the repository; the
evidence of earlier deploys in `storage/logs/deploy-runner/` is left exactly where it is (never moved, edited or
deleted); the live runner is not edited by hand.

## 6f. Least-privilege identities: one-time provisioning and cutover (rule 235)

Run once per host, by the administrator, while its passwordless sudo still exists (on Biznet: the first release that
carries rule 235). Each mutating step is dry-run first, then `--apply`; stop at the first refusal.

**Status on Biznet `DaengtisiaMS`: spent.** It ran on 2026-11-16 (release `dms-deploy-privsep-phase-2-r3`, deploy
`20261116-022520`, `DEPLOY RUNNER OK`; passwordless sudo retired 02:28:48Z; `SUDO_POLICY=LEAST_PRIVILEGE`;
`docs/sprints/dms-deploy-privsep-phase-2.md` §7 and §12). Two earlier attempts stopped before any deploy and are the
reason steps 3 and 5 exist as written: the build rehearsal refused esbuild's in-tree hardlink (r2 fix), and the
operator refused every action on the stock Node.js symlinks (r3 fix). Every Biznet deploy now follows §6b through
the operator; administrator root steps need the owner's password.

1. **Export the approved release** (§6c step 2, the whole `scripts` and `deploy` trees) into a new root-only
   `/root/dms-release-<APPROVED_SHA>` and prove every exported file byte-identical to the commit object (§6c step 3,
   for each file). Everything below runs from that export, never from the working tree.
2. **Build identity:** `sudo -n bash /root/dms-release-<APPROVED_SHA>/scripts/provision-deploy-identities.sh --build-identity`
   (dry run), then with `--apply`.
3. **Rehearse the unprivileged build** on a scratch export (the application is not touched):
   `sudo -n bash /root/dms-release-<APPROVED_SHA>/scripts/build-privsep-rehearsal.sh --ref <APPROVED_SHA>` →
   `BUILD_PRIVSEP_REHEARSAL=PASS`. A failure stops the cutover; the deploy would fail the same way.
4. **Deploy operator:** the workstation creates the operator's own ED25519 key (its private half never leaves the
   workstation); copy only the public half to the host, then
   `… provision-deploy-identities.sh --operator --operator-user <name> --operator-key <public key file>` (dry run),
   then with `--apply`. It ends with the operator's sudo verdict `OPERATOR`.
5. **Prove the boundary (read-only):** as the administrator, `sudo -l -U <operator> <entrypoint> <args>` answers for
   the positive requests and refuses the negative ones (a shell, `status; id`, rollback, any other command); over SSH
   the operator key refuses a shell and every non-allowlisted request (`DEPLOY_OPERATOR=DENIED`, exit 64) and answers
   `trust-check` (`RELEASE GUARD TRUST: READY`). Add the workstation alias (§2). `posture`, and with it
   `production-target-check.sh --operator --remote`, answers only once the checkout carries
   `scripts/production-posture.sh`, i.e. after step 6 on a host's first rule 235 release: until then the operator's
   target rests on the administrator alias's `VERIFIED`, the operator alias's static checks (same pinned host key)
   and the operator's `trust-check` (`HOST_KEY=OK`); run the `--operator --remote` check right after the deploy.
6. **Deploy through the operator** (§6b steps 4–8 in their rule 235 form). This is the first proof that the restricted
   path carries a real deploy, and the first deploy whose composer and npm run as the build identity. Then remove the
   export (§6c step 7) and any rehearsal leftovers, while the administrator's root steps need no password yet.
7. **Retire the administrator's passwordless sudo — only after the owner proved the password.** The owner, in their
   own terminal: `ssh -t daengtisiams-biznet-prod 'sudo -k; sudo -v && echo ADMIN_PASSWORD_OK'` (`sudo -v` asks for
   the password while any of the account's rules needs one; `sudo -u root true` would still match the passwordless
   rule and prove nothing). Only after `ADMIN_PASSWORD_OK`: `… provision-deploy-identities.sh --retire-admin-nopasswd --admin-password-verified` (dry run,
   then `--apply`); the drop-in is moved to `/root/sudoers-retired/`, never deleted. Then `sudo -k; sudo -n true` as
   `dmsadmin` must fail ("a password is required") and `ssh daengtisiams-biznet-deploy sudo-policy-check` must print
   `SUDO_POLICY=LEAST_PRIVILEGE`.
8. **If anything breaks:** the administrator keeps sudo with the owner's password (SSH, or the provider's web console
   with the same account); a retired drop-in is restored by moving it back under `visudo -c`. Never re-enable
   passwordless sudo silently: record it as a deviation in the sprint doc.

## 6g. Releases under approval contract 3 — direct signed approval (rule 236)

Applies once the host lists contract `3` in `DMS_PRODUCTION_RELEASE_APPROVAL_CONTRACTS` and the operator entrypoint
was re-provisioned with `submit-approval` (rule 236 §3). No approval branch, commit, PR, CI run or merge.

0. **One-time activation (per host, after the release that carries rule 236 is deployed under contract 2 and
   verified).** Until then the installed entrypoint is the previous one: `sudo-policy-check` reports
   `DEPLOY_OPERATOR_ENTRYPOINT=STALE` (a WATCH; its three expressions are a subset of the five rendered ones, so the
   operator still classifies `OPERATOR`) and `submit-approval`/`check-approval` are refused. The owner, in their own
   terminal (the administrator's sudo needs the password since rule 235), stages the operator's public key in the
   administrator's home and re-provisions from the deployed tree, dry run then apply, in one session:
   `ssh daengtisiams-biznet-prod 'cat > ~/operator.pub' < <operator public key>` then
   `ssh -t daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo bash scripts/provision-deploy-identities.sh --operator --operator-user <operator> --operator-key ~/operator.pub && sudo bash scripts/provision-deploy-identities.sh --operator --operator-user <operator> --operator-key ~/operator.pub --apply && rm -f ~/operator.pub'`.
   Then (no password): `ssh daengtisiams-biznet-deploy sudo-policy-check` → `DEPLOY_OPERATOR_ENTRYPOINT=CURRENT`,
   `SUDO_POLICY=LEAST_PRIVILEGE`; `ssh daengtisiams-biznet-deploy trust-check` → `APPROVAL_CONTRACTS=2 3`,
   `APPROVAL_STORE=ABSENT|OK`, `RELEASE GUARD TRUST: READY`. The first contract-3 approval is proven with
   `check-approval` (step 4) before any contract-3 deploy.

   **Dispatch evidence (rule 236 §5) has no separate switch:** it ships in the authority and the guard of a release.
   That release is verified by the guard already installed, so it needs its **push** run. After it is deployed,
   `verify <deploy-id>` passes and the GO tag exists, the first release whose newest accepted run is a dispatch is
   proven read-only with `check-approval` (step 4): `PASS: required CI — workflow_dispatch run <id>` and
   `RELEASE APPROVAL CHECK: GO`. Record that run id in the sprint doc as the production verification of §5.
   Done on 2026-11-21: dispatch run 44898680653 (CI-CD-PR-ONLY-POLICY-1 phase B4).

   **PR-only CI (rule 236 §5.7, phase B5):** the workflow has no `push` or `schedule` trigger, so a merge to the base
   branch starts no run. The release's CI evidence is the manual dispatch below. A push run exists only for commits
   from before phase B5, or after a CI trigger restoration (`docs/governance/go-tag-exceptions.md`).

1. **Release commit:** implementation PR merged, with its pull request CI green. The host needs CI of the exact merge
   commit, which since phase B5 only a manual dispatch provides.

   **The manual dispatch run (rule 236 §5)** — on a host whose installed authority lists `workflow_dispatch` in
   `DMS_PRODUCTION_CI_EVENTS` (true since the phase-B release, deployed and verified on 2026-11-21). While the base
   branch tip **is** the release SHA, dispatch the workflow on the base branch with
   its default inputs: `gh workflow run foundation-evidence-gates.yml --ref <base branch>`. Never set
   `critical_test_workers` (a CI-PERF-04 evaluation run is refused, rule 212 §3); `runner_mode` and the Full Suite
   inputs are not needed. Then confirm the run is for the release SHA:
   `gh run list --workflow foundation-evidence-gates.yml --branch <base branch> --event workflow_dispatch --limit 1 --json databaseId,headSha,status,conclusion`
   → `headSha` equals the SHA and the run ends `completed`/`success`. A dispatch after the branch moved on runs the
   newer tip and never proves the release SHA. Two dispatches on the base branch share one concurrency group, so a
   second dispatch cancels the first (and so would a push run after a restoration): the newest run is the one the
   host checks, so dispatch once and let it finish green. The dispatch is CI evidence only, never an approval (step 2
   stays mandatory).
2. **Owner approval and signature (owner only, own terminal):** the owner states the written approval of the full SHA
   (CG-22, recorded in the sprint doc), then runs
   `bash scripts/release-approval-sign.sh --release-id <id> --target-sha <SHA> --go-tag <go_tag> [--hours 48]`
   from an up-to-date checkout. It shows the record and the window (UTC and WITA), asks YES on the terminal, signs
   with the owner's key (passphrase typed; ssh-agent not used) and prints the bundle path, its digests and the submit
   command. An assistant never runs it for the owner and never sees the key.
3. **Prove the target:** `bash scripts/production-target-check.sh --operator --remote` → `VERIFIED`;
   `ssh daengtisiams-biznet-deploy trust-check` → `APPROVAL_STORE=OK`, `RELEASE GUARD TRUST: READY`.
4. **Submit:** `ssh daengtisiams-biznet-deploy submit-approval --release-id <id> < <bundle>` →
   `RELEASE APPROVAL SUBMITTED: <approval-id>`. A refusal changes nothing; fix the cause (never edit a signed record —
   sign a new one). Then prove it read-only:
   `ssh daengtisiams-biznet-deploy check-approval --release-id <id> --approved-sha <SHA>` →
   `RELEASE APPROVAL CHECK: GO` (the whole guard, CI included; nothing is claimed or launched). `BUSY` means a
   deploy, rollback, submission or another check is running: run it again afterwards. A rate-limit denial names the
   reset time (UTC); the check keeps 4 GitHub requests in reserve for the deploy.
5. **Deploy, verify, smoke, GO:** §6b steps 4–8 through the operator (`start`, `follow`, `verify <deploy-id>` — the
   run record names `approval_contract=3`, the approval id, digests and the CI run id). Outside 02:25–02:40 WITA.
   The deploy's own pre-deploy dump stays mandatory; BK-2 is not a gate (rule 232).
6. **Withdraw** (administrator, owner's password): `sudo bash scripts/release-approval-withdraw.sh --release-id <id>
   --approval-id <hex>` — the approval can never deploy and no longer blocks a corrected one. Never prune or move the
   store: proofs re-hash it.
7. **Record:** the closure doc lists the approval id and the record/signature SHA-256 (off-host anchor).

## 7. What stays true from the Hostinger era

- CG-15: deploy only through the runner, executed on the production host, complete only at `exit=0` plus `DEPLOY OK`.
- Backup before migrate; `migrate --force` only; never `migrate:fresh`, `db:wipe`, `schema:drop` or `migrate:reset` -- never.
- Historical sprint records keep their Hostinger names (`srv1730088`, `ssh daengtisiams-vps`). They record what was
  true when they were written, and they are not rewritten.
