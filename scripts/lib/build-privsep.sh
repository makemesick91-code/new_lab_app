# shellcheck shell=bash
#
# DMS-DEPLOY-PRIVSEP-PHASE-2 (rule 235) — composer and npm never run as root.
#
# Sourced (never executed) from the immutable snapshot by scripts/deploy-vps.sh
# and scripts/rollback-vps.sh, and by scripts/build-privsep-rehearsal.sh.
#
# THE DEFECT (rule 233 §9 R1): the deploy ran `composer install`, `npm ci` and
# `npm run build` as root. composer runs --no-scripts since rule 233, but npm
# runs every dependency's lifecycle scripts and `npm run build` runs vite and
# its plugins — third-party code, as root, on every deploy and rollback.
#
# THE MODEL:
#   * A dedicated BUILD identity (DMS_PRODUCTION_BUILD_USER): no login shell,
#     no sudo, not the runtime user, in none of the runtime/admin groups. Its home
#     is ROOT's (nothing in it but `cache/`), so a build can plant no SSH key,
#     mail forward or user unit there; only `cache/` (0700) is the identity's,
#     for the package caches. It cannot read the environment file.
#   * Before a build step root HANDS the build trees (vendor/, node_modules/,
#     public/build) to it — they are root-owned and nothing of the build
#     identity is running, so nothing can race the hand-over.
#   * The step runs in a transient systemd service (systemd-run --wait --pipe):
#     the build identity's uid, a clean environment, the whole filesystem
#     read-only except the handed trees and its cache, the environment files
#     masked by /dev/null, storage/, bootstrap/cache and .git inaccessible,
#     loopback denied except the DNS stub (no database, no local service),
#     private /tmp and /dev, no setuid, no capabilities, a time limit — and when
#     the step ends systemd kills every process left in its cgroup, so no build
#     process outlives the step.
#   * After the step root CLAIMS the trees back (owner root:root). It refuses
#     device nodes, FIFOs, sockets, setuid/setgid files, a hardlinked file with
#     a link OUTSIDE the tree, an entry another identity owns and any entry
#     writable by group or others —
#     so, with no build process alive, nobody but root can change a tree while
#     it is claimed.
# Root never reads a file the build identity wrote and never executes its code.
#
# Observed and trusted values (names, uids, paths) are PARAMETERS.

_dms_build_err() { echo "build-privsep: $*" >&2; }

# Space-free absolute or relative paths only: they become systemd properties.
_dms_build_plain_path() {
  [[ "${1:-}" =~ ^[A-Za-z0-9/._-]+$ ]] && [[ "$1" != *..* ]]
}

