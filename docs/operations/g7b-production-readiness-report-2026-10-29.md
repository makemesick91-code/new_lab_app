---
kb_status: snapshot
kb_verified_against: 755c6bce
kb_updated: 2026-10-29
---

# Laporan Kesiapan Produksi G7-B — Rotasi Application Key (2026-10-29)

Laporan ini mencatat kesiapan rotasi application key produksi per **2026-10-29 22:30 WITA**.
Halaman ini **bukan** persetujuan eksekusi. Sumber kebenaran tetap kode, GO tag git, dan pull request.

Kembali ke [00-INDEX](../00-INDEX.md). Halaman terkait:
[status remediasi keamanan](security-remediation-status-2026-10-27.md) dan
[catatan sprint G7-B](../sprints/security-g7b-app-key-reencryption-1.md).

> Halaman ini sengaja tidak memuat application key, password, isi file environment, nama role
> database, alamat produksi, atau data pasien. Nama file salinan environment juga tidak dicantumkan,
> karena sebagian memuat nama staf; yang dicatat hanya lokasi, jumlah, dan klasifikasinya.

## Kesimpulan

**Rotasi application key produksi tetap NO-GO.** Semua langkah yang bisa dikerjakan tanpa custodian
sudah selesai dan terverifikasi. Yang tersisa adalah keputusan dan tindakan owner:

1. menunjuk custodian kedua dari manajemen, lalu melakukan seremoni escrow key lama;
2. memutuskan retensi 27 salinan file environment yang masih memuat key yang sedang aktif;
3. memberi persetujuan eksekusi rotasi yang terpisah.

## Otoritas produksi

