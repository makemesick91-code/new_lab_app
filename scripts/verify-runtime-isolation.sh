#!/usr/bin/env bash
#
# INFRA-SEC-RUNTIME-1 — DaengtisiaMS runtime isolation verifier.
#
# READ-ONLY. Proves that DaengtisiaMS runs under its own dedicated Unix identity
# and that the co-tenant application sharing this host cannot reach DaengtisiaMS
# secrets or private clinical storage.
#
# It never creates, moves, deletes, chowns or chmods anything, never restarts a
# service, never prints the contents of a secret or a patient file, and never
# runs a database command. Access is proven with `test -r` / `test -w` on the
# metadata only.
#
# Usage:
#   bash scripts/verify-runtime-isolation.sh [options]
#
# Options:
#   --app-dir DIR        Application root            (default: from identity file)
#   --identity-file F    Runtime identity authority  (default: deploy/runtime-identity.conf)
#   --fpm-pool-dir DIR   Override PHP-FPM pool dir   (tests)
#   --nginx-site F       Override nginx site file    (tests)
#   --systemd-dir DIR    Override systemd unit dir   (default: /etc/systemd/system)
#   --require-host       Treat "cannot inspect this host fact" as FAIL, not SKIP.
#                        Used by the deploy/rollback path on the production VPS.
#   --skip-os-account    Skip live OS-account inspection (existence, shell,
#                        supplementary groups) and evaluate only the file and
#                        configuration invariants. FOR AUTOMATED CONTRACT TESTS
#                        against synthetic fixtures, where the declared identity
#                        is the unprivileged test user rather than the real
#                        production account. Mutually exclusive with
#                        --require-host, so the production path can never use it.
#   --quiet              Only print the summary and failures.
#
# Exit: 0 = every applicable check passed. 1 = at least one FAIL.
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"

IDENTITY_FILE="${REPO_DIR}/deploy/runtime-identity.conf"
APP_DIR=""
FPM_POOL_DIR_OVERRIDE=""
NGINX_SITE_OVERRIDE=""
SYSTEMD_DIR="/etc/systemd/system"
REQUIRE_HOST=0
SKIP_OS_ACCOUNT=0
QUIET=0

while [ $# -gt 0 ]; do
  case "$1" in
    --app-dir) APP_DIR="${2:?--app-dir needs a value}"; shift 2 ;;
    --identity-file) IDENTITY_FILE="${2:?--identity-file needs a value}"; shift 2 ;;
    --fpm-pool-dir) FPM_POOL_DIR_OVERRIDE="${2:?--fpm-pool-dir needs a value}"; shift 2 ;;
    --nginx-site) NGINX_SITE_OVERRIDE="${2:?--nginx-site needs a value}"; shift 2 ;;
    --systemd-dir) SYSTEMD_DIR="${2:?--systemd-dir needs a value}"; shift 2 ;;
    --require-host) REQUIRE_HOST=1; shift ;;
    --skip-os-account) SKIP_OS_ACCOUNT=1; shift ;;
    --quiet) QUIET=1; shift ;;
    -h|--help) sed -n '2,30p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "verify-runtime-isolation: unknown option '$1'" >&2; exit 2 ;;
  esac
done

# The fixture escape hatch must never be reachable from the production path.
if [ "$REQUIRE_HOST" -eq 1 ] && [ "$SKIP_OS_ACCOUNT" -eq 1 ]; then
  echo "FATAL: --skip-os-account is a test-fixture flag and cannot be combined with --require-host" >&2
  exit 2
fi

PASS_COUNT=0
FAIL_COUNT=0
SKIP_COUNT=0

ok()   { PASS_COUNT=$((PASS_COUNT + 1)); [ "$QUIET" -eq 1 ] || echo "  GO   $1"; }
bad()  { FAIL_COUNT=$((FAIL_COUNT + 1)); echo "  FAIL $1"; }
# A host fact we cannot inspect from here. Under --require-host this is a FAIL:
# the production path must never report GO on evidence it could not actually see.
skip() {
  if [ "$REQUIRE_HOST" -eq 1 ]; then
    bad "$1 (host evidence required but not inspectable)"
  else
    SKIP_COUNT=$((SKIP_COUNT + 1))
    [ "$QUIET" -eq 1 ] || echo "  SKIP $1"
  fi
}
section() { [ "$QUIET" -eq 1 ] || echo "== $1 =="; }

