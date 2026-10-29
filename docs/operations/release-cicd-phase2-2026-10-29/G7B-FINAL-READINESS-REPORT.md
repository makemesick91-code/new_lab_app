---
kb_status: snapshot
kb_verified_against: fb3726c5
kb_updated: 2026-10-30
---

# Laporan Kesiapan Final G7-B — Rotasi Application Key (2026-10-30)

**Status: READY_FOR_OWNER_APPROVAL.** Ini **bukan** ROTATION_COMPLETE. Rotasi application key produksi
tetap **NO-GO** sampai owner memberikan persetujuan eksekusi tersendiri. Semua yang bisa disiapkan tanpa
keputusan owner sudah selesai dan diukur ulang pada rilis produksi `fb3726c5`. Yang tersisa adalah
keputusan owner: custodian, retensi, jendela rotasi, dan persetujuan eksekusi.

Laporan ini menggantikan [laporan kesiapan 2026-10-29](../g7b-production-readiness-report-2026-10-29.md)
untuk status terbaru. Laporan lama tetap menjadi bukti gladi rotasi pada salinan data. Kembali ke
[00-INDEX](../../00-INDEX.md). Halaman terkait:
[F4BC-PRODUCTION-RELEASE-REPORT](F4BC-PRODUCTION-RELEASE-REPORT.md),
[G7B-ESCROW-TOOLS](G7B-ESCROW-TOOLS.md) (sumber tool).

> Halaman ini tidak memuat application key, passphrase, password, isi file environment, nama file
> salinan environment (sebagian memuat nama staf), alamat produksi, atau data pasien. Yang dicatat
> hanya lokasi, jumlah, mode, dan klasifikasi.

## Ringkasan status

| # | Pemeriksaan | Status | Bukti singkat |
|---|---|---|---|
| 1 | Prosedur escrow dual-custodian | **PASS** | Prosedur dan tool siap; gladi penuh dua kali di host produksi, termasuk sesudah rilis `fb3726c5` |
| 2 | Custodian utama (IT) ditunjuk | **BLOCKED_BY_OWNER** | Usulan: Raushan Fikri Ridha (IT), konsisten dengan custodian 1 signing Android. Perlu konfirmasi owner. |
| 3 | Custodian kedua (manajemen) ditunjuk | **BLOCKED_BY_OWNER** | Belum ditunjuk. Sengaja tidak diisi tanpa keputusan owner. |
| 4 | Dua salinan escrow terenkripsi terpisah | **PASS** (tool) / **BLOCKED_BY_OWNER** (salinan nyata) | Gladi membuat dua ciphertext independen yang terbukti berbeda; salinan nyata butuh custodian |
| 5 | Pemulihan tiap salinan tanpa mencetak key | **PASS** (gladi) | Verify MATCH untuk dua salinan; recovery check membaca 8/8 nilai dari backup sebelum rotasi |
| 6 | Inventaris ulang salinan environment | **PASS**, dengan temuan | **32** salinan memuat key aktif, bukan 27: 5 ada di dalam arsip |
| 7 | Identifikasi salinan yang dibutuhkan rollback, pemulihan, retensi | **PASS** | Tidak ada salinan yang dibutuhkan setelah escrow terverifikasi |
| 8 | Rencana penghapusan aman | **PASS** (rencana) / **BLOCKED_BY_OWNER** (eksekusi) | Rencana di bawah; tidak ada file yang dihapus |
| 9 | Pemulihan backup | **PASS** | Backup sebelum deploy `fb3726c5` dipulihkan ke database sementara, lalu dibuang |
| 10 | Inventaris data terenkripsi | **PASS** | 4 kolom terdaftar; 8 nilai, semuanya di satu kolom |
| 11 | Dry run re-enkripsi | **PASS** | Produksi: 8 nilai, semuanya di key saat ini, 0 tidak terbaca |
| 12 | Kompatibilitas pelepasan key lama | **PASS** | CI pada `fb3726c5` dan gladi langkah C/D pada salinan |
| 13 | Penanganan login ticket dokter | **PASS** | CI pada `fb3726c5` |
| 14 | Pairing perangkat | **PASS** | CI pada `fb3726c5` |
| 15 | Invalidasi payment intent | **PASS** | CI pada `fb3726c5` |
| 16 | Kesiapan queue dan worker | **PASS** | Worker aktif sejak deploy; 0 job antre; 0 job gagal |
| 17 | Prosedur rollback | **PASS** (pada salinan) | Rollback memindahkan 8 nilai kembali ke key lama; tool belum dipasang di host |
| 18 | Tidak ada transaksi klinis/kasir aktif di jendela usulan | **BLOCKED_BY_OWNER** | Jendela belum diusulkan; gate aktivitas siap dan sudah lulus dua kali (lihat di bawah) |
| 19 | Persetujuan eksekusi rotasi | **BLOCKED_BY_OWNER** | Belum diberikan |

