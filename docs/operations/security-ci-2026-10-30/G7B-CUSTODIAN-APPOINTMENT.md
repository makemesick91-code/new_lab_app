---
kb_status: snapshot
kb_verified_against: fb3726c5
kb_updated: 2026-10-30
---

# G7-B — Penunjukan Custodian dan Persiapan Escrow Dual-Custodian (2026-10-30)

**Status akhir: `G7_B_ROTATION_EXECUTION=NO_GO`. Seremoni escrow `BELUM DIJALANKAN`.** Owner menunjuk
custodian kedua hari ini. Custodian pertama masih usulan dan menunggu konfirmasi formal. Escrow dan
verifikasi pemulihan belum bisa berstatus PASS, karena keduanya hanya bisa dilakukan oleh dua custodian
secara langsung di terminal. Karena itu **laporan final kesiapan G7-B belum dibuat** (lihat bagian
terakhir untuk syaratnya).

Halaman ini melanjutkan [G7B-SECURITY-GATE-CLOSURE](G7B-SECURITY-GATE-CLOSURE.md) dan memakai prosedur yang
sudah diaudit di [G7B-FINAL-READINESS-REPORT](../release-cicd-phase2-2026-10-29/G7B-FINAL-READINESS-REPORT.md)
serta tool di [G7B-ESCROW-TOOLS](../release-cicd-phase2-2026-10-29/G7B-ESCROW-TOOLS.md). Langkah seremoni
yang siap dijalankan ada di [G7B-ESCROW-CEREMONY-RUNBOOK](G7B-ESCROW-CEREMONY-RUNBOOK.md). Kembali ke
[00-INDEX](../../00-INDEX.md).

> Halaman ini tidak memuat application key, identitas key, passphrase, password, isi file environment,
> path atau nama file salinan environment, alamat host, atau data pasien.

## Keputusan owner (2026-10-30)

| Peran | Identitas | Status | Dasar |
|---|---|---|---|
| Custodian 1 — IT | Raushan Fikri Ridha (IT DaengtisiaMS) | **DIUSULKAN — menunggu konfirmasi formal owner** | Pernyataan owner 2026-10-30: "Custodian 1 yang diusulkan … menunggu konfirmasi formal." |
| Custodian 2 — manajemen | **Jene Monika — Supervisor RME Klinik Gigi Daengtisia** | **DITUNJUK** | Keputusan owner 2026-10-30: "Penunjukan Custodian 2 disetujui." |

Catatan:

- **Ditunjuk bukan terverifikasi.** Syarat gate `SECOND_CUSTODIAN` baru `VERIFIED` setelah Jene Monika
  menyegel salinannya sendiri, salinan itu lulus `verify` (MATCH dengan key aktif), dan lulus
  `recovery-check` dengan passphrase-nya sendiri. Status hari ini: `APPOINTED`.
- Custodian 2 memegang peran operasional lain di aplikasi (Supervisor RME, approver terpisah untuk
  gelombang migrasi arsip lama). Peran aplikasi itu tidak berhubungan dengan custody application key dan
  tidak memberinya akses apa pun ke host atau ke key. Penunjukan ini juga tidak memberinya akses tersebut.
- Penunjukan custodian signing Android tetap bukan bukti custody application key (aturan 210 no. 12).
- Pemeriksaan hari ini: host produksi **tidak punya akun login manusia** selain root. Root dapat diakses
  dengan 3 kunci SSH. Dari host, kunci itu tidak bisa dipetakan ke orang. Karena itu pernyataan bahwa
  custodian manajemen tidak memegang akses host harus **diatestasi** oleh custodian 2 di catatan custody,
  bukan disimpulkan dari host.

## Gate rilis G7-B sesudah keputusan hari ini

| Syarat | Sebelumnya | Sekarang | Yang masih dibutuhkan |
|---|---|---|---|
| `PRIMARY_CUSTODIAN` (IT) | Usulan | **PENDING_CONFIRMATION** | Konfirmasi formal owner |
| `SECOND_CUSTODIAN` | BLOCKED | **APPOINTED** (belum VERIFIED) | Seal, verify, dan recovery check oleh Jene Monika |
| `ENV_COPY_INVENTORY` | VERIFIED | **VERIFIED**, diukur ulang hari ini tanpa drift | — |
| `RETENTION_DECISION` | PENDING | **PENDING** | Keputusan owner per salinan |
| `OLD_KEY_RECOVERY` | NOT VERIFIED | **NOT VERIFIED** | Recovery check dari dua salinan custodian yang nyata |
| `NEW_KEY_RECOVERY` | NOT VERIFIED | **NOT VERIFIED** | Escrow key baru di jendela rotasi |
| `REENCRYPTION_TEST` | PASS | PASS | — |
| `ROLLBACK_TEST` | PASS | PASS | — |
| `BACKUP_RESTORE` | PASS | PASS | — |
| `PRODUCTION_EXECUTION_APPROVAL` | Tidak ada | **Tidak ada** | Persetujuan eksekusi tersendiri dari owner |

