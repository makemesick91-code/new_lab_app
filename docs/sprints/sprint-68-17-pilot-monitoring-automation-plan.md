# Sprint 68.17 — Pilot Monitoring Automation Plan

## Executive Summary

- Sprint 68.15 dan 68.16 menunjukkan performa pilot **stabil** — aplikasi sehat (`APP_ENV=pilot`, debug OFF, maintenance OFF), layanan aktif, sumber daya VPS longgar (~92 GB disk, ~7.1 GB RAM, load ~0.00), database kecil (18 MB, 25 pembayaran), Q5/Q6 sub-ms, HTTP dasar OK (`/` → 302, `/login` → 200).
- Monitoring saat ini **manual dan read-only** — operator menjalankan checklist Sprint 68.14 via SSH, mengumpulkan bukti, lalu mendokumentasikan sprint evidence.
- Otomasi **opsional tetapi berguna** untuk mengurangi repetisi perintah SSH mingguan, menstandarkan format output, dan mempercepat klasifikasi OK/WATCH/INVESTIGATE/FIX.
- Sprint 68.17 **hanya merencanakan** otomasi — **tidak mengimplementasikan** command, cron, alert, dashboard, migrasi, atau agent monitoring.
- **Tidak ada deploy** di Sprint 68.17.
- MVP yang direkomendasikan: Laravel Artisan command read-only `php artisan pilot:performance-snapshot` — eksekusi manual dulu, tanpa PII, tanpa raw log.
- Checklist manual mingguan Sprint 68.14 **tetap dipertahankan** sebagai fallback jika otomasi gagal atau belum di-deploy.
- Implementasi di masa depan **memerlukan persetujuan deploy** terpisah setelah merge/GO tag.

## Deploy Decision

| Item | Decision |
|---|---|
| Deploy needed now | No |
| SSH needed now | No |
| Migration/index needed now | No |
| Runtime code change now | No |
| Automation implemented now | No |
| Future deploy needed? | Yes, only if automation is implemented |

## Background Evidence

| Sprint | Evidence | Decision |
|---|---|---|
| 68.14 | Monitoring plan/runbook | No deploy |
| 68.15 | First VPS evidence pack — app OK, services OK, resources OK, DB 18 MB / 25 payments, Q5 0.073 ms, Q6 0.220 ms, HTTP OK | OK |
| 68.16 | Weekly evidence review — no material drift vs 68.15, Q5 0.071 ms, Q6 0.083 ms | OK, no drift |
| Local stress (68.8–68.13) | 250k patients, 500k payments, Owner Dashboard ~60 ms avg, Q5 ~80 ms, Q6 ~118 ms | OK baseline, not pilot-comparable |

**Sumber bukti:** `docs/sprints/sprint-68-14-pilot-performance-monitoring-plan.md`, `docs/sprints/sprint-68-15-pilot-performance-monitoring-evidence-pack.md`, `docs/sprints/sprint-68-16-pilot-performance-monitoring-weekly-evidence-review.md`.

## Problem Statement

Checklist monitoring manual Sprint 68.14 **terbukti efektif** — dua sprint evidence berturut-turut (68.15, 68.16) menghasilkan keputusan OK tanpa deploy. Namun prosesnya **repetitif**:

- Operator harus SSH ke VPS setiap minggu.
- Perintah yang sama (`artisan about`, `df`, `free`, `systemctl`, query SQL, `curl`) diulang manual.
- Format bukti bervariasi tergantung operator.
- Klasifikasi threshold OK/WATCH/INVESTIGATE/FIX dilakukan manual.

Otomasi dapat **menstandarkan** pengumpulan bukti dan mengurangi human error, tetapi harus dirancang dengan hati-hati:

- **Tidak** mengekspos PII (KTP/NIK, nama pasien, baris data mentah).
- **Tidak** menjalankan perintah destruktif (`migrate:fresh`, `db:wipe`, restart layanan tanpa SOP).
- **Tidak** menghasilkan alert berisik pada status OK.
- **Tidak** menggantikan review manusia sepenuhnya — operator tetap menilai konteks bisnis.

