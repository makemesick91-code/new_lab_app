---
kb_status: unverified
kb_verified_against: b134d084
kb_updated: 2026-11-25
---

# R6-B6 — Production backup format transition to pg_dump custom format (design only)

Date: 2026-11-25. Source: repository at `b134d084`, while the R6-B6 Phase 3 changes to the drill files
(`scripts/lib/restore-archive.sh`, `scripts/restore-drill-vm.sh`, `scripts/restore-drill-vm-guest.sh`) were in
progress. Line references into those three files may have moved; every other reference is to committed code.

Location legend. The repository is public, so this document never names a host, an account, a key or a real
production path. Locations use these symbols:

| Symbol | Meaning | Where the code defines it |
|---|---|---|
| `<deploy-backup-dir>` | the app-relative private backup directory that NSF-10 verification allows | `config/backup_governance.php:15-17`, `scripts/backup-vps.sh:27`, `scripts/deploy-vps.sh:355` |
| `<rollback-backup-dir>` | the app-relative private directory for pre-rollback dumps | `scripts/rollback-vps.sh:295,301` |
| `<backups-root>` | the parent of both, normalised to 2750/0640 | `scripts/lib/runtime-privsep.sh:332,360-362` |
| `<manual-backup-dir>` | the manual helper's default: a subdirectory of the caller's working directory | `scripts/backup_postgres.sh:18` |
| `<root-staging>` | the deploy/rollback run's root-only snapshot staging directory | `scripts/deploy-vps.sh:373-374` |
| `<root-evidence-dir>` | the root-only deploy evidence directory (key `DMS_PRODUCTION_DEPLOY_EVIDENCE_DIR`) | `deploy/production-host.conf:70` |
| `<pg16-bindir>` | a pinned PostgreSQL 16 client directory, used by absolute path | `scripts/rollout-restore-drill.sh:176`, drill config key `DMS_DRILL_PG_BINDIR` |
| runtime identity | the dedicated application runtime account (PHP-FPM, scheduled backup) | `deploy/runtime-identity.conf` |

---

## 1. Status and authority

- **Design approval only.** This plan prepares a later production change. It changes nothing in production or in the
  repository. No command was run against production, a database or the network to write it.
- **Authority.** The owner approved the pg_dump custom format (`pg_dump -Fc`) as the target backup standard (R6-B6,
  PR-6: `docs/architecture/r6-b6-isolated-restore-drill-design.md` §0.4). That decision sets the target. It does not
  authorise any production change.
- **A separate owner approval is required** for each production phase in §4–§5, through the release process in §13.
  Nothing here may be read as that approval (CG-22).
- Facts come from code. Where the repository cannot show something, this plan says "not determinable from the
  repository" and lists it in §14.

---

## 2. Current backup inventory, from code

### 2.1 Finding in one line

Every automated production producer writes **uncompressed, unencrypted, plain-text SQL** (`pg_dump` with no
`--format` and no compression). The code does not support the "possibly gzip'd" assumption: no producer compresses.
The only custom-format producer is the manual helper `scripts/backup_postgres.sh`, which nothing calls and nothing
verifies. The isolated drill accepts only custom-format archives. **Today no production backup is drill-eligible.**

### 2.2 Producers

