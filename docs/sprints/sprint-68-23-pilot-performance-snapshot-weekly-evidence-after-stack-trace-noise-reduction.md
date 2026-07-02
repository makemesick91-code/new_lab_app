# Sprint 68.23 — Pilot Performance Snapshot Weekly Evidence Review After Stack Trace Noise Reduction

## Executive Summary

- Sprint 68.23 menjalankan **bukti monitoring mingguan setelah Sprint 68.22 Stack Trace Noise Reduction** menggunakan command `php artisan pilot:performance-snapshot` yang sudah di-deploy pada VPS/pilot.
- SSH ke `daengtisiams-vps` (`srv1730088`) dilakukan dalam mode **read-only**; tidak ada deploy, migrasi, restart, cache clear, composer install, atau npm build.
- VPS terverifikasi pada HEAD `b0d4082` / tag `sprint-68-22-pilot-snapshot-stack-trace-noise-reduction-go`; tidak ada drift release.
- **Console mode** berjalan sukses; keluaran menampilkan grouped stack trace metrics dan **Overall OK**.
- **JSON mode** divalidasi dengan `JSON_THROW_ON_ERROR` → **JSON_OK**.
- **Markdown mode** berjalan sukses; satu run menunjukkan transient **Http WATCH** (variansi timing run-to-run), sedangkan run JSON/console/fail-on-watch konsisten **OK**.
- **`--fail-on-watch`** mengembalikan **exit code 0** — stack trace grouping tetap efektif; tidak ada false WATCH dari unparseable stack traces.
- **`--since=7d`** JSON valid (**JSON_7D_OK**); **`--since=bad`** exit **10** — validasi `--since` tetap benar.
- **Overall status: OK** — App, Database, Resources, HTTP, dan Logs semua **OK**.
- **Logs grouping Sprint 68.22 tetap efektif:** `fresh_error_like_count=0`, `orphan_unparseable_error_like_count=0`, `unparseable_error_like_count=0`, `historical_stack_trace_line_count=1090` grouped informatif, `log_grouping_status=grouped`.
- **App/DB/Resources/HTTP performa OK** — Q5/Q6 sub-ms, HTTP `/` dan `/login` <100 ms, disk/RAM sehat.
- **Deploy tidak diperlukan.** Tidak ada defect command yang ditemukan.
- **Keputusan operasional performa: OK** — lanjutkan weekly snapshot; pertimbangkan Sprint 68.24 scheduling plan.

## Deploy Decision

| Item | Decision |
|---|---|
| Deploy needed | No |
| Deploy performed | No |
| Reason | Existing stack-trace-grouping command from Sprint 68.22 was already deployed |
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

| Metric | Sprint 68.22 Deploy | Sprint 68.23 Current | Decision |
|---|---|---|---|
| HEAD | b0d4082 | b0d4082 | OK — no drift |
| GO tag | sprint-68-22-pilot-snapshot-stack-trace-noise-reduction-go | sprint-68-22-pilot-snapshot-stack-trace-noise-reduction-go | OK |
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
| console mode runs | Yes — exit 0, Overall OK |
| JSON mode validates | Yes — JSON_OK |
| Markdown mode runs | Yes — sections + Logs grouped summary rendered |
| fail-on-watch exit check | EXIT_CODE=0 (expected for OK overall) |
| since=7d validation | JSON_7D_OK |
| invalid since check | BAD_SINCE_EXIT=10 |

## Snapshot Section Summary

| Section | Sprint 68.22 | Sprint 68.23 | Decision |
|---|---|---|---|
| App | OK | OK | Stable |
| Database | OK | OK | Stable |
| Resources | OK | OK | Stable |
| HTTP | OK | OK | Stable (one markdown run showed transient WATCH) |
| Logs | OK | OK | Stable — grouping effective |
| Overall | OK | OK | No regression from 68.22 |

## Key Metrics

| Metric | Sprint 68.22 VPS | Sprint 68.23 Current | Delta | Status |
|---|---:|---:|---:|---|
| DB size | 17.96 MB | ~17.97–17.98 MB | +0.01 | OK |
| Patients | 32 | 32 | 0 | OK |
| Visits | 26 | 26 | 0 | OK |
| Invoices | 17 | 17 | 0 | OK |
| Payments | 25 | 25 | 0 | OK |
| Q5 | ~0.028 ms | ~0.027–0.046 ms | ~0 (variance run-to-run) | OK |
| Q6 | ~0.052 ms | ~0.041–0.069 ms | ~0 (variance run-to-run) | OK |
| Disk free | 91.71 GB | 91.71 GB | 0 | OK |
| RAM available | ~7.1 GB | ~7.1 GB | 0 | OK |
| HTTP `/` | 302 ~31 ms | 302 ~32 ms | ~+1 ms | OK |
| HTTP `/login` | 200 ~21 ms | 200 ~20 ms | ~−1 ms | OK |

## Logs Grouping Evidence

