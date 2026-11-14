# BK-2 off-server copy: workstation status and recovery after a restart

Rule: `.cursor/rules/230-bk2-offserver-workstation-recovery.mdc`. Sprint record:
`docs/sprints/dms-bk2-backup-reliability-wsl2-recovery-1.md`. Readers: the owner (who is also the HDD custodian) and
any operator or AI session preparing deploy work on the WSL2 workstation.

This repository is public. The HDD identifiers, the pull account and the key-escrow paths are in the owner's private
BK-2 tree and private runbook, not here. Commands below are in that tree's `bin/` directory: the directory named by
`ExecStart` in `systemctl cat dms-bk2-pull.service`.

## 1. What runs, and what "OK" means

- The server builds one encrypted package a day (18:30 UTC, 02:30 WITA) and keeps it until the workstation acknowledges it.
- `dms-bk2-pull.timer` on the workstation runs the pull every 30 minutes and 5 minutes after boot. When the HDD is
  ready, it copies each package to the encrypted HDD and verifies it: manifest SHA-256, an O_DIRECT re-read and a
  decrypt check. Only then does it acknowledge the package to the server.
- The pull never unlocks or mounts the HDD. After a Windows or WSL restart the HDD is detached until the custodian
  re-attaches it (section 3).
- A run says `result=OK`, exits 0 and sets the local `OFF_SERVER=OK` only when that run confirmed every listed package
  on the HDD. It says `DEGRADED` (exit 75, unit shown failed) when it could not confirm anything, and `FAIL` (exit 1)
  when something is wrong. systemd's "success" is not the verdict: read the status.

## 2. Check the status (read-only, no sudo)

```bash
bk2-status.sh            # full report: verdict, live HDD state, last run, OFF_SERVER (local and server), alerts, next step
bk2-status.sh --brief    # silent when OK; one banner otherwise (exit 0 OK, 1 WARN, 2 CRIT)
journalctl -u dms-bk2-pull.service | grep 'run end' | tail -1
journalctl -p warning -u dms-bk2-pull.service --since today | grep BK2_ALERT
```

| Live HDD state | Meaning | Action |
|---|---|---|
| `OK` | disk, LUKS, mapping, ext4 and the mount as PID 1 sees it are all proven | none |
| `ABSENT` | not attached to WSL (normal after a restart) | custodian: section 3 |
| `LOCKED` | proven, but the LUKS container is closed | custodian: section 3 |
| `NOT_MOUNTED` | unlocked and proven, not mounted | custodian: section 3 |
| `NOT_IN_PID1_NS` | mounted only in one shell's namespace; the timer cannot see it | custodian: section 4 |
| `MISMATCH`, `WRONG_MAPPING`, `DUPLICATE_MOUNT`, `READ_ONLY`, `UNVERIFIABLE` | identity in doubt: nothing is written | **stop**: section 5 |

## 3. Recover after a Windows or WSL restart (custodian only)

Run this in your own WSL terminal. It needs your sudo password and the LUKS passphrase. Both are typed only into the
prompts of `sudo` and `cryptsetup`, never into a chat, a file or a script.

```bash
bk2-custodian.sh plan        # read-only: the live state and the exact commands attach would run
bk2-custodian.sh attach      # interactive recovery
```

`attach` does only the missing steps and proves the identity again after each one:

1. **Windows.** If the disk is absent, it finds the disk by serial and model (it must be exactly one) and asks you to
   approve a UAC prompt. The elevated PowerShell looks the disk up again and runs `wsl --mount <disk> --bare`: a bare
   attach, with nothing mounted or formatted.
2. **Unlock.** `sudo cryptsetup open --type luks2 --tries 3 <LUKS partition> <canonical mapping name>`. Type the
   passphrase at cryptsetup's prompt. A wrong passphrase leaves the HDD locked and stops the procedure.
3. **Mount in PID 1's namespace.** `sudo nsenter --target 1 --mount -- mount -t ext4 -o rw,nosuid,nodev,noexec …`,
   the namespace the timer is cloned from.
4. **Pull now.** `sudo systemctl start dms-bk2-pull.service` (skip with `--no-pull`), then the status report.

Done means `bk2-status.sh` reports verdict `OK` and the last `run end` line shows `result=OK` and `OFF_SERVER=OK` with
`verified_on_hdd=N/N`. The read-only `bk2-custodian.sh windows-command` prints the Windows step on its own.

## 4. `NOT_IN_PID1_NS`

The HDD was mounted in a shell's private namespace (for example from a session started outside systemd). Release it
there (`sudo umount <mount point>` in that shell; this only detaches the mount and deletes nothing), then run
`bk2-custodian.sh attach` again.

## 5. A fail state

`MISMATCH`, `WRONG_MAPPING`, `DUPLICATE_MOUNT`, `READ_ONLY` and `UNVERIFIABLE` mean the tooling cannot prove that the
mount point is the backup HDD, or that it is healthy. Nothing is written while they last. Do not unlock, mount, repair
or reformat anything to make the check pass. Read the `REASON:` line (`bk2-status.sh`) and decide with the custodian.
`READ_ONLY` can mean a filesystem error: get the owner's decision before any repair. Packages keep waiting safely on
the server, and their age raises the alert level (section 6).