| # | Producer | Format / compression / encryption | Naming | Location | Retention | Who runs it | Consumers |
|---|---|---|---|---|---|---|---|
| P-A | **Pre-deploy backup**: `scripts/deploy-vps.sh:339-391`, launched by `scripts/deploy-vps-runner.sh:96,278-281` | Plain SQL. `pg_dump -h -p -U -d` with no `-F` (`deploy-vps.sh:375-379`). No compression, no encryption. | `pre_auto_deploy_<YYYYmmdd-HHMMSS>.sql` (`:365`). The stamp is `date +%Y%m%d-%H%M%S` in the host's local time zone (`:13`). | Root dumps into `<root-staging>/dump.sql` (`:373-375`), hashes it (`:380`), and hands the bytes to the runtime identity, which writes `<deploy-backup-dir>/…` (`:381`). Root re-hashes the copy and compares (`:384-387`). | Shares `<deploy-backup-dir>` with P-C, so P-C's `*.sql` prune deletes it after 14 days, floor 3 (`backup-vps.sh:64-76`). | The deploy runner, as root on the host, before the checkout and migrations (CG-15). | NSF-10 verify (`deploy-vps.sh:464`); `release:evidence-capture --backup-path` (`:543`); trusted digest line `Pre-deploy backup: <path> sha256=<hex>` in the root-only run log (`:391`), parsed by `scripts/lib/restore-integrity.sh:36-67`; `restore_postgres.sh`, `restore-rehearsal.sh` and `rollout-restore-drill.sh` through `--deploy-id`. |
| P-B | **Pre-rollback backup**: `scripts/rollback-vps.sh:284-325` | Plain SQL. Same `pg_dump` invocation (`:311-315`). No compression, no encryption. | `pre_rollback_<YYYYmmdd-HHMMSS>.sql` (`:301`, stamp `:33`) | Root staging, then `<rollback-backup-dir>` (`:294-295,309-317`) | **No prune in code.** Host-side pruning is not determinable from the repository. | The administrator, as root on the host (`rollback-vps.sh <commit>`) | NSF-10 verify (`:373`); the digest is printed to the operator's terminal (`:325`) and is the `--expected-sha256` source for `restore_postgres.sh` (`:469`). |
| P-C | **Scheduled daily backup (ENT-12)**: `scripts/backup-vps.sh` | Plain SQL. `pg_dump` with no `-F` (`:49-53`). No compression, no encryption. | `auto_backup_<YYYYmmdd-HHMMSS>.sql` (`:26,47`) | `<deploy-backup-dir>` (`:27,46`) | Prunes `*.sql` (all kinds in the directory) with mtime over `BACKUP_DR_RETENTION_DAYS` (default 14), keeping at least `BACKUP_DR_MIN_RETAINED` (default 3) (`:35-36,61-79`; `config/backup_dr.php:113-116`). | The systemd oneshot `deploy/systemd/*-db-backup.service` (runs as the runtime identity, `:36-38`, `ExecStart` `:44`, `Nice=10`, idle I/O `:47-48`, `TimeoutStartSec=1800` `:52`). Its timer `deploy/systemd/*-db-backup.timer` fires at 18:30 UTC = 02:30 WITA (`:92`), `Persistent=true` (`:97`), `RandomizedDelaySec=60` (`:101`). The unit runs the script from the live checkout, so a deploy changes this producer at its next run without reinstalling the unit. | NSF-10 verify (`:59`); the monitoring freshness signal; the developer console; the storage usage report; the ENT-12 governance scanner. **No root-held digest exists:** the producer runs as the runtime identity. |
| P-D | **Manual helper**: `scripts/backup_postgres.sh` | **Custom format** (`-F c`, `:22-27`). Default compression (pg_dump's gzip). No encryption. | `<db>_<YYYYmmdd_HHMMSS>.dump` (`:13,22`). Note the different stamp format. | `<manual-backup-dir>` by default (`:18`). That is outside the NSF-10 allowed directory. | None | Manual only. No script, unit or doc runbook calls it (only `config/deployment_rollback.php:41` lists it, as advisory). It has hard-coded defaults for the database name and user (`:14-15`). | None. No verify, no digest. `tests/Feature/Hardening/BackupScriptTest.php:19-24` asserts only that it contains `pg_dump`. |
| P-E | **Rehearsal snapshot**: `scripts/restore-rehearsal.sh:160-176` | Plain SQL. `pg_dump -d` as the PostgreSQL superuser over the peer socket (`:170`). No compression, no encryption. | `rehearsal_source_<YYYYmmdd-HHMMSS>.sql` (`:165`) | Root staging copy, plus a runtime-owned copy in `<deploy-backup-dir>` (default `:89`; written `:174-175`) | Pruned by P-C's `*.sql` rule | The administrator, as root, when no backup is passed | Its own in-place replay (`:268-274`); evidence JSON (`:326-344`) |
| P-F | **BK-2 encrypted off-server package** (host-only) | **Not determinable from the repository.** The timer comment says BK-2 "only read[s] the database" (`deploy/systemd/*-db-backup.timer:75-78`), which implies its own dump in an unknown format. Packages are encrypted, and the workstation verifies each one: manifest SHA-256, an O_DIRECT re-read and a decrypt check (`docs/runbooks/bk2-offserver-workstation-recovery.md:13-16`). | Not in repository | Server-side, then the custodian's encrypted HDD | "It never prunes" (`*-db-backup.timer:86-87`) | The host-only timer (02:30 WITA). Manual-only directive: never a sprint or deploy gate (`.cursor/rules/232-bk2-manual-only-not-a-deploy-gate.mdc`). | The private BK-2 recovery procedure (not in repository) |

The shared creation helper, `scripts/lib/private-db-dump.sh`, is sourced by P-A to P-D:
- the backup directory is 2750 with no default ACL (`:46-48,66-84`);
- the dump is born 0600 under `umask 077`, written to `<dest>.partial` under `noclobber` (O_EXCL), refused when empty
  and published by an atomic rename (`:92-149`);
- the dump is written through **stdout redirected to a regular file** (`:120`), so the output is seekable.

Deploy normalisation then widens the backup tree to group-readable 0640 (`scripts/lib/runtime-privsep.sh:360-362`).
`scripts/harden-secret-permissions.sh:152-156` already treats `*.sql`, `*.sql.gz`, `*.dump` and `*.tar.gz` as data
backups.

### 2.3 Consumers (restore, drill, verification, monitoring)

| Consumer | What it accepts | Format coupling |
|---|---|---|
| `scripts/restore_postgres.sh` (the operator's production DR restore) | Plain SQL only. It streams the file into `psql` as the PostgreSQL superuser (`:3,411-423`). It is integrity-gated (`:343-357`) and checks owner roles and the runtime ownership gate from the SQL text (`:365-381`). | It refuses a custom archive at the header check: `dms_restore_verify_copy` needs `-- PostgreSQL database dump` (`scripts/lib/restore-integrity.sh:114-117`). Its `--deploy-id` path accepts only `pre_auto_deploy_…\.sql` and **exactly one** `Pre-deploy backup:` line (`restore-integrity.sh:60-64`). |
| `scripts/restore-rehearsal.sh` | Plain SQL only, replayed with `psql --single-transaction` **as the superuser into a scratch database on the same cluster, without restricted mode** (`:268-274`) | It has the same header and evidence coupling as above (`:179-195`). |
| `scripts/rollout-restore-drill.sh` | Plain SQL. Verification only: the in-place replay is retired, and the script exits 3 (`:3-8,328-337`). | It verifies a plain file (`:266`), then tells the operator to run `restore-drill-vm.sh` **on that same file** (`:335`). The drill refuses it (row below). **This hand-off cannot succeed for any production backup today.** |
| `scripts/restore-drill-vm.sh` + `scripts/lib/restore-archive.sh` (R6-B6) | **Custom format only.** The drill checks: the `PGDMP` magic after the digest (`restore-archive.sh:124-131`); TOC header `Format: CUSTOM`, `Dumped from database version: 16.`, `Dumped by pg_dump version: 16.` (`:53-55`); entry-type allowlist (`:23-24`); only the `public` schema (`:26`); every owner in the signed role manifest; every TABLE has one TABLE DATA entry; per-table counts from `pg_restore --section=data --restrict-key` (`:214-251`). | It refuses on the production host (`restore-drill-vm.sh:146`). Work space is sized from max(archive × 8, uncompressed data × 4) and capped at 1024 MiB (`:192,285-287`). |
| `php artisan foundation:backup-verify` (`app/Services/Foundation/BackupVerificationService.php`) | Path inside `<deploy-backup-dir>` only (`:43-58`). Extension `sql` only (`config/backup_governance.php:19`; check at service `:81-85`). Size ≥ 1024. A header marker, `PGDMP` included, is a WATCH when absent (`config/backup_governance.php:29-33`). | **A `.dump` FAILs** (`BACKUP-EXTENSION-EXPECTED`). The rollback path's use of the verifier is §14 Q-15. |
| `release:evidence-capture --backup-path` (`app/Services/Foundation/ReleaseEvidenceService.php:419-423,509-526`) | Any path. It runs backup-verify and records the basename and size. | Inherits backup-verify's extension rule |
| Monitoring "Deploy — Latest Backup" (`app/Services/Foundation/FoundationMonitoringStatusService.php:324-370`; `config/foundation_monitoring.php:51,80,176-184`) | The newest **regular file of any name** in `<deploy-backup-dir>` by mtime. Older than 48 h is WATCH. | Format-agnostic, so a `.partial` or a sidecar in the same directory would be reported as "latest backup". |
| Developer console disk/backup card (`app/Support/DeveloperConsole/DeveloperConsoleService.php:303-318`) | The newest five files of any name | Same as above |
| Storage usage report (`app/Support/Storage/StorageUsageReportService.php:208-218`) | Bytes under `<deploy-backup-dir>` | Format-agnostic |
| Governance scanners: `app/Support/Backup/BackupDrScanner.php:80-110`, `app/Support/Deploy/DeploymentRollbackScanner.php:71,152`, `app/Support/Cicd/CicdEnterpriseGateScanner.php:76`, `app/Services/Architecture/NsfApplicationRulesService.php:693,1062` | Literal markers in the scripts: `pg_dump`, `test -s`, `php artisan foundation:backup-verify`, `BACKUP_DR_RETENTION_DAYS`, the backup directory. `config/deployment_rollback.php:172-175` pins the exact lines `… dms_write_private_dump "${DUMP_STAGING}/dump.sql" pg_dump`, `DUMP_SHA256=… dump.sql …` and the runtime copy line. | Renaming the staging file or rewriting those lines breaks the scanners. Adding lines does not. |
| BK-2 workstation pull (rule 230) | Encrypted packages, whose contents are not in the repository | Format-agnostic acknowledgement |

### 2.4 Tests that pin backup behaviour (they must change together with the code)

- `tests/Feature/Hardening/BackupScriptTest.php:9-31` checks that `backup_postgres.sh` and `restore_postgres.sh`
  exist and contain the strings `pg_dump` and `pg_restore`. The `pg_restore` assertion passes only because a comment
  in `restore_postgres.sh:19-20` mentions `pg_restore`. The test is vacuous.
- `tests/Feature/Deploy/PrivateDatabaseDumpCreationTest.php` covers the creation helper: modes, partial, symlink, ACL
  and umask. It is format-agnostic, and it covers `backup-vps.sh`.
- `tests/Feature/Foundation/BackupVerificationFoundationTest.php:23,34,102-109` covers `allowed_extensions` containing
  `sql`, the header sniff, and no content read.
- `tests/Feature/Foundation/RestoreDrillTrustedBackupTest.php:31,209` and
  `tests/Feature/Deploy/DeployHardenLeastPrivilegeTest.php:937,1002-1011` pin the `Pre-deploy backup:` line and
  `pre_auto_deploy_….sql`.
- `tests/Feature/Deploy/SecretFilePermissionHardeningTest.php:333-343` covers mode handling of
  `pre_auto_deploy_*.sql`.
- `tests/Feature/Deploy/DeployHardenBk2OffServerRecoveryTest.php:98` checks that the deploy script contains
  `pre_auto_deploy_`.
- `tests/Feature/Architecture/Ent12BackupDisasterRecoveryAutomationTest.php:24-29,134-161` covers the backup and
  rehearsal script markers.
- `tests/Feature/Foundation/RestoreDrillArchiveTest.php` and `RestoreDrillVmProtocolTest.php` cover `PGDMP` and the
  custom-only drill.
- No test references the systemd backup unit or the `auto_backup_` / `pre_rollback_` names.

### 2.5 Documents that state the current behaviour

- `docs/runbooks/backup-dr-restore-rehearsal-runbook.md:11` gives RTO 60, RPO 1440 and retention 14.
  `:26-34` says backups "are plain-text `pg_dump` output". `:49-56` covers the unit install.
- `docs/runbooks/roll-5-backup-restore-drill-runbook.md:29-30` covers deploy backups as `*.sql`. `:54-56` says the
  drill needs `-Fc`. `:65` says readiness does not read drill records yet. `:191-199` gives a draft RPO ≤ 24 h and
  RTO ≤ 4 h. `:156` shows example evidence with a `.sql` path.
- `docs/architecture/backup-disaster-recovery-automation-governance.md:39-48` covers RTO, RPO and retention.
- `docs/runbooks/bk2-offserver-workstation-recovery.md` and `.cursor/rules/230-…`, `232-…` cover BK-2 (format not
  stated).
- No `*disaster*` or `*rpo*` runbook exists beyond these.

---

## 3. Target format and invariants

| # | Invariant | Decision and reason |
|---|---|---|
| T-1 | **Format** | `pg_dump --format=custom`. It is the only format the drill accepts (PR-6). It is also the only one with a structured TOC (owners and entry types) and per-entry data blocks that `pg_restore -l` and `--section` can inspect without heuristics. |
| T-2 | **Version** | pg_dump **16**, run from `<pg16-bindir>` **by absolute path**, never through PATH. Today every producer calls a bare `pg_dump` (`deploy-vps.sh:375`, `rollback-vps.sh:311`, `backup-vps.sh:49`), and which binary PATH or a version-switching wrapper resolves to is not determinable (§14 Q-3). Before dumping, the producer asserts that `pg_dump --version` reports major 16 and fails closed otherwise. The server major must also be 16. The drill refuses any archive whose header does not say "Dumped from database version: 16." and "Dumped by pg_dump version: 16." (`restore-archive.sh:54-55`). Minor: pg_dump at least as new as the server's 16.x minor. The drill side needs pg_restore 16.15 or later (§0.4). |
| T-3 | **Compression** | pg_dump's **built-in gzip at level 6**, stated explicitly (`--compress=6`, which is gzip; the custom format's default level), so the archive never depends on a build default. Not lz4 or zstd for now: every pg_restore that must read the archive (drill image, any DR host) would need the same library, and that is not proven. A later switch is an owner decision based on the measurements in §5. **Never external compression** (`pg_dump -Fc | gzip`): pg_restore cannot read the result directly, and the pipe makes pg_dump's output non-seekable. |
| T-4 | **Seekable output** | The archive is written by pg_dump's stdout **redirected straight to a regular file**, as `dms_write_private_dump` already does (`private-db-dump.sh:120`). Never through a pipe or an encryption filter. pg_dump records data-block offsets in the TOC only when its output is seekable. `pg_restore -j` and the drill's selective `pg_restore -L <list>` per section (`scripts/restore-drill-sandbox-inner.sh:173-205`) depend on reading the archive in an order other than the file order. The parallel phase must confirm that `-L` reordering and `-j` work on a production-shaped archive (§5). |
| T-5 | **`--no-owner`?** | **Do not pass it.** (a) For archive formats pg_dump ignores `-O`: ownership is a restore-time choice in pg_restore, so the flag would only mislead readers. (b) The drill's TOC check needs an owner on every entry and checks each against the signed role manifest (D-Q6-a, `restore-archive.sh` owner parsing). P5 (no runtime ownership after R6) is decided from those owners. (c) The restore policy is "ownership is never stripped or rewritten" (`restore_postgres.sh:360-363`). |
| T-6 | **Other flags** | Do not pass `-x/--no-privileges`: it drops ACL entries, and the R6 privilege split and the drill's last-phase ACL restore rely on them. Do not pass `--clean`, `--create`, `--if-exists`, `--schema`/`--table` filters or `--exclude-*`. The archive must be a complete database dump. Any TOC entry type outside the drill allowlist, found in §5, is a drill-design finding. It is never a reason to change dump flags silently. Connection parameters stay as today. |
| T-7 | **Deterministic naming** | Keep each producer's existing stem and stamp, and change only the extension to **`.dump`**: `pre_auto_deploy_<stamp>.dump`, `pre_rollback_<stamp>.dump`, `auto_backup_<stamp>.dump`. `.dump` is already used by `backup_postgres.sh:22` and by the drill usage (`restore-drill-vm.sh:17,135`), and `harden-secret-permissions.sh:155` handles it. The stamp format stays identical so deploy-id correlation keeps working. In the parallel phase, the `.sql` and `.dump` of one run share the stem. The host time-zone semantics of the stamp (`date` with no `-u`) are unchanged and not determinable (§14 Q-4). |
| T-8 | **SHA-256 recorded independently** | The **trusted** digest comes from an identity the runtime cannot impersonate, before the runtime holds the bytes, as rule 235 R4 already requires (`restore-integrity.sh:12-27`). Deploy: root hashes the staged archive and writes a **new, distinct** line to the root-only run log, `Pre-deploy archive: <path> sha256=<hex>`. It must **not** reuse the `Pre-deploy backup:` prefix, because `restore-integrity.sh:60-64` refuses a log with more than one such line. Reusing it would break every `--deploy-id` plain restore for the whole parallel phase. Rollback: print `Pre-rollback archive: <path> sha256=<hex>` next to the plain digest. Scheduled (P-C): **no independent record is possible today**, because the producer is the runtime identity (owner decision OD-4). A **sidecar** `<archive>.sha256` (sha256sum format, basename only) may be written for convenience and for off-server copy checks. It is informational only and never a trust anchor ("a digest stored beside the dump proves nothing", `restore-integrity.sh:14-16`). Put sidecars in a `checksums/` subdirectory, not beside the archive, so the monitoring "latest file" signal and the `-maxdepth 1` prune do not see them (§11). |
| T-9 | **Private file modes** | Unchanged: `dms_write_private_dump` creates 0600 with O_EXCL, refuses empty output and publishes by atomic rename. Directories are 2750 with no default ACL. Deploy normalisation sets 0640 (group read only). Deploy and rollback archives are produced in `<root-staging>` and copied to the runtime identity, with root re-hashing the copy (`deploy-vps.sh:381-387`). The `.dump` goes through the same path and the same checks. |
| T-10 | **Encryption at rest** | On the server, the code has none today: protection is by file mode. The timer comment calls BK-2 "the only ENCRYPTED, OFF-SERVER" copy (`*-db-backup.timer:86-87`). Target for this transition: **no change to on-server encryption**, because an on-server key would sit on the same host as the archive (small benefit), while every verify, monitor, hash and restore step would need a decrypt stage. **Every copy that leaves the server is encrypted**: BK-2 (unchanged), and the archive moved to the isolated drill host (OD-7). Whether the VPS volume itself is encrypted is not determinable (§14 Q-6). |

---

## 4. Backup scheduler compatibility (what each producer needs)

The plan uses four releases. Each is a separate owner-approved production change (§13):

- **R1, parallel.** Both formats are produced.
- **R2, validation tooling.** No producer change. The custom DR helper, the drill evidence and monitoring are added.
- **R3, cutover.** Custom becomes the primary format.
- **R4, plain retirement.** Optional and later.

| Producer | R1, parallel (both formats) | R3, cutover / R4, retirement |
|---|---|---|
| P-A pre-deploy (`deploy-vps.sh`) | Add a **second** dump after the plain one, in the same root staging directory: `"<pg16-bindir>/pg_dump" --format=custom --compress=6 …` into `<root-staging>/dump.custom` through `dms_write_private_dump`. Root hashes it. The runtime copy goes to `<deploy-backup-dir>/pre_auto_deploy_<stamp>.dump`, then root re-hashes and compares. The log gets the new `Pre-deploy archive:` line. Keep the plain lines byte for byte (`config/deployment_rollback.php:172-175`), and add the new lines as additional required markers. Run backup-verify on both files (needs C-1). The extra dump adds its measured duration before the checkout. The deploy has no maintenance mode (no `artisan down` in `deploy-vps.sh`), so both dumps are live, consistent snapshots, taken at slightly different instants. | R3: `release:evidence-capture --backup-path` points to the `.dump` (`:543`). R4: remove the plain dump. The `Pre-deploy backup:` line ends, and the `--deploy-id` plain path stays valid for historical deploys only. |
| P-B pre-rollback (`rollback-vps.sh`) | The same second dump to `<rollback-backup-dir>/pre_rollback_<stamp>.dump`, with its digest printed next to the plain one. First resolve §14 Q-15. | As P-A |
| P-C scheduled (`backup-vps.sh` + unit) | Add the second dump to `auto_backup_<stamp>.dump`, then verify both. The **prune becomes per format**: today it matches only `*.sql` (`:64,69,76`), so `.dump` files would grow without bound. Run separate floor and age loops for `*.sql` and `*.dump`, and remove `checksums/` sidecars with their archive. The `TimeoutStartSec=1800` budget (`*-db-backup.service:52`) must cover both dumps. That is likely, given ~1 s measured for one plain dump on 2026-11-03 (`*-db-backup.timer:19`), but it must be re-measured on today's data. The unit file needs no change, because it runs the script from the checkout. If the timeout or the identity changes (OD-4), the unit must be reinstalled: a privileged host step (`install`, `daemon-reload`) outside the deploy runner, which is a separate owner-authorised admin action. The ENT-12 scanner markers stay present. | R3: monitoring requires `.dump` freshness. R4: stop the plain dump and **freeze the `*.sql` prune**, so the plain files left are never deleted by automation (§10, OD-8). |
| P-D manual helper (`backup_postgres.sh`) | Not used in production. Either align it with T-2 to T-8 (pinned pg16, allowed directory, verify, digest, no hard-coded defaults) or mark it deprecated (OD-12). Do not use it as a production producer while this transition runs. | Retire or align |
| P-E rehearsal snapshot (`restore-rehearsal.sh`) | **Do not port to custom.** Its replay is the same in-place superuser replay that finding A-1 retired in `rollout-restore-drill.sh` (§8). Its future is an owner decision (OD-10), outside this format change. | n/a |
| P-F BK-2 (host-only) | Not determinable. If the BK-2 package embeds its own plain dump, the owner decides whether and when it switches (OD-11). Its private runbook must then say which restore tool applies. BK-2 is never a gate (rule 232). | Owner |
| Shared config and code (C-1 to C-4), shipped in R1 before any `.dump` is verified | **C-1** `config/backup_governance.php:19`: `allowed_extensions` gains `dump`. Without it, the deploy (`deploy-vps.sh:464`) and the scheduled backup (`backup-vps.sh:59`) **FAIL** on the `.dump` under `set -euo pipefail`. `PGDMP` is already a header marker (`:31`), so a custom archive reaches GO. **C-2** The monitoring and developer-console signals filter on the archive extensions and ignore `*.partial` and `checksums/`. **C-3** `production-host-preflight.sh:66` and the operator tool allowlist (`deploy-operator-entrypoint.sh:55`) also check the pinned `<pg16-bindir>/pg_dump`. **C-4** Tests are updated with the code (§2.4). New suites get `--filter` tokens in both runner variants (CG-19). | — |

---

## 5. Parallel backup validation phase

**Duration.** N = **15 consecutive days** after R1 is deployed (proposal, OD-2). Fourteen days is the retention
window, so by day 15 the per-format prune has deleted at least one `.dump` and the floor logic has run for real. The
phase must also include at least **one deploy backup (P-A)**, and, only if the owner orders a rollback for another
reason, one P-B. Rollbacks are never run just to test.

**Per run, recorded by the producer.** Only metadata, no data (CG-11):
- exit status for each format;
- start and end epoch, so duration;
- size in bytes;
- sha256;
- the `pg_dump --version` line;
- the server version (`SHOW server_version`, read-only).
These go to the root-only run log for P-A and P-B, and to the journal (`SyslogIdentifier` of the unit) for P-C.

**Comparisons.** Every parsing of an archive happens on the **isolated drill host**, never on production: the drill
design keeps pg_restore parsing out of the production host (D1, PR-6). Production produces bytes and digests only.

| Check | Method | Pass criterion |
|---|---|---|
| TOC sanity | `dms_archive_inspect` (sandboxed `pg_restore -l`) + `dms_archive_toc_validate` with the signed role manifest | The header says CUSTOM, from 16, by pg_dump 16. Every type is in the allowlist, the only schema is `public`, every owner is in the manifest, the extension is in the drill allowlist, and every TABLE has one TABLE DATA entry. The catalog shape (`functions:tables:indexes:constraints:fk:triggers:sequences`) is stable across days, except where an approved migration changed it. |
| Per-table row counts | Custom: `pg_restore --section=data --restrict-key` counted by `dms_archive_count_program` in the inspection unit (already implemented). Plain: a parse-only counter over `COPY … FROM stdin;` … `\.` blocks, on the drill host. It is a **comparison metric, never a trust decision**, and the plain file is never replayed. | Equal counts on every table. **Snapshot caveat:** two sequential `pg_dump` runs see different snapshots. Option A (recommended for P-C): the validation harness exports one snapshot (`BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY; SELECT pg_export_snapshot();`, session held open) and runs both dumps with `pg_dump --snapshot=<id>`. Any difference then fails. Option B: sequential runs, where a difference is accepted only on tables written between the two snapshots, and is recorded. OD-6 chooses. |
| Size | Bytes per format, and the ratio custom/plain | Recorded. Anomaly: a custom size more than 20% from its 7-day median, unexplained, is WATCH (threshold is an owner choice). |
| Duration | Seconds per format | Custom dump time is within the unit's 1800 s budget with margin, and the deploy-time increase is recorded. |
| Seekability | `pg_restore -l` lists data offsets implicitly. Run one `pg_restore -L <reordered list> -f /dev/null` and one `pg_restore -j 2 -f`-style dry render (or a parallel restore inside the drill VM, if the drill gains it) on the drill host | No "possibly due to out-of-order restore request" error |
| Isolated drill | `scripts/restore-drill-vm.sh --expected-sha256 <independent digest> --manifest <signed manifest> <archive.dump>` on the drill host | **GO** with host-verified digest equal to the guest's per-table digest, equal table count and equal catalog shape. Minimum set: the first `.dump`, one mid-phase, the last, and **at least one P-A archive verified with its `Pre-deploy archive:` digest**. Every drill record is signed and chained (D10). |
| Drill size headroom | `DATA_BYTES` from the inspection, compared with the 1024 MiB cap (`restore-drill-vm.sh:285-287`) | Below the cap with margin. If not, the drill **refuses** the production archive. That is a blocker for the drill (D6 pilot limits), not for the format change (§14 Q-8). |

**Stop conditions.** Any one of these suspends the phase and holds R3:
- a custom dump failure;
- a digest mismatch between root and the runtime copy;
- a drill FAIL that is not explained;
- a TOC rejection;
- an unexplained count difference;
- disk free below the owner-set threshold.
The plain path keeps running throughout, so recovery capability does not change.

**Evidence.** A sprint doc (CG-24) records the measured numbers, never estimates (CG-21). A WATCH stays a WATCH.

---

## 6. Restore fidelity

The goal is to prove that the custom archive restores the same schema and data. Only disposable targets are used: the
drill VM on the isolated drill host. Nothing is replayed on the production cluster (A-1), and the drill VM never
connects to production PostgreSQL (D3).

1. **Catalog shape (available now).** The shape is computed from the TOC on the host and from the restored catalog in
   the guest, and GO requires them equal (P4P-1). The shape is also compared across the 15 days against the migration
   history: new tables or indexes appear only after an approved deploy.
2. **Per-table counts (available now).** The host counts every TABLE DATA block (PR-5) and builds a canonical sha256
   over `schema<TAB>table<TAB>rows` in C order. The guest computes the same digest read-only (`search_path=pg_catalog`,
   `row_security=off`), and GO requires equality. The plain-format counts from §5 add a second, independent producer
   of the same numbers.
3. **Content checksum of selected tables (needs a small, separately approved drill extension).**
   - Pick a fixed set of core tables, chosen by role, not by data: patients, visits, medical records, invoices and
     payments, the inventory movement ledger.
   - For each table, compute `md5(string_agg(row::text, E'\n' ORDER BY <primary key>))` in two places:
     - (a) on production, read-only, **inside the exported snapshot** of Option A in §5, so it describes exactly the
       archived state;
     - (b) in the drill guest, after the restore.
   - Only the digests leave either side, never rows (CG-11). Equality proves the content without replaying the plain
     file anywhere.
   - The production query needs explicit owner authorisation (CG-14: production checks are read-only unless
     authorised).
4. **Restore paths and tool parity.** Restore the same archive once in the default drill order and once with
   `pg_restore -j 2` inside a second disposable VM run, if the drill gains a parallel option. The counts digest and the
   catalog shape must be identical. This proves T-4 on real data.
5. **What fidelity does not cover.** Roles and other cluster globals are not in a database dump, in either format. The
   drill takes role names from the signed manifest. Production role provisioning at DR time stays the separate
   operator step it is today (D-Q6-a).

---

## 7. Encryption and retention

- **On server (unchanged posture).**
  - Archives are 0600 when created and 0640 after normalisation, in 2750 directories with no default ACL.
  - The runtime identity can read every backup. It can also read the live database, so this adds no new exposure, but
    it is recorded as a residual risk (§14 R-7).
  - No encryption at rest in the code (T-10). Volume encryption: §14 Q-6.
- **Off server.** BK-2 encrypts and never prunes. The workstation's acknowledgement (manifest sha256, O_DIRECT re-read,
  decrypt check) does not depend on the format. If BK-2 starts carrying `.dump` files, the custodian's private runbook
  states the tool (`pg_restore`).