Keputusan: **NO-GO**. Enam syarat belum terpenuhi. Rotasi application key produksi tidak dijalankan dan
tidak dijadwalkan oleh pekerjaan ini.

## Pemeriksaan pra-seremoni di produksi (2026-10-30, 14:07–14:15 WITA)

Semua pemeriksaan bersifat baca-saja dan dijalankan sebagai root lewat stdin SSH. Tidak ada file, database,
konfigurasi, atau layanan yang diubah. Log aplikasi 1.443.432 byte sebelum dan sesudah, sama dengan nilai
pada laporan penutupan gate pagi ini.

| Pemeriksaan | Hasil |
|---|---|
| Rilis produksi | `fb3726c5` (tree `e25afb7a`, tag `post-audit-f4bc-release-1-go`), 0 file terlacak berubah |
| File environment aktif | mode 640, `root:daengtisiams`, tidak berubah |
| Dry run re-enkripsi produksi | 8 total, 8 current, 0 previous, 0 tidak terbaca, 0 previous key terkonfigurasi |
| Inventaris file biasa | 35 salinan: 27 key aktif, 3 key lain, 5 kosong. Sidik jari set path `ed19251788eb56ea`, **sama** |
| Inventaris arsip | 37 arsip, 7 dengan anggota file environment, 5 anggota key aktif, 3 key lain. Sidik jari `4c2f3ae075670479`, **sama** |
| Salinan key aktif yang terbaca akun lain | 0 (2 salinan terbaca akun lain, keduanya kosong) |
| GnuPG | 2.4.4, mendukung `--no-symkey-cache` |
| Pinentry untuk prompt passphrase | `pinentry-curses` terpasang dan menjadi pinentry bawaan |
| Perintah re-enkripsi di rilis | `security:app-key-reencrypt` terdaftar |
| Sumber recovery check | Backup pra-deploy terbaru `pre_auto_deploy_20261029-170635` (backup rilis `fb3726c5`, lulus `backup-verify` 9/9 pagi ini) |
| Perangkat lunak recovery check | `python3`, `runuser`, `createdb`, `dropdb`, `psql`, `sha256sum` tersedia |
| Sisa artefak escrow | 0 direktori escrow, 0 database `dms_g7b%` |
| Layanan | nginx, php8.3-fpm, worker queue aktif; 0 job gagal |
| Tool escrow | sha256 `c23037cb…0dd68a2`. Blok kode di KB menghasilkan file yang identik byte demi byte |

### Temuan persiapan: jalur passphrase interaktif belum pernah dijalankan

Dua gladi escrow di host produksi memakai passphrase buatan mesin lewat `--pinentry-mode loopback`. Mode
`seal`, `verify`, dan `recovery-check` pada seremoni nyata memakai jalur lain: gpg-agent membuka
`pinentry-curses` di terminal yang dinamai `GPG_TTY`. Jalur itu belum pernah berjalan di host ini.

Dua akibatnya, keduanya sudah dimasukkan ke runbook:

1. **Tool harus dijalankan dari file, bukan lewat stdin.** Tool hanya mengisi `GPG_TTY` bila stdin-nya
   terminal. Kalau dijalankan dengan `ssh -t <host> 'bash -s' < tool.sh`, stdin adalah isi script, sehingga
   pinentry tidak punya terminal dan prompt passphrase tidak muncul. Cara yang benar: unggah tool ke
   direktori khusus root, cocokkan sha256-nya, lalu jalankan `ssh -t <host> 'bash <path tool> …'`.
2. **Langkah 0 (uji pinentry dengan key sekali pakai).** Sebelum key aktif disegel, operator menyegel key
   acak sekali pakai dengan mode `seal` dan `verify` yang sama. Custodian mengetik passphrase latihan.
   Langkah ini menguji prompt di terminal yang akan dipakai tanpa menyentuh key aktif, lalu semua
   artefaknya dihapus. Tool tidak diubah. Ini satu-satunya tambahan terhadap prosedur yang sudah diaudit.

Kalau prompt gagal di tengah seal yang sebenarnya, gpg gagal dan tool berhenti karena `set -euo pipefail`.
Belum ada salinan escrow yang tercatat, jadi langkah itu cukup diulang. Langkah 0 tetap dijalankan agar
custodian tidak bertemu prompt yang macet di depan key aktif.

## Apa artinya "dilindungi secara independen"

Dua salinan escrow dianggap independen bila **semua** syarat berikut terpenuhi. Semua syarat ini diperiksa
di runbook.

