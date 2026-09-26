# Runbook — Unggah Arsip Legacy dari Kunjungan (Verifikasi Tanggal Sekali)

**Sprint:** REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1
**Rule:** `.cursor/rules/167-legacy-visit-bound-preverified-ingestion.mdc`

---

## Untuk siapa

| Peran | Boleh |
|---|---|
| **Admin Klinik / Front Office** | Unggah arsip legacy dari kunjungan + verifikasi tanggal |
| **Pemeriksa** (pemegang `review_` + `publish_`) | Memeriksa dan menerbitkan |

Petugas yang mengunggah **tidak pernah** menerbitkan dokumennya sendiri
(LEGACY-RME-SOD-1). Itu bukan kekurangan alur ini — itu kontrolnya.

---

## Kapan memakai alur ini

Gunakan **hanya** bila pasien benar-benar datang dan **kunjungan nyata sudah ada**.

**JANGAN PERNAH** membuat kunjungan palsu, kunjungan placeholder, kunjungan
migrasi, atau kunjungan Rp0 hanya agar dokumen lama bisa diunggah. Bila pasien
tidak sedang berkunjung, gunakan jalur **backlog / migrasi massal** yang lama.

---

## Langkah operator (Admin Klinik)

1. Pasien datang; kunjungan dibuat seperti biasa.
2. Buka **Detail Kunjungan**.
3. Pada kartu **Arsip Legacy**, pilih:
   - **Unggah Arsip RME Lama**, atau
   - **Unggah Odontogram Lama**.
4. **Buka dokumen aslinya.** Baca tanggalnya dari kertas — bukan dari nama
   berkas, bukan dari ingatan.
5. Isi tanggal:
   - **RME:** tanggal paling **awal** dan tanggal paling **akhir** pada dokumen.
     Kosongkan tanggal akhir bila dokumen hanya memuat satu tanggal.
   - **Odontogram:** satu tanggal dokumen.
6. Isi **Nomor RM yang tertera pada dokumen** (khusus RME), persis seperti
   tertulis.
7. Pilih berkas PDF.
8. Centang pernyataan:
   > *Saya telah memeriksa dokumen asli dan memastikan tanggal yang dimasukkan
   > sesuai dengan dokumen.*
9. **Verifikasi & Unggah.**
10. Dokumen masuk antrean render, lalu menunggu pemeriksa.

### Aturan tanggal yang akan ditolak server

- Tanggal dokumen **sama dengan** tanggal kunjungan → **ditolak**. Gunakan
  proses review Legacy standar.
- Tanggal dokumen **sesudah** tanggal kunjungan → ditolak.
- Bila pasien punya RME native, seluruh tanggal dokumen harus **lebih awal**
  dari RME pertama di sistem.
- Tanggal tidak boleh mendahului tanggal lahir pasien, dan tidak boleh hari ini
  atau masa depan.

Batas yang **paling ketat** yang berlaku.

---

## Langkah pemeriksa (checker)

Anda **tidak perlu mengetik ulang tanggal.** Tanggal sudah diverifikasi sekali
oleh petugas yang mengunggah dan ditampilkan sebagai bukti **read-only** pada
kartu *"Tanggal Telah Diverifikasi"*, lengkap dengan siapa yang memverifikasi,
kapan, dan pada kunjungan mana.

Tugas Anda, satu kali konfirmasi:

1. Dokumen benar-benar milik pasien ini.
2. Halaman hasil render ada dan terbaca.
3. Tidak ada ketidakcocokan dokumen yang jelas.
4. Tidak ada peringatan validasi pada halaman.

Lalu **Review** → **Publish**.

### Bila Anda menilai tanggalnya SALAH

**Jangan terbitkan.** Tidak ada kolom edit tanggal di tahap ini — dan itu
disengaja. Mengubah tanggal lalu menerbitkan akan membuat bukti verifikasi tidak
lagi cocok dengan catatan klinis yang diterbitkan.

Yang benar:

1. **Batalkan** dokumen (jalur koreksi kanonik).
2. Minta petugas mengunggah ulang dengan tanggal yang benar.
3. Bila dokumen sudah terlanjur terbit, gunakan **VOID + impor ulang** oleh
   pemegang wewenang VOID.

---

## Kenapa penerbitan bisa ditolak di detik terakhir

Server memeriksa ulang bukti verifikasi tepat sebelum menerbitkan. Penerbitan
ditolak bila:

| Penyebab | Arti |
|---|---|
| Berkas sumber berubah | Hash tidak lagi cocok — yang diverifikasi dokumen lain |
| Kunjungan dibatalkan | Kunjungan yang dibatalkan tidak dapat menjadi dasar |
| Kunjungan dihapus | Dasar verifikasi hilang |
| **Tanggal kunjungan berubah** | Bukti **tidak** dialihkan diam-diam ke batas baru |
| Bukti tidak lengkap | Tidak ditebak, tidak diperbaiki — impor ulang |

Semua di atas = batalkan dan impor ulang. Tidak ada jalur perbaikan di tempat.

---

## Deploy

```
php artisan migrate --force
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=RoleSeeder --force
php artisan permission:cache-reset
```

`LEGACY_RME_REQUIRE_SEPARATE_PUBLISHER` tetap `true`. Sprint ini tidak menambah,
mengubah, atau memperkenalkan flag apa pun.
