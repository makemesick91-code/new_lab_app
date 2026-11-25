#!/usr/bin/env bash
# R6-B11 — isolated pgBackRest rehearsal on a disposable runner (owner decision 2026-11-26 §7: "evaluate pgBackRest
# and other appropriate backup tooling before selecting the final architecture"). Synthetic data only; never
# production. PostgreSQL 16 and pgBackRest come from the runner's apt sources (their origin is recorded).
#
# Two repositories: repo1 "on the host", repo2 "off the host"; both encrypted (aes-256-cbc, a passphrase generated per
# run, never printed). Cases:
#   B1 stanza, check, full backups to both repositories, info
#   B2 exact PITR from repo2 (the off-host copy) to a recorded target time
#   B3 repository encryption: no table name readable in repo2; a wrong passphrase cannot restore
#   B4 archive failure: repo2 unwritable -> archiving blocked (fail closed), backlog, then drained
#   B5 queue limit: archive-push-queue-max drops WAL while the repositories are down -> a PITR gap (the trade-off)
#   B6 verify detects a tampered WAL segment in repo2; a restore through it fails
#   B7 incremental backup, then retention (expire): the oldest full backup goes, the newest restores
#   B8 timeline: the PITR'd server promotes and archives timeline 2; a second PITR on timeline 2
# Usage: pgbackrest-rehearsal.sh <out dir>
set -uo pipefail
OUT="${1:?out dir}"; mkdir -p "$OUT"; : > "$OUT/summary.txt"
PGB=/usr/lib/postgresql/16/bin
W="$(mktemp -d)"; SOCK="$W/sock"; mkdir -p "$SOCK" "$W/repo1" "$W/repo2" "$W/spool" "$W/log" "$W/lock"; chmod 700 "$W" "$SOCK"
CFG="$W/pgbackrest.conf"
P=0; F=0
ok() { if [ "$2" = 0 ]; then P=$((P + 1)); echo "PASS $1 ${3:-}" | tee -a "$OUT/summary.txt"; else F=$((F + 1)); echo "FAIL $1 ${3:-}" | tee -a "$OUT/summary.txt"; fi; }
note() { echo "NOTE $*" | tee -a "$OUT/summary.txt"; }
q() { "$PGB/psql" -X -q -At -h "$SOCK" -p "$1" -d "$2" -c "$3"; }
pb() { pgbackrest --config="$CFG" --stanza=demo "$@"; }
now() { date +%s.%N; }
cleanup() { for d in "$W"/pg-*; do [ -d "$d" ] && "$PGB/pg_ctl" -D "$d" -m immediate stop > /dev/null 2>&1; done
  cp -r "$W/log" "$OUT/pgbackrest-log" 2> /dev/null; chmod -R u+w "$W" 2> /dev/null; rm -rf -- "$W"; }
trap cleanup EXIT

{ echo "pgbackrest: $(pgbackrest version)"; echo "postgres: $("$PGB/postgres" --version)"
  dpkg-query -W -f='${Package} ${Version}\n' pgbackrest postgresql-16 2> /dev/null
  apt-cache policy pgbackrest | sed -n '1,8p'; } > "$OUT/versions.txt"
PASS1="$(head -c 48 /dev/urandom | base64 -w0)"; PASS2="$(head -c 48 /dev/urandom | base64 -w0)"
writecfg() { # [queue max]
  cat > "$CFG" <<EOF
[global]
repo1-path=$W/repo1
repo1-cipher-type=aes-256-cbc
repo1-cipher-pass=$PASS1
repo1-retention-full=1
repo2-path=$W/repo2
repo2-cipher-type=aes-256-cbc
repo2-cipher-pass=$PASS2
repo2-retention-full=2
compress-type=zst
archive-async=y
archive-timeout=10
spool-path=$W/spool
${1:+archive-push-queue-max=$1}
log-path=$W/log
lock-path=$W/lock
log-level-console=warn
log-level-file=info
start-fast=y
process-max=2

[demo]
pg1-path=$W/pg-src
pg1-socket-path=$SOCK
pg1-port=55601
EOF
  chmod 0600 "$CFG"
}
writecfg
start() { "$PGB/pg_ctl" -D "$W/$1" -l "$W/$1.log" -w -t 60 -o "-k $SOCK -c listen_addresses='' -p $2 ${3:-}" start > /dev/null; }
ARCH="-c archive_mode=on -c archive_timeout=60 -c archive_command='pgbackrest --config=$CFG --stanza=demo archive-push %p'"
waitarch() { for _ in $(seq 1 90); do [ "$(find "$W/$1/pg_wal/archive_status" -name '*.ready' | wc -l)" = 0 ] && return 0; sleep 1; done; return 1; }
promoted() { for _ in $(seq 1 120); do [ "$(q "$1" postgres "SELECT NOT pg_is_in_recovery()" 2> /dev/null)" = t ] && return 0; sleep 1; done; return 1; }

