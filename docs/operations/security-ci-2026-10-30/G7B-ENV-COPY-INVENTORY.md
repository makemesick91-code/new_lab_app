---
kb_status: snapshot
kb_verified_against: fb3726c5
kb_updated: 2026-10-30
---

# G7-B — Inventaris Rahasia Salinan Environment per Salinan (2026-10-30)

Inventaris ini mendaftar **setiap** salinan file environment di host produksi yang memuat baris application
key, termasuk anggota arsip. Kembali ke [00-INDEX](../../00-INDEX.md) atau ke
[G7B-SECURITY-GATE-CLOSURE](G7B-SECURITY-GATE-CLOSURE.md).

> **Rahasia dijaga di host.** Setiap baris memakai id opak (`C..` file biasa, `A..` anggota arsip).
> Pemetaan id ke path hanya ada di manifest root-only di host produksi:
> direktori `/root/security-g7b-inventory-20261030/` (mode 0700), file `manifest.json` (mode 0600,
> sha256 awalan `dcfa98f5540e25b6`). Halaman ini tidak memuat path, nama file, nilai key, identitas key,
> password, atau isi file. Nama file sebagian salinan memuat nama staf, karena itu tidak dicantumkan.

## Cara mengukur

- Tool: `g7b-confidential-inventory.py` (sha256 awalan `a56c27794a9bdccb`, sumber di
  [G7B-SECURITY-GATE-TOOLS](G7B-SECURITY-GATE-TOOLS.md)), dijalankan sebagai root, 2026-10-30 08:24 WITA,
  dengan `nice`/`ionice` kelas idle.
- Lokasi yang dipindai sama dengan inventaris 2026-10-30 01:11 WITA, ditambah direktori backup deploy lama.
- Nilai key hanya dibandingkan di memori proses. Label `LIVE` = sama dengan key aktif; `OTHER-n` = key lain
  yang berbeda (3 key berbeda); `EMPTY` = baris key tanpa nilai.
- "Dirujuk runtime" = path muncul di konfigurasi systemd, nginx, PHP-FPM, cron, atau supervisor, **atau**
  sedang dibuka oleh proses mana pun.
- "Terbaca akun lain" memeriksa bit mode file **dan** seluruh direktori induknya.
- Pemilik: pemilik file di sistem. Pemilik akuntabel untuk semua salinan adalah custodian IT setelah
  ditunjuk owner.

## Ringkasan

| Kategori | Jumlah |
|---|---:|
| Baris total | 47 |
| Memuat key aktif (`LIVE`) | 32 (27 file biasa + 5 anggota arsip) |
| Memuat key lain (`OTHER-1..3`) | 6 (3 file biasa + 3 anggota arsip) |
| Baris key kosong | 9 (5 file biasa + 4 anggota arsip) |
| Terbaca akun lain | 2, keduanya `EMPTY` |
| Dirujuk runtime | 0 |
| Memuat password database yang berlaku | 0 |
| Arsip dengan anggota file environment | 7 |
| Dump database yang dipindai | 72, 0 gagal dibaca |
| Dump yang membutuhkan key selain key aktif | 0 |

Satu-satunya salinan **aktif** adalah file environment aktif aplikasi, yang tidak termasuk tabel ini dan
tidak pernah disentuh rencana penghapusan.

## Tabel per salinan

Kolom mode berformat `file/induk`. Bulan = bulan modifikasi terakhir (UTC). Status persetujuan semua baris
adalah `PENDING_OWNER`: hanya owner yang menyetujui retensi atau penghapusan.

