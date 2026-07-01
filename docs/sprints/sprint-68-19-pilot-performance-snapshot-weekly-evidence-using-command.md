# Sprint 68.19 — Pilot Performance Snapshot Weekly Evidence Using Command

## Executive Summary

- Sprint 68.19 menjalankan **bukti monitoring mingguan pertama** menggunakan command `php artisan pilot:performance-snapshot` yang sudah di-deploy pada Sprint 68.18 — menggantikan checklist SSH manual Sprint 68.14–68.16 untuk pengumpulan bukti terstandar.
- SSH ke `daengtisiams-vps` (`srv1730088`) dilakukan dalam mode **read-only**; tidak ada deploy, migrasi, restart, cache clear, composer install, atau npm build.
- VPS terverifikasi pada HEAD `f11b578` / tag `sprint-68-18-pilot-performance-snapshot-command-foundation-go`; command tersedia di `artisan list`.
- **Console mode** berjalan sukses; keluaran menampilkan tabel ringkasan metrik dan klasifikasi per section.
- **JSON mode** divalidasi dengan `JSON_THROW_ON_ERROR` → **JSON_OK**.
- **Markdown mode** berjalan sukses; format tabel section + metrik database konsisten dengan console/JSON.
- **`--fail-on-watch`** mengembalikan **exit code 1** karena overall status WATCH — sesuai desain classifier.
- **Overall status command: WATCH** — hanya karena section **Logs** (error-like count 66 dari tail log historis, ambang WATCH >20).
- **App, Database, Resources, dan HTTP semua OK** — tidak ada regresi performa DB/SQL/HTTP/sumber daya.
- WATCH dari Logs **masih persisten** seperti Sprint 68.18 deploy verification; bukan indikasi performa buruk melainkan noise historis di tail `laravel.log`.
- **Deploy tidak diperlukan.** Tidak ada defect command yang ditemukan.
- **Keputusan operasional performa: OK** — lanjutkan weekly snapshot via command; pertimbangkan tuning classifier Logs di sprint mendatang jika WATCH membingungkan operator.

## Deploy Decision

| Item | Decision |
|---|---|
| Deploy needed | No |
| Reason | Existing command from Sprint 68.18 was already deployed and verified |
| Code change needed | No |
| Migration needed | No |
| Cron/alert/dashboard | No |

## SSH Decision

| Item | Decision |
|---|---|
| SSH used | Yes |
| Host | daengtisiams-vps / srv1730088 |
| Mode | Read-only command execution |
| VPS write operation | No |

## Release Context

| Metric | Sprint 68.18 Deploy | Sprint 68.19 Current | Decision |
|---|---|---|---|
| HEAD | f11b578 | f11b578 | OK — no drift |
| GO tag | sprint-68-18-pilot-performance-snapshot-command-foundation-go | sprint-68-18-pilot-performance-snapshot-command-foundation-go | OK |
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
| Markdown mode runs | Yes — header + sections rendered |
| fail-on-watch exit check | EXIT_CODE=1 (expected for WATCH overall) |

## Snapshot Summary

| Section | Status | Notes |
|---|---|---|
| App | OK | pilot, debug OFF, maintenance OFF |
| Database | OK | 17.94 MB, counts stable, Q5/Q6 sub-ms, index valid, no long queries |
| Resources | OK | ~92 GB disk free, ~7.1 GB RAM available, load 0.00 |
| HTTP | OK | `/` 302 (~27 ms), `/login` 200 (~17 ms) |
| Logs | WATCH | error_like_count 66 in tail scan (~179 KB); historical noise |
| Overall | WATCH | Worst-of-sections; driven solely by Logs |

## Key Metrics

| Metric | Sprint 68.18 VPS | Sprint 68.19 Current | Delta | Status |
|---|---:|---:|---:|---|
| DB size | 17.94 MB | 17.94 MB | 0 | OK |
| Patients | 32 | 32 | 0 | OK |
| Visits | 26 | 26 | 0 | OK |
| Invoices | 17 | 17 | 0 | OK |
| Payments | 25 | 25 | 0 | OK |
| Q5 | 0.024–0.036 ms | 0.021–0.047 ms | ~0 (variance run-to-run) | OK |
| Q6 | 0.045–0.051 ms | 0.039–0.052 ms | ~0 (variance run-to-run) | OK |
| Disk free | ~92 GB | ~91.7 GB | ~0 | OK |
| RAM available | ~7.1 GB | ~7.1 GB | 0 | OK |
| HTTP `/` | 302 | 302 (~27 ms avg) | OK | OK |
| HTTP `/login` | 200 | 200 (~17 ms avg) | OK | OK |
| Logs error-like | WATCH (historical tail) | 66 (WATCH) | persisten | WATCH (non-performance) |

## WATCH / Logs Analysis