## Automation Goals

- Menstandarkan output monitoring mingguan agar konsisten dengan template Sprint 68.14–68.16.
- Mengurangi repetisi perintah SSH manual untuk operator.
- Menjaga semua pemeriksaan **read-only** — tidak ada write DB, tidak ada perubahan konfigurasi.
- Mengklasifikasikan hasil sebagai **OK / WATCH / INVESTIGATE / FIX** sesuai ambang resmi Sprint 68.12–68.14.
- Melindungi privasi — hanya aggregate counts, timings, dan status layanan.
- Menghasilkan bukti yang dapat dilampirkan ke sprint evidence report di masa depan.
- Menyediakan fondasi untuk alerting terbatas di fase berikutnya (hanya INVESTIGATE/FIX).
- Memungkinkan perbandingan tren (delta minggu-ke-minggu) tanpa menyimpan raw log.

## Non-Goals

- **Tidak ada deploy** di Sprint 68.17.
- **Tidak ada** automatic remediation (restart service, buat index, optimisasi query).
- **Tidak ada** auto index creation atau migration.
- **Tidak ada** auto restart/reload nginx/php-fpm/PostgreSQL.
- **Tidak ada** DB write di MVP kecuali secara eksplisit disetujui di fase DB table.
- **Tidak ada** raw log collection atau penyimpanan log ke repo.
- **Tidak ada** export data tingkat pasien.
- **Tidak ada** authenticated page benchmark sampai mekanisme aman tersedia (`stress:benchmark-rme-pages` saat ini hanya `APP_ENV=stress`).
- **Tidak menggantikan** review manusia sepenuhnya — operator tetap membaca ringkasan dan memutuskan sprint berikutnya.
- **Tidak menginstall** Netdata/Prometheus/node exporter di sprint ini.

## Recommended MVP Architecture

**Rekomendasi utama:** Laravel Artisan command read-only.

```bash
php artisan pilot:performance-snapshot
```

### MVP behavior (fase implementasi berikutnya)

| Aspek | Perilaku |
|---|---|
| Mode | Read-only checks only |
| Output | Console table + optional markdown/JSON summary |
| PII | Tidak ada — aggregate counts dan timings saja |
| Raw logs | Tidak disimpan |
| DB schema | Tidak diubah |
| Alerting | Tidak ada di fase 1 |
| Eksekusi | Manual dulu — operator atau cron di fase 2 |
| Penyimpanan | Opsional `storage/app/monitoring/` (tidak di-commit ke repo) |

### Mengapa Laravel Artisan lebih baik daripada shell-only

| Keuntungan | Penjelasan |
|---|---|
| Integrasi config | Membaca `APP_ENV`, koneksi DB, branch context dari Laravel — tanpa menduplikasi `.env` di shell |
| Testability | Pest feature tests dapat memverifikasi threshold classification dan env guard |
| Threshold logic | Satu service class untuk klasifikasi OK/WATCH/INVESTIGATE/FIX — konsisten dengan sprint docs |
| Future dashboard | Output JSON dapat dipakai dashboard Owner tanpa refactor besar |
| Future alerting | Exit code + `--notify` dapat ditambahkan tanpa mengubah inti command |
| Safety guard | Env guard (`pilot`, `local`, `stress`, `testing`) dapat di-enforce di application layer |

## Alternative Architecture Options

| Option | Pros | Cons | Recommendation |
|---|---|---|---|
| Laravel Artisan command | Terintegrasi, testable, pakai app config | Perlu deploy app code | **Recommended MVP** |
| Shell script + cron | Sederhana di server | Sulit di-test, risiko secret/log leak | Phase 2 supplement only |
| systemd timer | Scheduling robust | Kompleksitas VPS ops | Phase 2 scheduling |
| External uptime monitor | HTTP check mudah (UptimeRobot, etc.) | Tidak bisa inspeksi DB/app internal | **Supplement only** |
| Netdata/Prometheus/node exporter | Monitoring resource powerful | Install/ops overhead, security review | Future optional (Phase 5) |
| Manual weekly checklist | Paling aman, sudah terbukti | Repetitif | **Keep as fallback** |

