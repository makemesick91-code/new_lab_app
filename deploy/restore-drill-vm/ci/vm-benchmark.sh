#!/usr/bin/env bash
#
# R6-B17 zero-budget SYNTHETIC recovery benchmark (owner decision "ZERO-BUDGET SYNTHETIC RECOVERY VALIDATION" §3).
# TEST TOOLING on a DISPOSABLE GitHub-hosted runner, run after vm-boot-gate.sh on the same provisioned drill host.
# Synthetic data only. It drives the INSTALLED orchestrator exactly as the gate does (the same supervisor, VM and D6
# limits): it measures, it never relaxes a limit.
#   B1  about 30 MiB of row data (today's production-size equivalent), BK-2's dump shape (pg_dump -Fc -C -Z 6): GO
#   B2  about 96 MiB: GO
#   B3  about 180 MiB (the D6 envelope: data x 4 + 256 <= 1024 MiB): GO
#   B4  about 210 MiB (above D6): refused before any VM boots, recorded
# Per drill: wall time; a timestamped transcript (VM start-up); QEMU peak RSS; the QEMU unit's cgroup memory.peak;
# vmstat CPU; the VM work directory's disk high-water; the decision; per-table digest equality; the signed and
# witnessed record; QEMU's account, cgroup and network namespace.
# Usage: vm-benchmark.sh <out dir>
set -uo pipefail
OUT="$(mkdir -p "${1:?out dir}" && cd "$1" && pwd)"
L=/usr/local/lib/dms-restore-drill
ORCH="$L/drill/scripts/restore-drill-vm.sh"
RUNR=dmsdrill-runner; QEU=dmsdrill-qemu
INBOX=/var/lib/dms-restore-drill/inbox
IP=/etc/dms-restore-drill/image.properties
PGB=/usr/lib/postgresql/16/bin
[ -x "$ORCH" ] && id "$QEU" > /dev/null 2>&1 && [ -r "$IP" ] || { echo "the drill-host layout is not provisioned"; exit 2; }
RESULTS="$OUT/results-benchmark.txt"
: > "$RESULTS"
PASS=0; FAIL=0
check() { if [ "$3" -eq 0 ]; then PASS=$((PASS + 1)); echo "PASS   $1  $2  [$4]" >> "$RESULTS"; else FAIL=$((FAIL + 1)); echo "FAIL   $1  $2  [$4]" >> "$RESULTS"; fi; }
bench() { echo "BENCH  $1  $2" >> "$RESULTS"; }
W="$(mktemp -d /tmp/rdb.XXXXXX)"
chmod 0700 "$W"
PGD="$W/pgdata"; SOCK="$W"; PORT=55440
cleanup() { "$PGB/pg_ctl" -D "$PGD" -m fast stop > /dev/null 2>&1; rm -rf -- "$W"; }
trap cleanup EXIT

# --- the runner, as the gate records it -----------------------------------------------------------------------------
{
  echo "runner_cpu=$(lscpu | sed -n 's/^Model name: *//p')"
  echo "runner_cpus=$(nproc) $(lscpu | sed -n 's/^Thread(s) per core: */threads_per_core=/p')"
  echo "runner_mem_total_kib=$(sed -n 's/^MemTotal: *\([0-9]*\).*/\1/p' /proc/meminfo)"
  echo "runner_disk=$(df -B1 --output=size,avail / | tail -n 1)"
  echo "runner_kernel=$(uname -r) kvm=$(test -c /dev/kvm && echo yes || echo no)"
  grep -E '^DMS_IMAGE_(PG_MAJOR|PG_MINOR|EXTENSIONS)=' "$IP"
} > "$OUT/runner.txt"

