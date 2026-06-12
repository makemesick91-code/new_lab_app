# Checklist Deploy VPS Pilot — Sprint 22 (Phase 22.1–22.7)

> **Jenis fase:** Dokumentasi / runbook saja  
> **Deploy dilakukan:** Tidak — dokumen ini disusun lokal; perintah VPS tidak dijalankan saat penulisan.  
> **Wajib dibaca:** Baca seluruh runbook dan konfirmasi langkah backup/rollback sebelum aksi produksi.

---

## 1. Tujuan

Dokumen ini adalah checklist deploy **aman** untuk memindahkan perubahan **Sprint 22** (Phase 22.1 role/permission/menu, Phase 22.2 smoke test RME, Phase 22.5–22.6 Dasbor Owner KPI/filter/drilldown, dan fase checklist terkait) ke lingkungan VPS/pilot ADLMS.

**Prioritas utama:** mempertahankan data pilot yang sudah ada. Deploy ini **tidak** boleh mereset database, menghapus data pasien nyata, atau menjalankan seeder destruktif.

| Item | Nilai |
|------|-------|
| Branch deploy (Phase 22.2) | `feature/sprint-22-rme-smoke-test-checklist` |
| Tag deploy (Phase 22.2) | `sprint-22-phase-22-2-rme-smoke-test-checklist` |
| Branch checklist (Phase 22.3) | `feature/sprint-22-vps-pilot-deployment-checklist` |
| Tag checklist (Phase 22.3) | `sprint-22-phase-22-3-vps-pilot-deployment-checklist` |
| Branch deploy (Phase 22.6) | `feature/sprint-22-owner-dashboard-branch-filter-drilldown` |
| Tag deploy (Phase 22.6) | `sprint-22-phase-22-6-owner-dashboard-branch-filter-drilldown` |
| Commit baseline Phase 22.6 | `8dc1d58` |
| Branch checklist (Phase 22.7) | `feature/sprint-22-vps-owner-dashboard-smoke-checklist` |
| Tag checklist (Phase 22.7) | `sprint-22-phase-22-7-owner-dashboard-smoke-checklist` |
| Commit baseline Phase 22.2 | `5d491f3e3bc95dbc0f434af0e9450b5b3279671d` |
| Path aplikasi VPS (contoh) | `APP_DIR` — ganti dengan path proyek di VPS |
| Path proyek lokal (contoh) | `~/Projects/new_lab_app` atau `/mnt/DATA/new_lab_app` |

---

## 2. Asumsi Deploy

Sebelum memulai, pastikan kondisi berikut terpenuhi:

- [ ] VPS sudah menjalankan aplikasi ADLMS (deploy sebelumnya, mis. Sprint 21 RC).
- [ ] PostgreSQL sudah tersedia dan database pilot berisi data operasional.
- [ ] **Data pilot yang ada harus dipertahankan** — tidak ada reset database.
- [ ] Branch dan tag target **sudah di-push** ke remote sebelum `git pull` di VPS.
- [ ] Operator memiliki akses SSH ke VPS.
- [ ] Operator mengetahui path proyek di VPS (`APP_DIR`).
- [ ] Operator mengetahui nama database dan user PostgreSQL dari `.env` (`DB_NAME`, `DB_USER`).
- [ ] Operator dapat menjalankan perintah `php artisan` di VPS.
- [ ] Jendela deploy disetujui (maintenance mode opsional sesuai kebijakan ops).

---

## 3. Verifikasi Lokal Sebelum Deploy

Jalankan di **mesin lokal** sebelum menyetujui deploy VPS.

```bash
cd ~/Projects/new_lab_app   # atau /mnt/DATA/new_lab_app

git branch --show-current
git status --short

php artisan optimize:clear
php artisan test --filter=RME
php artisan test
./vendor/bin/pint --dirty
```

### Aturan terminal untuk tes berat

> **PENTING:** Tes berat (`php artisan test --filter=RME` dan `php artisan test` full suite) **wajib** dijalankan di **Ubuntu Terminal biasa**, **bukan** terminal terintegrasi Cursor atau VS Code.

Alasan: suite penuh berat dan lama; terminal editor dapat memicu indexing/watcher tambahan sehingga performa tidak stabil.

### Kriteria lulus lokal

| Pengecekan | Harapan |
|------------|---------|
| Working tree | Bersih atau hanya perubahan yang disengaja |
| Branch | Branch Phase 22.2 atau Phase 22.3 yang disetujui |
| RME tests | Semua lulus |
| Full suite | Semua lulus |
| Pint | PASS, 0 file dirty |