# ---------------------------------------------------------------------------------------------------------------- B1
"$PGB/initdb" -D "$W/pg-src" --auth=trust -E UTF8 --locale=C.UTF-8 --data-checksums > /dev/null || { echo "initdb failed"; exit 90; }
start pg-src 55601 "$ARCH"
q 55601 postgres "CREATE DATABASE synth"
q 55601 synth "CREATE TABLE synth_visits (id bigserial PRIMARY KEY, branch int, note text)"
q 55601 synth "INSERT INTO synth_visits (branch, note) SELECT g % 5, md5(g::text) FROM generate_series(1, 20000) g"
pb stanza-create > "$OUT/b1-stanza.txt" 2>&1; r1=$?
pb check > "$OUT/b1-check.txt" 2>&1; r2=$?
t0=$(now); pb --repo=1 --type=full backup > "$OUT/b1-backup1.txt" 2>&1; r3=$?; pb --repo=2 --type=full backup > "$OUT/b1-backup2.txt" 2>&1; r4=$?
bt=$(echo "$(now) - $t0" | bc | cut -c1-5)
pb info > "$OUT/b1-info.txt" 2>&1
ok B1-stanza-check-full-backups "$([ "$r1$r2$r3$r4" = 0000 ]; echo $?)" "stanza=$r1 check=$r2 repo1=$r3 repo2=$r4; both full backups ${bt} s; repo1 $(du -sb "$W/repo1" | cut -f1) B, repo2 $(du -sb "$W/repo2" | cut -f1) B"

# ---------------------------------------------------------------------------------------------------------------- B2
q 55601 synth "INSERT INTO synth_visits (branch, note) SELECT g % 5, md5(g::text) FROM generate_series(20001, 30000) g"
sleep 1; T="$(q 55601 synth "SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') || '+00'")"
EXP_N="$(q 55601 synth "SELECT count(*) FROM synth_visits")"; EXP_D="$(q 55601 synth "SELECT md5(string_agg(id::text || note, ',' ORDER BY id)) FROM synth_visits")"
sleep 1; q 55601 synth "DELETE FROM synth_visits WHERE branch = 3"; q 55601 synth "INSERT INTO synth_visits (branch, note) VALUES (9, 'after-target')"
q 55601 postgres "SELECT pg_switch_wal()" > /dev/null; waitarch pg-src; sleep 3
t1=$(now)
pb --repo=2 --pg1-path="$W/pg-pitr" --type=time "--target=$T" --target-action=promote restore > "$OUT/b2-restore.txt" 2>&1; rr=$?
start pg-pitr 55602 "-c archive_mode=off"; promoted 55602; rto=$(echo "$(now) - $t1" | bc | cut -c1-5)
GOT_N="$(q 55602 synth "SELECT count(*) FROM synth_visits" 2> /dev/null)"; GOT_D="$(q 55602 synth "SELECT md5(string_agg(id::text || note, ',' ORDER BY id)) FROM synth_visits" 2> /dev/null)"
AFTER="$(q 55602 synth "SELECT count(*) FROM synth_visits WHERE note = 'after-target'" 2> /dev/null)"
ok B2-exact-pitr-from-offhost-repo "$([ "$rr" = 0 ] && [ "$GOT_N" = "$EXP_N" ] && [ "$GOT_D" = "$EXP_D" ] && [ "$AFTER" = 0 ]; echo $?)" "restore=$rr rows=${GOT_N}/${EXP_N} digest_equal=$([ "$GOT_D" = "$EXP_D" ] && echo yes || echo no) after_target=${AFTER}; restore+replay ${rto} s"
"$PGB/pg_ctl" -D "$W/pg-pitr" -m fast stop > /dev/null; rm -rf "$W/pg-pitr"

# ---------------------------------------------------------------------------------------------------------------- B3
plain=$(grep -rlaF 'synth_visits' "$W/repo2" | wc -l)
pgbackrest --config="$CFG" --stanza=demo --repo=2 --repo2-cipher-pass=wrong-passphrase --pg1-path="$W/pg-wrong" restore > "$OUT/b3-wrong-pass.txt" 2>&1; wr=$?
ok B3-repository-encrypted "$([ "$plain" = 0 ] && [ "$wr" != 0 ]; echo $?)" "files in repo2 with a readable table name: ${plain}; restore with a wrong passphrase exit ${wr}"
rm -rf "$W/pg-wrong"

