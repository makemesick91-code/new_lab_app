# shellcheck shell=bash
#
# DMS-RELEASE-GUARD-TRUST-HARDENING-1 — trusted filesystem paths (rule 231).
#
# Sourced (never executed) by scripts/lib/release-approval.sh and
# scripts/lib/deploy-runner-evidence.sh.
#
# A path is TRUSTED for the deploy identity when nothing less privileged than
# that identity can replace it, or any directory above it:
#
#   - it is absolute and canonical (no symlink anywhere in it, no `.`/`..`);
#   - it is NOT inside the application checkout, whose storage/ and
#     bootstrap/cache are owned by the runtime user after every deploy (a
#     root-owned directory inside a runtime-writable parent can be renamed away
#     and replaced by the runtime user, so no permission bit can protect it);
#   - it and every directory above it is owned by root or by the deploy
#     identity itself, and is not writable by group or others — except a
#     root-owned sticky directory (/tmp), where only an entry's owner may rename
#     or remove it.
#
# The deploy identity is a PARAMETER (the caller passes `id -u`; on production
# that is root), the convention of scripts/lib/production-host-preflight.sh, so
# the contract is testable without root.

if [ -n "${DMS_TRUSTED_PATH_LOADED:-}" ]; then
  return 0
fi
DMS_TRUSTED_PATH_LOADED=1

dms_trusted_owner_ok() {
  # dms_trusted_owner_ok <owner uid> <deploy uid>
  [ "$1" = "0" ] || [ "$1" = "$2" ]
}

dms_trusted_canonical() {
  # True when PATH is absolute, made of plain components, and resolves to
  # itself (`readlink -m` follows every symlink that exists along the way).
  local path="$1" real
  [[ "$path" =~ ^/[A-Za-z0-9._/-]*$ ]] || return 1
  case "$path" in
    */|*//*|*/./*|*/../*|*/.|*/..) [ "$path" = "/" ] || return 1 ;;
  esac
  real="$(readlink -m -- "$path" 2>/dev/null)" || return 1
  [ "$real" = "$path" ]
}

dms_trusted_outside() {
  # dms_trusted_outside <path> <app_dir> — the path is not the checkout and not
  # inside it (compared after resolving the checkout itself).
  local path="$1" app real_app
  app="${2:-}"
  [ -n "$app" ] || return 0
  real_app="$(readlink -m -- "$app" 2>/dev/null)" || real_app="$app"
  real_app="${real_app%/}"
  [ -n "$real_app" ] || return 1
  case "${path%/}/" in
    "${real_app}/"*) return 1 ;;
  esac
  return 0
}

