# shellcheck shell=bash
#
# SECURITY-FIX-DEPLOY-BACKUP-FILE-PERMISSIONS-1 — private database dump creation.
#
# This file is SOURCED (never executed) by every script that writes a database
# dump: scripts/deploy-vps.sh, scripts/rollback-vps.sh, scripts/backup-vps.sh
# and scripts/backup_postgres.sh. It is the ONE place a dump file is created, so
# the creation mode cannot drift between those paths again.
#
# THE INVARIANT: a database dump is private FROM THE INSTANT ITS FILE EXISTS.
#
# The defect this replaces was `pg_dump ... > "$BACKUP"` followed by
# `chmod 0640 "$BACKUP"`. The shell opens (creates) the redirect target BEFORE
# pg_dump runs, using the caller's umask — 022 for root's deploy shell and for a
# systemd service with no UMask= — so the inode was born 0644 and stayed that
# way for the whole dump. A post-write chmod narrows a window; it cannot close
# one. The scheduled ENT-12 backup path did not even have the chmod.
#
# How the window is closed instead:
#   1. `umask 077` is applied in a SUBSHELL that wraps only the dump, so the
#      redirect itself creates the file 0600. The umask never leaks to the rest
#      of the deploy (composer/npm/cache artifacts keep their normal modes).
#   2. The dump is written to "<dest>.partial" under bash `noclobber`, which
#      opens with O_CREAT|O_EXCL: a pre-planted file or symlink at that name
#      is refused instead of followed or truncated.
#   3. A failed or empty dump removes the partial file — and even before the
#      removal it was 0600, so a crash leaves nothing readable.
#   4. The complete, non-empty dump is renamed into place atomically, KEEPING its
#      creation mode 0600. There is deliberately no chmod here: deploy/rollback
#      run as root inside a runtime-owned directory, and a path-based chmod after
#      the write could be redirected through a symlink the runtime user swapped
#      in. The deploy's storage normalization (restrict_backup_tree) later grants
#      the runtime group read (0640) over the whole tree; the scheduled backup,
#      which runs AS the runtime user, needs no group read at all.
#   5. A directory carrying a DEFAULT ACL is refused: a default ACL replaces the
#      umask in the kernel's create-mode calculation, so umask 077 would no
#      longer guarantee anything there.
#
# Nothing here prints dump contents or credentials. The password is passed by
# the caller as PGPASSWORD in the environment of the single dump command, never
# as an argument.

# Modes are LITERALS below, deliberately not variables: callers source the
# application environment file (set -a) around this helper, and nothing supplied
# through the environment may ever widen a dump or its directory.
#   backup directory: 2750 — no access for "other", not even traverse; setgid
#                     keeps new files in the runtime group.
#   dump file:        0600 at creation and at publication (umask 077).

_dms_dump_err() { echo "private-db-dump: $*" >&2; }

# Refuse a directory whose default ACL could override the umask.
_dms_dump_assert_no_default_acl() {
  local dir="$1" acl
  command -v getfacl >/dev/null 2>&1 || return 0
  acl="$(getfacl --default --omit-header --absolute-names -- "$dir" 2>/dev/null || true)"
  if [ -n "$(printf '%s' "$acl" | tr -d '[:space:]')" ]; then
    _dms_dump_err "refusing ${dir}: it carries a default ACL, which overrides the umask for new files"
    return 1
  fi
  return 0
}

# Create (if needed) and restrict a backup directory. Fails closed if the
# directory still grants "other" any access afterwards.
dms_prepare_private_backup_dir() {
  local dir="$1" mode
  if [ -z "$dir" ]; then
    _dms_dump_err "no backup directory given"
    return 1
  fi
  if [ -L "$dir" ]; then
    _dms_dump_err "refusing ${dir}: backup directory must not be a symlink"
    return 1
  fi
  ( umask 077 && mkdir -p -- "$dir" ) || return 1
  chmod 2750 -- "$dir" 2>/dev/null || true
  mode="$(stat -c '%a' -- "$dir")"
  if [ $(( 8#${mode} & 0007 )) -ne 0 ]; then
    _dms_dump_err "backup directory ${dir} still grants 'other' access (mode ${mode})"
    return 1
  fi
  _dms_dump_assert_no_default_acl "$dir"
}

# dms_write_private_dump <destination> <command> [args...]
#
# Runs <command> with its stdout redirected into a file that is created 0600,
# then publishes it at <destination> with the approved final mode. Returns
# non-zero (and leaves no partial file behind) if the command fails or produces
# nothing.
dms_write_private_dump() {
  local dest="$1"
  shift || true
  if [ -z "$dest" ] || [ "$#" -eq 0 ]; then
    _dms_dump_err "usage: dms_write_private_dump <destination> <command> [args...]"
    return 2
  fi

  local dir partial mode
  dir="$(dirname -- "$dest")"
  partial="${dest}.partial"

  if [ ! -d "$dir" ]; then
    _dms_dump_err "backup directory does not exist: ${dir}"
    return 1
  fi
  _dms_dump_assert_no_default_acl "$dir" || return 1

  if [ -L "$partial" ] || [ -L "$dest" ]; then
    _dms_dump_err "refusing to write through a symlink at ${partial} or ${dest}"
    return 1
  fi
  # A partial left by an earlier crash was itself created 0600; remove it so the
  # exclusive create below can succeed.
  if [ -e "$partial" ]; then
    rm -f -- "$partial"
  fi

  if ! ( umask 077 && set -o noclobber && "$@" > "$partial" ); then
    rm -f -- "$partial"
    _dms_dump_err "dump command failed; partial output removed"
    return 1
  fi

  if [ ! -s "$partial" ]; then
    rm -f -- "$partial"
    _dms_dump_err "dump command produced no output; refusing an empty backup"
    return 1
  fi

  # Check the mode BEFORE publishing (stat does not follow a symlink), so an
  # unsafe file is never moved into place.
  mode="$(stat -c '%a' -- "$partial")"
  if [ -L "$partial" ] || [ $(( 8#${mode} & 0077 )) -ne 0 ]; then
    rm -f -- "$partial"
    _dms_dump_err "partial dump is not a private regular file (mode ${mode}); refusing to publish"
    return 1
  fi

  # Explicit status checks, not `set -e`: the helper must stay correct even when
  # a caller invokes it inside an `if` or `||`, where errexit is suspended.
  if ! mv -f -T -- "$partial" "$dest"; then
    rm -f -- "$partial"
    _dms_dump_err "could not publish ${dest}"
    return 1
  fi
  return 0
}
