#!/usr/bin/env bash
#
# DMS-DEPLOY-PRIVSEP-PHASE-2 (rule 235) — READ-ONLY least-privilege sudo check.
#
# Classifies the EFFECTIVE sudo rights of every account on this host from sudo's
# own long listing (scripts/lib/sudo-policy.sh) and checks the deploy
# operator's entrypoint and the sudoers files. The end state rule 235 requires:
#
#   * no account holds passwordless unrestricted sudo (OD-6);
#   * the deploy operator (one or more accounts) may run ONLY the root-owned
#     entrypoint, with narrow argument expressions, as root, without SETENV;
#   * only the declared administrator (DMS_PRODUCTION_ADMIN_USER) holds
#     unrestricted sudo, and every one of its rules needs its password — the
#     owner-approved break-glass role;
#   * the installed entrypoint is root-owned, not group/other-writable, inside a
#     root-owned chain, and identical to scripts/deploy-operator-entrypoint.sh
#     (a STALE copy is a WATCH: re-run the provisioning);
#   * every sudoers file is root-owned and not group/other-writable, and
#     `visudo -c` accepts the whole policy.
#
# Run on the host as root (the deploy operator reaches it through its
# entrypoint: `sudo-policy-check`). It never creates, edits, moves or deletes
# anything and runs no PHP.
#
# Exit 0 and SUDO_POLICY=LEAST_PRIVILEGE when the end state holds; 1 and
# SUDO_POLICY=VIOLATION (or NO_OPERATOR) when it does not; 2 on a usage or
# authority error.
set -euo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SELF_DIR}/.." && pwd)"
AUTHORITY="${APP_DIR}/deploy/production-host.conf"

[ "$#" -eq 0 ] || { echo "usage: bash scripts/sudo-policy-check.sh   (no arguments; run as root on the host)" >&2; exit 2; }
[ "$(id -u)" = "0" ] || { echo "sudo-policy-check: run as root — another identity cannot read every account's sudo rights" >&2; exit 2; }

# This check sources a library and reads an authority from the tree it lives
# in: both, and their directories, must be root's and not group/other-writable.
assert_trusted() {
  local path
  for path in "$1" "$(dirname -- "$1")"; do
    if [ -L "$path" ] || [ "$(stat -c '%u' -- "$path")" != 0 ] || [ $(( 8#$(stat -c '%a' -- "$path") & 8#022 )) -ne 0 ]; then
      echo "sudo-policy-check: ${path} is a symlink, not root's, or writable by another identity — refusing to read ${1}" >&2
      exit 2
    fi
  done
}
assert_trusted "${SELF_DIR}/lib/sudo-policy.sh"
assert_trusted "$AUTHORITY"
# shellcheck source=lib/sudo-policy.sh
. "${SELF_DIR}/lib/sudo-policy.sh"

authority_value() {
  sed -n "s/^[[:space:]]*$1=//p" "$AUTHORITY" | head -n 1 | sed 's/^"//; s/"$//'
}
ADMIN_USER="$(authority_value DMS_PRODUCTION_ADMIN_USER)"
ENTRYPOINT="$(authority_value DMS_PRODUCTION_OPERATOR_ENTRYPOINT)"
if [ -z "$ADMIN_USER" ] || [ -z "$ENTRYPOINT" ]; then
  echo "sudo-policy-check: ${AUTHORITY} must declare DMS_PRODUCTION_ADMIN_USER and DMS_PRODUCTION_OPERATOR_ENTRYPOINT" >&2
  exit 2
fi

echo "== Sudo least-privilege check (rule 235, read-only) =="
violations=0
operators=0
admins=0
without=0

while IFS=: read -r user _; do
  [ "$user" = "root" ] && continue
  is_admin=0
  [ "$user" = "$ADMIN_USER" ] && is_admin=1
  if ! listing="$(sudo -ll -U "$user" 2>&1)"; then
    echo "SUDO_ACCOUNT user=${user} verdict=VIOLATION sudo could not list this account's rights"
    violations=$((violations + 1))
    continue
  fi
  verdict="$(dms_sudo_classify "$ENTRYPOINT" "$is_admin" <<< "$listing" || true)"
  case "$verdict" in
    NONE) without=$((without + 1)); continue ;;
    OPERATOR) operators=$((operators + 1)) ;;
    ADMIN_PASSWORD) admins=$((admins + 1)) ;;
    *) violations=$((violations + 1)) ;;
  esac
  echo "SUDO_ACCOUNT user=${user} verdict=${verdict}"
done < <(getent passwd)
echo "SUDO_ACCOUNTS_WITHOUT_SUDO=${without}"

# The installed entrypoint: present, a trusted chain, identical to the in-tree copy.
entry_state="ABSENT"
if [ -e "$ENTRYPOINT" ] || [ -L "$ENTRYPOINT" ]; then
  entry_state="UNTRUSTED"
  trusted=1
  path="$ENTRYPOINT"
  while :; do
    if [ -L "$path" ] || [ "$(stat -c '%u' -- "$path")" != 0 ] || [ $(( 8#$(stat -c '%a' -- "$path") & 8#022 )) -ne 0 ]; then
      trusted=0
      break
    fi
    [ "$path" = "/" ] && break
    path="$(dirname -- "$path")"
  done
  if [ "$trusted" -eq 1 ] && [ -f "$ENTRYPOINT" ]; then
    if [ "$(sha256sum < "$ENTRYPOINT")" = "$(sha256sum < "${SELF_DIR}/deploy-operator-entrypoint.sh")" ]; then
      entry_state="CURRENT"
    else
      entry_state="STALE"
    fi
  fi
fi
echo "DEPLOY_OPERATOR_ENTRYPOINT=${entry_state} (${ENTRYPOINT})"
case "$entry_state" in
  UNTRUSTED) violations=$((violations + 1)) ;;
  ABSENT) [ "$operators" -eq 0 ] || violations=$((violations + 1)) ;;
  STALE) echo "  WATCH: re-run scripts/provision-deploy-identities.sh --apply to install the current entrypoint" ;;
esac

# Every sudoers file root-owned and not group/other-writable; the whole policy parses.
bad_files="$(find /etc/sudoers /etc/sudoers.d -xdev \( ! -uid 0 -o -perm /022 -o -type l \) -print 2>/dev/null | wc -l)"
echo "SUDOERS_UNTRUSTED_FILES=${bad_files}"
[ "$bad_files" -eq 0 ] || violations=$((violations + 1))
if visudo -c -q >/dev/null 2>&1; then
  echo "SUDOERS_SYNTAX=OK"
else
  echo "SUDOERS_SYNTAX=INVALID"
  violations=$((violations + 1))
fi

echo "SUDO_OPERATORS=${operators}"
echo "SUDO_ADMINS_PASSWORD_REQUIRED=${admins}"
echo "SUDO_VIOLATIONS=${violations}"
if [ "$violations" -ne 0 ]; then
  echo "SUDO_POLICY=VIOLATION"
  exit 1
fi
if [ "$operators" -eq 0 ]; then
  echo "SUDO_POLICY=NO_OPERATOR"
  exit 1
fi
echo "SUDO_POLICY=LEAST_PRIVILEGE"