- **Drill host.**
  - The archive travels encrypted and is held in a private directory.
  - The drill gives its VM an AES-256-CTR payload, which is explicitly "not for secrecy" (`scripts/lib/restore-vm.sh:6-10`).
  - After a drill, the staged copy is removed. Whether the orchestrator removes its work copy on every path is not
    verified here (§14 Q-11).
  - The drill host's archive retention is an owner decision (OD-7).
- **Retention of `.dump` files.**
  - The same policy as plain: `BACKUP_DR_RETENTION_DAYS` (14) with a floor of `BACKUP_DR_MIN_RETAINED` (3), **counted
    per format**.
  - Deploy archives share `<deploy-backup-dir>` and fall under the same rule, as plain deploy backups do today.
  - `<rollback-backup-dir>` has no prune in code.
- **Disk during the parallel phase.** Each day adds one plain and one custom dump, plus deploy pairs. Sizes are not
  determinable from the repository. Before R1, the owner reads free space and the current backup bytes (storage usage
  report, read-only) and sets a stop threshold (§5).
- **Historical plain backups.** Never deleted by this transition and never converted (§10).

---

## 8. Disaster recovery compatibility

| Helper | Today | What it needs for custom archives |
|---|---|---|
| `restore_postgres.sh` (DR restore into a scratch database or production) | `psql` as the superuser over a plain stream (`:411-423`). Integrity via `dms_restore_verify_copy` (plain header), owner roles parsed from SQL text, evidence regex `.sql`. | **A separate custom-format path, built and rehearsed in R2 before the R3 cutover.** Use `pg_restore` from `<pg16-bindir>`, never `psql`, on the custom file. Integrity: `dms_archive_verify_copy` (PGDMP + trusted digest), plus a new evidence parser for `Pre-deploy archive:` lines with the same exactly-one rule. Owner roles come from the archive TOC, not text heuristics. `pg_restore -l` runs in the same hardened unit as `dms_archive_inspect`, so root never parses the archive unconfined. Replay options (OD-9): (a) `pg_restore -d <target> --exit-on-error --single-transaction` (no psql meta-commands exist on this path; `-j` is not allowed with `--single-transaction`); or (b) `pg_restore -f - --restrict-key=<fresh>` into restricted `psql`, mirroring the drill. Neither strips ownership (T-5). The plain path stays **unchanged** for historical files. |
| `restore-rehearsal.sh` | Takes its own plain snapshot and replays it in place, as the superuser, **without restricted mode** (`:268-274`). This is the same class of risk that finding A-1 retired in `rollout-restore-drill.sh`. | Not ported. OD-10: retire it, or reduce it to verification only, as `rollout-restore-drill.sh` was. Rehearsal proof moves to the drill VM. |
| `rollout-restore-drill.sh` | Verifies plain only, then points at the VM drill with the same file (`:335`), which refuses it. | Accept a `.dump` through `dms_archive_verify_copy` and the `Pre-deploy archive:` evidence, then print the drill command for it. Or retire the script. Either way, the hand-off must name a file the drill accepts. |
| `restore-drill-vm.sh` | Custom only | No format change is needed. It needs the extension allowlist to include `pg_stat_statements`: the production dump carries it (`restore_postgres.sh:38-41`), and the drill refuses an archive with an extension outside the allowlist (PR-8). It also needs a manifest listing every archive owner. |
| `foundation:backup-verify` | `sql` only | C-1 (§4). Also resolve §14 Q-15. |
| BK-2 (off server, host-only) | Format not in repository | If the package contents change, the private BK-2 recovery procedure names `pg_restore` for `.dump` and keeps `psql` for historical `.sql`. Not a gate (rule 232). |

