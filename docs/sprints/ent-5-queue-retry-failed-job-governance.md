# ENT-5 — Queue, Retry & Failed Job Governance (Sprint Evidence)

Branch: `feature/ent-5-queue-retry-failed-job-governance`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
GO tag (setelah merge): `ent-5-queue-retry-failed-job-governance-go`

**ENT-5 is implementation-heavy and becomes part of DaengtisiaMS application
rules.** Berbeda dari ENT-1..ENT-4 (baseline lock docs), ENT-5 mengirim kode
runtime nyata: standar retry terpusat, base job + trait, readiness inspector,
governance command baru, dan section governance summary baru.

## Sprint Goal

Mengoperasionalkan fondasi QUEUE-1 sehingga modul masa depan bisa memakai
Laravel queue, retry, dan failed job secara konsisten: observable, enforceable,
testable, terdokumentasi, dan terkunci di foundation rules.

## Runtime Implementation Summary

| Artefak | File |
|---|---|
| Retry/connection/failed-job standard | `config/queue_governance.php` → section `ent5_retry_failed_job` (additive; QUEUE-1 section tidak berubah) |
| Trait defaults | `app/Support/Queue/EnterpriseQueueRetryDefaults.php` |
| Base job class | `app/Support/Queue/EnterpriseQueueJob.php` |
| Readiness inspector (read-only) | `app/Support/Queue/QueueRetryFailedJobReadinessService.php` |
| Governance rule catalog | `app/Services/Foundation/QueueRetryFailedJobGovernanceService.php` |
| Gate command | `app/Console/Commands/FoundationQueueRetryFailedJobCheckCommand.php` → `foundation:queue-retry-failed-job-check` (`--json`, `--strict`, `--fail-on-warning`) |
| Governance summary section | `queue_retry_governance` di `FoundationGovernanceSummaryService` (informational, non-blocking; `queue_governance` QUEUE-1 tidak disentuh) |
| Roadmap | `config/foundation_roadmap.php`: ENT-5 → completed + pointer; rules `queue_retry_failed_job_governance_locked`; ENT-4 `deploy_evidence_commit` |

Tidak ada migrasi (tabel `jobs`/`failed_jobs` sudah ada dari migrasi standar),
tidak ada route/permission baru, tidak ada perubahan workflow RME/payment/
inventory/lab, tidak ada vendor baru (tanpa Horizon/Redis).

## Rule IDs

`ENT5-Q001` koneksi per environment (sync lokal saja) · `ENT5-Q002` failed-job
storage `database-uuids`/`failed_jobs` · `ENT5-Q003` standar tries=3 /
backoff=[10,60,180] / timeout=120 (cap 5/600) · `ENT5-Q004` idempotency wajib
untuk payments/invoices/inventory/lab-candidate/notifications · `ENT5-Q005`
perintah queue destruktif tidak diotomasi di app code · `ENT5-Q006` worker via
runbook, deploy tidak start worker · `ENT5-Q007` section
`queue_retry_governance` di governance summary · `ENT5-Q008` semua ShouldQueue
baru wajib extend `EnterpriseQueueJob` / trait / deklarasi eksplisit.

Detail: `docs/architecture/queue-retry-failed-job-governance.md`.
Runbook: `docs/architecture/queue-worker-operations-runbook.md`.

## Commands Added

```bash
php artisan foundation:queue-retry-failed-job-check [--json] [--strict|--fail-on-warning]
```

Read-only; GO/WATCH → exit 0 (WATCH → 1 saat strict); FAIL → exit 1.

## Tests

- `tests/Feature/Architecture/Ent5QueueRetryFailedJobGovernanceTest.php` —
  roadmap/doc/rules/summary/freeze-lock assertions + `foundation:roadmap-check --strict`.
- `tests/Feature/Foundation/QueueRetryFailedJobGovernanceCommandTest.php` —
  command GO/JSON/strict, deteksi koneksi terlarang per environment, deteksi
  driver failed salah, scan ShouldQueue (0 job = pass), dan **runtime end-to-end**:
  job berbasis `EnterpriseQueueJob` di-dispatch ke koneksi `database`, diproses
  `queue:work --once`; job gagal masuk `failed_jobs` dan bisa di-`queue:retry`/
  `queue:forget` per uuid.
- Update tests yang mem-pin `next_recommended_sprint = ENT-5` → `ENT-6`
  (pola yang sama seperti setiap sprint foundation sebelumnya).

## Deployment Checklist (VPS pilot)

1. Backup DB (`pre_ent5_...sql`, verifikasi non-empty) — stop bila gagal.
2. Fetch + checkout GO tag; `composer install --no-dev`; `npm ci && npm run build`.
3. `php artisan migrate --force` (harusnya `Nothing to migrate.`) — **tidak pernah** `migrate:fresh`/`db:wipe`.
4. `optimize:clear` + `config:cache`/`route:cache`/`view:cache`/`event:cache`; reset permission storage/bootstrap-cache; restart php-fpm; reload nginx.
5. **Tidak menyalakan queue worker/systemd service baru** — ENT-5 = worker-ready, bukan worker rollout.

## VPS Smoke Checklist

```bash
php artisan foundation:queue-retry-failed-job-check --strict
php artisan foundation:queue-governance-check
php artisan queue:failed
php artisan foundation:roadmap-check --strict   # next = ENT-6, tidak stale
php artisan architecture:foundation-governance-summary
```

## GO / NO-GO

GO bila: seluruh test suite hijau, pint clean, `foundation:queue-retry-failed-job-check --strict`
exit 0 di lokal & VPS, roadmap next = ENT-6 tidak stale, semua section
governance summary tetap GO, smoke HTTP login 200, tidak ada error baru di log.
NO-GO bila ada yang gagal → jangan tag/deploy, laporkan apa adanya.

## Rollback Notes

Governance/additive-only: rollback = checkout tag sebelumnya
(`ent-4-redis-cache-enterprise-policy-go`) + `optimize:clear` + rebuild cache.
Tidak ada migrasi untuk di-rollback; restore backup hanya bila DB tersentuh
(seharusnya tidak).
