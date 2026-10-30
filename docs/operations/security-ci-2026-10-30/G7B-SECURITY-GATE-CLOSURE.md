---
kb_status: snapshot
kb_verified_against: fb3726c5
kb_updated: 2026-10-30
---

# G7-B — Penutupan Gate Keamanan Rotasi Application Key (2026-10-30)

**Status akhir: `G7_B_ROTATION_EXECUTION=NO_GO`.** Persiapan keamanan yang bisa dikerjakan tanpa keputusan
owner sudah lengkap dan diukur ulang hari ini pada rilis produksi `fb3726c5`. Rotasi application key
produksi **tidak** dijalankan. Tiga syarat gate bergantung pada owner dan belum terpenuhi: custodian kedua,
keputusan retensi, dan persetujuan eksekusi.

> **Pembaruan 2026-10-30 (sore):** owner menunjuk custodian 2 (Jene Monika, Supervisor RME). Custodian 1 masih
> menunggu konfirmasi formal. `SECOND_CUSTODIAN` kini `APPOINTED`, belum `VERIFIED`; rotasi tetap NO-GO. Lihat
> [G7B-CUSTODIAN-APPOINTMENT](G7B-CUSTODIAN-APPOINTMENT.md). Isi halaman ini tidak diubah.

Halaman ini melanjutkan [G7B-FINAL-READINESS-REPORT](../release-cicd-phase2-2026-10-29/G7B-FINAL-READINESS-REPORT.md)
dan tidak menggantikan bukti di sana. Kembali ke [00-INDEX](../../00-INDEX.md). Halaman terkait:
[G7B-ENV-COPY-INVENTORY](G7B-ENV-COPY-INVENTORY.md) (inventaris per salinan),
[G7B-SECURITY-GATE-TOOLS](G7B-SECURITY-GATE-TOOLS.md) (sumber tool yang dijalankan hari ini).

> Halaman ini tidak memuat application key, identitas key, passphrase, password, isi file environment,
> nama file atau path salinan (sebagian memuat nama staf), atau data pasien. Pemetaan id ke path hanya
> ada di manifest root-only di host produksi.

## Gate rilis G7-B

| Syarat | Status | Dasar |
|---|---|---|
| `SECOND_CUSTODIAN` | **BLOCKED** | Belum ada penunjukan custodian kedua dari manajemen di repository, keputusan owner, atau catatan custody mana pun. Tidak diisi tanpa keputusan owner. |
| `ENV_COPY_INVENTORY` | **VERIFIED** | Inventaris per salinan, 47 baris, diukur 2026-10-30 08:24 WITA. Set path tidak berubah sejak 2026-10-30 01:11 WITA. Lihat [G7B-ENV-COPY-INVENTORY](G7B-ENV-COPY-INVENTORY.md). |
| `RETENTION_DECISION` | **PENDING** | Usulan per salinan ada; semua berstatus `PENDING_OWNER`. Tidak ada file yang dihapus. |
| `OLD_KEY_RECOVERY` | **NOT VERIFIED** | Tool escrow lulus gladi dengan passphrase acak buatan mesin. Pemulihan nyata dari dua salinan escrow yang dipegang dua custodian belum mungkin karena custodian belum ditunjuk. |
| `NEW_KEY_RECOVERY` | **NOT VERIFIED** | Key baru belum dibuat. Escrow key baru adalah langkah di jendela rotasi. |
| `REENCRYPTION_TEST` | **PASS** | Gladi A0→B hari ini pada salinan sekali pakai dari backup rilis `fb3726c5`, ditambah 22 test isolasi. |
| `ROLLBACK_TEST` | **PASS** | Gladi R1/R2 hari ini: 8 nilai kembali ke key lama, lalu terbaca dengan key lama saja. |
| `BACKUP_RESTORE` | **PASS** | `pre_auto_deploy_20261029-170635` lulus `foundation:backup-verify` 9/9 dan dipulihkan ke database sekali pakai (174 tabel, 7 detik), lalu dihapus. |
| `PRODUCTION_EXECUTION_APPROVAL` | **TIDAK ADA** | Owner menyatakan secara eksplisit belum mengizinkan rotasi produksi. |