**Rule for the whole transition:** the plain producer and every plain restore helper stay in service until the custom
DR helper has a recorded GO rehearsal on disposable infrastructure, and the owner has approved R3. At no point does
production hold only a format it cannot restore.

---

## 9. RTO and RPO

- **Stated values (they disagree).**
  - `config/backup_dr.php:122,124`, the ENT-15 runbook (`backup-dr-restore-rehearsal-runbook.md:11`) and the DR
    governance doc (`:43-46`) give **RTO 60 min** and **RPO 1440 min**.
  - `roll-5-backup-restore-drill-runbook.md:191-199` gives a *draft* **RTO ≤ 4 h** and **RPO ≤ 24 h**.
  - The 60-minute versus 4-hour difference is unresolved (OD-13).
- **Measured values in the repository.**
  - One plain scheduled dump took about 1 s, and BK-2 about 9 s, on 2026-11-03 (`*-db-backup.timer:19`).
  - No restore duration from production data is in the repository. The rehearsal JSON's `restore_seconds` lives on
    the host and is not determinable here.
- **Effect of the format change.**
  - **RPO: none.** The cadence stays daily at 18:30 UTC, plus every deploy or rollback, and the timer is unchanged.
    Deploy backups are live snapshots with no maintenance window. That is unchanged.
  - **RTO:**
    - During R1 and R2, unchanged: plain restore stays the DR path.
    - After R3, RTO depends on the custom helper. `pg_restore -d` is expected to be comparable to `psql` replay.
      `pg_restore -j` can shorten large restores, but the gain is unmeasured and `-j` excludes single-transaction
      atomicity.
    - Until the custom helper exists, a custom archive has **no production restore path**, so its RTO is undefined.
      That is why §8's rule holds.
