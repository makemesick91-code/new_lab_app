#!/bin/bash
#
# DMS-DEPLOY-PRIVSEP-PHASE-2 (rule 235) — the deploy operator's ONLY privileged
# entrypoint (closes OD-6: the deploy account held unrestricted passwordless sudo).
#
# scripts/provision-deploy-identities.sh installs a root-owned copy of this file
# at DMS_PRODUCTION_OPERATOR_ENTRYPOINT (root:root 0755). One program, two roles:
#
#  1. SSH GATEWAY — runs as the deploy operator. The operator's only authorized
#     key is `restrict,command="<entrypoint>"`, so every SSH session lands here
#     with the requested words in SSH_ORIGINAL_COMMAND. The words are checked
#     against a strict character set, split WITHOUT a shell (no glob, no
#     expansion, no quoting), validated against the allowlist below and handed
#     to `sudo -n <entrypoint> <words>`. The operator never gets a shell.
#  2. PRIVILEGED DISPATCHER — runs as root through sudo. It validates the words
#     AGAIN (the sudo rule's expressions are a second, independent check, not the
#     only one), proves that the program it is about to run and every directory
#     above it is root-owned and not writable by group or others, clears the
#     environment and runs exactly one allowlisted action:
#
#       start --release-id <id> --approved-sha <40-hex>   deploy ONE owner-signed release (rules 229/231)
#       check-approval --release-id <id> --approved-sha <40-hex>  the release guard only (read-only; rule 236)
#       submit-approval --release-id <id>                 store ONE owner-signed contract-3 approval read
#                                                         from stdin (scripts/release-approval-submit.sh,
#                                                         DMS-RELEASE-APPROVAL-DIRECT-1); never deploys
#       follow | status | verify <deploy-id> | trust-check  the runner's own read-only views
#       drift-check                                         scripts/privsep-drift-check.sh (rule 233)
#       sudo-policy-check                                   scripts/sudo-policy-check.sh (rule 235)
#       posture                                             scripts/production-posture.sh (read-only)
#
# NOT reachable from here, by design: rollback, database restore, BK-2, the
# release trust anchor, provisioning, any shell. Those are break-glass actions
# for the administrator account, whose sudo requires the owner's password.
#
# The only environment variables ever read are SSH_ORIGINAL_COMMAND (gateway
# role) and SUDO_USER (dispatcher role, set by sudo itself after env_reset; a
# plain account name is handed to the approval submission for its receipt and
# to nothing else). Paths are constants: an installed root program never takes
# a path, a PATH entry or an option from its caller's environment.
#
# Exit codes: 0 the action's own success; the action's own code otherwise;
# 64 not an allowlisted request; 65 an untrusted program, directory or PATH entry.
set -euo pipefail
umask 022

readonly DMS_OPERATOR_ENTRYPOINT=/usr/local/sbin/dms-deploy-operator
readonly DMS_OPERATOR_APP_DIR=/var/www/asia-dental-lab-v2
# Merged /usr: /bin and /sbin are symlinks into /usr, so they are not listed
# (every entry must be a real, root-owned directory).
readonly DMS_OPERATOR_SAFE_PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin
# Every command below — the trust checks included — resolves through this PATH,
# never through one the caller (sshd, sudo) supplied.
export PATH="$DMS_OPERATOR_SAFE_PATH"
# The programs the actions run as root, besides the in-tree scripts.
readonly DMS_OPERATOR_TOOLS="bash env sudo git composer node npm php pg_dump runuser systemd-run sha256sum find stat ssh-keygen python3 curl flock"

# The same expressions as the rendered sudo rule, plus the 80-character bound
# the release approval guard enforces (scripts/lib/release-approval.sh).
readonly DMS_OPERATOR_RE_RELEASE='^[a-z0-9][a-z0-9-]{2,79}$'
readonly DMS_OPERATOR_RE_SHA='^[0-9a-f]{40}$'
readonly DMS_OPERATOR_RE_DEPLOY_ID='^[0-9]{8}-[0-9]{6}$'
# A request is lowercase words of [a-z0-9-] separated by single spaces.
readonly DMS_OPERATOR_RE_REQUEST='^[a-z0-9-]+( [a-z0-9-]+)*$'

