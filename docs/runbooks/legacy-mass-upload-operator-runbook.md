# Runbook Operator — Mass Upload Arsip Legacy (RME & Odontogram)

**Sprint:** FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1
**Untuk:** Operator migrasi arsip lama (Admin Klinik / petugas yang sudah biasa
melakukan Upload Legacy RME satu per satu) dan engineer on-call.

Semua nama tombol di runbook ini adalah nama yang **benar-benar ada** di
antarmuka. Kalau yang Anda lihat di layar berbeda, berhenti dan laporkan —
jangan menebak.

---

## 0. Ringkasan satu paragraf

Mass Upload memungkinkan Anda mengunggah **banyak arsip lama sekaligus** dalam
satu paket ZIP. Sistem memeriksa setiap baris lebih dahulu, menunjukkan mana
yang layak dan mana yang ditolak beserta alasannya, lalu **Anda** yang memutuskan
untuk memulai. Mass Upload **tidak menerbitkan (publish) dokumen apa pun** —
dokumen hasil mass upload masuk ke tahap tinjau, sama seperti upload satu-satu,
dan tetap diterbitkan oleh petugas berwenang yang berbeda.

Upload satu-satu **tidak dihapus** dan tetap alat yang benar untuk: koreksi satu
pasien, penggantian setelah VOID, retry, dan migrasi manual berjumlah kecil.

---

## 1. Di mana menunya

Sidebar → grup **Import Legacy**:

| Menu | Kegunaan |
|---|---|
| Ringkasan Import Legacy | Ringkasan semua jenis import legacy |
| Upload Legacy Pasien | Impor data master pasien lama (CSV) |
| Upload Legacy RME | Upload **satu** arsip RME |
| Upload Legacy Odontogram | Upload **satu** arsip odontogram |
| **Mass Upload Legacy RME** | Upload **banyak** arsip RME |
| **Mass Upload Legacy Odontogram** | Upload **banyak** arsip odontogram |

Kalau dua menu Mass Upload tidak muncul, penyebabnya satu dari dua hal:
izin Anda tidak mencakup upload arsip lama, atau fitur migrasi arsip lama sedang
tidak aktif pada deployment ini. Mass Upload memakai **izin yang sama** dengan
upload satu-satu (`create_legacy_rme_imports` / `create_legacy_odontogram_imports`)
— tidak ada izin baru yang perlu diminta.

---

## 2. Menyiapkan paket

### 2.1 Isi paket

Satu berkas **ZIP** yang memuat:

1. **`manifest.csv`** — tepat dengan nama itu, di **akar** arsip (bukan di dalam
   subfolder).
2. Seluruh berkas **PDF** yang dirujuk manifest.

Berkas PDF boleh berada di dalam subfolder; yang dicocokkan adalah **nama
berkasnya**, bukan jalurnya.

### 2.2 Kolom manifest — Mass Upload Legacy RME

```
medical_record_number,file_name,rme_date_earliest,rme_date_latest
DG-TLK1-2024-0001,rme-0001.pdf,2018-02-07,2021-11-30
DG-TLK1-2024-0002,rme-0002.pdf,2017-03-04,
```

### 2.3 Kolom manifest — Mass Upload Legacy Odontogram

```
medical_record_number,file_name,document_date
DG-TLK1-2024-0001,odontogram-0001.pdf,2019-04-11
```

Gunakan tombol **Unduh Contoh Manifest** di halaman unggah agar kolomnya pasti
benar.

### 2.4 Aturan yang paling sering dilanggar

**Nomor RM harus LENGKAP.** Tulis `DG-TLK1-2024-0001`, bukan `0001` dan bukan
`2024-0001`. Nomor tidak lengkap akan **ditolak** dengan alasan
`SOURCE_RM_INVALID`. Ini disengaja: pencarian pasien bisa mencocokkan potongan
nomor ke pasien mana pun di cabang mana pun, dan pada 250 baris hal itu adalah
mesin pembuat salah-pasien.

**Satu pasien hanya boleh muncul SATU KALI per manifest.** Kalau arsip kertas
seorang pasien terdiri dari beberapa tahun:

> ❌ `rme-2019.pdf`, `rme-2020.pdf`, `rme-2021.pdf` sebagai tiga baris
> ✅ **gabungkan menjadi satu PDF**, lalu tulis `rme_date_earliest` = tanggal
> paling awal dan `rme_date_latest` = tanggal paling akhir

Satu pasien hanya boleh memiliki **satu** arsip RME aktif dan **satu** arsip
odontogram aktif. Baris kedua untuk pasien yang sama akan ditolak dengan
`DUPLICATE_MANIFEST_PATIENT`.

