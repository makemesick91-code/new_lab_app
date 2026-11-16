# DMS-DEPLOY-PRIVSEP-PHASE-2 — least-privilege deploy identities (rule 235)

**Date:** 2026-11-15 → 2026-11-16 (WITA). **Type:** security fix / deployment (Foundation-First Sprint Lock: allowed category).
**Status:** **COMPLETE / PRODUCTION VERIFIED / GO** (2026-11-16; §12). Full Suite deferred (CG-18), not passed.

| Item | Value |
|---|---|
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` at `eb4f22bcabc5b60d901d86d09b90b9ca3fcf113c` |
| Production before | `9312818fc9115707b9e0b351ae805080be465b4a` (rule 233, deploy `20261115-063339`, GO `dms-deploy-privsep-hardening-1-go`) |
| Branch | `fix/dms-deploy-privsep-phase-2` |
| Implementation PR / merge SHA | #636 → **`c7638a6f9ab52d6ae21ea538c86a6a2107887c29`** (tree `b04a6ad3df823f7fb9305a896b2eab7a64c78c97` = tested head tree) |
| Fix PRs / merge SHAs | r2 #638 → `92cb5572304c38e74aa5c48b146a9bcad0bc98e5`; r3 #640 → **`75ac3578808a8dd8c720bf38e98a86685183eb66`** (tree `d7a13105cbccb9be95d687d1cc646e04b39d18ef` = tested head tree) |
| Release id / approval | **`dms-deploy-privsep-phase-2-r3`**, owner-approved and owner-signed, approval PR #641 → `ccbc313b` (unused, never edited: `dms-deploy-privsep-phase-2` #637, `-r2` #639) |
| Deploy id | **`20261116-022520`** (through the deploy operator; `DEPLOY RUNNER OK`) |
| GO tag | **`dms-deploy-privsep-phase-2-go`** → `75ac3578…` (annotated, tag object `35fd319b4afc1f225733f6608c8ebd251b9331cc`) |
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
| r3 fix PR #640, head `fb943082` | run `43767942068` **success**: Critical Test Gate **10,222 passed**, 0 failed (both r3 tests ran: PASS); Selective, Quality, Android, NSF-9, NSF-10 passed; Full Suite skipped = DEFERRED |
| r3 merge | squash, pinned to `fb94308259fc84315843aa5a03ce34322ab320f3`: **`75ac3578808a8dd8c720bf38e98a86685183eb66`**, parent `fcafdd70`, tree `d7a13105…` = tested head tree |
| Post-merge push CI on `75ac3578…` | run `43773683512` **success**: Critical Test Gate **10,222 passed**, 0 failed (the second runner variant skipped, as on every post-merge run); Selective, Quality, Android, NSF-9, NSF-10 success; Full Suite skipped = DEFERRED |
| Approval PR #641, head `d6224055` | run `43785839296` **success**: Critical Test Gate **10,222 passed** (docs-only skip step skipped: the tests ran) |
| Full Suite | **DEFERRED** (CG-18 global policy) — not passed |
| Graphify 0.9.99 `graphify update .` | 58,628 nodes, 142,366 edges, 2,449 communities (rule 233's tree: 58,463 / 142,051 / 2,421); at closure (`ccbc313b`, r3 included) 58,677 / 142,457 / 2,385 (§14); `graphify-out/` untracked |

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

### 7.2 Runbook §6f for `92cb5572` (r2) — rehearsal PASS, operator provisioned, operator refused every action; deploy not started

Approval PR #639 (record, signature, this doc) passed its CI on head `346ba003` (run `43759250169`: Critical Gate
`Run critical regression tests` 10,220 passed, the docs-only skip step skipped; Full Suite deferred, CG-18) and merged
as `fcafdd70` at 2026-11-15T21:50:59Z; only the record, its signature and this doc follow `92cb5572`.

| Step (§6f) | Result |
|---|---|
| Target | `PRODUCTION_TARGET=VERIFIED` (pinned ED25519 only) |
| Approval on the host | `FETCH_HEAD` `fcafdd70`; record SHA-256 `9108cae0…2891` equals the workstation's; `Good "dms-release-approval" signature for owner` against the host anchor; `trust-check` `RELEASE GUARD TRUST: READY`; HEAD `9312818f`, tracked tree clean |
| Export `/root/dms-release-92cb5572…` | 74 files in the commit, 74 on disk, 0 mismatches; root `0700`; 0 group/other-writable, 0 non-root, 0 symlinks |
| Build identity | dry run, then `--apply` (idempotent): home `root:root 0755`, `cache/` `0700` build-owned, `nologin`, own group only |
| Rehearsal `--ref 92cb5572…` | **`BUILD_PRIVSEP_REHEARSAL=PASS`**: vendor 13,226 / node_modules 6,452 / public/build 19 entries, all root-owned and not writable; `MANIFEST_SHA256` `30fa536d…53b7` equal to live (`BUILD_OUTPUT_IDENTICAL_TO_LIVE=YES`); composer 7 s, npm 12 s; 0 build processes, 0 workspaces left |
| Operator (`--operator`, dry run then `--apply`, 21:52:52Z) | account own group only, password locked; home and `.ssh` `root 0755`, one `restrict,command=` key `root 0644`; entrypoint `root:root 0755`, SHA-256 `92bb3b46…e772` equal to the commit blob; drop-in `20-dms-deploy-operator` `root 0440`; `visudo -c` OK; verdict `OPERATOR` |
| Sudo boundary (`sudo -l -U`, `sudo -ll -U \| cat -A`) | allowed: `start --release-id … --approved-sha <40-hex>`, `follow`, `status`, `verify <id>`, `trust-check`, `drift-check`, `sudo-policy-check`, `posture`; refused: `/bin/bash`, `/usr/bin/bash`, `/usr/bin/id`, the bare entrypoint, `rollback <sha>`, `status; id`, a 1-character release id, a 41-hex SHA, `verify ../x`, `shell`, `sudoedit /etc/sudoers` |
| SSH boundary (operator key) | no command, `id`, `status; id`, `rollback …`: `DEPLOY_OPERATOR=DENIED` exit 64; PTY refused; `-W` "administratively prohibited"; sftp and scp closed |
| **`ssh … trust-check` (allowed action)** | **`untrusted: node installation: … is a symlink, foreign-owned or group/other-writable`, `DEPLOY_OPERATOR=DENIED`, exit 65** |

Cause (read-only inspection): the host's Node.js installation (`/opt/nodejs/node-v22.23.4-linux-x64`, 5,866
entries, 0 non-root, 0 group/other-writable) is a stock tarball holding three relative symlinks into its own `lib/`:
`bin/npm`, `bin/npx`, `bin/corepack`. r2's `dms_operator_trusted_tree` refused **any** symlink, so the operator
refused every action on every real host; the CI fixtures never modelled a Node installation. A read-only simulation
of every root-side check of the installed entrypoint (sourced from the export, as root, `stat`/`find`/`readlink` and
version probes only) found nothing else: entrypoint chain, PATH, the program chains of the runner and the drift
check, the `scripts/`, `deploy/` and `.git` trees (0 symlinks each) and the 13 other tools PASS; the
`sudo-policy-check` and `posture` programs are absent until this release is deployed (expected). Under the
entrypoint's `env -i` environment git 2.43.0, node v22.23.4, npm 10.9.9, PHP 8.3.6, pg_dump 16.15, systemd 255,
runuser and Composer 2.7.1 all answer.

The deploy was **not** started; nothing was cut over: `10-dmsadmin` untouched, HEAD `9312818f`, tracked tree clean,
`PRIVSEP_DRIFT=NONE`, no runner process. The export was removed. The operator account, its key, the entrypoint and
its drop-in stay installed: they refuse every action (fail closed) until re-provisioned from r3, which reinstalls
the entrypoint. The r2 record stays unspent and unused, never edited.

Fix (release `dms-deploy-privsep-phase-2-r3`): `dms_operator_trusted_tree` keeps the ownership and mode walk (a
symlink's own mode is meaningless and is no longer tested; its owner still is), refuses a tree that is itself a
symlink or not absolute, and accepts a symlink only when **every step** of resolving it stays inside the tree
(`dms_operator_link_inside`): the target is relative, and the link's directory followed by each target component in
turn resolves (`readlink -z -e`, never trimmed) to the tree's canonical path or below it (`<root>/…`, so a
same-prefix sibling is outside), on the tree's own filesystem (a mount below the tree, which `find -xdev` never
walks, refuses). A dangling or looping link, an absolute target, a link leaving the tree through `..` or a sibling, a
link that leaves and comes back, and a failed walk (find's status ends the list, deterministically under errexit on
or off) all refuse.

Independent review of the first r3 draft (landing-only check): 1 MEDIUM — a link that leaves the tree through an
outside hop (`/tmp`, or a runtime-writable directory) and lands back inside was accepted, and the outside hop could
be repointed during a deploy (not exploitable on the host: no symlink under `scripts/`, `deploy/`, `.git`, and the
stock Node links stay inside); 1 LOW — a link into a mount below the tree; 1 test note — the walk-failure test reached
a different branch than production. All three fixed as above. Tests: the stock Node layout passes; every refusal is
pinned, including three leave-and-return shapes (outside file link, outside directory link, in-tree directory link
at another depth), each of which a landing-only check accepts (mutation-tested: restricting the position check to the
landing fails the suite; ignoring find's status fails it too). The final functions, run read-only on the host as
root against the live `scripts/`, `deploy/` and `.git` trees, the 14 tools (Node's installation included), the
entrypoint chain and the PATH, PASSED all 19. `r3` needs a new owner approval and signature of its own SHA.

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

- **OD-6 cutover: done** (2026-11-16T02:28:48Z, §12.3). Every administrator root step now needs the owner's password:
  root's password is locked, so that password (SSH, or the provider's web console) is the recovery path; the owner
  proved both after the cutover. Agents can no longer run `sudo -n` on the host; read-only host checks go through the
  operator (`posture`, `drift-check`, `sudo-policy-check`, `trust-check`).
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
| r2 release approval | owner approval (§11.5), signature (§11.6); approval PR #639 merged `fcafdd70` |
| §6f for r2 | rehearsal **PASS**, operator provisioned, boundary proven; the operator **refused every action** (Node.js symlinks) — deploy not started (§7.2); fix → release `-r3` |
| r3 fix | PR #640 (CI run `43767942068`: Critical Gate 10,222 passed) merged `75ac3578`; post-merge CI run `43773683512` green (Critical Gate 10,222 passed; Full Suite deferred, CG-18) |
| r3 release approval | owner approval 2026-11-16T00:56:39Z (§11.7), signature verified (§11.8); approval PR #641 merged `ccbc313b` |
| One-time provisioning, rehearsal, deploy through the operator | **done** (§12.2): deploy `20261116-022520`, `DEPLOY RUNNER OK` |
| Administrator's passwordless sudo retired (owner-gated) | **done** 02:28:48Z; `SUDO_POLICY=LEAST_PRIVILEGE`; owner `ADMIN_ROOT_OK` over SSH and console (§12.3) |
| Production verification, GO tag | **done**: §12.2–12.4; `dms-deploy-privsep-phase-2-go` → `75ac3578` |

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

### 11.6 The owner's r2 signature (verified on the workstation, read-only)

Owner-signed in their own terminal (`ssh-keygen -Y sign`, namespace `dms-release-approval`, file form); signature
blob `3acd5a465e4ef065e986506e0608bd2b54b9ef71` over the committed record blob
`af57da8ccfd6ee44fa7dbd31fb389c283c3eae36`: `Good "dms-release-approval" signature for owner with ED25519 key
SHA256:0WGGTlMDzLPGAhQaRG+Ag7BqoypSLinSNOHalZKtgt4`. Refused (exit 255): namespace `git`; `NOT_AFTER` one second
later; principal `someone`; and the `c7638a6f` signature against the r2 record.

### 11.7 Release approval r3 of the exact SHA (2026-11-16T00:56:39Z)

The r2 release was not deployed (§7.2). After the fix PR #640 merged as `75ac3578…` and its post-merge CI run
`43773683512` was green (Critical Gate 10,222 passed), and a read-only preflight (target `VERIFIED`, HEAD `9312818f`
clean, `RELEASE GUARD TRUST: READY`, `PRIVSEP_DRIFT=NONE`, no runner, 166 ERROR lines), the owner answered
**"Approve 75ac3578"** to: deploying **`75ac3578808a8dd8c720bf38e98a86685183eb66`** (tree
`d7a13105cbccb9be95d687d1cc646e04b39d18ef`; PR #640) to production on Biznet `DaengtisiaMS` as release
`dms-deploy-privsep-phase-2-r3`, through runbook §6f, under the same conditions as §11.5 (re-provision the operator,
which reinstalls the entrypoint; deploy through the operator; the OD-6 cutover as approved in §11.1–11.2, the
administrator's drop-in retired only after verification; pre-deploy dump mandatory; BK-2 skipped; rollback only on
the owner's explicit go-ahead, by full SHA `9312818fc9115707b9e0b351ae805080be465b4a`, code only; GO tag
`dms-deploy-privsep-phase-2-go` only after production verification; 48-hour window). The r2 and `c7638a6f` records
stay untouched and unused.

Record: `deploy/release-approvals/dms-deploy-privsep-phase-2-r3.approval` (contract 2, window 2026-11-16T00:56:39Z → 2026-11-18T00:56:39Z).

### 11.8 The owner's r3 signature (verified on the workstation, read-only)

Owner-signed in their own terminal (`ssh-keygen -Y sign`, namespace `dms-release-approval`, file form); signature
blob `dbd55c9cdc39addb00317b65b24cd2144ef7f46b` over the committed record blob
`badc4dc72a7b5dda46f44e9888ec75f1cb76588e`: `Good "dms-release-approval" signature for owner with ED25519 key
SHA256:0WGGTlMDzLPGAhQaRG+Ag7BqoypSLinSNOHalZKtgt4`. Refused (exit 255): namespace `git`; principal `someone`;
`NOT_AFTER` one second later; and the r2 signature against the r3 record.

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


### 12.2 Runbook §6f for `75ac3578` (r3) and the deploy through the operator (2026-11-16)

| Step | Result |
|---|---|
| Target | `PRODUCTION_TARGET=VERIFIED` (administrator alias) |
| Approval on the host | `FETCH_HEAD` `ccbc313b`; record SHA-256 `0194ae95…0d72` equals the workstation's; `Good "dms-release-approval" signature for owner` against the host anchor; `RELEASE GUARD TRUST: READY`; HEAD `9312818f`, tracked tree clean |
| Export `/root/dms-release-75ac3578…` | 76 files in the commit, 76 on disk, 0 mismatches (r2's record and signature are now in the tree); root `0700`; 0 writable, 0 non-root, 0 symlinks |
| Build identity | dry run, `--apply` (idempotent) |
| Rehearsal `--ref 75ac3578…` | **`BUILD_PRIVSEP_REHEARSAL=PASS`**: vendor 13,226 / node_modules 6,452 / public/build 19 entries, all root-owned and not writable; manifest `30fa536d…53b7` = live; composer 6 s, npm 15 s; 0 workspaces, 0 build processes left |
| Operator re-provisioned (`--operator`, dry run then `--apply`) | existing account accepted (own group only, password locked); entrypoint reinstalled, SHA-256 `3b1e0121…2461` = the commit blob (carries `dms_operator_link_inside`); drop-in re-rendered `root 0440`; `visudo -c` OK; verdict `OPERATOR` |
| Sudo boundary | `sudo -ll -U` lists exactly the three rendered expressions; **8/8** allowed, **11/11** refused (the §7.2 set) |
| SSH boundary | no command, `id`, `status; id`, `rollback …` → `DEPLOY_OPERATOR=DENIED` exit 64; PTY refused; `-W` administratively prohibited; sftp and scp closed |
| **`ssh … trust-check` (operator)** | **`RELEASE GUARD TRUST: READY`** (r2's refusal is gone) |
| `production-target-check.sh --operator --remote` (before the deploy) | `NOT_VERIFIED`: its remote half asks `posture`, whose program arrives with this release (`…production-posture.sh is missing`). Deviation, recorded: the operator's target rested on the administrator alias's `VERIFIED`, the operator alias's static checks (same address, only the pinned ED25519 key, `StrictHostKeyChecking yes`) and the operator's `trust-check` (`HOST_KEY=OK`); the check was run again right after the deploy (below). Runbook §6f step 5 now says so |
| Time | 10:24 WITA (outside the 02:25–02:40 WITA backup window) |
| **`ssh … start --release-id dms-deploy-privsep-phase-2-r3 --approved-sha 75ac3578…`** | `RELEASE APPROVAL: GO` (signature, ancestry with only approvals and docs after the target, release unspent, window); deploy **`20261116-022520`**, 02:25:20Z → 02:27:06Z |
| `verify 20261116-022520` (operator) | **`DEPLOY_APPROVED_TARGET_MATCH=YES (75ac3578…)`**, `final exit=0`, **`DEPLOY RUNNER OK`**; runner contract 4; signer key and host key recorded; status `exit=0` |
| Deploy log (1,276 lines, read as root before the cutover) | `PRODUCTION HOST PREFLIGHT: GO`; `Pre-deploy backup: …pre_auto_deploy_20261116-022521.sql sha256=ba61d690…734e`; `== Composer install (build identity, rule 235) ==` (nothing to install), `== NPM build (build identity, rule 235) ==` (`npm ci` + `vite build`); post-autoload-dump as the runtime user; `Nothing to migrate`; runtime isolation 107 GO / 0 FAIL; `DEPLOY_PRIVSEP_RUNTIME_FOREIGN_ENTRIES=0`, `…SOURCE_FOREIGN_ENTRIES=0`, **`DEPLOY_PRIVSEP=ENFORCED`**; nginx test OK; **`DEPLOY OK`** |
| Pre-deploy dump | 34,083,952 bytes, runtime-owned `0640`; SHA-256 recomputed as root = the logged digest |
| `drift-check` / `posture` (operator) | `PRIVSEP_DRIFT=NONE` (0/0); HEAD `75ac3578…`, tracked 0; php8.3-fpm, nginx, postgresql active; queue worker inactive+disabled; db-backup and BK-2 package timers active; KTP-prune and pilot-snapshot timers disabled (all unchanged); locks IDLE; **166** ERROR lines (= baseline) |
| `production-target-check.sh --operator --remote` (after) | **`PRODUCTION_TARGET=VERIFIED`** (hostname and address read through `posture`) |
| Smoke (canonical URL) | `/login`, `/health/live`, `/health/ready`, `/health/lb` → 200; `/storage/`, a private storage path → 403 |
| Build artifacts | manifest SHA-256 `30fa536d…53b7` (unchanged); `vendor/`, `node_modules/`, `public/build` 0 non-root or writable entries; `public/build` 0 symlinks (`node_modules` holds npm's 22 `.bin` links); 0 build processes; 0 transient units |
| Export | removed after `DEPLOY RUNNER OK` with no runner process; 0 exports left |

### 12.3 OD-6 cutover (2026-11-16T02:28:48Z)

Before: `visudo -c` OK; `/etc/sudoers.d/10-dmsadmin` = `dmsadmin ALL=(ALL) NOPASSWD:ALL`; the administrator's password
set (`P`); no cron, cron.d entry or systemd unit runs as the administrator; `sudo-policy-check` (operator) reported
`SUDO_POLICY=VIOLATION` with exactly one violation, that rule (expected before the cutover).
`provision-deploy-identities.sh --retire-admin-nopasswd --admin-password-verified` from the deployed tree: dry run,
then `--apply` → the drop-in moved to `/root/sudoers-retired/10-dmsadmin.20261116T022848Z` (kept, not deleted), whole
policy re-checked, `administrator sudo verdict: ADMIN_PASSWORD`, `ADMIN_NOPASSWD=RETIRED`.

| Check after | Result |
|---|---|
| `sudo -k; sudo -n true` as the administrator | **refused**: `sudo: a password is required` (exit 1); `sudo -n -l` exit 1 |
| `sudo-policy-check` (operator) | administrator `ADMIN_PASSWORD`, operator `OPERATOR`, 36 accounts without sudo, entrypoint `CURRENT`, 0 untrusted sudoers files, syntax OK, **0 violations, `SUDO_POLICY=LEAST_PRIVILEGE`** |
| Operator still works | `trust-check` READY, `drift-check` NONE; a negative request still `DENIED` |
| Target checks | administrator alias and operator alias both `PRODUCTION_TARGET=VERIFIED` |
| Owner recovery (condition 7) | the owner, in their own terminal: `ssh -t daengtisiams-biznet-prod 'sudo -k; sudo -v && echo ADMIN_ROOT_OK'` → **`ADMIN_ROOT_OK`**; Biznet web console login and `sudo -k; sudo -v` → PASS ("ADMIN_ROOT_OK (SSH + console)") |

OD-6 conditions (§11.2): 1 password-only administrator — met; 2 operator as designed and tested — met; 3 sudo
validated before and after — met; 4 old rule removed only after its replacement carried a real deploy — met (moved,
kept); 5 legitimate commands succeed, unauthorized refused — met (§12.2, above); 6 no equivalent unrestricted path —
`SUDO_POLICY=LEAST_PRIVILEGE` over every account; 7 SSH and console recovery — proven by the owner; 8 release guard,
signed approval and rollback authorization kept — met; 9 BK-2 never run — met; 10 GO only after CI, deploy and
verification — met.

### 12.4 GO

Before the tag: tags fetched; `dms-deploy-privsep-phase-2-go` absent locally and on `origin`; `go_tag` in
`75ac3578:.sprint/current.yml` = `dms-deploy-privsep-phase-2-go`; smoke 200 ×4 / 403; `posture` HEAD `75ac3578…`,
locks IDLE, 166 ERROR lines. Annotated tag **`dms-deploy-privsep-phase-2-go`** (object `35fd319b…`) on
**`75ac3578808a8dd8c720bf38e98a86685183eb66`** = the production HEAD, pushed and re-read from `origin`. BK-2: skipped
(rule 232). Rollback: none.

## 13. Permanent standards (owner brief, Phase P)

The owner's 20 standing policies, each with ONE authority (rule 233 §4 is the index) and its enforcement. No new
rule file was needed: the gaps were three index rows (host identity, finding evidence, temporary resources).

| # | Policy | Authority | Enforced by |
|---|---|---|---|
| 1 | No unrestricted sudo for deploy accounts | rule 235 §2.1 | `sudo-policy-check.sh` (`SUDO_POLICY`); `DeployHardenLeastPrivilegeTest` classifier matrix |
| 2 | Privileged execution only through allowlisted operations | rule 235 §2.2 | rendered sudo expressions + entrypoint allowlist, cross-checked by tests; host `sudo -l -U` probes |
| 3 | Trusted root entrypoints protected against lower-privilege writes | rule 235 §2.3 | entrypoint chain/tree/tool trust walk (fail closed, exit 65); tests incl. r3 leave-and-return |
| 4 | Privileged arguments validated | rule 235 §2.2–2.3 | strict request regex in the gateway and again as root; sudo regex arguments; 32 hostile requests in tests |
| 5 | composer/npm never as root | rule 235 §2.4 | `dms_build_run` sandbox; CI scanner (`build_line_prefix`); deploy log sections |
| 6 | Application PHP never as root | rule 233 | runtime privsep helpers; scanner; `DEPLOY_PRIVSEP=ENFORCED` |
| 7 | Runtime storage never controls trusted code | rule 233, rule 235 §2.5 | placeholders-only guard test; source/runtime foreign-entry gates |
| 8 | Restore requires integrity verification | rule 235 §2.6 | `restore-integrity.sh`; R4 evidence matrix tests |
| 9 | Production restore needs explicit authorization | rule 235 §2.7 | not reachable by the operator; administrator password; `--force-production` + typed confirmation |
| 10 | Rollback needs the owner's explicit decision | CG-15, CG-22, rule 231 §8c S1 | operator cannot roll back; full-SHA ancestor guard; administrator password |
| 11 | Runners execute on the approved VPS | CG-15, rule 92, AIR-DEP-01/02 | production host preflight in every deploy; immutable snapshot bootstrap |
| 12 | Host identity and release SHA verification mandatory | CG-14, rules 229/231 | `production-target-check.sh` (`--operator`); release guard `DEPLOYMENT=DENIED` |
| 13 | Privilege drift fails closed | rule 233 | `privsep-drift-check.sh`; the deploy's final gate |
| 14 | Pre-deploy database backup mandatory | rule 176, rule 235 §2.6 | the deploy stops before the checkout if the dump or its digest fails |
| 15 | BK-2 manual-only | rule 232 | never a gate; not invoked by deploy or rollback |
| 16 | Offline custody optional | `DMS-OFFLINE-CUSTODY-OPTIONAL-1` (rule 233 §4) | owner policy; no gate requires it |
| 17 | Read-only production smoke | CG-13, CG-14 | runbook §6b step 6; no fabricated records |
| 18 | GO tags only after production verification | CG-16, CG-17 | runbook §6b step 8; `verify` → `DEPLOY RUNNER OK` |
| 19 | Security findings closed only on evidence | CG-21 | sprint doc evidence tables; review findings fixed before merge |
| 20 | Temporary resources cleaned after the sprint | CG-20, CG-02 | closure cleanup with measured counts (§15) |

## 14. Knowledge and architecture

- **Graphify 0.9.99** (`graphify update .`, no LLM) on the closure tree `ccbc313b`: 58,628 → **58,677** nodes,
  142,366 → **142,457** edges, 2,385 communities. Present: `deploy-operator-entrypoint.sh` (26 nodes, incl.
  `dms_operator_link_inside`, `dms_operator_trusted_tree`, `dms_operator_dispatch`), `sudo-policy.sh`,
  `sudo-policy-check.sh`, `provision-deploy-identities.sh`, `build-privsep.sh` (`dms_build_run`, `dms_build_claim`,
  `dms_build_handover`, `_dms_build_escaping_hardlinks`), `build-privsep-rehearsal.sh`, `restore-integrity.sh`
  (`dms_restore_evidence_digest`), `production-posture.sh`, `runtime-privsep.sh` (`dms_privsep_as_runtime_fn_from`).
  Limit: for shell, Graphify extracts definitions within a file, not calls across sourced files (no edge
  `deploy-vps.sh` → `dms_build_run`). `graphify-out/` is untracked.
- **Dataviz:** a security-hardening summary (privileged operations before/after, allowed vs refused probes per channel,
  the three release attempts, findings closed, production checks), measured data only, no host address, account name
  or key material; palette validated with the dataviz validator (light and dark: all checks pass). Private artifact,
  shared by the owner if wanted.
- **Obsidian:** the vault worktree is synchronized to the closure merge commit after this PR merges (CG-02: detached
  checkout, never an edit); the result is reported with the final report.
- **Rules touched at closure:** rule 233 §4 (three index rows), runbook §6f (status; step 5 bootstrap ordering),
  `docs/ai-knowledge/23_DaengtisiaMS_Deployment_VPS_Runbook.md` (access line). `CLAUDE.md` CG-14/CG-15, `AGENTS.md`,
  rule 235 and AIR-DEP-40 already state the post-cutover model (PR #636) and are unchanged.

## 15. Cleanup (measured before the closure PR)

| Resource | Result |
|---|---|
| Worktrees | 4 removed (implementation/r2, r2 approval, r3, r3 approval; each clean, its PR merged); the closure worktree is removed after its merge; `~/Projects/dms-release-approval-direct-1` is the next sprint's (DMS-RELEASE-APPROVAL-DIRECT-1), kept |
| Branches | 6 sprint branches deleted locally and on `origin` (#636–#641, each merged at its tested head); 0 left |
| Temporary database schemas | 0 (none besides `public` in the local test database) |
| Background processes | 0 (no CI watcher, test run, graph build or SSH session of this sprint) |
| Workstation backups | the sprint's two (`~/.ssh` config before the operator alias; known-hosts before the approved key removal) removed after both target checks `VERIFIED`; two older ones from earlier sprints untouched |
| Host | exports 0; rehearsal workspaces 0; build processes 0; transient units 0; deploy, release and rollback locks IDLE; `/root/sudoers-retired/` kept (recovery); the build identity's cache kept (in use) |
| Deploy snapshot | **0 left**: the owner, with the administrator's password (the directory is root-only since the cutover), read `/run/daengtisiams-deploy` (no `deploy-…` snapshot directory) and the deploy log's tail (`DEPLOY_SNAPSHOT_CLEANED=YES`) |