# ── 1. Identity authority ───────────────────────────────────────────────────
section "RUNTIME IDENTITY AUTHORITY"

if [ ! -r "$IDENTITY_FILE" ]; then
  bad "identity file unreadable: ${IDENTITY_FILE}"
  echo "RUNTIME ISOLATION: NOT GO (no identity authority — refusing to guess)"
  exit 1
fi
# The file is committed, contains no secrets, and is KEY=value only.
# shellcheck disable=SC1090
. "$IDENTITY_FILE"
ok "identity authority readable (${IDENTITY_FILE})"

# Scalar keys that must carry a value.
for key in DMS_RUNTIME_USER DMS_RUNTIME_GROUP DMS_FPM_POOL DMS_FPM_SOCKET \
           DMS_FPM_POOL_FILE DMS_NGINX_SITE DMS_QUEUE_SERVICE DMS_APP_DIR \
           DMS_RUNTIME_WRITABLE_PATHS; do
  if [ -z "${!key:-}" ]; then
    bad "identity authority missing required key ${key}"
  fi
done
# List keys that must be DECLARED but may legitimately be scoped to empty (a
# caller verifying a narrower surface). An undeclared key, by contrast, means the
# authority is incomplete and the corresponding invariant would be skipped
# silently — which is exactly the failure mode this gate exists to prevent.
for key in DMS_SOURCE_IMMUTABLE_PATHS DMS_PRIVATE_PATHS DMS_FORBIDDEN_RUNTIME_USERS; do
  if ! grep -qE "^[[:space:]]*${key}=" "$IDENTITY_FILE"; then
    bad "identity authority does not declare ${key}"
  fi
done

RUNTIME_USER="${DMS_RUNTIME_USER:-}"
RUNTIME_GROUP="${DMS_RUNTIME_GROUP:-}"
[ -n "$APP_DIR" ] || APP_DIR="${DMS_APP_DIR:-$REPO_DIR}"
FPM_POOL_FILE="${FPM_POOL_DIR_OVERRIDE:+${FPM_POOL_DIR_OVERRIDE}/$(basename "${DMS_FPM_POOL_FILE}")}"
[ -n "$FPM_POOL_FILE" ] || FPM_POOL_FILE="${DMS_FPM_POOL_FILE}"
DEFAULT_POOL_FILE="${DMS_FPM_DEFAULT_POOL_FILE:-}"
if [ -n "$FPM_POOL_DIR_OVERRIDE" ] && [ -n "$DEFAULT_POOL_FILE" ]; then
  DEFAULT_POOL_FILE="${FPM_POOL_DIR_OVERRIDE}/$(basename "$DEFAULT_POOL_FILE")"
fi
NGINX_SITE="${NGINX_SITE_OVERRIDE:-${DMS_NGINX_SITE}}"

# The runtime must never be a privileged or shared/co-tenant account. This is the
# invariant that replaces the old "first FPM pool user wins" heuristic.
for forbidden in ${DMS_FORBIDDEN_RUNTIME_USERS:-root www-data}; do
  if [ "$RUNTIME_USER" = "$forbidden" ]; then
    bad "declared runtime user '${RUNTIME_USER}' is forbidden (privileged or shared with a co-tenant)"
  fi
done
if [ "$FAIL_COUNT" -eq 0 ]; then
  ok "declared runtime identity ${RUNTIME_USER}:${RUNTIME_GROUP} is dedicated and unprivileged"
fi

# ── 2. OS account ───────────────────────────────────────────────────────────
section "DEDICATED OS ACCOUNT"

if [ "$SKIP_OS_ACCOUNT" -eq 1 ]; then
  [ "$QUIET" -eq 1 ] || echo "  ---- OS account inspection skipped (fixture mode)"