## Proposed Command Design

### Command

```bash
php artisan pilot:performance-snapshot
```

### Future options (tidak diimplementasikan di Sprint 68.17)

```bash
php artisan pilot:performance-snapshot --json
php artisan pilot:performance-snapshot --markdown
php artisan pilot:performance-snapshot --since=24h
php artisan pilot:performance-snapshot --no-db
php artisan pilot:performance-snapshot --no-system
php artisan pilot:performance-snapshot --fail-on-watch
php artisan pilot:performance-snapshot --notify
php artisan pilot:performance-snapshot --output=storage/app/monitoring/snapshot-YYYY-MM-DD.json
```

### Environment guard

| Environment | Default behavior |
|---|---|
| `pilot` | Allowed |
| `local` | Allowed |
| `stress` | Allowed |
| `testing` | Allowed |
| `production` (jika berbeda dari pilot) | **Refuse** — require `--force-production` jika pernah diperlukan |
| Unknown | **Refuse** dengan exit code 10 |

### Exit codes

| Exit Code | Meaning |
|---:|---|
| 0 | OK |
| 1 | WATCH |
| 2 | INVESTIGATE |
| 3 | FIX |
| 10 | Unsafe environment/config |

### Proposed internal structure (future implementation)

```text
app/Console/Commands/PilotPerformanceSnapshotCommand.php
app/Modules/Reporting/Services/PilotPerformanceSnapshotService.php
  ├── collectAppHealth()
  ├── collectServiceHealth()      # optional --no-system
  ├── collectResourceHealth()     # optional --no-system
  ├── collectDatabaseMetrics()    # optional --no-db
  ├── collectSqlBenchmarks()      # Q5, Q6 via EXPLAIN ANALYZE
  ├── collectHttpSmoke()          # / and /login only
  ├── collectLogSummary()         # count only, no raw lines
  └── classifyOverallStatus()
```

**Catatan Q5/Q6:** Gunakan query yang sama dengan Sprint 68.14 runbook — catat hanya execution time dari `EXPLAIN ANALYZE`, bukan baris data:

```sql
-- Q5 Owner aggregate
EXPLAIN (ANALYZE, BUFFERS)
SELECT COALESCE(SUM(amount), 0)
FROM trx_rme_payments
WHERE branch_id = :branch_id
  AND paid_at >= DATE :from_date
  AND paid_at < DATE :to_date;

-- Q6 Daily trend
EXPLAIN (ANALYZE, BUFFERS)
SELECT DATE(paid_at) AS day, COALESCE(SUM(amount), 0)
FROM trx_rme_payments
WHERE branch_id = :branch_id
  AND paid_at >= DATE :from_date
  AND paid_at < DATE :to_date
GROUP BY DATE(paid_at)
ORDER BY day;
```

Branch ID dan date window harus di-resolve dari konfigurasi operasional (bukan dari request user) — misalnya branch pilot aktif + window tahun berjalan.

## Metrics to Capture

