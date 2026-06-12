# Checklist Smoke Test Manual — Dasbor Owner (Sprint 22.5–22.6)

## 1. Tujuan

Checklist ini untuk **validasi manual Dasbor Owner** setelah deploy Sprint 22 Phase 22.5 (KPI RME/Lab pilot) dan Phase 22.6 (filter cabang, ringkasan per cabang, drilldown read-only).

**Fokus:**

- KPI monitoring RME/Lab
- Filter cabang Owner
- Tabel **Ringkasan Per Cabang**
- Link drilldown **read-only** yang muncul sesuai permission
- Memastikan role Branch Admin dan Kasir **tidak terpengaruh**
- Memastikan membuka dashboard **tidak mengubah data** operasional

---

## 2. Prasyarat

Sebelum mulai, pastikan:

- [ ] Kode sudah berada di branch/tag **Sprint 22.6** atau lebih baru (`feature/sprint-22-owner-dashboard-branch-filter-drilldown` / `sprint-22-phase-22-6-owner-dashboard-branch-filter-drilldown`).
- [ ] `PermissionSeeder` sudah dijalankan di lingkungan uji.
- [ ] `RoleSeeder` sudah dijalankan di lingkungan uji.
- [ ] `RmeSmokeTestSeeder` **opsional** — hanya jika butuh akun/data smoke test.
- [ ] Akun **Owner** tersedia (smoke test atau pilot asli).
- [ ] Minimal satu akun **Admin Lab** tersedia jika perlu memverifikasi link kandidat/lab order (opsional).
- [ ] Browser siap (Chrome atau Firefox disarankan).
- [ ] Data RME/Lab boleh **kosong** atau berisi data pilot — KPI boleh menampilkan nol.
- [ ] **Backup database** sudah dibuat sebelum deploy/pull di VPS (lihat `docs/pilot/vps_pilot_deployment_checklist.md`).

---

## 3. Akun Uji

Gunakan akun smoke test dari Phase 22.2 (password semua: `SmokeTestPilot!`):

| Role | Email |
|------|-------|
| Dokter | `dokter.smoke@pilot-test.local` |
| Perawat | `perawat.smoke@pilot-test.local` |
| Kasir | `kasir.smoke@pilot-test.local` |
| Owner | `owner.smoke@pilot-test.local` |

**Catatan:**

- Jika akun Owner smoke **tidak** digunakan di VPS, gunakan akun Owner pilot asli yang memiliki permission `view_owner_dashboard`.
- Jika link kandidat/lab order **tidak muncul** untuk Owner, itu **boleh terjadi** jika role Owner memang read-only terbatas (tanpa `view_lab_orders` / `manage_lab_orders`).
- Untuk uji batas role Branch Admin, gunakan akun **Admin Klinik** atau **Admin Lab** yang memiliki `view_branch_dashboard` (bukan akun smoke test di atas).

---

## 4. Pengingat Deploy Aman

Urutan deploy aman (ringkas — detail lengkap di `docs/pilot/vps_pilot_deployment_checklist.md`):

1. **Backup database** terlebih dahulu (`pg_dump` atau script backup yang disetujui).
2. `git fetch --all --tags`
3. Checkout/pull branch atau tag Sprint 22.6+ yang disetujui.
4. `composer install --no-dev --optimize-autoloader` (di VPS).
5. `php artisan optimize:clear`
6. `php artisan migrate --force` **hanya** jika ada migrasi baru yang sudah direview (Phase 22.5–22.6 **tidak** menambah migrasi schema).
7. Seeder aman (idempotent):

```bash
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=RmeSmokeTestSeeder --force   # opsional
```

8. Rebuild cache: `php artisan config:cache`, `route:cache`, `view:cache`
9. Periksa log: `tail -n 100 storage/logs/laravel.log`

**Sprint 22.5–22.6 tidak memerlukan reset data destruktif.**

### Perintah terlarang

Jangan jalankan perintah berikut di VPS/pilot:

| Perintah | Alasan |
|----------|--------|
| `php artisan migrate:fresh` | Menghapus seluruh schema dan data |
| `php artisan migrate:fresh --seed` | Reset penuh + seed default |
| `php artisan db:wipe` | Menghapus semua tabel |
| `php artisan db:seed` tanpa `--class=` | Menjalankan `DatabaseSeeder` penuh — berbahaya di pilot |
| `TRUNCATE` / hapus massal data pilot | Kehilangan data irreversible |

Detail seeder: `docs/pilot/safe_seeder_rollout.md`.

---