- **How to measure.**
  1. Dump duration and size per format, from the producer's own timestamps (§5).
  2. Restore duration of each drilled archive in the VM: host wall clock, and guest-reported time marked
     GUEST_ASSERTED.
  3. A timed DR rehearsal of the custom helper on a disposable target off production: detection to decision to
     restore to application smoke checks. Measured end to end, recorded as measured numbers, and compared with the
     owner's reconciled RTO.
  4. RPO measured as the age of the newest verified archive at a given moment, which the freshness signal already
     computes.

---

## 10. Historical backup compatibility

- Existing plain-SQL backups (`pre_auto_deploy_*.sql`, `pre_rollback_*.sql`, `auto_backup_*.sql`,
  `rehearsal_source_*.sql`), and the BK-2 packages, **stay restorable with the existing helpers**:
  - `restore_postgres.sh` with `--deploy-id` (via the unchanged `Pre-deploy backup:` line) or with
    `--expected-sha256`;
  - the verification-only `rollout-restore-drill.sh`.
- **They are never deleted and never converted by this transition.** Converting plain SQL to custom would mean
  replaying it into a database and dumping again. That is a restore of untrusted content and a new artefact with a
  different digest, which is exactly what A-1 forbids on the production host.
- **Interaction with the existing prune.** Until R4, the scheduled prune keeps applying its current `*.sql` rule, as
  it does today. That rule exists, it is not part of this change, and it is owner-approved ENT-12 behaviour. At R4,
  when plain production stops, the `*.sql` prune is **frozen**, so the last plain backups are kept indefinitely as the
  compatibility set. Deleting them later is a separate owner decision (OD-8). BK-2 never prunes.
