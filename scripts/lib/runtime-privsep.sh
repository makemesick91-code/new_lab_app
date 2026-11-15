# shellcheck shell=bash
#
# DMS-DEPLOY-PRIVSEP-HARDENING-1 (rule 233) — privilege separation between the
# root deploy/rollback program and the application's runtime identity.
#
# Sourced (never executed) from the immutable snapshot by scripts/deploy-vps.sh
# and scripts/rollback-vps.sh, and by the tests. It is a member of
# config/deployment_entrypoint.php execution_closure.closure_members.
#
# THE DEFECT (OD-1 DEPLOY-PRIVSEP, rule 231 §8): the deploy ran PHP as root —
# composer's post-autoload-dump (`artisan package:discover`), `artisan migrate`,
# about fifty governance gates, and in a rollback the cache rebuild and the
# smoke — and it ran `chown -R`, `chmod -R` and `find -exec chmod` as root over
# storage/ and bootstrap/cache, which the runtime user owns. Root therefore:
#   * `require`d PHP the runtime can rewrite (bootstrap/cache/*.php, compiled
#     views) and unserialized cache entries the runtime can write;
#   * appended to log files, and redirected JSON evidence into paths, that the
#     runtime can replace with a symlink to any file on the host;
#   * chmod'ed paths the runtime can swap for a symlink between find's lstat and
#     chmod's path walk, and ran `chmod -R` on a private path the runtime can
#     replace with a symlink (chmod follows a symlink named on its command line).
# Code execution as the runtime user became root at the next deploy or rollback.
#
# THE RULES THIS LIBRARY ENFORCES:
#   1. Root never executes application PHP. Every artisan command runs as the
#      runtime user (dms_privsep_as_runtime); composer runs --no-scripts.
#   2. Root never opens, creates, truncates or chmods a path inside a runtime
#      tree. Content and modes there are written by the runtime user, who can
#      only ever reach files it already owns, whatever symlinks it plants.
#   3. Root changes ownership inside a runtime tree only for entries found
#      foreign-owned, with lchown run from inside the entry's own directory
#      (find -execdir: no path walk to redirect), and never through a hardlink,
#      a device node, a FIFO or a socket.
#   4. Before root writes into the source tree (git checkout, composer, npm run
#      build) it claims, top-down, every source entry another identity owns or
#      can write.
#   5. Drift is measured and gated: the run ends NOT GO while a runtime tree
#      holds a foreign entry or the source tree holds an entry another identity
#      owns or can write.
#
# Observed and trusted values (uids, names) are PARAMETERS, never read from the
# environment: an inherited variable must not be able to widen anything.

_dms_privsep_err() { echo "runtime-privsep: $*" >&2; }

# find -execdir refuses to run with a relative or empty PATH entry; root's
# ownership repair and source claim run with this fixed PATH instead.
_DMS_PRIVSEP_SAFE_PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

# ─── Running work as the runtime user ───────────────────────────────────────

# _dms_privsep_close_inherited_fds
# Closes every descriptor above 2 in the current (sub)shell, so a process
# started from it inherits only stdin, stdout and stderr.
_dms_privsep_close_inherited_fds() {
  local entry fd
  for entry in /proc/"$BASHPID"/fd/*; do
    fd="${entry##*/}"
    case "$fd" in '' | *[!0-9]*) continue ;; esac
    if [ "$fd" -gt 2 ] && [ "$fd" -lt 255 ]; then
      eval "exec ${fd}>&-" 2>/dev/null || true
    fi
  done
}

