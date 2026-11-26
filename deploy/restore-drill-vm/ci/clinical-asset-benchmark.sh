#!/usr/bin/env bash
#
# R6-B17 zero-budget SYNTHETIC clinical archive benchmark (owner decision "ZERO-BUDGET SYNTHETIC RECOVERY VALIDATION"
# §3). TEST TOOLING on a DISPOSABLE GitHub-hosted runner. Synthetic files and a synthetic database only.
# It runs the R6-B13 tools (scripts/clinical-asset-backup.sh, scripts/clinical-asset-reconcile.sh) on about 1 GiB:
# 1,024 files of 1 MiB random content (incompressible: the worst case for time and space), each referenced by a
# database row with its SHA-256. Steps, each timed with GNU time (wall, CPU, peak RSS):
#   CB1 snapshot (encrypt to a throwaway recipient)   CB2 verify --deep   CB3 restore into an isolated, empty target
#   CB4 reconcile the restored tree against the database: VERDICT CONSISTENT, every file present, no orphan
#   CB5 the restored tree is private (files 0600, directories 0700) and byte-identical to the source
# Usage: clinical-asset-benchmark.sh <out dir>
set -uo pipefail
OUT="$(mkdir -p "${1:?out dir}" && cd "$1" && pwd)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
B="$REPO/scripts/clinical-asset-backup.sh"; R="$REPO/scripts/clinical-asset-reconcile.sh"; ROOTS="$REPO/deploy/clinical-assets/roots.conf"
PGB=/usr/lib/postgresql/16/bin
RESULTS="$OUT/results-clinical-benchmark.txt"
: > "$RESULTS"
PASS=0; FAIL=0
check() { if [ "$3" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS   $1  $2  [$4]" >> "$RESULTS"; else FAIL=$((FAIL + 1)); echo "FAIL   $1  $2  [$4]" >> "$RESULTS"; fi; }
bench() { echo "BENCH  $1  $2" >> "$RESULTS"; }
FILES="${CAB_BENCH_FILES:-1024}"; FILE_KIB="${CAB_BENCH_FILE_KIB:-1024}"
W="$(mktemp -d /tmp/cab.XXXXXX)"
chmod 0700 "$W"
PGD="$W/pgdata"; SOCK="$W"; PORT=55441
cleanup() { "$PGB/pg_ctl" -D "$PGD" -m fast stop > /dev/null 2>&1; gpgconf --kill gpg-agent > /dev/null 2>&1; chmod -R u+w "$W" 2> /dev/null; rm -rf -- "$W"; }
trap cleanup EXIT
avail="$(df -B1 --output=avail "$W" | tail -n 1)"
[ "$avail" -gt $(( FILES * FILE_KIB * 1024 * 4 )) ] || { echo "not enough free disk for the benchmark (${avail} bytes)"; exit 2; }
{
  echo "runner_cpu=$(lscpu | sed -n 's/^Model name: *//p')"
  echo "runner_cpus=$(nproc) mem_total_kib=$(sed -n 's/^MemTotal: *\([0-9]*\).*/\1/p' /proc/meminfo) disk_avail_bytes=${avail}"
} > "$OUT/runner-clinical.txt"

# --- keys: the source holds the public key only; the private key stays in its own keyring (R6-B13's model) ----------
export GNUPGHOME="$W/gnupg"; mkdir -m 0700 "$GNUPGHOME"
gpg --batch --quiet --pinentry-mode loopback --passphrase '' --quick-gen-key 'cab-bench <synthetic@example.invalid>' ed25519 sign never 2> /dev/null
FPR="$(gpg --list-keys --with-colons | awk -F: '/^fpr/ { print $10; exit }')"
gpg --batch --quiet --pinentry-mode loopback --passphrase '' --quick-add-key "$FPR" cv25519 encr never 2> /dev/null

# --- the synthetic estate: files and the database rows that reference them --------------------------------------------
"$PGB/initdb" -D "$PGD" -U benchsu -A trust -E UTF8 --locale=C.UTF-8 > /dev/null || { echo "initdb failed"; exit 2; }
"$PGB/pg_ctl" -D "$PGD" -o "-k $SOCK -p $PORT -c listen_addresses=''" -l "$W/pg.log" -w start > /dev/null || { echo "the throwaway cluster did not start"; exit 2; }
export PGHOST="$SOCK" PGPORT="$PORT" PGUSER=benchsu PGDATABASE=cab_bench
"$PGB/psql" -X -q -v ON_ERROR_STOP=1 -d postgres -c "CREATE DATABASE cab_bench"
"$PGB/psql" -X -q -v ON_ERROR_STOP=1 -c "CREATE TABLE hw (id serial, branch_id int, path text, hash text)"
S="$W/live/storage/app"; mkdir -p "$S"
t0="$(date +%s.%N)"
: > "$W/rows.tsv"
for i in $(seq 1 "$FILES"); do
  b=$(( i % 5 + 1 )); p="handwritings/${b}/${i}/handwriting_p1_${i}.png"
  mkdir -p "$S/clinical-evidence-private/handwritings/${b}/${i}"
  head -c $(( FILE_KIB * 1024 )) /dev/urandom > "$S/clinical-evidence-private/$p"
  printf '%s\t%s\t%s\n' "$b" "$p" "$(sha256sum < "$S/clinical-evidence-private/$p" | cut -d' ' -f1)" >> "$W/rows.tsv"
done
"$PGB/psql" -X -q -v ON_ERROR_STOP=1 -c "\\copy hw (branch_id, path, hash) FROM '$W/rows.tsv'"
bench CB0-estate "files=${FILES} bytes=$(du -sb "$S" | cut -f1) generation_seconds=$(awk -v a="$t0" -v b="$(date +%s.%N)" 'BEGIN { printf "%.1f", b - a }')"
MAP="$W/map.tsv"
printf '%s\t%s\t%s\t%s\t%s\n' hw durable 0 public "SELECT 'clinical_evidence', path, hash, branch_id FROM hw" > "$MAP"
STATE="$W/state"; STORE="$W/store"

timed() { # <case> <command…>: GNU time's wall, CPU and peak RSS for one step
  local c="$1"; shift
  /usr/bin/time -f "wall_seconds=%e cpu_pct=%P max_rss_kib=%M" -o "$OUT/$c-time.txt" "$@" > "$OUT/$c.txt" 2>&1
}
timed CB1 bash "$B" snapshot --storage "$S" --roots "$ROOTS" --state "$STATE" --store "$STORE" --recipient "$FPR"; rc=$?
check CB1 "the 1 GiB synthetic tree is snapshotted (encrypted, content-addressed)" "$([ "$rc" = 0 ] && grep -q "files=${FILES} " "$OUT/CB1.txt"; echo $?)" "$(grep -o 'files=[0-9]*.*new_bytes=[0-9]*' "$OUT/CB1.txt" | head -n 1)"
bench CB1 "$(cat "$OUT/CB1-time.txt") store_bytes=$(du -sb "$STORE" | cut -f1)"
timed CB2 bash "$B" verify --store "$STORE" --deep; rc=$?
check CB2 "the store verifies deep (every object decrypts to its digest)" "$([ "$rc" = 0 ] && grep -q '^VERIFIED ' "$OUT/CB2.txt"; echo $?)" "$(head -c 120 "$OUT/CB2.txt")"
bench CB2 "$(cat "$OUT/CB2-time.txt")"
timed CB3 bash "$B" restore --store "$STORE" --target "$W/restored" --storage "$S"; rc=$?
check CB3 "the head snapshot restores into an isolated, empty target" "$([ "$rc" = 0 ] && grep -q "files=${FILES} " "$OUT/CB3.txt"; echo $?)" "$(head -c 140 "$OUT/CB3.txt")"
bench CB3 "$(cat "$OUT/CB3-time.txt") restored_bytes=$(du -sb "$W/restored" | cut -f1)"
timed CB4 bash "$R" --map "$MAP" --out "$W/rec" --tree "$W/restored"; rc=$?
check CB4 "the restored tree reconciles with the database: CONSISTENT, nothing missing, no orphan" \
  "$([ "$rc" = 0 ] && grep -q '^VERDICT CONSISTENT' "$W/rec/report.txt" && grep -q "^ASSET hw rows=${FILES} ok=${FILES}" "$W/rec/report.txt"; echo $?)" \
  "$(grep -E '^(ASSET hw|ROOT clinical-evidence|VERDICT)' "$W/rec/report.txt" 2> /dev/null | tr '\n' ' ')"
bench CB4 "$(cat "$OUT/CB4-time.txt")"
cp "$W/rec/report.txt" "$OUT/CB4-report.txt" 2> /dev/null
modes="$(find "$W/restored" -type f ! -perm 0600 | wc -l) $(find "$W/restored" -type d ! -perm 0700 | wc -l)"
# The restore lays files out under the root NAME of roots.conf (clinical-evidence), not the live directory name.
rootname="$(awk '$2 == "clinical-evidence-private" { print $1; exit }' "$ROOTS")"
same=1; [ -n "$rootname" ] && diff -rq "$S/clinical-evidence-private" "$W/restored/$rootname" > /dev/null 2>&1 && same=0
check CB5 "the restored tree is private (files 0600, directories 0700) and byte-identical to the source" "$([ "$modes" = "0 0" ] && [ "$same" = 0 ]; echo $?)" "non_private_files_dirs=${modes} identical=$([ "$same" = 0 ] && echo yes || echo no)"
echo "TOTAL pass=${PASS} fail=${FAIL}" >> "$RESULTS"
cat "$OUT/runner-clinical.txt" "$RESULTS"
[ "$FAIL" = 0 ]
