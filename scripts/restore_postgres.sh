#!/usr/bin/env bash
#
# DaengtisiaMS — PostgreSQL restore from a PLAIN-TEXT pg_dump backup.
#
# This is the explicit, operator-driven production data restore helper. It is
# NEVER invoked automatically: scripts/deploy-vps.sh and scripts/rollback-vps.sh
# only ever *print* it as the next manual step.
#
# ---------------------------------------------------------------------------
# WHY THIS FILE WAS REWRITTEN (B2 — the documented restore procedure was broken)
# ---------------------------------------------------------------------------
# The previous version could not restore a single one of this project's backups.
# Three independent defects, each sufficient on its own:
#
#   1. WRONG TOOL. Every backup this project produces is PLAIN-TEXT SQL:
#      scripts/backup-vps.sh and scripts/deploy-vps.sh both run
#      `pg_dump ... > file` with no --format, so the output is a psql script
#      (`file` reports "ASCII text"), it contains zero DROP statements, and it
#      opens with a `\restrict <token>` psql meta-command. `pg_restore` only
#      reads custom/directory/tar archives; pointed at a plain SQL file it fails
#      immediately. `--clean --if-exists` was also meaningless here — there is
#      nothing to clean in a plain dump — while making the command *look* like
#      it would wipe the target.
#
#   2. WRONG DEFAULTS. It defaulted to database `asia_dental_lab` and user
#      `postgres`. Neither is what this deployment uses; the real values are in
#      the application environment file (DB_DATABASE / DB_USERNAME). A restore
#      helper that defaults to a database which does not exist is not a
#      procedure, it is a dead end.
#
#   3. NO SAFETY AND WEAK FAILURE REPORTING. `set -e` alone, no pipefail, no
#      production guard, and an unconditional "Restore completed" echo. A
#      partially applied restore could print success.
#
# ---------------------------------------------------------------------------
# CANONICAL HANDLING OF `CREATE EXTENSION pg_stat_statements` (superuser-only)
# ---------------------------------------------------------------------------
# The dump carries the production database's extension, near the top:
#
#     CREATE EXTENSION IF NOT EXISTS pg_stat_statements WITH SCHEMA public;
#     COMMENT ON EXTENSION pg_stat_statements IS '...';
#
# Installing an extension requires superuser. The application role (DB_USERNAME)
# is deliberately NOT a superuser and MUST NOT be made one — it is the role a
# web request runs as.
#
# Two candidate workarounds were tested against a disposable database on the
# production host before choosing:
#
#   (a) "Pre-create the extension as superuser, then restore as the app role."
#       REJECTED — measured, not assumed. With the extension already present,
#       `CREATE EXTENSION IF NOT EXISTS` is indeed a no-op, but the very next
#       statement fails:
#           ERROR:  must be owner of extension pg_stat_statements
#       (COMMENT ON EXTENSION requires ownership, and the extension is owned by
#       the superuser that created it). The restore aborted after 2 tables.
#
#   (b) "Run psql as the local PostgreSQL superuser and pipe the dump in on
#       stdin from an account that can read it."  CHOSEN.
#
# So: the restore itself runs as the local `postgres` superuser over the peer
# socket. The dump is streamed on stdin rather than passed as a path, because
# backups are mode 0640 owned by the runtime user (daengtisiams) — the postgres
# OS account cannot open the file directly, but it can read a pipe. The restored
# extension then ends up owned by the superuser, exactly as in production.
#
# No superuser privilege is granted to the application role at any point.
#
# ---------------------------------------------------------------------------
# SAFETY
# ---------------------------------------------------------------------------
#   - Fail-fast: set -euo pipefail.
#   - This script NEVER drops or wipes anything. It creates a database only when
#     explicitly asked (--create) and otherwise only reads and inserts.
#   - Restoring into the production database is REFUSED unless
#     --force-production is passed AND the operator types the database name to
#     confirm (or passes --yes for an unattended, pre-approved run).
#   - Real exit-status propagation: ON_ERROR_STOP=1, pipefail, explicit
#     PIPESTATUS inspection, and a post-restore verification. Success is printed
#     only when the restore actually succeeded.
#
# ---------------------------------------------------------------------------
# USAGE
# ---------------------------------------------------------------------------
#   Restore into a NEW scratch/analysis database (safe, the common case):
#     sudo bash scripts/restore_postgres.sh \
#       --file storage/app/backups/deploy/<dump>.sql \
#       --target asia_dental_lab_scratch --create
#
#   Restore over PRODUCTION (disaster recovery only, irreversible):
#     sudo bash scripts/restore_postgres.sh \
#       --file storage/app/backups/deploy/<dump>.sql --force-production
#
#   Options:
#     --file <path>        Plain-text .sql dump to restore. May also be given as
#                          the first positional argument (legacy form).
#     --target <db>        Database to restore INTO. Default: DB_DATABASE from
#                          the application environment file (i.e. production),
#                          which then requires --force-production.
#     --owner <role>       Owner for a database created with --create.
#                          Default: DB_USERNAME from the environment file.
#     --create             Create the target database first (fails if it exists).
#     --force-production   Permit the target to be the production database.
#     --yes                Skip the interactive typed confirmation.
#     --no-single-transaction
#                          Apply the dump statement-by-statement instead of as
#                          one all-or-nothing transaction.
#     --superuser-account <os-user>
#                          Local OS account owning the PostgreSQL superuser role.
#                          Default: postgres (override with PG_SUPERUSER_ACCOUNT).
#     -h | --help          Show this usage text.
#
# Exit codes: 0 success · 1 restore/verification failure · 2 usage or refusal.
set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
ENV_FILE="${ENV_FILE:-${APP_DIR}/.env}"
PG_SUPERUSER_ACCOUNT="${PG_SUPERUSER_ACCOUNT:-postgres}"

