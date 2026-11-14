# DMS-BK2-BACKUP-RELIABILITY-WSL2-RECOVERY-1 — BK-2 backup reliability and WSL2 recovery

| Field | Value |
|---|---|
| Date | 2026-11-14 (WITA) |
| Status | **WATCH**: implemented, tested and running live; the owner's restart drill (§7) and the merge are open |
| Category | Foundation-First allowed category 5, *backup / restore / monitoring / CI-CD gates* |
| Base | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` at `32c4a760` |
| Branch / PR | `ops/dms-bk2-backup-reliability-wsl2-recovery-1` / #625 |
| Durable rule | `.cursor/rules/230-bk2-offserver-workstation-recovery.mdc`, index line `AIR-DEP-36` |
| Runbook | `docs/runbooks/bk2-offserver-workstation-recovery.md` |
| Production | untouched: no deploy, no server-side BK-2 change, read-only status checks only |
| GO tag | none: nothing is deployed, so there is no `go_tag` |

## 1. Why

The BK-2 off-server copy is pulled by `dms-bk2-pull.timer` on the owner's Windows 11 + WSL2 workstation onto an
encrypted backup HDD. `wsl --mount` does not survive a Windows or WSL restart, so the HDD is detached after every
restart until the custodian re-attaches and unlocks it. On 2026-11-13/14 that went unnoticed until the 8D deploy
preflight stopped on `OFF_SERVER=PENDING` (`docs/sprints/dms-rme-revision-program-8d.md`). The same morning the
workstation restarted at 06:21 WITA. Every pull from 06:28 to 08:00 logged the HDD as an identity failure, and no
alert fired until the owner re-attached it at about 08:05.

## 2. Read-only audit (findings)

| # | Finding | Evidence |
|---|---|---|
| A1 | The server half is sound: `OFF_SERVER` derives only from acknowledgements, and the workstation acks only after manifest SHA-256, an O_DIRECT re-read, decrypt-verify against the server's plaintext hashes and the documents check. Thresholds: `DELAY_H=26`, `STALE_H=72`. | host `dms-bk2-status` (read-only, target verified): 13/13 acked, `ON_SERVER=OK`, `OFF_SERVER=OK` |
| A2 | A disk that was not attached was reported as `IDENTITY_MISMATCH`, the word for a foreign device. | journal 06:28–08:00: `HDD identity FAIL … is not attached` → `hdd=IDENTITY_MISMATCH` |
| A3 | No workstation alert existed. The system unit sets desktop notifications off, no notifier is installed, and a locked HDD never notified at all. | unit `Environment=BK2_NOTIFY=0`; journal shows `alerts=none` throughout |
| A4 | The pull exited 0 with the HDD unavailable, so systemd recorded success while nothing was copied. | script header: "0 = run completed (even if the HDD was unavailable)" |
| A5 | The local status copied `OFF_SERVER` from the server, so it read `OK` with the HDD detached until the next package appeared. | `status.env` fields |
| A6 | Nothing checked PID 1's mount namespace. `/proc/1/mountinfo` is readable unprivileged; the custodian's shell shared PID 1's namespace this time. | same mount id in `/proc/self` and `/proc/1` |
| A7 | No custodian helper existed; attach, unlock and mount were done from memory. Windows disk numbers can change, so only the serial identifies the disk. | `Win32_DiskDrive` (read-only) |
| A8 | No leftover processes, locks or `.incoming-*` packages. All 13 on-HDD packages were structurally consistent with their manifests. | `flock -n`, process list, per-package stat |
| A9 | The repository is **public**. The BK-2 tooling and the HDD identifiers live in the owner's private tree, and must stay there. | `gh repo view` |

## 3. What changed

### 3.1 Private BK-2 tree on the workstation (not in this repository)

The owner's private tree was snapshotted first (`releases/20261114T0146Z-pre-reliability/`, with `SHA256SUMS` and the
installed unit text). The changes were staged in a separate directory, tested, and installed between two timer runs
with one atomic rename per file. Version `2026-11-14.2`.

| File | Change |
|---|---|
| `etc/bk2.conf` (new) | identity values and SLA thresholds in one owner-private file; no secret |
| `bin/hdd-identity-check.sh` | explicit states (rule 230 §3); judges the mount from PID 1's table; detects wrong mapping names, extra holders, stacked or duplicate mounts, read-only mounts or superblocks and subdirectory binds; fails closed when PID 1's table is unreadable |
| `bin/bk2-pull.sh` | consumes the state token, cross-checked against the exit code; re-confirms delivered copies (structural every run, O_DIRECT daily and before any re-ack); derives a local `OFF_SERVER`; `result=OK/DEGRADED/FAIL` with exit 0/75/1; `level` and `local_alerts`; `BK2_ALERT` journal lines at crit or warning priority. The sync, verify, decrypt and ack core is unchanged. |
| `bin/bk2-status.sh` (new) | read-only verdict: live identity, run age, timer and unit state, the run's own verdict, leftovers; `--brief` banner mode |
| `bin/bk2-custodian.sh` (new) | `plan`, `windows-command` (read-only) and `attach` (interactive): UAC attach by serial, then unlock at cryptsetup's own prompt, then mount in PID 1's namespace, then one pull, then status |
| `tests/run-tests.sh` (new) | fixture harness, described in §5 |

Unchanged: `bk2-key-home.sh`, `direct-read-verify.py`, `verify_tar.py` (byte-identical); the systemd unit and timer; the
server; the keys.

### 3.2 This repository

- `.cursor/rules/230-bk2-offserver-workstation-recovery.mdc`: the contract, without identifiers.
- `docs/runbooks/bk2-offserver-workstation-recovery.md`: operator procedure, restart drill and rollback.
- `docs/runbooks/production-host-identity.md` §6b step 3: one added sentence. The gate itself is unchanged. The
  workstation verdict is read early, and a fail state is a HOLD.
- `.claude/rules/deploy-infra.md`: `AIR-DEP-36` and a canonical-rules row.
- `docs/00-INDEX.md`: the runbook is listed under Backup & DR.
- `tests/Feature/Deploy/DeployHardenBk2OffServerRecoveryTest.php`, declared in `critical_gate_mandatory_suites` and
  selected by the existing `DeployHarden` token in both critical variants. No workflow change was needed.

## 4. Decisions

- **Identifiers stay private (A9).** The repository carries only the shapes. A guard test fails if a disk WWN, a
  by-uuid path, a dm-crypt uuid or a drive-serial shape appears in `docs/`, rules, `deploy/`, `scripts/`, `config/` or
  `.github/`. The pull account and the escrow file names are kept out by review, because a guard would have to
  publish them in order to detect them.
- **A degraded run is a failed unit.** Exit 75 makes `systemctl` show the unit failed whenever the copy could not be
  confirmed. The verdict itself lives in `status.env` and `bk2-status.sh`.
- **No unit change and no sudo.** Everything new runs from the existing `ExecStart` path, so no system unit was
  edited. Privileged steps exist only inside the custodian's interactive `attach`.
- **Alerts go to the journal and the status file, not a desktop popup.** A system service has no desktop session. The
  optional interactive-shell banner (`bk2-status.sh --brief` in the owner's shell profile) is left to the owner: an
  automated session must not edit a shell startup file.
- **The server is unchanged.** Pushing server alerts off the host would change production backup tooling, which the
  brief does not authorise. Recorded as a follow-up.

## 5. Tests

| Suite | Result |
|---|---|
| Private harness `tests/run-tests.sh`, staged tree | 90 passed, 0 failed |
| Same harness on the installed tree (installed files byte-identical to the staged ones) | 90 passed, 0 failed |
| Mutation check: identity judged from the caller's namespace; local `OFF_SERVER` copied from the server; exit 0 when degraded; TTY guard removed; *absent* reported as *mismatch* (the old bug); stray-agent check by command-line text | 6 of 6 mutations caught (1–8 checks fail each) |
| Live read-only checks on the real HDD | identity `HDD_STATE=OK` in 55 ms; `plan` → OK; `windows-command` resolves the disk by serial; `attach` without a TTY refuses and runs nothing |

The harness covers 31 identity-chain states and faults on fixture `/dev`, `/sys` and `/proc` trees. For the pull it
covers absent, locked, invisible-to-PID-1 and mismatched disks, exit-code/state disagreement, server unreachable
(warn, then crit), pending past 26 h and 72 h, and an end-to-end sync of GPG-encrypted fixture packages through a
throwaway escrow chain (fetch, O_DIRECT, 8/8 decrypt-verify, documents, ack). It also covers re-confirmation, a
truncated copy, a same-size corruption caught only by the daily O_DIRECT pass, ack refusal followed by re-ack, server
WARN and FAIL, and lock contention. For the leftovers it checks for temp directories, gpg-agents, `.incoming-*`
packages and lock holders. The status checks are banner, mismatch, timer off, stale run, FAIL, abandoned incoming,
stray temp and a run in progress. The custodian checks are no TTY, no-op when attached, the exact privileged command
order, no key file or passphrase, a wrong passphrase, a step that makes no progress, every fail state, the PID 1
namespace guidance, the UAC path, a decline, two matching disks, `plan`, and static checks for destructive commands.

Repository: the new suite, `DevflowAiInstructionSystemTest` and `ProductionShellCommandGuardTest` pass locally. The
dotenv warnings come from the missing environment file in the worktree. The Full Suite is deferred under CG-18, not
passed.

## 6. Live verification (2026-11-14, WITA)

- **Install.** At 10:01 the pull was idle and the lock free, with the next run 5 minutes away. The new files were
  installed with one atomic rename each, the pull script last, so a run in between would always get a compatible pair.
- **First timer run on the new version** (10:07:06–10:07:15, `dms-bk2-pull.service`, unchanged unit):
  `run start version=2026-11-14.2 server_reachable=yes hdd=OK`; `on-HDD confirmation: 13/13 listed packages verified
  (… O_DIRECT re-hash: 13 package(s), full daily pass)`; `run end result=OK level=none ON_SERVER=OK OFF_SERVER=OK
  server_OFF_SERVER=OK verified_on_hdd=13/13 pending=0 synced=0 failed=0 hdd=OK alerts=none local_alerts=none`; the
  unit `Result=success`, `ExecMainStatus=0`. The full O_DIRECT pass took about 7 seconds.
- **A defect found live and fixed.** The first live `bk2-status.sh` reported a stray BK-2 gpg-agent. That was a false
  positive: `pgrep -f` matched the calling shell, whose command line mentioned the pattern. The check now considers only
  processes named `gpg-agent` with a BK-2 homedir. Two regression tests were added (a caller mentioning the words; a
  real `gpg-agent`-named process with a BK-2 homedir), and the original behaviour fails them. After reinstalling,
  `bk2-status.sh --brief` is silent with exit 0.
- **Leftovers.** No BK-2 gpg-agent, no `/tmp/dms-bk2-*`, `/tmp/bk2-pull.*` or test temp directories, no `.incoming-*`
  package, and exactly one mount of the backup filesystem in PID 1's table. The staging copy was removed after the
  install. The private tree keeps only `bin/`, `etc/`, `tests/`, `releases/` (the pre-change snapshot and the install
  checksums) and a private README.
- **Not exercised live.** The `ABSENT` → `attach` → `OK` path after a real restart (open item 1), and a live alert. The
  HDD stayed attached throughout, and the tooling was never pointed at an artificially unavailable disk.

## 7. Open items

1. **Restart drill (owner).** Runbook §8: restart WSL or Windows, see `ABSENT` and a `DEGRADED` run with `HDD_ABSENT`,
   run `bk2-custodian.sh attach`, then see `OK`. It needs the custodian's passphrase and ends every WSL session, so an
   AI session cannot run it. Until it is done, recovery after a restart is proven only by the fixture tests and by the
   manual recovery of 2026-11-14 08:05.
2. **Interactive banner (owner, optional).** Add the `bk2-status.sh --brief` line to the shell profile (runbook §2).
3. **Merge (owner).** The base has no branch protection; merge authority is the owner's.
4. **Follow-up, not in scope.** Push the server's `DELAYED`/`STALE` alerts somewhere the owner sees them even when the
   workstation is off. That is a production backup change and needs its own authorisation.