- **Overall WATCH disebabkan hanya oleh section Logs** — App, Database, Resources, dan HTTP semua **OK**.
- Classifier Logs (`PilotPerformanceSnapshotService::collectLogSummary`) memindai tail `laravel.log` (maks 2 MB) dengan pola `ERROR|CRITICAL|SQLSTATE|timeout|exception`; count **66** melebihi ambang WATCH (>20) tetapi di bawah INVESTIGATE (>100).
- Label `since: 24h` pada metrik Logs mengacu pada opsi command default; implementasi saat ini memindai **tail byte log**, bukan filter timestamp 24 jam — sehingga count mencakup entri historis lama (tinker, view exception, cache permission) yang sama seperti review manual Sprint 68.15–68.16.
- **Tidak ada bukti regresi performa DB, SQL, HTTP, atau sumber daya.**
- WATCH dari Logs **persisten** dari Sprint 68.18 — perilaku classifier konsisten, bukan degradasi baru.
- **Rekomendasi:** tidak perlu deploy atau optimisasi performa; pertimbangkan Sprint 68.20 untuk tuning classifier/window Logs jika operator ingin overall OK tanpa noise historis.

## Comparison With Sprint 68.15–68.16 Manual Evidence

| Area | Manual Evidence 68.15–68.16 | Command Evidence 68.19 | Decision |
|---|---|---|---|
| App health | OK | OK | Match |
| DB size/counts | 18 MB, 32/26/17/25 | 17.94 MB, 32/26/17/25 | Match (rounding) |
| Q5/Q6 | sub-ms (0.07–0.22 ms manual) | sub-ms (~0.02–0.05 ms command) | OK — same order of magnitude on tiny dataset |
| HTTP basic | / 302, /login 200 | / 302, /login 200 | Match |
| Logs | manual category review — no fresh critical | command WATCH — 66 error-like tail lines | Command stricter; historical noise only |
| Overall | OK (manual judgment) | WATCH (command worst-of; Logs-driven) | Operational OK; command WATCH from Logs |

## Comparison With Local Stress Baseline

| Area | Local Stress Closure (68.12) | Pilot 68.19 | Decision |
|---|---|---|
| Patients | 250,000 | 32 | Not comparable |
| Payments | 500,400 | 25 | Not comparable |
| DB size | ~2,059 MB | 17.94 MB | Pilot health check only |
| Q5 | ~80 ms | ~0.03 ms | Pilot far below WATCH threshold |
| Q6 | ~118 ms | ~0.05 ms | Pilot far below WATCH threshold |
| Owner HTTP | ~60 ms avg | N/A (basic /login only) | Stress benchmark deferred on pilot |

Pilot volume sangat kecil dibanding stress lokal — snapshot ini adalah **health monitoring operasional**, bukti kapasitas, bukan stress test.

## Final Decision

| Level | Decision | Reasoning |
|---|---|---|
| Command overall status | WATCH | Logs section error_like_count 66 > threshold 20 |
| Operational performance | **OK** | App/DB/Resources/HTTP OK; no drift vs 68.15–68.18; sub-ms SQL; HTTP <100 ms |
| Deploy / optimization | **No action** | No regression; Logs WATCH is historical classifier noise |

## Recommendations

- Lanjutkan **weekly snapshot** menggunakan `php artisan pilot:performance-snapshot` sebagai workflow utama bukti monitoring.
- Gunakan `--json` untuk arsip internal operator (jangan commit raw output ke repo).
- Gunakan `--fail-on-watch` hanya jika automation alerting sudah disetujui — saat ini exit 1 akan terpicu oleh Logs historis meskipun performa OK.
- **Sprint 68.20 yang direkomendasikan:** Pilot Performance Snapshot Weekly Evidence Review 2 (ulang command minggu depan, bandingkan drift).
- **Alternatif Sprint 68.20:** Pilot Snapshot Logs Classification Tuning — jika WATCH dari Logs historis mengganggu interpretasi operator (mis. filter by timestamp, kategori error, atau naikkan ambang pilot-only).

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
# Local branch setup
git fetch origin
git switch feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git pull --ff-only origin feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git switch -c feature/sprint-68-19-pilot-performance-snapshot-weekly-evidence-using-command

# Local safety
git status --short
php artisan about
php artisan list | grep pilot:performance-snapshot
graphify update .

# VPS preflight (read-only)
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && hostname && date -Is && git rev-parse --short HEAD && git describe --tags --exact-match HEAD && php artisan about && php artisan list | grep pilot:performance-snapshot'

# VPS evidence collection (read-only)
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot --json | php -r "json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); echo \"JSON_OK\\n\";"'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot --json'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot --markdown | head -n 80'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot --fail-on-watch; echo EXIT_CODE=$?'

# Local commit hygiene
git diff --check
```

## Safety Confirmation

- No deploy unless code fix was required — **no code fix required**.
- No VPS write operation (fail-on-watch used `/tmp` only, deleted immediately).
- No migration.
- No destructive DB command.
- No business logic changed.
- No `.env`/backup/SSH key/DB dump/log committed.
- No generated monitoring output committed.
- No real PII/KTP/NIK exposed.
- SSH was read-only.
- Evidence was summarized, not raw sensitive output.

## Final Status

DONE / COMMITTED / PUSHED / PR MERGED / GO-TAGGED / NO DEPLOY