# --- a throwaway owner key, its signed synthetic contract and manifest (the gate's own pattern) ----------------------
ssh-keygen -q -t ed25519 -N '' -C owner -f "$W/owner"
echo "owner@ci $(cat "$W/owner.pub")" | sudo install -o root -g root -m 0644 /dev/stdin /etc/dms-restore-drill/manifest-anchor
prop() { sed -n "s/^$1=//p" "$IP" | head -n 1; }
{
  printf 'DMS_ENV_CONTRACT_VERSION=1\nDMS_ENV_CONTRACT_ID=ci-bench-env-0001\nDMS_ENV_CONTRACT_SOURCE=synthetic-ci\nDMS_ENV_CONTRACT_ISSUED_BY=owner@ci\n'
  printf 'DMS_ENV_CONTRACT_ISSUED_AT=%s\nDMS_ENV_CONTRACT_NOT_AFTER=%s\n' "$(date -u -d '-1 minute' +%Y-%m-%dT%H:%M:%SZ)" "$(date -u -d '+1 day' +%Y-%m-%dT%H:%M:%SZ)"
  printf 'DMS_ENV_PG_MAJOR=%s\nDMS_ENV_PG_MINOR=%s\nDMS_ENV_SERVER_ENCODING=UTF8\nDMS_ENV_LOCALE_PROVIDER=libc\n' "$(prop DMS_IMAGE_PG_MAJOR)" "$(prop DMS_IMAGE_PG_MINOR)"
  printf 'DMS_ENV_DATCOLLATE=C.UTF-8\nDMS_ENV_DATCTYPE=C.UTF-8\nDMS_ENV_ICU_LOCALE=none\nDMS_ENV_COLLATION_VERSION=none\n'
  printf 'DMS_ENV_GLIBC_VERSION=%s\nDMS_ENV_ICU_VERSION=%s\nDMS_ENV_TZDATA_VERSION=%s\nDMS_ENV_EXTENSIONS=none\n' "$(prop DMS_IMAGE_GLIBC_VERSION)" "$(prop DMS_IMAGE_ICU_VERSION)" "$(prop DMS_IMAGE_TZDATA_VERSION)"
  printf 'DMS_ENV_SET_TIMEZONE=Asia/Makassar\nDMS_ENV_SET_DATESTYLE=ISO, MDY\nDMS_ENV_SET_INTERVALSTYLE=postgres\nDMS_ENV_SET_ARRAY_NULLS=on\n'
  printf 'DMS_ENV_SET_LC_MONETARY=C.UTF-8\nDMS_ENV_SET_LC_NUMERIC=C.UTF-8\nDMS_ENV_SET_LC_TIME=C.UTF-8\nDMS_ENV_SET_EXTRA_FLOAT_DIGITS=1\n'
  printf 'DMS_ENV_SET_BYTEA_OUTPUT=hex\nDMS_ENV_SET_XMLBINARY=base64\nDMS_ENV_SET_XMLOPTION=content\nDMS_ENV_SET_STANDARD_CONFORMING_STRINGS=on\n'
  printf 'DMS_ENV_SET_QUOTE_ALL_IDENTIFIERS=off\nDMS_ENV_SET_DEFAULT_TEXT_SEARCH_CONFIG=pg_catalog.english\nDMS_ENV_SET_TIMEZONE_ABBREVIATIONS=Default\nDMS_ENV_SET_TRANSFORM_NULL_EQUALS=off\n'
} > "$W/bench-env-contract"
ssh-keygen -q -Y sign -f "$W/owner" -n dms-restore-env-contract "$W/bench-env-contract" > /dev/null
{
  printf 'DMS_ROLE_MANIFEST_CONTRACT=2\nDMS_ROLE_MANIFEST_ID=ci-bench-manifest-0001\nDMS_ROLE_MANIFEST_ROLES=app_runtime dms_owner\n'
  printf 'DMS_ROLE_MANIFEST_RUNTIME_ROLE=app_runtime\nDMS_ROLE_MANIFEST_R6_OWNER_ROLE=none\nDMS_ROLE_MANIFEST_ISSUED_BY=owner@ci\n'
  printf 'DMS_ROLE_MANIFEST_ENV_CONTRACT_SHA256=%s\n' "$(sha256sum "$W/bench-env-contract" | cut -d' ' -f1)"
  printf 'DMS_ROLE_MANIFEST_ISSUED_AT=%s\nDMS_ROLE_MANIFEST_NOT_AFTER=%s\n' "$(date -u -d '-1 minute' +%Y-%m-%dT%H:%M:%SZ)" "$(date -u -d '+1 day' +%Y-%m-%dT%H:%M:%SZ)"
} > "$W/bench-manifest"
ssh-keygen -q -Y sign -f "$W/owner" -n dms-restore-role-manifest "$W/bench-manifest" > /dev/null

