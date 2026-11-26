#!/usr/bin/env bash
# O-CI-1 regression guard for restore_db (tests/Fixtures/clinical-assets/restore-db.sh): a restore failure can never
# pass silently, on any of its three paths, and the newer-client accommodation drops exactly one header line and keeps
# transaction protection. Synthetic data and throwaway databases only (it refuses any database name not its own).
# Needs a PostgreSQL server through the libpq environment with the right to create databases.
# Usage: restore-db-guard.sh <new work dir>      prints "PASS|FAIL <case> <detail>" and "TOTAL pass=N fail=M"
set -uo pipefail
umask 077
export LC_ALL=C
W="${1:?work dir}"; [ ! -e "$W" ] || { echo "the work dir must not exist"; exit 2; }
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
mkdir -p -m 0700 "$W"; W="$(cd "$W" && pwd)"
TAG="rdg_$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')"; SRC="${TAG}_src"; DST="${TAG}_dst"
[[ $SRC =~ ^rdg_[0-9a-f]{8}_src$ ]] || { echo "refusing a database name that is not this guard's own"; exit 2; }
cleanup() {
  psql -X -q -d postgres -c "DROP DATABASE IF EXISTS ${SRC}" -c "DROP DATABASE IF EXISTS ${DST}" > /dev/null 2>&1
  rm -rf -- "$W"
}
trap cleanup EXIT
P=0; F=0
ok() { if [ "$2" = 0 ]; then P=$((P + 1)); echo "PASS $1 ${3:-}"; else F=$((F + 1)); echo "FAIL $1 ${3:-}"; fi; }
q() { psql -X -q -At -v ON_ERROR_STOP=1 -d "$1" -c "$2"; }
# shellcheck source=restore-db.sh
. "$HERE/restore-db.sh"

REAL_RESTORE="$(command -v pg_restore)"
SMAJ=$(( $(q postgres 'SHOW server_version_num') / 10000 ))
q postgres "CREATE DATABASE ${SRC}"
q "$SRC" "CREATE TABLE t (id serial PRIMARY KEY, v text); INSERT INTO t (v) VALUES ('a'), ('b'), ('c')"
pg_dump -Fc -C -Z 6 -d "$SRC" -f "$W/a.dump" || { echo "FAIL setup pg_dump"; exit 1; }

fresh() { q postgres "DROP DATABASE IF EXISTS ${DST}" && q postgres "CREATE DATABASE ${DST}"; }
rows() { q "$DST" 'SELECT count(*) FROM t' 2> /dev/null || echo none; }

# A version-matched client directory whose pg_restore either fails or delegates to the real one.
mkdir -p "$W/lib-fail/${SMAJ}/bin" "$W/lib-ok/${SMAJ}/bin" "$W/bin-old" "$W/bin-new"
printf '#!/usr/bin/env bash\necho "pg_restore: error: injected failure" >&2\nexit 1\n' > "$W/lib-fail/${SMAJ}/bin/pg_restore"
printf '#!/usr/bin/env bash\nexec %q "$@"\n' "$REAL_RESTORE" > "$W/lib-ok/${SMAJ}/bin/pg_restore"
# A client no newer than the server, which fails.
printf '#!/usr/bin/env bash\n[ "${1:-}" = --version ] && { echo "pg_restore (PostgreSQL) %s.0 (guard stub)"; exit 0; }\necho "pg_restore: error: injected failure" >&2\nexit 1\n' "$SMAJ" > "$W/bin-old/pg_restore"
# A newer client: it reports major SMAJ+2 and, when rendering SQL, opens with the 17+ header and an optional injection.
cat > "$W/bin-new/pg_restore" <<EOF
#!/usr/bin/env bash
[ "\${1:-}" = --version ] && { echo "pg_restore (PostgreSQL) $((SMAJ + 2)).0 (guard stub)"; exit 0; }
if [[ " \$* " == *" -f - "* ]]; then
  echo 'SET transaction_timeout = 0;'
  [ -n "\${GUARD_INJECT_FIRST:-}" ] && echo "\$GUARD_INJECT_FIRST"
  "$REAL_RESTORE" "\$@"; rc=\$?
  [ -n "\${GUARD_INJECT_LAST:-}" ] && echo "\$GUARD_INJECT_LAST"
  exit \$rc
fi
exec "$REAL_RESTORE" "\$@"
EOF
chmod 0700 "$W/lib-fail/${SMAJ}/bin/pg_restore" "$W/lib-ok/${SMAJ}/bin/pg_restore" "$W/bin-old/pg_restore" "$W/bin-new/pg_restore"

fresh; CAB_PG_LIBDIR="$W/lib-fail" restore_db "$W/a.dump" "$DST" "$W/e"; rc=$?
[ "$rc" != 0 ]; ok M1-matched-client-failure-propagates $? "exit ${rc} (${RESTORE_PATH})"

fresh; CAB_PG_LIBDIR="$W/lib-ok" restore_db "$W/a.dump" "$DST" "$W/e"; rc=$?; n="$(rows)"
[ "$rc" = 0 ] && [ "$n" = 3 ] && [[ $RESTORE_PATH == "client ${SMAJ} = server ${SMAJ}" ]]; ok M2-matched-client-restores $? "exit ${rc}, rows ${n} (${RESTORE_PATH})"

fresh; PATH="$W/bin-old:$PATH" CAB_PG_LIBDIR="$W/none" restore_db "$W/a.dump" "$DST" "$W/e"; rc=$?
[ "$rc" != 0 ] && [[ $RESTORE_PATH == "client ${SMAJ}, server ${SMAJ}" ]]; ok E1-older-or-equal-client-failure-propagates $? "exit ${rc} (${RESTORE_PATH})"

fresh; PATH="$W/bin-new:$PATH" CAB_PG_LIBDIR="$W/none" restore_db "$W/a.dump" "$DST" "$W/e"; rc=$?; n="$(rows)"
[ "$rc" = 0 ] && [ "$n" = 3 ] && [[ $RESTORE_PATH == "client $((SMAJ + 2)) > server ${SMAJ}"* ]]; ok N1-newer-client-drops-only-the-header $? "exit ${rc}, rows ${n}"

fresh; GUARD_INJECT_FIRST='SET bogus_parameter_for_the_guard = 1;' PATH="$W/bin-new:$PATH" CAB_PG_LIBDIR="$W/none" restore_db "$W/a.dump" "$DST" "$W/e"; rc=$?; n="$(rows)"
[ "$rc" != 0 ] && [ "$n" = none ]; ok N2-any-other-setting-fails-and-restores-nothing $? "exit ${rc}, rows ${n}"

fresh; GUARD_INJECT_LAST='SELECT 1/0;' PATH="$W/bin-new:$PATH" CAB_PG_LIBDIR="$W/none" restore_db "$W/a.dump" "$DST" "$W/e"; rc=$?; n="$(rows)"
[ "$rc" != 0 ] && [ "$n" = none ]; ok N3-late-error-rolls-back-everything $? "exit ${rc}, rows ${n}"

echo "TOTAL pass=${P} fail=${F}"
[ "$F" = 0 ]