# dms_privsep_as_runtime <user> <command> [args...]
#
# Runs ONE command as the runtime user — directly when the caller already is
# that user, otherwise through runuser. Refuses an unknown account and any
# account with uid 0, so a mistaken or forged name can never make this root.
# umask 027: what the runtime creates during a deploy is not world-readable
# before the final normalization publishes the approved modes.
#
# The runtime process never receives one of root's descriptors: stdin is
# /dev/null, stdout and stderr are a pipe that a root `cat` copies to the
# caller's stdout (the root-only deploy log), and every other descriptor is
# closed. An inherited log descriptor could be truncated and rewritten by a
# compromised runtime (ftruncate works on an O_APPEND file); a pipe cannot.
# The command's own exit status is returned.
dms_privsep_as_runtime() {
  local user="${1:-}" uid
  local -a hop=()
  shift || true
  if [ -z "$user" ] || [ "$#" -eq 0 ]; then
    _dms_privsep_err "usage: dms_privsep_as_runtime <user> <command> [args...]"
    return 2
  fi
  uid="$(id -u -- "$user" 2>/dev/null || true)"
  if [ -z "$uid" ] || [ "$uid" = "0" ]; then
    _dms_privsep_err "refusing to run as '${user}': not an existing unprivileged account"
    return 2
  fi
  if [ "$(id -u)" != "$uid" ]; then
    hop=(runuser -u "$user" --)
  fi
  ( umask 027 && _dms_privsep_close_inherited_fds && exec "${hop[@]}" "$@" ) </dev/null 2>&1 | cat
  return "${PIPESTATUS[0]}"
}

# dms_privsep_as_runtime_fn <user> "<helper functions>" <function> [args...]
#
# Runs a shell FUNCTION of the snapshot as the runtime user. The snapshot is
# root-only (0700), so the runtime cannot source it: root serializes the
# definitions with `declare -f` (trusted bytes from the snapshot) and a fresh
# bash running as the runtime user executes them.
dms_privsep_as_runtime_fn() {
  local user="${1:-}" helpers="${2:-}" fn="${3:-}" defs name
  if [ "$#" -lt 3 ] || [ -z "$fn" ]; then
    _dms_privsep_err "usage: dms_privsep_as_runtime_fn <user> \"<helper functions>\" <function> [args...]"
    return 2
  fi
  shift 3
  defs="$(declare -f _dms_privsep_err)"
  # shellcheck disable=SC2086 # helpers is a space-separated list of names
  for name in $helpers "$fn"; do
    if ! declare -F "$name" >/dev/null 2>&1; then
      _dms_privsep_err "unknown function '${name}'"
      return 2
    fi
    defs+=$'\n'"$(declare -f "$name")"
  done
  dms_privsep_as_runtime "$user" bash -c "set -euo pipefail"$'\n'"${defs}"$'\n''"$@"' dms-privsep "$fn" "$@"
}

# dms_privsep_runtime_output <user> <file> <command> [args...]
#
# Runs a command as the runtime user with its stdout written to <file>. The
# redirect is opened BY THE RUNTIME USER: root never opens a path inside a
# runtime tree, so a symlink planted at <file> reaches only what the runtime
# could already write.
dms_privsep_runtime_output() {
  local user="${1:-}" out="${2:-}"
  if [ "$#" -lt 3 ] || [ -z "$out" ]; then
    _dms_privsep_err "usage: dms_privsep_runtime_output <user> <file> <command> [args...]"
    return 2
  fi
  shift 2
  # shellcheck disable=SC2016 # expanded by the runtime user's bash, not here
  dms_privsep_as_runtime "$user" bash -c 'out="$1"; shift; exec "$@" > "$out"' dms-privsep-output "$out" "$@"
}

# ─── Root-side preconditions and repairs ────────────────────────────────────

# dms_privsep_kernel_guards [sysctl directory]
#
# fs.protected_hardlinks=1 stops the runtime from hardlinking a file it does not
# own into its tree, which is what makes the lchown repair below safe;
# fs.protected_symlinks=1 is the matching baseline for root's own use of
# world-writable sticky directories. Both are Ubuntu defaults; a host without
# them is NOT GO.
dms_privsep_kernel_guards() {
  local dir="${1:-/proc/sys/fs}" key value rc=0
  for key in protected_hardlinks protected_symlinks; do
    value="$(cat -- "${dir}/${key}" 2>/dev/null || true)"
    if [ "$value" = "1" ]; then
      echo "DEPLOY_PRIVSEP_KERNEL_${key^^}=1"
    else
      _dms_privsep_err "fs.${key} is '${value:-unreadable}', expected 1 — NOT GO"
      rc=1
    fi
  done
  return "$rc"
}