dms_operator_deny() {
  echo "dms-deploy-operator: $*" >&2
  echo "DEPLOY_OPERATOR=DENIED" >&2
}

dms_operator_usage() {
  cat >&2 <<'USAGE'
usage (through the deploy operator's SSH key):
  start --release-id <id> --approved-sha <40-hex>
  check-approval --release-id <id> --approved-sha <40-hex>   (read-only guard run)
  submit-approval --release-id <id>   (the signed approval bundle on stdin)
  follow | status | verify <YYYYmmdd-HHMMSS> | trust-check
  drift-check | sudo-policy-check | posture
USAGE
}

# dms_operator_validate <word>... — 0 when the words are exactly one allowlisted request.
dms_operator_validate() {
  [ "$#" -ge 1 ] || return 1
  case "$1" in
    follow|status|trust-check|drift-check|sudo-policy-check|posture)
      [ "$#" -eq 1 ]
      ;;
    verify)
      [ "$#" -eq 2 ] && [[ "$2" =~ $DMS_OPERATOR_RE_DEPLOY_ID ]]
      ;;
    start|check-approval)
      [ "$#" -eq 5 ] \
        && [ "$2" = "--release-id" ] && [[ "$3" =~ $DMS_OPERATOR_RE_RELEASE ]] \
        && [ "$4" = "--approved-sha" ] && [[ "$5" =~ $DMS_OPERATOR_RE_SHA ]]
      ;;
    submit-approval)
      [ "$#" -eq 3 ] \
        && [ "$2" = "--release-id" ] && [[ "$3" =~ $DMS_OPERATOR_RE_RELEASE ]]
      ;;
    *)
      return 1
      ;;
  esac
}

# dms_operator_split <request> — prints one validated word per line. The request
# is matched against a character set that excludes every shell metacharacter,
# whitespace other than a single space, and every non-ASCII byte, and is split on
# that space only: nothing is globbed, expanded or unquoted.
dms_operator_split() {
  local request="${1:-}" LC_ALL=C
  [ "${#request}" -le 200 ] || return 1
  [[ "$request" =~ $DMS_OPERATOR_RE_REQUEST ]] || return 1
  local -a words=()
  local IFS=' '
  set -f
  read -r -a words <<< "$request"
  set +f
  dms_operator_validate "${words[@]}" || return 1
  printf '%s\n' "${words[@]}"
}

