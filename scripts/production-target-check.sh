#!/usr/bin/env bash
# DMS-POST-MIGRATION-DEV-INFRA-ALIGNMENT-1 — positive production target check.
#
# Proves, from a WORKSTATION, that an SSH alias names the real production host
# before anyone runs a sensitive command through it. Production moved from
# Hostinger to Biznet Gio on 2026-11-03 and the old alias `daengtisiams-vps` was
# re-pointed at the frozen Hostinger box, so "the alias I always use" is no
# longer evidence of anything. Identity has to be shown, not assumed.
#
# What it checks (all against deploy/production-host.conf):
#   static (no network):  the alias resolves — via `ssh -G` — to the production
#                         address, as the admin account, with strict host-key
#                         checking, no DNS/learned key trust, and EVERY known-hosts
#                         entry for that host equal to the pinned fingerprint.
#   --remote (read-only): connects with BatchMode + StrictHostKeyChecking=yes and
#                         reads `hostname` and `hostname -I` only.
# A target matching any frozen legacy hostname, address or alias is refused.
#
# It never accepts, adds, refreshes or removes a host key, never edits SSH
# configuration and, without --remote, never opens a connection.
#
# Usage:
#   bash scripts/production-target-check.sh [--alias NAME] [--ssh-config FILE]
#                                           [--authority FILE] [--remote]
# Exit: 0 = PRODUCTION_TARGET=VERIFIED, 1 = NOT_VERIFIED, 2 = usage/tooling error.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AUTHORITY="${SCRIPT_DIR}/../deploy/production-host.conf"
ALIAS=""
SSH_CONFIG=""
REMOTE=0

usage() {
  cat >&2 <<'USAGE'
usage: bash scripts/production-target-check.sh [--alias NAME] [--ssh-config FILE]
                                              [--authority FILE] [--remote]
USAGE
  exit 2
}

while [ $# -gt 0 ]; do
  case "$1" in
    --alias) [ $# -ge 2 ] || usage; ALIAS="$2"; shift 2 ;;
    --ssh-config) [ $# -ge 2 ] || usage; SSH_CONFIG="$2"; shift 2 ;;
    --authority) [ $# -ge 2 ] || usage; AUTHORITY="$2"; shift 2 ;;
    --remote) REMOTE=1; shift ;;
    -h|--help) usage ;;
    *) echo "unknown argument: $1" >&2; usage ;;
  esac
done

command -v ssh >/dev/null 2>&1 || { echo "FATAL: ssh is not installed" >&2; exit 2; }
command -v ssh-keygen >/dev/null 2>&1 || { echo "FATAL: ssh-keygen is not installed" >&2; exit 2; }
[ -r "$AUTHORITY" ] || { echo "FATAL: authority file not readable: ${AUTHORITY}" >&2; exit 2; }
if [ -n "$SSH_CONFIG" ] && [ ! -r "$SSH_CONFIG" ]; then
  echo "FATAL: ssh config not readable: ${SSH_CONFIG}" >&2
  exit 2
fi

# Parse the authority key by key — it is data, never sourced as code.
authority_value() {
  local key="$1" line value
  line="$(grep -E "^${key}=" "$AUTHORITY" | tail -n 1)"
  value="${line#*=}"
  value="${value%\"}"
  value="${value#\"}"
  printf '%s' "$value"
}

PROD_HOSTNAME="$(authority_value DMS_PRODUCTION_HOSTNAME)"
PROD_ADDRESS="$(authority_value DMS_PRODUCTION_ADDRESS)"
PROD_ALIAS="$(authority_value DMS_PRODUCTION_SSH_ALIAS)"
PROD_ADMIN="$(authority_value DMS_PRODUCTION_ADMIN_USER)"
PROD_KEY_SHA256="$(authority_value DMS_PRODUCTION_HOST_KEY_SHA256)"
FROZEN_HOSTNAMES="$(authority_value DMS_FROZEN_LEGACY_HOSTNAMES)"
FROZEN_ADDRESSES="$(authority_value DMS_FROZEN_LEGACY_ADDRESSES)"
FROZEN_ALIASES="$(authority_value DMS_FROZEN_LEGACY_SSH_ALIASES)"