die() {
    echo "FATAL: $*" >&2
    exit "${2:-1}"
}

usage() {
    sed -n '/^# USAGE$/,/^# Exit codes/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

# Read a single key from the application environment file WITHOUT sourcing it.
# Sourcing would execute arbitrary content and clobber shell state; this only
# ever reads the one key asked for and strips surrounding quotes.
env_value() {
    local key="$1" value
    [ -r "$ENV_FILE" ] || return 1
    value="$(sed -n "s/^[[:space:]]*${key}=//p" "$ENV_FILE" | head -n 1)" || return 1
    value="${value%$'\r'}"
    value="${value#\"}"
    value="${value%\"}"
    value="${value#\'}"
    value="${value%\'}"
    printf '%s' "$value"
}

# ---------------------------------------------------------------------------
# Arguments
# ---------------------------------------------------------------------------
DUMP_FILE=""
TARGET_DB=""
TARGET_OWNER=""
CREATE_TARGET=0
FORCE_PRODUCTION=0
ASSUME_YES=0
SINGLE_TRANSACTION=1

while [ "$#" -gt 0 ]; do
    case "$1" in
        --file) DUMP_FILE="${2:-}"; shift 2 ;;
        --file=*) DUMP_FILE="${1#*=}"; shift ;;
        --target) TARGET_DB="${2:-}"; shift 2 ;;
        --target=*) TARGET_DB="${1#*=}"; shift ;;
        --owner) TARGET_OWNER="${2:-}"; shift 2 ;;
        --owner=*) TARGET_OWNER="${1#*=}"; shift ;;
        --create) CREATE_TARGET=1; shift ;;
        --force-production) FORCE_PRODUCTION=1; shift ;;
        --yes|-y) ASSUME_YES=1; shift ;;
        --no-single-transaction) SINGLE_TRANSACTION=0; shift ;;
        --superuser-account) PG_SUPERUSER_ACCOUNT="${2:-}"; shift 2 ;;
        --superuser-account=*) PG_SUPERUSER_ACCOUNT="${1#*=}"; shift ;;
        -h|--help) usage; exit 0 ;;
        --*) usage >&2; die "unknown option: $1" 2 ;;
        *)
            # Legacy positional form: scripts/restore_postgres.sh <backup_file>
            if [ -z "$DUMP_FILE" ]; then DUMP_FILE="$1"; shift
            else usage >&2; die "unexpected argument: $1" 2
            fi
            ;;
    esac
