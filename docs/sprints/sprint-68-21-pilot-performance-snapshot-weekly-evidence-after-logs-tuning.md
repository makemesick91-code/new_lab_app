# Sprint 68.21 — Pilot Performance Snapshot Weekly Evidence Review After Logs Tuning

## Executive Summary

- Sprint 68.21 menjalankan **bukti monitoring mingguan pertama setelah tuning Logs Sprint 68.20** menggunakan command `php artisan pilot:performance-snapshot` yang sudah di-deploy pada VPS/pilot.
- SSH ke `daengtisiams-vps` (`srv1730088`) dilakukan dalam mode **read-only**; tidak ada deploy, migrasi, restart, cache clear, composer install, atau npm build.
- VPS terverifikasi pada HEAD `c5f8e23` / tag `sprint-68-20-pilot-snapshot-logs-classification-tuning-go`; tidak ada drift release.
- **Console mode** berjalan sukses; keluaran memisahkan fresh/historical/unparseable dan menampilkan reason Logs.
- **JSON mode** divalidasi dengan `JSON_THROW_ON_ERROR` → **JSON_OK**.
- **Markdown mode** berjalan sukses; baris Logs menampilkan `fresh=0`, `historical_tail=15`, `lookback=24h`.
- **`--fail-on-watch`** mengembalikan **exit code 1** karena overall WATCH — sesuai desain setelah tuning (unparseable stack-trace fallback).
- **`--since=7d`** JSON valid (**JSON_7D_OK**); **`--since=bad`** exit **10** — validasi `--since` tetap benar.
- **Overall status: WATCH** — hanya karena section **Logs** (51 baris error-like stack trace tanpa timestamp; `timestamp_parse_status=partial`).
- **App, Database, Resources, dan HTTP semua OK** — tidak ada regresi performa.
- **Logs tuning Sprint 68.20 berperilaku sesuai harapan:** `fresh_error_like_count=0`, `historical_tail_error_like_count=15` informatif (tidak meng-escalate sendiri), WATCH dari unparseable safety fallback.
- **Deploy tidak diperlukan.** Tidak ada defect command yang ditemukan.
- **Keputusan operasional performa: OK** — lanjutkan weekly snapshot; pertimbangkan Sprint 68.22 stack-trace noise reduction hanya jika WATCH membingungkan operator.

## Deploy Decision

| Item | Decision |
|---|---|
| Deploy needed | No |
| Deploy performed | No |
| Reason | Existing tuned command from Sprint 68.20 was already deployed |
| Code change needed | No |
| Migration needed | No |
| Cron/alert/dashboard | No |

## SSH Decision

| Item | Decision |
|---|---|
| SSH used | Yes |
| Host | daengtisiams-vps / srv1730088 |
| Mode | Read-only command execution |
| VPS write operation | No, except temporary `/tmp` file for fail-on-watch and invalid-since exit checks (removed immediately) |

## Release Context

| Metric | Sprint 68.20 Deploy | Sprint 68.21 Current | Decision |
|---|---|---|---|
| HEAD | c5f8e23 | c5f8e23 | OK — no drift |
| GO tag | sprint-68-20-pilot-snapshot-logs-classification-tuning-go | sprint-68-20-pilot-snapshot-logs-classification-tuning-go | OK |
| Working tree | clean | clean | OK |
| APP_ENV | pilot | pilot | OK |
| Debug | OFF | OFF | OK |
| Maintenance | OFF | OFF | OK |
| Laravel | 12.61.0 | 12.61.0 | OK |
| PHP | 8.3.6 | 8.3.6 | OK |

## Command Availability

| Check | Result |
|---|---|
| artisan list includes command | Yes — `pilot:performance-snapshot` |
| console mode runs | Yes — exit 0 |
| JSON mode validates | Yes — JSON_OK |
| Markdown mode runs | Yes — sections + Logs fresh/historical/lookback rendered |
| fail-on-watch exit check | EXIT_CODE=1 (expected for WATCH overall) |
| since=7d validation | JSON_7D_OK |
| invalid since check | BAD_SINCE_EXIT=10 |

## Snapshot Section Summary

