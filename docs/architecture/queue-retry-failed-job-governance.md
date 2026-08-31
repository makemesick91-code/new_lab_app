# ENT-5 — Queue, Retry & Failed Job Governance

Status: **LOCKED (ENT-5)** — bagian dari Enterprise Foundation Freeze Rules.
Sprint: ENT-5 — Queue, Retry & Failed Job Governance.
Base foundation: QUEUE-1 (Queue, Idempotency & Outbox Foundation) — tidak digantikan, dioperasionalkan.

Dokumen ini adalah sumber kebenaran (durable lock) untuk perilaku queue, retry,
failed job, dan worker di DaengtisiaMS. Semua sprint berikutnya, semua AI
assistant (Claude/Cursor/Codex), dan semua reviewer wajib mengikuti aturan di
dokumen ini.

**Aturan inti untuk semua prompt/sprint berikutnya:** Queue, retry, failed job,
dan worker behavior wajib mengikuti ENT-5. Setiap job/listener `ShouldQueue`
baru wajib mendefinisikan atau mewarisi timeout/retry/backoff/idempotency yang
disetujui dan wajib tercakup oleh governance check
(`foundation:queue-retry-failed-job-check`).

## Komponen Runtime

| Komponen | Lokasi | Peran |
|---|---|---|
| Retry standard config | `config/queue_governance.php` → `ent5_retry_failed_job` | Sumber kebenaran tries/backoff/timeout, kebijakan koneksi per environment, failed-job storage, scan convention |
| Trait defaults | `App\Support\Queue\EnterpriseQueueRetryDefaults` | Properti `tries`/`backoff`/`timeout` + `applyEnterpriseQueueRetryDefaults()` dari config |
| Base job | `App\Support\Queue\EnterpriseQueueJob` | Kelas dasar semua job masa depan; otomatis memakai standar retry pusat |
| Readiness inspector | `App\Support\Queue\QueueRetryFailedJobReadinessService` | Pemeriksa read-only: koneksi, failed storage, standar retry, scan ShouldQueue, scan perintah destruktif, runbook |
| Governance rules | `App\Services\Foundation\QueueRetryFailedJobGovernanceService` | Menerbitkan ENT5-Q001..Q008 ke `architecture:foundation-governance-summary` (section `queue_retry_governance`, informational/non-blocking) |
| Gate command | `php artisan foundation:queue-retry-failed-job-check` | `--json`, `--strict`/`--fail-on-warning`; FAIL → exit non-zero |
| Worker runbook | `docs/architecture/queue-worker-operations-runbook.md` | Operasional worker, inspeksi failed job, retry/forget manual |

## Aturan ENT5-Q001..Q008

### ENT5-Q001 — Kebijakan koneksi queue per environment
Koneksi queue aktif wajib diizinkan untuk environment berjalan.
`sync` hanya untuk local/testing. Pilot/staging/production wajib memakai
koneksi broker-backed (`database` sekarang; `redis` setelah readiness CACHE-1
GO) supaya retry dan failed job benar-benar ada.

### ENT5-Q002 — Failed job storage wajib siap dan bisa diinspeksi
Driver failed job wajib `database-uuids` dengan tabel `failed_jobs`
(migrasi standar Laravel sudah ada — tidak ada migrasi baru di ENT-5).
Failed job harus bisa dilihat (`queue:failed`), di-retry per id
(`queue:retry <uuid>`), dan dihapus per id (`queue:forget <uuid>`). Failed-job
storage tidak boleh dimatikan.

### ENT5-Q003 — Standar retry/backoff/timeout terpusat
Default: `tries=3`, `backoff=[10, 60, 180]` detik, `timeout=120` detik.
Batas keras: `max_tries=5`, `max_timeout=600` detik. Override per job boleh,
tapi wajib dalam batas dan dijustifikasi saat review. Sumber kebenaran:
`config/queue_governance.php` → `ent5_retry_failed_job.retry_standards`.

### ENT5-Q004 — Job domain kritis wajib idempotent
Job yang menyentuh payments, invoices, inventory, lab candidate generation,
atau notifications wajib idempotent (pakai QUEUE-1 `IdempotencyService`).
Pembayaran yang sudah commit tidak boleh di-rollback karena job sekunder gagal.
RME payment → LabCaseCandidate tetap idempotent dan tidak boleh auto-create
LabOrder. Tidak ada job yang auto-send WhatsApp.

### ENT5-Q005 — Perintah queue destruktif tidak boleh diotomasi
Perintah yang menghapus antrean/failed job secara massal (flush/clear) hanya
boleh dijalankan manual oleh operator sesuai runbook. Kode aplikasi, scheduler,
dan deploy script tidak boleh memanggilnya. Governance check memindai `app/`
untuk pola ini (pola disimpan di config, bukan hardcoded di source).

### ENT5-Q006 — Worker mengikuti runbook; deploy tidak pernah start worker
Worker berjalan lewat setup operator/supervisor eksplisit sesuai
`docs/architecture/queue-worker-operations-runbook.md`. Deploy tidak boleh
menyalakan long-running worker sebagai efek samping (kebijakan QUEUE-1
`no_long_running_worker_started_by_deploy` tetap berlaku).

### ENT5-Q007 — Terlihat di foundation governance summary
Section `queue_retry_governance` wajib muncul di
`architecture:foundation-governance-summary` sebagai section informational
non-blocking, dan tidak boleh melemahkan/menggantikan section `queue_governance`
milik QUEUE-1.

