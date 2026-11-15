#!/usr/bin/env bash
#
# DMS-DEPLOY-PRIVSEP-PHASE-2 (rule 235) — provision the least-privilege deploy
# identities. MANUAL, run as root on the production host by the administrator,
# from a trusted copy of an approved release (runbook production-host-identity.md
# §6f). DRY RUN unless --apply. Idempotent: a re-run converges, never duplicates.
#
#   --build-identity
#       The build identity composer and npm run as (DMS_PRODUCTION_BUILD_USER):
#       a system account with no login shell, no sudo, its own group only, and a
#       private home (DMS_PRODUCTION_BUILD_HOME, 0700) for the package caches.
#
#   --operator --operator-user <name> --operator-key <public key file>
#       The deploy operator: an SSH account whose ONLY authorized key is
#       `restrict,command="<entrypoint>"`, whose home, .ssh and authorized_keys are
#       root's (the account cannot change its own key or options), and whose only
#       sudo right is the root-owned entrypoint with narrow argument expressions
#       (scripts/lib/sudo-policy.sh). Installs the entrypoint
#       (DMS_PRODUCTION_OPERATOR_ENTRYPOINT) and the sudoers drop-in
#       (DMS_PRODUCTION_OPERATOR_SUDOERS): rendered, checked with `visudo -cf`,
#       moved into place atomically, then the whole policy re-checked (`visudo -c`)
#       and moved back out if it does not parse. The account name stays on the host.
#
#   --retire-admin-nopasswd --admin-password-verified
#       Moves the sudoers drop-in that gives the administrator PASSWORDLESS
#       unrestricted sudo (OD-6) out of /etc/sudoers.d into /root/sudoers-retired/
#       (kept, never deleted). Refuses unless: the administrator has a usable
#       password; another rule still gives it sudo WITH its password; the drop-in
#       holds nothing but that one rule; a deploy operator is provisioned and
#       classifies as OPERATOR. --admin-password-verified records that the OWNER
#       proved `sudo -k; sudo -v` with that password in their own terminal first —
#       this script cannot prove it and never asks for or reads a password.
#
# Never run by the deploy, the rollback or CI. Prints no secret, key or password.
# Exit: 0 done (or dry run complete), 1 refused or failed, 2 usage.
set -euo pipefail
umask 022

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_ROOT="$(cd "${SELF_DIR}/.." && pwd)"
AUTHORITY="${SOURCE_ROOT}/deploy/production-host.conf"
RETIRED_DIR=/root/sudoers-retired

APPLY=0
DO_BUILD=0
DO_OPERATOR=0
DO_RETIRE=0
PASSWORD_VERIFIED=0
OPERATOR_USER=""
OPERATOR_KEY=""

usage() {
  cat >&2 <<'USAGE'
usage: bash scripts/provision-deploy-identities.sh [--apply]
         [--build-identity]
         [--operator --operator-user <name> --operator-key <public key file>]
         [--retire-admin-nopasswd --admin-password-verified]
USAGE
  exit 2
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --apply) APPLY=1; shift ;;
    --build-identity) DO_BUILD=1; shift ;;
    --operator) DO_OPERATOR=1; shift ;;
    --operator-user) [ "$#" -ge 2 ] || usage; OPERATOR_USER="$2"; shift 2 ;;
    --operator-key) [ "$#" -ge 2 ] || usage; OPERATOR_KEY="$2"; shift 2 ;;
    --retire-admin-nopasswd) DO_RETIRE=1; shift ;;
    --admin-password-verified) PASSWORD_VERIFIED=1; shift ;;
    *) usage ;;
  esac
done
[ $((DO_BUILD + DO_OPERATOR + DO_RETIRE)) -gt 0 ] || usage
if [ "$DO_OPERATOR" -eq 1 ] && { [ -z "$OPERATOR_USER" ] || [ -z "$OPERATOR_KEY" ]; }; then usage; fi

die() { echo "provision-deploy-identities: $*" >&2; exit 1; }
plan() { if [ "$APPLY" -eq 1 ]; then echo "APPLY: $*"; else echo "DRY RUN: would $*"; fi; }

[ "$(id -u)" = "0" ] || die "run as root on the production host (the administrator's sudo)"