done

cd "$APP_DIR"

[ -n "$DUMP_FILE" ] || { usage >&2; die "no backup file given" 2; }
[ -f "$DUMP_FILE" ] || die "backup file not found: ${DUMP_FILE}" 2
[ -s "$DUMP_FILE" ] || die "backup file is empty: ${DUMP_FILE}" 2

PRODUCTION_DB="$(env_value DB_DATABASE || true)"
[ -n "$PRODUCTION_DB" ] || die "could not read DB_DATABASE from ${ENV_FILE} (is it readable by $(id -un)?)" 2

TARGET_DB="${TARGET_DB:-$PRODUCTION_DB}"
if [ -z "$TARGET_OWNER" ]; then
    TARGET_OWNER="$(env_value DB_USERNAME || true)"
fi
[ -n "$TARGET_OWNER" ] || die "could not read DB_USERNAME from ${ENV_FILE}" 2

# ---------------------------------------------------------------------------
# Production guard
# ---------------------------------------------------------------------------
if [ "$TARGET_DB" = "$PRODUCTION_DB" ]; then
    if [ "$FORCE_PRODUCTION" -ne 1 ]; then
        cat >&2 <<GUARD
REFUSED: '${TARGET_DB}' is the PRODUCTION database for this deployment.

Restoring over it overwrites live clinical data and is not reversible by this
script. If that is genuinely what you intend, re-run with --force-production.

To restore into a scratch copy instead (almost always what you want):
  sudo bash scripts/restore_postgres.sh --file "${DUMP_FILE}" \\
    --target ${PRODUCTION_DB}_scratch --create
GUARD
        exit 2
    fi

    if [ "$ASSUME_YES" -ne 1 ]; then
        [ -t 0 ] || die "refusing an unattended production restore: pass --yes only when this has been explicitly approved" 2
        echo "About to restore ${DUMP_FILE} OVER THE PRODUCTION DATABASE '${TARGET_DB}'."
        printf "Type the database name to confirm: "
        read -r CONFIRMATION
        [ "$CONFIRMATION" = "$TARGET_DB" ] || die "confirmation did not match; nothing was changed" 2
    fi
    echo "!! PRODUCTION RESTORE CONFIRMED for '${TARGET_DB}'"
fi

# ---------------------------------------------------------------------------
# Superuser mediation (see the header for why this is required)
# ---------------------------------------------------------------------------
as_superuser() {
    if [ "$(id -un)" = "$PG_SUPERUSER_ACCOUNT" ]; then
        "$@"
    else
        sudo -n -u "$PG_SUPERUSER_ACCOUNT" -- "$@"
    fi
}

if ! SUPER_CHECK="$(as_superuser psql -X -Atqc "SELECT usesuper FROM pg_user WHERE usename = current_user" 2>/dev/null)"; then
    die "cannot reach PostgreSQL as OS account '${PG_SUPERUSER_ACCOUNT}'. Run this script as root (or as ${PG_SUPERUSER_ACCOUNT}), or pass --superuser-account." 2
fi
[ "$SUPER_CHECK" = "t" ] || die "role reached via '${PG_SUPERUSER_ACCOUNT}' is not a superuser; the dump's CREATE EXTENSION / COMMENT ON EXTENSION statements would fail" 2

# The dump is typically mode 0640 owned by the runtime user, so the postgres OS
# account cannot open it. Stream it on stdin from an account that can read it.
DUMP_OWNER="$(stat -c '%U' -- "$DUMP_FILE")"
read_dump() {
    if [ -r "$DUMP_FILE" ]; then
        cat -- "$DUMP_FILE"
    else
        sudo -n -u "$DUMP_OWNER" -- cat -- "$DUMP_FILE"
    fi
}