**Tanggal dibaca manusia, bukan mesin.** Sistem tidak membaca tanggal dari isi
PDF. Tanggal yang Anda tulis di manifest adalah pernyataan Anda bahwa itulah
yang tertera pada dokumen.

**Jangan pernah menulis kolom** `nik`, `ktp`, `patient_id`, atau `branch_id`.
Paket akan ditolak seluruhnya. Cabang ditentukan server dari data pasien.

---

## 3. Alur kerja

```
Unggah Paket  →  Paket diperiksa  →  Tinjau hasil  →  Mulai Upload  →  Tinjau & Publish
                       (otomatis)        (Anda)          (Anda)         (petugas lain)
```

### Langkah 1 — Unggah

Menu Mass Upload → **Unggah Paket Baru** → pilih ZIP → **Unggah dan Periksa**.

Belum ada dokumen yang dibuat pada langkah ini.

### Langkah 2 — Baca hasil pemeriksaan

Halaman batch menampilkan: Total Baris, Layak, Ditolak, Diproses, Gagal Teknis.

Gunakan filter **Status baris** untuk melihat kelompok tertentu. Tombol **Unduh
Laporan** menghasilkan CSV berisi nomor baris, Nomor RM, nama berkas, status,
kode alasan, dan keterangan — aman untuk diteruskan (tidak memuat nama pasien
maupun NIK).

### Langkah 3 — Mulai

Tombol **Mulai Upload (N baris)**. N adalah jumlah baris yang **server**
nyatakan layak.

Menekan tombol ini **tidak bisa** memaksa baris yang ditolak untuk ikut
diproses. Baris yang ditolak tetap ditolak.

### Langkah 4 — Lanjutkan bila perlu

Paket besar diproses **bertahap** agar antrean render tidak jenuh. Bila status
batch masih *Sedang diproses*, tekan **Lanjutkan Proses** sampai selesai.
Menekannya berkali-kali aman — baris yang sudah jadi tidak akan dibuat dua kali.

### Langkah 5 — Tinjau dan terbitkan

Setiap dokumen hasil mass upload masuk ke daftar **Upload Legacy RME** /
**Upload Legacy Odontogram** dalam tahap tinjau. Penerbitan dilakukan per
dokumen oleh petugas berwenang yang **berbeda dari pengunggah**. Mass Upload
tidak mengubah aturan itu sama sekali.

---

## 4. Arti status batch

| Status | Arti | Tindakan |
|---|---|---|
| Paket diunggah / Memvalidasi paket | Sedang diperiksa | Tunggu |
| **Siap ditinjau** | Pemeriksaan selesai | Baca hasil, lalu Mulai Upload |
| **Paket ditolak** | Arsip tidak bisa dipakai | Perbaiki ZIP/manifest, unggah ulang |
| Dikonfirmasi / Sedang diproses | Pembuatan dokumen berjalan | Lanjutkan Proses |
| **Selesai** | Semua baris layak sudah dibuat | Lanjut ke tahap tinjau |
| **Selesai dengan baris ditolak** | **Normal.** Sebagian ditolak | Tangani baris ditolak satu-satu |
| Dibatalkan | Dibatalkan sebelum ada dokumen | — |
| Gagal | Kegagalan teknis setelah mulai | Hubungi engineer |

> *Selesai dengan baris ditolak* adalah hasil **wajar** untuk arsip nyata —
> bukan kegagalan. Arsip 250 pasien hampir selalu memuat beberapa pasien yang
> sudah punya dokumen.

---

## 5. Arti kode alasan per baris

### Paket ditolak seluruhnya (tidak ada baris yang diproses)

| Kode | Perbaikan |
|---|---|
| `MANIFEST_MISSING` | Letakkan `manifest.csv` di akar ZIP |
| `MANIFEST_HEADER_INVALID` | Kolom tidak sesuai — unduh contoh manifest |
| `MANIFEST_HEADER_FORBIDDEN` | Hapus kolom `nik`/`ktp`/`patient_id`/`branch_id` |
| `MANIFEST_FILE_MISSING` | Ada berkas yang dirujuk manifest tapi tidak ada di ZIP |
| `MANIFEST_DUPLICATE_FILE` | Satu berkas dirujuk dua baris |
| `MANIFEST_EMPTY` | Manifest tidak punya baris data |
| `PACKAGE_UNREADABLE` / `PACKAGE_NOT_ZIP` | ZIP rusak atau bukan ZIP |
| `PACKAGE_UNSAFE_PATH` / `PACKAGE_ABSOLUTE_PATH` / `PACKAGE_SYMLINK_ENTRY` | Arsip memuat jalur/tautan tidak aman — buat ulang ZIP dari folder bersih |
| `PACKAGE_DUPLICATE_ENTRY` | Dua berkas dengan nama sama di subfolder berbeda |
| `PACKAGE_TOO_MANY_ENTRIES` / `PACKAGE_UNCOMPRESSED_TOO_LARGE` | Pecah menjadi beberapa paket |
| `FILE_NOT_PDF` | Ada berkas berekstensi .pdf yang isinya bukan PDF |