elif command -v getent >/dev/null 2>&1; then
  if getent passwd "$RUNTIME_USER" >/dev/null 2>&1; then
    ok "user '${RUNTIME_USER}' exists"

    RUNTIME_SHELL="$(getent passwd "$RUNTIME_USER" | awk -F: '{print $7}')"
    case "$RUNTIME_SHELL" in
      */nologin|*/false) ok "user '${RUNTIME_USER}' has a no-login shell (${RUNTIME_SHELL})" ;;
      *) bad "user '${RUNTIME_USER}' has an interactive shell (${RUNTIME_SHELL})" ;;
    esac

    PRIMARY_GROUP="$(id -gn "$RUNTIME_USER" 2>/dev/null || echo '')"
    if [ "$PRIMARY_GROUP" = "$RUNTIME_GROUP" ]; then
      ok "primary group is '${RUNTIME_GROUP}'"
    else
      bad "primary group is '${PRIMARY_GROUP}', expected '${RUNTIME_GROUP}'"
    fi

    # Least privilege: no administrative or co-tenant supplementary groups.
    RUNTIME_GROUPS="$(id -Gn "$RUNTIME_USER" 2>/dev/null || echo '')"
    LEAKED=""
    for g in sudo docker adm www-data postgres staff admin; do
      case " ${RUNTIME_GROUPS} " in *" ${g} "*) LEAKED="${LEAKED} ${g}" ;; esac
    done
    if [ -n "$LEAKED" ]; then
      bad "user '${RUNTIME_USER}' holds privileged/shared group(s):${LEAKED}"
    else
      ok "user '${RUNTIME_USER}' holds no privileged or co-tenant group"
    fi
  else
    skip "user '${RUNTIME_USER}' exists"
  fi

  if getent group "$RUNTIME_GROUP" >/dev/null 2>&1; then
    ok "group '${RUNTIME_GROUP}' exists"
    # The whole point: the co-tenant identity must NOT be able to reach
    # DaengtisiaMS group-readable material.
    GROUP_MEMBERS="$(getent group "$RUNTIME_GROUP" | awk -F: '{print $4}')"
    INTRUDERS=""
    for m in $(echo "$GROUP_MEMBERS" | tr ',' ' '); do
      [ -n "$m" ] || continue
      [ "$m" = "$RUNTIME_USER" ] && continue
      INTRUDERS="${INTRUDERS} ${m}"
    done
    if [ -n "$INTRUDERS" ]; then
      bad "group '${RUNTIME_GROUP}' has non-runtime member(s):${INTRUDERS} — co-tenant isolation broken"
    else
      ok "group '${RUNTIME_GROUP}' has no foreign member"
    fi
  else
    skip "group '${RUNTIME_GROUP}' exists"
  fi
else
  skip "OS account inspection (getent unavailable)"
fi

# ── 3. PHP-FPM pool ─────────────────────────────────────────────────────────
section "DEDICATED PHP-FPM POOL"

if [ -r "$FPM_POOL_FILE" ]; then
  ok "pool file present (${FPM_POOL_FILE})"
  pool_val() { grep -E "^[[:space:]]*$1[[:space:]]*=" "$FPM_POOL_FILE" | head -1 | sed -E 's/^[^=]*=[[:space:]]*//' | tr -d '[:space:]'; }

  if grep -qE "^[[:space:]]*\[${DMS_FPM_POOL}\][[:space:]]*$" "$FPM_POOL_FILE"; then
    ok "pool name is [${DMS_FPM_POOL}]"
  else
    bad "pool file does not declare [${DMS_FPM_POOL}]"
  fi

  POOL_USER="$(pool_val user)"
  POOL_GROUP="$(pool_val group)"
  POOL_LISTEN="$(pool_val listen)"

  if [ "$POOL_USER" = "$RUNTIME_USER" ]; then
    ok "pool runs as user '${POOL_USER}'"
  else
    bad "pool user is '${POOL_USER}', expected '${RUNTIME_USER}'"
  fi
  if [ "$POOL_GROUP" = "$RUNTIME_GROUP" ]; then
    ok "pool runs as group '${POOL_GROUP}'"
  else
    bad "pool group is '${POOL_GROUP}', expected '${RUNTIME_GROUP}'"
  fi
  if [ "$POOL_LISTEN" = "$DMS_FPM_SOCKET" ]; then
    ok "pool listens on the dedicated socket"
  else
    bad "pool listens on '${POOL_LISTEN}', expected '${DMS_FPM_SOCKET}'"
  fi
else
  skip "dedicated pool file present (${FPM_POOL_FILE})"
fi

# The distribution default pool runs as the shared account. Once DaengtisiaMS has
# its own pool the default one must not remain active on this PHP version, or a
# www-data worker can still execute DaengtisiaMS code.
if [ -n "$DEFAULT_POOL_FILE" ]; then
  if [ -e "$DEFAULT_POOL_FILE" ]; then
    bad "default shared pool still active: ${DEFAULT_POOL_FILE}"
  else
    ok "default shared pool is not active"
  fi
fi