# ---------------------------------------------------------------------------------------------------------------- B4
lockrepo() { find "$1" -type d -exec chmod "$2" {} +; }
lockrepo "$W/repo2/archive/demo" 0500
for i in 1 2 3; do q 55601 synth "INSERT INTO synth_visits (branch, note) SELECT 7, repeat('x', 400) FROM generate_series(1, 20000)"; q 55601 postgres "SELECT pg_switch_wal()" > /dev/null; done
sleep 25
during="$(q 55601 postgres "SELECT failed_count || '|' || (last_failed_time > COALESCE(last_archived_time, '-infinity'))::text FROM pg_stat_archiver")|ready=$(find "$W/pg-src/pg_wal/archive_status" -name '*.ready' | wc -l)"
lockrepo "$W/repo2/archive/demo" 0750; t2=$(now); waitarch pg-src; drain=$(echo "$(now) - $t2" | bc | cut -c1-5)
after="ready=$(find "$W/pg-src/pg_wal/archive_status" -name '*.ready' | wc -l)"
ok B4-offhost-repo-outage-blocks-archiving "$([[ "$during" == *"|true|ready="* ]] && [ "$after" = ready=0 ]; echo $?)" "during [failed|failing|backlog]=${during}; restored -> drained in ${drain} s (${after}). One unavailable repository blocks archiving for both (fail closed): pg_wal grows during an off-host outage"

# ---------------------------------------------------------------------------------------------------------------- B5
"$PGB/pg_ctl" -D "$W/pg-src" -m fast stop > /dev/null
writecfg 32MB
start pg-src 55601 "$ARCH"
before5=$(find "$W/repo1/archive/demo" -name '0000*' -type f | wc -l)
lockrepo "$W/repo1/archive/demo" 0500; lockrepo "$W/repo2/archive/demo" 0500
for i in 1 2 3 4 5 6; do q 55601 synth "INSERT INTO synth_visits (branch, note) SELECT 6, repeat('y', 400) FROM generate_series(1, 20000)"; q 55601 postgres "SELECT pg_switch_wal()" > /dev/null; done
sleep 15
ready5=$(find "$W/pg-src/pg_wal/archive_status" -name '*.ready' | wc -l)
lockrepo "$W/repo1/archive/demo" 0750; lockrepo "$W/repo2/archive/demo" 0750
q 55601 postgres "SELECT pg_switch_wal()" > /dev/null; waitarch pg-src; sleep 3
after5=$(find "$W/repo1/archive/demo" -name '0000*' -type f | wc -l)
dropped=$(grep -c 'dropped WAL file' "$W/log/demo-archive-push-async.log" 2> /dev/null || echo 0)
pb check > "$OUT/b5-check.txt" 2>&1; ck=$?
ok B5-queue-limit-drops-wal "$([ "$dropped" -ge 1 ]; echo $?)" "queue max 32 MiB, both repositories down, 6 segments: pg_wal backlog ${ready5}; 'dropped WAL file' log lines ${dropped}; repo1 segments ${before5} -> ${after5}. PostgreSQL saw success: the archive now has a GAP, so no PITR across it until the next full backup (the disk-protection trade-off)"
"$PGB/pg_ctl" -D "$W/pg-src" -m fast stop > /dev/null
writecfg; start pg-src 55601 "$ARCH"
pb --repo=1 --type=full backup > "$OUT/b5-refull1.txt" 2>&1; pb --repo=2 --type=full backup > "$OUT/b5-refull2.txt" 2>&1
note "B5 after the gap: a new full backup on both repositories re-establishes a recoverable chain"

# ---------------------------------------------------------------------------------------------------------------- B6
q 55601 synth "INSERT INTO synth_visits (branch, note) VALUES (5, 'b6-marker')"; q 55601 postgres "SELECT pg_switch_wal()" > /dev/null; waitarch pg-src; sleep 3
seg="$(find "$W/repo2/archive/demo" -name '0000*' -type f | sort | tail -n 2 | head -n 1)"
cp "$seg" "$W/seg.orig"; printf 'XXXX' | dd of="$seg" bs=1 seek=200 conv=notrunc status=none
pb --repo=2 verify > "$OUT/b6-verify.txt" 2>&1; vr=$?
vcmd=present; grep -Eqi 'invalid command|unknown command|not a valid command' "$OUT/b6-verify.txt" && vcmd=missing
grep -Eqi 'checksum|invalid|corrupt|mismatch|error' "$OUT/b6-verify.txt"; vfound=$?
pb --repo=2 --pg1-path="$W/pg-tamper" --type=default restore > "$OUT/b6-restore.txt" 2>&1
start pg-tamper 55603 "-c archive_mode=off" 2> /dev/null; sleep 10
tst="$(q 55603 postgres "SELECT CASE WHEN pg_is_in_recovery() THEN 'in recovery' ELSE 'promoted' END" 2> /dev/null || echo 'not running')"
grep -Ei 'error|fatal' "$W/pg-tamper.log" 2> /dev/null | tail -n 3 > "$OUT/b6-tamper-server.txt"
ok B6-tampered-segment-detected "$([ "$vcmd" = present ] && [ "$vr" != 0 ] && [ "$vfound" = 0 ]; echo $?)" "verify command ${vcmd}, exit ${vr} (findings in output: $([ "$vfound" = 0 ] && echo yes || echo no)); a restore through it: server ${tst}"
"$PGB/pg_ctl" -D "$W/pg-tamper" -m immediate stop > /dev/null 2>&1; rm -rf "$W/pg-tamper"; cp "$W/seg.orig" "$seg"