| Category | Metric | Source | Threshold |
|---|---|---|---|
| App | APP_ENV | `config('app.env')` | `pilot` expected on VPS |
| App | Debug mode | `config('app.debug')` | OFF on pilot |
| App | Maintenance mode | `app()->isDownForMaintenance()` | OFF |
| App | Laravel version | `Application::VERSION` | informational |
| App | PHP version | `PHP_VERSION` | informational |
| App | Cache status | `artisan about` equivalent | informational |
| Service | php-fpm active | `systemctl is-active` or doc input | active |
| Service | nginx active | `systemctl is-active` | active |
| Service | postgresql active | `systemctl is-active` | active |
| Resource | Disk free | `df` / `disk_free_space()` | <20 GB WATCH, <10 GB FIX |
| Resource | Memory available | `free` / `/proc/meminfo` | document trend |
| Resource | Load average | `sys_getloadavg()` / `uptime` | WATCH if sustained high |
| Resource | Uptime | `uptime` | informational |
| DB | Database size | `pg_database_size()` | trend |
| DB | Patients count | `COUNT(*)` on `mst_patients` | trend |
| DB | Visits count | `COUNT(*)` on `trx_clinic_visits` | trend |
| DB | Invoices count | `COUNT(*)` on `trx_rme_invoices` | trend |
| DB | Payments count | `COUNT(*)` on `trx_rme_payments` | >10k → closer weekly review |
| DB | Payment index valid/ready/size | `pg_index` / `pg_indexes` | valid + ready |
| DB | Connections by state | `pg_stat_activity` | trend |
| DB | Long-running queries | `pg_stat_activity` WHERE state != idle | none expected on pilot |
| DB | Table growth snapshot | `pg_stat_user_tables` | dead tuples trend |
| SQL | Q5 runtime | EXPLAIN ANALYZE aggregate | see SQL thresholds |
| SQL | Q6 runtime | EXPLAIN ANALYZE daily trend | see SQL thresholds |
| HTTP | `/` response | HTTP client / curl | 302 expected |
| HTTP | `/login` response | HTTP client / curl | 200 expected |
| HTTP | Authenticated routes | deferred | only when safe mechanism exists |
| Logs | ERROR/CRITICAL count | Laravel log scan (count only) | trend, no raw storage |
| Logs | SQLSTATE count | pattern count in log tail | trend |
| Logs | Timeout count | pattern count in log tail | trend |
| Release | Git HEAD / tag | optional read-only `git` if available | informational |

## Threshold Classification

Gunakan ambang resmi Sprint 68.12–68.14.

### SQL

| Runtime | Status | Action |
|---:|---|---|
| <100 ms | OK | No action |
| 100–300 ms | WATCH | Monitor |
| 300–500 ms | WATCH / investigate | Review frequency/impact |
| 500 ms–1s | INVESTIGATE | Open investigation sprint |
| >1s | FIX | Optimization sprint required |

### HTTP

| Runtime | Status | Action |
|---:|---|---|
| <100 ms avg | OK | No action |
| 100–300 ms avg | OK/WATCH | Monitor |
| 300–500 ms avg | WATCH | Review |
| 500 ms–1s avg | INVESTIGATE | Investigation sprint |
| >1s avg or p95 | FIX | Optimization required |

### Capacity

| Metric | OK | WATCH | INVESTIGATE/FIX |
|---|---:|---:|---:|
| Payments | <10k | 10k–1M | >1M + user-visible slowdown |
| Disk free | >20 GB | 10–20 GB | <10 GB |
| Owner Dashboard avg | <300 ms | 300–500 ms | >500 ms |
| Owner Dashboard p95 | <500 ms | 500 ms–1s | >1s |
| Q5/Q6 SQL | <300 ms | 300–500 ms | >500 ms |

### Capacity / optimization triggers (human decision)

Buka sprint optimisasi hanya jika:

- Owner Dashboard HTTP avg >300 ms **konsisten**.
- Owner Dashboard HTTP avg >500 ms pada pilot/VPS.
- Owner Dashboard p95 >1s.
- Q5/Q6 SQL >500 ms **konsisten**.
- Payment rows >1M **plus** keluhan slowness user-visible.
- Payment rows >10k → tingkatkan frekuensi weekly evidence review.
- Bukti pilot/VPS **materially slower** dari baseline stress lokal.
- Keluhan pengguna tentang kelambatan.

## Output Format

### MVP console summary

```text
Pilot Performance Snapshot
Checked at: 2026-07-01T12:00:00+08:00
Environment: pilot
Overall status: OK

App:        OK
Services:   OK
Resources:  OK
Database:   OK
Q5/Q6:      OK
HTTP:       OK
Logs:       OK
```

### Markdown output (`--markdown` or default file)

```markdown
# Pilot Performance Snapshot — 2026-07-01

| Area | Metric | Result | Status |
|---|---|---:|---|
| App | APP_ENV | pilot | OK |
| App | Debug | OFF | OK |
| DB | Size | 18 MB | OK |
| DB | Payments | 25 | OK |
| SQL | Q5 | 0.071 ms | OK |
| SQL | Q6 | 0.083 ms | OK |
| HTTP | / | 302 | OK |
| HTTP | /login | 200 | OK |
| Logs | ERROR count (24h) | 0 | OK |
```

