# Cabang Sunu — checklist pasien REAL pertama

**Audience: the Sunu front desk, the treating doctor and the cashier, with the
owner supervising the first run.**

This is the first patient after the fictional estate was removed. Everything
below is performed with a **real patient and real clinical information**. No step
asks anyone to invent data, and no step should be performed "just to test".

If any step behaves differently from what is written here, **stop and report it**
before continuing with the patient. Do not work around it.

---

## Sebelum mulai (owner / supervisor)

| # | Langkah | Yang diharapkan |
|---|---------|-----------------|
| 0.1 | Login sebagai Admin Sunu | Masuk tanpa diminta perangkat / WebAuthn |
| 0.2 | Lihat pemilih cabang | Hanya **Cabang Sunu (SPN4)** yang tersedia |
| 0.3 | Buka daftar pasien | Kosong — ini benar, bukan error |

Cabang Sunu terkunci ke SPN4 di sisi server. Admin Sunu **tidak bisa** berpindah
ke Landak / Antang / Telkomas, dan itu memang disengaja.

---

## 1. Pendaftaran pasien

| # | Langkah | Yang diharapkan |
|---|---------|-----------------|
| 1.1 | Daftarkan pasien REAL (nama, tanggal lahir, kontak sebenarnya) | Tersimpan |
| 1.2 | Isi **Nomor RM** sesuai penomoran klinik | Nomor RM diketik manual oleh petugas — sistem tidak membuat urutan otomatis |
| 1.3 | Periksa Nomor RM yang tersimpan | Berbentuk `DG-SPN4-2026-…` |
| 1.4 | Periksa cabang pasien | **Cabang Sunu**, bukan MAIN dan bukan cabang lain |

> Pencarian pasien bersifat **global** (identitas pasien lintas cabang). Itu
> normal dan berbeda dari cakupan operasional cabang.

## 2. Kunjungan & antrian

| # | Langkah | Yang diharapkan |
|---|---------|-----------------|
| 2.1 | Buat kunjungan baru untuk pasien tersebut | Cabang terisi **Sunu** otomatis |
| 2.2 | Buka **Antrian Pasien** | Kunjungan muncul |
| 2.3 | Pilih ruangan | Hanya **Ruangan A** / **Ruangan B** yang tersedia |

Tanpa ruangan, dokter **tidak** bisa membuka Rekam Medis / Odontogram — itu
gerbang yang disengaja, bukan kerusakan.

## 3. Pemeriksaan dokter

| # | Langkah | Yang diharapkan |
|---|---------|-----------------|
| 3.1 | Dokter membuka kunjungan | Terbuka setelah ruangan terisi |
| 3.2 | Lengkapi **Consent** | Wajib sebelum lanjut |
| 3.3 | Tulis Rekam Medis (tulisan tangan) | Tersimpan; tanda tangan/handwriting wajib saat finalisasi |
| 3.4 | Odontogram bila memang dilakukan | Tersimpan pada kunjungan ini |
| 3.5 | **Selesai Pemeriksaan** | Status menjadi *Selesai Pemeriksaan* (masuk kasir) |

Dokter **tidak** bisa menandai *Selesai Visit* langsung — hanya pembayaran kasir
yang menutup kunjungan.

## 4. Kasir

| # | Langkah | Yang diharapkan |
|---|---------|-----------------|
| 4.1 | Buat tagihan sesuai tindakan yang BENAR-BENAR dilakukan | Tersimpan |
| 4.2 | Catat pembayaran sebenarnya | Lunas → kunjungan **Selesai**; sebagian → kunjungan tetap selesai, sisa menjadi piutang |
| 4.3 | Cetak struk | Tercetak |

## 5. Lab (hanya bila memang dibutuhkan secara klinis)

Jangan membuat order lab untuk "mencoba". Bila memang ada indikasi, jalankan alur
Lab Workflow seperti biasa.

## 6. Verifikasi sesudahnya

| # | Langkah | Yang diharapkan |
|---|---------|-----------------|
| 6.1 | Buka riwayat pasien | Kunjungan + rekam medis tampil |
| 6.2 | Buka laporan pasien / pembayaran | Angka sesuai transaksi nyata |
| 6.3 | Konfirmasi ke owner bahwa backup harian berikutnya sudah berjalan | Backup otomatis harian pukul 18:30 UTC |

---

## Kalau ada yang salah

Catat dan laporkan — **jangan** dihapus, jangan diulang dengan data karangan,
dan jangan diperbaiki langsung di database:

- pasien tidak bisa didaftarkan, atau cabangnya bukan Sunu;
- pemilih cabang menawarkan cabang selain Sunu;
- Admin Sunu diminta verifikasi perangkat / WebAuthn (seharusnya **tidak**);
- dokter tidak bisa membuka RME meski ruangan sudah dipilih;
- kasir tidak bisa membuat tagihan setelah pemeriksaan selesai.