| Section | Sprint 68.20 | Sprint 68.21 | Decision |
|---|---|---|---|
| App | OK | OK | Stable |
| Database | OK | OK | Stable |
| Resources | OK | OK | Stable |
| HTTP | OK | OK | Stable |
| Logs | WATCH | WATCH | Expected — unparseable fallback, not historical |
| Overall | WATCH | WATCH | Driven solely by Logs unparseable count |

## Key Metrics

| Metric | Sprint 68.20 VPS | Sprint 68.21 Current | Delta | Status |
|---|---:|---:|---:|---|
| DB size | 17.94 MB | 17.95 MB | +0.01 | OK |
| Patients | 32 | 32 | 0 | OK |
| Visits | 26 | 26 | 0 | OK |
| Invoices | 17 | 17 | 0 | OK |
| Payments | 25 | 25 | 0 | OK |
| Q5 | 0.023–0.046 ms | 0.025–0.030 ms | ~0 | OK |
| Q6 | 0.023–0.046 ms | 0.048–0.067 ms | ~0 (variance run-to-run) | OK |
| Disk free | ~92 GB | 91.71 GB | ~0 | OK |
| RAM available | ~7.1 GB | ~7.1 GB | 0 | OK |
| HTTP `/` | 302 (~30 ms) | 302 (~28 ms) | OK | OK |
| HTTP `/login` | 200 (~23 ms) | 200 (~17 ms) | OK | OK |

## Logs Tuning Evidence

| Metric | Sprint 68.20 | Sprint 68.21 | Decision |
|---|---:|---:|---|
| lookback_window | 24h | 24h | Stable |
| fresh_error_like_count | 0 | 0 | OK — no fresh errors in window |
| historical_tail_error_like_count | 15 | 15 | Informational only — does not escalate |
| critical_fresh_count | 0 | 0 | OK |
| unparseable_error_like_count | 51 | 51 | Stable — triggers safe WATCH fallback |
| timestamp_parse_status | partial | partial | Expected on mixed-format tail |
| Logs status | WATCH | WATCH | Unparseable fallback, not historical |
| Overall impact | WATCH via Logs | WATCH via Logs | Not a performance regression |

**Interpretasi:**

- **Historical logs tetap informatif.** Count 15 tidak lagi menjadi penyebab utama WATCH setelah tuning 68.20; console/JSON menampilkannya terpisah dari fresh.
- **Fresh count tetap 0** dalam lookback 24h — tidak ada error baru yang memengaruhi status.
- **Unparseable stack-trace lines (51) masih memicu WATCH** — ini fallback keselamatan yang disengaja agar stack trace tanpa timestamp tidak diabaikan sebagai fresh=0 palsu.
- **Bukan regresi performa.** App/DB/Resources/HTTP semua OK; Q5/Q6 sub-ms; HTTP <100 ms.

## Comparison With Sprint 68.19

| Area | Sprint 68.19 | Sprint 68.21 |
|---|---|---|
| Logs model | single tail count 66 | fresh/historical/unparseable separated |
| Fresh errors | not separated | 0 (within 24h) |
| Historical count | not separated (escalated WATCH) | 15 (informational only) |
| Unparseable | not tracked | 51 (WATCH fallback) |
| Overall | WATCH (historical noise) | WATCH (unparseable fallback only) |
| App/DB/Resources/HTTP | OK | OK |

Tuning 68.20 berhasil memisahkan noise historis dari eskalasi status; WATCH minggu ini bukan karena 66/15 entri historis melainkan karena baris stack trace tanpa timestamp.

## Comparison With Manual Evidence 68.15–68.16

| Area | Manual Evidence | Command Evidence 68.21 | Decision |
|---|---|---|---|
| App health | OK | OK | Match |
| DB size/counts | 18 MB, 32/26/17/25 | 17.95 MB, 32/26/17/25 | Match (rounding) |
| Q5/Q6 | sub-ms (0.07–0.22 ms manual) | sub-ms (~0.03–0.07 ms command) | OK |
| HTTP basic | / 302, /login 200 | / 302 (~28 ms), /login 200 (~17 ms) | Match |
| Logs | no fresh critical | WATCH — unparseable stack traces, fresh=0 | Command stricter; no fresh errors |