**Jangan deploy ke VPS jika full suite gagal.**

---

## 4. Push Wajib Sebelum Deploy VPS

Pastikan branch dan tag sudah ada di remote **sebelum** SSH ke VPS:

```bash
git push origin feature/sprint-22-rme-smoke-test-checklist
git push origin sprint-22-phase-22-2-rme-smoke-test-checklist
git push origin feature/sprint-22-vps-pilot-deployment-checklist
git push origin sprint-22-phase-22-3-vps-pilot-deployment-checklist
```

---

## 5. Backup Sebelum Deploy (VPS)

> **Label:** Jalankan di VPS saat deploy  
> **Aturan berhenti:** Jika backup gagal atau ukuran file nol → **hentikan deploy segera**.

### 5.1 Siapkan variabel (placeholder)

Ganti placeholder dengan nilai nyata di VPS. **Jangan** menulis password ke repositori atau tiket publik.

```bash
export APP_DIR="/path/ke/aplikasi"          # contoh: /var/www/asia-dental-lab-v2
export BACKUP_DIR="$HOME/backups/daengtisia"
export DB_NAME="nama_database_dari_env"     # dari DB_DATABASE di .env
export DB_USER="user_postgres_dari_env"     # dari DB_USERNAME di .env
```

### 5.2 Buat direktori backup

```bash
mkdir -p "$BACKUP_DIR"
```

### 5.3 Export timestamp dan jalankan pg_dump

```bash
export BACKUP_FILE="$BACKUP_DIR/${DB_NAME}_before_sprint22_$(date +%Y%m%d_%H%M%S).dump"

pg_dump -U "$DB_USER" -d "$DB_NAME" -F c -f "$BACKUP_FILE"
```

Alternatif: gunakan `scripts/backup_postgres.sh` di repo dengan `BACKUP_DIR` yang sama (format custom `.dump`).

### 5.4 Verifikasi file backup

```bash
ls -lh "$BACKUP_FILE"
```

**Kriteria lulus:** file ada, ukuran **bukan nol**, path tercatat untuk rollback.

### 5.5 Catat referensi rollback

- Path file backup: `________________________`
- Commit/branch VPS sebelum pull: `________________________`
- Waktu backup: `________________________`

---

## 6. Urutan Perintah Deploy VPS

> **Label:** Jalankan di VPS setelah backup terverifikasi

```bash
cd "$APP_DIR"

git status --short
git fetch --all --tags

# Pilih salah satu target yang disetujui (terbaru: Phase 22.6+):
git checkout feature/sprint-22-owner-dashboard-branch-filter-drilldown
# atau:
# git checkout sprint-22-phase-22-6-owner-dashboard-branch-filter-drilldown
# historis:
# git checkout feature/sprint-22-rme-smoke-test-checklist
# git checkout sprint-22-phase-22-2-rme-smoke-test-checklist

composer install --no-dev --optimize-autoloader

# Hanya jika aset frontend berubah dan VPS mendukung build:
# npm ci && npm run build

php artisan optimize:clear

# Hanya jika ada migrasi baru yang sudah direview — Sprint 22.1–22.6 tidak menambah migrasi schema:
# php artisan migrate --force

php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=RoleSeeder
php artisan db:seed --class=RmeSmokeTestSeeder

php artisan config:cache
php artisan route:cache
php artisan view:cache

# Jika queue worker dipakai:
# php artisan queue:restart
```

### Catatan penting seeder

- **`RmeSmokeTestSeeder` bersifat opt-in** — hanya jalankan jika tim pilot membutuhkan akun/data smoke test. Lihat `docs/pilot/safe_seeder_rollout.md`.
- **`DatabaseSeeder` tidak berubah** dan **tidak boleh** dipakai sebagai mekanisme reset produksi/pilot di VPS.
- **`php artisan migrate:fresh` dan `php artisan db:wipe` dilarang** di VPS (lihat bagian 11).

### Migrasi dan data

Sprint 22 Phase 22.1–22.6 **tidak** menambah migrasi schema baru dan **tidak** memerlukan reset data destruktif. Jalankan `php artisan migrate --force` **hanya** jika fase berikutnya menambahkan migrasi yang sudah direview.

