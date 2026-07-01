# Sprint 68.16 — Pilot Performance Monitoring Weekly Evidence Review

## Executive Summary

- Sprint 68.16 mengulang checklist monitoring performa pilot (Sprint 68.14) **sekali** dalam mode **read-only** pada VPS/pilot — tanpa deploy, migrasi, restart, atau perubahan konfigurasi.
- Hasil dibandingkan dengan baseline Sprint 68.15; **tidak ada drift material** pada aplikasi, layanan, sumber daya, database, indeks, Q5/Q6, maupun HTTP dasar.
- SSH ke `daengtisiams-vps` (`srv1730088`) berhasil; path aplikasi `/var/www/asia-dental-lab-v2` terverifikasi.
- Kesehatan aplikasi **OK**: `APP_ENV=pilot`, debug **OFF**, maintenance **OFF**, Laravel 12.61.0, PHP 8.3.6, cache config/events/routes/views **CACHED**.
- Layanan **OK**: php8.3-fpm, nginx, PostgreSQL semua `active`.
- Sumber daya VPS **OK**: disk ~92 GB bebas (5% terpakai), memori ~7.1 GB available, load average ~0.00, uptime 27 hari.
- Database pilot **stabil** (18 MB): 32 pasien, 26 kunjungan, 17 invoice, 25 pembayaran — delta nol vs Sprint 68.15; volume masih jauh di bawah ambang review ketat (10k payments).
- Q5/Q6 pada pilot (branch_id=2, window 2026-01-01 s/d 2027-01-01, 16 baris match): Q5 **0.071 ms**, Q6 **0.083 ms** — **OK**; validasi fungsional pada volume kecil, bukan stress test.
- Log Laravel: tidak ada error kritis **baru** sejak 2026-06-30; temuan lama = Auth class typo (2026-06-29), tinker parse error, view online-context exception, cache permission denied (2026-06-29/30).
- HTTP dasar **OK**: `/` → 302 ke login; `/login` → 200. Benchmark HTTP terautentikasi **skipped** (`stress:benchmark-rme-pages` hanya `APP_ENV=stress`).
- **Deploy tidak diperlukan.** VPS masih pada tag `sprint-68-2-read-path-bottleneck-investigation-go` (HEAD `f365eef`) — sama dengan Sprint 68.15; tidak ada bukti performa yang memerlukan deploy atau optimisasi.
- **Keputusan akhir: OK** — lanjutkan monitoring mingguan sesuai Sprint 68.14.

## Deploy Decision

| Item | Decision |
|---|---|
| Deploy needed | No |
| SSH needed | Yes, read-only evidence only |
| Migration/index needed | No |
| Runtime code change needed | No |
| VPS config change | No |
| Reason | Weekly evidence review only; semua metrik dalam ambang OK |

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
| Authenticated HTTP benchmark | Skipped | Command restricted to `APP_ENV=stress`; pilot env forbidden |

## Environment / Release Context

| Metric | Sprint 68.15 Baseline | Sprint 68.16 Current | Decision |
|---|---|---|---|
| Checked at | 2026-07-01T11:27 UTC | 2026-07-01T11:36 UTC | OK |
| Hostname | srv1730088 | srv1730088 | OK |
| App path | /var/www/asia-dental-lab-v2 | /var/www/asia-dental-lab-v2 | OK |
| APP_ENV | pilot | pilot | OK |
| Debug mode | OFF | OFF | OK |
| Maintenance mode | OFF | OFF | OK |
| Laravel version | 12.61.0 | 12.61.0 | OK |
| PHP version | 8.3.6 | 8.3.6 | OK |
| Current git HEAD | f365eef | f365eef | OK — no release drift |
| Exact tag at HEAD | sprint-68-2-read-path-bottleneck-investigation-go | sprint-68-2-read-path-bottleneck-investigation-go | OK |
| Working tree status | clean | clean | OK |

## Service Health

| Service | Sprint 68.15 | Sprint 68.16 | Decision |
|---|---|---|---|
| php8.3-fpm | active | active (running) | OK |
| nginx | active | active (running) | OK |
| PostgreSQL | active | active (cluster running) | OK |

## VPS Resource Health