Tidak ada pemeriksaan berstatus FAIL. Ada satu temuan keamanan dari tool saya sendiri, sudah diperbaiki
dan dicatat di bagian "Temuan keamanan".

## Otoritas produksi saat diukur

| Item | Nilai |
|---|---|
| Commit produksi | `fb3726c5` (GO tag `post-audit-f4bc-release-1-go`) |
| Waktu pengukuran | 2026-10-30 01:10–01:20 WITA, setelah deploy dan verifikasi rilis |
| Log aplikasi | Ukuran identik sebelum dan sesudah semua pengukuran |
| Re-enkripsi | Hanya dry run. `security:app-key-reencrypt --apply` **tidak** dijalankan di produksi. |

## 1–5. Escrow dual-custodian

### Tool

Tool escrow (`g7b-escrow-app-key.sh`, sha256 `c23037cb…dd68a2`) punya empat mode: `seal`, `verify`,
`recovery-check`, `rehearse`. Sumber lengkap ada di [G7B-ESCROW-TOOLS](G7B-ESCROW-TOOLS.md). Prinsipnya:

- Key hanya berpindah lewat pipe dan environment proses. Key tidak pernah lewat argumen perintah, file
  plaintext, atau stdout.
- Setiap salinan dienkripsi simetris dengan GPG (AES-256, S2K SHA-512 dengan 65 juta iterasi, tanpa
  cache passphrase) di direktori GPG sementara yang dihapus di akhir run.
- Setiap salinan punya manifest berisi label custodian, waktu, sha256 ciphertext, dan identitas key
  (hash bergaram dari key, bukan key-nya). Verifikasi membandingkan manifest, hasil dekripsi, dan key
  yang sedang hidup.
- Recovery check memulihkan backup sebelum deploy terbaru ke database sementara milik `postgres`, lalu
  menjalankan re-enkripsi **dry run** dengan key baru acak plus key escrow sebagai previous key.
  Database sementara selalu dihapus, juga saat gagal.

### Gladi di host produksi

Dijalankan dengan key hidup dan passphrase acak buatan mesin (tidak pernah dicetak), di direktori
sementara 0700 yang dihapus pada run yang sama.

| Langkah | Hasil gladi 1 (23:2x WITA, pada `755c6bce`) | Hasil gladi 2 (01:1x WITA, pada `fb3726c5`) |
|---|---|---|
| Seal dua salinan, ciphertext berbeda | PASS | PASS |
| Verify kedua salinan = key hidup | MATCH, MATCH | MATCH, MATCH |
| Kontrol: passphrase custodian lain ditolak | PASS | PASS |
| Kontrol: ciphertext diubah ditolak manifest | PASS | PASS |
| Kontrol: ciphertext diubah ditolak GPG walau manifest dipalsukan | PASS | PASS |
| Kontrol: salinan berisi key lain ditolak | PASS | PASS |
| Backup sumber recovery check | `pre_auto_deploy_20261029-030912` | `pre_auto_deploy_20261029-170635` |
| A0: key baru saja | 8 tidak terbaca (kontrol) | 8 tidak terbaca (kontrol) |
| A: key baru + key escrow sebagai previous | 8 terbaca sebagai previous, 0 tidak terbaca | 8 terbaca sebagai previous, 0 tidak terbaca |
| Sisa artefak (database sementara, direktori sementara) | 0 setelah perbaikan (lihat temuan) | 0 |
| Durasi | 8,0 detik | 7,3 detik |

### Prosedur seremoni (setelah owner menunjuk custodian)

1. **Persiapan.** Owner menetapkan custodian utama dan kedua serta waktunya. Tool diunggah ke direktori
   khusus root, lalu sha256-nya dicocokkan dengan nilai di atas.