# The node and every directory above it are owned by root and not writable by
# group or others (the node itself may be owned by <owner uid>).
_dms_build_trusted_chain() {
  local node="$1" owner="$2" path out
  path="$node"
  while :; do
    if [ -L "$path" ]; then
      echo "${path} is a symlink"
      return 1
    fi
    out="$(stat -c '%u %a' -- "$path" 2>/dev/null)" || { echo "${path} cannot be inspected"; return 1; }
    if [ "$path" = "$node" ]; then
      [ "${out% *}" = "$owner" ] || { echo "${path} is owned by uid ${out% *}, expected ${owner}"; return 1; }
    else
      [ "${out% *}" = 0 ] || { echo "${path} is owned by uid ${out% *}, not root"; return 1; }
      [ $(( 8#${out#* } & 8#022 )) -eq 0 ] || { echo "${path} is writable by group or others"; return 1; }
    fi
    [ "$path" = "/" ] && return 0
    path="$(dirname -- "$path")"
  done
}

# _dms_build_escaping_hardlinks <tree> — prints how many hardlinked files of
# <tree> share their inode with a name OUTSIDE <tree> (fewer of the inode's
# names inside the tree than its link count). A hardlink whose every name lies
# inside the tree is the build's own — esbuild's install script links its
# launcher to the native binary — and, with fs.protected_hardlinks=1 (required
# by rule 233's preflight), the build identity can only hardlink inodes it owns.
# A failed or partial walk can only lower the count found inside: it refuses.
_dms_build_escaping_hardlinks() {
  find "$1" -xdev -type f -links +1 -printf '%i %n\n' 2>/dev/null \
    | sort | uniq -c | awk '$1 != $3 { n += $1 } END { print n + 0 }'
}

# dms_build_identity_check <build user> <build home> <runtime user> <runtime group>
# The build identity exists, is unprivileged, is not the runtime identity or the
# web server, belongs to no runtime or administrative group, has no login shell,
# and owns a private home directory under a root-owned chain.
dms_build_identity_check() {
  local user="${1:-}" home="${2:-}" runtime_user="${3:-}" runtime_group="${4:-}"
  local uid shell group why
  if [ -z "$user" ] || [ -z "$home" ] || [ -z "$runtime_user" ] || [ -z "$runtime_group" ]; then
    _dms_build_err "usage: dms_build_identity_check <build user> <build home> <runtime user> <runtime group>"
    return 2
  fi
  uid="$(id -u -- "$user" 2>/dev/null || true)"
  if [ -z "$uid" ]; then
    _dms_build_err "build identity '${user}' does not exist — provision it: bash scripts/provision-deploy-identities.sh --build-identity --apply"
    return 1
  fi
  if [ "$uid" = 0 ] || [ "$user" = "$runtime_user" ] || [ "$user" = "www-data" ] \
     || [ "$uid" = "$(id -u -- "$runtime_user" 2>/dev/null || echo none)" ]; then
    _dms_build_err "build identity '${user}' is privileged or shared with the runtime or the web server"
    return 1
  fi
  for group in $(id -Gn -- "$user"); do
    case "$group" in
      "$runtime_group"|www-data|sudo|admin|adm|wheel|docker|lxd|root|shadow|disk)
        _dms_build_err "build identity '${user}' belongs to group '${group}'"
        return 1
        ;;
    esac
  done
  shell="$(getent passwd "$user" | cut -d: -f7)"
  case "$shell" in
    /usr/sbin/nologin|/sbin/nologin|/bin/false|/usr/bin/false) ;;
    *) _dms_build_err "build identity '${user}' has a login shell (${shell})"; return 1 ;;
  esac
  if ! _dms_build_plain_path "$home" || [ "${home#/}" = "$home" ]; then
    _dms_build_err "build home '${home}' is not a plain absolute path"
    return 1
  fi
  # The home is root's (0755, a trusted chain): the identity cannot create
  # .ssh, .forward or user units there. Only cache/ is the identity's own.
  if [ ! -d "$home" ] || ! why="$(_dms_build_trusted_chain "$home" 0)"; then
    _dms_build_err "build home ${home} must be root's and not writable by another identity: ${why:-missing}"
    return 1
  fi
  if [ $(( 8#$(stat -c '%a' -- "$home") & 8#022 )) -ne 0 ]; then
    _dms_build_err "build home ${home} is writable by group or others"
    return 1
  fi
  if [ -n "$(find "$home" -mindepth 1 -maxdepth 1 ! -name cache -print -quit 2>/dev/null)" ]; then
    _dms_build_err "build home ${home} holds something besides cache/ (an SSH key, a forward or a unit would let the identity act outside a build)"
    return 1
  fi
  if [ -L "${home}/cache" ] || [ ! -d "${home}/cache" ] || [ "$(stat -c '%u %a' -- "${home}/cache")" != "${uid} 700" ]; then
    _dms_build_err "build cache ${home}/cache must be the identity's own directory, mode 0700"
    return 1
  fi
  dms_build_no_processes "$user" || return 1
  echo "Build identity: ${user} (uid ${uid}, root-owned home ${home}, private cache, no login shell, no runtime or admin group, no process running)"
}

# dms_build_no_processes <build user> — 0 when no process of the build identity runs.
dms_build_no_processes() {
  local uid count
  uid="$(id -u -- "${1:-}" 2>/dev/null || true)"
  [ -n "$uid" ] || { _dms_build_err "unknown build identity '${1:-}'"; return 2; }
  count="$(pgrep -c -u "$uid" 2>/dev/null || true)"
  if [ "${count:-0}" -ne 0 ]; then
    _dms_build_err "${count} process(es) of the build identity are running — refusing to hand over or claim a tree"
    return 1
  fi
}

# dms_build_handover <build user> <build group> <tree>... — run as root from the
# application directory. A missing tree is created (by root, in its root-owned
# parent). Refuses a tree that is a symlink, holds an entry owned by neither
# root nor the build identity, or holds a hardlinked file; then gives every
# root-owned entry to the build identity, without following a symlink.
dms_build_handover() {
  local user="${1:-}" group="${2:-}" uid tree n
  shift 2 || true
  uid="$(id -u -- "$user" 2>/dev/null || true)"
  if [ -z "$uid" ] || [ "$uid" = 0 ] || [ -z "$group" ] || [ "$#" -eq 0 ]; then
    _dms_build_err "usage: dms_build_handover <unprivileged build user> <group> <tree>..."
    return 2
  fi
  dms_build_no_processes "$user" || return 1
  for tree in "$@"; do
    if ! _dms_build_plain_path "$tree" || [ "${tree#/}" != "$tree" ]; then
      _dms_build_err "tree '${tree}' must be a plain relative path"
      return 2
    fi
    if [ -L "$tree" ]; then
      _dms_build_err "${tree} is a symlink — refusing to hand it over"
      return 1
    fi
    if [ ! -e "$tree" ]; then
      mkdir -m 0755 -- "$tree" || return 1
    fi
    [ -d "$tree" ] || { _dms_build_err "${tree} is not a directory"; return 1; }
    if [ -n "$(find "$tree" -xdev \( ! -uid 0 ! -uid "$uid" \) -print -quit 2>/dev/null)" ]; then
      _dms_build_err "${tree} holds an entry owned by neither root nor the build identity — run the privilege repair first"
      return 1
    fi
    n="$(_dms_build_escaping_hardlinks "$tree")"
    if [ "$n" -ne 0 ]; then
      _dms_build_err "${tree} holds ${n} hardlinked file(s) with a link outside the tree — refusing to hand it to the build identity"
      return 1
    fi
    chown -R -h --from=0 "${user}:${group}" -- "$tree" || return 1
  done
}

# dms_build_claim <build user> <tree>... — run as root from the application
# directory, after the build step ended. Every entry becomes root:root and
# loses group/other write; special files, setuid/setgid files, hardlinked files
# and foreign entries fail the claim (counts only, never a file name).
dms_build_claim() {
  local user="${1:-}" uid tree n
  shift || true
  uid="$(id -u -- "$user" 2>/dev/null || true)"
  if [ -z "$uid" ] || [ "$uid" = 0 ] || [ "$#" -eq 0 ]; then
    _dms_build_err "usage: dms_build_claim <unprivileged build user> <tree>..."
    return 2
  fi
  dms_build_no_processes "$user" || return 1
  for tree in "$@"; do
    if ! _dms_build_plain_path "$tree" || [ "${tree#/}" != "$tree" ]; then
      _dms_build_err "tree '${tree}' must be a plain relative path"
      return 2
    fi
    if [ -L "$tree" ] || [ ! -d "$tree" ]; then
      _dms_build_err "${tree} is missing or a symlink after the build"
      return 1
    fi
    n="$(find "$tree" -xdev \( ! -type f ! -type d ! -type l \) -print 2>/dev/null | wc -l)"
    [ "$n" -eq 0 ] || { _dms_build_err "${tree}: ${n} device node(s), FIFO(s) or socket(s) — NOT GO"; return 1; }
    n="$(find "$tree" -xdev ! -type d ! -type l -perm /6000 -print 2>/dev/null | wc -l)"
    [ "$n" -eq 0 ] || { _dms_build_err "${tree}: ${n} setuid/setgid file(s) — NOT GO"; return 1; }
    n="$(find "$tree" -xdev ! -type l -perm /022 -print 2>/dev/null | wc -l)"
    [ "$n" -eq 0 ] || { _dms_build_err "${tree}: ${n} entr(ies) writable by group or others — NOT GO"; return 1; }
    n="$(_dms_build_escaping_hardlinks "$tree")"
    [ "$n" -eq 0 ] || { _dms_build_err "${tree}: ${n} hardlinked file(s) with a link outside the tree — NOT GO"; return 1; }
    n="$(find "$tree" -xdev \( ! -uid 0 ! -uid "$uid" \) -print 2>/dev/null | wc -l)"
    [ "$n" -eq 0 ] || { _dms_build_err "${tree}: ${n} entr(ies) owned by another identity — NOT GO"; return 1; }
    # Every entry is root's or the build identity's, and none is writable by
    # anyone else (checked above): with no build process alive the tree is
    # static, and all of it becomes root:root, as the source drift gate requires.
    chown -R -h root:root -- "$tree" || return 1
    n="$(find "$tree" -xdev \( ! -uid 0 -o ! -gid 0 -o \( ! -type l -perm /022 \) \) -print 2>/dev/null | wc -l)"
    [ "$n" -eq 0 ] || { _dms_build_err "${tree}: ${n} entr(ies) still not root-owned and read-only after the claim — NOT GO"; return 1; }
  done
}

# dms_build_assert_no_symlinks <tree> — the web server serves this tree: a
# symlink in it could expose a file outside it.
dms_build_assert_no_symlinks() {
  local n
  n="$(find "${1:?}" -xdev -type l -print 2>/dev/null | wc -l)"
  [ "$n" -eq 0 ] || { _dms_build_err "${1}: ${n} symlink(s) in a tree the web server serves — NOT GO"; return 1; }
}

# dms_build_env_masks <directory> — the environment files at the top of
# <directory> that the asset build reads (vite and laravel-vite-plugin load
# .env, .env.local, .env.<mode> and .env.<mode>.local; `vite build` runs in
# mode production). Each is masked with /dev/null so the build neither reads
# nor fails on it. Other environment files (backups) are root-only or
# runtime-group files the build identity cannot open, and nothing reads them.
dms_build_env_masks() {
  local dir="${1:?}" name
  for name in .env .env.local .env.production .env.production.local; do
    [ -e "${dir}/${name}" ] || [ -L "${dir}/${name}" ] || continue
    _dms_build_plain_path "${dir}/${name}" || { _dms_build_err "environment file path '${dir}/${name}' is not plain"; return 1; }
    printf '%s ' "${dir}/${name}"
  done
}

# dms_build_run <build user> <build home> <work dir> <writable trees> <masked files> <command> [args...]
# Runs ONE build command as the build identity in a transient, sandboxed systemd
# service and returns its exit status. <writable trees> and <masked files> are
# space-separated absolute paths (either may be empty).
dms_build_run() {
  local user="${1:-}" home="${2:-}" workdir="${3:-}" writable="${4:-}" masked="${5:-}" uid group path
  shift 5 || { _dms_build_err "usage: dms_build_run <user> <home> <workdir> <writable> <masked> <command> [args...]"; return 2; }
  uid="$(id -u -- "$user" 2>/dev/null || true)"
  if [ -z "$uid" ] || [ "$uid" = 0 ] || [ "$#" -eq 0 ]; then
    _dms_build_err "refusing to run a build step as '${user}': not an existing unprivileged account"
    return 2
  fi
  group="$(id -gn -- "$user")"
  local -a props=(
    -p "User=${user}" -p "Group=${group}"
    -p "WorkingDirectory=${workdir}"
    -p UMask=0022
    -p NoNewPrivileges=yes -p RestrictSUIDSGID=yes -p CapabilityBoundingSet= -p AmbientCapabilities=
    -p PrivateTmp=yes -p PrivateDevices=yes -p ProtectSystem=strict -p ProtectHome=yes
    -p ProtectKernelTunables=yes -p ProtectKernelModules=yes -p ProtectKernelLogs=yes
    -p ProtectControlGroups=yes -p ProtectClock=yes -p ProtectHostname=yes -p ProtectProc=invisible
    -p LockPersonality=yes -p RestrictRealtime=yes -p RestrictNamespaces=yes
    -p KillMode=control-group -p RuntimeMaxSec=1800
    -p IPAddressAllow=127.0.0.53 -p IPAddressDeny=localhost
    -p "InaccessiblePaths=-${workdir}/storage -${workdir}/bootstrap/cache -${workdir}/.git"
    -p "Environment=HOME=${home}/cache PATH=/usr/local/bin:/usr/bin LANG=C.UTF-8 CI=1"
    -p "Environment=XDG_CACHE_HOME=${home}/cache/xdg XDG_CONFIG_HOME=${home}/cache/config"
    -p "Environment=COMPOSER_HOME=${home}/cache/composer COMPOSER_CACHE_DIR=${home}/cache/composer-files COMPOSER_NO_INTERACTION=1"
    -p "Environment=npm_config_cache=${home}/cache/npm npm_config_update_notifier=false npm_config_fund=false npm_config_audit=false"
  )
  local -a rw=() masks=()
  read -r -a rw <<< "$writable"
  read -r -a masks <<< "$masked"
  for path in "$home" "$workdir" "${rw[@]}" "${masks[@]}"; do
    if ! _dms_build_plain_path "$path" || [ "${path#/}" = "$path" ]; then
      _dms_build_err "sandbox path '${path}' is not a plain absolute path"
      return 2
    fi
  done
  props+=(-p "ReadWritePaths=${home}/cache")
  for path in "${rw[@]}"; do
    props+=(-p "ReadWritePaths=${path}")
  done
  for path in "${masks[@]}"; do
    props+=(-p "BindReadOnlyPaths=/dev/null:${path}")
  done
  ( _dms_build_close_inherited_fds && exec systemd-run --quiet --wait --pipe --collect --service-type=exec \
      --unit="dms-build-$$-${RANDOM}" "${props[@]}" -- "$@" ) </dev/null 2>&1 | cat
  return "${PIPESTATUS[0]}"
}

# Every descriptor above 2 closed, so the sandboxed step inherits nothing of
# root's (the deploy log, the release lock).
_dms_build_close_inherited_fds() {
  local entry fd
  for entry in /proc/"$BASHPID"/fd/*; do
    fd="${entry##*/}"
    case "$fd" in '' | *[!0-9]*) continue ;; esac
    if [ "$fd" -gt 2 ] && [ "$fd" -lt 255 ]; then
      eval "exec ${fd}>&-" 2>/dev/null || true
    fi
  done
}