- **The isolated drill does not accept plain-SQL backups.** `dms_archive_verify_copy` refuses any file without the
  `PGDMP` magic before a parser sees it (`restore-archive.sh:124-131`), and the TOC check requires `Format: CUSTOM`.
  No historical plain backup can be drilled. Drill evidence exists only for archives produced after R1.

---

## 11. Monitoring and alerting

| Signal | Today | Change |
|---|---|---|
| Latest-backup freshness (`FoundationMonitoringStatusService.php:324-370`, WATCH after 48 h, `config/foundation_monitoring.php:80`) | Newest file of any name | Filter on `*.sql`/`*.dump`, ignore `*.partial` and `checksums/`. **During R1–R2:** report both formats, and WATCH when either is older than 48 h or missing. **From R3:** `.dump` freshness drives the signal, and `.sql` is informational. |
| Developer console card (`DeveloperConsoleService.php:303-318`) | Newest five files | Same filter. Show the format. |
| Backup unit outcome | `Type=oneshot`, no `Restart=`, a failure leaves the unit `failed` (`*-db-backup.service:40-43`). `scripts/production-posture.sh:39-40` reports timer active/enabled. | Whether anything alerts on a `failed` unit is not determinable from the repository (§14 Q-12). Proposed: the posture and monitoring read the last run's result per format. |
| backup-verify decision | GO/WATCH/FAIL per file | Both formats are verified in R1–R2. A FAIL on `.dump` fails the run, as for `.sql`. |
| Digest record presence | The `Pre-deploy backup:` line | New: exactly one `Pre-deploy archive:` line per deploy from R1. A missing or duplicate line is a FAIL for drill eligibility. |
| pg_dump version | Not checked | The producer fails closed when it is not major 16 (T-2). The failure appears in the unit or deploy status. |
| Size and duration anomaly | None | WATCH when custom size or duration moves more than an owner-set percentage from its 7-day median (§5). |
| Drill evidence | Readiness does not read signed drill records (`roll-5-…runbook.md:65`). `restore_drill_evidence` stays WATCH (staleness 720 h, `config/rollout_readiness.php:242`). | Unchanged by this plan. Wiring readiness to drill records is the separate change OD-ISO-9. |
| BK-2 | `bk2-status.sh` levels and SLA (rule 230) | Unchanged. Never a gate (rule 232). |