2. **Seal.** `--mode seal` sekali per custodian, lewat `ssh -t`. Custodian mengetik passphrase-nya
   sendiri di prompt GPG. Passphrase tidak pernah lewat chat, argumen perintah, atau file, dan dua
   custodian memakai passphrase yang berbeda.
3. **Verify.** `--mode verify --env <file aktif>` untuk tiap salinan. Harus MATCH.
4. **Recovery check per salinan.** `--mode recovery-check` untuk **masing-masing** salinan, dengan
   custodiannya mengetik passphrase. Harus PASS.
5. **Pindah ke media custodian.** Ciphertext dan manifest dipindahkan ke media masing-masing. Di media,
   sha256 ciphertext dicocokkan dengan manifest.
6. **Bersihkan host.** Salinan di host dihapus per path, lalu diverifikasi sudah tidak ada.
7. **Catat.** Catat label custodian, waktu, sha256 ciphertext, identitas key, dan media. Nilai key tidak
   pernah dicatat.
8. **Uji berkala.** Tiap custodian membawa kembali salinannya untuk recovery check ulang sebelum key lama
   dipensiunkan.

Prasyarat rotasi tetap: langkah 1–7 selesai untuk kedua custodian **sebelum** rotasi dijalankan.

## 6. Inventaris ulang salinan environment

Diukur 2026-10-30 01:11 dan 01:19 WITA. Nilai hanya dibandingkan secara internal di host, dan tidak ada
nama file atau nilai yang dicetak. Keterjangkauan diuji untuk file dan seluruh direktori induknya, bukan
hanya bit mode file.

### File biasa

| Lokasi | File dengan baris key | Key aktif | Key lain (bukan key aktif) | Kosong/template | Mode | Terjangkau akun lain |
|---|---:|---:|---:|---:|---|---:|
| Root aplikasi | 12 | 10 | 0 | 2 | 0600, 0644 | 2 (keduanya **kosong**) |
| `storage/backups` | 1 | 1 | 0 | 0 | 0600; direktori 0770 | 0 |
| Direktori backup deploy privat | 10 | 10 | 0 | 0 | 0640; direktori 0750 | 0 |
| Direktori home root | 9 | 6 | 2 | 1 | 0600/0640/0644; induk 0700 | 0 |
| Direktori aplikasi lama | 3 | 0 | 1 | 2 | 0644; induk 0700 | 0 |
| **Jumlah** | **35** | **27** | **3** | **5** | | **2, keduanya tanpa key** |

### Di dalam arsip — temuan baru

Inventaris file biasa tidak bisa melihat file environment yang terbungkus arsip. Scan baru membaca
anggota arsip yang bernama seperti file environment, membandingkannya di memori, lalu membuangnya.

| Lokasi | Arsip dipindai | Arsip dengan file environment | Anggota berisi key aktif | Anggota berisi key lain | Mode | Terjangkau akun lain |
|---|---:|---:|---:|---:|---|---:|
| Direktori backup deploy privat | 26 | 3 (snapshot runtime 2026-06-25/26) | 3 | 0 | 0640 | 0 |
| Direktori home root | 11 | 4 (2026-06-05 s.d. 2026-08-05) | 2 | 3 | 0644; induk 0700 | 0 |
| **Jumlah** | **37** | **7** | **5** | **3** | | **0** |

0 arsip yang tidak terbaca.

### Kesimpulan inventaris

- Pemegang key aktif berjumlah **32** (27 file biasa ditambah 5 anggota arsip), bukan 27 seperti
  laporan sebelumnya. Kelima salinan di dalam arsip harus ikut keputusan retensi.
- **Tidak ada satu pun** pemegang key aktif yang bisa dibaca akun lokal di luar root dan grup runtime.
  Grup runtime sudah memegang key yang sama lewat file environment aktif.
- Tidak ada salinan yang memuat password database yang sedang berlaku.
- 6 salinan memuat application key lain yang **bukan** key aktif. Data produksi saat ini 100% di key
  aktif (dry run: 8/8 current), jadi key lain itu tidak dibutuhkan untuk membaca data produksi. Namun
  key tersebut tetap material kunci dan ikut keputusan retensi.
- Sidik jari himpunan path (untuk mendeteksi perubahan tanpa membuka nama file): file biasa
  `ed19251788eb56ea`, arsip `4c2f3ae075670479`.