### JSON output (`--json`)

```json
{
  "checked_at": "2026-07-01T12:00:00+08:00",
  "environment": "pilot",
  "overall_status": "OK",
  "exit_code": 0,
  "metrics": {
    "app": { "env": "pilot", "debug": false, "maintenance": false, "status": "OK" },
    "services": { "php_fpm": "active", "nginx": "active", "postgresql": "active", "status": "OK" },
    "resources": { "disk_free_gb": 92, "memory_available_gb": 7.1, "load_avg": [0.0, 0.0, 0.0], "status": "OK" },
    "database": { "size_mb": 18, "patients": 32, "visits": 26, "invoices": 17, "payments": 25, "status": "OK" },
    "sql": { "q5_ms": 0.071, "q6_ms": 0.083, "status": "OK" },
    "http": { "root_code": 302, "login_code": 200, "status": "OK" },
    "logs": { "error_count_24h": 0, "sqlstate_count_24h": 0, "status": "OK" }
  }
}
```

**Aturan output:** Tidak menyimpan raw logs, baris pasien, KTP/NIK, atau credential.

## Data Storage and Retention Plan

### Phase 1 — Manual snapshot (MVP implementation)

| Aspek | Rencana |
|---|---|
| DB table | Tidak ada |
| Penyimpanan | Opsional file JSON/markdown di `storage/app/monitoring/` |
| Repo | Tidak di-commit — operator salin ringkasan ke sprint doc |
| Retention file | 30 hari — cleanup manual atau command `--prune` di fase 2 |
| Raw logs | Tidak disimpan |

### Phase 2 — Scheduled snapshot

| Aspek | Rencana |
|---|---|
| Penyimpanan | `storage/app/monitoring/YYYY-MM-DD/` |
| Retention | 30–90 hari |
| Cleanup | Artisan `pilot:performance-snapshot --prune-days=30` atau cron cleanup |
| DB table | Opsional — lihat Phase 2b |

### Phase 2b — Optional DB table (explicit approval required)

| Aspek | Rencana |
|---|---|
| Table | `monitoring_snapshots` (aggregate metrics JSON column) |
| Retention | 90 hari |
| Pruning | Scheduled prune command |
| PII | Tidak ada — hanya angka agregat |

### Phase 3 — Dashboard + trends

| Aspek | Rencana |
|---|---|
| UI | Owner/Super Admin dashboard |
| Data | Read from `monitoring_snapshots` or file archive |
| Retention display | Monthly trend charts |
| Alert history | Log alert events without PII |

## Privacy and Security Rules

| Rule | Detail |
|---|---|
| No DB_PASSWORD | Jangan pernah output credential database |
| No `.env` dump | Jangan baca atau cetak isi `.env` |
| No raw patient rows | Hanya `COUNT(*)` aggregate |
| No KTP/NIK | Tidak pernah di snapshot atau alert |
| No patient phone/email | Tidak termasuk dalam metrik |
| No raw logs | Hanya count kategori error — bukan isi baris log |
| Sanitize query previews | Q5/Q6 hanya timing — bukan hasil query |
| Aggregate only | Reports berisi counts dan timings saja |
| Alert content | Alert tidak boleh menyertakan PII |
| Access control | Command hanya untuk operator server; dashboard future = Owner/Super Admin permission |
| No commit artifacts | File snapshot di `storage/app/monitoring/` tidak di-commit ke git |
| SSH keys / backups | Tidak pernah disertakan dalam output monitoring |

## Alerting Strategy

| Phase | Behavior |
|---|---|
| Phase 1 (MVP) | **No automatic alert** — operator membaca console output |
| Phase 2 | Exit code non-zero + optional file marker di `storage/app/monitoring/ALERT` |
| Phase 3 | Email / Telegram / WhatsApp — **hanya INVESTIGATE atau FIX**, bukan setiap OK |
| Noise control | Require 2 consecutive WATCH sebelum alert; FIX langsung alert |
| Secrets | Token webhook di `.env` — **tidak di-commit** |