Satu syarat yang gagal sudah cukup untuk NO-GO. Di sini empat syarat belum terpenuhi.

## Otoritas produksi saat diukur

| Item | Nilai |
|---|---|
| Commit produksi | `fb3726c5` (tree `e25afb7a`, GO tag `post-audit-f4bc-release-1-go`) |
| Base kanonik | `6da25a51` (hanya dokumen dan infrastruktur test di atas produksi) |
| Waktu ukur | 2026-10-30 08:20–08:30 WITA |
| Layanan | nginx, php8.3-fpm, worker queue aktif; 0 job gagal |
| Log aplikasi | 1.443.432 byte sebelum dan sesudah semua pengukuran dan gladi |
| File environment aktif | mode 640, `root:daengtisiams`, tidak berubah sejak rotasi password database G7-A (2026-10-27) |
| Dry run re-enkripsi produksi | 8 total, 8 current, 0 previous, 0 tidak terbaca, 0 previous key terkonfigurasi |

Identitas key aktif (hash bergaram, format tool escrow) dicatat di manifest root-only, bukan di halaman ini.
Bukti bahwa key tidak berubah: (1) 27 salinan berlabel `LIVE` cocok dengan key aktif, termasuk salinan
dari backup deploy 2026-10-29; (2) semua ciphertext di 42 dump database bulan Oktober terbaca dengan key
aktif saja; (3) dry run produksi: 8/8 current.

## Rekonsiliasi angka "28 salinan"

Catatan historis G7-B menyebut 28 salinan environment, 27 di antaranya memuat key aktif. Angka itu benar
untuk cakupan pengukuran awal (file biasa yang memuat nilai key, tanpa file kosong dan tanpa isi arsip).
Pengukuran hari ini memakai cakupan penuh.

| Cakupan | Salinan | Key aktif | Key lain | Kosong |
|---|---:|---:|---:|---:|
| Catatan historis (file biasa bernilai key) | 28 | 27 | 1 | — |
| File biasa, termasuk yang kosong dan dua salinan key lain di home root | 35 | 27 | 3 | 5 |
| Anggota arsip yang memuat baris key | 12 | 5 | 3 | 4 |
| **Total hari ini** | **47** | **32** | **6** | **9** |

- Ke-27 salinan berkey aktif dari catatan historis semuanya ada di inventaris hari ini.
- Satu salinan "key lain" dari catatan historis adalah salinan di direktori aplikasi lama (id `C13`).
- Tambahan dari pengukuran 2026-10-30: dua salinan key lain di home root (`C16`, `C18`), lima file kosong,
  dan anggota arsip. Empat anggota arsip kosong baru terlihat hari ini karena scan arsip sebelumnya hanya
  menghitung anggota yang bernilai key.
- Ada **3 key lain yang berbeda** (`OTHER-1`, `OTHER-2`, `OTHER-3`), tidak satu pun sama dengan key aktif.

## Apakah data terenkripsi bergantung pada salinan mana pun?

| Data | Key yang dibutuhkan | Sumber bukti |
|---|---|---|
| Database produksi (4 kolom, 8 nilai) | Key aktif saja | Dry run produksi hari ini |
| 42 dump database di direktori backup deploy (Oktober 2026) | Key aktif saja (6, 7, atau 8 nilai per dump) | Scan dump: MAC tiap ciphertext dicocokkan di memori |
| 30 dump database lain (Juni–Agustus 2026) | Tidak ada ciphertext | Scan dump yang sama |
| Salinan berkey `OTHER-1..3` | Tidak dibutuhkan untuk data apa pun di host | 0 dari 72 dump dan 0 baris produksi memakai key itu |