# _dms_privsep_trusted_dir <trusted uid> <dir>
# A directory nobody but <trusted uid> can replace or write into.
_dms_privsep_trusted_dir() {
  local trusted="$1" dir="$2" mode
  if [ -L "$dir" ] || [ ! -d "$dir" ]; then
    _dms_privsep_err "${dir} is not a real directory"
    return 1
  fi
  if [ "$(stat -c '%u' -- "$dir")" != "$trusted" ]; then
    _dms_privsep_err "${dir} is owned by uid $(stat -c '%u' -- "$dir"), expected ${trusted}"
    return 1
  fi
  mode="$(stat -c '%a' -- "$dir")"
  if [ $(( 8#${mode} & 8#022 )) -ne 0 ]; then
    _dms_privsep_err "${dir} is group- or world-writable (mode ${mode})"
    return 1
  fi
  return 0
}

# dms_privsep_runtime_roots <trusted uid> <user> <group> <relative dir>...
#
# Run from the application directory. A runtime root (storage, bootstrap/cache)
# must be a real directory and every directory above it, up to the application
# directory, must be one only <trusted uid> can write: then nobody but root can
# replace the root, and the NON-recursive lchown below cannot be redirected.
# A missing root is created first, inside that trusted parent.
dms_privsep_runtime_roots() {
  local trusted="${1:-}" user="${2:-}" group="${3:-}" rel parent current
  if [ "$#" -lt 4 ]; then
    _dms_privsep_err "usage: dms_privsep_runtime_roots <trusted uid> <user> <group> <relative dir>..."
    return 2
  fi
  shift 3
  _dms_privsep_trusted_dir "$trusted" . || return 1
  for rel in "$@"; do
    case "/${rel}/" in
      //|*/../*|*/./*|*//*) _dms_privsep_err "invalid runtime root '${rel}'"; return 1 ;;
    esac
    case "$rel" in /*) _dms_privsep_err "runtime root '${rel}' must be relative"; return 1 ;; esac
    parent="$(dirname -- "$rel")"
    while [ "$parent" != "." ]; do
      _dms_privsep_trusted_dir "$trusted" "$parent" || return 1
      parent="$(dirname -- "$parent")"
    done
    if [ -L "$rel" ]; then
      _dms_privsep_err "runtime root ${rel} is a symlink — NOT GO"
      return 1
    fi
    if [ ! -e "$rel" ]; then
      mkdir -- "$rel" || { _dms_privsep_err "cannot create runtime root ${rel}"; return 1; }
    fi
    if [ ! -d "$rel" ]; then
      _dms_privsep_err "runtime root ${rel} is not a directory — NOT GO"
      return 1
    fi
    current="$(stat -c '%U:%G' -- "$rel")"
    if [ "$current" != "${user}:${group}" ]; then
      chown -h -- "${user}:${group}" "$rel" || { _dms_privsep_err "cannot hand ${rel} to ${user}"; return 1; }
      echo "DEPLOY_PRIVSEP_RUNTIME_ROOT_CLAIMED=${rel} (was ${current})"
    fi
  done
}

# The runtime trees are live: PHP-FPM creates and deletes session and cache
# files while a deploy walks them. Every walk below uses -ignore_readdir_race so
# an entry that vanishes mid-walk is not an error (GNU find exits 1 otherwise,
# which would stop a deploy after its migration).

# dms_privsep_foreign_count <user> <group> <tree>...
# Read-only: the number of entries in the trees not owned by <user>:<group>.
dms_privsep_foreign_count() {
  local user="$1" group="$2"
  shift 2
  find -P "$@" -ignore_readdir_race \( ! -user "$user" -o ! -group "$group" \) -printf 'x' | wc -c
}

# dms_privsep_foreign_summary <user> <group> <tree>...
# Read-only: foreign entries grouped by owner, type and their first three path
# segments. Never prints a file name: names in storage can carry personal data.
dms_privsep_foreign_summary() {
  local user="$1" group="$2"
  shift 2
  find -P "$@" -ignore_readdir_race \( ! -user "$user" -o ! -group "$group" \) -printf '%u:%g %y %h\n' \
    | awk '{ n = split($3, s, "/"); d = s[1]; for (i = 2; i <= n && i <= 3; i++) d = d "/" s[i]; print $1, $2, d }' \
    | sort | uniq -c
}

# dms_privsep_repair_runtime_ownership <user> <group> <tree>...
#
# Hands foreign entries of the runtime trees (left by an older root deploy, a
# git checkout of a tracked file, or an operator) to the runtime user. Only
# directories, symlinks and single-link regular files: a hardlinked file could
# be someone else's inode, and a device node, FIFO or socket has no business
# there — any of those stops the run for a human. Each entry is lchowned (-h:
# a symlink is never followed) from inside its own directory (-execdir: find
# holds that directory open, so no path component can be swapped).
dms_privsep_repair_runtime_ownership() {
  local user="${1:-}" group="${2:-}" refused before after
  if [ "$#" -lt 3 ]; then
    _dms_privsep_err "usage: dms_privsep_repair_runtime_ownership <user> <group> <tree>..."
    return 2
  fi
  shift 2
  refused="$(find -P "$@" -ignore_readdir_race \( ! -user "$user" -o ! -group "$group" \) \
    \( \( -type f -links +1 \) -o \( ! -type f ! -type d ! -type l \) \) -printf 'x' | wc -c)" || return 1
  if [ "$refused" -ne 0 ]; then
    _dms_privsep_err "${refused} foreign entr(ies) in the runtime trees are hardlinked files, device nodes, FIFOs or sockets — refusing to hand them to ${user}; inspect by hand — NOT GO"
    return 1
  fi
  before="$(dms_privsep_foreign_count "$user" "$group" "$@")" || return 1
  echo "DEPLOY_PRIVSEP_RUNTIME_FOREIGN_BEFORE_REPAIR=${before}"
  if [ "$before" -eq 0 ]; then
    return 0
  fi
  dms_privsep_foreign_summary "$user" "$group" "$@" | sed 's/^/  foreign: /'
  # An entry that vanishes between the walk and its chown makes chown, and so
  # find, fail; the recount below is what decides.
  PATH="$_DMS_PRIVSEP_SAFE_PATH" find -P "$@" -ignore_readdir_race \( ! -user "$user" -o ! -group "$group" \) \
    \( -type d -o -type l -o \( -type f -links 1 \) \) \
    -execdir chown -h -- "${user}:${group}" {} + || true
  after="$(dms_privsep_foreign_count "$user" "$group" "$@")" || return 1
  if [ "$after" -ne 0 ]; then
    _dms_privsep_err "${after} foreign entr(ies) remain in the runtime trees after the repair — NOT GO"
    return 1
  fi
  echo "DEPLOY_PRIVSEP_RUNTIME_REPAIRED=${before}"
}

# ─── Runtime-side mode normalization (runs AS the runtime user) ─────────────

# dms_privsep_normalize_modes <private path>...
#
# MUST run as the runtime user (dms_privsep_as_runtime_fn), from the application
# directory; it refuses root. The runtime can only change the modes of files it
# owns, so a symlink planted anywhere in these trees reaches nothing it could
# not already change — which is why this no longer runs as root.
#   storage + bootstrap/cache, except the backup tree and the private paths:
#       directories 2775, files 0664 (setgid keeps the runtime group); never 0777
#   storage/app/backups: directories 2750, files 0640 (AIR-DEP-14)
#   every declared private path: owner rw (u+rwX), nothing for "other" (o-rwx),
#       group bits as found — private trees are never widened (rule 209)
dms_privsep_normalize_modes() {
  local rel tree
  local -a prune=()
  if [ "$(id -u)" = "0" ]; then
    _dms_privsep_err "refusing to normalize runtime modes as root (rule 233) — NOT GO"
    return 1
  fi
  for rel in "$@"; do
    case "/${rel}/" in
      //|*/../*|*/./*|*//*) _dms_privsep_err "invalid private path '${rel}'"; return 1 ;;
    esac
    case "$rel" in /*) _dms_privsep_err "private path '${rel}' must be relative"; return 1 ;; esac
    prune+=(-path "$rel" -prune -o)
  done
  for tree in storage bootstrap/cache storage/app/backups "$@"; do
    if [ -L "$tree" ]; then
      _dms_privsep_err "${tree} is a symlink — NOT GO"
      return 1
    fi
  done
  # One chmod per entry (`\;`), as before rule 233: PHP-FPM deletes session and
  # cache files while this runs, and a vanished entry must fail only its own
  # chmod (a false test for find), never the whole deploy.
  find storage bootstrap/cache -ignore_readdir_race -path storage/app/backups -prune -o "${prune[@]}" -type d -exec chmod 2775 {} \;
  find storage bootstrap/cache -ignore_readdir_race -path storage/app/backups -prune -o "${prune[@]}" -type f -exec chmod 0664 {} \;
  if [ -d storage/app/backups ]; then
    find storage/app/backups -ignore_readdir_race -type d -exec chmod 2750 {} +
    find storage/app/backups -ignore_readdir_race -type f -exec chmod 0640 {} +
  fi
  for rel in "$@"; do
    [ -d "$rel" ] || continue
    chmod -R u+rwX,o-rwx -- "$rel"
  done
}

# ─── Source tree: root's own, top-down ──────────────────────────────────────

# _dms_privsep_source_find <trusted uid> <trusted gid> [find actions...]
#
# The source side is the application directory minus the runtime trees and the
# environment files (rule 100 owns those: root:<runtime group> 0640). Matches
# every entry another identity owns or can write (symlink modes are ignored).
_dms_privsep_source_find() {
  local uid="$1" gid="$2"
  shift 2
  find -P . -xdev \
    \( -path ./storage -o -path ./bootstrap/cache -o -path ./.env -o -path './.env.*' \) -prune -o \
    \( ! -uid "$uid" -o ! -gid "$gid" -o \( -perm /022 ! -type l \) \) "$@"
}

# dms_privsep_source_foreign_count <trusted uid> <trusted gid>   (read-only)
dms_privsep_source_foreign_count() {
  _dms_privsep_source_find "$1" "$2" -printf 'x' | wc -c
}

# dms_privsep_source_foreign_summary <trusted uid> <trusted gid>   (read-only)
dms_privsep_source_foreign_summary() {
  _dms_privsep_source_find "$1" "$2" -printf '%u:%g %#m %y %h\n' \
    | awk '{ n = split($4, s, "/"); d = s[1]; for (i = 2; i <= n && i <= 3; i++) d = d "/" s[i]; print $1, $2, $3, d }' \
    | sort | uniq -c
}

# dms_privsep_claim_source_tree <trusted uid> <trusted gid>
#
# Run from the application directory, as root, BEFORE root writes there. Every
# source entry another identity owns or can write is claimed: lchown to the
# trusted owner, and "group/other write" stripped (never on a symlink). find
# visits a directory — and the synchronous -exec runs — before it reads that
# directory's entries, so by the time an entry is claimed every directory above
# it is already root's and no other identity can swap it. A hardlinked file, a
# device node, a FIFO or a socket among them stops the run for a human: claiming
# a hardlink would take over whatever other path shares that inode.
dms_privsep_claim_source_tree() {
  local uid="${1:-}" gid="${2:-}" before refused after
  if [ -z "$uid" ] || [ -z "$gid" ]; then
    _dms_privsep_err "usage: dms_privsep_claim_source_tree <trusted uid> <trusted gid>"
    return 2
  fi
  refused="$(_dms_privsep_source_find "$uid" "$gid" \
    \( \( -type f -links +1 \) -o \( ! -type f ! -type d ! -type l \) \) -printf 'x' | wc -c)" || return 1
  if [ "$refused" -ne 0 ]; then
    _dms_privsep_err "${refused} source entr(ies) another identity owns or can write are hardlinked files, device nodes, FIFOs or sockets — refusing to claim them; inspect by hand — NOT GO"
    return 1
  fi
  before="$(dms_privsep_source_foreign_count "$uid" "$gid")" || return 1
  echo "DEPLOY_PRIVSEP_SOURCE_FOREIGN_BEFORE_CLAIM=${before}"
  if [ "$before" -eq 0 ]; then
    return 0
  fi
  dms_privsep_source_foreign_summary "$uid" "$gid" | sed 's/^/  source: /'
  # shellcheck disable=SC2016 # expanded by the per-entry bash, not here
  PATH="$_DMS_PRIVSEP_SAFE_PATH" _dms_privsep_source_find "$uid" "$gid" \
    -exec bash -c 'chown -h -- "$1:$2" "$3" && { [ -L "$3" ] || chmod go-w -- "$3"; }' \
      dms-privsep-claim "$uid" "$gid" {} \;
  after="$(dms_privsep_source_foreign_count "$uid" "$gid")" || return 1
  if [ "$after" -ne 0 ]; then
    _dms_privsep_err "${after} source entr(ies) are still owned or writable by another identity after the claim — NOT GO"
    return 1
  fi
  echo "DEPLOY_PRIVSEP_SOURCE_CLAIMED=${before}"
}

# dms_privsep_assert_trusted_file <trusted uid> <file>
#
# A file root is about to source (the environment file): a regular file, not a
# symlink, owned by <trusted uid>, that no group or other identity can write.
# A file another identity could have written is never sourced by root — its
# content cannot be trusted even after its mode is repaired. Read-only.
dms_privsep_assert_trusted_file() {
  local trusted="${1:-}" file="${2:-}" mode
  if [ -z "$trusted" ] || [ -z "$file" ]; then
    _dms_privsep_err "usage: dms_privsep_assert_trusted_file <trusted uid> <file>"
    return 2
  fi
  if [ -L "$file" ] || [ ! -f "$file" ]; then
    _dms_privsep_err "${file} is not a regular file — root will not source it — NOT GO"
    return 1
  fi
  if [ "$(stat -c '%u' -- "$file")" != "$trusted" ]; then
    _dms_privsep_err "${file} is owned by uid $(stat -c '%u' -- "$file"), expected ${trusted} — root will not source it — NOT GO"
    return 1
  fi
  mode="$(stat -c '%a' -- "$file")"
  if [ $(( 8#${mode} & 8#022 )) -ne 0 ]; then
    _dms_privsep_err "${file} is group- or world-writable (mode ${mode}); another identity may have changed it — root will not source it; inspect by hand — NOT GO"
    return 1
  fi
  echo "DEPLOY_PRIVSEP_TRUSTED_FILE=${file##*/}"
}

# ─── The gates ──────────────────────────────────────────────────────────────

# dms_privsep_prepare <trusted uid> <trusted gid> <user> <group>
#
# Before the first mutation of a deploy or rollback: kernel preconditions, the
# two runtime roots, foreign entries of the runtime trees repaired, the source
# tree claimed for root.
dms_privsep_prepare() {
  local uid="${1:-}" gid="${2:-}" user="${3:-}" group="${4:-}"
  if [ "$#" -ne 4 ]; then
    _dms_privsep_err "usage: dms_privsep_prepare <trusted uid> <trusted gid> <user> <group>"
    return 2
  fi
  dms_privsep_kernel_guards || return 1
  dms_privsep_runtime_roots "$uid" "$user" "$group" storage bootstrap/cache || return 1
  dms_privsep_repair_runtime_ownership "$user" "$group" storage bootstrap/cache || return 1
  dms_privsep_claim_source_tree "$uid" "$gid" || return 1
}

# dms_privsep_final_gate <trusted uid> <trusted gid> <user> <group>
#
# The last privilege gate before DEPLOY OK / ROLLBACK OK: no foreign entry left
# in the runtime trees, no source entry another identity owns or can write.
dms_privsep_final_gate() {
  local uid="${1:-}" gid="${2:-}" user="${3:-}" group="${4:-}" runtime_foreign source_foreign
  if [ "$#" -ne 4 ]; then
    _dms_privsep_err "usage: dms_privsep_final_gate <trusted uid> <trusted gid> <user> <group>"
    return 2
  fi
  runtime_foreign="$(dms_privsep_foreign_count "$user" "$group" storage bootstrap/cache)"
  source_foreign="$(dms_privsep_source_foreign_count "$uid" "$gid")"
  echo "DEPLOY_PRIVSEP_RUNTIME_FOREIGN_ENTRIES=${runtime_foreign}"
  echo "DEPLOY_PRIVSEP_SOURCE_FOREIGN_ENTRIES=${source_foreign}"
  if [ "$runtime_foreign" -ne 0 ] || [ "$source_foreign" -ne 0 ]; then
    dms_privsep_foreign_summary "$user" "$group" storage bootstrap/cache | sed 's/^/  foreign: /' >&2
    dms_privsep_source_foreign_summary "$uid" "$gid" | sed 's/^/  source: /' >&2
    _dms_privsep_err "privilege drift after the run — NOT GO"
    return 1
  fi
  echo "DEPLOY_PRIVSEP=ENFORCED"
}