# Everything this script installs or reads comes from its own tree: that tree
# (and every directory above it) must be root's and not group/other-writable.
assert_trusted_tree() {
  local path="$1"
  while :; do
    if [ -L "$path" ] || [ "$(stat -c '%u' -- "$path")" != 0 ] || [ $(( 8#$(stat -c '%a' -- "$path") & 8#022 )) -ne 0 ]; then
      die "${path} is a symlink, not root's, or writable by another identity — run from a trusted copy of the release"
    fi
    [ "$path" = "/" ] && break
    path="$(dirname -- "$path")"
  done
}
assert_trusted_tree "${SELF_DIR}/lib"
for f in "${SELF_DIR}/lib/sudo-policy.sh" "${SELF_DIR}/lib/production-host-preflight.sh" "${SELF_DIR}/lib/build-privsep.sh" \
         "$AUTHORITY" "${SOURCE_ROOT}/deploy/runtime-identity.conf" "${SELF_DIR}/deploy-operator-entrypoint.sh"; do
  assert_trusted_tree "$f"
done
# shellcheck source=lib/production-host-preflight.sh
. "${SELF_DIR}/lib/production-host-preflight.sh"
# shellcheck source=lib/sudo-policy.sh
. "${SELF_DIR}/lib/sudo-policy.sh"

if ! dms_production_host_preflight "$AUTHORITY" "$(id -u)" "$(hostname)" "$(hostname -I 2>/dev/null || true)" \
     useradd usermod getent install visudo sudo ssh-keygen passwd; then
  die "production host preflight failed — nothing was changed"
fi

authority_value() { dms_preflight_authority_value "$AUTHORITY" "$1"; }
ADMIN_USER="$(authority_value DMS_PRODUCTION_ADMIN_USER)"
BUILD_USER="$(authority_value DMS_PRODUCTION_BUILD_USER)"
BUILD_HOME="$(authority_value DMS_PRODUCTION_BUILD_HOME)"
ENTRYPOINT="$(authority_value DMS_PRODUCTION_OPERATOR_ENTRYPOINT)"
SUDOERS="$(authority_value DMS_PRODUCTION_OPERATOR_SUDOERS)"
identity_value() {
  sed -n "s/^[[:space:]]*$1=//p" "${SOURCE_ROOT}/deploy/runtime-identity.conf" | head -n 1 | sed 's/^"//; s/"$//'
}
RUNTIME_USER="$(identity_value DMS_RUNTIME_USER)"
RUNTIME_GROUP="$(identity_value DMS_RUNTIME_GROUP)"
for v in ADMIN_USER BUILD_USER BUILD_HOME ENTRYPOINT SUDOERS RUNTIME_USER RUNTIME_GROUP; do
  [ -n "${!v}" ] || die "the authority declares no ${v}"
done
case "$SUDOERS" in /etc/sudoers.d/[a-z0-9]*) ;; *) die "operator sudoers path must be a file directly in /etc/sudoers.d" ;; esac
[[ "$(basename -- "$SUDOERS")" =~ ^[a-z0-9-]+$ ]] || die "operator sudoers file name may hold only [a-z0-9-] (sudo ignores names with a dot)"

# ── Build identity ──────────────────────────────────────────────────────────
provision_build_identity() {
  echo "== Build identity (${BUILD_USER}) =="
  if getent passwd "$BUILD_USER" >/dev/null; then
    echo "account ${BUILD_USER} exists (uid $(id -u -- "$BUILD_USER"))"
  else
    plan "create system account ${BUILD_USER} (own group, home ${BUILD_HOME}, shell /usr/sbin/nologin, password locked)"
    if [ "$APPLY" -eq 1 ]; then
      useradd --system --user-group --no-create-home --home-dir "$BUILD_HOME" --shell /usr/sbin/nologin "$BUILD_USER"
    fi
  fi
  # The home is ROOT's (0755) so a build cannot plant .ssh, .forward or user
  # units there; only cache/ (0700) is the identity's, for its package caches.
  plan "own ${BUILD_HOME} by root (0755) and ${BUILD_HOME}/cache by ${BUILD_USER} (0700)"
  if [ "$APPLY" -eq 1 ]; then
    [ ! -L "$BUILD_HOME" ] || die "${BUILD_HOME} is a symlink"
    install -d -o root -g root -m 0755 -- "$BUILD_HOME"
    chown -h root:root -- "$BUILD_HOME"; chmod 0755 -- "$BUILD_HOME"
    [ ! -L "${BUILD_HOME}/cache" ] || die "${BUILD_HOME}/cache is a symlink"
    install -d -o "$BUILD_USER" -g "$BUILD_USER" -m 0700 -- "${BUILD_HOME}/cache"
    chown -h -- "${BUILD_USER}:${BUILD_USER}" "${BUILD_HOME}/cache"; chmod 0700 -- "${BUILD_HOME}/cache"
  fi
  if [ "$APPLY" -eq 1 ] || getent passwd "$BUILD_USER" >/dev/null; then
    # shellcheck source=lib/build-privsep.sh
    . "${SELF_DIR}/lib/build-privsep.sh"
    if [ "$APPLY" -eq 1 ]; then
      dms_build_identity_check "$BUILD_USER" "$BUILD_HOME" "$RUNTIME_USER" "$RUNTIME_GROUP" || die "the build identity is not least-privilege"
    fi
  fi
}

# ── Deploy operator ─────────────────────────────────────────────────────────
provision_operator() {
  local home key_line key_type staged listing verdict keytmp
  echo "== Deploy operator =="
  [[ "$OPERATOR_USER" =~ ^[a-z][a-z0-9-]{2,31}$ ]] || die "--operator-user must be 3-32 characters of [a-z0-9-], starting with a letter"
  # The rule's argument expressions are sudo regular expressions (sudo 1.9.10+).
  local sudo_version
  sudo_version="$(sudo -V | sed -n 's/^Sudo version \([0-9][0-9.]*\).*/\1/p' | head -n 1)"
  if [ -z "$sudo_version" ] || ! printf '%s\n%s\n' 1.9.10 "$sudo_version" | sort -V -C; then
    die "sudo ${sudo_version:-of unknown version} predates 1.9.10: it cannot parse the operator rule's regular expressions — nothing was changed"
  fi
  case "$OPERATOR_USER" in
    root|"$ADMIN_USER"|"$BUILD_USER"|"$RUNTIME_USER"|www-data|postgres|nobody) die "--operator-user must be a dedicated account, not '${OPERATOR_USER}'" ;;
  esac
  if [ ! -f "$OPERATOR_KEY" ] || [ -L "$OPERATOR_KEY" ]; then
    die "--operator-key must be a regular file holding one public key"
  fi
  [ "$(grep -cv '^[[:space:]]*$' "$OPERATOR_KEY")" = 1 ] || die "--operator-key must hold exactly one public key line"
  key_type="$(awk 'NF { print $1; exit }' "$OPERATOR_KEY")"
  [ "$key_type" = "ssh-ed25519" ] || die "the operator key must be ssh-ed25519 (got '${key_type}')"
  ssh-keygen -l -f "$OPERATOR_KEY" >/dev/null 2>&1 || die "--operator-key is not a valid public key"
  # Options are set here, never taken from the file; the comment is fixed.
  key_line="restrict,command=\"${ENTRYPOINT}\" ssh-ed25519 $(awk 'NF { print $2; exit }' "$OPERATOR_KEY") dms-deploy-operator"
  echo "operator key: $(ssh-keygen -l -E sha256 -f "$OPERATOR_KEY" | awk '{print $1, $2}')"

  if getent passwd "$OPERATOR_USER" >/dev/null; then
    # Re-provisioning is allowed; converting a live or grouped account is not.
    if [ "$(id -G -- "$OPERATOR_USER" | wc -w)" -ne 1 ]; then
      die "existing account ${OPERATOR_USER} belongs to supplementary groups ($(id -nG -- "$OPERATOR_USER")) — the operator has its own group only"
    fi
    if pgrep -u "$(id -u -- "$OPERATOR_USER")" >/dev/null 2>&1; then
      die "existing account ${OPERATOR_USER} has running processes — refusing to change its home while they could race the change"
    fi
    home="$(getent passwd "$OPERATOR_USER" | cut -d: -f6)"
    echo "account ${OPERATOR_USER} exists (uid $(id -u -- "$OPERATOR_USER"), home ${home})"
  else
    home="/home/${OPERATOR_USER}"
    plan "create account ${OPERATOR_USER} (own group only, shell /bin/bash for the forced command, password locked, home ${home} owned by root)"
    if [ "$APPLY" -eq 1 ]; then
      useradd --user-group --no-create-home --home-dir "$home" --shell /bin/bash "$OPERATOR_USER"
    fi
  fi
  case "$home" in /home/[a-z]*) ;; *) die "operator home '${home}' is not under /home" ;; esac

  plan "own ${home} and ${home}/.ssh by root (0755) and write ${home}/.ssh/authorized_keys (root, 0644): one key, restrict + forced entrypoint"
  if [ "$APPLY" -eq 1 ]; then
    [ ! -L "$home" ] || die "${home} is a symlink"
    install -d -o root -g root -m 0755 -- "$home"
    chown -h root:root -- "$home"; chmod 0755 -- "$home"
    [ ! -L "${home}/.ssh" ] || die "${home}/.ssh is a symlink"
    install -d -o root -g root -m 0755 -- "${home}/.ssh"
    chown -h root:root -- "${home}/.ssh"; chmod 0755 -- "${home}/.ssh"
    rm -f -- "${home}/.ssh/authorized_keys2"
    # Written in root's own directory, then renamed into place: a rename
    # replaces whatever entry sits at the destination (a planted symlink
    # included) and never writes through it.
    keytmp="$(mktemp /root/.dms-operator-key.XXXXXX)"
    printf '%s\n' "$key_line" > "$keytmp"
    chown root:root -- "$keytmp"; chmod 0644 -- "$keytmp"
    mv -f -T -- "$keytmp" "${home}/.ssh/authorized_keys"
    # Key-only: the password stays locked (also the provider console).
    usermod -L -- "$OPERATOR_USER"
  fi

  plan "install ${ENTRYPOINT} (root:root 0755) from scripts/deploy-operator-entrypoint.sh"
  if [ "$APPLY" -eq 1 ]; then
    install -o root -g root -m 0755 -- "${SELF_DIR}/deploy-operator-entrypoint.sh" "${ENTRYPOINT}.new"
    mv -f -- "${ENTRYPOINT}.new" "$ENTRYPOINT"
  fi

  staged="$(dirname -- "$SUDOERS")/.$(basename -- "$SUDOERS").staged"
  plan "render ${SUDOERS} (entrypoint only, narrow arguments), check it with visudo -cf, move it into place, re-check the whole policy"
  if [ "$APPLY" -eq 1 ]; then
    ( umask 0337 && dms_sudo_render_operator_rule "$OPERATOR_USER" "$ENTRYPOINT" > "$staged" )
    chown root:root -- "$staged"; chmod 0440 -- "$staged"
    visudo -c -q -f "$staged" || { rm -f -- "$staged"; die "the rendered operator rule does not parse — nothing was installed"; }
    mv -f -- "$staged" "$SUDOERS"
    if ! visudo -c -q; then
      mv -f -- "$SUDOERS" "${staged}.rejected"
      die "the sudoers policy did not parse with the operator rule — it was moved back out (${staged}.rejected)"
    fi
    listing="$(sudo -ll -U "$OPERATOR_USER")"
    verdict="$(dms_sudo_classify "$ENTRYPOINT" 0 <<< "$listing" || true)"
    echo "operator sudo verdict: ${verdict}"
    [ "$verdict" = "OPERATOR" ] || die "the operator's effective sudo rights are not the entrypoint only (${verdict})"
  fi
}