for required in PROD_HOSTNAME PROD_ADDRESS PROD_ALIAS PROD_ADMIN PROD_KEY_SHA256; do
  if [ -z "${!required}" ]; then
    echo "FATAL: authority ${AUTHORITY} does not declare ${required} — refusing to guess" >&2
    exit 2
  fi
done

[ -n "$ALIAS" ] || ALIAS="$PROD_ALIAS"

FAILURES=0
pass() { echo "PASS: $*"; }
fail() { echo "FAIL: $*"; FAILURES=$((FAILURES + 1)); }

in_list() {
  local needle="$1" item
  shift
  # The authority stores lists as space-separated words; splitting is intended.
  # shellcheck disable=SC2048,SC2086
  for item in $*; do
    [ "$item" = "$needle" ] && return 0
  done
  return 1
}

echo "== production target check =="
echo "authority: ${AUTHORITY}"
echo "alias:     ${ALIAS}"

if in_list "$ALIAS" "$FROZEN_ALIASES"; then
  fail "alias '${ALIAS}' is a frozen legacy alias — it is never a production alias"
fi

SSH_G_ARGS=()
[ -n "$SSH_CONFIG" ] && SSH_G_ARGS=(-F "$SSH_CONFIG")

if ! RESOLVED="$(ssh "${SSH_G_ARGS[@]}" -G "$ALIAS" 2>/dev/null)"; then
  fail "ssh -G could not resolve alias '${ALIAS}'"
  RESOLVED=""
fi

resolved() {
  printf '%s\n' "$RESOLVED" | awk -v k="$1" '$1 == k { $1 = ""; sub(/^ /, ""); print; exit }'
}

R_HOST="$(resolved hostname)"
R_USER="$(resolved user)"
R_PORT="$(resolved port)"
R_STRICT="$(resolved stricthostkeychecking)"
R_UPDATE="$(resolved updatehostkeys)"
R_DNS="$(resolved verifyhostkeydns)"
R_ALIAS_KEY="$(resolved hostkeyalias)"
R_USER_KH="$(resolved userknownhostsfile)"
R_GLOBAL_KH="$(resolved globalknownhostsfile)"

echo "resolved:  ${R_USER:-?}@${R_HOST:-?}:${R_PORT:-?}"

if in_list "$R_HOST" "$FROZEN_ADDRESSES" || in_list "$R_HOST" "$FROZEN_HOSTNAMES"; then
  fail "alias resolves to FROZEN legacy infrastructure (${R_HOST}) — refusing"
fi

if [ "$R_HOST" = "$PROD_ADDRESS" ]; then
  pass "alias resolves to the production address ${PROD_ADDRESS}"
else
  # A DNS name is not accepted: the alias must pin the address, so a DNS change
  # can never silently move the target.
  fail "alias resolves to '${R_HOST}', expected the pinned production address ${PROD_ADDRESS}"
fi

if [ "$R_USER" = "$PROD_ADMIN" ]; then
  pass "login account is the production admin '${PROD_ADMIN}'"
else
  fail "login account is '${R_USER}', expected '${PROD_ADMIN}' (root has no SSH login on production)"
fi

if [ "$R_STRICT" = "true" ]; then
  pass "StrictHostKeyChecking is enforced"
else
  fail "StrictHostKeyChecking is '${R_STRICT}', expected yes — never accept or auto-add a production host key"
fi

if [ "$R_UPDATE" = "true" ]; then
  fail "UpdateHostKeys is enabled — the client could learn new host keys without review"
else
  pass "UpdateHostKeys does not extend trust automatically"
fi

if [ "$R_DNS" = "true" ] || [ "$R_DNS" = "ask" ]; then
  fail "VerifyHostKeyDNS is '${R_DNS}' — host-key trust must come from the pinned file, not DNS"
else
  pass "VerifyHostKeyDNS is off"
fi

# Known-hosts: the name ssh looks up is the HostKeyAlias when one is set, and is
# bracketed with the port when the port is not 22.
LOOKUP="${R_HOST}"
if [ -n "$R_ALIAS_KEY" ] && [ "$R_ALIAS_KEY" != "none" ]; then
  LOOKUP="$R_ALIAS_KEY"
