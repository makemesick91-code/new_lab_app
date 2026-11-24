---
kb_status: unverified
kb_verified_against: 039a7f59
kb_updated: 2026-11-25
---

# R6-B6 isolated restore drill: structural redesign (finding A-1)

**Status: DESIGN PROPOSAL, not implemented.**
- Finding A-1 (HIGH) stays **OPEN**. This document does not close it. Closure needs the implementation, system-mode
  tests and an independent review of the implemented isolation controls.
- R6-B6-F1 (the rehearsal swallows a failed drop) stays **OPEN** as a separate issue.
- No production change, no push, no restore or drill was run on any shared or production system.

Source directive: "G6 — R6-B6 STRUCTURAL SECURITY REDESIGN" (owner, 2026-11-25). The PR #695 head and every local
remediation commit up to `039a7f59` are kept unchanged.

The design went through **one independent adversarial review round** (DR-1 to DR-19). Its seven proven findings were
reproduced, fixed in the prototype profile and re-tested (§11).

## 1. Problem and objective

Since D-Q7, the restore drill replays a plain-SQL backup as the PostgreSQL superuser on the production cluster. Before
R6-D, the runtime role owns the whole application schema, so it can plant schema code that pg_dump faithfully writes
into the backup: CHECK and domain expressions, defaults, generated columns, partition expressions, casts, operators,
aggregates. A backup that matches its trusted digest is therefore still attacker-influenced content. Replaying it as
the superuser runs that code as the superuser, and from there as the cluster's OS account, with every production
database in reach.

PR #695 answers this with a deny-list:
- psql restricted mode;
- a load guard that inspects the catalog before each data block;
- refusal of split CHECK entries.

Three independent review rounds each found a new superuser-execution path, so the deny-list cannot be shown complete.

**Objective (owner):** an untrusted backup is never replayed with superuser privileges on the production PostgreSQL
cluster.

**Design principle:** stop asking "which schema code is dangerous?" (a deny-list). Instead, make every piece of code in
the backup run where it cannot matter:
- in a disposable cluster;
- as an unprivileged, ephemeral OS identity;
- with no network and no view of production files or sockets;
- with no writable place that can hold executable code;
- with hard resource limits and a lifetime bounded by the kernel.

Then decide GO only from facts the host can check itself.

## 2. Threat model