# dms_operator_trusted_node <trusted uid> <path> — the path is not a symlink, is
# owned by <trusted uid> and is not writable by group or others.
dms_operator_trusted_node() {
  local uid="$1" path="$2" out
  if [ ! -e "$path" ] || [ -L "$path" ]; then echo "${path} is missing or is a symlink"; return 1; fi
  out="$(stat -c '%u %a' -- "$path" 2>/dev/null)" || { echo "${path} cannot be inspected"; return 1; }
  [ "${out% *}" = "$uid" ] || { echo "${path} is owned by uid ${out% *}, not ${uid}"; return 1; }
  [ $(( 8#${out#* } & 8#022 )) -eq 0 ] || { echo "${path} is writable by group or others (mode ${out#* })"; return 1; }
}

# dms_operator_trusted_chain <trusted uid> <absolute path> — the path and every
# directory above it, up to /, are trusted nodes.
dms_operator_trusted_chain() {
  local uid="$1" path="$2" why
  case "$path" in /*) ;; *) echo "${path} is not absolute"; return 1 ;; esac
  while :; do
    why="$(dms_operator_trusted_node "$uid" "$path")" || { echo "$why"; return 1; }
    [ "$path" = "/" ] && return 0
    path="$(dirname -- "$path")"
  done
}

# dms_operator_link_inside <root> <device> <link> — EVERY step of resolving the
# link stays inside <root> on <root>'s filesystem: its target is relative, and
# its directory followed by each component of the target in turn (the kernel's
# own order) resolves to an existing entry that is <root> or below it, on
# <device>. Checking only the final landing would accept a link that leaves the
# tree and comes back through a hop someone else can repoint. Every link in the
# tree gets this check, so links reached through other links are covered too.
# Names are read NUL-delimited and never trimmed. Prints why on refusal.
dms_operator_link_inside() {
  local root="$1" dev="$2" link="$3" raw rest part path hop
  IFS= read -r -d '' raw < <(readlink -z -- "$link") || { echo "dangles"; return 1; }
  case "$raw" in /*) echo "points outside it (absolute target)"; return 1 ;; esac
  path="${link%/*}"
  rest="$raw"
  while :; do
    part="${rest%%/*}"
    case "$rest" in */*) rest="${rest#*/}" ;; *) rest="" ;; esac
    if [ -n "$part" ]; then
      path="${path}/${part}"
      IFS= read -r -d '' hop < <(readlink -z -e -- "$path") || { echo "dangles"; return 1; }
      case "$hop" in "$root"|"$root"/*) ;; *) echo "points outside it"; return 1 ;; esac
      [ "$(stat -c '%d' -- "$hop" 2>/dev/null)" = "$dev" ] || { echo "points outside it (another filesystem)"; return 1; }
    fi
    [ -n "$rest" ] || return 0
  done
}

# dms_operator_trusted_tree <trusted uid> <dir> — <dir> is a real directory, no
# entry below it is owned by another uid or (symlinks aside, whose mode is
# meaningless) writable by group or others, and every symlink below it stays
# inside it (dms_operator_link_inside). r3: a stock Node.js installation links
# bin/npm, bin/npx and bin/corepack into its own lib/, so "no symlink at all"
# refused every real host; a link that dangles or leaves the tree still refuses.
# The symlink list ends with find's own status, so a failed walk refuses too.
dms_operator_trusted_tree() {
  local uid="$1" dir="$2" bad root dev link why status walked=0
  case "$dir" in /*) ;; *) echo "${dir} is not absolute"; return 1 ;; esac
  if [ ! -d "$dir" ] || [ -L "$dir" ]; then echo "${dir} is missing, not a directory, or a symlink"; return 1; fi
  bad="$(find "$dir" -xdev \( ! -uid "$uid" -o \( ! -type l -perm /022 \) \) -print -quit 2>/dev/null || echo "$dir")"
  [ -z "$bad" ] || { echo "an entry under ${dir} is foreign-owned or group/other-writable"; return 1; }
  root="$(readlink -e -- "$dir")" || { echo "${dir} cannot be resolved"; return 1; }
  dev="$(stat -c '%d' -- "$root")" || { echo "${dir} cannot be inspected"; return 1; }
  while IFS= read -r -d '' link; do
    case "$link" in
      DMS_FIND_STATUS=0) walked=1; continue ;;
      DMS_FIND_STATUS=*) echo "the symlink walk under ${dir} failed"; return 1 ;;
    esac
    why="$(dms_operator_link_inside "$root" "$dev" "$link")" || { echo "a symlink under ${dir} ${why}"; return 1; }
  done < <(if find "$dir" -xdev -type l -print0 2>/dev/null; then status=0; else status=$?; fi; printf 'DMS_FIND_STATUS=%s\0' "$status")
  [ "$walked" = 1 ] || { echo "${dir} cannot be inspected"; return 1; }
}

# dms_operator_trusted_path <trusted uid> <PATH> — every PATH entry is a trusted chain.
dms_operator_trusted_path() {
  local uid="$1" entry why
  local -a entries=()
  IFS=':' read -r -a entries <<< "$2"
  for entry in "${entries[@]}"; do
    why="$(dms_operator_trusted_chain "$uid" "$entry")" || { echo "PATH entry ${entry}: ${why}"; return 1; }
  done
}

# dms_operator_trusted_tools <trusted uid> <tool>... — each tool resolves (on
# PATH) to a file whose whole chain is trusted, and node's whole installation
# (npm runs from it) is a trusted tree.
dms_operator_trusted_tools() {
  local uid="$1" tool path real why
  shift
  for tool in "$@"; do
    path="$(command -v -- "$tool" 2>/dev/null)" || { echo "tool ${tool} is not on the trusted PATH"; return 1; }
    real="$(readlink -f -- "$path")" || { echo "tool ${tool} cannot be resolved"; return 1; }
    why="$(dms_operator_trusted_chain "$uid" "$real")" || { echo "tool ${tool} (${real}): ${why}"; return 1; }
    if [ "$tool" = "node" ]; then
      why="$(dms_operator_trusted_tree "$uid" "$(dirname -- "$(dirname -- "$real")")")" || { echo "node installation: ${why}"; return 1; }
    fi
  done
}

# dms_operator_program <action> — the in-tree program an action runs.
dms_operator_program() {
  case "$1" in
    start|check-approval|follow|status|verify|trust-check) echo scripts/deploy-vps-runner.sh ;;
    submit-approval) echo scripts/release-approval-submit.sh ;;
    drift-check) echo scripts/privsep-drift-check.sh ;;
    sudo-policy-check) echo scripts/sudo-policy-check.sh ;;
    posture) echo scripts/production-posture.sh ;;
    *) return 1 ;;
  esac
}

dms_operator_gateway() {
  local request="${SSH_ORIGINAL_COMMAND:-}" words
  local -a argv=()
  if [ -z "$request" ]; then
    dms_operator_deny "no command: this key runs one allowlisted deploy action, never a shell"
    dms_operator_usage
    exit 64
  fi
  if ! words="$(dms_operator_split "$request")"; then
    dms_operator_deny "not an allowlisted request"
    dms_operator_usage
    exit 64
  fi
  mapfile -t argv <<< "$words"
  exec /usr/bin/sudo -n -- "$DMS_OPERATOR_ENTRYPOINT" "${argv[@]}"
}

# dms_operator_require <check> [args...] — runs one trust check; refuses on failure.
dms_operator_require() {
  local why
  if ! why="$("$@")"; then
    dms_operator_deny "untrusted: ${why:-$1 failed}"
    exit 65
  fi
}

dms_operator_dispatch() {
  local action program
  if ! dms_operator_validate "$@"; then
    dms_operator_deny "not an allowlisted request"
    dms_operator_usage
    exit 64
  fi
  action="$1"
  program="$(dms_operator_program "$action")"
  # The installed entrypoint itself, the PATH every child resolves commands
  # from, the program's directory chain, the two trees the actions read their
  # programs, libraries and host authority from, the checkout's git directory
  # and every tool root runs.
  dms_operator_require dms_operator_trusted_chain 0 "$DMS_OPERATOR_ENTRYPOINT"
  dms_operator_require dms_operator_trusted_path 0 "$DMS_OPERATOR_SAFE_PATH"
  dms_operator_require dms_operator_trusted_chain 0 "${DMS_OPERATOR_APP_DIR}/${program}"
  dms_operator_require dms_operator_trusted_tree 0 "${DMS_OPERATOR_APP_DIR}/scripts"
  dms_operator_require dms_operator_trusted_tree 0 "${DMS_OPERATOR_APP_DIR}/deploy"
  # git runs as root on the checkout (fetch, approval objects): its hooks and
  # configuration are part of the trusted closure.
  dms_operator_require dms_operator_trusted_tree 0 "${DMS_OPERATOR_APP_DIR}/.git"
  # shellcheck disable=SC2086 # a fixed, space-separated list of tool names
  dms_operator_require dms_operator_trusted_tools 0 $DMS_OPERATOR_TOOLS
  # The runner receives the validated words; the read-only checks take none;
  # the approval submission takes `--release-id <id>` and its stdin (the
  # bundle), plus the operator account sudo authenticated — sudo sets
  # SUDO_USER itself after env_reset, and only a plain account name is passed
  # on, for the submission receipt.
  local submitter="${SUDO_USER:-}"
  local -a submission=()
  [[ "$submitter" =~ ^[a-z][a-z0-9-]{0,31}$ ]] || submitter="unknown"
  case "$action" in
    drift-check|sudo-policy-check|posture) set -- ;;
    submit-approval) shift; submission=("SUDO_USER=${submitter}") ;;
  esac
  cd "$DMS_OPERATOR_APP_DIR"
  exec /usr/bin/env -i PATH="$DMS_OPERATOR_SAFE_PATH" HOME=/root USER=root LOGNAME=root SHELL=/usr/bin/bash \
    LANG=C.UTF-8 "${submission[@]}" /usr/bin/bash --noprofile --norc "${DMS_OPERATOR_APP_DIR}/${program}" "$@"
}

dms_operator_main() {
  if [ "$(id -u)" = "0" ]; then
    dms_operator_dispatch "$@"
  else
    dms_operator_gateway
  fi
}

# Sourced by the tests for its functions; executed by sshd and sudo.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  dms_operator_main "$@"
fi