echo "== Restore plan =="
echo "  dump file   : ${DUMP_FILE} ($(stat -c '%s' -- "$DUMP_FILE") bytes, owner ${DUMP_OWNER})"
echo "  target db   : ${TARGET_DB}"
echo "  restored as : OS account ${PG_SUPERUSER_ACCOUNT} (PostgreSQL superuser, peer socket)"
echo "  create db   : $([ "$CREATE_TARGET" -eq 1 ] && echo "yes (owner ${TARGET_OWNER})" || echo "no")"
echo "  atomic      : $([ "$SINGLE_TRANSACTION" -eq 1 ] && echo "yes (single transaction)" || echo "no")"

if [ "$CREATE_TARGET" -eq 1 ]; then
    echo "== Create target database: ${TARGET_DB} =="
    # -T template0 so the restore does not inherit anything local from template1.
    as_superuser createdb -O "$TARGET_OWNER" -T template0 -- "$TARGET_DB"
fi

# Target must exist before we stream a dump into it.
if ! as_superuser psql -X -Atqc "SELECT 1 FROM pg_database WHERE datname = '${TARGET_DB}'" | grep -q '^1$'; then
    die "target database '${TARGET_DB}' does not exist (pass --create to create it)" 2
fi

# ---------------------------------------------------------------------------
# Restore
# ---------------------------------------------------------------------------
PSQL_ARGS=(-X -q -v ON_ERROR_STOP=1 -d "$TARGET_DB" -f -)
if [ "$SINGLE_TRANSACTION" -eq 1 ]; then
    PSQL_ARGS=(--single-transaction "${PSQL_ARGS[@]}")
fi

echo "== Restore (psql, plain-text dump on stdin) =="
RESTORE_START="$(date +%s)"
# errexit is suspended for exactly this pipeline so both members' statuses can
# be inspected; without that, a failure would abort before PIPESTATUS is read.
set +o errexit
# stdout is discarded (a plain dump echoes a result row per setval/SELECT);
# stderr is deliberately NOT redirected so psql errors stay visible.
read_dump | as_superuser psql "${PSQL_ARGS[@]}" > /dev/null
PIPE_STATUS=("${PIPESTATUS[@]}")
set -o errexit
RESTORE_SECONDS=$(( $(date +%s) - RESTORE_START ))

READER_RC="${PIPE_STATUS[0]:-1}"
PSQL_RC="${PIPE_STATUS[1]:-1}"

# psql first: when it stops on an error the reader is killed by SIGPIPE (141),
# which is a consequence of the real failure, not a separate one.
if [ "$PSQL_RC" -ne 0 ]; then
    die "restore FAILED (psql exit ${PSQL_RC}) — '${TARGET_DB}' is NOT a usable restore. See the psql error above."
fi
if [ "$READER_RC" -ne 0 ]; then
    die "restore FAILED: could not read the dump (exit ${READER_RC})"
fi

# ---------------------------------------------------------------------------
# Verify — a restore that produced no schema is a failed restore.
# ---------------------------------------------------------------------------
echo "== Verify restored database =="
TABLE_COUNT="$(as_superuser psql -X -Atqc \
    "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'" \
    -d "$TARGET_DB")"

if ! [ "${TABLE_COUNT:-0}" -ge 1 ] 2>/dev/null; then
    die "restore produced no tables in '${TARGET_DB}' — treating as FAILED"
fi

# Representative row counts. Each table is probed with to_regclass FIRST so an
# unexpected schema reports n/a instead of aborting the verification (a missing
# relation is a planner error, so it cannot be guarded inside a single query).
count_table() {
    local table="$1" exists
    exists="$(as_superuser psql -X -Atqc "SELECT to_regclass('public.${table}') IS NOT NULL" -d "$TARGET_DB" 2>/dev/null || echo f)"
    if [ "$exists" = "t" ]; then
        as_superuser psql -X -Atqc "SELECT count(*) FROM public.${table}" -d "$TARGET_DB" 2>/dev/null || echo "n/a"
    else
        echo "n/a"
    fi
}

ROW_SUMMARY="patients=$(count_table mst_patients) visits=$(count_table trx_clinic_visits) payments=$(count_table trx_rme_payments)"

echo "RESTORE OK: ${TABLE_COUNT} tables into '${TARGET_DB}' in ${RESTORE_SECONDS}s (${ROW_SUMMARY}) from ${DUMP_FILE}"
