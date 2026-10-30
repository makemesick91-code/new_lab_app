#!/usr/bin/env bash
#
# CI-PERF-04-CONTROLLED-PARALLEL-TESTING — resource sampler for EVALUATION runs only.
#
# `start` launches a background sampler (every 2 s) that records CPU busy %, memory
# used, PHP test-process RSS, PostgreSQL active backends and backends waiting on a
# lock, and snapshots the working tree. `stop` ends it, prints a one-line RESULT
# summary, and reports any path the test run created OUTSIDE the evidence and
# framework scratch directories (an evidence-isolation regression would show up
# here as a new file under a canonical evidence path).
#
# Never used on pull_request / push / schedule runs: the Critical Gate itself is
# never slowed down or changed by measurement. Writes only under storage/ci-evidence/.
#
# Usage: scripts/ci/sample-critical-resources.sh start|stop

set -uo pipefail

DIR="storage/ci-evidence"
CSV="${DIR}/nsf-r011-critical-resource-samples.csv"
PIDFILE="${DIR}/.ci-perf-04-sampler.pid"
PRE="${DIR}/.ci-perf-04-tree-before.txt"
SUMMARY="${DIR}/nsf-r011-critical-resource-summary.txt"
mkdir -p "$DIR"

tree_snapshot() {
    git status --porcelain --untracked-files=all 2>/dev/null \
        | grep -vE '^\?\? (storage/ci-evidence/|storage/framework/|storage/logs/|bootstrap/cache/|\.env$)' \
        | sort
}

case "${1:-}" in
    start)
        tree_snapshot > "$PRE"
        (
            echo "t,cpu_busy_pct,mem_used_mb,php_rss_mb,pg_active,pg_lock_waits"
            prev=$(awk '/^cpu /{print $2+$3+$4+$6+$7+$8, $2+$3+$4+$5+$6+$7+$8}' /proc/stat)
            while :; do
                sleep 2
                now=$(awk '/^cpu /{print $2+$3+$4+$6+$7+$8, $2+$3+$4+$5+$6+$7+$8}' /proc/stat)
                busy=$(awk -v a="$prev" -v b="$now" 'BEGIN{split(a,x," ");split(b,y," "); d=y[2]-x[2]; print (d>0)?int((y[1]-x[1])*100/d):0}')
                prev=$now
                used=$(awk '/MemTotal/{t=$2} /MemAvailable/{a=$2} END{printf "%d", (t-a)/1024}' /proc/meminfo)
                rss=$(ps -eo rss,args | awk '/php/ && /(artisan test|pest|paratest|phpunit)/ && !/awk/ {s+=$1} END{printf "%d", s/1024}')
                pg=""
                if command -v psql >/dev/null 2>&1; then
                    pg=$(PGPASSWORD="${DB_PASSWORD:-}" psql -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-postgres}" -Atc \
                        "select count(*) filter (where state='active'), count(*) filter (where wait_event_type='Lock') from pg_stat_activity where backend_type='client backend'" \
                        "${DB_DATABASE:-testing}" 2>/dev/null | tr '|' ',')
                fi
                echo "$(date +%s),${busy},${used},${rss},${pg:-,}"
            done
        ) > "$CSV" 2>/dev/null &
        echo $! > "$PIDFILE"
        echo "ci-perf-04 sampler started (pid $(cat "$PIDFILE")), nproc=$(nproc), mem_total_mb=$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo), cpu=$(lscpu 2>/dev/null | sed -n 's/^Model name:[[:space:]]*//p' | head -1)"
        ;;
    stop)
        [[ -f "$PIDFILE" ]] && kill "$(cat "$PIDFILE")" 2>/dev/null
        rm -f "$PIDFILE"
        python3 -I - "$CSV" <<'PY' | tee "$SUMMARY"
import csv, statistics as st, sys
rows = list(csv.DictReader(open(sys.argv[1])))
def col(k):
    out = []
    for r in rows:
        v = (r.get(k) or '').strip()
        if v:
            out.append(float(v))
    return out
cpu, mem, rss, act, lock = col('cpu_busy_pct'), col('mem_used_mb'), col('php_rss_mb'), col('pg_active'), col('pg_lock_waits')
fmt = lambda xs, f: (f(xs) if xs else 'n/a')
print("RESULT samples=%d cpu_busy_mean=%s cpu_busy_peak=%s mem_used_peak_mb=%s php_rss_peak_mb=%s pg_active_peak=%s pg_lock_wait_samples=%s"
      % (len(rows), fmt(cpu, lambda x: round(st.mean(x), 1)), fmt(cpu, max), fmt(mem, max), fmt(rss, max),
         fmt(act, max), ('%d/%d' % (sum(1 for x in lock if x > 0), len(lock))) if lock else 'n/a'))
PY
        NEW=$(comm -13 "$PRE" <(tree_snapshot) || true)
        if [[ -n "$NEW" ]]; then
            echo "workspace_paths_created_by_tests=$(printf '%s\n' "$NEW" | grep -c .)" | tee -a "$SUMMARY"
            printf '%s\n' "$NEW" | head -50 | sed 's/^/  /' | tee -a "$SUMMARY"
        else
            echo "workspace_paths_created_by_tests=0" | tee -a "$SUMMARY"
        fi
        [[ -n "${GITHUB_STEP_SUMMARY:-}" ]] && { echo '```'; cat "$SUMMARY"; echo '```'; } >> "$GITHUB_STEP_SUMMARY"
        ;;
    *)
        echo "usage: $0 start|stop" >&2
        exit 2
        ;;
esac
exit 0