| Item | Nilai |
|---|---|
| Commit produksi | `755c6bce` |
| GO tag | `post-audit-security-release-1-go` |
| Deploy | 2026-10-29 11:09 WITA, lewat runner deploy resmi; `DEPLOY OK`, tanpa migrasi |
| Perintah re-enkripsi | `security:app-key-reencrypt` sudah ada di produksi (dari #534) |

## Status prasyarat

| # | Prasyarat | Status | Bukti |
|---|---|---|---|
| 1 | Escrow key lama | **BELUM** — tindakan owner | Butuh custodian kedua dari manajemen dan seremoni oleh manusia. Prosedur di bawah. |
| 2 | Pemulihan backup sebelum rotasi | **Terverifikasi** | Backup sebelum deploy dipulihkan ke database sementara (174 tabel, 10 detik). Tanpa key lama: 8 nilai tidak terbaca. Dengan key lama sebagai previous key: 8 nilai terbaca. |
| 3 | Migrasi data terenkripsi | **Terverifikasi** | Pada salinan data produksi: 8 nilai ditulis ulang ke key baru. Dry run di produksi: 8 nilai, semuanya di key saat ini, 0 tidak terbaca. |
| 4 | Pemulihan setelah key lama dilepas | **Terverifikasi** | Pada salinan: key baru saja membaca 8 nilai; key lama saja tidak membaca satu pun, jadi penulisan ulang benar-benar terjadi. |
| 5 | Login dokter dan pairing perangkat | Terverifikasi dalam CI | Uji terisolasi #534. Di jendela rotasi: satu login dokter dan satu verifikasi perangkat setelah konfigurasi dibangun ulang. |
| 6 | Payment intent | Terverifikasi dalam CI | Form pembayaran yang dibuka sebelum rotasi ditolak lalu dibuka ulang, tidak pernah dibayar dua kali. Di jendela: tidak ada form kasir terbuka. |
| 7 | Tidak ada transaksi klinis atau kasir aktif | Diperiksa di jendela | Referensi 2026-10-29 11:08 WITA: 0 sesi aktif dalam 10 menit, 0 kunjungan sedang diperiksa. |
| 8 | Queue dan worker | **Terverifikasi** | Worker aktif di rilis `755c6bce`, 0 job antre, 0 job gagal. Worker di-restart di jendela setelah konfigurasi dibangun ulang. |
| 9 | Rollback | **Terverifikasi pada salinan** | Rollback memindahkan 8 nilai kembali ke key lama, lalu key lama saja membaca semuanya. Tool rotasi belum dipasang di host; dipasang di direktori tool khusus root saat eksekusi disiapkan. |
| 10 | Laporan kesiapan final | Halaman ini | — |

## Gladi rotasi pada salinan data produksi

Dijalankan 2026-10-29 11:16 WITA di host produksi, terhadap database sementara yang dipulihkan dari
backup sebelum deploy. Database produksi, konfigurasinya, dan key-nya tidak diubah.

- Key lama dibaca dari file environment ke variabel shell, dan key baru dibuat di memori. Keduanya
  hanya diteruskan lewat environment proses, tidak pernah lewat argumen perintah, dan tidak pernah
  dicetak atau ditulis ke file.
- Sebelum ada penulisan, dua pengaman harus lulus: proses terhubung ke database sementara, dan override
  key benar-benar berlaku.
- Database sementara dihapus di akhir. Tidak ada yang tersisa.

| Langkah | Key yang dikonfigurasi | Hasil |
|---|---|---|
| A0 | key baru saja, pada backup sebelum rotasi | 8 tidak terbaca |
| A | key baru, key lama sebagai previous | 8 terbaca sebagai "previous" |
| B | apply | 8 ditulis ulang |
| C | key baru saja | 8 terbaca; key lama boleh dilepas |
| D | key lama saja | 8 tidak terbaca |
| R1 | rollback: key lama, key baru sebagai previous, apply | 8 ditulis ulang kembali |
| R2 | key lama saja, setelah rollback | 8 terbaca |

Setelah gladi: 0 baris audit rotasi di database produksi, dry run produksi tidak berubah, dan log
aplikasi tidak bertambah.

## Prosedur escrow dual-custodian (usulan)

Escrow harus selesai **sebelum** rotasi. Tujuannya agar backup yang diambil sebelum rotasi tetap bisa
dipulihkan selama masa retensinya, tanpa membiarkan key lama tersimpan di host.

| Peran | Siapa | Media |
|---|---|---|
| Custodian utama (IT) | Usulan: Raushan Fikri Ridha (IT), konsisten dengan custodian 1 signing Android. Perlu konfirmasi owner. | Vault terenkripsi di workstation IT |
| Custodian kedua (manajemen) | **Ditunjuk owner.** Harus orang di luar IT. | Media terenkripsi terpisah, disimpan di kantor manajemen |

Langkah:

1. Di host, key lama dienkripsi dua kali secara terpisah, satu per custodian. Passphrase diketik
   langsung oleh masing-masing custodian di terminal, tidak pernah lewat argumen perintah, chat, atau
   file.
2. Masing-masing salinan terenkripsi dipindahkan ke media custodian. Salinan di host dihapus setelah
   pemindahan terverifikasi.
3. **Uji pemulihan per custodian**: salinan dibawa kembali, didekripsi di memori, lalu dipakai sebagai
   previous key untuk membaca salinan sementara dari backup sebelum rotasi (langkah A pada gladi). Lulus
   bila 8 nilai terbaca. Key tidak pernah dicetak.
4. Dicatat siapa, kapan, dan di media mana; tanpa nilai key.
5. Setelah seluruh backup sebelum rotasi melewati masa retensinya, owner memutuskan apakah salinan
   escrow dimusnahkan.

## Klasifikasi salinan file environment

Diukur 2026-10-29, hanya membandingkan nilai secara internal di host. Tidak ada nilai yang dicetak.

| Lokasi | Jumlah | Mode | Rentang tanggal | Rahasia hidup yang dimuat |
|---|---:|---|---|---|
| Root aplikasi | 10 | 0600, pemilik root | 2026-08-14 s.d. 2026-09-17 | application key |
| `storage/backups` | 1 | 0600; direktori 2770 | 2026-06-10 | application key |
| Direktori backup deploy privat | 10 | 0640, pemilik user runtime; direktori 2750 | 2026-06-28 s.d. 2026-07-01 | application key |
| Direktori home root (**belum tercatat di catatan G7-B**) | 6 | 0600 / 0640; direktori induk 0700 root | 2026-08-16 s.d. 2026-10-09 | application key |
| Direktori aplikasi lama | 1 | 0644, direktori induk 0700 root, tidak dilayani web server | 2026-06-03 | tidak ada (key berbeda, password database sudah tidak berlaku) |

Catatan:

- Total **28** salinan, bukan 21. Enam salinan di direktori home root belum masuk hitungan sebelumnya.
- 27 salinan memuat application key yang sedang aktif. Selain itu, mereka tidak memuat rahasia hidup
  lain: password database di semua salinan adalah password lama yang sudah dirotasi di G7-A, password
  mail dan Redis berisi placeholder `null`, dan kredensial object storage kosong.
- Tidak ada salinan yang bisa dibaca akun lokal lain di luar root dan grup runtime. Grup runtime sudah
  memegang key yang sama lewat file environment aktif, jadi tidak ada paparan tambahan saat ini.
- Setelah rotasi, ke-27 salinan menjadi pemegang key lama. Key lama hanya boleh berada di escrow,
  sehingga salinan ini tidak lagi dibutuhkan untuk pemulihan.

**Usulan retensi (keputusan owner):** setelah escrow terverifikasi, musnahkan ke-27 salinan pada jendela
rotasi, sebelum key lama dinyatakan pensiun. Salinan di direktori aplikasi lama tidak mendesak; bisa
ikut keputusan arsip direktori lama tersebut.

## Keputusan owner yang dibutuhkan

| Keputusan | Status |
|---|---|
| Custodian utama IT | Usulan tersedia, perlu konfirmasi |
| Custodian kedua dari manajemen | **Belum ditunjuk** |
| Retensi 27 salinan pemegang key | **Belum diputuskan** |
| Retensi direktori aplikasi lama | Belum diputuskan, tidak mendesak |
| Persetujuan eksekusi rotasi | **Belum diberikan** |

## Larangan yang tetap berlaku

- Jangan merotasi application key produksi tanpa persetujuan eksekusi terpisah.
- Jangan menjalankan `security:app-key-reencrypt --apply` di produksi di luar jendela rotasi.
- Jangan menghapus key lama sebelum escrow terverifikasi dan seluruh dependensinya aman.
- Jangan menyimpan application key di repository, laporan, knowledge base, CI, atau chat.
