# Sprint 68.15 — Pilot Performance Monitoring Evidence Pack

## Executive Summary

- Sprint 68.15 menjalankan checklist monitoring performa pilot (Sprint 68.14) **sekali** pada VPS/pilot dalam mode **read-only** — tanpa deploy, migrasi, restart, atau perubahan konfigurasi.
- SSH ke `daengtisiams-vps` (`srv1730088`) berhasil; path aplikasi `/var/www/asia-dental-lab-v2` terverifikasi.
- Kesehatan aplikasi **OK**: `APP_ENV=pilot`, debug **OFF**, maintenance **OFF**, Laravel 12.61.0, PHP 8.3.6, cache config/events/routes/views **CACHED**.
- Layanan **OK**: php8.3-fpm, nginx, PostgreSQL semua `active`.
- Sumber daya VPS **OK**: disk ~92 GB bebas (5% terpakai), memori ~7.1 GB available, load average ~0.00.
- Database pilot **sangat kecil** (18 MB): 32 pasien, 26 kunjungan, 17 invoice, 25 pembayaran — **tidak comparable** dengan baseline stress lokal 250k/556k/500k.
- Q5/Q6 pada pilot (branch_id=2, window 2026-01-01 s/d 2027-01-01, 16 baris match): Q5 **0.073 ms**, Q6 **0.220 ms** — **OK**, tetapi hanya validasi fungsional pada volume kecil, bukan stress test.
- Log Laravel: tidak ada error kritis **baru** sejak 2026-06-30; temuan lama = tinker parse error, view online-context exception, cache permission denied (2026-06-29/30).
- HTTP dasar **OK**: `/` → 302 ke login; `/login` → 200. Benchmark HTTP terautentikasi **deferred** (`stress:benchmark-rme-pages` hanya `APP_ENV=stress`).
- **Deploy tidak diperlukan.** VPS masih pada tag `sprint-68-2-read-path-bottleneck-investigation-go` (HEAD `f365eef`) — di luar scope sprint ini; tidak ada bukti performa yang memerlukan deploy optimisasi.
- **Keputusan akhir: OK** — lanjutkan monitoring mingguan sesuai Sprint 68.14.

## Deploy Decision

| Item | Decision |
|---|---|
| Deploy needed | No |
| SSH needed | Yes, read-only evidence only |
| Migration/index needed | No |
| Runtime code change needed | No |
| VPS config change | No |
| Reason | Evidence pack only; semua metrik dalam ambang OK |

## Evidence Collection Scope

| Area | Collected | Notes |
|---|---|---|
| Laravel app health | Yes | `php artisan about` |
| Git/release context | Yes | HEAD, tag, log — read-only |
| Service status | Yes | php8.3-fpm, nginx, postgresql active |
| VPS resource health | Yes | disk, memory, load, uptime |
| Laravel logs | Yes | Privacy-safe category summary only |
| DB size/counts | Yes | 18 MB, counts verified |
| DB table growth | Yes | pg_stat_user_tables snapshot |
| DB connections/long queries | Yes | 1 active, no long queries |
| Payment index review | Yes | Index exists, valid, ready |
| Q5/Q6 timing | Yes | Sub-ms on 25-row pilot dataset |
| HTTP basic check | Yes | 302 + 200 OK |
| Authenticated HTTP benchmark | No | Deferred — command restricted to `APP_ENV=stress`; pilot env forbidden |

## Environment / Release Context

| Metric | Value |
|---|---|
| Checked at | 2026-07-01T11:27 UTC |
| Hostname | srv1730088 |
| App path | /var/www/asia-dental-lab-v2 |
| APP_ENV | pilot |
| Debug mode | OFF |
| Maintenance mode | OFF |
| Laravel version | 12.61.0 |
| PHP version | 8.3.6 |
| Current git HEAD | f365eef |
| Exact tag at HEAD | sprint-68-2-read-path-bottleneck-investigation-go |
| Working tree status | Clean |

## Service Health

| Service | Status | Decision |
|---|---|---|
| php8.3-fpm | active (running) | OK |
| nginx | active (running) | OK |
| PostgreSQL | active (exited wrapper, cluster running) | OK |

## VPS Resource Health