if [ -n "${DMS_FPM_SOCKET:-}" ]; then
  if [ -S "$DMS_FPM_SOCKET" ]; then
    ok "dedicated FPM socket exists (${DMS_FPM_SOCKET})"
  else
    skip "dedicated FPM socket exists (${DMS_FPM_SOCKET})"
  fi
fi

# ── 4. nginx binding ────────────────────────────────────────────────────────
section "NGINX BINDING"

if [ -r "$NGINX_SITE" ]; then
  BOUND="$(grep -E '^[[:space:]]*fastcgi_pass' "$NGINX_SITE" | head -1 | sed -E 's/.*fastcgi_pass[[:space:]]+//; s/;[[:space:]]*$//' | tr -d '[:space:]')"
  if [ "$BOUND" = "unix:${DMS_FPM_SOCKET}" ]; then
    ok "site binds the dedicated socket"
  else
    bad "site binds '${BOUND}', expected 'unix:${DMS_FPM_SOCKET}'"
  fi
else
  skip "nginx site readable (${NGINX_SITE})"
fi

# ── 5. Service identities ───────────────────────────────────────────────────
section "SERVICE IDENTITIES"

unit_identity_ok() {
  # $1 unit name. Reads the installed unit plus any drop-in override.
  local unit="$1" unit_file="${SYSTEMD_DIR}/$1" u="" g="" f
  [ -r "$unit_file" ] || return 2
  u="$(grep -hE '^[[:space:]]*User[[:space:]]*=' "$unit_file" | tail -1 | sed -E 's/^[^=]*=[[:space:]]*//' | tr -d '[:space:]')"
  g="$(grep -hE '^[[:space:]]*Group[[:space:]]*=' "$unit_file" | tail -1 | sed -E 's/^[^=]*=[[:space:]]*//' | tr -d '[:space:]')"
  if [ -d "${SYSTEMD_DIR}/${unit}.d" ]; then
    for f in "${SYSTEMD_DIR}/${unit}.d"/*.conf; do
      [ -r "$f" ] || continue
      local du dg
      du="$(grep -hE '^[[:space:]]*User[[:space:]]*=' "$f" | tail -1 | sed -E 's/^[^=]*=[[:space:]]*//' | tr -d '[:space:]')"
      dg="$(grep -hE '^[[:space:]]*Group[[:space:]]*=' "$f" | tail -1 | sed -E 's/^[^=]*=[[:space:]]*//' | tr -d '[:space:]')"
      [ -n "$du" ] && u="$du"
      [ -n "$dg" ] && g="$dg"
    done
  fi
  [ "$u" = "$RUNTIME_USER" ] && [ "$g" = "$RUNTIME_GROUP" ] && return 0
  echo "${u:-<unset>}:${g:-<unset>}"
  return 1
}

for unit in "$DMS_QUEUE_SERVICE" ${DMS_BACKGROUND_SERVICES:-}; do
  [ -n "$unit" ] || continue
  set +e
  actual="$(unit_identity_ok "$unit")"
  rc=$?
  set -e
  case "$rc" in
    0) ok "unit ${unit} runs as ${RUNTIME_USER}:${RUNTIME_GROUP}" ;;
    2) skip "unit ${unit} installed" ;;
    *) bad "unit ${unit} runs as ${actual}, expected ${RUNTIME_USER}:${RUNTIME_GROUP}" ;;
  esac
done

# The tracked unit source must already declare the dedicated identity, so a
# reinstall from the repository can never restore the shared account.
TRACKED_UNIT="${REPO_DIR}/${DMS_QUEUE_UNIT_SOURCE:-}"
if [ -n "${DMS_QUEUE_UNIT_SOURCE:-}" ] && [ -r "$TRACKED_UNIT" ]; then
  if grep -qE "^[[:space:]]*User[[:space:]]*=[[:space:]]*${RUNTIME_USER}[[:space:]]*$" "$TRACKED_UNIT" \
     && grep -qE "^[[:space:]]*Group[[:space:]]*=[[:space:]]*${RUNTIME_GROUP}[[:space:]]*$" "$TRACKED_UNIT"; then
    ok "tracked queue unit source declares the dedicated identity"
  else
    bad "tracked queue unit source does not declare ${RUNTIME_USER}:${RUNTIME_GROUP}"
  fi
fi

# ── 6. Secret isolation ─────────────────────────────────────────────────────
section "SECRET ISOLATION"

