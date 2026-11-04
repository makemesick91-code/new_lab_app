# shellcheck shell=bash
#
# DMS-POST-MIGRATION-DEV-INFRA-ALIGNMENT-1 — on-host production preflight.
#
# Sourced (never executed) by scripts/deploy-vps.sh and scripts/rollback-vps.sh
# from the immutable deployment snapshot, BEFORE the first mutation: before the
# secret file is touched, before the database is dumped, and before any
# checkout. It answers three questions and refuses on any "no":
#
#   1. Is this the production host?  Hostname and address must match
#      deploy/production-host.conf, and the host must not carry any frozen
#      legacy (Hostinger) hostname or address. A positive match is required:
#      "not Hostinger" is not the same as "is production".
#   2. Is this run privileged?  The deploy chowns runtime paths, restarts
#      PHP-FPM and reloads nginx, so it must run as root (on Biznet: through
#      `sudo -n`). Without this check a non-root run fails part-way instead of
#      refusing up front, and the deploy lock silently falls back to a
#      world-writable directory.
#   3. Is the toolchain present?  The deploy runs `npm ci` AFTER the checkout
#      and `composer install`; a missing tool discovered there leaves new PHP
#      code live beside stale assets and an unmigrated schema.
#
# Every observed value is a PARAMETER — the caller passes `id -u`, `hostname`
# and `hostname -I`. That keeps the function testable without root, and it
# means there is no environment variable an operator could set to talk a
# production deploy past the check.
#
# Usage:
#   dms_production_host_preflight <authority> <euid> <hostname> <addresses> [<tool>...]
# With no tools given, the canonical deploy toolchain below is required. An
# explicit list replaces it for tests and for read-only maintenance tools that
# call fewer programs (scripts/deploy-runner-quarantine-stale-status.sh).
# Returns 0 and prints "PRODUCTION HOST PREFLIGHT: GO" only when every check
# passes; otherwise prints one FATAL line per failed check and returns 1.

dms_preflight_authority_value() {
  local file="$1" key="$2" line value
  line="$(grep -E "^${key}=" "$file" | tail -n 1)"
  value="${line#*=}"
  value="${value%\"}"
  value="${value#\"}"
  printf '%s' "$value"
}

dms_preflight_in_list() {
  local needle="$1" item
  shift
  # The authority stores lists as space-separated words; splitting is intended.
  # shellcheck disable=SC2048,SC2086
  for item in $*; do
    [ "$item" = "$needle" ] && return 0
  done
  return 1
}

dms_production_host_preflight() {
  local authority="${1:-}" euid="${2:-}" observed_host="${3:-}" observed_addrs="${4:-}"
  shift 4 2>/dev/null || true
  local failures=0 tool frozen
  # The canonical toolchain: every program the deploy and the rollback call
  # after their first mutation. Function-local on purpose — no environment can
  # shorten it. Explicit arguments replace it only for tests and for narrower
  # maintenance tools; the deploy and the rollback never pass any.
  local -a tools=("$@")
  if [ "${#tools[@]}" -eq 0 ]; then
    tools=(git composer node npm php pg_dump runuser nginx systemctl)
  fi

  echo "== Production host preflight (before any mutation) =="

  if [ -z "$authority" ] || [ ! -r "$authority" ]; then
    echo "FATAL: production host authority is missing or unreadable (${authority:-<none>}) — refusing to guess" >&2
    return 1
  fi

  local prod_host prod_addr frozen_hosts frozen_addrs
  prod_host="$(dms_preflight_authority_value "$authority" DMS_PRODUCTION_HOSTNAME)"
  prod_addr="$(dms_preflight_authority_value "$authority" DMS_PRODUCTION_ADDRESS)"
  frozen_hosts="$(dms_preflight_authority_value "$authority" DMS_FROZEN_LEGACY_HOSTNAMES)"
  frozen_addrs="$(dms_preflight_authority_value "$authority" DMS_FROZEN_LEGACY_ADDRESSES)"

  if [ -z "$prod_host" ] || [ -z "$prod_addr" ]; then
    echo "FATAL: authority ${authority} does not declare the production hostname and address — refusing to guess" >&2
    return 1
  fi

  # 1. Never the frozen legacy host — checked first and independently, so the
  #    message names the real danger even if the production match also fails.
  if dms_preflight_in_list "$observed_host" "$frozen_hosts"; then
    echo "FATAL: this host '${observed_host}' is FROZEN legacy infrastructure — deploys never run here" >&2
    failures=$((failures + 1))
  fi
  for frozen in $frozen_addrs; do
    if dms_preflight_in_list "$frozen" "$observed_addrs"; then
      echo "FATAL: this host carries the FROZEN legacy address ${frozen} — deploys never run here" >&2
      failures=$((failures + 1))
    fi
  done

  # 1b. Positive identity: exactly the declared production host.
  if [ "$observed_host" = "$prod_host" ]; then
    echo "PASS: hostname is the production host '${prod_host}'"
  else
    echo "FATAL: hostname is '${observed_host:-<empty>}', expected the production host '${prod_host}'" >&2
    failures=$((failures + 1))
  fi
  if dms_preflight_in_list "$prod_addr" "$observed_addrs"; then
    echo "PASS: host carries the production address ${prod_addr}"
  else
    echo "FATAL: host does not carry the production address ${prod_addr} (has: ${observed_addrs:-<none>})" >&2
    failures=$((failures + 1))
  fi

  # 2. Privilege.
  if [ "$euid" = "0" ]; then
    echo "PASS: running as root"
  else
    echo "FATAL: running as uid ${euid:-<unknown>}; the deploy must run as root (on production: sudo -n bash scripts/deploy-vps-runner.sh start)" >&2
    failures=$((failures + 1))
  fi

  # 3. Toolchain — every tool the deploy will call after its first mutation.
  for tool in "${tools[@]}"; do
    if command -v "$tool" >/dev/null 2>&1; then
      echo "PASS: tool '${tool}' is installed"
    else
      echo "FATAL: required tool '${tool}' is not installed on this host" >&2
      failures=$((failures + 1))
    fi
  done

  if [ "$failures" -ne 0 ]; then
    echo "PRODUCTION HOST PREFLIGHT: NOT GO (${failures} failing check(s)) — nothing was backed up, checked out or migrated" >&2
    return 1
  fi

  echo "PRODUCTION HOST PREFLIGHT: GO"
  return 0
}

