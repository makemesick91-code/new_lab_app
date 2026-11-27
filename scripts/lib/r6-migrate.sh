# shellcheck shell=bash
#
# DMS-SPN4-READY-PILOT-1 R6-B4 (rule 245; Design Lock §13.4 row R6-B4; OD-R6-1;
# F1-B) — the deploy's migrate step behind the host switch for the migrator.
#
# Sourced by scripts/deploy-vps.sh after scripts/lib/runtime-privsep.sh. Root
# runs it, from the application directory.
#
# The switch is a root-owned host file (DMS_R6_SWITCH_FILE, default
# /etc/dms-r6/migrator.conf). It is read, never sourced: only the keys below,
# only plain values.
#
#   absent, or DMS_R6_MIGRATOR=0
#       today's behaviour, unchanged: `php artisan migrate --force` as the
#       runtime user.
#   DMS_R6_MIGRATOR=1   (only after R6-D has split the roles)
#       1. `php artisan migrate --database=pgsql_migrate --force` as the
#          dedicated migration OS account, through `env -i` and an explicit
#          allowlist: no APP_KEY, no runtime password, no environment file the
#          account could read (it is outside the runtime group), the `array`
#          cache store, and every Laravel cache path redirected to a fresh
#          0700 directory the account owns, so nothing is read from or
#          written to bootstrap/cache (F1-B);
#       2. `php artisan foundation:db-privilege-apply --apply` the same way
#          (R6-B3: the runtime's class grants, one transaction);
#       3. `php artisan foundation:db-privilege-check` as the RUNTIME user:
#          anything but MATCH (exit 0) stops the deploy before any smoke.
#
# Every refusal names the problem and never a configured value.

DMS_R6_SWITCH_FILE="${DMS_R6_SWITCH_FILE:-/etc/dms-r6/migrator.conf}"
# Where the per-deploy private cache directory of the migration account is made (root-only).
DMS_R6_CACHE_ROOT="${DMS_R6_CACHE_ROOT:-/run}"

# The keys a switch file may hold; any other key, or a value outside the
# plain-character class, refuses the whole file.
_DMS_R6_KEYS="DMS_R6_MIGRATOR DMS_R6_MIGRATION_OS_USER DMS_MIGRATE_SOCKET_DIR DMS_MIGRATE_PORT DMS_MIGRATE_DATABASE DMS_MIGRATE_USERNAME DMS_MIGRATE_OWNER_ROLE DMS_PRIVILEGE_RUNTIME_ROLE"

_dms_r6_err() { echo "r6-migrate: $*" >&2; }