## 5. Langkah Smoke Test Manual

Centang setiap baris setelah diverifikasi. Lampirkan screenshot sesuai kolom **Bukti screenshot**.

| No | Role | Halaman | Aksi | Ekspektasi | Bukti screenshot |
|----|------|---------|------|------------|-------------------|
| 1 | Owner | Login | Login sebagai Owner (`owner.smoke@pilot-test.local` atau akun Owner pilot) | Berhasil masuk tanpa error | |
| 2 | Owner | `/dashboard` | Buka Dasbor | Halaman dashboard terbuka (HTTP 200, tanpa error 500) | |
| 3 | Owner | `/dashboard` | Cari bagian **Monitoring Pilot RME & Lab** | Bagian tersebut tampil di dashboard Owner | |
| 4 | Owner | `/dashboard` | Periksa label KPI | Semua label berikut tampil: **Kunjungan RME Hari Ini**, **RM Draft**, **RM Final Hari Ini**, **Menunggu Kasir**, **Invoice RME Belum Dibayar**, **Pembayaran RME Hari Ini**, **Kandidat Lab RME Pending**, **Kandidat Lab Dikonversi**, **Lab Order dari RME Hari Ini** | |
| 5 | Owner | `/dashboard` | Cari panel **Funnel RME ke Lab** | Panel funnel tampil dengan tahapan RME → kasir → bayar → kandidat → lab order | |
| 6 | Owner | `/dashboard` | Cari panel **Perlu Perhatian** | Panel perhatian tampil (boleh kosong jika tidak ada item) | |
| 7 | Owner | `/dashboard` | Baca disclaimer monitoring | Teks tampil: **Dashboard ini hanya monitoring; tidak membuat atau mengubah data RME/Lab.** | |
| 8 | Owner | `/dashboard` | Cari kontrol **Filter Cabang** | Dropdown filter cabang tampil di bagian monitoring | |
| 9 | Owner | `/dashboard` | Pilih **Semua Cabang** | Opsi terpilih; teks scope: **Menampilkan semua cabang aktif** | |
| 10 | Owner | `/dashboard` | Pilih satu cabang aktif dari dropdown | Halaman reload; teks scope: **Menampilkan cabang: {nama cabang}** | |
| 11 | Owner | `/dashboard` | Setelah ganti cabang, perhatikan nilai KPI | KPI ter-update tanpa error halaman | |
| 12 | Owner | `/dashboard?branch_id=999999` | Buka URL dengan `branch_id` tidak valid secara manual | Dashboard tetap terbuka; fallback aman ke semua cabang aktif (tanpa crash) | |
| 13 | Owner | `/dashboard` | Cari tabel **Ringkasan Per Cabang** | Tabel ringkasan tampil | |
| 14 | Owner | `/dashboard` | Periksa nama cabang di tabel | Nama cabang **aktif** muncul di baris tabel | |
| 15 | Owner | `/dashboard` | Jika ada cabang tidak aktif yang diketahui | Cabang tidak aktif **tidak** muncul di tabel | |
| 16 | Owner | `/dashboard` | Periksa kolom tabel | Kolom: **Cabang**, **Kunjungan Hari Ini**, **Menunggu Kasir**, **Invoice Belum Dibayar**, **Kandidat Lab Pending**, **Dikonversi Hari Ini**, **Status Perhatian** | |
| 17 | Owner | `/dashboard` | Periksa kartu KPI yang punya permission drilldown | Teks link **Lihat detail** hanya pada kartu yang diizinkan permission user | |
| 18 | Owner | Drilldown | Klik **Lihat detail** pada drilldown kunjungan klinik (jika ada) | Halaman indeks kunjungan terbuka (read-only/list) | |
| 19 | Owner | Drilldown | Di halaman tujuan drilldown | Tidak ada aksi create/edit otomatis terpicu dari dashboard | |
| 20 | Owner | Drilldown | Jika link kandidat lab muncul, klik **Lihat detail** kandidat | Halaman indeks kandidat lab (`/lab/case-candidates`) terbuka | |
| 21 | Owner | `/dashboard` | Jika link kandidat/lab order **tidak** muncul | Catat sebagai **diterima** jika Owner tidak punya `view_lab_orders` | |
| 22 | Branch Admin | Login | Login sebagai Admin Klinik / Admin Lab (`view_branch_dashboard`) | Berhasil masuk | |
| 23 | Branch Admin | `/dashboard` | Buka Dasbor | Dashboard branch admin terbuka | |
| 24 | Branch Admin | `/dashboard` | Cari **Filter Cabang** Owner | **Tidak** tampil | |
| 25 | Branch Admin | `/dashboard` | Cari **Ringkasan Per Cabang** global Owner | **Tidak** tampil | |
| 26 | Kasir | Login | Login sebagai Kasir (`kasir.smoke@pilot-test.local`) | Berhasil masuk | |
| 27 | Kasir | `/dashboard` | Buka Dasbor | Dashboard Kasir terbuka | |
| 28 | Kasir | `/dashboard` | Cari bagian global **Monitoring Pilot RME & Lab** Owner | **Tidak** tampil | |
| 29 | Owner | DB / UI | Catat jumlah record sebelum buka dashboard (jika memungkinkan): kunjungan klinik, invoice RME, pembayaran RME, kandidat lab, lab order | Angka baseline tercatat | |
| 30 | Owner | `/dashboard` | Refresh dashboard beberapa kali | Tidak ada record baru atau perubahan status hanya karena membuka dashboard | |
| 31 | Owner | `/dashboard` | Bandingkan jumlah record setelah refresh | Sama dengan baseline (tidak ada mutasi data) | |