ENV_FILE="${APP_DIR}/.env"
if [ -e "$ENV_FILE" ]; then
  if [ -L "$ENV_FILE" ]; then
    bad "environment file is a symlink — refusing to reason about its target"
  else
    E_OWNER="$(stat -c '%U' "$ENV_FILE")"
    E_GROUP="$(stat -c '%G' "$ENV_FILE")"
    E_MODE="$(stat -c '%a' "$ENV_FILE")"
    EXPECTED_SECRET_OWNER="${DMS_SECRET_OWNER:-root}"

    if [ "$E_OWNER" = "$EXPECTED_SECRET_OWNER" ]; then
      ok "environment file owned by ${EXPECTED_SECRET_OWNER}"
    else
      bad "environment file owned by '${E_OWNER}', expected '${EXPECTED_SECRET_OWNER}'"
    fi

    if [ "$E_GROUP" = "$RUNTIME_GROUP" ]; then
      ok "environment file group is the dedicated runtime group"
    else
      bad "environment file group is '${E_GROUP}', expected '${RUNTIME_GROUP}' (a shared group re-opens co-tenant read)"
    fi

    case "$E_MODE" in
      640|600) ok "environment file mode ${E_MODE} (not world-readable)" ;;
      *) bad "environment file mode is ${E_MODE}, expected 640 or stricter" ;;
    esac
  fi
else
  skip "environment file present (${ENV_FILE})"
fi

# No ACL may re-grant what the mode removed.
if command -v getfacl >/dev/null 2>&1 && [ -e "$ENV_FILE" ]; then
  if getfacl -p --omit-header "$ENV_FILE" 2>/dev/null | grep -qE '^(user|group):[^:]+:[^-]'; then
    bad "environment file carries a named-user/group ACL entry"
  else
    ok "environment file carries no named ACL entry"
  fi
elif [ -e "$ENV_FILE" ]; then
  # Without the acl tools, the kernel still reports an extended ACL as a
  # trailing '+' in the mode string. Absence of '+' proves absence of an ACL.
  if ls -ld "$ENV_FILE" | awk '{print $1}' | grep -q '+$'; then
    bad "environment file mode string reports an extended ACL"
  else
    ok "environment file carries no extended ACL"
  fi
fi

# ── 7. Runtime-writable paths ───────────────────────────────────────────────
section "RUNTIME WRITABLE PATHS"

for rel in ${DMS_RUNTIME_WRITABLE_PATHS:-}; do
  p="${APP_DIR}/${rel}"
  if [ -e "$p" ]; then
    P_OWNER="$(stat -c '%U' "$p")"
    P_GROUP="$(stat -c '%G' "$p")"
    if [ "$P_OWNER" = "$RUNTIME_USER" ] && [ "$P_GROUP" = "$RUNTIME_GROUP" ]; then
      ok "${rel} owned by ${RUNTIME_USER}:${RUNTIME_GROUP}"
    else
      bad "${rel} owned by ${P_OWNER}:${P_GROUP}, expected ${RUNTIME_USER}:${RUNTIME_GROUP}"
    fi
  else
    skip "${rel} present"
  fi
done

# ── 8. Source immutability ──────────────────────────────────────────────────
section "SOURCE IMMUTABILITY"

