# DMS-DEPLOY-PRIVSEP-PHASE-2 — least-privilege deploy identities (rule 235)

**Date:** 2026-11-15 (WITA). **Type:** security fix / deployment (Foundation-First Sprint Lock: allowed category).
**Status:** IMPLEMENTED — release, deploy and production verification pending (§10).

| Item | Value |
|---|---|
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` at `eb4f22bcabc5b60d901d86d09b90b9ca3fcf113c` |
| Production before | `9312818fc9115707b9e0b351ae805080be465b4a` (rule 233, deploy `20261115-063339`, GO `dms-deploy-privsep-hardening-1-go`) |
| Branch | `fix/dms-deploy-privsep-phase-2` |
| Implementation PR / merge SHA | #636 → **`c7638a6f9ab52d6ae21ea538c86a6a2107887c29`** (tree `b04a6ad3df823f7fb9305a896b2eab7a64c78c97` = tested head tree) |
| Release id / approval | pending (`dms-deploy-privsep-phase-2`) |
| Deploy id | pending |
| GO tag | `dms-deploy-privsep-phase-2-go` (declared in `.sprint/current.yml`; created only after production verification) |
| Durable rule | `.cursor/rules/235-deploy-least-privilege-sudo.mdc` (AIR-DEP-40 in `.claude/rules/deploy-infra.md`; row in rule 233 §4) |

## 1. Owner request (CG-22)

The owner ordered this sprint end to end (2026-11-15): remove unrestricted sudo from the deploy account (OD-6) and the
remaining privilege-escalation paths of rule 233 §9 — composer and npm as root (R1), tracked files inside runtime
storage (R2), restores without digest verification (R4) — with least privilege, through the full DEVFLOW to a
production-verified GO, the rules made permanent, and a verified cleanup. Standing conditions: owner-signed release
approval of the exact SHA; rollback only on explicit owner approval; `deploy-vps-runner.sh` on the VPS only; the
deploy's own pre-deploy database dump mandatory; BK-2 never run (`DMS-BK2-MANUAL-ONLY-1`); offline key custody
optional (`DMS-OFFLINE-CUSTODY-OPTIONAL-1`); no deployment in 02:25–02:40 WITA; never claim a gate without evidence.

The brief's provisional ids OD-7/OD-8/OD-9 are the canonical rule 233 §9 residuals **R1**, **R2** and **R4**; this
record uses those ids (no new finding ids were minted).

## 2. Baseline (production, read-only, 2026-11-15, before implementation)

`bash scripts/production-target-check.sh --remote` → `PRODUCTION_TARGET=VERIFIED` (host key
`SHA256:i3As…O2bc`). The base tip equals the previous closure (`eb4f22bc`): nothing landed since rule 233's release.

| Fact | Value |
|---|---|
| Deploy/admin login | `dmsadmin` (groups `dmsadmin`, `adm`, `sudo`); **one** authorized key — the workstation key the agent uses, no passphrase |
| Its effective sudo (`sudo -ll -U`) | `/etc/sudoers` `%sudo ALL=(ALL:ALL) ALL` (password) **plus** `/etc/sudoers.d/10-dmsadmin` `dmsadmin ALL=(ALL) NOPASSWD:ALL` — **OD-6 root cause** |
| sudo | 1.9.15p5 (regular-expression command arguments supported); Defaults `env_reset`, `secure_path`, `use_pty` |
| Passwords | root **locked**; `dmsadmin` has a password (set 2026-11-12); runtime user locked |
| sshd | key-only (`PasswordAuthentication no`), `PermitRootLogin prohibit-password`, root `authorized_keys` empty; hardening drop-in names the provider web console as the emergency path |
| Other login-shell accounts | `postgres` (system); the BK-2 pull account (name in the owner's private tree); no other sudo rights |
| Cron / units using the admin | none (`/var/spool/cron/crontabs` empty; no unit references the admin) |
| Build tools | `/usr/bin/composer` (root 755), `/usr/local/bin/{node,npm,npx}` → `/opt/nodejs/node-v22.23.4…` (root 755) |
| PATH directories, app directory, `scripts/`, `deploy/` | all `root:root 755`; no untrusted entry under `scripts/` or `deploy/` |
| Build trees | `vendor/` 13,226 entries, `node_modules/` 6,452, `public/build` 19 — all `root:root` (rule 233 claim) |
| Production environment file | 0 `ASSET_URL` lines; 1 `VITE_` line, which no frontend code reads (`import.meta.env` unused) |
| Live asset manifest | `public/build/manifest.json` sha256 `30fa536d…` |
| systemd | 255 |

## 3. Audit and threat model

Attacker A: anyone holding the workstation's admin key (a compromised workstation, agent or session). Attacker B: code
execution as the runtime user (rule 233's attacker). Attacker C: a malicious composer/npm dependency. Asset: root on
the host, hence every patient record, key and backup.

### 3.1 OD-6 — what the deploy account could do before this sprint

| # | Capability | Before | After this sprint |
|---|---|---|---|
| D1 | Run any command as root / open a root shell | **yes** (`NOPASSWD: ALL`) | operator: no (one program, allowlisted requests); admin: only with the owner's password |
| D2 | Change the sudo policy | yes | no (operator); password (admin) |
| D3 | Change trusted deploy scripts, the runner, the trust anchor | yes (as root) | no: operator cannot write; the entrypoint refuses an untrusted `scripts/`/`deploy/` tree, PATH entry or program chain |
| D4 | Replace an executable a privileged script runs; PATH tricks | yes | no: fixed PATH of root-owned directories, verified on every call; `env -i` |
| D5 | Inject environment into privileged scripts | yes | no: `env_reset`, `!setenv`, the entrypoint reads only `SSH_ORIGINAL_COMMAND` and clears the rest |
| D6 | Argument injection / shell expansion / globbing | n/a (had a shell) | no: strict character set, split on single spaces without a shell, validated twice (entrypoint + sudo expressions) |
| D7 | Symlinks / writable entrypoints / interpreter abuse / wildcards | yes | no: no symlink, foreign or writable entry accepted; no interpreter or wildcard in the rule |
| D8 | Unauthorized rollback or database restore | yes | operator: unreachable; admin: password (owner) + existing guards |
| D9 | Disable the release trust check | yes | no |
| D10 | Read the owner's signing private key | no (never on the host) | no |

### 3.2 Residuals of rule 233 §9

| # | Vector | Severity | Disposition |
|---|---|---|---|
| R1a | `npm ci` runs every dependency's lifecycle scripts, `npm run build` runs vite and plugins — as root, on deploy and rollback | **HIGH** (supply chain → root) | **Fixed**: build identity in a sandboxed transient service; trees handed over / claimed back |
| R1b | `composer install` as root (scripts already off since rule 233; plugins still loadable) | MEDIUM | **Fixed**: build identity |
| R1c | `composer --version` probe as root from the application directory — composer can activate a plugin from `vendor/` | MEDIUM (found in this audit) | **Fixed**: probes from `/`, composer `--no-plugins` |
| R1d | npm/vite as root read the environment file (vite loads it) and inherit root's exported secrets | MEDIUM | **Fixed**: the sandbox gets a clean environment; the environment files the asset build reads (`.env`, `.env.local`, `.env.production`, `.env.production.local`) are masked with `/dev/null`; the mask list is computed before the first mutation (12 environment files on the host, all plain names, 2026-11-15) |
| R2 | Five tracked June-2026 development scripts under `storage/` (`*_smoke.php`, `seedcheck.php`) create records with model factories — on production they would fabricate data (CG-13); root's checkout writes them into a runtime-owned tree | MEDIUM | **Fixed**: removed (no reference anywhere); a test allows only `.gitignore`/`.gitkeep` under the runtime trees |
| R4 | Both superuser restore tools stream a runtime-owned plain-SQL dump to psql; a dump changed after the deploy could run psql meta-commands as the PostgreSQL superuser's OS account; the rehearsal even re-read its fresh snapshot from the runtime-owned file | MEDIUM | **Fixed**: trusted digest evidence required; root-only staging copy verified; only the copy is restored |
| R4b | The digest the deploy logged was computed by the runtime user over its own file (`as_runtime sha256sum`), so a resident runtime process could swap the dump between pg_dump and the hash (found by the independent review, §8 F1) | **HIGH** | **Fixed**: root runs pg_dump into a private file in the run's root-only snapshot, hashes it, hands the bytes to the runtime user on a read-only stdin, and refuses a runtime copy that differs |

## 4. Design

```
workstation ──ssh (operator key, restrict + forced command)──▶ /usr/local/sbin/dms-deploy-operator (gateway, as operator)
     │                                                              │ validated words only
     │                                                              ▼
     │                                         sudo (rule: entrypoint + ^…$ expressions, env_reset, !setenv)
     │                                                              ▼
     │                                         entrypoint (root): re-validate, trust checks, env -i
     │                                                              ▼
     │                    deploy-vps-runner.sh start|follow|status|verify|trust-check · privsep-drift-check.sh
     │                    sudo-policy-check.sh · production-posture.sh
     │                                                              ▼ (start)
     │                    rules 229/231 approval guard → immutable snapshot → deploy-vps.sh (root)
     │                         ├─ artisan, dump, gates ─▶ runtime user (rule 233)
     │                         └─ composer, npm ───────▶ build identity, systemd-run sandbox (rule 235)
     └──ssh (admin key) ──▶ dmsadmin ── sudo + owner's password ──▶ break-glass: rollback, restore, BK-2, provisioning
