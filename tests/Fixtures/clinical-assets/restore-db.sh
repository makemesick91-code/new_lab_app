# shellcheck shell=bash
# R6-B13 rehearsal helper, sourced by rehearsal.sh and by RestoreDrillCiPostgresClientAlignmentTest (O-CI-1).
#
# restore_db <archive> <database> <stderr file>: restore with --no-owner and FAIL on any error. A client whose major
# version matches the server's is used when installed, with --exit-on-error. A newer client restoring into an older
# server is outside what PostgreSQL supports: pg_restore 17+ opens every session with "SET transaction_timeout = 0",
# which a 16 server rejects (CI ran client 18 against the 16 service before O-CI-1). Only then is the archive rendered
# as SQL, exactly that one session-header line dropped, and the rest applied in one transaction with ON_ERROR_STOP, so
# any other error still fails. RESTORE_PATH names the path taken, for the case's detail.
#
# The server is reached through the libpq environment. CAB_PG_LIBDIR (default /usr/lib/postgresql) is where the
# version-matched client is looked up; only the tests point it elsewhere.
restore_db() {
  local smaj cmaj libdir="${CAB_PG_LIBDIR:-/usr/lib/postgresql}"
  smaj="$(psql -X -q -At -v ON_ERROR_STOP=1 -d postgres -c 'SHOW server_version_num')"; smaj=$((smaj / 10000))
  cmaj="$(pg_restore --version | grep -oE '[0-9]+' | head -n 1)"
  if [ -x "${libdir}/${smaj}/bin/pg_restore" ]; then
    RESTORE_PATH="client ${smaj} = server ${smaj}"
    "${libdir}/${smaj}/bin/pg_restore" --exit-on-error --no-owner -d "$2" "$1" 2> "$3"; return
  fi
  if [ "$cmaj" -le "$smaj" ]; then
    RESTORE_PATH="client ${cmaj}, server ${smaj}"
    pg_restore --exit-on-error --no-owner -d "$2" "$1" 2> "$3"; return
  fi
  RESTORE_PATH="client ${cmaj} > server ${smaj}: SQL with only the transaction_timeout header dropped, ON_ERROR_STOP"
  pg_restore --no-owner -f - "$1" 2> "$3" | grep -vxF 'SET transaction_timeout = 0;' \
    | psql -X -q -v ON_ERROR_STOP=1 --single-transaction -d "$2" > /dev/null 2>> "$3"
}