Kesimpulan: **tidak ada salinan yang dibutuhkan untuk membaca data**, selama key aktif tersimpan di escrow
dua custodian. Key lain (`OTHER-1..3`) tidak punya relevansi pemulihan untuk data apa pun yang ada di host.
Batas klaim: dump yang pernah disalin ke luar host tidak bisa diperiksa dari sini.

## Gladi rotasi terisolasi (hari ini)

Dijalankan dengan `g7b-isolated-rehearsal.sh` (sha256 awalan `508892bdd7d3d95a`) sebagai root di host
produksi, terhadap database **sekali pakai** yang dipulihkan dari backup rilis `fb3726c5`. Key hidup dan key
baru acak hanya lewat environment proses, tidak lewat argumen perintah. Konfigurasi produksi, database
produksi, dan file environment aktif tidak ditulis.

| Langkah | Konfigurasi key | Hasil |
|---|---|---|
| Restore | — | 174 tabel dalam 7 detik; `backup-verify` 9/9 GO |
| Guard 1 | — | Proses terhubung ke database sekali pakai |
| A0 | Key baru saja | 8 tidak terbaca (kontrol: backup lama tidak terbaca tanpa key lama) |
| A | Key baru + key lama sebagai previous | 8 previous, 0 tidak terbaca |
| B | `--apply` | 8 ditulis ulang ke key baru |
| C | Key baru saja | 8 current, key lama bisa dilepas |
| D | Key lama saja | 8 tidak terbaca (bukti penulisan ulang benar terjadi) |
| R1 | Rollback `--apply` (key lama current, key baru previous) | 8 ditulis kembali ke key lama |
| R2 | Key lama saja | 8 current |
| Audit | — | 2 baris `APP_KEY_VALUES_REENCRYPTED` di database sekali pakai (berisi jumlah saja) |
| Pembersihan | — | Database sekali pakai dihapus; 0 tersisa |

Test isolasi lokal: 4 suite `AppKeyReencryption*` pada PostgreSQL 16.15 (container sekali pakai), worktree
di `6da25a51`: **22 lulus, 172 assertion**. Cakupan: re-enkripsi, rollback, resume setelah terputus,
pemulihan backup sebelum rotasi dengan key escrow, penolakan backup lama setelah key dilepas, login ticket,
pairing code, payment intent, cookie, dan signed link.

## Piagam custody application key

Bagian ini mendefinisikan peran yang harus diisi owner. **Identitas tidak diisi tanpa keputusan owner.**

| Peran | Identitas | Status |
|---|---|---|
| Custodian 1 — IT | Usulan dari laporan 2026-10-30: Raushan Fikri Ridha (IT) | **Menunggu konfirmasi owner** |
| Custodian 2 — manajemen | — | **BLOCKED: belum ditunjuk** |

**Penunjukan signing Android bukan penunjukan custody application key.** Custodian 1 signing APK
(aturan 145/146) adalah penunjukan untuk material key lain dengan cakupan lain, dan media "Custodian 2/3"
di sana adalah tujuan penyimpanan, bukan orang. Penunjukan itu tidak dipakai sebagai bukti custody
application key. Usulan nama custodian 1 di atas hanya usulan.

### Cakupan

- Setiap custodian memegang **satu salinan escrow terenkripsi yang independen** dari application key: key
  lama sebelum rotasi, dan key baru sesudah rotasi. Masing-masing salinan dienkripsi dengan passphrase
  custodian itu sendiri (GPG simetris, AES-256, S2K SHA-512).
- Tidak ada custodian yang memegang passphrase custodian lain. Salah satu salinan cukup untuk pemulihan,
  sehingga kehilangan satu custodian tidak menghilangkan key.
- Custodian manajemen tidak memegang akses host. Custodian IT memegang akses host.

### Penyimpanan escrow

- Media offline milik custodian, di lokasi fisik yang berbeda untuk dua custodian.
- Tidak boleh disimpan di VPS, CI, repository, knowledge base, layanan chat, atau penyimpanan awan bersama.
- Setiap salinan disertai manifest: label custodian, waktu seal, sha256 ciphertext, identitas key (hash
  bergaram). Manifest tidak memuat key atau passphrase.