for rel in ${DMS_SOURCE_IMMUTABLE_PATHS:-}; do
  p="${APP_DIR}/${rel}"
  if [ -e "$p" ]; then
    S_OWNER="$(stat -c '%U' "$p")"
    S_MODE="$(stat -c '%a' "$p")"
    # Writable by the runtime if the runtime owns it, or if group/other write is
    # granted (the runtime is not in the owning group here, but a world-writable
    # source file would be writable by anyone including the co-tenant).
    GROUP_W=$(( (8#${S_MODE} & 0020) != 0 ))
    OTHER_W=$(( (8#${S_MODE} & 0002) != 0 ))
    if [ "$S_OWNER" = "$RUNTIME_USER" ]; then
      bad "${rel} is owned by the runtime user — application source must not be runtime-writable"
    elif [ "$OTHER_W" -eq 1 ]; then
      bad "${rel} is world-writable (mode ${S_MODE})"
    elif [ "$GROUP_W" -eq 1 ] && [ "$(stat -c '%G' "$p")" = "$RUNTIME_GROUP" ]; then
      bad "${rel} is group-writable by the runtime group (mode ${S_MODE})"
    else
      ok "${rel} is not runtime-writable (owner ${S_OWNER}, mode ${S_MODE})"
    fi
  else
    skip "${rel} present"
  fi
done

# ── 9. Private clinical storage + secret backups ────────────────────────────
section "PRIVATE STORAGE ISOLATION"

for rel in ${DMS_PRIVATE_PATHS:-}; do
  p="${APP_DIR}/${rel}"
  if [ -d "$p" ]; then
    D_MODE="$(stat -c '%a' "$p")"
    D_OWNER="$(stat -c '%U' "$p")"
    if [ $(( 8#${D_MODE} & 0005 )) -ne 0 ]; then
      bad "${rel} is world-readable/traversable (mode ${D_MODE}) — co-tenant can reach clinical evidence"
    elif [ "$D_OWNER" != "$RUNTIME_USER" ]; then
      bad "${rel} owned by ${D_OWNER}, expected ${RUNTIME_USER}"
    else
      ok "${rel} is private to ${RUNTIME_USER} (mode ${D_MODE})"
    fi
  else
    skip "${rel} present"
  fi
done

# ── 10. Live co-tenant denial ───────────────────────────────────────────────
# The core acceptance proof. Only root can assume another identity, so this runs
# on the production host during deploy; elsewhere it is reported as SKIP rather
# than silently claimed as passing.
section "CO-TENANT NEGATIVE PROOF"

if [ "$(id -u)" -eq 0 ] && command -v runuser >/dev/null 2>&1; then
  for intruder in ${DMS_FORBIDDEN_RUNTIME_USERS:-www-data}; do
    [ "$intruder" = "root" ] && continue
    getent passwd "$intruder" >/dev/null 2>&1 || continue

    if [ -e "$ENV_FILE" ]; then
      if runuser -u "$intruder" -- test -r "$ENV_FILE" 2>/dev/null; then
        bad "'${intruder}' CAN read the environment file — co-tenant isolation FAILED"
      else
        ok "'${intruder}' cannot read the environment file"
      fi
    fi

    for rel in ${DMS_PRIVATE_PATHS:-}; do
      p="${APP_DIR}/${rel}"
      [ -d "$p" ] || continue
      if runuser -u "$intruder" -- test -r "$p" 2>/dev/null; then
        bad "'${intruder}' CAN read ${rel} — private clinical storage exposed"
      else
        ok "'${intruder}' cannot read ${rel}"
      fi
    done
  done

  # Positive counterpart: the dedicated runtime must actually be able to work.
  if getent passwd "$RUNTIME_USER" >/dev/null 2>&1; then
    if [ -e "$ENV_FILE" ]; then
      if runuser -u "$RUNTIME_USER" -- test -r "$ENV_FILE" 2>/dev/null; then
        ok "'${RUNTIME_USER}' can read the environment file"
      else
        bad "'${RUNTIME_USER}' CANNOT read the environment file — the runtime would boot with an empty configuration"
      fi
    fi
    for rel in ${DMS_RUNTIME_WRITABLE_PATHS:-}; do
      p="${APP_DIR}/${rel}"
      [ -d "$p" ] || continue
      if runuser -u "$RUNTIME_USER" -- test -w "$p" 2>/dev/null; then
        ok "'${RUNTIME_USER}' can write ${rel}"
      else
        bad "'${RUNTIME_USER}' CANNOT write ${rel}"
      fi
    done
    for rel in ${DMS_SOURCE_IMMUTABLE_PATHS:-}; do
      p="${APP_DIR}/${rel}"
      [ -e "$p" ] || continue
      if runuser -u "$RUNTIME_USER" -- test -w "$p" 2>/dev/null; then
        bad "'${RUNTIME_USER}' CAN write ${rel} — application source is runtime-writable"
      else
        ok "'${RUNTIME_USER}' cannot write ${rel}"
      fi
    done
  fi
else
  skip "live co-tenant/runtime access proof (requires root)"
fi

# ── Summary ─────────────────────────────────────────────────────────────────
echo
echo "RUNTIME ISOLATION SUMMARY: ${PASS_COUNT} GO / ${FAIL_COUNT} FAIL / ${SKIP_COUNT} SKIP"
if [ "$FAIL_COUNT" -gt 0 ]; then
  echo "RUNTIME ISOLATION: NOT GO"
  exit 1
fi
echo "RUNTIME ISOLATION: GO (runtime=${RUNTIME_USER}:${RUNTIME_GROUP}, pool=${DMS_FPM_POOL}, socket=${DMS_FPM_SOCKET})"