fi
if [ -n "$R_PORT" ] && [ "$R_PORT" != "22" ]; then
  LOOKUP="[${LOOKUP}]:${R_PORT}"
fi

PINNED_SEEN=0
FOREIGN_SEEN=0
for kh in $R_USER_KH $R_GLOBAL_KH; do
  case "$kh" in
    "~"/*) kh="${HOME}/${kh#\~/}" ;;
  esac
  if [ "$kh" = "/dev/null" ]; then
    if in_list "$kh" "$R_USER_KH"; then
      fail "UserKnownHostsFile is /dev/null — host keys are never remembered, so nothing is pinned"
    fi
    continue
  fi
  [ -r "$kh" ] || continue
  while read -r _host _type fingerprint _rest; do
    [ -n "${fingerprint:-}" ] || continue
    if [ "$fingerprint" = "$PROD_KEY_SHA256" ]; then
      PINNED_SEEN=1
    else
      FOREIGN_SEEN=$((FOREIGN_SEEN + 1))
      fail "known-hosts file ${kh} holds an UNPINNED key for ${LOOKUP}: ${_type} ${fingerprint}"
    fi
  done < <(ssh-keygen -l -F "$LOOKUP" -f "$kh" 2>/dev/null | grep -v '^#')
done

if [ "$PINNED_SEEN" -eq 1 ] && [ "$FOREIGN_SEEN" -eq 0 ]; then
  pass "the only known host key for ${LOOKUP} is the pinned ${PROD_KEY_SHA256}"
elif [ "$PINNED_SEEN" -eq 0 ]; then
  fail "the pinned production host key ${PROD_KEY_SHA256} is not recorded for ${LOOKUP} — verify it out of band; never ssh-keyscan it"
fi

if [ "$REMOTE" -eq 1 ]; then
  if [ "$FAILURES" -ne 0 ]; then
    fail "remote identity check skipped: the static checks failed, so the alias is not safe to connect through"
  else
    # Read-only: two commands that report identity and change nothing.
    if REMOTE_OUT="$(ssh "${SSH_G_ARGS[@]}" \
          -o BatchMode=yes -o ConnectTimeout=15 \
          -o StrictHostKeyChecking=yes -o UpdateHostKeys=no \
          "$ALIAS" 'hostname; hostname -I' 2>/dev/null)"; then
      REMOTE_HOSTNAME="$(printf '%s\n' "$REMOTE_OUT" | sed -n 1p)"
      REMOTE_ADDRESSES="$(printf '%s\n' "$REMOTE_OUT" | sed -n 2p)"
      if [ "$REMOTE_HOSTNAME" = "$PROD_HOSTNAME" ]; then
        pass "remote hostname is '${PROD_HOSTNAME}'"
      else
        fail "remote hostname is '${REMOTE_HOSTNAME}', expected '${PROD_HOSTNAME}'"
      fi
      if in_list "$REMOTE_HOSTNAME" "$FROZEN_HOSTNAMES"; then
        fail "remote host is the FROZEN legacy host '${REMOTE_HOSTNAME}'"
      fi
      if in_list "$PROD_ADDRESS" "$REMOTE_ADDRESSES"; then
        pass "remote host carries the production address ${PROD_ADDRESS}"
      else
        fail "remote host does not carry ${PROD_ADDRESS} (has: ${REMOTE_ADDRESSES})"
      fi
      for frozen in $FROZEN_ADDRESSES; do
        if in_list "$frozen" "$REMOTE_ADDRESSES"; then
          fail "remote host carries the FROZEN legacy address ${frozen}"
        fi
      done
    else
      fail "could not complete the read-only remote identity check over '${ALIAS}'"
    fi
  fi
fi

if [ "$FAILURES" -eq 0 ]; then
  echo "PRODUCTION_TARGET=VERIFIED"
  [ "$REMOTE" -eq 1 ] || echo "note: static checks only — add --remote to confirm the live host identity"
  exit 0
fi

echo "PRODUCTION_TARGET=NOT_VERIFIED (${FAILURES} failing check(s)) — do not run sensitive commands through '${ALIAS}'"
exit 1
