# Checklist Operator — Smoke Test RME Pilot

## Tujuan

Dokumen ini panduan **uji coba smoke test** alur RME (Rekam Medis Elektronik) untuk pilot klinik. Tujuannya memastikan alur dasar RME berjalan sebelum atau selama uji coba pilot, tanpa bantuan developer.

Data uji coba **bukan data pasien nyata**. Semua label mengandung kata "SMOKE TEST".

---

## Pra-pengecekan

Sebelum mulai, pastikan:

- [ ] URL aplikasi pilot benar (contoh: URL VPS yang diberikan admin IT).
- [ ] Akun login smoke test tersedia (lihat bagian Akun Uji Coba).
- [ ] Cabang aktif benar: **Klinik Gigi Daengtisia Pusat** (kode `MAIN`).
- [ ] `PermissionSeeder` sudah dijalankan di server.
- [ ] `RoleSeeder` sudah dijalankan di server.
- [ ] `RmeSmokeTestSeeder` sudah dijalankan di server.
- [ ] Browser siap (Chrome atau Firefox disarankan).
- [ ] Alat screenshot siap (Print Screen atau snipping tool).

---

## Akun & Data Uji Coba

| Item | Nilai |
|------|-------|
| **Password semua akun smoke test** | `SmokeTestPilot!` |
| **Cabang** | Klinik Gigi Daengtisia Pusat (`MAIN`) |
| **Pasien** | PASIEN SMOKE TEST RME (MRN: `MRN-SMOKE-TEST-RME`) |
| **Kunjungan klinik (dokter/perawat)** | `VIS-SMOKE-TEST-RME` — status *sedang berjalan* |
| **Kunjungan kasir** | `VIS-SMOKE-CASHIER-RME` — status *menunggu kasir* |
| **Dokter (master data)** | DOKTER SMOKE TEST |
| **Akun Dokter** | `dokter.smoke@pilot-test.local` |
| **Akun Perawat** | `perawat.smoke@pilot-test.local` |
| **Akun Kasir** | `kasir.smoke@pilot-test.local` |
| **Akun Owner** | `owner.smoke@pilot-test.local` |

---

## Alur Smoke Test Langkah demi Langkah

### A. Login sebagai Perawat

| Langkah | Tindakan | Hasil yang diharapkan |
|---------|----------|----------------------|
| A1 | Buka URL aplikasi dan login sebagai Perawat | Berhasil masuk; nama **PERAWAT SMOKE TEST** terlihat |
| A2 | Buka menu kunjungan RME / antrian kunjungan | Halaman daftar kunjungan terbuka |
| A3 | Cari kunjungan `VIS-SMOKE-TEST-RME` | Kunjungan muncul di daftar |
| A4 | Buka detail kunjungan | Detail pasien **PASIEN SMOKE TEST RME** terlihat |
| A5 | Logout | Kembali ke halaman login |

### B. Login sebagai Dokter

| Langkah | Tindakan | Hasil yang diharapkan |
|---------|----------|----------------------|
| B1 | Login sebagai Dokter | Berhasil masuk; nama **DOKTER SMOKE TEST** terlihat |
| B2 | Buka antrian kunjungan RME | Halaman kunjungan terbuka |
| B3 | Buka kunjungan `VIS-SMOKE-TEST-RME` | Detail kunjungan terbuka |
| B4 | Buka halaman rekam medis kunjungan | Form rekam medis (draft) terbuka |
| B5 | Ubah catatan rekam medis, simpan | Perubahan tersimpan tanpa error |
| B6 | Buka odontogram kunjungan | Halaman odontogram terbuka |
| B7 | Ubah odontogram (mis. catatan ringkas), simpan | Perubahan tersimpan tanpa error |
| B8 | Logout | Kembali ke halaman login |

### C. Login sebagai Kasir

| Langkah | Tindakan | Hasil yang diharapkan |
|---------|----------|----------------------|
| C1 | Login sebagai Kasir | Berhasil masuk; nama **KASIR SMOKE TEST** terlihat |
| C2 | Buka menu Kasir RME / billing | Halaman antrian kasir terbuka |
| C3 | Cari kunjungan `VIS-SMOKE-CASHIER-RME` | Kunjungan muncul (status menunggu kasir) |
| C4 | Buka halaman buat tagihan untuk kunjungan tersebut | Form tagihan terbuka (rekam medis sudah final) |
| C5 | Coba buka menu buat kunjungan baru | **Ditolak** — Kasir tidak boleh membuat kunjungan |
| C6 | Logout | Kembali ke halaman login |

### D. Login sebagai Owner

| Langkah | Tindakan | Hasil yang diharapkan |
|---------|----------|----------------------|
| D1 | Login sebagai Owner | Berhasil masuk |
| D2 | Buka Dasbor | Dasbor owner terbuka |
| D3 | Periksa menu sidebar | Menu operasional terbatas sesuai role (tidak ada akses kasir/lab penuh) |
| D4 | Coba buka menu Kasir RME | **Ditolak** — Owner tidak mengelola billing |
| D5 | Logout | Kembali ke halaman login |

---

## Screenshot jika Ada Masalah

Ambil screenshot berikut:

1. **URL** lengkap di address bar browser.
2. **Halaman error** (jika muncul).
3. **Form sebelum simpan** (isi yang diinput).
4. **Hasil setelah simpan** (sukses atau gagal).
5. **Sidebar/menu** jika menu salah tampil atau hilang.
6. **Nama user/role** yang terlihat di aplikasi.
7. **Browser console** hanya jika Anda tahu cara membukanya dan diminta IT.

---

## Format Laporan Bug

Gunakan format ini saat melaporkan ke IT/developer:

```
Role pengguna    :
Cabang           :
URL              :
Tindakan         :
Hasil diharapkan :
Hasil aktual     :
Screenshot       : (lampirkan)
Waktu kejadian   :
Perangkat/Browser:
Catatan tambahan :
```

---

## Perintah Aman (hanya admin IT/developer)

Jalankan di server setelah deploy, **bukan** oleh operator klinik sehari-hari:

```bash
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=RoleSeeder
php artisan db:seed --class=RmeSmokeTestSeeder
```

Seeder smoke test **aman dijalankan ulang** — tidak menghapus data yang ada.

---

## Perintah TERLARANG di VPS/Pilot

**Jangan pernah** jalankan perintah berikut di database pilot/VPS:

```bash
php artisan migrate:fresh
php artisan db:wipe
php artisan migrate:fresh --seed
```

Perintah di atas **menghapus semua data** dan tidak boleh digunakan di lingkungan pilot.

---

## Catatan

- Smoke test ini **tidak menggantikan** uji coba klinis lengkap dengan pasien nyata.
- Password `SmokeTestPilot!` hanya untuk lingkungan pilot/uji coba.
- Jika akun smoke test belum ada, minta admin IT menjalankan seeder di atas.