### Baris tertentu ditolak (baris lain tetap lanjut)

| Kode | Arti | Perbaikan |
|---|---|---|
| `SOURCE_RM_INVALID` | Nomor RM tidak lengkap / tidak sah | Tulis Nomor RM **lengkap** |
| `SOURCE_RM_MISMATCH` | Nomor RM merujuk pasien lain | Periksa ulang dokumennya |
| `PATIENT_NOT_FOUND` | Nomor RM tidak ada | Periksa data master pasien |
| `PATIENT_AMBIGUOUS` | Nomor RM cocok >1 pasien | Masalah data master — eskalasi |
| `PATIENT_NOT_AUTHORIZED` / `BRANCH_MISMATCH` | Pasien di luar cakupan Anda | Minta operator cabang tersebut |
| `BRANCH_NOT_ADMITTED` | Cabang belum dibuka untuk migrasi | Eskalasi ke pengelola migrasi |
| `ALREADY_PUBLISHED` | Pasien sudah punya arsip terbit | Koreksi = VOID lalu upload satu-satu |
| `ACTIVE_IMPORT_EXISTS` | Sudah ada proses impor aktif | Selesaikan/batalkan yang lama dulu |
| `DUPLICATE_MANIFEST_PATIENT` | Pasien muncul 2× di manifest ini | Gabungkan jadi satu PDF (lihat §2.4) |
| `DUPLICATE_SOURCE` | Dokumen identik dengan baris lain | Hapus salah satu |
| `DATE_MISSING` / `DATE_INVALID` / `DATE_RANGE_INVALID` | Tanggal kosong/salah | Perbaiki manifest |
| `NATIVE_RME_BOUNDARY` / `NATIVE_ODONTOGRAM_BOUNDARY` | Tanggal melanggar batas rekam elektronik | Periksa tanggal dokumen |
| `DOMAIN_REFUSED` | Ditolak aturan lain (pesannya menyusul di kolom keterangan) | Baca keterangan |
| `PROCESSING_ERROR` | Kegagalan teknis | Ulangi; bila berulang eskalasi |

---

## 6. Retry, koreksi dan pembatalan

**Retry teknis.** Baris dengan `PROCESSING_ERROR` dapat diproses lagi lewat
**Lanjutkan Proses**. Ini **tidak** membuat siklus dokumen baru — dokumen yang
sama yang diulang.

**Batalkan batch.** Tombol **Batalkan Batch** hanya tersedia **sebelum** ada
dokumen yang dibuat. Setelah ada dokumen, tombol itu tidak muncul lagi dan
server menolaknya. Ini disengaja: menarik kembali dokumen yang sudah ada adalah
**VOID** pada dokumen itu, dengan wewenang tersendiri — bukan efek samping dari
membatalkan satu baris antrean kerja.

**Koreksi dokumen yang sudah terbit.** VOID dokumen tersebut, lalu unggah
penggantinya lewat **Upload Legacy RME** satu-satu. Jangan menunggu mass upload
untuk ini.

---

## 7. Untuk engineer on-call

```bash
# Antrean render yang dipakai mass upload = antrean kanonik yang sudah ada.
# Mass upload TIDAK menambah antrean baru.
php artisan queue:failed

# Status migrasi & kesiapan
php artisan foundation:roadmap-check --strict
```

Fakta yang perlu diketahui saat menangani insiden:

- Mass upload **tidak** punya mekanisme kunci sendiri. Setiap dokumen dibuat
  lewat `createFromUpload()` kanonik, yang mengambil advisory lock PostgreSQL
  dan mengevaluasi ulang semua gate. Duplikasi dokumen tidak mungkin terjadi
  dari sisi mass upload.
- ZIP, manifest, dan PDF hasil ekstraksi disimpan di disk **privat**
  (`legacy_mass_upload_private`), tidak pernah di webroot. Direktori kerja
  dibersihkan saat batch selesai, ditolak, atau dibatalkan.
- Laporan CSV tidak memuat nama pasien maupun NIK.

---

## 8. Yang TIDAK dilakukan Mass Upload