| Metric | Sprint 68.15 | Sprint 68.16 | Decision |
|---|---:|---:|---|
| Disk free | 92 GB | 92 GB (5% used) | OK |
| Memory available | 7.1 GB | 7.1 GB | OK |
| Load average | ~0.00 | 0.00, 0.00, 0.00 | OK |
| Uptime | 27 days | 27 days, 12h | OK |

## Laravel Log Review

| Finding | Sprint 68.15 | Sprint 68.16 | Decision |
|---|---|---|---|
| Fresh critical errors | none since 2026-06-30 | none since 2026-06-30 | OK |
| SQLSTATE/errors | old known only | 0 new SQLSTATE in sampled tail | OK |
| Timeout/errors | none known | 0 timeout in sampled tail | OK |
| Notes | old tinker/view/cache-permission | Same categories only: (1) Auth class not found in AuthenticatedSessionController — 2026-06-29, operator/debugging; (2) tinker/psysh parse errors — 2026-06-29; (3) online-context/select.blade.php BadMethodCallException — 2026-06-29, known view bug; (4) cache Permission denied on storage/framework/cache — 2026-06-30, resolved operationally. No patient PII, KTP/NIK, or tokens copied to this document. | OK |

## Database Size and Counts

| Metric/Table | Sprint 68.15 | Sprint 68.16 | Delta | Decision |
|---|---:|---:|---:|---|
| DB size | 18 MB | 18 MB | 0 | OK |
| mst_patients | 32 | 32 | 0 | OK |
| trx_clinic_visits | 26 | 26 | 0 | OK |
| trx_rme_invoices | 17 | 17 | 0 | OK |
| trx_rme_payments | 25 | 25 | 0 | OK |

Payment date range (branch_id=2): `2026-06-13` – `2026-06-27` (16 rows match 2026 window filter).

## Table Growth / Autovacuum Snapshot

| Table | Live Rows | Dead Rows | Total Size | Last Autovacuum | Last Autoanalyze | Decision |
|---|---:|---:|---:|---|---|---|
| mst_patients | 32 | 9 | 128 kB | — | 2026-06-16 | OK |
| trx_clinic_visits | 26 | 5 | 176 kB | 2026-06-29 | 2026-06-26 | OK |
| trx_rme_invoices | 17 | 34 | 112 kB | — | 2026-06-27 | OK |
| trx_rme_payments | 25 | 0 | 128 kB | — | — | OK |

Dead tuples on invoices (34) tetap negligible pada skala pilot; autovacuum tidak urgent.

## DB Connections / Long Queries

| Metric | Sprint 68.15 | Sprint 68.16 | Decision |
|---|---|---|---|
| Connection count by state | 1 active | active: 1 (monitoring session only) | OK |
| Long-running queries | none | none (only self-query) | OK |

## Payment Index Review

| Index | Sprint 68.15 | Sprint 68.16 | Decision |
|---|---|---|---|
| trx_rme_payments_branch_paid_at_idx | valid, 16 kB | valid, ready, 16 kB | OK |

## Q5/Q6 SQL Timing

Branch used: `branch_id=2` (most payments). Window: `2026-01-01` – `2027-01-01` (covers all pilot payment dates). Matching rows: 16.

| Query | Sprint 68.15 | Sprint 68.16 | Delta | Threshold Status | Decision |
|---|---:|---:|---:|---|---|
| Q5 Owner aggregate | 0.073 ms | 0.071 ms | −0.002 ms | OK (<100 ms) | OK |
| Q6 Daily trend | 0.220 ms | 0.083 ms | −0.137 ms | OK (<100 ms) | OK |

Plan summary:
- Q5: Seq Scan on trx_rme_payments (25 rows total, 16 match filter); shared hit=1.
- Q6: Seq Scan + GroupAggregate + quicksort (7 day groups); shared hit=4.

**Caveat:** Pilot has only 25 payments vs 500,400 in local stress. These timings confirm query correctness and index presence, not capacity at scale.

## HTTP Basic Check

| Target | Sprint 68.15 | Sprint 68.16 | Decision |
|---|---|---|---|
| `/` | 302 login | HTTP 302 → /login | OK |
| `/login` | 200 | HTTP 200 OK | OK |
| Authenticated benchmark | skipped | skipped — `stress:benchmark-rme-pages` requires `APP_ENV=stress` | OK |