## 7. Salinan yang dibutuhkan untuk rollback, pemulihan, atau retensi

| Kebutuhan | Apakah butuh salinan environment? | Alasan |
|---|---|---|
| Rollback kode (`rollback-vps.sh`) | Tidak | Rollback memakai file environment aktif, bukan salinan |
| Rollback rotasi | Tidak | Butuh key lama, yang disimpan di escrow dua custodian |
| Pemulihan backup sebelum rotasi | Tidak | Butuh key lama sebagai previous key; sumbernya escrow |
| Pemulihan backup sesudah rotasi | Tidak | Memakai key baru di file environment aktif |
| Retensi arsip runtime lama | Mungkin | Arsip runtime Juni juga memuat file non-rahasia; owner memutuskan apakah seluruh arsip dibuang |

Setelah escrow terverifikasi, tidak ada salinan environment yang diperlukan untuk operasi apa pun.

## 8. Rencana penghapusan aman (menunggu persetujuan retensi)

**Tidak ada file yang dihapus.** Rencana ini baru boleh dijalankan setelah (a) kedua salinan escrow lulus
recovery check, dan (b) owner menyetujui retensi.

1. **Urutan.** Rencana dijalankan di jendela rotasi: sesudah escrow terverifikasi, dan sebelum key lama
   dinyatakan pensiun. Menghapus salinan sebelum escrow terverifikasi bisa menghilangkan satu-satunya
   cara membaca backup lama.
2. **Cakupan.** 27 file biasa dan 5 anggota arsip pemegang key aktif. 6 salinan key lain dan 5 file
   kosong/template mengikuti keputusan owner. Direktori aplikasi lama tidak mendesak.
3. **Cara.** Hapus per path yang terdaftar di manifest root-only yang dibuat saat eksekusi, bukan dengan
   glob. File environment aktif tidak pernah disentuh. Untuk arsip, owner memilih antara membuang
   seluruh arsip lama atau menyimpannya sampai masa retensi; arsip tidak diubah isinya.
4. **Batas teknis.** Di disk virtual, penimpaan isi file (`shred`) tidak menjamin data lama hilang dari
   lapisan penyimpanan dan snapshot penyedia. Kontrol utamanya adalah **rotasi itu sendiri**: setelah
   rotasi, salinan ini hanya memuat key lama yang sudah pensiun.
5. **Verifikasi.** Jalankan ulang kedua inventaris. Hasil yang diharapkan: 0 pemegang key aktif di luar
   file environment aktif, dan sidik jari himpunan path berubah sesuai yang dihapus.

## 9–11. Backup, data terenkripsi, dan dry run

| Item | Hasil |
|---|---|
| Pemulihan backup | `pre_auto_deploy_20261029-170635` (backup deploy `fb3726c5`) dipulihkan ke database sementara, lalu dihapus. Database produksi tidak disentuh. |
| Kolom terenkripsi terdaftar | 4 kolom: SATUSEHAT eligibility snapshot (8 nilai), SATUSEHAT preview snapshot (0), snapshot identitas kasus merge pasien (0), nilai akhir resolusi merge (0) |
| Dry run produksi | 8 total, 8 current, 0 previous, 0 tidak terbaca, 0 previous key terkonfigurasi |
| Apply pada salinan | Gladi 2026-10-29 (laporan lama): 8 nilai ditulis ulang ke key baru, lalu rollback memindahkannya kembali |

## 12–15. Konsumen key di luar database

Rangkaian test berikut berjalan dan lulus di critical gate rilis `fb3726c5` (run `40897488296`):
22 test di empat suite `AppKeyReencryption*`.

| Konsumen | Perilaku yang diuji |
|---|---|
| Login ticket dokter | Ticket dari key lama ditolak dan dokter login ulang; ticket sesudah rotasi diterima |
| Kode pairing perangkat | Kode dari key lama tidak terbaca dan harus diterbitkan ulang |
| Form pembayaran (payment intent) | Form yang ditandatangani key lama ditolak, tidak pernah dibayar dua kali |
| Cookie terenkripsi dan link bertanda tangan | Diterima selama key lama dikonfigurasi sebagai previous; dienkripsi ulang ke key baru |
| Pelepasan key lama | Setelah re-enkripsi, semua nilai terbaca tanpa previous key; backup lama ditolak dengan pesan pemulihan |
| Rollback dan resume | Rotasi selesai bisa di-rollback; re-enkripsi yang terputus bisa di-rollback atau dilanjutkan |

