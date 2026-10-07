# SECURITY-FIX-DEPLOY-BACKUP-FILE-PERMISSIONS-1 — Database dumps private from creation

**Type:** SECURITY_FIX · **Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
@ `04cfec70` · **Rule:** `.cursor/rules/176-private-database-dump-creation.mdc`

No migration, no permission, no route, no schema, no application logic change.

## The invariant

> A database dump is private from the instant its file exists — not merely after a `chmod`.

## Root cause (from source, then measured)

Four scripts create database dumps. All created the file under the CALLER's umask:

| Writer | Creation | Post-step | Run as / umask |
|---|---|---|---|
| `scripts/deploy-vps.sh` (pre-deploy) | `pg_dump ... > "$BACKUP"` | `chmod 0640` after the dump | root, 022 |
| `scripts/rollback-vps.sh` (pre-rollback) | `pg_dump ... > "$BACKUP"` | `chmod 0640` after the dump | root, 022 |
| `scripts/backup-vps.sh` (scheduled ENT-12, systemd) | `pg_dump ... > "$BACKUP"` | **none** | runtime user, systemd default UMask 0022 |
| `scripts/backup_postgres.sh` (manual) | `pg_dump -f file` | **none** | operator, 022 |

The shell opens (creates) a redirect target BEFORE the command runs, and
`pg_dump -f` creates its own file — so the inode was born `0644` and stayed that
way for the whole dump. A trailing `chmod` shortens that window; it cannot close
it. The scheduled path never narrowed it at all.

**A second, deploy-wide window.** `normalize_runtime_ownership` (deploy, and the
inline copy in rollback) ran `find storage ... -type f -exec chmod 0664` and
`-type d -exec chmod 2775` over the WHOLE storage tree — backups included — and
only then `restrict_private_paths` stripped "other". Twice per deploy, every dump
on the host was `0664` inside a `2775` directory for the duration of the find.

**What limited the exposure in practice (stated, not used as an excuse).**
`storage/app/backups` is in `DMS_PRIVATE_PATHS`, so between deploys the backup
directory itself carries no "other" bits and a non-group account cannot traverse
into it — a `0644` file inside a `2770` directory is not reachable. The defect is
real regardless: file privacy depended on a directory mode that the deploy itself
re-widened, and the file had no protection of its own.

## Fix

New shared helper `scripts/lib/private-db-dump.sh` — the only code that creates a dump:

1. `umask 077` in a subshell that wraps ONLY the dump — the redirect creates the
   file `0600`; the umask never leaks to composer/npm/cache artifacts (asserted).
2. Writes `<dest>.partial` under bash `noclobber` (O_CREAT|O_EXCL): a pre-planted
   file or symlink is refused, never followed or truncated.
3. Failure or empty output removes the partial; even while it existed it was `0600`.
4. Checks the partial is still a private regular file, then publishes it
   **unchanged at `0600`** by atomic rename. No `chmod` after the write (see
   security review). Modes are literals, never environment-overridable.
5. `dms_prepare_private_backup_dir` sets backup directories to `2750` and refuses
   a symlinked directory or one carrying a default ACL (a default ACL replaces
   the umask in the kernel's create-mode calculation).

All four writers use it; the password still reaches pg_dump only as `PGPASSWORD`
in that one command's environment. Deploy and rollback normalization now
**prune** `storage/app/backups` from the 2775/0664 widening and set the tree to
2750/0640 directly. The helper is sourced from the DEPLOY-HARDEN-1 immutable
snapshot (`${DEPLOY_TOOLS_DIR}/scripts/lib/...`), which archives the whole
`scripts/` tree, so a rollback to an older ref still dumps privately.

## Regression

`tests/Feature/Deploy/PrivateDatabaseDumpCreationTest.php` (18 tests). The
stand-in dump command stats ITS OWN stdout through `/proc/$BASHPID/fd/1` before
writing a byte, reporting the mode any other account would have seen at that
moment. A control test proves the probe is real: the old pattern reports `644`
at creation while its final mode reads `0640` — which is exactly why a
final-mode check could never catch this defect. Covered: hostile umask `000` and
deploy umask `022`, final mode, no umask leak, failure cleanup, empty refusal,
pre-planted symlink, stale partial, directory restriction, symlinked directory,
default ACL, and static routing of every `pg_dump` block through the helper.

Mutation (revert by copy): removing the umask → 3 failed; final mode 0644 → 5;
accepting empty output → 1; old redirect in `backup-vps.sh` → 1; unpruned deploy
normalization → 1. Zero survivors.

`SecretFilePermissionHardeningTest` "tighten a dump the moment it is created"
pinned the retired redirect-then-chmod pattern; it is **superseded in place**
(not deleted) by "create a dump private instead of tightening it afterwards".

The suite is selected by a `PrivateDatabaseDump` token in BOTH critical-gate
variants and declared in `config/ci_runner.php` `critical_gate_mandatory_suites`.