## Comparison With Local Stress Baseline

| Area | Local Stress Closure | Pilot 68.21 | Decision |
|---|---|---|---|
| Patients | 250,000 | 32 | Not comparable |
| Payments | 500,400 | 25 | Not comparable |
| DB size | ~2,059 MB | 17.95 MB | Pilot health check only |
| Q5 | ~80 ms | ~0.03 ms | Pilot far below WATCH threshold |
| Q6 | ~118 ms | ~0.05 ms | Pilot far below WATCH threshold |
| Owner HTTP | ~60 ms avg | N/A (basic /login only) | Stress benchmark deferred on pilot |

Pilot volume sangat kecil dibanding stress lokal — snapshot ini adalah **health monitoring operasional**, bukan bukti kapasitas.

## Final Decision

| Level | Decision | Reasoning |
|---|---|---|
| Command overall status | **WATCH** | Logs `unparseable_error_like_count=51` dengan `timestamp_parse_status=partial` |
| Operational performance | **OK** | App/DB/Resources/HTTP OK; no drift; sub-ms SQL; HTTP <100 ms |
| Deploy / optimization | **No action** | Tuning 68.20 verified; WATCH is intentional safety fallback |

## Recommendations

- Lanjutkan **weekly snapshot** menggunakan `php artisan pilot:performance-snapshot`.
- Gunakan `--json` untuk arsip internal operator (jangan commit raw output ke repo).
- **`--fail-on-watch`** akan exit 1 pada kondisi saat ini — gunakan hanya jika automation alerting sudah disetujui dan operator memahami unparseable WATCH.
- **Sprint 68.22 yang direkomendasikan (utama):** Pilot Performance Snapshot Weekly Evidence Review 2 After Logs Tuning — ulang command minggu depan, bandingkan drift fresh/unparseable.
- **Alternatif Sprint 68.22:** Pilot Snapshot Stack Trace Noise Reduction — hanya jika WATCH dari unparseable stack trace membingungkan operator; tidak urgent.

## What Was Not Done

- No deploy.
- No migration.
- No cron/systemd.
- No alert integration.
- No dashboard UI.
- No monitoring DB table.
- No raw log copy.
- No DB dump.
- No PII exposure.
- No `--output` file left on VPS.

## Commands Run

```bash
# Local branch setup & safety
cd ~/Projects/new_lab_app
git switch feature/sprint-68-21-pilot-performance-snapshot-weekly-evidence-after-logs-tuning
php artisan about
php artisan list | grep pilot:performance-snapshot
graphify update .

# VPS preflight (read-only)
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && hostname && date -Is && git rev-parse --short HEAD && git describe --tags --exact-match HEAD && php artisan about && php artisan list | grep pilot:performance-snapshot'

# VPS evidence collection (read-only)
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot --json | php -r "json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); echo \"JSON_OK\\n\";"'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot --json'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot --markdown | head -n 100'

# Exit behavior checks (temporary /tmp, removed immediately)
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && set +e && php artisan pilot:performance-snapshot --fail-on-watch >/tmp/pilot-snapshot-68-21-exit-check.txt 2>&1; echo EXIT_CODE=$?; rm -f /tmp/pilot-snapshot-68-21-exit-check.txt'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot --since=7d --json | php -r "json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); echo \"JSON_7D_OK\\n\";"'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && set +e && php artisan pilot:performance-snapshot --since=bad --json >/tmp/pilot-snapshot-bad-since.txt 2>&1; echo BAD_SINCE_EXIT=$?; rm -f /tmp/pilot-snapshot-bad-since.txt'

# Local doc commit checks
git diff --check
graphify update .
```

## Safety Confirmation

- No deploy unless code fix was required — **no code fix required**.
- No VPS write operation except temporary `/tmp` exit-check files (used and removed).
- No migration.
- No destructive DB command.
- No business logic changed.
- No `.env`/backup/SSH key/DB dump/log/generated report committed.
- No real PII/KTP/NIK exposed.
- SSH was read-only command execution.
- Evidence was summarized, not raw sensitive output.

## Final Status

DONE / COMMITTED / PUSHED / PR MERGED / GO-TAGGED / NO DEPLOY