## 6. Alerts and SLA

| Alert (`local_alerts`) | Level |
|---|---|
| HDD unavailable | warn; crit 45 minutes after the last run that saw it OK |
| HDD identity fail state | crit |
| oldest package not on the HDD > 26 h (server `DELAYED`) / > 72 h (server `STALE`) | crit |
| server unreachable | warn; crit from the 3rd consecutive run |
| verification failure, invalid HDD copy, destination not writable, server `FAIL` | crit |
| server `WARN` / server alerts | warn |
| last run older than 75 min / 225 min; timer disabled (from `bk2-status.sh`) | warn / crit / crit |

Copies already on the HDD are re-checked on every run (record, manifest hash, file sizes) and re-hashed with O_DIRECT
at least once every 24 hours.

## 7. Before deploy work

Section 6b step 3 of `docs/runbooks/production-host-identity.md` stays the gate: the host `dms-bk2-status` must show
`ON_SERVER=OK` and `OFF_SERVER=OK`. Read `bk2-status.sh` at the start of deploy work as well. If the HDD is
unavailable, tell the owner then, not at the preflight, because the deploy's own package will wait on the server. A
fail state or a `FAIL` result is a HOLD until the owner decides.

## 8. Restart drill (owner)

After any change to the BK-2 tooling, prove both legs live (rule 230 §11): the restart is **reported** (the first
timer run after boot fails with an alert) and then **recovered** (attach, then a run that confirms every package). The
fixture tests do not replace either leg. The drill ends every WSL session, including AI sessions, so only the custodian
runs it. Do not run it while a deploy is in progress or about to start: the deploy preflight needs `OFF_SERVER=OK`.

1. **Before.** `bk2-status.sh` reports `OK`, and `systemctl is-active dms-bk2-pull.service` reports `inactive`.
2. **Restart.** In Windows PowerShell run `wsl --shutdown`, then open WSL again (or restart Windows). `uptime -s` gives
   the boot time.
3. **Reported: do not attach yet.** `bk2-status.sh` reports the live HDD `ABSENT`, verdict `WARN`. Wait for the first
   timer run after boot, 5 to 6 minutes after boot. This read-only loop returns when that run has ended; the other three
   commands print the evidence:

   ```bash
   until journalctl -b -u dms-bk2-pull.service --no-pager | grep -q 'run end'; do sleep 20; done
   journalctl -b -u dms-bk2-pull.service --no-pager | grep 'run end'
   journalctl -b -p warning -u dms-bk2-pull.service --no-pager | grep BK2_ALERT
   systemctl show dms-bk2-pull.service -p Result -p ExecMainStatus
   ```

   This leg passes when the run logs `result=DEGRADED`, `hdd=ABSENT`, `OFF_SERVER=UNVERIFIED` and
   `local_alerts=HDD_ABSENT` (`level=warn`; `HDD_ABSENT_<N>m` at `level=crit` once 45 minutes have passed since a run
   last saw the HDD `OK`), a `BK2_ALERT` line appears, and the unit shows `Result=exit-code` and `ExecMainStatus=75`.
   Anything else is a drill failure. Record it and decide with the owner before attaching; a fail state follows
   section 5. Attaching before this run ends hides the leg, and the drill does not count.
4. **Recovered.** `bk2-custodian.sh attach` (section 3). This leg passes when `bk2-status.sh` reports verdict `OK`, the
   last `run end` line shows `result=OK`, `OFF_SERVER=OK` and `verified_on_hdd=N/N`, `systemctl show` shows
   `Result=success` and `ExecMainStatus=0`, and the host `dms-bk2-status` reports `ON_SERVER=OK` and `OFF_SERVER=OK`.
5. **Record** for the sprint record: the boot time, both `run end` lines, the `BK2_ALERT` line, both unit results and
   the final status. Copy only these. The journal's `sudo` line for the unlock carries the partition's device path,
   which stays private.

Last full pass: drill 2 on 2026-11-14, both legs (sprint record §9).

## 9. Never

- Never type, paste or store the LUKS passphrase or a recovery key anywhere but cryptsetup's own prompt.
- Never let an automated session run `attach`; it refuses without a terminal.
- Never format, initialise, repair or wipe the backup HDD, and never delete a verified package.
- Never attach a disk chosen by its Windows disk number alone; the serial decides.
- Never hand-edit the pull's `status.env`, and never treat a run's exit code alone as success.
- Never copy a `sudo` journal line of the unlock into a record or a doc: it carries the partition's device path.

## 10. Tooling tests and rollback

`tests/run-tests.sh` in the private tree runs the identity chain, pull, status and custodian checks. It uses fixtures and
stubs only: no sudo, no network, and it never touches the real HDD or state. Every change to the private tree is
preceded by a snapshot in its `releases/` directory. To roll back, restore the scripts from the latest snapshot with
the pull idle (`systemctl is-active dms-bk2-pull.service` reports `inactive`), one atomic rename per file.