# --- synthetic databases from the fixture generator's clean schema, dumped the way BK-2 dumps --------------------------
"$PGB/initdb" -D "$PGD" -U benchsu -A trust -E UTF8 --locale=C.UTF-8 > /dev/null || { echo "initdb failed"; exit 2; }
"$PGB/pg_ctl" -D "$PGD" -o "-k $SOCK -p $PORT -c listen_addresses=''" -l "$W/pg.log" -w start > /dev/null || { echo "the throwaway cluster did not start"; exit 2; }
q() { "$PGB/psql" -X -q -v ON_ERROR_STOP=1 -h "$SOCK" -p "$PORT" "$@"; }
q -U benchsu -d postgres -c "CREATE ROLE app_runtime LOGIN" -c "CREATE ROLE dms_owner LOGIN"
gen() { # <db> <rows>: about 425 bytes of compressible clinical-like row text per row (production compresses about 6:1)
  q -U benchsu -d postgres -c "CREATE DATABASE $1"
  q -U benchsu -d "$1" -c "GRANT CREATE ON SCHEMA public TO app_runtime" -c "GRANT CREATE ON DATABASE $1 TO app_runtime"
  q -U app_runtime -d "$1" <<SQL
CREATE TABLE migrations (id serial PRIMARY KEY, migration text NOT NULL);
INSERT INTO migrations (migration) VALUES ('synthetic_one'), ('synthetic_two');
CREATE TABLE visits (id bigint PRIMARY KEY, branch_id int NOT NULL, visit_date date NOT NULL, note text NOT NULL);
INSERT INTO visits SELECT g, g % 5 + 1, date '2026-01-01' + (g % 300),
  'synthetic note ' || g || ': ' || repeat('tooth 16 occlusal caries, composite restoration, review in 6 months; ', 5) || md5(g::text)
  FROM generate_series(1, $2) g;
CREATE INDEX visits_branch_date ON visits (branch_id, visit_date);
SQL
  "$PGB/pg_dump" -h "$SOCK" -p "$PORT" -U app_runtime -d "$1" -Fc -C -Z 6 -f "$W/$1.dump"
}
#        case rows (about 425 bytes each)
CASES="B1:74000 B2:237000 B3:444000 B4:518000"
for c in $CASES; do
  n="${c%%:*}"; r="${c##*:}"; db="bench_${n,,}"
  gen "$db" "$r" || { check "$n-generate" "the synthetic archive was generated" 1 "rows=${r}"; continue; }
  cp -- "$W/$db.dump" "$W/$n.dump"
  sudo install -o "$(id -un)" -g dmsdrill-inbox -m 0640 "$W/$n.dump" "$INBOX/bench-$n.dump"
  bytes="$("$PGB/pg_restore" -f - --data-only "$W/$n.dump" | wc -c)"
  bench "$n-archive" "rows=${r} archive_bytes=$(stat -c %s "$W/$n.dump") copy_text_bytes=${bytes} sha256=$(sha256sum "$W/$n.dump" | cut -d' ' -f1)"
done
for f in bench-env-contract bench-env-contract.sig bench-manifest bench-manifest.sig; do
  sudo install -o "$(id -un)" -g dmsdrill-inbox -m 0640 "$W/$f" "$INBOX/$f"
done