| Id | Jenis | Lokasi | Pemilik | Mode | Bulan | Key | Terbaca akun lain | Dirujuk runtime | Relevansi pemulihan | Usulan retensi | Persetujuan |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `C01` | File | Root aplikasi | root | 600/755 | 2026-08 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C02` | File | Root aplikasi | root | 600/755 | 2026-09 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C03` | File | Root aplikasi | root | 600/755 | 2026-08 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C04` | File | Root aplikasi | root | 600/755 | 2026-08 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C05` | File | Root aplikasi | root | 600/755 | 2026-09 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C06` | File | Root aplikasi | root | 644/755 | 2026-08 | EMPTY | Ya | Tidak | Tidak memuat key | Hapus atau simpan sebagai template — keputusan owner | PENDING_OWNER |
| `C07` | File | Root aplikasi | root | 600/755 | 2026-09 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C08` | File | Root aplikasi | root | 600/755 | 2026-09 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C09` | File | Root aplikasi | root | 600/755 | 2026-09 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C10` | File | Root aplikasi | root | 600/755 | 2026-09 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C11` | File | Root aplikasi | root | 600/755 | 2026-09 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C12` | File | Root aplikasi | root | 644/755 | 2026-06 | EMPTY | Ya | Tidak | Tidak memuat key | Hapus atau simpan sebagai template — keputusan owner | PENDING_OWNER |
| `C13` | File | Direktori aplikasi lama | root | 644/700 | 2026-06 | OTHER-1 | Tidak | Tidak | Tidak ada dump di host atau baris produksi yang memakai key ini | Hapus atau segel — keputusan owner (tidak mendesak) | PENDING_OWNER |
| `C14` | File | Direktori aplikasi lama | root | 644/700 | 2026-06 | EMPTY | Tidak | Tidak | Tidak memuat key | Hapus atau simpan sebagai template — keputusan owner | PENDING_OWNER |
| `C15` | File | Direktori aplikasi lama | root | 644/755 | 2026-06 | EMPTY | Tidak | Tidak | Tidak memuat key | Hapus atau simpan sebagai template — keputusan owner | PENDING_OWNER |
| `C16` | File | Home root | root | 640/755 | 2026-07 | OTHER-2 | Tidak | Tidak | Tidak ada dump di host atau baris produksi yang memakai key ini | Hapus atau segel — keputusan owner (tidak mendesak) | PENDING_OWNER |
| `C17` | File | Home root | root | 644/755 | 2026-07 | EMPTY | Tidak | Tidak | Tidak memuat key | Hapus atau simpan sebagai template — keputusan owner | PENDING_OWNER |
| `C18` | File | Home root | root | 644/755 | 2026-07 | OTHER-3 | Tidak | Tidak | Tidak ada dump di host atau baris produksi yang memakai key ini | Hapus atau segel — keputusan owner (tidak mendesak) | PENDING_OWNER |
| `C19` | File | Home root | root | 640/700 | 2026-08 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C20` | File | Home root | root | 640/700 | 2026-08 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C21` | File | Home root | root | 600/700 | 2026-08 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C22` | File | Home root | root | 600/700 | 2026-08 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C23` | File | Home root | root | 600/700 | 2026-10 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C24` | File | Home root | root | 600/700 | 2026-08 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C25` | File | Backup deploy privat | daengtisiams | 640/750 | 2026-06 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C26` | File | Backup deploy privat | daengtisiams | 640/750 | 2026-06 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C27` | File | Backup deploy privat | daengtisiams | 640/750 | 2026-06 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C28` | File | Backup deploy privat | daengtisiams | 640/750 | 2026-06 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C29` | File | Backup deploy privat | daengtisiams | 640/750 | 2026-06 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C30` | File | Backup deploy privat | daengtisiams | 640/750 | 2026-06 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C31` | File | Backup deploy privat | daengtisiams | 640/750 | 2026-06 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C32` | File | Backup deploy privat | daengtisiams | 640/750 | 2026-07 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C33` | File | Backup deploy privat | daengtisiams | 640/750 | 2026-07 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C34` | File | Backup deploy privat | daengtisiams | 640/750 | 2026-07 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `C35` | File | `storage/backups` | daengtisiams | 600/770 | 2026-06 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `A01` | Arsip | Home root | root | 644/700 | 2026-06 | OTHER-1 | Tidak | Tidak | Tidak ada dump di host atau baris produksi yang memakai key ini | Hapus atau segel — keputusan owner (tidak mendesak) | PENDING_OWNER |
| `A02` | Arsip | Home root | root | 644/700 | 2026-06 | EMPTY | Tidak | Tidak | Tidak memuat key | Hapus atau simpan sebagai template — keputusan owner | PENDING_OWNER |
| `A03` | Arsip | Home root | root | 644/755 | 2026-08 | OTHER-2 | Tidak | Tidak | Tidak ada dump di host atau baris produksi yang memakai key ini | Hapus atau segel — keputusan owner (tidak mendesak) | PENDING_OWNER |
| `A04` | Arsip | Home root | root | 644/755 | 2026-08 | EMPTY | Tidak | Tidak | Tidak memuat key | Hapus atau simpan sebagai template — keputusan owner | PENDING_OWNER |
| `A05` | Arsip | Home root | root | 644/755 | 2026-08 | OTHER-2 | Tidak | Tidak | Tidak ada dump di host atau baris produksi yang memakai key ini | Hapus atau segel — keputusan owner (tidak mendesak) | PENDING_OWNER |
| `A06` | Arsip | Home root | root | 644/755 | 2026-08 | EMPTY | Tidak | Tidak | Tidak memuat key | Hapus atau simpan sebagai template — keputusan owner | PENDING_OWNER |
| `A07` | Arsip | Home root | root | 644/755 | 2026-08 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `A08` | Arsip | Home root | root | 644/755 | 2026-08 | EMPTY | Tidak | Tidak | Tidak memuat key | Hapus atau simpan sebagai template — keputusan owner | PENDING_OWNER |
| `A09` | Arsip | Home root | root | 644/755 | 2026-08 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `A10` | Arsip | Backup deploy privat | daengtisiams | 640/750 | 2026-06 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `A11` | Arsip | Backup deploy privat | daengtisiams | 640/750 | 2026-06 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |
| `A12` | Arsip | Backup deploy privat | daengtisiams | 640/750 | 2026-06 | LIVE | Tidak | Tidak | Tidak dibutuhkan setelah dua salinan escrow lulus recovery check | Hapus per path di jendela rotasi, setelah escrow terverifikasi | PENDING_OWNER |

## Aturan eksekusi saat retensi disetujui

1. Penghapusan hanya dijalankan **setelah** dua salinan escrow lulus recovery check, dan di jendela rotasi.
2. Hapus per path dari manifest root-only, satu baris per id, bukan dengan glob. File environment aktif tidak
   pernah masuk daftar.
3. Untuk arsip, owner memilih: buang seluruh arsip lama, atau simpan sampai masa retensi. Isi arsip tidak
   diubah.
4. Di disk virtual, penimpaan isi file tidak menjamin data hilang dari lapisan penyimpanan dan snapshot
   penyedia. Kontrol utamanya adalah rotasi: sesudah rotasi, salinan berkey `LIVE` hanya memuat key yang
   sudah pensiun.
5. Verifikasi dengan menjalankan ulang inventaris: 0 baris `LIVE`, dan sidik jari set path berubah sesuai
   path yang dihapus.