# dms_r6_switch_read <expected owner uid> <runtime user> <runtime group>
#
# Sets DMS_R6_MIGRATOR (0 or 1) and, for 1, the DMS_R6_*/DMS_MIGRATE_* values.
# Returns 0 when the switch is absent or valid, 1 when it must not be trusted.
dms_r6_switch_read() {
  local owner_uid="${1:?owner uid}" runtime_user="${2:?runtime user}" runtime_group="${3:?runtime group}"
  local file="$DMS_R6_SWITCH_FILE" line key value meta
  DMS_R6_MIGRATOR=0
  for key in $_DMS_R6_KEYS; do
    [ "$key" = DMS_R6_MIGRATOR ] || unset "$key"
  done

  if [ ! -e "$file" ] && [ ! -L "$file" ]; then
    return 0
  fi
  if [ -L "$file" ] || [ ! -f "$file" ]; then
    _dms_r6_err "the migrator switch file is not a regular file — NOT GO"
    return 1
  fi
  meta="$(stat -c '%u %a' -- "$file")" || return 1
  if [ "${meta%% *}" != "$owner_uid" ] || [ $(( 8#${meta##* } & 8#022 )) -ne 0 ]; then
    _dms_r6_err "the migrator switch file must be owned by the deploy identity and writable by nobody else — NOT GO"
    return 1
  fi

  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in ''|'#'*) continue ;; esac
    key="${line%%=*}"
    value="${line#*=}"
    case " ${_DMS_R6_KEYS} " in
      *" ${key} "*) [ "$key" != "$line" ] ;;
      *) false ;;
    esac || { _dms_r6_err "the migrator switch file holds an unknown or malformed line — NOT GO"; return 1; }
    case "$value" in
      *[!A-Za-z0-9_./-]*|'') _dms_r6_err "the migrator switch file holds a value outside the plain character class (${key}) — NOT GO"; return 1 ;;
    esac
    printf -v "$key" '%s' "$value"
  done < "$file"

  case "$DMS_R6_MIGRATOR" in
    0) return 0 ;;
    1) ;;
    *) _dms_r6_err "DMS_R6_MIGRATOR must be 0 or 1 — NOT GO"; return 1 ;;
  esac

  for key in $_DMS_R6_KEYS; do
    if [ -z "${!key:-}" ]; then
      _dms_r6_err "the migrator switch is on but ${key} is missing — NOT GO"
      return 1
    fi
  done
  case "$DMS_MIGRATE_SOCKET_DIR" in /*) ;; *) _dms_r6_err "DMS_MIGRATE_SOCKET_DIR must be absolute — NOT GO"; return 1 ;; esac
  case "$DMS_MIGRATE_PORT" in *[!0-9]*) _dms_r6_err "DMS_MIGRATE_PORT must be numeric — NOT GO"; return 1 ;; esac

  local mig="$DMS_R6_MIGRATION_OS_USER" uid gid
  uid="$(id -u -- "$mig" 2>/dev/null)" || { _dms_r6_err "the migration OS account does not exist — NOT GO"; return 1; }
  if [ "$uid" = 0 ] || [ "$mig" = "$runtime_user" ]; then
    _dms_r6_err "the migration OS account must be its own unprivileged account — NOT GO"
    return 1
  fi
  # OD-R6-1 / F1-B: never a member of the runtime group, by primary or supplementary group.
  gid="$(getent group "$runtime_group" | cut -d: -f3)"
  if [ -z "$gid" ] || [ "$(id -g -- "$mig")" = "$gid" ] || [[ " $(id -nG -- "$mig") " == *" ${runtime_group} "* ]]; then
    _dms_r6_err "the migration OS account must not be in the runtime group — NOT GO"
    return 1
  fi
  return 0
}

# dms_r6_migrate <runtime user> <runtime group>
#
# The deploy's migrate step. For switch 0 it is exactly the former
# `php artisan migrate --force` as the runtime user.
dms_r6_migrate() {
  local runtime_user="${1:?runtime user}" runtime_group="${2:?runtime group}"
  dms_r6_switch_read 0 "$runtime_user" "$runtime_group" || return 1

  # v4 A4: the switch must agree with the database. Read-only, as the runtime.
  local state
  state="$(dms_privsep_as_runtime "$runtime_user" php artisan foundation:db-privilege-check --split-state 2>/dev/null | tail -n 1)" || state="split=unknown"
  case "${DMS_R6_MIGRATOR}:${state}" in
    0:split=0|1:split=1) ;;
    0:split=1) _dms_r6_err "the migrator switch is off but the database is already split — refused before migrating — NOT GO"; return 1 ;;
    1:split=0) _dms_r6_err "the migrator switch is on but the database is not split yet (R6-D comes first) — refused before migrating — NOT GO"; return 1 ;;
    *) _dms_r6_err "the role-split state of the database cannot be determined — refused before migrating — NOT GO"; return 1 ;;
  esac

  if [ "$DMS_R6_MIGRATOR" = 0 ]; then
    echo "R6 migrator switch: 0 (migrations run as the runtime user, unchanged)"
    dms_privsep_as_runtime "$runtime_user" php artisan migrate --force
    return
  fi

  echo "R6 migrator switch: 1 (migrations as the dedicated migration OS account through pgsql_migrate)"
  local mig="$DMS_R6_MIGRATION_OS_USER" cache status=0
  cache="$(mktemp -d "${DMS_R6_CACHE_ROOT}/dms-r6-migrate.XXXXXX")" || { _dms_r6_err "no private cache directory — NOT GO"; return 1; }
  chown -- "$mig" "$cache" && chmod 0700 -- "$cache" || { rm -rf -- "$cache"; _dms_r6_err "the private cache directory could not be handed over — NOT GO"; return 1; }

  local -a allow=(
    PATH=/usr/local/bin:/usr/bin:/bin HOME=/nonexistent APP_ENV=production LOG_CHANNEL=stderr
    CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array DB_CONNECTION=pgsql_migrate
    DB_MIGRATE_SOCKET_DIR="$DMS_MIGRATE_SOCKET_DIR" DB_MIGRATE_PORT="$DMS_MIGRATE_PORT"
    DB_MIGRATE_DATABASE="$DMS_MIGRATE_DATABASE" DB_MIGRATE_USERNAME="$DMS_MIGRATE_USERNAME"
    DB_MIGRATE_OWNER_ROLE="$DMS_MIGRATE_OWNER_ROLE" DB_PRIVILEGE_RUNTIME_ROLE="$DMS_PRIVILEGE_RUNTIME_ROLE"
    APP_CONFIG_CACHE="$cache/config.php" APP_SERVICES_CACHE="$cache/services.php" APP_PACKAGES_CACHE="$cache/packages.php"
    APP_ROUTES_CACHE="$cache/routes.php" APP_EVENTS_CACHE="$cache/events.php"
  )

  echo "== R6-B4 migrate (migration OS account, pgsql_migrate) =="
  dms_privsep_as_runtime "$mig" env -i "${allow[@]}" php artisan migrate --database=pgsql_migrate --force || status=$?
  if [ "$status" = 0 ]; then
    echo "== R6-B4 runtime grants (foundation:db-privilege-apply --apply) =="
    dms_privsep_as_runtime "$mig" env -i "${allow[@]}" php artisan foundation:db-privilege-apply --apply || status=$?
  fi
  rm -rf -- "$cache"
  if [ "$status" != 0 ]; then
    _dms_r6_err "the migration or the runtime grants failed (exit ${status}) — NOT GO"
    return 1
  fi

  echo "== R6-B4 privilege check as the runtime (foundation:db-privilege-check) =="
  status=0
  dms_privsep_as_runtime "$runtime_user" env DB_MIGRATE_OWNER_ROLE="$DMS_MIGRATE_OWNER_ROLE" \
    DB_PRIVILEGE_RUNTIME_ROLE="$DMS_PRIVILEGE_RUNTIME_ROLE" php artisan foundation:db-privilege-check || status=$?
  if [ "$status" != 0 ]; then
    _dms_r6_err "the database does not match the role separation (check exit ${status}) — the deploy stops before smoke — NOT GO"
    return 1
  fi
  echo "R6-B4: migrations, runtime grants and the privilege check: GO"
}

# dms_r6_rollback_guard <target sha>
#
# IAR-A12: while the split is active, a rollback to a commit that predates
# R6-B4 (no scripts/lib/r6-migrate.sh) is refused before anything changes: that
# code would migrate as the runtime user, which can no longer create anything.
dms_r6_rollback_guard() {
  local target="${1:?target sha}"
  # The switch file is root's; a rollback never accepts a weaker one.
  dms_r6_switch_read 0 "${RUNTIME_USER:-}" "${RUNTIME_GROUP:-}" || return 1
  [ "$DMS_R6_MIGRATOR" = 1 ] || return 0
  if ! git cat-file -e "${target}:scripts/lib/r6-migrate.sh" 2>/dev/null; then
    _dms_r6_err "the role split is active and the rollback target predates R6-B4 — refused before anything changed — NOT GO"
    return 1
  fi
  return 0
}