**Jangan** jalankan `migrate:fresh`, `migrate:fresh --seed`, atau `db:wipe` di VPS. Seeder aman tetap: `PermissionSeeder`, `RoleSeeder`, dan opsional `RmeSmokeTestSeeder` (lihat `docs/pilot/safe_seeder_rollout.md`).

---

## 7. Rollout Seeder Aman

Urutan wajib (idempotent — aman dijalankan ulang):

```bash
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=RoleSeeder
php artisan db:seed --class=RmeSmokeTestSeeder
```

| Seeder | Fungsi |
|--------|--------|
| `PermissionSeeder` | Memperbarui/menambah permission Spatie |
| `RoleSeeder` | Memperbarui role dan assignment permission (Owner, Kasir, Perawat, dll.) |
| `RmeSmokeTestSeeder` | Membuat/memperbarui data smoke test RME (pasien, kunjungan, akun uji) |

Semua seeder di atas:

- **Idempotent** — re-run tidak menduplikasi record kritis (natural key: email, MRN, visit number).
- **Tidak menghapus** data pilot yang tidak terkait smoke test.
- **Tidak** menjalankan `truncate`, `delete` massal, atau `migrate:fresh`.

Detail lengkap: `docs/pilot/safe_seeder_rollout.md`.

---

## 8. Verifikasi Pasca-Deploy

Checklist manual setelah deploy dan seeder (jika dijalankan):

- [ ] Halaman login terbuka tanpa error 500
- [ ] Dashboard terbuka setelah login
- [ ] **Owner** — dashboard owner dapat diakses (`owner.smoke@pilot-test.local` jika seeder dijalankan)
- [ ] **Perawat** — dapat membuka antrian kunjungan RME / route pendukung kunjungan
- [ ] **Dokter** — dapat membuka route rekam medis / odontogram dokter
- [ ] **Kasir** — dapat membuka antrian kasir RME
- [ ] **Kasir tidak dapat** mengedit route rekam medis khusus dokter (403/ditolak)
- [ ] Pasien smoke test ada: `MRN-SMOKE-TEST-RME` (jika `RmeSmokeTestSeeder` dijalankan)
- [ ] Kunjungan smoke test ada: `VIS-SMOKE-TEST-RME`, `VIS-SMOKE-CASHIER-RME`
- [ ] Visibilitas sidebar sesuai role (Dasbor, RME, Kasir RME, Lab — sesuai permission)
- [ ] Tidak ada error 500 baru di log Laravel

Panduan operator lengkap: `docs/pilot/rme_smoke_test_operator_checklist.md`.

---

## 8.1 Owner Dashboard Smoke Test — Sprint 22.5–22.6

Jalankan **setelah** deploy dan seeder aman (jika dijalankan). Panduan lengkap:

**`docs/pilot/owner_dashboard_manual_smoke_test_checklist.md`**

### Validasi minimum (Owner)

1. Login sebagai Owner (`owner.smoke@pilot-test.local` atau akun Owner pilot dengan `view_owner_dashboard`).
2. Buka `/dashboard`.
3. Konfirmasi bagian **Monitoring Pilot RME & Lab** tampil.
4. Uji filter **Semua Cabang** — teks **Menampilkan semua cabang aktif**.
5. Uji filter satu cabang aktif — teks **Menampilkan cabang: {nama}**.
6. Uji tabel **Ringkasan Per Cabang** (cabang aktif saja).
7. Uji satu link **Lihat detail** yang diizinkan permission (jika ada).
8. Konfirmasi dashboard **Branch Admin** dan **Kasir** tidak menampilkan filter/ringkasan global Owner.

### Bukti

- Screenshot sesuai daftar di checklist manual Owner.
- Gunakan format laporan bug di `docs/pilot/owner_dashboard_manual_smoke_test_checklist.md` jika ada temuan.

---

## 9. Pengecekan Log Laravel

```bash
cd "$APP_DIR"
tail -n 100 storage/logs/laravel.log
```

Opsional — cari error terbaru:

```bash
grep -E "ERROR|CRITICAL" storage/logs/laravel.log | tail -n 20
```

**Keamanan:** Jangan membagikan isi log publik jika mengandung data sensitif (token, password, PII pasien).

---

## 10. Rencana Rollback

### Rollback aplikasi (non-destruktif)

```bash
cd "$APP_DIR"

git fetch --all --tags
git checkout sprint-21-release-candidate   # atau tag/commit stabil sebelumnya
# catat commit: git log -1 --oneline

composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
# php artisan queue:restart   # jika queue dipakai
```