Di jendela rotasi tetap dijalankan uji langsung: satu login dokter dan satu verifikasi perangkat setelah
konfigurasi dibangun ulang.

## 16–18. Queue, rollback, dan aktivitas

| Item | Hasil |
|---|---|
| Worker | Aktif, di-restart oleh deploy `fb3726c5` pukul 01:07:40 WITA; 0 job antre; 0 job gagal. Di jendela rotasi, worker di-restart lagi setelah konfigurasi dibangun ulang. |
| Tool rotasi | `g7b-rotate-app-key.sh` (sha256 `d79d56ca…66ff`), mode plan/apply/verify/rollback/retire-previous/finalize; tersimpan di cabang audit (commit `def7a3a2`). Belum dipasang di host; dipasang di direktori khusus root saat eksekusi disiapkan. |
| Gate aktivitas | `f4-activity-gate.php` (sha256 `9f4c58a2…eacc`), baca-saja: lulus pada 2026-10-29 23:1x WITA dan 2026-10-30 01:06 WITA (semua sinyal aktif 0). Untuk jendela rotasi, gate dijalankan ulang tepat sebelum langkah apply. |

## Temuan keamanan: key hidup sempat terlihat di argumen proses

Saat gladi pertama di produksi (2026-10-29 sekitar 23:21 WITA), versi awal tool escrow meneruskan key
hidup ke perintah artisan sebagai argumen `env APP_KEY=…` untuk dua pemanggilan. Akibatnya, key sempat
terlihat di daftar argumen proses `/usr/bin/env` selama jeda singkat sebelum `exec`.

- **Paparan.** Host tidak memasang `hidepid` pada `/proc`, sehingga akun lokal lain secara teori bisa
  membaca argumen proses selama jeda itu. Akun lokal lain adalah akun aplikasi tetangga dan `postgres`.
  Grup runtime aplikasi sudah memegang key yang sama.
- **Bukti penyalahgunaan.** Tidak ada. Jeda berlangsung dalam orde milidetik, dan tidak ada proses lain
  yang diketahui memantau `/proc` saat itu. Peluangnya kecil, tetapi tidak bisa dibuktikan nol.
- **Perbaikan.** Key sekarang diteruskan lewat assignment environment shell, bukan argumen. Versi
  perbaikan diverifikasi dengan 1.471 sapuan `/proc` selama gladi (8 detik): 0 argumen proses
  berbentuk key. Kontrol positif (key sengaja ditaruh di argumen) terdeteksi.
- **Cacat kedua di run yang sama.** Trap pembersihan bersarang membaca variabel lokal yang sudah hilang,
  sehingga satu database sementara dan satu direktori sementara (berisi ciphertext gladi dengan
  passphrase acak yang tidak pernah dicetak) tertinggal. Keduanya dihapus per path dan diverifikasi 0.
  Tool sekarang memakai satu fungsi pembersihan global.
- **Implikasi.** Temuan ini **memperkuat** alasan rotasi: kemungkinan paparan sekecil apa pun hanya
  tuntas dengan mengganti key. Rotasi tetap menunggu persetujuan owner.

## Keputusan owner yang dibutuhkan

| Keputusan | Status |
|---|---|
| Konfirmasi custodian utama IT | Usulan tersedia |
| Penunjukan custodian kedua dari manajemen | **Belum** |
| Retensi 32 pemegang key aktif (27 file biasa + 5 anggota arsip) | **Belum** |
| Retensi 6 salinan key lain dan arsip runtime lama | Belum, tidak mendesak |
| Jendela rotasi (di luar jam klinik dan kasir) | **Belum** |
| Persetujuan eksekusi rotasi | **Belum** |

## Larangan yang tetap berlaku

- Jangan merotasi application key produksi tanpa persetujuan eksekusi terpisah.
- Jangan menjalankan `security:app-key-reencrypt --apply` di produksi di luar jendela rotasi.
- Jangan menghapus salinan environment sebelum escrow terverifikasi dan retensi disetujui.
- Jangan meneruskan key lewat argumen perintah, dan jangan menulisnya ke file plaintext, log, laporan,
  knowledge base, CI, atau chat.