# ---------------------------------------------------------------------------------------------------------------- B7
q 55601 synth "INSERT INTO synth_visits (branch, note) SELECT 4, 'b7' FROM generate_series(1, 500)"
pb --repo=1 --type=incr backup > "$OUT/b7-incr.txt" 2>&1; ri=$?
fulls_before=$(pb --repo=1 info --output=json 2> /dev/null | python3 -c 'import json,sys; d=json.load(sys.stdin); print(sum(1 for b in d[0]["backup"] if b["type"]=="full"))' 2> /dev/null)
pb --repo=1 --type=full backup > "$OUT/b7-full.txt" 2>&1
pb --repo=1 expire > "$OUT/b7-expire.txt" 2>&1; re=$?
fulls_after=$(pb --repo=1 info --output=json 2> /dev/null | python3 -c 'import json,sys; d=json.load(sys.stdin); print(sum(1 for b in d[0]["backup"] if b["type"]=="full"))' 2> /dev/null)
pb --repo=1 --pg1-path="$W/pg-ret" restore > "$OUT/b7-restore.txt" 2>&1; rr7=$?
start pg-ret 55604 "-c archive_mode=off"; promoted 55604
n7="$(q 55604 synth "SELECT count(*) FROM synth_visits WHERE note = 'b7'" 2> /dev/null)"
ok B7-incremental-and-retention "$([ "$ri" = 0 ] && [ "$re" = 0 ] && [ "${fulls_after:-9}" = 1 ] && [ "$rr7" = 0 ] && [ "$n7" = 500 ]; echo $?)" "incr=$ri; full backups ${fulls_before} -> after expire (retention-full=1) ${fulls_after}; restore of the newest: rows ${n7}/500"
"$PGB/pg_ctl" -D "$W/pg-ret" -m fast stop > /dev/null; rm -rf "$W/pg-ret"

# ---------------------------------------------------------------------------------------------------------------- B8
# a failover: the source stops for good; the PITR'd server takes over and archives into the same stanza (timeline 2)
"$PGB/pg_ctl" -D "$W/pg-src" -m fast stop > /dev/null
pb --repo=2 --pg1-path="$W/pg-src2" --type=default restore > "$OUT/b8-restore1.txt" 2>&1
sed -i "s|^pg1-path=.*|pg1-path=$W/pg-src2|" "$CFG"
start pg-src2 55601 "$ARCH"; promoted 55601
TL="$(q 55601 postgres "SELECT timeline_id FROM pg_control_checkpoint()")"
q 55601 synth "INSERT INTO synth_visits (branch, note) SELECT 8, 'tl2' FROM generate_series(1, 300)"
sleep 1; T2="$(q 55601 synth "SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') || '+00'")"
EXP2="$(q 55601 synth "SELECT count(*) FROM synth_visits")"
sleep 1; q 55601 synth "INSERT INTO synth_visits (branch, note) VALUES (9, 'after-target-two')"
q 55601 postgres "SELECT pg_switch_wal()" > /dev/null; waitarch pg-src2; sleep 3
pb --repo=2 --pg1-path="$W/pg-tl2" --type=time "--target=$T2" --target-timeline=latest --target-action=promote restore > "$OUT/b8-restore2.txt" 2>&1; r8=$?
start pg-tl2 55605 "-c archive_mode=off"; promoted 55605
GOT2="$(q 55605 synth "SELECT count(*) FROM synth_visits" 2> /dev/null)"; A2="$(q 55605 synth "SELECT count(*) FROM synth_visits WHERE note = 'after-target-two'" 2> /dev/null)"
ok B8-timeline-two-pitr "$([ "$r8" = 0 ] && [ "$GOT2" = "$EXP2" ] && [ "$A2" = 0 ]; echo $?)" "new primary on timeline ${TL}; PITR on it: restore=$r8 rows ${GOT2}/${EXP2}, after-target ${A2}; history files in repo2: $(find "$W/repo2/archive/demo" -name '*.history*' | wc -l)"

pb info > "$OUT/final-info.txt" 2>&1
echo "TOTAL pass=${P} fail=${F}" | tee -a "$OUT/summary.txt"
[ "$F" = 0 ]