Alerts, logs and evidence carry file names, sizes, digests, counts and durations only. They never carry rows, KTP/NIK,
credentials or environment-file contents (CG-11).

---

## 12. Rollback procedure

- **Mechanism.** Every phase is a code release, so rolling it back is a code rollback:
  - release the previous approved commit through the normal runner path (rule 229 or 236);
  - or, on the host, `scripts/rollback-vps.sh <commit>`. That is code only, never an automatic data restore, and it
    never deletes a backup or the rollback database (CG-15).
  - Before relying on `rollback-vps.sh`, settle §14 Q-15.
- **R1 → before R1.** Revert the second dump and the per-format prune. Plain production was never changed, so recovery
  capability is unaffected. `.dump` files already written are **kept**: the reverted `*.sql`-only prune no longer
  touches them, and nobody deletes them by hand (CG-15). Their later disposal is an owner decision. C-1 (`dump` allowed)
  may stay, because it is harmless.
- **R3 → R1 state.** Point evidence capture and monitoring back to `.sql`. The plain helper was never removed (§8), so
  DR returns to the proven path at once.
- **R4 → R3 state.** Restore the plain producer and un-freeze nothing: the frozen `.sql` set stays frozen.
- **Unit file.** If a phase changed the systemd unit (OD-4 identity, or the timeout), rollback reinstalls the previous
  unit text through the same privileged admin step. The deploy runner does not manage units.
- **Data.** No phase changes the database schema or data (no migration). Rollback never restores data.

---

## 13. Required production change approval (checklist)

**Owner decisions, recorded in the sprint doc before the step they govern (CG-22):**
- [ ] OD-1: approve this plan as the design of record.
- [ ] OD-2: parallel-phase length N (proposed 15 days) and the pass criteria in §5.
- [ ] OD-3: compression method and level (proposed built-in gzip, level 6, explicit).
- [ ] OD-4: trusted digest for the scheduled backup. Options: (a) a dedicated backup identity separate from the
  runtime, writing its digest to a root-owned record; (b) only deploy and rollback archives are drill-eligible; (c) the
  BK-2 manifest digest as the independent record.
- [ ] OD-5: sidecar location (proposed `checksums/` subdirectory) and format.
- [ ] OD-6: snapshot-sharing harness (Option A) versus explained-delta comparison (Option B).
- [ ] OD-7: custody of archives moved to the isolated drill host: channel, encryption in transit and at rest, retention
  and deletion.
- [ ] OD-8: `.dump` retention, and freezing the `*.sql` prune at R4.
- [ ] OD-9: custom DR helper design: `pg_restore -d` direct, versus `-f - --restrict-key` into restricted psql.
- [ ] OD-10: future of `restore-rehearsal.sh` (in-place superuser replay).
- [ ] OD-11: BK-2 package format (host-only).
- [ ] OD-12: retire or align `backup_postgres.sh`.
- [ ] OD-13: reconcile RTO (60 min versus 4 h).
- [ ] OD-14: monitoring thresholds (size and duration anomaly, disk-free stop threshold).
- [ ] OD-15: explicit go/no-go for each of R1, R2, R3 and R4 separately.

**Gates for every release (R1 to R4), as written in the worktree's `CLAUDE.md`:**
- [ ] CG-01/02/03: the work is cut from the remote base branch in its own worktree. Explicit `git add` paths only. No
  force-push, no `--no-verify`. PRs squash-merge.
- [ ] CG-17: required CI passes on the **exact candidate SHA**. Skipped is not passed. No gate, classifier or test is
  weakened, and no `paths-ignore` is added.
- [ ] CG-18: no Full Suite claim. Use focused `php artisan test --filter=…` and `sprint:test-plan`.
- [ ] CG-19: every new test file matches a `--filter` token in **both** runner variants. Critical suites are declared in
  `config/ci_runner.php`.
- [ ] CG-20: one test process per worktree. Database-sensitive checks run on PostgreSQL 16. Focused tests cover the
  happy path, validation, authorisation and failure paths: dump failure, version mismatch, empty dump, duplicate
  evidence line, prune floors per format.
- [ ] CG-14: before any production step, prove the target with `bash scripts/production-target-check.sh --remote` and
  proceed only on `PRODUCTION_TARGET=VERIFIED`. The pinned host key and `StrictHostKeyChecking yes` stay as they are,
  and a changed key is a stop. The frozen legacy host is never a target. Production checks stay read-only unless
  authorised. **Never run `php artisan tinker` or psysh.**
- [ ] CG-15, release:
  - deploy only with `bash scripts/deploy-vps-runner.sh start --release-id <id> --approved-sha <sha>`, on the host,
    through the operator entrypoint;
  - an approval for the exact SHA: a release approval record (rule 229) or an owner-signed `submit-approval` (rule 236);
  - push nothing to the base before `start`;
  - complete only when `verify <deploy-id>` reports `DEPLOY RUNNER OK` (status `exit=0` and log `DEPLOY OK`), never by
    reading `latest.status` raw;
  - never pre-pull or hand-edit the production tree;
  - rule 233/235: artisan runs as the runtime identity, and composer and npm run as the build identity;
  - if the environment file is edited, re-assert its owner and mode as CG-15 requires. This plan needs no
    environment-file change.
- [ ] **A backup before the change.** The runner's own pre-deploy backup is the before-change backup (and, from R1, its
  custom twin). Record its digest line. BK-2 freshness is **not** a gate (rule 232).
- [ ] CG-09: this change has no migration. In any case: `migrate --force` only after a backup, and **never**
  `migrate:fresh`, `db:wipe`, `schema:drop`, `migrate:reset` or destructive SQL on the VPS.
- [ ] Privileged host steps: unit reinstall and `daemon-reload` only if OD-4 or the timeout changes the unit. They are
  separate admin actions under the owner's break-glass procedure, never folded into a deploy.
- [ ] CG-11: evidence, logs, docs and chat carry no patient data, credentials or environment-file contents. Content
  checksums are digests only.
- [ ] CG-13: no fabricated records. Validation uses real archives for counts and digests only, and synthetic data
  only in tests.
- [ ] CG-16: a GO tag only after merge, deploy, production verification and the owner's explicit closure approval.
- [ ] CG-21: measured numbers only. "Pre-existing" claims are reproduced on the untouched base.
- [ ] CG-24: a sprint doc and a durable rule (`.cursor/rules/NNN-*.mdc` plus one line in the domain rule file). Docs
  in §2.5 that say "backups are plain-text" are updated in the same PR as the code they describe.

---

## 14. Risks and open questions

**Open questions (not determinable from the repository):**
- Q-1: the production database size, and the plain dump size today. These set the disk budget and the drill
  headroom (Q-8).