dms_trusted_mode_private() {
  # No group or other write bit (octal mode as printed by `stat -c %a`).
  [[ "$1" =~ ^[0-7]{3,4}$ ]] || return 1
  (( (8#$1 & 8#022) == 0 ))
}

dms_trusted_ancestors() {
  # dms_trusted_ancestors <canonical path> <deploy uid> — every directory above
  # PATH. Prints the first problem and returns 1.
  local dir owner mode stat_out
  dir="$(dirname -- "$1")"
  while :; do
    if [ -L "$dir" ] || [ ! -d "$dir" ]; then
      echo "${dir} is not a real directory"
      return 1
    fi
    stat_out="$(stat -c '%u %a' -- "$dir" 2>/dev/null)" || { echo "${dir} cannot be inspected"; return 1; }
    owner="${stat_out% *}"
    mode="${stat_out#* }"
    if ! dms_trusted_owner_ok "$owner" "$2"; then
      echo "${dir} is owned by uid ${owner}, neither root nor the deploy identity ($2)"
      return 1
    fi
    if ! dms_trusted_mode_private "$mode"; then
      if ! { [[ "$mode" =~ ^[0-7]{4}$ ]] && (( (8#$mode & 8#1000) != 0 )) && [ "$owner" = "0" ]; }; then
        echo "${dir} is writable by group or others (mode ${mode})"
        return 1
      fi
    fi
    [ "$dir" = "/" ] && return 0
    dir="$(dirname -- "$dir")"
  done
}

dms_trusted_file() {
  # dms_trusted_file <label> <path> <deploy uid> <app_dir>
  # A regular file nothing below the deploy identity can change. Prints the
  # reason and returns 1 when it is not.
  local label="$1" path="$2" uid="$3" app="$4" stat_out owner mode why
  if ! dms_trusted_canonical "$path"; then
    echo "${label} '${path}' is not a canonical absolute path (no symlink, no '..')"
    return 1
  fi
  if [ -L "$path" ] || [ ! -f "$path" ]; then
    echo "${label} ${path} is missing or is not a regular file"
    return 1
  fi
  if ! dms_trusted_outside "$path" "$app"; then
    echo "${label} ${path} lies inside the application checkout ${app}"
    return 1
  fi
  stat_out="$(stat -c '%u %a' -- "$path" 2>/dev/null)" || { echo "${label} ${path} cannot be inspected"; return 1; }
  owner="${stat_out% *}"
  mode="${stat_out#* }"
  if ! dms_trusted_owner_ok "$owner" "$uid"; then
    echo "${label} ${path} is owned by uid ${owner}, neither root nor the deploy identity (${uid})"
    return 1
  fi
  if ! dms_trusted_mode_private "$mode"; then
    echo "${label} ${path} is writable by group or others (mode ${mode})"
    return 1
  fi
  if ! why="$(dms_trusted_ancestors "$path" "$uid")"; then
    echo "${label} ${path}: ${why}"
    return 1
  fi
  return 0
}

dms_trusted_dir() {
  # dms_trusted_dir <label> <path> <deploy uid> <app_dir>
  # The deploy identity's OWN private directory: owned by exactly that uid,
  # mode 0700, outside the checkout, with a trusted chain above it.
  local label="$1" path="$2" uid="$3" app="$4" stat_out owner mode why
  if ! dms_trusted_canonical "$path" || [ "$path" = "/" ]; then
    echo "${label} '${path}' is not a canonical absolute directory (no symlink, no '..')"
    return 1
  fi
  if [ -L "$path" ] || [ ! -d "$path" ]; then
    echo "${label} ${path} is missing or is not a real directory"
    return 1
  fi
  if ! dms_trusted_outside "$path" "$app"; then
    echo "${label} ${path} lies inside the application checkout ${app}"
    return 1
  fi
  stat_out="$(stat -c '%u %a' -- "$path" 2>/dev/null)" || { echo "${label} ${path} cannot be inspected"; return 1; }
  owner="${stat_out% *}"
  mode="${stat_out#* }"
  if [ "$owner" != "$uid" ] || [ "$mode" != "700" ]; then
    echo "${label} ${path} is uid ${owner} mode ${mode}; it must be the deploy identity's (${uid}) with mode 700"
    return 1
  fi
  if ! why="$(dms_trusted_ancestors "$path" "$uid")"; then
    echo "${label} ${path}: ${why}"
    return 1
  fi
  return 0
}

dms_trusted_dir_prepare() {
  # dms_trusted_dir_prepare <label> <path> <deploy uid> <app_dir>
  # Creates the directory (and missing parents) private to the deploy identity
  # when it does not exist, then requires dms_trusted_dir. An existing
  # directory is never chmod-ed or chown-ed into trust: it must already be.
  local label="$1" path="$2"
  if ! dms_trusted_canonical "$path" || [ "$path" = "/" ]; then
    echo "${label} '${path}' is not a canonical absolute directory (no symlink, no '..')"
    return 1
  fi
  if ! dms_trusted_outside "$path" "$4"; then
    echo "${label} ${path} lies inside the application checkout $4"
    return 1
  fi
  if [ ! -e "$path" ] && [ ! -L "$path" ]; then
    (umask 077 && mkdir -p -- "$path") 2>/dev/null || { echo "${label} ${path} could not be created"; return 1; }
  fi
  dms_trusted_dir "$label" "$path" "$3" "$4"
}

dms_trusted_entry() {
  # dms_trusted_entry <path> <deploy uid> — an evidence file inside a trusted
  # directory: absent, or a regular file (never a symlink) owned by the deploy
  # identity and not writable by group or others.
  local path="$1" stat_out owner mode
  [ -e "$path" ] || [ -L "$path" ] || return 0
  if [ -L "$path" ] || [ ! -f "$path" ]; then
    echo "${path} is not a regular file"
    return 1
  fi
  stat_out="$(stat -c '%u %a' -- "$path" 2>/dev/null)" || { echo "${path} cannot be inspected"; return 1; }
  owner="${stat_out% *}"
  mode="${stat_out#* }"
  if [ "$owner" != "$2" ] || ! dms_trusted_mode_private "$mode"; then
    echo "${path} is uid ${owner} mode ${mode}; evidence must be the deploy identity's ($2) and not group/other-writable"
    return 1
  fi
  return 0
}

dms_trusted_host_key_fingerprint() {
  # dms_trusted_host_key_fingerprint <public host key file> — prints the
  # SHA256 fingerprint of the key this host presents to SSH clients (the
  # identity scripts/production-target-check.sh pins from the workstation).
  local file="$1" out fp
  [ -n "$file" ] && [ -r "$file" ] || return 1
  out="$(ssh-keygen -l -E sha256 -f "$file" 2>/dev/null)" || return 1
  fp="${out#* }"
  fp="${fp%% *}"
  [[ "$fp" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]] || return 1
  printf '%s' "$fp"
}

dms_trusted_boot_id() {
  # The kernel's identifier of the current boot: a run recorded under another
  # boot id was cut off by a restart.
  local id=""
  { IFS= read -r id < /proc/sys/kernel/random/boot_id; } 2>/dev/null || true
  [[ "$id" =~ ^[0-9a-f-]{36}$ ]] || return 1
  printf '%s' "$id"
}