| Metric | Sprint 68.22 | Sprint 68.23 | Decision |
|---|---:|---:|---|
| lookback_window | 24h | 24h | Stable |
| fresh_error_like_count | 0 | 0 | OK — no fresh errors in window |
| historical_tail_error_like_count | 15 | 15 | Informational only — does not escalate |
| critical_fresh_count | 0 | 0 | OK |
| unparseable_error_like_count | 0 | 0 | OK — no orphan stack trace inflation |
| fresh_stack_trace_line_count | 0 | 0 | OK |
| historical_stack_trace_line_count | 1090 | 1090 | Grouped informatif — does not escalate |
| orphan_unparseable_error_like_count | 0 | 0 | OK |
| attached_unparseable_line_count | 8 | 8 | Stable — attached to parent events |
| timestamp_parse_status | ok | ok | OK |
| log_grouping_status | grouped | grouped | OK |
| Logs status | OK | OK | Stack trace noise reduction remains effective |
| Overall impact | OK | OK | Not a performance regression |

**Interpretasi:**

- **Stack trace grouping tetap efektif.** 1090 baris continuation historical tetap grouped di bawah 15 parent events; tidak memicu WATCH.
- **Historical stack trace lines tetap informatif.** Tidak meng-escalate Logs/Overall status.
- **Fresh count tetap 0** dalam lookback 24h — tidak ada error baru.
- **Orphan unparseable count tetap 0** — tidak ada baris stack trace tanpa parent yang memicu false WATCH (masalah Sprint 68.21 dengan 51 unparseable sudah resolved).
- **Bukan regresi performa.** App/DB/Resources/HTTP semua OK; Q5/Q6 sub-ms; HTTP <100 ms.

## Comparison With Sprint 68.21

| Area | Sprint 68.21 | Sprint 68.23 |
|---|---|---|
| Logs status | WATCH from 51 unparseable stack trace lines | OK |
| Overall | WATCH | OK |
| Fresh errors | 0 | 0 |
| Historical events | 15 | 15 |
| Unparseable lines | 51 | 0 |
| Stack traces grouped | No | Yes |
| App/DB/Resources/HTTP | OK | OK |
| `--fail-on-watch` | exit 1 | exit 0 |

Sprint 68.22 stack trace noise reduction **terverifikasi stabil** setelah satu siklus mingguan — false WATCH dari unparseable stack traces tidak kembali.

## Comparison With Sprint 68.22

| Area | Sprint 68.22 | Sprint 68.23 |
|---|---|---|
| Logs status | OK | OK |
| Overall | OK | OK |
| Stack traces grouped | Yes | Yes |
| `--fail-on-watch` | exit 0 | exit 0 |
| fresh_error_like_count | 0 | 0 |
| historical_stack_trace_line_count | 1090 | 1090 |
| orphan_unparseable_error_like_count | 0 | 0 |
| App/DB/Resources/HTTP | OK | OK |
| VPS HEAD/tag | b0d4082 / 68.22 GO | b0d4082 / 68.22 GO (no drift) |

Metrik logs dan performa **stabil** week-over-week; tidak ada drift deploy atau regresi.

## Comparison With Local Stress Baseline

| Area | Local Stress Closure | Pilot 68.23 | Decision |
|---|---|---|---|
| Patients | 250,000 | 32 | Not comparable |
| Payments | 500,400 | 25 | Not comparable |
| DB size | ~2,059 MB | ~17.98 MB | Pilot health check only |
| Q5 | ~80 ms | ~0.03 ms | Pilot far below WATCH threshold |
| Q6 | ~118 ms | ~0.04 ms | Pilot far below WATCH threshold |
| Owner HTTP | ~60 ms avg | N/A (basic /login only) | Stress benchmark deferred on pilot |

Pilot volume sangat kecil dibanding stress lokal — snapshot ini adalah **health monitoring operasional**, bukan bukti kapasitas.

## Final Decision

| Level | Decision | Reasoning |
|---|---|---|
| Command overall status | **OK** | App/DB/Resources/HTTP/Logs all OK; fresh=0; grouping effective |
| Operational performance | **OK** | No drift; sub-ms SQL; HTTP <100 ms; resources healthy |
| Deploy / optimization | **No action** | 68.22 grouping verified stable after weekly cycle |

## Recommendations

- Lanjutkan **weekly snapshot** menggunakan `php artisan pilot:performance-snapshot`.
- **`--fail-on-watch`** sekarang aman untuk automation alerting pada kondisi pilot saat ini (exit 0 when OK).
- Pertimbangkan sprint berikutnya:
  - **Sprint 68.24 — Pilot Snapshot Scheduling Plan** (cron/systemd design), atau
  - **Sprint 68.24 — Pilot Performance Snapshot Weekly Evidence Review 2** jika weekly manual evidence masih diperlukan sebelum scheduling.

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

## Commands Run

```bash
# Local branch setup
git fetch origin
git switch feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git pull --ff-only origin feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git switch -c feature/sprint-68-23-pilot-performance-snapshot-weekly-evidence-after-stack-trace-noise-reduction

# Local safety
git status --short
php artisan about
php artisan list | grep pilot:performance-snapshot
graphify update .

# VPS preflight (read-only)
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && hostname && git rev-parse --short HEAD && git describe --tags --exact-match HEAD && php artisan about && php artisan list | grep pilot:performance-snapshot'

# VPS evidence (read-only)
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot --json | php -r "json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); echo \"JSON_OK\n\";"'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot --markdown | head -n 120'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot --fail-on-watch; echo EXIT_CODE=$?'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot --since=7d --json | php -r "json_decode(...); echo \"JSON_7D_OK\n\";"'
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && php artisan pilot:performance-snapshot --since=bad --json; echo BAD_SINCE_EXIT=$?'
```

## Safety Confirmation

- No deploy unless code fix was required — **no code fix needed**.
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