| Syarat | Cara pembuktian |
|---|---|
| Passphrase berbeda, masing-masing hanya diketahui pemiliknya | Tiap custodian mengetik passphrase sendiri di pinentry. Tidak ada yang mendiktekan, mencatat, atau melihat layar saat diketik. |
| Ciphertext berbeda | sha256 kedua manifest berbeda (dicek di runbook langkah 4) |
| Salinan satu tidak bisa dibuka dengan passphrase salinan lain | Sudah terbukti di dua gladi. Di seremoni tidak diuji ulang, karena pengujian itu berarti satu custodian mengetik passphrase di salinan milik custodian lain. |
| Media berbeda dan lokasi fisik berbeda | Dicatat per custodian di catatan custody |
| Tidak ada salinan tersisa di host, workstation operator, CI, repository, KB, chat, atau cloud | Penghapusan per path dan pemeriksaan ulang (runbook langkah 7) |
| Masing-masing bisa memulihkan sendiri | `recovery-check` lulus untuk **setiap** salinan, dengan passphrase pemiliknya |

## Risiko sisa yang dicatat

1. **Hanya ada satu operator host.** Custodian manajemen sengaja tidak memegang akses host. Akibatnya
   pemulihan dari salinan custodian 2 tetap butuh orang yang memegang akses root untuk menjalankan tool.
   Bila custodian 1 tidak tersedia, owner perlu menunjuk operator host pengganti sebelum pemulihan.
   Prosedur akses darurat di [G7B-SECURITY-GATE-CLOSURE](G7B-SECURITY-GATE-CLOSURE.md) tetap berlaku.
2. **Key berpindah lewat proses di host.** Selama seal, verify, dan recovery check, key aktif ada di memori
   proses root. Key tidak masuk argumen proses; ini sudah diverifikasi dengan 1.471 sapuan `/proc` pada
   gladi. Risiko ini sama dengan saat key dibaca aplikasi.
3. **Workstation operator ikut dilalui ciphertext.** Ciphertext disalin langsung ke media custodian,
   bukan ke disk workstation. Bila tersalin ke disk workstation, file itu dihapus per path dan dicatat.
   Yang terpapar hanya ciphertext; passphrase tidak pernah tertulis.

## Yang dikerjakan dan yang tidak

Dikerjakan hari ini:

- Mencatat keputusan owner di dokumen ini, di aturan 210, dan di `AIR-FND-37`.
- Pemeriksaan pra-seremoni baca-saja di produksi (tabel di atas).
- Runbook seremoni dengan peran bernama: [G7B-ESCROW-CEREMONY-RUNBOOK](G7B-ESCROW-CEREMONY-RUNBOOK.md).

Tidak dikerjakan, dengan alasan:

| Item | Alasan |
|---|---|
| Seal salinan escrow | Passphrase hanya boleh diketik custodian sendiri di terminal. Sesi otomasi tidak punya terminal dan tidak boleh memegang passphrase. |
| Verify dan recovery check salinan nyata | Sama seperti di atas. Recovery check juga butuh salinan yang belum ada. |
| Seal salinan custodian 1 | Custodian 1 belum dikonfirmasi formal. |
| Retensi atau penghapusan salinan environment | Belum ada keputusan retensi. Tidak ada file yang dihapus. |
| Rotasi application key produksi | Tidak ada persetujuan eksekusi. Tetap NO-GO. |
| Laporan final kesiapan G7-B | Owner meminta laporan ini dibuat setelah escrow dan recovery verification PASS. Keduanya belum terjadi. |

## Syarat sebelum laporan final kesiapan G7-B dibuat

Laporan final dibuat setelah semua syarat ini terbukti dan tercatat di catatan custody:

1. Custodian 1 dikonfirmasi formal oleh owner.
2. Langkah 0 (uji pinentry) PASS di terminal yang dipakai seremoni.
3. Kedua salinan disegel (`SEALED`), dengan identitas key sama dan sha256 ciphertext berbeda.
4. `verify` MATCH dengan key aktif untuk kedua salinan.
5. `recovery-check` PASS untuk **masing-masing** salinan, dengan passphrase pemiliknya: 8 nilai terbaca
   sebagai previous, 0 tidak terbaca, dan kontrol A0 (key baru saja) membuat 8 nilai tidak terbaca.
6. Kedua salinan sudah di media masing-masing, sha256 di media cocok dengan manifest, dan host bersih
   (0 file escrow, 0 database `dms_g7b%`).
7. Log aplikasi tidak bertambah karena seremoni, dan dry run produksi tetap 8/8 current.

Laporan final itu **tidak** memberi izin rotasi. Rotasi tetap butuh keputusan retensi, jendela rotasi di luar
jam klinik dan kasir, dan persetujuan eksekusi tersendiri dari owner.

## Larangan yang tetap berlaku

- Jangan merotasi application key produksi tanpa persetujuan eksekusi terpisah.
- Jangan mencetak, mengirim, atau menyimpan nilai application key di chat, GitHub, Obsidian, log, laporan,
  CI, atau argumen perintah.
- Jangan mengetik, mendiktekan, atau menyimpan passphrase custodian atas nama custodian.
- Jangan menghapus salinan environment sebelum escrow terverifikasi dan retensi disetujui.