### ENT5-Q008 — Konvensi adopsi untuk modul masa depan
Setiap job/listener baru yang `implements ShouldQueue` wajib salah satu dari:
1. extend `App\Support\Queue\EnterpriseQueueJob` (disarankan), atau
2. `use App\Support\Queue\EnterpriseQueueRetryDefaults` + panggil
   `applyEnterpriseQueueRetryDefaults()` di constructor, atau
3. mendeklarasikan eksplisit `$tries`, `$backoff` (atau method `backoff()`),
   dan `$timeout` dalam batas ENT5-Q003.

Nama queue wajib terdaftar di `allowed_queue_names` (lihat ENT5-Q009 — daftar
ini bertambah ketika sebuah modul memerlukan queue khusus, dan penambahannya
wajib disertai consumer). Payload job tidak boleh berisi PII/KTP/NIK/secret
(aturan QUEUE-1 `no_pii_in_queue_payload` tetap berlaku). Scan
`foundation:queue-retry-failed-job-check` menandai kelas yang melanggar sebagai
FAIL.

### ENT5-Q009 — Setiap queue yang diproduksi wajib punya consumer produksi
Ditambahkan oleh BUGFIX-LEGACY-ODONTOGRAM-QUEUE-CONSUMER-1. Kontrak lengkap:
`docs/architecture/queue-producer-consumer-contract.md`.

Sebuah nama queue yang bisa di-dispatch aplikasi wajib (a) terdaftar di
`allowed_queue_names`, DAN (b) muncul pada daftar `--queue` direktif `ExecStart`
di worker unit yang dilacak repositori. Sebaliknya worker tidak boleh
mengkonsumsi queue yang tidak terdaftar.

Alasannya bukan teoretis: queue tanpa consumer adalah kegagalan paling senyap
yang dimiliki sistem ini — `attempts = 0`, `reserved_at` NULL, `failed_jobs`
kosong, tanpa exception dan tanpa log, sementara status domain tetap `QUEUED`
sehingga tidak bisa dibedakan dari pekerjaan yang masih berjalan. Hal ini sudah
terjadi dua kali (`legacy-rme-documents`, lalu `legacy-odontogram-documents`).
Karena itu pelanggaran adalah **FAIL**, bukan laporan.

Nama queue di-resolve saat runtime dari config key yang menentukannya (semua
queue khusus di sini bisa ditimpa environment), dan registri produsen diperiksa
kelengkapannya terhadap pohon sumber — modul baru tidak bisa menambah queue
khusus tanpa terlihat. Sebuah entri boleh menangguhkan syarat consumer lewat
`consumer_required_when`, yaitu pembacaan langsung terhadap flag fitur yang sama
dengan runtime; ini bukan daftar pengecualian, karena mengaktifkan fitur akan
mengembalikan syarat tersebut dengan sendirinya.

Selisih antara unit yang dilacak dan unit yang terpasang di host dilaporkan
sebagai **WARNING**, bukan FAIL: deploy dilarang memasang atau menjalankan
worker (ENT5-Q006), sehingga aktivasi adalah langkah operator setelahnya, dan
FAIL di sini akan mengunci deploy yang justru harus berjalan lebih dulu.

### ENT5-Q010 — Direktif unit worker wajib berada di section yang dibaca systemd
Direktif pada unit worker produksi wajib dideklarasikan di section tempat
systemd benar-benar membacanya. Direktif yang salah section akan diabaikan
diam-diam, sehingga isi file dan service yang berjalan berbeda tanpa ada yang
mengungkapkannya.

Produksi membuktikannya: `StartLimitIntervalSec=0` berada di `[Service]`,
systemd mencatat `Unknown key name 'StartLimitIntervalSec' in section 'Service',
ignoring`, lalu menerapkan default-nya sendiri — `systemctl show` melaporkan
`StartLimitIntervalUSec=10s` terhadap file yang meminta `0`.

## Cara Verifikasi

```bash
php artisan foundation:queue-retry-failed-job-check            # GO/WATCH → 0, FAIL → 1
php artisan foundation:queue-retry-failed-job-check --strict   # WATCH juga → 1
php artisan foundation:queue-retry-failed-job-check --json
php artisan architecture:foundation-governance-summary          # section queue_retry_governance
```

Command bersifat read-only: tidak mem-dispatch job, tidak menyalakan worker,
tidak menghapus apa pun.

## Yang TIDAK dilakukan ENT-5

- Tidak mengubah driver queue runtime (pilot tetap `database`).
- Tidak menyalakan long-running worker di VPS (worker-ready, bukan worker rollout).
- Tidak menambah migrasi (tabel `jobs`/`failed_jobs` sudah ada).
- Tidak menambah Horizon/Redis/vendor eksternal.
- Tidak mengubah alur RME/payment/inventory/lab.

## Relasi Dokumen

- `docs/architecture/enterprise-foundation-freeze-rules.md` — Section 7 (Queue, Idempotency & Outbox Rules) mengunci dokumen ini sebagai durable lock.
- `docs/architecture/queue-1-queue-idempotency-outbox-foundation.md` — fondasi QUEUE-1 yang dioperasionalkan ENT-5.
- `docs/architecture/queue-worker-operations-runbook.md` — runbook operasional worker/failed job.
- `docs/sprints/ent-5-queue-retry-failed-job-governance.md` — evidence sprint ENT-5.