# --- one measured drill -------------------------------------------------------------------------------------------------
drill_measured() { # <case>: writes $OUT/<case>.txt (timestamped transcript) and the samples; echoes the exit status
  local c="$1" sha t0 t1 rc vmpid smpid
  sha="$(sha256sum "$W/$c.dump" | cut -d' ' -f1)"
  vmstat -n 1 > "$OUT/$c-vmstat.txt" 2>&1 &
  vmpid=$!
  ( prss=0; pcg=0; pdisk=0; ident=""
    while :; do
      for p in $(pgrep -u "$QEU" 2> /dev/null); do
        [ "$(sudo readlink "/proc/${p}/exe" 2> /dev/null)" = /usr/bin/qemu-system-x86_64 ] || continue
        r="$(ps -o rss= -p "$p" 2> /dev/null | tr -d ' ')"; [ -n "$r" ] && [ "$r" -gt "$prss" ] && prss="$r"
        [ -n "$ident" ] || ident="user=$(ps -o user= -p "$p") cgroup=$(sudo cat "/proc/${p}/cgroup" 2> /dev/null) netns_qemu=$(sudo readlink "/proc/${p}/ns/net") netns_host=$(sudo readlink /proc/1/ns/net)"
      done
      m="$(sudo cat /sys/fs/cgroup/system.slice/dmsqemu.service/memory.peak 2> /dev/null || echo 0)"; [ "${m:-0}" -gt "$pcg" ] && pcg="$m"
      d="$(sudo du -sb /var/lib/dmsdrill-qemu/work 2> /dev/null | cut -f1)"; [ "${d:-0}" -gt "$pdisk" ] && pdisk="$d"
      printf 'qemu_peak_rss_kib=%s qemu_unit_memory_peak_bytes=%s vm_workdir_peak_bytes=%s %s\n' "$prss" "$pcg" "$pdisk" "$ident" > "$OUT/$c-peaks.txt"
      sleep 0.5
    done ) > /dev/null 2>&1 &
  smpid=$!
  t0="$(date +%s.%N)"
  sudo -n -u "$RUNR" -- "$ORCH" --env-contract "$INBOX/bench-env-contract" --expected-sha256 "$sha" --manifest "$INBOX/bench-manifest" "$INBOX/bench-$c.dump" 2>&1 \
    | while IFS= read -r line; do printf '%s %s\n' "$(date +%s.%N)" "$line"; done > "$OUT/$c.txt"
  rc="${PIPESTATUS[0]}"
  t1="$(date +%s.%N)"
  kill "$smpid" "$vmpid" 2> /dev/null; wait "$smpid" "$vmpid" 2> /dev/null
  echo "wall_seconds=$(awk -v a="$t0" -v b="$t1" 'BEGIN { printf "%.1f", b - a }') exit=${rc}" > "$OUT/$c-wall.txt"
  echo "$rc"
}
has() { grep -q -- "$2" "$OUT/$1.txt"; }
recorded() { grep -Eq 'Evidence: started RECORD [0-9]{6} [0-9a-f]{64} witnessed' "$OUT/$1.txt" && grep -Eq 'Evidence: RECORD [0-9]{6} [0-9a-f]{64} witnessed' "$OUT/$1.txt"; }
cpu_summary() { awk 'NR > 2 && $13 ~ /^[0-9]+$/ { n++; u = $13 + $14; s += u; if (u > m) m = u } END { if (n) printf "cpu_busy_avg_pct=%.0f cpu_busy_max_pct=%d samples=%d", s / n, m, n; else printf "cpu=unsampled" }' "$OUT/$1-vmstat.txt"; }
vm_phase() { # seconds from the VM-boot line to the decision line
  awk '/VM boot in the supervised QEMU unit/ { b = $1 } /Decision: / && b { printf "vm_boot_to_decision_seconds=%.1f", $1 - b; exit }' "$OUT/$1.txt"
}
for n in B1 B2 B3; do
  [ -f "$W/$n.dump" ] || continue
  rc="$(drill_measured "$n")"
  sha="$(sha256sum "$W/$n.dump" | cut -d' ' -f1)"
  rec="$(sudo sh -c "grep -l '${sha}' /var/lib/dmsdrill-signer/store/*/record 2> /dev/null | xargs -r grep -l '^DMS_DRILL_EVIDENCE_DECISION=GO\$' | head -n 1 | xargs -r cat")"
  exp="$(printf '%s\n' "$rec" | sed -n 's/^DMS_DRILL_EVIDENCE_EXPECTED=//p')"; gst="$(printf '%s\n' "$rec" | sed -n 's/^DMS_DRILL_EVIDENCE_GUEST_ASSERTED=//p')"
  dig() { printf '%s' "$1" | tr ',' '\n' | sed -n "s/^$2=//p"; }
  same=1; [ -n "$(dig "$exp" per_table_digest)" ] && [ "$(dig "$exp" per_table_digest)" = "$(dig "$gst" per_table_digest)" ] && [ "$(dig "$exp" rows)" = "$(dig "$gst" rows)" ] && same=0
  peaks="$(cat "$OUT/$n-peaks.txt" 2> /dev/null)"
  isolated=1; [[ "$peaks" == *"user=${QEU} "* ]] && [[ "$peaks" == *"0::/system.slice/dmsqemu.service"* ]] \
    && [ "$(sed -n 's/.*netns_qemu=\([^ ]*\) .*/\1/p' <<< "$peaks")" != "$(sed -n 's/.*netns_host=//p' <<< "$peaks")" ] && isolated=0
  check "$n" "the synthetic archive restores GO in the VM, digests equal, signed and witnessed, QEMU isolated" \
    "$([ "$rc" = 0 ] && has "$n" 'Decision: GO ' && recorded "$n" && [ "$same" = 0 ] && [ "$isolated" = 0 ]; echo $?)" \
    "exit=${rc} digests_equal=$([ "$same" = 0 ] && echo yes || echo no) isolated=$([ "$isolated" = 0 ] && echo yes || echo no) rows=$(dig "$gst" rows)"
  bench "$n-measured" "$(cat "$OUT/$n-wall.txt") $(vm_phase "$n") $(cpu_summary "$n") $(sed 's/ user=.*//' "$OUT/$n-peaks.txt" 2> /dev/null) tmpfs=$(grep -o 'tmpfs_mib=[0-9]*' "$OUT/$n.txt" | head -n 1)"
done
# B4: above the D6 envelope, refused before any VM boots (the limit is enforced, never relaxed), and recorded.
if [ -f "$W/B4.dump" ]; then
  rc="$(drill_measured B4)"
  check B4 "an archive above the D6 working-space limit is refused before any VM boots, and the refusal is recorded" \
    "$([ "$rc" != 0 ] && has B4 'above the pilot limit 1024 MiB (D6)' && ! has B4 'VM boot in the supervised QEMU unit' && recorded B4; echo $?)" \
    "exit=${rc} $(grep -o 'needs about [0-9]* MiB of working space ([0-9]* bytes of data)' "$OUT/B4.txt" | head -n 1)"
  bench B4-measured "$(cat "$OUT/B4-wall.txt")"
fi
echo "TOTAL pass=${PASS} fail=${FAIL}" >> "$RESULTS"
cat "$OUT/runner.txt" "$RESULTS"
[ "$FAIL" = 0 ]