### Prosedur pemulihan dengan kontrol ganda

Pemulihan selalu melibatkan dua orang yang berbeda:

1. Operator host (akses root) menyiapkan direktori 0700 dan menjalankan tool dalam mode `recovery-check`
   atau `verify`.
2. Custodian pemegang salinan mengetik passphrase-nya sendiri di prompt GPG lewat `ssh -t`. Passphrase tidak
   pernah lewat chat, argumen perintah, atau file.
3. Jika custodian yang memulihkan adalah custodian IT yang juga operator host, custodian kedua hadir sebagai
   saksi dan menandatangani catatan custody.
4. Key yang dipulihkan hanya dipakai lewat environment proses, tidak pernah dicetak, dan host dibersihkan per
   path di akhir run.

### Akses darurat

- Satu custodian tidak tersedia: salinan custodian lain cukup, dengan saksi dari owner.
- Kedua salinan hilang sebelum rotasi: key lama tetap ada di file environment aktif; buat ulang escrow.
- Kedua salinan hilang sesudah key lama dilepas: backup sebelum rotasi tidak lagi bisa membaca 4 kolom
  terenkripsi (saat ini 8 nilai SATUSEHAT eligibility snapshot). Data lain di backup tetap bisa dipulihkan.
  Dampak ini terbatas dan harus disetujui owner sebelum key lama dilepas.

### Persyaratan audit

- Setiap seal, verify, recovery-check, dan pemakaian key dicatat di catatan custody di luar repository:
  waktu, operator, custodian, saksi, sha256 ciphertext, identitas key, hasil. Nilai key tidak pernah dicatat.
- Recovery check ulang oleh tiap custodian sebelum key lama dilepas dan setiap 90 hari sesudahnya.
- Setiap perubahan custodian membutuhkan seal ulang dan pemusnahan salinan lama yang tercatat.

## Temuan dari pekerjaan hari ini

1. **Keluaran inventaris pertama tidak tiba di sisi lokal.** Proses inventaris di host selesai dan menulis
   manifest root-only, tetapi stdout-nya tidak pernah sampai ke sesi SSH, dan sesi itu dihentikan. Isi
   manifest kemudian dibaca ulang dengan skrip ringkasan yang hanya mencetak kolom non-rahasia. Tidak ada
   data yang hilang dan tidak ada yang tercetak. Penyebab pastinya tidak diselidiki.
2. **Tidak ada drift.** Sidik jari set path file biasa tetap `ed19251788eb56ea`; jumlah arsip dengan file
   environment tetap 7.
3. **Tidak ada salinan yang aktif dipakai runtime.** 0 dari 47 salinan dirujuk konfigurasi systemd, nginx,
   PHP-FPM, cron, atau supervisor, dan 0 sedang dibuka oleh proses mana pun.
4. **Dua salinan terbaca akun lain, keduanya kosong** (`C06`, `C12`): sama dengan pengukuran sebelumnya.

## Langkah berikutnya (semuanya menunggu owner)

1. Konfirmasi custodian IT dan tunjuk custodian manajemen.
2. Seremoni seal dan recovery check untuk kedua custodian (prosedur di laporan kesiapan final).
3. Putuskan retensi per salinan di [G7B-ENV-COPY-INVENTORY](G7B-ENV-COPY-INVENTORY.md).
4. Tetapkan jendela rotasi di luar jam klinik dan kasir.
5. Berikan persetujuan eksekusi rotasi tersendiri.

## Larangan yang tetap berlaku

- Jangan merotasi application key produksi tanpa persetujuan eksekusi terpisah.
- Jangan menjalankan `security:app-key-reencrypt --apply` di database produksi di luar jendela rotasi.
- Jangan menghapus salinan environment sebelum escrow terverifikasi dan retensi disetujui.
- Jangan meneruskan key lewat argumen perintah, dan jangan menulisnya ke file plaintext, log, laporan,
  knowledge base, CI, atau chat.