### Rollback database

- **Utamakan:** tidak restore DB kecuali deploy/seeder menyebabkan korupsi data nyata.
- Jika restore **benar-benar** diperlukan:
  1. Hentikan akses aplikasi (`php artisan down` atau stop web server).
  2. Restore **hanya** dari file backup yang sudah diverifikasi (bagian 5).
  3. Gunakan `pg_restore` atau prosedur restore yang disetujui ops — **jangan** `migrate:fresh`.
  4. Verifikasi aplikasi, lalu `php artisan up`.

**Jangan** menjalankan restore destruktif secara casual.

---

## 11. Perintah Terlarang

Perintah berikut **dilarang keras** di VPS/pilot:

| Perintah | Alasan |
|----------|--------|
| `php artisan migrate:fresh` | Menghapus seluruh schema dan data |
| `php artisan migrate:fresh --seed` | Reset penuh + seed default |
| `php artisan db:wipe` | Menghapus semua tabel |
| `php artisan db:seed` tanpa `--class=` | Menjalankan `DatabaseSeeder` penuh — berbahaya di pilot |
| Perintah `TRUNCATE` / hapus massal tabel pilot | Kehilangan data irreversible |
| Perintah uji lokal yang mereset DB | Salin-paste dari lingkungan dev |

Jika ragu, **hentikan** dan konsultasi developer sebelum menjalankan perintah artisan terkait database.

---

## 12. Format Laporan Insiden

Jika deploy atau seeder gagal, catat:

| Field | Isi |
|-------|-----|
| Tanggal/waktu | |
| Path VPS (`APP_DIR`) | |
| Branch/tag git | |
| Perintah yang dijalankan | |
| Hasil yang diharapkan | |
| Hasil aktual | |
| Cuplikan log / screenshot | |
| Tindakan rollback | |
| Penanggung jawab | |

---

## 13. Referensi Terkait

- `docs/pilot/safe_seeder_rollout.md` — detail rollout seeder aman
- `docs/pilot/owner_dashboard_manual_smoke_test_checklist.md` — smoke test manual Dasbor Owner (Phase 22.5–22.6)
- `docs/pilot/owner_dashboard_rme_lab_kpi_notes.md` — definisi KPI developer
- `docs/pilot/rme_smoke_test_operator_checklist.md` — uji manual operator RME
- `docs/pilot/rme_smoke_test_developer_notes.md` — desain seeder developer
- `docs/pilot/sprint_22_release_candidate_notes.md` — release candidate notes Sprint 22
- `docs/pilot/vps_pilot_go_no_go_checklist.md` — keputusan GO / NO-GO deploy pilot
- `docs/sprint_21_vps_pilot_deployment_checklist.md` — runbook Sprint 21 (referensi historis)
- `scripts/vps_pilot_preflight.sh` — preflight read-only (opsional, lokal/VPS)

---

## 14. Sprint 22 Release Candidate & Go/No-Go

Setelah Phase 22.8, gunakan dokumen closure Sprint 22 sebelum menyetujui deploy RC ke VPS:

| Dokumen | Isi |
|---------|-----|
| `docs/pilot/sprint_22_release_candidate_notes.md` | Ringkasan fase 22.1–22.8, target branch/tag deploy, urutan deploy aman, kriteria GO/NO-GO, rollback, keterbatasan, backlog Sprint 23 |
| `docs/pilot/vps_pilot_go_no_go_checklist.md` | Checklist operasional GO / GO dengan catatan / NO-GO + tabel sign-off (Developer, Operator Klinik, Owner, Admin Lab, Kasir) |

**Rekomendasi deploy RC Sprint 22:**

- Branch: `feature/sprint-22-closure-rc-go-no-go` (setelah Phase 22.8 di-push) atau `feature/sprint-22-vps-owner-dashboard-smoke-checklist` (baseline fungsional Phase 22.7)
- Tag: `sprint-22-phase-22-8-closure-rc-go-no-go` atau `sprint-22-phase-22-7-owner-dashboard-smoke-checklist`
- Tag RC opsional setelah persetujuan stakeholder: `sprint-22-release-candidate`

**Alur disarankan:**

1. Baca release candidate notes.
2. Jalankan deploy sesuai bagian 6–9 dokumen ini.
3. Isi go/no-go checklist (smoke test Owner, RME, lab candidate).
4. Kumpulkan sign-off sebelum membuka akses penuh ke operator pilot.