| Item | Content |
|---|---|
| Adversary | Whoever controls the runtime database role or the application before R6-D. They can shape any schema object and any row the backup contains. They can also write the application's own files, including the app copy of the drill evidence (DR-8). |
| Not in scope | Root on the host. The operator passing a wrong trusted digest (a residual carried over from #695). Physical access. |
| Assumed capability inside the sandbox | Arbitrary native code as the sandbox identity. This is the worst case: a disposable superuser reaches it through an untrusted procedural language if one is installed, and a psql parser flaw could too. Every control below is a kernel or service-manager control, never a PostgreSQL privilege check alone. |
| Assets | The production cluster (data, roles, its OS account, data directory, socket, TCP port). The application (checkout, environment file, clinical evidence storage). Backups and deploy evidence. Host services (Redis, PHP-FPM, nginx, D-Bus, systemd and its varlink sockets). The cloud metadata service and hypervisor channels (vsock). Host availability (RAM, CPU, disk). The operator's terminal and the drill evidence. Patient data privacy (CG-11). |
| Security goal | Nothing the backup contains can read, write, connect to or signal a production asset, keep running after the drill, exhaust the host, or turn a broken restore into a GO. |
| Non-goal | Certifying that a backup is free of malicious content. The drill proves restorability of honest backups and containment of malicious ones. |

## 3. Architecture and trust boundaries

```
 TRUSTED: root on the production host                    ║  UNTRUSTED: transient unit (per-run name)
                                                         ║  (assume arbitrary code from the first byte replayed)
 operator ── sudo ──> scripts/rollout-restore-drill.sh   ║
   TB-2     (unchanged entry point, root)                ║   identity  DynamicUser (ephemeral uid, no groups),
   │                                                     ║             no capabilities, no_new_privs
   ├─ TB-1  trusted digest (root-only deploy log or      ║   files     read-only system; minimal /etc (5 files);
   │        an independent --expected-sha256)            ║             hidden /home /root /run /var /opt /mnt
   ├─ TB-1  source checks; root-only staged copy; sha256 ║             /media, host cgroups, /dev/pts;
   ├─ dump trailer check; expected table and row counts  ║             tmpfs /srv (size cap): PGDATA, socket, logs;
   │        taken from the dump text                     ║             private noexec /dev/shm; /tmp and /var/tmp
   ├─ TB-3  read-only preflight on production: role      ║             read-only (PrivateTmp is host disk)
   │        names (constant SQL, no backup content)      ║   network   own namespace (loopback only), AF_UNIX
   ├─ capacity check; lock; refuse while a unit exists   ║             only, IPAddressDeny=any
   │                                                     ║   exec      NoExecPaths=/ + ExecPaths allowlist;
   │  systemd-run (system manager) ═══ TB-4 ════════════>║             MemoryDenyWriteExecute; seccomp, also
   │    stdin:  restricted stream of the staged copy ───>║             without memfd, io_uring, keyring
   │    stdout: result channel <── ≤8 KiB, ≤64 lines ────║   limits    MemoryMax, MemorySwapMax=0, TasksMax,
   │    stderr: bounded sink                             ║             CPUQuota, OOMPolicy=stop, RuntimeMaxSec
   ├─ TB-10 strict parse; GO only from host-checkable    ║   self-check  attests the effective profile before
   │        facts (exits, bytes, completeness)           ║             it reads any input
   ├─ evidence: root-attested copy + app copy            ║   disposable PG16 (same pinned binaries)
   └─ cleanup: stop unit; staging copy already unlinked  ║   TB-5  drill_super: trusted setup, then NOLOGIN
                                                         ║         drill_restore NOSUPERUSER, member of the
                                                         ║         mirrored roles: replay + verification
 PROTECTED: never visible or reachable from the unit     ║
   production cluster (socket dir under /run, TCP port, data dir, OS account), app checkout, environment file,
   storage, backups, deploy logs, Redis/PHP-FPM/nginx sockets, D-Bus, systemd sockets, metadata service, vsock
```

| Boundary | What crosses it | What is trusted | Control |
|---|---|---|---|
| TB-1 Backup origin | the backup file | only the digest from the root-only deploy log, or an independent record | #695 chain, unchanged: digest validation, source checks, one root-only staged copy and its sha256. New: pg_dump trailer and `\unrestrict` key check (DR-7). |
| TB-2 Operator | the command line | root, and the digest the administrator passes | argument validation; no default backup |
| TB-3 Production cluster | constant read-only catalog SQL from the wrapper | the wrapper's own SQL | role names compared in bash, never interpolated; nothing is created or replayed on production |
| TB-4 OS identity | the unit start | systemd and the property set | DynamicUser, NoNewPrivileges, empty capability set, seccomp, RestrictNamespaces. The unit's own self-check attests the effective profile (DR-3). |
| TB-5 Database role, in the disposable cluster | the replay | nothing after the replay starts | replay and verification run as `drill_restore` (NOSUPERUSER). `drill_super` becomes NOLOGIN after setup (DR-5). Psql restricted mode is **load-bearing**: it stops `\c`, `\!` and `\o` before PostgreSQL sees them. |
| TB-6 Cluster | none | n/a | separate initdb, private socket in the 0700 PGDATA, `listen_addresses=''` |
| TB-7 Filesystem | the dump bytes (stdin only); the read-only inner script | a read-only system tree | allowlist mount view. Every writable directory is a size-capped noexec tmpfs, or the private mqueue. The self-check refuses anything else (DR-1). |
| TB-8 Network | none | n/a | the private network namespace is the network layer that holds alone. The family filter covers socket(2) only; io_uring is closed by seccomp (DR-2). IPAddressDeny is unproven (DR-3). |
| TB-9 External commands | exec | the pinned PG16 binaries, bash and dash | NoExecPaths=/ + ExecPaths; W^X (MemoryDenyWriteExecute, noexec writable mounts, no memfd_create: DR-4) |
| TB-10 Result channel and evidence | the unit's stdout | nothing it says | bounded strict parse; no cluster process inherits it (DR-10); GO only from host-checkable facts; error text never leaves (SQLSTATE only); the evidence is root-attested (DR-8) |
| TB-11 Kernel | everything | the kernel and systemd | shared kernel: residual R-1 |

## 4. Drill flow (target)

1. **Unchanged from #695:**
   - entry and arguments;
   - library and environment-file ownership checks;
   - the pinned client in `DMS_PG_BINDIR`. The check extends to `postgres`, `initdb` and `pg_isready`, and
     `postgres --version` must also report 16.15 or later.
2. **Integrity:**
   - unchanged from #695: trusted digest, source checks, root-only staged copy, sha256 of the copy. Phase 1 keeps the
     split-CHECK refusal;
   - new: the copy must end with pg_dump's trailer and a `\unrestrict` matching its `\restrict` key;
   - new: the host counts the `CREATE TABLE public.` statements and the COPY rows in the copy;
   - new: the copy is opened, then unlinked, and is hashed and streamed from the open descriptor, so no plaintext copy
     outlives the wrapper (DR-13).
3. **Read-only preflight on production.** Constant SQL as today; no database is created there.
   - D-Q6-a: every owner role named in the dump exists on production.
   - P5: the runtime ownership gate.
   - Non-system role names are read for mirroring (OD-ISO-2).
4. **Capacity and exclusivity:**
   - take a root-only lock; also refuse while any drill unit is loaded, since an orphaned unit outlives the lock (DR-13);
   - the per-run `User=` name has 31 characters or fewer and must not resolve through `getent` (DR-11);
   - tmpfs size = max(1 GiB, 6 × staged size), at most `DMS_RESTORE_DRILL_MAX_TMPFS_MB`;
   - MemoryMax = tmpfs + 512 MiB;
   - refuse (fail closed) when MemAvailable minus MemoryMax falls below a reserve.
5. **Launch the unit** with the property set in §5. `--wait --pipe --collect`; stderr goes to a bounded sink (DR-14).
   Environment: role names, locale, `TMPDIR=/srv`. Stdin: the restricted stream. The wrapper counts the bytes it
   streams.
6. **Inside the unit**, using bash builtins and pinned binaries only:
   - **profile self-check**, before any input, attesting the effective profile:
     - every writable directory is a noexec, size-capped tmpfs or the private mqueue;
     - Seccomp 2, NoNewPrivs 1, CapEff 0;
     - only `lo` in `/sys/class/net`;
     - in system mode, the uid is in the DynamicUser range (DR-3, DR-11);
   - initdb, then the server (§5.6);
   - mirror roles NOLOGIN; create `drill_restore`; create the database;
   - `ALTER ROLE drill_super NOLOGIN` (DR-5);
   - replay as `drill_restore` with `ON_ERROR_STOP` and `VERBOSITY=sqlstate`; fd 3 is closed for the server, the
     replay and verification (DR-10);
   - verify as `drill_restore` with `search_path = pg_catalog` and qualified names: tables, fidelity counts, base
     tables and total rows;
   - emit `DRILL key=value` lines;
   - stop the server immediately (SIGQUIT, F-ISO-4).
7. **Wrapper decision.** GO only when every condition holds; no condition rests on the sandbox's word alone:
   - unit exit 0 and feeder exit 0;
   - streamed bytes = the size of the restricted stream;
   - `result=GO`, no duplicate key, no rejected line;
   - **completeness**: the restored base tables and rows equal the counts the host took from the dump (DR-7);
   - `migrations_table` = 1.
8. **Evidence:**
   - schema v1, `production_overwrite: false`;
   - target `disposable_cluster_restore_drill_<stamp>` (a safe marker);
   - notes: the effective isolation profile (`systemctl show` of the security properties, DR-3), replay identity,
     fidelity and completeness counts;
   - root writes an **attested copy** with its sha256 into the trusted deploy-evidence directory. Readiness trusts that
     copy, not the runtime-writable app copy (DR-8).

   The app boot and health-route checks run outside the unit, as before.
9. **Cleanup:**
   - traps on EXIT, TERM and HUP stop the unit; the tmpfs dies with the mount namespace, DynamicUser and IPC are
     released;
   - cleanup never relies on SIGINT (F-ISO-3);
   - after a SIGKILL of the wrapper, the unit ends by itself at stdin EOF or RuntimeMaxSec, and the next run refuses to
     start until it is gone.

## 5. Isolation requirements

### 5.1 OS identity
- `DynamicUser=yes` with a per-run `User=` name of at most 31 characters (for example `dmsdrill-<12 hex>`) that does not
  resolve before launch. Otherwise systemd silently reuses an existing static user of that name (DR-11).
- After start, the uid must lie in 61184–65519.
- `NoNewPrivileges=yes`, `CapabilityBoundingSet=` (empty), `AmbientCapabilities=` (empty), `RestrictSUIDSGID=yes`,
  `KeyringMode=private`, `UMask=0077`, `LimitCORE=0`, `CoredumpFilter=0`, `LimitNOFILE=1024`.
- The identity owns nothing outside the unit, is not a role on the production cluster, and holds no credential.
- **Name resolution (DR-6, now certain):** PG16 `initdb` aborts when its effective uid has no passwd entry. The choice is
  part of OD-ISO-1:
  - **Option A:** bind only the socket `/run/systemd/userdb/io.systemd.DynamicUser` read-only. This is a small parser
    surface in PID 1; verify it in phase 3.
  - **Option B:** a static nologin system user. Then `/etc/passwd` is enough, but the uid is shared between runs, so
    the "refuse while any drill unit exists" rule becomes mandatory.

### 5.2 Filesystem (allowlist view)
- `ProtectSystem=strict`, `ProtectHome=yes`, `PrivateTmp=yes` (implied by DynamicUser, and it cannot be turned off),
  `PrivateDevices=yes`, `PrivateIPC=yes`.
- `TemporaryFileSystem=` with `:ro` for `/run`, `/var`, `/opt`, `/mnt`, `/media`.
- **`/etc` allowlist (DR-16):** `TemporaryFileSystem=/etc:ro` plus `BindReadOnlyPaths=` of `/etc/passwd`, `/etc/group`,
  `/etc/nsswitch.conf`, `/etc/ld.so.cache` and `/etc/localtime`. That is enough for initdb, the server and psql. Unit
  files, drop-ins and service configs are no longer visible.
- `InaccessiblePaths=/sys/fs/cgroup /dev/pts` (DR-12). The host cgroup tree exposes the PIDs and resource statistics of
  production services; `PrivateDevices` otherwise binds the host's devpts.
- `TemporaryFileSystem=/srv:size=<cap>,mode=1777,noexec,nosuid,nodev` for PGDATA, the socket, logs and `TMPDIR`.
- **F-ISO-1:** `PrivateDevices=yes` binds the host `/dev/shm`. Fix: `TemporaryFileSystem=/dev/shm:size=64M,mode=1777,noexec,nosuid,nodev`.
- **F-ISO-2:** `NoExecPaths=/` does not cover the mounts systemd adds itself. Fix: explicit `noexec`, and
  `NoExecPaths=/ /tmp /var/tmp`.
- **DR-1:** the `PrivateTmp=` directories are on the host root filesystem with no cap, and a dump could fill them
  through psql `\o`, or through native code. A `TemporaryFileSystem=/tmp` is silently overridden by PrivateTmp. Fix:
  `ReadOnlyPaths=/tmp /var/tmp` and `TMPDIR=/srv`.
- The inner script is bind-mounted read-only, from a root-owned file that is not writable by others.

### 5.3 Network
- `PrivateNetwork=yes`: loopback only, and a separate abstract Unix socket namespace. This layer was proven to hold on
  its own, including against an io_uring-created AF_INET socket.
- `RestrictAddressFamilies=AF_UNIX`. It filters socket(2) only, and holds alone for that path (L3). **DR-2:**
  `IORING_OP_SOCKET` bypasses it; io_uring is therefore removed by seccomp (L3b).
- AF_VSOCK is not covered by PrivateNetwork; both socket paths to it are now closed. Phase 5 checks `/dev/vsock` on
  the VPS.
- `IPAddressDeny=any`: **DR-3**, it is a silent no-op in the user-mode prototype and was never tested alone. It counts
  as a layer only after phase 3 proves it in system mode.
- PostgreSQL inside: `listen_addresses=''`, socket in the 0700 PGDATA.

### 5.4 Kernel, IPC and devices
- `ProtectKernelTunables`, `ProtectKernelModules`, `ProtectKernelLogs`, `ProtectControlGroups`, `ProtectClock`,
  `ProtectHostname`.
- `ProtectProc=invisible`, `ProcSubset=pid`, `RestrictNamespaces=yes`, `LockPersonality=yes`, `RestrictRealtime=yes`,
  `MemoryDenyWriteExecute=yes`.
- `SystemCallArchitectures=native`, `SystemCallFilter=@system-service`, and
  `SystemCallFilter=~@privileged @resources @mount @swap @reboot @raw-io @module @debug @obsolete @cpu-emulation @clock`.
- **DR-2, DR-4, DR-12:** also `~memfd_create io_uring_setup io_uring_enter io_uring_register @keyring`. Before this
  change, a memfd ran a binary past every exec control. The default action kills the process (proven: `mount`,
  `setuid(0)`, `ptrace`, `memfd_create`, io_uring and `add_key` all end in SIGSYS).
- **DR-17:** the real ExecPaths set is wider than "PG16 plus a shell". It includes every PG16 tool and a dozen
  non-library executables in `/usr/lib/x86_64-linux-gnu`, and the loader can start any ELF file inside it. This is
  acceptable once memfd is closed; narrowing it to per-file ExecPaths is optional hardening.

### 5.5 Resources and lifetime
| Property | Value |
|---|---|
| MemoryMax | tmpfs cap + 512 MiB; tmpfs pages are charged to the unit |
| MemorySwapMax | 0: patient data never reaches swap |
| TasksMax | 64 |
| CPUQuota | 50% on production |
| Nice | 10 |
| OOMPolicy | stop: stopping the unit is what frees the tmpfs |
| OOMScoreAdjust | 800 |
| RuntimeMaxSec | for example 1800 |
| TimeoutStopSec | 10 |
| KillMode | control-group |

- `IOWeight=10` is only effective with the io controller and BFQ or io.cost. Disk writes are prevented outright instead
  (DR-1).

### 5.6 PostgreSQL inside the unit
- The same pinned PG16 binaries, which must report 16.15 or later.
- `initdb -E UTF8 --locale=<DMS_RESTORE_DRILL_LOCALE> --auth=trust -U drill_super`.
- Server settings:
  - `listen_addresses=''`, `unix_socket_directories=<PGDATA>`, `unix_socket_permissions=0700`;
  - `fsync`, `synchronous_commit` and `full_page_writes` off; `jit=off`; `autovacuum=off`;
  - `max_connections=8`, `shared_buffers` 64 MB, `max_locks_per_transaction=1024`;
  - log to a file in the tmpfs; no archiving and no replication; empty preload libraries.
- Roles:
  - mirrored role names NOLOGIN (names only);
  - `drill_restore`: LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS, a member of every mirrored
    role (INHERIT, SET);
  - the database is owned by `drill_restore`;
  - after setup, **`drill_super` becomes NOLOGIN** (DR-5).
- **Fidelity deviation:** each mirrored role gets CREATE on `public` before the replay.
- **What still runs with superuser rights (DR-15):** no session of `drill_super` runs after setup. However, a trusted
  extension that the dump creates runs its install script with superuser rights (PostgreSQL's trusted-extension rule),
  and the restore role can set per-role and per-database parameters (a denial-of-service lever only). Both stay inside
  the unit.
- **Psql restricted mode is load-bearing for TB-5:** without it, `\c - drill_super` would have reached the superuser
  before DR-5. It is never retired.
- The server always stops with SIGQUIT (F-ISO-4).
- Privacy: psql and server output stay in the tmpfs. Only the SQLSTATE and the stage leave the unit.
- **Prototype-only paths must not reach the implementation (DR-18):**
  - superuser replay mode;
  - the test report;
  - the `--relax`, `--compat-r4` and `--no-trailer-check` switches.

## 6. Files to change in the implementation PR (not in this document's commit)

| File | Change |
|---|---|
| `scripts/rollout-restore-drill.sh` | Replace createdb, replay, counts and dropdb on production with the unit launch. Keep the integrity chain and the read-only preflight. Add the trailer and count extraction, unlink-after-open staging, the lock plus the existing-unit refusal, the capacity check, the name and `getent` checks, and the host-side GO conditions (§4 step 7). Write the root-attested evidence copy. |
| `scripts/lib/restore-integrity.sh` | The pinned-client check also covers `postgres`, `initdb` and `pg_isready`, with a `postgres --version` 16.15+ check. Add the trailer and count helpers. Restricted stream, load guard and split-CHECK refusal stay. |
| `scripts/lib/restore-sandbox.sh` (new) | The single source of truth for the property set, unit naming, launch, bounded stderr, byte-counted feed, strict parse, effective-profile capture, cleanup and capacity check. |
| `scripts/restore-drill-sandbox-inner.sh` (new) | The in-unit orchestrator: builtins and pinned binaries only; profile self-check; NOLOGIN; fd hygiene; counts. |
| `app/Services/Foundation/RestoreDrillEvidenceService.php`, `config/rollout_readiness.php` | Verify the root-attested evidence copy, or treat the app copy as unattested (DR-8). |
| `.cursor/rules/235-deploy-least-privilege-sudo.mdc` §5 | The boundary becomes isolation; restricted mode is load-bearing; the residuals are restated. A new durable rule (next free number at implementation time) records the isolation profile. |
| `.claude/rules/deploy-infra.md` | One index line. |
| `docs/runbooks/roll-5-backup-restore-drill-runbook.md` | Prerequisites, result reading, cleanup, the orphaned-unit rule. |
| `docs/evidence/rollout/restore-drill-template.md` | New notes and the attested copy. |
| `docs/sprints/dms-spn4-ready-pilot-1.md` §13 | R6-B6 row: A-1 "isolation implemented, review pending"; R6-B6-F1 stays OPEN. |
| `config/ci_runner.php` | New suite token and mandatory suite (CG-19). |
| `tests/Feature/Foundation/RestoreDrillIsolationProfileTest.php` (new) | Static checks: the complete property set (including DR-1, DR-2, DR-4 and DR-12 lines); no production createdb, replay or dropdb; parser and GO conditions; inner script uses builtins only; DR-18 paths absent. |
| `tests/Feature/Foundation/RestoreDrillSandboxRuntimeTest.php` (new) | The real sandbox (this matrix, final-profile parts). It must actually run in CI (OD-ISO-7); a skip is not a pass. |
| `RestoreDrillTrustedBackupTest`, `RestoreDrillOwnershipPrivilegeTest`, `RestoreDrillOwnerRolePreflightTest` | Update expectations: no database on production; replay identity; completeness. |
| `config/release_safety.php` | The drill script is listed: check whether a manifest or hash refresh is needed. |
| `scripts/restore-rehearsal.sh`, `scripts/restore_postgres.sh` | No change in this PR (residual R-5). |

## 7. Migration path from the deny-list

| Phase | Content | Gate |
|---|---|---|
| 0 (now) | #695 held: remote `1892b0bd`, local `039a7f59`, A-1 OPEN. This design, the prototype, and review round 1 fixed in the prototype. | none |
| 1 | Owner decisions OD-ISO-1 to OD-ISO-9 (§10) | owner |
| 2 | Implement on a branch on top of `039a7f59` (every #695 commit kept), with base `bd52f97f` merged in. The unit becomes the boundary. Restricted mode stays (load-bearing); the load guard and split-CHECK refusal stay as defense in depth. | local tests |
| 3 | System-mode verification on a non-production Linux host or a CI runner with sudo. It covers: uid separation (the LIMIT rows); DynamicUser name resolution (DR-6); name reuse (DR-11); IPAddressDeny alone (DR-3); `/dev/shm` and devpts behaviour; the effective profile capture. | evidence |
| 4 | **Independent review of the implemented isolation controls** (directive item 13, review round 2). Only after it may A-1 be reported CLOSED. | reviewer |
| 5 | Production readiness, read-only, owner-run: systemd version; MemAvailable; database size; locale; untrusted PLs installed; `/dev/vsock` and vsock modules; `kernel.core_pattern` handler (DR-9); `vm.memfd_noexec` and `kernel.io_uring_disabled`; checkout and config ownership (DR-19). A first production drill only by a separate owner decision (forbidden today). | owner |
| 6 (later) | Retire the load guard and the split-CHECK refusal if the review agrees (they cost fidelity and are not the boundary). **Restricted mode is not retired.** Move `restore-rehearsal.sh` onto the unit. R6-D gives `restore_postgres.sh` a non-superuser path. | owner |

## 8. Positive and negative test matrix

The local results are in §11. Status values:
- **PROVEN-L**: proven locally in the user-mode prototype, final profile;
- **B→A**: the finding reproduced before its fix and was closed after it;
- **NOT RUN**: needs system mode or production;
- **PLANNED**: an implementation test.

| ID | Case | Expected | Status |
|---|---|---|---|
| POS-1 | Synthetic runtime-owned dump | GO, completeness match | PROVEN-L |
| POS-2 | Full application schema (238 migrations, 186 tables), final profile, restore role, restricted stream | decision GO; 186/186 tables, 238/238 rows; triggers, constraints, functions and indexes equal the source | PROVEN-L |
| POS-3 | Three concurrent drills | all GO, separate units, nothing left behind | PROVEN-L (production: one at a time) |
| NEG-F | Read or write production stand-ins (decoy secrets in home, `/dev/shm`, `/var/tmp`; the system cluster socket; `/run`; D-Bus; host cgroups; devpts) | refused | PROVEN-L (F-ISO-1, DR-12 B→A) |
| NEG-D | Writes to host disk (`/tmp`, `/var/tmp`) | refused (EROFS) | B→A (DR-1) |
| NEG-N | TCP, UDP, IPv6, netlink, packet, metadata service, abstract socket, decoy cluster by socket and TCP, dblink; io_uring sockets including AF_VSOCK | refused; the network namespace and the family filter each hold alone for socket(2); io_uring is killed | PROVEN-L, DR-2 B→A; IPAddressDeny NOT RUN (DR-3) |
| NEG-X | Exec outside the allowlist; exec or mmap of code written to any writable mount; anonymous RWX; `LOAD` of a copied library; memfd exec | refused | PROVEN-L (F-ISO-2, DR-4 B→A) |
| NEG-K | User and mount namespaces, `mount`, `setuid(0)`, `ptrace`, `add_key`, `/proc/sys`, `/sys` and cgroup writes, capabilities | refused | PROVEN-L |
| NEG-D2 | The disposable superuser (superuser replay mode): `pg_read_file`, `COPY TO` file, `COPY … PROGRAM`, `lo_export`, `LOAD`, dblink | contained by the unit | PROVEN-L |
| NEG-R | The same statements as the restore role | refused by PostgreSQL itself (42501) | PROVEN-L |
| NEG-E | `\c - drill_super` in the dump, without restricted mode | before: the superuser was reached and shell ran. After: NOLOGIN, replay FAIL. | B→A (DR-5) |
| NEG-M | A psql meta-command in the dump | stops the replay under restricted mode | PROVEN-L |
| NEG-V | Forged result lines: through `\o /proc/self/fd/3`, and by the disposable superuser through `/proc/<pid>/fd/3` | before: one forged line arrived. After: fd 3 is closed, nothing arrives, decision FAIL. The superuser path is seen as duplicates, FAIL. | B→A (DR-10); R-2 |
| NEG-T7 | Truncated dump; rows lost during the replay | refused before launch (no trailer); completeness mismatch gives decision FAIL although the sandbox says GO | B→A (DR-7) |
| NEG-S | Disk fill, memory bomb, endless statement, fork bomb | FAIL inside the unit; no host-disk write; nothing left behind | PROVEN-L |
| NEG-C | SIGINT, SIGTERM, SIGKILL of the wrapper mid-replay; replay failure | the unit ends; no process or mount left behind | PROVEN-L |
| NEG-PF | A weakened profile (drill tmpfs without noexec) | refused by the self-check before any input | PROVEN-L |
| NEG-P | Privacy: no row content, secret or error text in the result channel or the journal | none | PROVEN-L |
| NEG-I | Tampered backup, untrusted digest, symlink, group-writable file, swap race, missing evidence, old psql | refused before launch | PROVEN by #695 (26 PASS), unchanged |
| NEG-U | Another uid's files, signals and `/proc`; DynamicUser NSS (DR-6); name reuse (DR-11); IPAddressDeny alone (DR-3) | refused, or as specified | NOT RUN (phase 3) |
| NEG-H | Production host checks: vsock, core_pattern, sysctls, ownership (DR-2, DR-9, DR-19) | as specified | NOT RUN (phase 5) |
| NEG-T | Pest: profile completeness, parser, GO conditions, no production replay path, no DR-18 paths | as specified | PLANNED |

## 9. Residual risks

| ID | Risk | Treatment |
|---|---|---|
| R-1 | Shared kernel and systemd: a kernel or service-manager flaw could cross the boundary | seccomp (no io_uring, memfd or keyring), no capabilities, no_new_privs, RestrictNamespaces. A VM is the stronger alternative (OD-ISO-1). |
| R-2 | Verdict integrity: native code in the unit can still write to the inner script's result channel through `/proc/<pid>/fd/3` (same uid) | Forgery yields FAIL, never GO: duplicate keys fail, and GO rests on host-checkable facts (exits, bytes, completeness against the host's own counts). Containment never depends on the verdict. |
| R-3 | Restore fidelity: the disposable cluster is not the production cluster | Role attributes and memberships are not mirrored. CREATE on `public` is pre-granted. Locale comes from config. Server settings differ. The drill no longer proves a restore into the production cluster itself. |
| R-4 | Resource contention on the production host (RAM tmpfs, CPU) | cgroup limits, a fail-closed capacity check, one drill at a time, a quiet window |
| R-5 | `restore_postgres.sh` (real DR) and `restore-rehearsal.sh` still replay as the superuser into production; R6-B6-F1 OPEN | separate owner decisions; R6-D |
| R-6 | A wrong trusted digest, or root compromise before the deploy hashed the dump | unchanged from #695 |
| R-7 | Within the allowlisted `/etc` files and the read-only system tree, world-readable non-secret content is visible; inotify works on readable trees | the `/etc` allowlist (DR-16) |
| R-8 | systemd version: the profile was validated on systemd 255 in user mode. A known property can be silently ineffective (DR-3). | effective-profile capture plus the in-unit attestation; phase 3 and 5 checks |
| R-9 | The prototype runs in user mode under the operator's uid, so uid separation is not proven locally | phase 3 |
| R-10 | `/bin/sh` is executable inside the unit because initdb needs it | the replay runs as the restore role with restricted mode, and the superuser is NOLOGIN; a two-unit split is optional hardening |
| R-11 | A SIGKILLed wrapper leaves the unit running until stdin EOF or RuntimeMaxSec | bounded lifetime; the next run refuses to start while it exists |
| R-12 | Patient data sits in RAM (tmpfs) during the drill | MemorySwapMax=0; nothing in the journal; see R-13 |
| R-13 | Core dumps (DR-9): with a pipe `core_pattern`, the kernel ignores `LimitCORE`, so a crash could hand memory with restored rows to the host crash handler | `CoredumpFilter=0`; phase 5 reads `core_pattern` and confirms how the handler treats dynamic uids |
| R-14 | Evidence forgery by the runtime (DR-8, pre-existing): the app copy of the evidence is runtime-writable | root-attested copy (OD-ISO-9) |
| R-15 | Hypervisor channels (vsock) and device side channels | io_uring and socket(2) paths closed; phase 5 checks `/dev/vsock` |

## 10. Owner decisions needed

| ID | Decision | Recommendation |
|---|---|---|
| OD-ISO-1 | Architecture: a transient systemd unit on the production host, versus an off-host VM. Identity: DynamicUser with the single userdb socket (option A) or a static nologin user (option B). | the unit (keeps patient data in its current custody); option A if phase 3 confirms it, otherwise B with the existing-unit refusal |
| OD-ISO-2 | Role mirroring: names read read-only from the production catalog, versus a trusted manifest. Roles are created only inside the disposable cluster (amends how D-Q6-a is applied). | read-only catalog read |
| OD-ISO-3 | Replay identity inside the disposable cluster | the non-superuser restore role, with `drill_super` NOLOGIN after setup; accept the CREATE-on-`public` deviation |
| OD-ISO-4 | Defense-in-depth layers | restricted mode is **mandatory** (load-bearing); keep the load guard and split-CHECK refusal in phase 2, review their retirement in phase 6 |
| OD-ISO-5 | Resource policy (tmpfs cap, MemoryMax, CPUQuota, RuntimeMaxSec, one drill at a time, refuse when too large) | as in §5.5 |
| OD-ISO-6 | Delivery: fast-forward update of #695, versus a new PR stacked on it | update #695 (one review of one boundary) |
| OD-ISO-7 | CI: a job that runs the real sandbox in system mode (workflow change) | yes, otherwise the runtime suite only skips |
| OD-ISO-8 | The independent reviewer for phase 4 (review round 2) | owner's choice |
| OD-ISO-9 | Root-attested drill evidence, with readiness reading the attested copy (DR-8) | yes; it touches the readiness service, so it is a separate scoped change |

## 11. Local feasibility evidence

Local runs used WSL2, the systemd 255 per-user manager and PostgreSQL 16.15, with synthetic data only. No shared or
production system was touched; the workstation's own cluster socket was only `stat()`ed from inside the unit. The
evidence pack holds the scripts and every run's raw output.

**Final run (run 6): 116 PASS, 0 FAIL, 8 LIMITATION.**

**Defects found by our own runs (1–4), all fixed:**

| Finding | Defect | Fix |
|---|---|---|
| F-ISO-1 | `PrivateDevices=yes` exposes the host `/dev/shm`: the disposable superuser read and wrote a decoy secret | private `/dev/shm` tmpfs |
| F-ISO-2 | `NoExecPaths=/` misses `TemporaryFileSystem=` and `PrivateTmp=` mounts: code written there executed | explicit `noexec`; the self-check refuses a weakened profile |
| F-ISO-3 | SIGINT is ignored by a background-started wrapper | cleanup on EXIT/TERM/HUP plus unit limits |
| F-ISO-4 | a fast shutdown hung on a full tmpfs until RuntimeMaxSec | immediate shutdown |

**Independent adversarial review, round 1 (19 findings):** the reviewer ran 51 tool actions and created no files outside
its own scratch directory, which it removed.

| ID | Severity | Finding | Disposition |
|---|---|---|---|
| DR-1 | HIGH | PrivateTmp is uncapped host disk, writable even through psql `\o`; a TemporaryFileSystem over it is silently dropped | **fixed**: `ReadOnlyPaths=/tmp /var/tmp`, `TMPDIR=/srv`, self-check requires capped tmpfs. B→A proven. |
| DR-2 | MEDIUM | io_uring creates sockets past `RestrictAddressFamilies`; AF_VSOCK reachable | **fixed**: io_uring syscalls filtered. B→A proven; L3 restated; vsock check in phase 5. |
| DR-3 | MEDIUM | IPAddressDeny untested alone, a no-op in user mode | **open to phase 3**; no longer counted; effective-profile capture plus attestation added |
| DR-4 | MEDIUM | memfd executes past ExecPaths and noexec | **fixed**: `memfd_create` filtered. B→A proven. |
| DR-5 | MEDIUM | `\c - drill_super` from the dump reached the superuser without restricted mode | **fixed**: NOLOGIN after setup. B→A proven. Restricted mode declared load-bearing. |
| DR-6 | MEDIUM | initdb needs a passwd entry, so hiding `/run` breaks DynamicUser | **design choice** OD-ISO-1 (option A or B); phase 3 |
| DR-7 | MEDIUM | a truncated dump got GO | **fixed**: trailer and key check, plus host-counted completeness. Proven: truncated → refused; lost rows → FAIL. |
| DR-8 | MEDIUM | the runtime can forge the app copy of the evidence (pre-existing) | **design change**: root-attested copy (OD-ISO-9) |
| DR-9 | MEDIUM | a pipe `core_pattern` ignores LimitCORE | **mitigated** in the profile (`CoredumpFilter=0`, accepted by systemd; not exercisable locally); handler check in phase 5 |
| DR-10 | LOW | every cluster process inherited the result channel | **fixed**: fd 3 closed. B→A proven. |
| DR-11 | LOW | DynamicUser silently reuses a static user; the unit name was too long for `User=` | **design**: short random name, `getent` check, uid-range assert |
| DR-12 | LOW | host cgroup tree, devpts and `add_key` exposed | **fixed**: InaccessiblePaths and `@keyring` filtered. B→A proven for cgroup. |
| DR-13 | LOW | an orphaned unit outlives the lock; the staging copy lingers | **design**: refuse while a unit exists; unlink-after-open staging; `OOMPolicy=stop` |
| DR-14 | LOW | the unit's stderr was a host file | **fixed**: bounded pipe (proven) |
| DR-15 | LOW | "nothing runs as the superuser after the first byte" was overstated | **reworded** in §5.6 |
| DR-16 | LOW | the `/etc` protection was a deny-list | **fixed**: `/etc` allowlist (5 files), initdb proven |
| DR-17 | INFO | ExecPaths is wider than stated | **documented** in §5.4 |
| DR-18 | INFO | prototype-only paths | **NEG-T** asserts their absence |
| DR-19 | INFO | the wrapper trusts checkout config files | phase 5 ownership check |

**What the final run shows (run 6):**
- **Production stand-ins**, each first proven reachable from the host (H1–H4): a decoy cluster with trust auth on a
  Unix socket in `/dev/shm` and on TCP 127.0.0.1, world-readable decoy secrets, and an abstract socket. From inside the
  unit, every one was hidden or unreachable.
- **Arbitrary native code** (a Python probe), as listed in NEG-F, NEG-D, NEG-N, NEG-X and NEG-K: every case refused.
  Process state: CapEff 0, NoNewPrivs 1, Seccomp 2.
- **The disposable superuser:** every escape was contained. **The restore role:** every escape was refused by
  PostgreSQL itself.
- **Before and after for each fix:** DR-1, DR-2, DR-4, DR-5, DR-7, DR-10 and DR-12 reproduced first, then were closed.
- **Resources:**
  - disk fill: FAIL in 1 s at the tmpfs cap;
  - memory bomb: stopped by MemoryMax;
  - endless statement: killed at RuntimeMaxSec;
  - fork bomb: 23 forks under TasksMax 32.
- **Cleanup:** SIGINT and SIGTERM stop the unit at once; after SIGKILL the unit ends by itself (17 s); nothing is left
  behind.
- **Fidelity:** the full schema restores with decision GO and completeness 186/186 tables, 238/238 rows. Triggers,
  constraints, functions and indexes (22, 1045, 5, 968) equal the source. Three concurrent drills all GO.
- **Privacy:** no stray line on the result channel, and nothing from inside any drill in the journal.
- **LIMITATION (8):** the user-mode prototype shares the operator's uid, so same-uid `/proc` and signals are not
  prevented here (phase 3). The 8 also cover R-2 as the disposable superuser and the shared abstract namespace when the
  network namespace is relaxed (by design).

Not run here: system-mode DynamicUser (phase 3), any production host, the deploy runner.

## 12. Recommendation

| Question | Recommendation |
|---|---|
| Implement this design (phases 1–2) | **GO**, after the owner decisions in §10. Locally it is feasible: the full schema restores with exact fidelity and completeness, every isolation layer held in the final matrix, and every proven review-round-1 finding is fixed and re-tested in the prototype. |
| Finding A-1 | **Stays OPEN.** NO-GO for any closure claim until the implementation, the system-mode tests (phase 3) and the independent review of the implemented controls (phase 4, round 2) are done. |
| A production drill | **NO-GO** (forbidden by the current directives; needs the phase 5 checks and a separate owner decision). |
| Merge of #695 | **NO-GO** (held). |
| R6-B6-F1 | Stays OPEN, separately. |
