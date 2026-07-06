# ENT-5 — Queue Worker Operations Runbook

Status: **ACTIVE (ENT-5)**. Runbook operasional untuk queue worker dan failed
job DaengtisiaMS. Semua perintah di sini dijalankan **manual oleh operator** —
tidak ada yang diotomasi oleh kode aplikasi, scheduler, atau deploy script
(ENT5-Q005/Q006).

## Posisi saat ini (pilot, single VPS)

- Koneksi queue pilot: `database` (tabel `jobs`), failed job: `database-uuids`
  (tabel `failed_jobs`). Keduanya sudah termigrasi.
- **Belum ada long-running worker yang dinyalakan di VPS** — belum ada job
  `ShouldQueue` di aplikasi. Status ENT-5: *governance-ready dan worker-ready,
  bukan forced worker rollout*. Worker dinyalakan lewat supervisor hanya saat
  modul pertama yang benar-benar mem-dispatch job masuk.
- Deploy (`scripts/deploy-vps.sh`) tidak boleh menyalakan worker.

## Menjalankan worker (sesuai standar ENT5-Q003)

```bash
php artisan queue:work --queue=default --tries=3 --backoff=10 --timeout=120 --max-time=3600
```

- `--max-time=3600` membuat worker keluar tiap jam supaya supervisor
  me-restart dengan kode terbaru (hindari worker memakai kode lama pasca deploy).
- Setelah deploy, selalu jalankan `php artisan queue:restart` supaya worker
  yang sedang berjalan mengambil kode baru.
- Queue tambahan yang diizinkan: `reports`, `notifications`, `maintenance`.

### Template supervisor (dipakai saat worker rollout disetujui)

```ini
[program:daengtisiams-queue-default]
command=php /var/www/asia-dental-lab-v2/artisan queue:work --queue=default --tries=3 --backoff=10 --timeout=120 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopwaitsecs=130   ; > timeout supaya job tidak terpotong saat stop
numprocs=1
```

`stopwaitsecs` wajib lebih besar dari `--timeout` agar job yang sedang berjalan
tidak dibunuh paksa saat restart.

## Inspeksi & tindak lanjut failed job

```bash
php artisan queue:failed              # daftar failed job (uuid, connection, queue, waktu, exception)
php artisan queue:retry <uuid>        # retry SATU failed job berdasarkan uuid
php artisan queue:forget <uuid>       # hapus SATU failed job berdasarkan uuid
php artisan queue:monitor database:default --max=100   # alarm bila backlog melebihi ambang
```

Prosedur:
1. Lihat exception di `queue:failed` / tabel `failed_jobs` (kolom `exception`).
2. Perbaiki akar masalah (bug/config) dulu — jangan retry berulang tanpa perbaikan.
3. Retry **per id**. Retry massal (`queue:retry --all`) hanya untuk insiden yang
   dipahami penuh, dengan persetujuan eksplisit, dan dicatat di evidence.
4. Job domain kritis (payments/invoices/inventory/lab candidate/notification)
   idempotent by design (ENT5-Q004), jadi retry aman — tapi tetap verifikasi
   efeknya setelah retry.

## Perintah destruktif — MANUAL ONLY, jangan diotomasi

- Menghapus semua failed job sekaligus, atau mengosongkan antrean
  (`flush`/`clear` family) — hanya operator, hanya saat insiden, selalu backup
  DB dulu, dan tidak pernah dipanggil dari kode aplikasi/cron/deploy.
- `queue:prune-failed` (menghapus failed job lama) hanya manual dengan `--hours`
  yang disepakati.

## Checklist smoke queue di VPS

```bash
php artisan foundation:queue-retry-failed-job-check --strict
php artisan foundation:queue-governance-check
php artisan queue:failed
php artisan architecture:foundation-governance-summary
```

Semua read-only. Worker tidak perlu berjalan agar check GO — yang diperiksa
adalah kesiapan (koneksi, storage failed job, standar retry, konvensi kode).