| Metric | Value | Decision |
|---|---:|---|
| Disk free | ~92 GB / 96 GB (5% used) | OK |
| Memory available | ~7.1 GB / 7.8 GB | OK |
| Load average | 0.00, 0.00, 0.00 | OK |
| Uptime | 27 days | OK |

## Laravel Log Review

| Finding | Result |
|---|---|
| Fresh critical errors | None since 2026-06-30 |
| SQLSTATE/errors | 0 recent SQLSTATE in sampled tail |
| Timeout/errors | 0 timeout in sampled tail |
| Notes | Sampled ~50 error-like lines. Categories: (1) tinker/psysh parse errors 2026-06-29 — operator debugging, not runtime; (2) `online-context/select.blade.php` BadMethodCallException 2026-06-29 — known view bug, not performance; (3) cache `Permission denied` on `storage/framework/cache` 2026-06-30 — resolved operationally (permissions). No patient PII, KTP/NIK, or tokens copied to this document. |

## Database Size and Counts

| Metric/Table | Value |
|---|---:|
| DB size | 18 MB |
| mst_patients | 32 |
| trx_clinic_visits | 26 |
| trx_rme_invoices | 17 |
| trx_rme_payments | 25 |

Payment date range: `2026-06-11` – `2026-06-27` (25 rows total).

## Table Growth / Autovacuum Snapshot

| Table | Live Rows | Dead Rows | Total Size | Last Autovacuum | Last Autoanalyze | Decision |
|---|---:|---:|---:|---|---|---|
| mst_patients | 32 | 9 | 128 kB | — | 2026-06-16 | OK |
| trx_clinic_visits | 26 | 5 | 176 kB | 2026-06-29 | 2026-06-26 | OK |
| trx_rme_invoices | 17 | 34 | 112 kB | — | 2026-06-27 | OK |
| trx_rme_payments | 25 | 0 | 128 kB | — | — | OK |

Dead tuples on invoices (34) are negligible at pilot scale; autovacuum not urgent.

## DB Connections / Long Queries

| Metric | Result | Decision |
|---|---|---|
| Connection count by state | active: 1 (monitoring session only) | OK |
| Long-running queries | None (only self-query) | OK |

## Payment Index Review

| Index | Exists | Valid | Ready | Size | Decision |
|---|---|---|---|---|---|
| trx_rme_payments_branch_paid_at_idx | Yes | Yes | Yes | 16 kB | OK |

## Q5/Q6 SQL Timing

Branch used: `branch_id=2` (most payments). Window: `2026-01-01` – `2027-01-01` (covers all pilot payment dates). Matching rows: 16.

| Query | Runtime | Plan Summary | Buffers Summary | Threshold Status | Decision |
|---|---:|---|---|---|---|
| Q5 Owner aggregate | 0.073 ms | Seq Scan on trx_rme_payments (25 rows total, 16 match filter) | shared hit=1 | OK (<100 ms) | OK |
| Q6 Daily trend | 0.220 ms | Seq Scan + GroupAggregate + quicksort (7 day groups) | shared hit=4 | OK (<100 ms) | OK |

**Caveat:** Pilot has only 25 payments vs 500,400 in local stress. These timings confirm query correctness and index presence, not capacity at scale.

## HTTP Check

| Target | Result | Decision |
|---|---|---|
| 127.0.0.1 | HTTP 302 → /login | OK |
| 127.0.0.1/login | HTTP 200 OK | OK |
| Authenticated benchmark | skipped | `stress:benchmark-rme-pages` requires `APP_ENV=stress`; pilot forbidden per Sprint 68.14 runbook |

## Comparison with Local Stress Closure

| Area | Local Stress Closure | Pilot/VPS Evidence | Decision |
|---|---|---|---|
| Data volume | 250k patients / 556k visits / 500k payments | 32 patients / 26 visits / 25 payments | Not comparable — pilot too small |
| Owner Dashboard | ~60 ms avg local stress | Not measured (benchmark deferred) | N/A — repeat when volume grows |
| Q5 | ~80 ms at 500k payments | 0.073 ms at 25 payments | Not comparable — OK at pilot scale |
| Q6 | ~118 ms at 500k payments | 0.220 ms at 25 payments | Not comparable — OK at pilot scale |
| DB size | ~2,059 MB local stress | 18 MB pilot | Expected delta |