- Tidak menerbitkan (publish) dokumen.
- Tidak melakukan VOID.
- Tidak membuat kunjungan (visit) palsu untuk meloloskan impor.
- Tidak menambah izin atau memperluas akses cabang.
- Tidak menghapus atau menggantikan Upload Legacy satu-satu.
- Tidak memberlakukan kuota harian baru.

---

## 9. "Request Entity Too Large" (HTTP 413)

BUGFIX-MASS-UPLOAD-REQUEST-ENTITY-TOO-LARGE-1.

### Apa artinya

Pesan ini **bukan** keputusan aplikasi. Aplikasi menolak paket yang terlalu
besar dengan pesan yang bisa ditindaklanjuti:

> Ukuran paket arsip melebihi batas yang diizinkan.

Jika yang muncul justru `413 Request Entity Too Large`, permintaan **belum
pernah sampai ke aplikasi** — satu lapis runtime (nginx atau PHP-FPM)
menolaknya lebih dulu. Karena itu tidak ada catatan di log aplikasi, dan
melaporkannya sebagai bug Mass Upload akan menyesatkan.

Batas yang berlaku setelah perbaikan ini:

| Ukuran paket | Hasil |
|---|---|
| ≤ 500 MiB | diterima; preflight berjalan |
| 500–512 MiB | sampai ke aplikasi → "Ukuran paket arsip melebihi batas yang diizinkan." |
| > 512 MiB | ditolak nginx dengan 413 |

### Cara mendiagnosis — sebutkan lapisannya, jangan menduga

Jalankan di VPS, berurutan. Hentikan di lapisan pertama yang gagal.

**1. Ukuran paket yang sebenarnya ditolak** (angkanya ada di log nginx, jadi
tidak perlu menebak):

```
sudo grep "too large body" /var/log/nginx/error.log | tail -5
```

Barisnya memuat jumlah byte, rute, dan host. Contoh kegagalan yang memicu
perbaikan ini: `2860825 bytes` pada
`POST /settings/rme/legacy-mass-imports`.

**2. Batas nginx yang berlaku:**

```
sudo nginx -T | grep -c client_max_body_size
```

- `0` → direktifnya **tidak dideklarasikan di mana pun**, sehingga nginx memakai
  **default bawaan 1 MiB**. Ini penyebab aslinya. Pasang kembali
  `include /var/www/asia-dental-lab-v2/deploy/nginx/upload-body-size.conf;`
  di dalam blok `server` DaengtisiaMS, lalu
  `sudo nginx -t && sudo systemctl reload nginx`.
- `1` atau lebih → periksa nilainya; harus `512m`.

**3. Batas PHP-FPM yang berlaku** (gunakan konfigurasi FPM, **bukan** `php -i`
dari CLI — CLI di host ini adalah PHP 8.5 sedangkan aplikasi berjalan di pool
PHP 8.3, jadi nilai CLI tidak mewakili apa pun):

```
sudo php-fpm8.3 -tt 2>&1 | grep -iE 'upload_max_filesize|post_max_size'
```

Harus `upload_max_filesize = 500M` dan `post_max_size = 512M`. Jika kosong, pool
sedang mewarisi php.ini bawaan distribusi (`2M` / `8M`) — pasang ulang
`deploy/php-fpm/daengtisiams.conf` dan reload `php8.3-fpm`.

### Yang TIDAK boleh dilakukan

- **Jangan** menyetel `client_max_body_size 0` atau membuang batas PHP. Ini
  dokumen klinis; unggahan arsip tanpa batas adalah celah habisnya disk dan
  habisnya worker.
- **Jangan** menaikkan batas aplikasi (`package.max_bytes`) hanya karena runtime
  mengizinkan lebih besar. Batas aplikasi adalah acuan; runtime mengikutinya.
- **Jangan** hanya memperbaiki nginx. Pool mewarisi php.ini, dan php.ini bawaan
  hanya `upload_max_filesize = 2M`. Menaikkan nginx saja memindahkan kegagalan
  satu lapis ke bawah, di mana PHP membuang berkasnya secara diam-diam dan
  aplikasi menampilkan pesan yang menyesatkan: "Paket arsip ZIP wajib diunggah."
- **Jangan** menyunting php.ini atau berkas nginx langsung di VPS sebagai
  perbaikan permanen. Sumber yang dikelola ada di repositori
  (`deploy/php-fpm/daengtisiams.conf` dan
  `deploy/nginx/upload-body-size.conf`); suntingan manual bisa terhapus oleh
  deploy berikutnya.

### Setelah batas diperbaiki

Unggah ulang paket yang sebelumnya gagal **hanya sampai tahap preflight /
tinjauan**. Jangan menekan konfirmasi atau mulai proses sampai hasil tinjauan
dibaca — konfirmasi akan membuat dokumen klinis.
