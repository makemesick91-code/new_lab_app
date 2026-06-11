# Checklist Operator — Validasi End-to-End RME → Kandidat Lab → Lab Order

## Tujuan

Checklist ini untuk **validasi alur lengkap** dari rekam medis (RME) sampai kandidat pekerjaan lab dan konversi ke lab order di lingkungan pilot. Gunakan bersama data smoke test Phase 22.2 atau kunjungan uji coba baru dengan tindakan yang membutuhkan lab.

Alur yang divalidasi:

1. Kunjungan RME → rekam medis difinalisasi
2. Kunjungan pindah ke antrian kasir
3. Kasir membuat tagihan RME dan melakukan pembayaran penuh
4. Sistem membuat kandidat lab (jika ada tindakan `requires_lab`)
5. Admin Lab meninjau dan mengonversi kandidat ke lab order
6. Referensi sumber tetap dapat dilacak; tidak ada tagihan/pembayaran lab otomatis

---

## Pra-pengecekan

Sebelum mulai, pastikan:

- [ ] URL aplikasi pilot benar (contoh: URL VPS dari admin IT).
- [ ] `PermissionSeeder` sudah dijalankan.
- [ ] `RoleSeeder` sudah dijalankan.
- [ ] `RmeSmokeTestSeeder` sudah dijalankan (jika memakai akun/data smoke test).
- [ ] Cabang aktif benar: **Klinik Gigi Daengtisia Pusat** (kode `MAIN`).
- [ ] Role pengguna tersedia: **Dokter**, **Kasir**, **Admin Lab** (dan Owner/Admin jika perlu).
- [ ] Ada **master tindakan/tarif** dengan flag membutuhkan lab (`requires_lab = true`). Jika belum ada di smoke data, pilih tindakan lab yang sudah dikonfigurasi admin di cabang pilot.
- [ ] Browser siap (Chrome atau Firefox disarankan).
- [ ] Alat screenshot siap.

---

## Akun Uji Coba

| Item | Nilai |
|------|-------|
| **Password semua akun smoke test** | `SmokeTestPilot!` |
| **Dokter** | `dokter.smoke@pilot-test.local` |
| **Perawat** | `perawat.smoke@pilot-test.local` |
| **Kasir** | `kasir.smoke@pilot-test.local` |
| **Owner** | `owner.smoke@pilot-test.local` |
| **Cabang** | Klinik Gigi Daengtisia Pusat (`MAIN`) |

**Admin Lab:** `RmeSmokeTestSeeder` tidak membuat akun Admin Lab khusus. Gunakan akun Admin Lab pilot yang sudah ditetapkan IT (role **Admin Lab** dengan izin `view_lab_orders` / `create_lab_orders`), atau minta admin menetapkan role tersebut ke akun uji coba.

---

## Langkah Validasi (Step-by-Step)

### 1. Login sebagai Dokter

| Langkah | Tindakan | Hasil yang diharapkan |
|---------|----------|----------------------|
| 1.1 | Login sebagai Dokter | Berhasil masuk |
| 1.2 | Buka antrian kunjungan RME | Daftar kunjungan terbuka |
| 1.3 | Buka kunjungan uji (smoke: `VIS-SMOKE-TEST-RME`, atau kunjungan baru `in_progress`) | Detail kunjungan terbuka |
| 1.4 | Buka rekam medis kunjungan | Form rekam medis terbuka |
| 1.5 | Pastikan **handwriting RM** sudah ada (gambar coretan dokter) | Tanpa handwriting, finalisasi ditolak |
| 1.6 | Finalisasi rekam medis | Status RM menjadi **FINAL** |
| 1.7 | Konfirmasi status kunjungan | Kunjungan menjadi **cashier_pending** (menunggu kasir) |
| 1.8 | Logout | Kembali ke halaman login |

### 2. Login sebagai Kasir

| Langkah | Tindakan | Hasil yang diharapkan |
|---------|----------|----------------------|
| 2.1 | Login sebagai Kasir | Berhasil masuk |
| 2.2 | Buka menu **Kasir RME** | Antrian kasir terbuka |
| 2.3 | Buka kunjungan yang sudah `cashier_pending` | Form buat tagihan terbuka |
| 2.4 | Buat tagihan dengan **minimal satu tindakan yang membutuhkan lab** | Tagihan tersimpan |
| 2.5 | Buka halaman pembayaran tagihan | Form pembayaran terbuka |
| 2.6 | Bayar **penuh** (jumlah = total tagihan) | Pembayaran berhasil |
| 2.7 | Buka struk/kwitansi pembayaran | Struk terbuka |
| 2.8 | Periksa ringkasan kandidat lab (jika UI menampilkannya) | Ada indikasi **Kandidat Lab RME** / status menunggu review |
| 2.9 | Logout | Kembali ke halaman login |