## Future Phased Roadmap

### Phase 1 — Artisan Snapshot Command (recommended Sprint 68.18)

- Implement `php artisan pilot:performance-snapshot`.
- Read-only checks: app, services (optional), resources (optional), DB, Q5/Q6, HTTP smoke, log count.
- Console + `--json` + `--markdown` output.
- Env guard (`pilot`, `local`, `stress`, `testing`).
- Exit codes 0–3 + 10.
- Pest feature tests untuk threshold classification dan env guard.
- Manual execution only — **no cron**.
- **Deploy required** setelah merge/GO dan persetujuan owner.

### Phase 2 — Scheduled Snapshot

- Cron (`0 6 * * 1` weekly) atau systemd timer.
- Auto-write ke `storage/app/monitoring/`.
- Retention cleanup command.
- **Deploy + VPS ops required** (crontab atau systemd unit).

### Phase 3 — Alerting

- `--notify` flag atau scheduled notify on INVESTIGATE/FIX.
- Email/Telegram/WhatsApp integration.
- Secrets di `.env`.
- **Deploy + secret management required**.

### Phase 4 — Dashboard

- Route + policy (`view_owner_dashboard` or dedicated permission).
- Blade dashboard dengan trend charts (aggregate only).
- **Deploy + UI tests required**.

### Phase 5 — Infrastructure Monitoring (optional)

- Netdata / Prometheus / node exporter.
- VPS-level resource graphs.
- **VPS ops + security review** — no app code deploy strictly required.

### Manual fallback (always available)

- Sprint 68.14 checklist tetap valid.
- Operator dapat skip otomasi dan jalankan runbook manual kapan saja.

## Implementation Safety Rules for Future Sprint

Implementasi di masa depan **wajib**:

1. Mulai dengan command read-only — tidak ada write DB di MVP.
2. Enforce env guard — refuse unknown/production tanpa flag eksplisit.
3. Tidak menjalankan `migrate:fresh`, `db:wipe`, `DROP`, `TRUNCATE`, atau restore.
4. Tidak menyimpan raw logs atau PII dalam output.
5. Tidak restart/reload service dari dalam command.
6. Tidak mengubah nginx/php-fpm/PostgreSQL config tanpa sprint terpisah.
7. Include Pest tests: happy path, env guard, threshold classification, branch-safe Q5/Q6.
8. Require deploy approval sebelum menjalankan di VPS pilot.
9. Dokumentasikan rollback plan sebelum deploy.
10. Validasi output pertama dengan checklist manual Sprint 68.14 side-by-side.

## Future Deploy Requirements

| Future Feature | Deploy Needed | VPS Ops Needed | Notes |
|---|---|---|---|
| Artisan command only | Yes | No/low | `git pull` + no migration |
| Cron/systemd schedule | Yes | Yes | crontab or systemd timer on VPS |
| JSON/markdown file storage | Yes | Maybe | `storage/app/monitoring/` permission review |
| Monitoring DB table | Yes | Yes | additive migration only |
| Alert integration | Yes | Yes | secrets in `.env`, not repo |
| Dashboard UI | Yes | No/low | route/policy/permission |
| Netdata/Prometheus | No app deploy | Yes | infra ops, firewall review |
| PostgreSQL extension | No app deploy | Yes | explicit approval |

## Risk Register

| Risk | Impact | Mitigation |
|---|---|---|
| Alert noise | Operator mengabaikan alert | Alert hanya INVESTIGATE/FIX; require consecutive evidence |
| PII leakage | Privacy incident | Aggregate-only output; code review + test assertions |
| Command overhead | Beban app/DB saat jam sibuk | Jalankan weekly off-hours; `--no-db` option |
| False positive | Sprint optimisasi tidak perlu | Require repeated evidence (2+ weeks WATCH) |
| Secret exposure | Credential leak | Never print `.env`; webhook tokens in env only |
| Cron failure | Bukti mingguan hilang | Manual fallback checklist; monitor cron exit code |
| Monitoring code bug | Keputusan salah | Tests + side-by-side manual validation pertama |
| EXPLAIN ANALYZE load | Query plan cache invalidation | Run off-hours; acceptable at pilot scale |
| Service check permission | Command gagal di non-root | Graceful degrade — mark service check as "manual required" |
| Graphify/vendor noise | N/A for monitoring | Command tidak bergantung pada graphify |