# ── Retire the administrator's passwordless sudo (OD-6) ─────────────────────
retire_admin_nopasswd() {
  local listing status operator_ok=0 user file content candidates=() without verdict stamp
  echo "== Retire passwordless sudo of the administrator (${ADMIN_USER}) =="
  [ "$PASSWORD_VERIFIED" -eq 1 ] || die "--admin-password-verified is required: the OWNER must first prove 'sudo -k; sudo -v' with the administrator's password in their own terminal"
  status="$(passwd -S "$ADMIN_USER" | awk '{print $2}')"
  [ "$status" = "P" ] || die "the administrator has no usable password (passwd -S: ${status}) — retiring passwordless sudo would lock it out"

  # A provisioned operator must already carry deploys without the administrator.
  while IFS=: read -r user _; do
    [ "$user" = "root" ] && continue
    if [ "$(dms_sudo_classify "$ENTRYPOINT" 0 <<< "$(sudo -ll -U "$user")" || true)" = "OPERATOR" ]; then
      operator_ok=1
    fi
  done < <(getent passwd)
  [ "$operator_ok" -eq 1 ] || die "no deploy operator is provisioned — provision and verify it first"

  # Which sudoers entries give the administrator ALL without a password.
  listing="$(sudo -ll -U "$ADMIN_USER")"
  mapfile -t candidates < <(awk '
    function flush() { if (file != "" && nopw && all) print file }
    /^Sudoers entry:/ { flush(); file = $3; nopw = 0; all = 0; incmds = 0; next }
    /^[ \t]+Options:/ { if ($0 ~ /!authenticate/) nopw = 1; next }
    /^[ \t]+Commands:/ { incmds = 1; next }
    incmds && /^\tALL$/ { all = 1; next }
    END { flush() }
  ' <<< "$listing")
  # Without them, the administrator must still be ADMIN_PASSWORD: password-only
  # sudo with a rule that reaches root, and no Defaults that bypass or redirect
  # its password (rootpw/targetpw/runaspw — root's password is locked here).
  without="$(awk -v skip="$(printf '%s\n' "${candidates[@]}")" '
    BEGIN { n = split(skip, s, "\n"); for (i = 1; i <= n; i++) if (s[i] != "") drop[s[i]] = 1 }
    /^Sudoers entry:/ { dropping = ($3 in drop) }
    /^User .* may run the following commands/ { dropping = 0 }
    !dropping { print }
  ' <<< "$listing")"
  verdict="$(dms_sudo_classify "$ENTRYPOINT" 1 <<< "$without" || true)"
  [ "$verdict" = "ADMIN_PASSWORD" ] \
    || die "without its passwordless rule the administrator would not hold password-only root sudo (${verdict}) — refusing; it would lock the administrator out"
  if [ "${#candidates[@]}" -eq 0 ]; then
    echo "the administrator holds no passwordless unrestricted sudo — nothing to retire"
    return 0
  fi
  stamp="$(date -u +%Y%m%dT%H%M%SZ)"
  for file in "${candidates[@]}"; do
    case "$file" in /etc/sudoers.d/*) ;; *) die "the passwordless rule lives in ${file}, not a drop-in — change it by hand under visudo" ;; esac
    if [ ! -f "$file" ] || [ -L "$file" ]; then die "${file} is not a regular file"; fi
    content="$(grep -Ev '^[[:space:]]*(#|$)' "$file" | sed -E 's/[[:space:]]+/ /g; s/ $//')"
    [[ "$content" =~ ^${ADMIN_USER}\ ALL\ ?=\ ?\(ALL(\ ?:\ ?ALL)?\)\ NOPASSWD:\ ?ALL$ ]] \
      || die "${file} holds more than the administrator's single passwordless rule — change it by hand under visudo"
    plan "move ${file} to ${RETIRED_DIR}/$(basename -- "$file").${stamp} (kept), then re-check the whole policy"
    if [ "$APPLY" -eq 1 ]; then
      install -d -o root -g root -m 0700 -- "$RETIRED_DIR"
      mv -- "$file" "${RETIRED_DIR}/$(basename -- "$file").${stamp}"
      if ! visudo -c -q; then
        mv -- "${RETIRED_DIR}/$(basename -- "$file").${stamp}" "$file"
        die "the sudoers policy did not parse without ${file} — it was moved back"
      fi
    fi
  done
  if [ "$APPLY" -eq 1 ]; then
    status="$(dms_sudo_classify "$ENTRYPOINT" 1 <<< "$(sudo -ll -U "$ADMIN_USER")" || true)"
    echo "administrator sudo verdict: ${status}"
    [ "$status" = "ADMIN_PASSWORD" ] || die "the administrator's sudo is not password-only after the change (${status})"
    echo "ADMIN_NOPASSWD=RETIRED — the administrator's sudo now needs its password"
  fi
}

if [ "$DO_BUILD" -eq 1 ]; then provision_build_identity; fi
if [ "$DO_OPERATOR" -eq 1 ]; then provision_operator; fi
if [ "$DO_RETIRE" -eq 1 ]; then retire_admin_nopasswd; fi
if [ "$APPLY" -eq 1 ]; then
  echo "PROVISION_DEPLOY_IDENTITIES=APPLIED — verify with: bash scripts/sudo-policy-check.sh"
else
  echo "PROVISION_DEPLOY_IDENTITIES=DRY_RUN — nothing was changed; re-run with --apply"
fi