**Status yang diharapkan setelah langkah 2:**

- Tagihan RME: **PAID**
- Kunjungan: **completed** (setelah pembayaran penuh)
- Kandidat lab: **pending_review** (satu kandidat per item lab-eligible)

### 3. Login sebagai Admin Lab

| Langkah | Tindakan | Hasil yang diharapkan |
|---------|----------|----------------------|
| 3.1 | Login sebagai Admin Lab | Berhasil masuk |
| 3.2 | Buka menu **Kandidat Lab RME** | Daftar kandidat terbuka |
| 3.3 | Buka detail kandidat dari langkah kasir | Info pasien, kunjungan, tagihan sumber terlihat |
| 3.4 | Pilih **layanan lab** (`lab_service_id`) dan konversi ke lab order | Konversi berhasil |
| 3.5 | Buka lab order hasil konversi | Lab order ada; bagian **Sumber RME** terlihat jika UI mendukung |
| 3.6 | Logout | Kembali ke halaman login |

**Status yang diharapkan setelah langkah 3:**

- Kandidat: **converted_to_lab_order**
- Lab order: **ada** (satu order, satu item jika aturan konversi standar)
- Tidak ada tagihan/pembayaran lab baru otomatis

---

## Pengecekan Negatif

| Peran | Tindakan | Hasil yang diharapkan |
|-------|----------|----------------------|
| **Kasir** | Coba buka **Kandidat Lab RME** atau konversi | **Ditolak** (403 / menu tidak ada) |
| **Dokter** | Coba buka **Kasir RME** atau form pembayaran | **Ditolak** |
| **Kasir / Dokter** | Bayar tagihan dengan jumlah **parsial** | **Ditolak** (aturan pilot: full payment only) |
| **Semua** | Tagihan dengan tindakan **tanpa lab** | **Tidak** membuat kandidat lab |
| **Admin Lab cabang A** | Buka kandidat dari cabang lain | **Ditolak** / tidak terlihat |

---

## Bukti Screenshot

Ambil screenshot pada titik berikut:

1. Status rekam medis **FINAL** setelah finalisasi
2. Halaman tagihan kasir (invoice RME)
3. Struk/kwitansi pembayaran + ringkasan kandidat lab (jika ada)
4. Detail kandidat lab sebelum konversi
5. Lab order setelah konversi + referensi sumber RME
6. Halaman error (jika ada kegagalan)

---

## Format Laporan Bug

| Field | Isi |
|-------|-----|
| **Role** | Dokter / Kasir / Admin Lab / lainnya |
| **Cabang** | Nama dan kode cabang aktif |
| **Pasien / No. kunjungan** | MRN, nomor kunjungan |
| **No. tagihan RME** | Invoice number |
| **ID / referensi kandidat** | ID atau deskripsi kandidat |
| **No. lab order** | Jika sudah dikonversi |
| **Tindakan** | Langkah yang dilakukan |
| **Hasil diharapkan** | Sesuai checklist |
| **Hasil aktual** | Apa yang terjadi |
| **Screenshot** | Lampiran |
| **Waktu** | Tanggal & jam kejadian |
| **Browser / perangkat** | Chrome/Firefox, desktop/mobile |

---

## Perintah Aman (Server / Lokal)

Jalankan **hanya** jika diperlukan dan sudah disetujui admin:

```bash
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=RoleSeeder
php artisan db:seed --class=RmeSmokeTestSeeder
```

Seeder smoke test **aman dijalankan ulang** — tidak menghapus data yang ada.

---

## Perintah TERLARANG di VPS/Pilot

**Jangan pernah** jalankan:

```bash
php artisan migrate:fresh
php artisan migrate:fresh --seed
php artisan db:seed
php artisan db:wipe
```

Perintah di atas dapat **menghapus atau mengacaukan data pilot**. Gunakan seeder **spesifik** seperti di bagian Perintah Aman.

---

## Catatan

- Checklist smoke test RME dasar (tanpa lab): `docs/pilot/rme_smoke_test_operator_checklist.md`
- Deploy VPS: `docs/pilot/vps_pilot_deployment_checklist.md`
- Rollout seeder aman: `docs/pilot/safe_seeder_rollout.md`