Pilot evidence **does not contradict** local stress closure. It confirms the environment is healthy at current real-data volume. Stress-scale conclusions from Sprint 68.8–68.13 remain valid for capacity planning; pilot monitoring must continue as data grows.

## Final Decision

**OK** — No action needed.

Reasoning:
- All service, resource, DB, index, and SQL checks within OK thresholds.
- No user-visible slowness signals; no fresh critical errors.
- Pilot dataset (~25 payments) is far below WATCH/INVESTIGATE capacity triggers (>500k payments, dashboard >300 ms, Q5/Q6 >500 ms).
- VPS release tag lags repo (68.2 vs 68.14 docs) but no performance evidence requires deploy in this sprint.

## Recommendations

Primary recommendation:
- Continue **weekly** pilot monitoring using Sprint 68.14 checklist (`docs/sprints/sprint-68-14-pilot-performance-monitoring-plan.md`).
- Re-run Q5/Q6 and HTTP smoke when payments exceed ~10k or users report slowness.
- Track cache-permission errors — if recurrence, address storage permissions in a separate ops sprint (not performance optimization).

Possible next sprint:
- **Sprint 68.16 — Pilot Performance Monitoring Weekly Evidence Review** (recommended)
- Sprint 68.16 — Pilot Performance Investigation (only if thresholds crossed)
- Sprint 68.16 — Monitoring Automation Plan (future)

## What Was Not Done

- No deploy.
- No migration.
- No restart/reload.
- No config change.
- No DB write.
- No log copy containing PII.
- No authenticated HTTP benchmark on pilot.
- No nginx/php-fpm raw log paste (optional sudo log review skipped to avoid sensitive operational output).

## Commands Run

Summarized read-only commands on `daengtisiams-vps`:

```bash
# Preflight
ssh daengtisiams-vps 'echo SSH_OK; test -d /var/www/asia-dental-lab-v2'

# App health
cd /var/www/asia-dental-lab-v2 && php artisan about

# Git context (no fetch/pull)
git status --short; git rev-parse --short HEAD; git describe --tags --exact-match HEAD; git log --oneline -3

# Services
systemctl is-active php8.3-fpm nginx postgresql
systemctl status php8.3-fpm nginx postgresql --no-pager --lines=5

# Resources
df -h; free -h; uptime; top -b -n 1 | head -n 25

# Laravel log summary (grep count + category review, no PII committed)
grep -Ei "ERROR|CRITICAL|exception|SQLSTATE|timeout" storage/logs/laravel.log | tail -n 50

# PostgreSQL read-only (credentials sourced from .env, never printed)
psql -c "SELECT pg_size_pretty(pg_database_size(current_database()));"
psql -c "SELECT COUNT(*) FROM mst_patients, trx_clinic_visits, trx_rme_invoices, trx_rme_payments;"
psql -c "SELECT MIN/MAX paid_at FROM trx_rme_payments;"
psql -c "SELECT relname, n_live_tup, n_dead_tup, ... FROM pg_stat_user_tables WHERE ..."
psql -c "SELECT state, COUNT(*) FROM pg_stat_activity ..."
psql -c "EXPLAIN (ANALYZE, BUFFERS) ... Q5 aggregate ..."
psql -c "EXPLAIN (ANALYZE, BUFFERS) ... Q6 daily trend ..."
psql -c "SELECT index_name, indisvalid FROM pg_class ... trx_rme_payments_branch_paid_at_idx"

# HTTP
curl -I http://127.0.0.1; curl -I http://127.0.0.1/login
php artisan list | grep stress:benchmark-rme-pages
```

Local:

```bash
graphify update .
git fetch/pull; branch feature/sprint-68-15-pilot-performance-monitoring-evidence-pack
```

## Safety Confirmation

- No deploy.
- No VPS write operation.
- No migration.
- No destructive DB command.
- No business logic changed.
- No `.env`/backup/SSH key/DB dump committed.
- No real PII/KTP/NIK exposed.
- SSH was read-only.
- Evidence was summarized, not raw sensitive data.

## Final Status

DONE / COMMITTED / PUSHED / PR MERGED / GO-TAGGED / NO DEPLOY