- Q-2: the PostgreSQL server minor version on production.
- Q-3: which `pg_dump` binary a bare `pg_dump` resolves to on the host (PATH, version-switching wrapper), for root
  (P-A, P-B) and for the runtime identity (P-C).
- Q-4: the host time zone, which `date +%Y%m%d-%H%M%S` stamps use.
- Q-5: what the BK-2 package contains (its own dump? which format? globals?).
- Q-6: whether the production volume is encrypted at rest.
- Q-7: whether host-side tooling prunes `<rollback-backup-dir>`, or anything outside the repository deletes backups.
- Q-8: whether production data fits the drill's 1024 MiB work-space cap (max(archive × 8, data × 4)). If not, the
  drill refuses every production archive until D6 limits change. That is an owner decision under R6-B6.
- Q-9: whether pg_dump of the production schema emits TOC entry types outside the drill allowlist (for example a
  `SCHEMA` or `DEFAULT ACL` entry after R6 changes schema ownership). Day 1 of §5 answers this.
- Q-10: transport and custody of archives from production to the isolated drill host.
- Q-11: whether the drill orchestrator removes its staged copy on every exit path.
- Q-12: whether anything alerts on a `failed` backup unit today.
- Q-13: current free disk space and backup growth rate on the host.
- Q-14: whether the environment overrides `BACKUP_DR_RETENTION_DAYS`, `BACKUP_DR_MIN_RETAINED` or the RTO/RPO values.
- Q-15: the reliability of the rollback path (`rollback-vps.sh`). It is a separate workstream (R6-B7, owner decision
  2026-11-25), tracked in the private evidence register; it is not part of this transition or of PR #695. Resolve it
  before any rollback is relied on and before the cutover.

**Risks:**
- R-1: **Evidence ambiguity in the parallel phase.** A second `Pre-deploy backup:` line would make every `--deploy-id`
  plain restore refuse (`restore-integrity.sh:60-64`). The mitigation is the distinct `Pre-deploy archive:` prefix,
  plus a test that asserts exactly one line of each.
- R-2: **Verify breaks the deploy.** Verifying a `.dump` before C-1 stops the deploy at `deploy-vps.sh:464` (after
  migrations) and stops the scheduled backup. The mitigation is to ship C-1 in the same release, before or with the
  producer change, with a test.
- R-3: **Unbounded growth.** The prune matches `*.sql` only. Without per-format pruning, `.dump` files accumulate.
- R-4: **Version drift.** An unpinned pg_dump that is not 16 produces archives the drill refuses (and could differ in
  output). The mitigation is T-2: pinned path and a version assert.
- R-5: **No trusted digest for scheduled archives** (P-C runs as the runtime identity). Until OD-4, only P-A and P-B
  archives are drill-eligible, so drill freshness depends on deploy cadence.
- R-6: **Monitoring false freshness.** Sidecars or `.partial` files in the backup directory would be reported as the
  latest backup. The mitigation is C-2 and the `checksums/` subdirectory.
- R-7: **Read exposure.** The runtime identity (the web process identity) can read every on-server backup. Unchanged
  by this plan. A dedicated backup identity (OD-4 a) would narrow it for P-C only.
- R-8: **In-place superuser replay remains** in `restore-rehearsal.sh` (unrestricted psql as the superuser on the
  production cluster), outside A-1's retirement. Not introduced here, but relevant to DR tooling (OD-10).
- R-9: **The drill hand-off is broken today.** `rollout-restore-drill.sh:335` points operators at a drill that refuses
  the file it just verified. It stays broken until custom archives exist and the script is updated.
- R-10: **Extra deploy time and load.** A second dump lengthens the pre-checkout phase of every deploy during R1–R2.
  Measure it, then accept or adjust.
- R-11: **Disk exhaustion** during the parallel phase (Q-1, Q-13). The mitigation is a pre-R1 capacity check and the
  stop threshold.
- R-12: **Vacuous guard test.** `BackupScriptTest::test_restore_script_uses_pg_restore` passes on a comment. Replace it
  with behavioural tests when the custom DR helper lands.
- R-13: **Doc drift.** Five documents state "plain-text" or `.sql` as the format (§2.5). They must change with the
  code, or readers will follow the wrong restore tool.
- R-14: **Drill line references drift.** The drill files were being edited by another session during this read. Re-check
  the references in §2.3 and §5 against the merged code before implementation.

## 15. Staged transition (directive "R6-B6 — Final Acceptance Closure", F5): status

The custom-format standard is approved **at the design level only**. Nothing below changes production.

| Step | Status | Evidence |
|---|---|---|
| 1. Inventory existing backup mechanisms | **Done** | §2 (producers, consumers, tests, documents) |
| 2. Implement and test custom-format support locally | **Done, not activated** | `scripts/backup-custom-format.sh` on the separate local branch `feature/r6-b6-backup-custom-format` (`61795750`, cut from the base, not pushed, not part of PR #695). It writes `pg_dump -Fc` 0600 from creation, verifies the PGDMP magic, a non-empty TOC and a sha256 sidecar, and writes a manifest. It is wired to no schedule, deploy, rollback or retention path; a test asserts that. `PrivateDatabaseDumpCustomFormatTest`: 10 tests, including a real dump on PostgreSQL 16. |
| 3. Validate synthetic backup restoration | **Done** | A backup of the migrated application schema (synthetic) made by that script, restored through the R6-B6 drill guest under a pinned environment contract: GO, 186/186 tables, 238/238 rows, environment digests equal (Evidence Pack 53). |
| 4. Parallel production backup procedure | **Prepared** (§5); needs owner approval | Producers P-A to P-C add a second `-Fc` dump beside the plain one; the plain dump stays authoritative until cutover. |
| 5. Checksum, encryption, retention, verification requirements | **Defined** (§7; sidecars as in step 2) | sha256 sidecar plus manifest for every archive; BK-2 encryption unchanged; per-format retention (the `*.sql` prune never touches `*.dump`); verification by `pg_restore --list` at creation and by the drill for restorability. |
| 6. RPO/RTO assumptions | **Audited; owner decision** | §9 and the RTO audit in Evidence Pack 53: 60 min (config, authoritative) against 4 h (draft runbook); no incident classes defined; no end-to-end recovery measured. |
| 7. Cutover acceptance criteria | **Defined below**; needs owner approval | — |
| 8. Rollback and historical compatibility | **Defined** (§10, §12) | Plain `.sql` backups stay restorable by the existing tooling; the plain dump continues until the cutover is accepted; nothing historical is deleted. |

**Cutover acceptance criteria** (all required, each evidenced):
- 30 consecutive days of parallel dumps, every `.dump` verified at creation;
- at least 3 drills of real production `-Fc` archives on the dedicated drill host with GO, each under a current signed
  environment contract, with the evidence verified off the host;
- the restore helpers, `foundation:backup-verify` and monitoring accept `.dump`, with tests (§4, §11);
- Q-15 resolved and released first (R6-B7, a separate workstream);
- RTO/RPO decided (OD-13), and the measured backup and restore times inside them;
- the owner's written approval of the cutover date. Plain dumps stop only then, and their retention is frozen, not
  pruned (§10).