## Rollback Plan for Future Implementation

Jika otomasi menyebabkan masalah setelah deploy:

1. **Disable cron/systemd timer** — hentikan eksekusi terjadwal.
2. **Stop notification integration** — hapus webhook atau disable `--notify`.
3. **Kembali ke manual checklist** Sprint 68.14 — sudah terbukti di 68.15/68.16.
4. **Revert application code** — `git revert` commit command jika perlu; tidak ada data loss karena read-only.
5. **Hapus file snapshot** di `storage/app/monitoring/` jika perlu ruang disk.
6. **Drop monitoring table** hanya jika Phase 2b sudah di-deploy dan disetujui — migration rollback terpisah.
7. **Dokumentasikan insiden** dalam sprint evidence report.

Tidak ada data loss operasional yang diharapkan karena monitoring bersifat read-only.

## Recommended Next Sprint

### Primary recommendation

**Sprint 68.18 — Pilot Performance Snapshot Command Foundation**

| Aspek | Scope |
|---|---|
| Command | `php artisan pilot:performance-snapshot` |
| Mode | Read-only only |
| Output | Console + `--json` + `--markdown` |
| Guard | Env guard + exit codes |
| Tests | Pest feature tests |
| Cron | No |
| Alerts | No |
| Deploy | Required only after merge/GO and explicit owner approval |

### Alternatives

| Option | When to choose |
|---|---|
| Sprint 68.18 — Pilot Performance Monitoring Weekly Evidence Review 3 | Owner ingin bukti manual lagi sebelum kode |
| Sprint 68.18 — Monitoring Automation Approval Checklist | Owner ingin sign-off formal sebelum implementasi |

## Comparison: Manual vs Automated

| Aspek | Manual (Sprint 68.14) | Automated (proposed MVP) |
|---|---|---|
| Operator effort | Tinggi — banyak perintah SSH | Rendah — satu command |
| Format konsistensi | Bervariasi | Terstandar (JSON/markdown) |
| Threshold classification | Manual | Otomatis dengan exit code |
| PII risk | Rendah jika operator disiplin | Rendah jika command di-review + tested |
| Deploy required | No | Yes (future) |
| Fallback | N/A | Manual checklist tetap tersedia |
| Proven | Yes (68.15, 68.16) | Not yet — needs validation sprint |
| Authenticated HTTP bench | Deferred | Deferred (same constraint) |

## What Was Not Done

- No deploy.
- No SSH/VPS evidence collection.
- No Laravel command implementation.
- No cron/systemd timer.
- No alert integration (email/Telegram/WhatsApp).
- No dashboard UI.
- No migration/index.
- No DB writes.
- No monitoring agent installation (Netdata/Prometheus).
- No nginx/php-fpm/PostgreSQL config change.
- No runtime business logic change.
- No auth/permission/branch isolation change.

## Commands Run

```bash
cd ~/Projects/new_lab_app
git fetch origin
git switch feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git pull --ff-only origin feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git switch -c feature/sprint-68-17-pilot-monitoring-automation-plan
pwd
git status --short
git branch --show-current
git log --oneline -5
php artisan about
graphify update .
ls docs/sprints | grep -E "68-(14|15|16)"
```

## Safety Confirmation

- No deploy.
- No VPS SSH/write operation.
- No migration.
- No destructive DB command.
- No business logic changed.
- No `.env`/backup/SSH key/DB dump/log committed.
- No real PII/KTP/NIK exposed.
- Automation was planned only, not implemented.

## Final Status

DONE / COMMITTED / PUSHED / PR MERGED / GO-TAGGED / NO DEPLOY