## Comparison With Local Stress Closure

| Area | Local Stress Closure | Sprint 68.16 Pilot/VPS | Decision |
|---|---|---|---|
| Data volume | 250k patients / 556k visits / 500k payments | 32 patients / 26 visits / 25 payments | Not comparable — pilot too small |
| Q5 | ~80 ms @ 500k payments | 0.071 ms @ 25 payments | Not comparable — OK at pilot scale |
| Q6 | ~118 ms @ 500k payments | 0.083 ms @ 25 payments | Not comparable — OK at pilot scale |
| DB size | ~2,059 MB | 18 MB | Expected delta |
| Owner Dashboard | ~60 ms avg local stress | Not measured (benchmark deferred) | N/A — repeat when volume grows |

Pilot volume jauh lebih kecil daripada data stress lokal. Q5/Q6 minggu ini adalah health check fungsional, bukan perbandingan kapasitas stress.

## Final Decision

**OK** — Tidak ada aksi yang diperlukan.

**Reasoning:**
- Semua area evidence (app, services, resources, DB, index, Q5/Q6, HTTP) dalam ambang OK.
- Tidak ada delta material vs Sprint 68.15 (counts, size, release context identik).
- Tidak ada error kritis baru di log.
- Payment rows (25) jauh di bawah ambang 10k untuk review mingguan lebih ketat.
- Tidak ada keluhan pengguna atau bukti slowness.
- Deploy, migrasi, optimisasi query, atau perubahan konfigurasi **tidak diperlukan**.

## Recommendation

- Lanjutkan monitoring mingguan sesuai Sprint 68.14.
- Ulangi evidence review ketika `trx_rme_payments` mendekati atau melebihi **10k** baris, atau jika ada laporan kelambatan dari pengguna.
- Tidak perlu deploy VPS ke tag terbaru untuk tujuan performa — bukti minggu ini tidak memerlukannya.
- Tidak perlu sprint optimisasi atau investigasi performa saat ini.

**Recommended next sprint options:**
- Sprint 68.17 — Pilot Performance Monitoring Weekly Evidence Review 2 (jadwal rutin)
- Sprint 68.17 — Pilot Monitoring Automation Plan (jika owner ingin mengurangi manual SSH)
- Sprint 68.17 — Pilot Performance Investigation (hanya jika metrik atau keluhan berubah)

## What Was Not Done

- No deploy.
- No git pull/fetch/checkout on VPS.
- No migration.
- No restart/reload.
- No config change.
- No DB write.
- No raw log copy to repository.
- No authenticated benchmark (unsafe/unavailable in pilot env).
- No nginx/php-fpm raw log review (skipped — privacy/ops; same as Sprint 68.15).

## Commands Run

```bash
# Local
git fetch origin
git switch feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git switch -c feature/sprint-68-16-pilot-performance-monitoring-weekly-evidence-review
git status --short
php artisan about
graphify update .

# VPS SSH (read-only, summarized)
ssh daengtisiams-vps 'echo SSH_OK; test -d /var/www/asia-dental-lab-v2'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan about'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && git status --short && git rev-parse HEAD && git describe --tags --exact-match HEAD'
ssh daengtisiams-vps 'systemctl is-active php8.3-fpm nginx postgresql'
ssh daengtisiams-vps 'df -h; free -h; uptime'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && grep -Ei ERROR\|CRITICAL\|SQLSTATE storage/logs/laravel.log | tail -n 40 | wc -l'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && source .env && psql ... (db size, counts, growth, connections, index, Q5/Q6 EXPLAIN ANALYZE)'
ssh daengtisiams-vps 'curl -I http://127.0.0.1; curl -I http://127.0.0.1/login'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan list | grep benchmark-rme'
```

## Safety Confirmation

- No deploy.
- No VPS write operation.
- No migration.
- No destructive DB command.
- No business logic changed.
- No `.env`/backup/SSH key/DB dump/log committed.
- No real PII/KTP/NIK exposed.
- SSH was read-only.
- Evidence was summarized, not raw sensitive data.

## Final Status

DONE / COMMITTED / PUSHED / PR MERGED / GO-TAGGED / NO DEPLOY