```

| Identity | May | Never |
|---|---|---|
| Deploy operator | the eight allowlisted entrypoint requests | a shell, any other command, rollback, restore, host changes |
| Administrator (`dmsadmin`) | everything, **with the owner's password** | passwordless root |
| Build identity (`daengtisiams-build`) | write `vendor/`, `node_modules/`, `public/build` and its home during one sandboxed step | read the environment file, keep a process alive, create setuid files, write anywhere else |
| Runtime user | unchanged (rule 233) | — |

Evaluated and not chosen (owner-visible): building assets in CI and verifying them on the VPS removes host-side
dependency resolution, but changes the delivery model; left as a follow-up decision (rule 235 §5).

## 5. Implementation (files)

- New: `scripts/deploy-operator-entrypoint.sh`, `scripts/lib/sudo-policy.sh`, `scripts/sudo-policy-check.sh`,
  `scripts/production-posture.sh`, `scripts/provision-deploy-identities.sh`, `scripts/lib/build-privsep.sh`,
  `scripts/build-privsep-rehearsal.sh`, `scripts/lib/restore-integrity.sh`.
- Changed: `scripts/deploy-vps.sh`, `scripts/rollback-vps.sh` (build identity, sandboxed composer/npm, neutral version
  probes), `scripts/restore_postgres.sh`, `scripts/restore-rehearsal.sh` (evidence, staging copy),
  `scripts/production-target-check.sh` (`--operator`), `scripts/lib/production-host-preflight.sh` (`systemd-run`,
  `pgrep` required), `deploy/production-host.conf` (build identity, operator entrypoint/sudoers, deploy alias),
  `app/Support/Deploy/DeploymentRollbackScanner.php` + `config/deployment_rollback.php` (root composer/npm rejected,
  build markers required), `config/deployment_entrypoint.php` (closure), `config/ci_runner.php` (mandatory suite).
- Removed: `storage/lo_smoke.php`, `storage/prod_smoke.php`, `storage/qc_smoke.php`, `storage/rep_smoke.php`,
  `storage/seedcheck.php`.
- Tests: `tests/Feature/Deploy/DeployHardenLeastPrivilegeTest.php` (new); two rule 233 pins re-pointed to the rule 235
  forms with the same intent (`DeployHardenPrivilegeSeparationTest` accepted form, `DeployHardenRunnerEvidenceContractTest`
  probe block).
- Rules and docs: rule 235, AIR-DEP-40 (+ AIR-DEP-16/31/33/35/36/39 tightened to stay within the 30,000-character
  budget: one-time historical facts moved out, no constraint dropped), rule 233 §4 row, CLAUDE.md CG-14/CG-15,
  AGENTS.md, runbook `production-host-identity.md` §2, §5, §6b, §6f (new), `docs/00-INDEX.md`, `.sprint/current.yml`.

Unchanged: schema, migrations, permissions, roles, routes, JS/CSS sources, packages, flags, branch locks, queue worker,
timers, the release approval contract, the runner, the immutable bootstrap.

## 6. Tests and quality gates (local, WSL2, PHP 8.3.6)

| Gate | Result |
|---|---|
| New suite `DeployHardenLeastPrivilegeTest` | **all passed** (entrypoint allow/deny matrix incl. 32 hostile requests through the real gateway with a sudo tripwire; classifier matrix of 28 policies incl. every review bypass; rendered rule ↔ entrypoint agreement; `visudo -cf`; build hand-over/claim refusals; sandbox properties via a `systemd-run` stub; R4 evidence/staging/digest matrix; restore tools refuse missing evidence; storage placeholders; `--operator` target check offline) |
| The 71 test files touching anything this change touches (deploy, rollback, restore, host authority, scanner, CI runner, rules, docs) | **1,552 tests**: 1,550 passed in the final run; 2 failed and were fixed — a pin on the old dump line (`SecretFilePermissionHardeningTest`, re-pointed to both private-creation calls) and the storage guard before the deletions were staged — and their suites re-run: **190 passed** |
| Warnings | one per test file: phpdotenv probing the worktree's absent environment file (`tests/TestCase.php`), as on the untouched base; CI writes one |
| ShellCheck 0.9.0 (`-x -P SCRIPTDIR`) | 8 new scripts: **0 findings**; every changed script reports exactly its base's codes (SC1091 info on the environment file; SC2119/SC2120 in `runtime-privsep.sh`, rule 233) |
| Pint | passed |
| `visudo -cf` on the rendered operator rule | `parsed OK` |
| Rule 233 pins re-pointed (same intent) | `DeployHardenPrivilegeSeparationTest` (accepted composer form → the build hand-off; dump lines → root-hashed staging), `DeployHardenRunnerEvidenceContractTest` (probe block), `SecretFilePermissionHardeningTest` (both dump calls private) |
| PR #636 CI, head `fbad4e2d` | run `43712846447`: Critical Test Gate **10,217 passed, 1 failed** — `visudo` on the CI runner predates sudo 1.9.10 and rejects regular-expression arguments ("regular expressions are not supported"); production and the workstation run 1.9.15p5 and parse the rule. Fixed: provisioning now refuses a sudo older than 1.9.10 before it changes anything, and the test requires `visudo` to accept the rule where sudo ≥ 1.9.10 and to refuse it for exactly that reason where it is older. Selective Module, Quality and Android gates passed |
| PR #636 CI, head `9fc67915` | run `43719716535` **success**: Critical Test Gate **10,218 passed**, 0 failed (`DeployHardenLeastPrivilegeTest` ran: PASS); Selective Module Gate **839 passed**; Quality, Android, NSF-9, NSF-10 gates passed; Full Suite skipped = DEFERRED |
| Merge | squash, pinned to the tested head `9fc67915ad4881979ec6169fa743df95eb176770`: **`c7638a6f9ab52d6ae21ea538c86a6a2107887c29`**, parent `eb4f22bc`, tree `b04a6ad3…` = tested head tree |
| Post-merge push CI on `c7638a6f…` | run `43725518536` **success** (14:39Z → 15:57Z): Critical Test Gate **10,218 passed**, 0 failed (`DeployHardenLeastPrivilegeTest` PASS); Selective Module, Quality, Android, NSF-9, NSF-10 gates success; Full Suite skipped = DEFERRED. Base tip still `c7638a6f…`, tree `b04a6ad3…` unchanged |
| r2 fix PR #638, head `be0106f4` | run `43741198071` **success**: Critical Test Gate **10,220 passed**, 0 failed (`DeployHardenLeastPrivilegeTest` PASS, incl. the two hardlink tests); Selective, Quality, Android, NSF-9, NSF-10 passed; Full Suite skipped = DEFERRED |
| r2 merge | squash, pinned to `be0106f47034bf5bc5f1fd7a216add4eb22c4c12`: **`92cb5572304c38e74aa5c48b146a9bcad0bc98e5`**, parent `861ded62`, tree `86e5b43cdf5281cadba4ddb606b7903df4d191b3` = tested head tree |
| Post-merge push CI on `92cb5572…` | run `43748162075` **success** (18:41Z → 19:58Z): Critical Test Gate **10,220 passed**, 0 failed; Selective, Quality, Android, NSF-9, NSF-10 success; Full Suite skipped = DEFERRED. Base tip still `92cb5572…`, tree `86e5b43c…` unchanged |
| Full Suite | **DEFERRED** (CG-18 global policy) — not passed |
| Graphify 0.9.99 `graphify update .` | 58,628 nodes, 142,366 edges, 2,449 communities (rule 233's tree: 58,463 / 142,051 / 2,421); `graphify-out/` untracked |

## 7. On-host build rehearsal

### 7.1 First rehearsal of `c7638a6f` (ended 2026-11-15T17:20Z, 01:20 WITA) — FAILED, deploy not started

After the approval PR (#637) merged (`861ded62`), runbook §6f ran on the host: target verified, the merged record's
signature verified against the host anchor, `trust-check` READY; export of `c7638a6f` (72 files, 0 mismatches, 0
extra, 0 symlinks; `git archive` left directories 0775 and files 0664 — group write removed inside the root-only
0700 export before use); build identity provisioned (`daengtisiams-build`, uid 995, `nologin`, locked, own group
only; home `root:root 0755` holding only `cache/` 0700). The rehearsal ran composer, `npm ci` and `vite build` as the
build identity in the sandbox successfully (environment-file mask included), then **refused the claim**:
`node_modules: 2 hardlinked file(s) — NOT GO`.

Cause: esbuild's install script replaces `node_modules/esbuild/bin/esbuild` with a hardlink to
`node_modules/@esbuild/linux-x64/bin/esbuild`. The live tree already held that pair (inode with 2 names, both inside
`node_modules/`, from the old root `npm ci`), so the approved release would have failed at the hand-over of
`node_modules/` — after the checkout and composer — leaving production half-updated. The deploy was **not** started;
nothing was cut over. The export was removed; no rehearsal workspace, build process or transient unit remained;
HEAD `9312818f`, tracked tree clean, `PRIVSEP_DRIFT=NONE`. The build identity and its 65 MB package cache stay (unused).

Fix (release `dms-deploy-privsep-phase-2-r2`): hand-over and claim refuse only a hardlinked file whose inode has a
name **outside** the tree (`_dms_build_escaping_hardlinks`: names found inside < link count); a pair inside the tree
is the build's own, and with `fs.protected_hardlinks=1` (rule 233's preflight) the build identity can only hardlink
inodes it owns. A host file linked into a tree is still refused (inside count 1 < link count 2). Tests: the esbuild
pattern passes hand-over and claim; escaping links are refused at both. The signed `c7638a6f` record is never
edited; `r2` needs a new owner approval and signature of its own SHA.

## 8. Independent review

A separate agent reviewed the uncommitted change adversarially, given the code and the goals only (read-only, no
production access, its own temporary directory, deleted afterwards). It found **no path from the deploy operator to
root through the entrypoint** (gateway parsing, the dispatcher's exact argc/word re-validation against sudo's
space-joined regex matching, the rendered rule, the key options and the release guard all held), and confirmed the
build hand-over/claim, the sandbox's environment isolation and the restore mechanics as sound. Its findings, all fixed
before the PR:

| # | Finding | Severity | Disposition |
|---|---|---|---|
| F1 | R4's "trusted" digest was the runtime user's hash of its own file — a resident runtime process could swap a poisoned dump in before it | **HIGH** | **Fixed** (§3.2 R4b) |
| F2 | Classifier false negatives: `!authenticate`/`rootpw`/`targetpw` in Defaults, command-specific Defaults, `env_keep`/`runchroot`, Chroot/Cwd, a broad character-class expression (`^[ -z]+$`) still classified OPERATOR/ADMIN_PASSWORD | MEDIUM | **Fixed**: operator commands must equal the rendered expressions exactly (single source `dms_sudo_operator_expressions`); strict Defaults/options/run-as checks for both roles; `secure_path` rendered into the drop-in; every bypass is a test case |
| F3 | The build identity owned its home: a malicious package could plant `~/.ssh/authorized_keys` (key login works for locked accounts under PAM) and keep a process outside the sandbox; a live build process found mid-deploy would stop the deploy after the checkout | MEDIUM | **Fixed**: home root-owned, only `cache/` (0700) is the identity's; the identity check refuses anything else in the home and any running build process, before the first mutation |
| F4 | The retirement counted any password `ALL` rule (even run-as another user) and ignored `rootpw`/`targetpw` (root's password is locked) — a possible lock-out | LOW (high impact) | **Fixed**: the administrator's listing without the passwordless rule must classify `ADMIN_PASSWORD` (a password rule reaching root; no password bypass or redirect) |
| F5 | The dispatcher resolved its first commands through the inherited PATH; its trust closure omitted the tools root runs and `.git` | LOW | **Fixed**: PATH pinned first; tools (and node's whole installation) and `.git` verified |
| F6 | A world-writable directory left by a build could take entries from another identity between the claim's checks and its chown | LOW | **Fixed**: the claim refuses any group/other-writable entry before it changes anything (setgid directories allowed, setuid/setgid files refused) |
| F7 | Scanner: `dms_build_run … npm ci && npm run build` passed (prefix judged per line); `nodejs`, `yarnpkg`, `bun`, `npm-cli.js` unknown | LOW | **Fixed**: each `;`/`&&`/`||`/`|` segment judged; tools added; deliberate obfuscation stays a review matter (rule 235 §5) |
| F8 | Re-provisioning an existing account could follow a planted symlink when writing the key; its password was not locked; supplementary groups not refused | LOW | **Fixed**: key written in root's directory and renamed into place; password locked; an account with supplementary groups or running processes refused |
| F9 | The sandbox could read `storage/framework/cache` and `.git/config` and reach loopback services (PostgreSQL) | LOW | **Fixed**: `storage/`, `bootstrap/cache`, `.git` inaccessible; loopback denied except the DNS stub `127.0.0.53` (the host's resolver, verified) |
| F10 | `follow` streams raw build output to the operator's terminal; `sudo -ll` format assumptions | INFO | Residual noted (rule 235 §5); the listing format is checked on the host before the operator is used (§12) |

The reviewer's suggested attestation `sudo -u root true` was **not** adopted: with the passwordless rule still present
the last matching rule wins and sudo would never prompt; `sudo -k; sudo -v` does prompt under the default
`verifypw=all` (rule 235 §4).

## 9. Residual risks and open owner decisions

- **OD-6 cutover is owner-gated.** The administrator's passwordless rule is retired only after the owner proves the
  password in their own terminal (runbook §6f step 7); root's password is locked, so that password (and the
  provider's web console) is the recovery path. The owner approved `dmsadmin` as the break-glass administrator role
  and proved the password over SSH and the console (§11.1–11.2).
- Build caches persist between deploys (a poisoned cache reaches only vendor code the runtime already runs, never root).
- Root's dump staging copy lives in the run's snapshot directory on the `/run` tmpfs (1.6 GB, 1.1 MB used; dump
  ≈ 34 MB on 2026-11-15): a database past ≈ 1.5 GB would stop the deploy — closed, before the checkout — until the
  staging moves to disk.
- Host-side dependency resolution stays (lockfiles authoritative); CI-built artifacts are a separate decision.
- `scripts/rollout-restore-drill.sh` (staging only, refuses production) restores as the application role without the
  digest check.
- OD-2 (rollback floor) and rule 231 §8c S1–S8 stand; roll back by full SHA only.

## 10. Release states

| State | Evidence |
|---|---|
| Implemented, locally verified | §6 |
| Reviewed | §8: no operator-to-root path; 1 HIGH, 2 MEDIUM, 6 LOW fixed before the PR |
| PR / CI / merge | #636 merged `c7638a6f`; PR CI and post-merge CI green (§6) |
| Release approval (owner signature) | owner approval 15:59:05Z (§11.3), signature verified (§11.4); approval PR #637 merged `861ded62` |
| First rehearsal (`c7638a6f`) | **FAILED** at the claim (esbuild hardlink pair) — deploy not started (§7.1); fix → release `-r2` |
| r2 fix | PR #638 merged `92cb5572` (§6); post-merge CI green (§6) |
| One-time provisioning, rehearsal, deploy through the operator | pending (runbook §6f) |
| Administrator's passwordless sudo retired (owner-gated) | pending |
| Production verification, GO tag | pending |

## 11. Owner decisions (CG-22)

### 11.1 Administrator role and recovery path (2026-11-15T13:50Z, 21:50 WITA)

Asked in the session (the brief allows unrestricted sudo to survive only as an explicitly approved, independent
administrative role). The owner answered: **Admin role — "Approve password-only"**: `dmsadmin` stays the break-glass
administrator with sudo that needs its password; the passwordless drop-in is moved to `/root/sudoers-retired/`, never
deleted. **Recovery — "Yes, both"**: the owner knows the `dmsadmin` password and can log in to the Biznet Gio web
console.

### 11.2 OD-6 cutover approval (written, 2026-11-15, after 11.1)

The owner reported verifying recovery manually: VPS `DaengtisiaMS`; SSH administrator login PASS; Biznet web console
login PASS; user `dmsadmin`; administrator password SET & VERIFIED; `sudo -k` PASS; `sudo -v` over SSH PASS;
`sudo -v` through the Biznet console PASS. This is the owner's proof required by runbook §6f step 7.

The owner approved the OD-6 cutover under these conditions (translated; the owner wrote in Indonesian):

1. Keep administrator access, password-only.
2. Apply the restricted deployment operator as designed and tested.
3. Validate the sudo configuration before and after the change.
4. Do not remove the old sudo configuration until its replacement is verified.
5. Prove that legitimate commands succeed and unauthorized ones are refused.
6. Prove that no equivalent unrestricted sudo path exists.
7. Prove that SSH and Biznet-console recovery keep working.
8. Keep the release guard, the signed approval and rollback authorization.
9. Never run BK-2.
10. No GO tag until all CI, the deployment and production verification pass.

"Continue the DEVFLOW automatically to GO when every gate is met. If the cutover fails, STOP; no rollback or risky
recovery change without the owner's approval." The deploy of the merged release still needs the owner's written
approval of its exact SHA and the owner's signature on its record (rule 231) — §11.3.

### 11.3 Release approval of the exact SHA (2026-11-15T15:59:05Z, 23:59 WITA)

Asked after post-merge CI run `43725518536` was green on the base tip, with the preflight of §12.1 and the residual
risks of §9 presented. The owner answered **"Approve c7638a6f…"** to: deploying
**`c7638a6f9ab52d6ae21ea538c86a6a2107887c29`** (tree `b04a6ad3df823f7fb9305a896b2eab7a64c78c97`; PR #636) to production on
Biznet `DaengtisiaMS`, release id `dms-deploy-privsep-phase-2`, through runbook §6f, under these conditions: the
one-time provisioning and the OD-6 cutover exactly as approved in §11.1–11.2; the pre-deploy database dump mandatory;
BK-2 skipped (`DMS-BK2-MANUAL-ONLY-1`); rollback only on the owner's explicit go-ahead, by full SHA
`9312818fc9115707b9e0b351ae805080be465b4a`, code only; read-only smoke; the GO tag `dms-deploy-privsep-phase-2-go`
only after production verification; a 48-hour record window.

Record: `deploy/release-approvals/dms-deploy-privsep-phase-2.approval` (contract 2, window 2026-11-15T15:59:05Z →
2026-11-17T15:59:05Z, 935 bytes, printable ASCII only). The owner signs the committed record with their own key; an
assistant never holds or uses that key.

### 11.4 The owner's signature (verified on the workstation, read-only)

The owner signed the record file in their own terminal with their own key (`ssh-keygen -Y sign`, namespace
`dms-release-approval`, file form) and reported "Signed". Signature blob `25fb3ab9dec7a503c8d0f96ad36144ecdbe9a4fe`.
Verified against the **committed** record blob `52d8f8f432e5496247a2d088ef272aea2beac429` with the owner's public key
as principal `owner`:

| Check | Result |
|---|---|
| Record file vs committed blob | identical |
| Committed blob, principal `owner`, namespace `dms-release-approval` | `Good "dms-release-approval" signature for owner with ED25519 key SHA256:0WGGTlMDzLPGAhQaRG+Ag7BqoypSLinSNOHalZKtgt4` — the key in the host's trust anchor |
| Namespace `git` | refused (`namespace does not match`, exit 255) |
| Record with `NOT_AFTER` one second later | refused (`incorrect signature`, exit 255) |
| Principal `someone` | refused (exit 255) |

The allowed-signers file used for this check lived in the session scratchpad only; it was never committed.

### 11.5 Release approval r2 of the exact SHA (2026-11-15T20:37:37Z)

The `c7638a6f` release was not deployed (§7.1). After the fix PR #638 merged as `92cb5572…` and its post-merge CI run
`43748162075` was green, the owner answered **"Approve 92cb5572…"** to: deploying
**`92cb5572304c38e74aa5c48b146a9bcad0bc98e5`** (tree `86e5b43cdf5281cadba4ddb606b7903df4d191b3`; PR #638) to
production on Biznet `DaengtisiaMS` as release `dms-deploy-privsep-phase-2-r2`, through runbook §6f, under the same
conditions as §11.3 (provisioning and the OD-6 cutover as approved in §11.1–11.2; pre-deploy dump mandatory; BK-2
skipped; rollback only on the owner's explicit go-ahead, by full SHA `9312818fc9115707b9e0b351ae805080be465b4a`,
code only; read-only smoke; GO tag `dms-deploy-privsep-phase-2-go` only after production verification; 48-hour
window). The `c7638a6f` record stays untouched and unused.

Record: `deploy/release-approvals/dms-deploy-privsep-phase-2-r2.approval` (contract 2, window 2026-11-15T20:37:37Z → 2026-11-17T20:37:37Z).

## 12. Deploy and production verification

### 12.1 Pre-approval preflight (read-only, 2026-11-15T15:15Z, 23:15 WITA)

**Workstation trust store.** `production-target-check.sh --remote` first reported `NOT_VERIFIED`: the workstation's
known-hosts file had gained two extra entries for the production address at 21:45:42 WITA (RSA `SHA256:yexu…Ih4Q`,
ECDSA `SHA256:6Vhf…6u5c`) during the owner's manual recovery check (an SSH login outside the alias, whose
`UpdateHostKeys` default records the host's other key types after the pinned key authenticated it). The pinned
ED25519 key was unchanged. Nothing was connected through the unverified alias. The owner chose the cleanup, verified
all three host keys in the Biznet web console (all MATCH) and approved removing exactly those two entries: the file
was backed up, the two lines removed, the pinned ED25519 line kept, no other host's key touched. The target check then
reported `PRODUCTION_TARGET=VERIFIED`, and the host's own `/etc/ssh/ssh_host_{ed25519,rsa,ecdsa}_key.pub`
fingerprints, read over the pinned channel, equal the pin and the two removed entries.

| Check | Result |
|---|---|
| `trust-check` (in-tree runner) | `TRUST_ANCHOR=OK` (1 signer key), `HOST_KEY=OK (SHA256:i3As…O2bc)`, `EVIDENCE_DIR=OK`, **`RELEASE GUARD TRUST: READY`** |
| HEAD / tracked tree | `9312818fc9115707b9e0b351ae805080be465b4a` / clean |
| Release, deploy, rollback locks | idle |
| Units / timers | `php8.3-fpm`, `nginx`, `postgresql` active; queue worker inactive+disabled (C5); db-backup and BK-2 package timers active; KTP-prune and pilot-snapshot timers disabled |
| Application log | 166 ERROR lines (the rule 233 GO baseline) |
| Privilege drift | `PRIVSEP_DRIFT=NONE` (runtime 0, source 0 foreign entries) |
| Pending migrations | none |
| Disk / `/run` | 48 G free / 1.6 G free |
| Sudo (OD-6, before) | `/etc/sudoers.d/10-dmsadmin` = `dmsadmin ALL=(ALL) NOPASSWD:ALL`; operator and build accounts absent |

