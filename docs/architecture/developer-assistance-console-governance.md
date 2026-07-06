# ENT-7 — Developer Assistance Console Governance

Status: **LOCKED (durable governance)** — 2026-07-06
Sprint: ENT-7 — Developer Assistance Console
Readiness command: `php artisan foundation:developer-console-check` (`--json`, `--strict`)
Governance section: `developer_console_governance` in `architecture:foundation-governance-summary`
Runtime surface: route `developer-console.index` (`GET /dev-console`), permission `view_developer_console`

## Tujuan

Menyediakan konsol diagnostik read-only untuk Super Admin: application/error log,
failed jobs, audit events, ringkasan slow query (NSF-3 pg_stat_statements),
deploy evidence, status izin storage, kesehatan DB/queue/cache/session,
serta disk & backup status — semuanya dengan masking PII/secret.

## Aturan (ENT7-DC001..ENT7-DC010)

| ID | Aturan |
|----|--------|
| ENT7-DC001 | Console strictly read-only — semua route console wajib GET/HEAD; tidak ada mutasi data, driver, atau konfigurasi runtime. |
| ENT7-DC002 | Akses digating permission `view_developer_console`; default hanya Super Admin. Role lain tidak boleh menerima permission ini tanpa persetujuan eksplisit. |
| ENT7-DC003 | Setiap page view console menulis baris audit immutable ke `sys_audit_logs` plus log aplikasi yang membawa request/correlation id OBS-1. |
| ENT7-DC004 | Masking PII/secret wajib pada semua excerpt: digit-run berbentuk KTP/NIK, kredensial, token, authorization header, dan email tidak pernah dirender penuh. |
| ENT7-DC005 | Password, token, session, cookie, API key, kredensial koneksi, dan isi file environment tidak boleh tampil di panel atau ekspor mana pun. |
| ENT7-DC006 | Excerpt dibatasi (line & length bound); kolom payload audit (old/new values) tidak pernah dirender. |
| ENT7-DC007 | Console hanya mengagregasi surface read-only yang sudah ada (readiness/audit/observability); tanpa migrasi, tanpa perubahan driver, tanpa write path baru selain baris auditnya sendiri. |
| ENT7-DC008 | Governance ENT-5 (`queue_retry_governance`) dan ENT-6 (`idempotency_outbox_governance`) tetap wajib GO dan hanya disurface, tidak dilemahkan. |
| ENT7-DC009 | `foundation:developer-console-check` read-only dan privacy-safe: output tidak pernah memuat raw log line, secret, atau identitas pasien. |
| ENT7-DC010 | Panel/ekspor console baru wajib menambah coverage masking, test permission/audit, dan lulus governance check ini sebelum rilis. |

## Integrasi Foundation

- ENT-5 tetap diverifikasi via `foundation:queue-retry-failed-job-check --strict`.
- ENT-6 tetap diverifikasi via `foundation:idempotency-outbox-check --strict`;
  section `queue_retry_governance` dan `idempotency_outbox_governance` tidak berubah.
- Slow query summary memakai layanan NSF-3 (query ternormalisasi, bebas literal/PII).
- Status storage/queue/cache mengikuti STORAGE-1/STATELESS-1/ENT-4 read-only.
- Section `developer_console_governance` bersifat informasional dan tidak
  mengubah `combinedDecision` yang blocking.

## Evidence

- Artefak `developer-console-check.json` wajib pada profil CI dan VPS
  (`release_evidence`), dihasilkan oleh CI gates dan `scripts/deploy-vps.sh`.
- Test: `tests/Feature/Architecture/Ent7DeveloperAssistanceConsoleTest.php`,
  `tests/Feature/Foundation/DeveloperConsoleGovernanceCommandTest.php`,
  `tests/Feature/DeveloperConsole/DeveloperConsoleTest.php`.