---

## 6. Hasil yang Diharapkan

Setelah checklist selesai, semua poin berikut harus terpenuhi:

- Dasbor Owner terbuka tanpa error.
- Bagian **Monitoring Pilot RME & Lab** tampil dengan KPI bernilai **nol atau angka live** sesuai data pilot.
- **Filter Cabang** berfungsi untuk **Semua Cabang** dan cabang terpilih.
- `branch_id` tidak valid tidak menyebabkan crash.
- **Ringkasan Per Cabang** menampilkan cabang aktif saja.
- Link **Lihat detail** muncul hanya pada kartu yang diizinkan permission (permission-aware).
- Dashboard Branch Admin dan Kasir **tidak berubah** (tanpa filter/ringkasan global Owner).
- **Tidak ada mutasi data** RME/Lab hanya karena membuka dashboard.

---

## 7. Daftar Bukti Screenshot

Wajib lampirkan screenshot berikut (atau catat alasan jika tidak relevan):

- [ ] Dasbor Owner — halaman penuh
- [ ] Bagian **Monitoring Pilot RME & Lab** (KPI cards)
- [ ] Filter **Semua Cabang**
- [ ] Filter satu cabang terpilih (dengan teks scope cabang)
- [ ] Tabel **Ringkasan Per Cabang**
- [ ] Halaman tujuan drilldown (jika link **Lihat detail** ada)
- [ ] Dasbor Branch Admin **tanpa** filter Owner
- [ ] Dasbor Kasir **tanpa** bagian global Owner RME/Lab
- [ ] Halaman error (jika ditemukan bug)

---

## 8. Format Laporan Bug

Gunakan format berikut saat melaporkan ke developer/IT:

| Field | Isi |
|-------|-----|
| Tanggal/waktu | |
| Lingkungan | Lokal / VPS |
| Branch/tag git | |
| Role pengguna | Owner / Branch Admin / Kasir / lainnya |
| Email pengguna | |
| Cabang terpilih (filter) | Semua Cabang / nama cabang / — |
| URL | |
| Tindakan | |
| Hasil diharapkan | |
| Hasil aktual | |
| Screenshot | (lampirkan) |
| Browser/perangkat | |
| Cuplikan log Laravel | (jika relevan) |

---

## 9. Keterbatasan yang Diketahui

- Halaman tujuan drilldown memakai **BranchContext** aktif user, **bukan** `branch_id` filter dashboard — operator mungkin perlu mengganti cabang aktif di halaman tujuan.
- Filter rentang tanggal (`date_from` / `date_to`) **belum** tersedia di UI (deferred).
- Tabel **Ringkasan Per Cabang** skala pilot (semua cabang aktif dalam satu tabel); pagination mungkin diperlukan jika jumlah cabang bertambah.
- Kartu KPI eksekutif inventory/lab finance mungkin masih terpisah atau placeholder tergantung versi dashboard.
- Dashboard ini **hanya monitoring** — tidak membuat, mengubah, atau menghapus transaksi RME/Lab.

---

## Referensi Terkait

- `docs/pilot/owner_dashboard_rme_lab_kpi_notes.md` — definisi KPI developer
- `docs/pilot/vps_pilot_deployment_checklist.md` — deploy VPS aman
- `docs/pilot/safe_seeder_rollout.md` — rollout seeder idempotent
- `docs/pilot/rme_smoke_test_operator_checklist.md` — smoke test alur RME