# DMS-DEV-INFRA-REMEDIATION-2 — the toolchain must also be the RIGHT version.
#
# Presence is not enough: Ubuntu 24.04's archive ships Node 18, and a PHP CLI of
# another minor than the FPM pool (the Hostinger worker ran PHP 8.5 beside an
# 8.3 web pool) would run migrations and artisan on an untested runtime. The
# deploy builds with exactly what CI builds with
# (.github/workflows/foundation-evidence-gates.yml: node-version '22',
# php-version '8.3'), so a mismatch is refused BEFORE the first mutation.
#
# The required versions are function-local: no environment can lower them.
# Every observed version string is a PARAMETER — the caller passes the output
# of `node --version`, `npm --version`, `php -r 'echo PHP_VERSION;'` and the
# first line of `composer --version` — so the check is testable without the
# tools installed.
#
# Usage:
#   dms_production_toolchain_versions <node> <npm> <php> <composer>
# Returns 0 and prints "PRODUCTION TOOLCHAIN VERSIONS: GO" only when all match.
dms_production_toolchain_versions() {
  local node_v="${1:-}" npm_v="${2:-}" php_v="${3:-}" composer_v="${4:-}" failures=0
  local -r node_major=22 npm_major_min=10 php_minor=8.3 composer_major=2

  echo "== Production toolchain versions (before any mutation) =="

  if [[ "$node_v" =~ ^v([0-9]+)\.[0-9]+\.[0-9]+$ ]] && [ "${BASH_REMATCH[1]}" = "$node_major" ]; then
    echo "PASS: Node.js ${node_v} (major ${node_major}, as CI)"
  else
    echo "FATAL: Node.js must be major ${node_major} (as CI builds); found '${node_v:-<absent>}'" >&2
    failures=$((failures + 1))
  fi
  if [[ "$npm_v" =~ ^([0-9]+)\.[0-9]+\.[0-9]+$ ]] && [ "${BASH_REMATCH[1]}" -ge "$npm_major_min" ]; then
    echo "PASS: npm ${npm_v}"
  else
    echo "FATAL: npm ${npm_major_min} or newer is required (bundled with Node.js ${node_major}); found '${npm_v:-<absent>}'" >&2
    failures=$((failures + 1))
  fi
  if [[ "$php_v" =~ ^([0-9]+\.[0-9]+)\.[0-9]+ ]] && [ "${BASH_REMATCH[1]}" = "$php_minor" ]; then
    echo "PASS: PHP CLI ${php_v} (the php${php_minor}-fpm pool's minor, as CI)"
  else
    echo "FATAL: the PHP CLI must be ${php_minor}.x, the same minor as the php${php_minor}-fpm pool and CI; found '${php_v:-<absent>}'" >&2
    failures=$((failures + 1))
  fi
  if [[ "$composer_v" =~ ^Composer\ version\ ([0-9]+)\.[0-9]+\.[0-9]+ ]] && [ "${BASH_REMATCH[1]}" = "$composer_major" ]; then
    echo "PASS: ${composer_v%% [0-9][0-9][0-9][0-9]-*}"
  else
    echo "FATAL: Composer ${composer_major}.x is required; found '${composer_v:-<absent>}'" >&2
    failures=$((failures + 1))
  fi

  if [ "$failures" -ne 0 ]; then
    echo "PRODUCTION TOOLCHAIN VERSIONS: NOT GO (${failures} failing check(s)) — nothing was backed up, checked out or migrated" >&2
    return 1
  fi
  echo "PRODUCTION TOOLCHAIN VERSIONS: GO"
  return 0
}
