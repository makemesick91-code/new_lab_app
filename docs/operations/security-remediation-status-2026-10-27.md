---
kb_status: snapshot
kb_verified_against: 755c6bce
kb_updated: 2026-10-29
---

# Status Remediasi Keamanan — 2026-10-27

Snapshot status remediasi keamanan setelah audit sistem menyeluruh 2026-10-27. Halaman ini
**bukan** sumber kebenaran. Yang menjadi sumber kebenaran adalah kode, GO tag git, dan pull
request. Status di sini dicatat per **2026-10-28 01:34 WITA**. Untuk keadaan terbaru, periksa GO tag dan
PR terkait.

> **Pembaruan 2026-10-29:** dua bagian **Pembaruan** di bawah mencatat yang terjadi sesudah snapshot
> ini. Bagian yang lebih baru mencatat rilis produksi `755c6bce`, yang membawa seluruh PR urutan merge
> dan sudah diverifikasi di produksi. Bagian per 07:52 WITA dipertahankan sebagai catatan pada waktu
> itu. F-4b dan F-4c sudah di-merge, tetapi belum di-deploy.

Kembali ke [00-INDEX](../00-INDEX.md).

> Halaman ini sengaja tidak memuat password, application key, nama role database, isi file
> environment, alamat produksi, atau data pasien. Lihat larangan keamanan di
> [conventions](../knowledge-base/conventions.md).

## Otoritas produksi

| Item | Nilai |
|---|---|
| Commit produksi | `755c6bce` (tree `b53ade16`) |
| GO tag pada HEAD produksi | `post-audit-security-release-1-go` |
| Diverifikasi | 2026-10-29, setelah deploy rilis pasca-audit; lihat bagian **Pembaruan — rilis produksi** |
| Produksi sebelumnya | `f9134aac` (tree `2aa2d01c`), GO tag `security-f8c-env-copy-backup-classification-1-go`, diverifikasi 2026-10-27 |

## Selesai dan terverifikasi di produksi

| Item | Status | Bukti |
|---|---|---|
| F-8 — path penyimpanan privat | **COMPLETE / GO** | GO tag `security-f8-private-storage-paths-1-go` → `043a4ae2`; [catatan sprint](../sprints/security-f8-private-storage-paths-1.md) |
| F-8b — config cache privat | **COMPLETE / GO** | GO tag `security-f8b-config-cache-private-1-go` → `2d258013`; [catatan sprint](../sprints/security-f8b-config-cache-private-1.md) |
| F-8c — klasifikasi salinan file environment | **COMPLETE / GO** | GO tag `security-f8c-env-copy-backup-classification-1-go` → `f9134aac`; [catatan sprint](../sprints/security-f8c-env-copy-backup-classification-1.md) |
| G7-A — rotasi password database aplikasi | **COMPLETE / GO** | lihat di bawah |

### G7-A — rotasi password database aplikasi

- Dijalankan 2026-10-27 pukul 21:00 WITA di jendela pemeliharaan yang disetujui owner.
- Server database hanya menerima verifier SCRAM-SHA-256 yang dihitung secara lokal. Nilai
  password tidak pernah dicetak, tidak masuk ke argumen perintah, dan tidak tertulis di dokumen
  mana pun.
- Pemeriksaan setelah rotasi: password baru diterima dan password lama ditolak; health 200;
  layanan aktif; queue worker sudah di-restart; satu backup terjadwal berhasil memakai kredensial
  baru; 0 error aplikasi sejak rotasi; nilai password baru tidak ditemukan di log mana pun.
- Pemeriksaan regresi F-8b/F-8c setelah rotasi: GO.

## Pembaruan — rilis produksi `755c6bce` per 2026-10-29 22:30 WITA

Bagian ini mencatat yang terjadi setelah bagian **Pembaruan** per 07:52 WITA di bawah. Bagian itu tidak
diubah, supaya tetap terbaca sebagai catatan pada waktu itu.

Tiga status dipisahkan dengan tegas:

- **MERGED**: sudah di-merge ke basis kanonik.
- **DEPLOYED**: sudah berjalan di produksi.
- **PRODUCTION-VERIFIED**: sudah diperiksa di produksi setelah deploy.

### Rilis produksi

| Item | Nilai |
|---|---|
| Commit produksi | `755c6bce` (tree `b53ade16`) |
| GO tag | `post-audit-security-release-1-go` (annotated, cocok persis dengan HEAD produksi) |
| Deploy | 2026-10-29 11:09 WITA, lewat runner deploy resmi yang dijalankan di VPS. Target dipin ke `755c6bce`, `DEPLOY OK`, tidak ada migrasi. |
| Backup sebelum deploy | Dibuat otomatis oleh deploy dan lulus `foundation:backup-verify` |
| Rollback | Kode saja, ke GO tag `security-f8c-env-copy-backup-classification-1-go` (`f9134aac`). Tidak ada migrasi yang perlu dibalik. |
| Produksi sebelumnya | `f9134aac` |

Rilis ini membawa seluruh PR di tabel **Urutan merge** di bawah, ditambah halaman status ini (#547).
Semuanya sekarang **DEPLOYED** dan **PRODUCTION-VERIFIED**.

### Validasi rilis sebelum deploy

- Run CI `40797351874` pada `755c6bce` (dijalankan manual, sehingga classifier memilih profil terkuat):
  - critical gate: 6.965 test lulus, 38.996 assertion, exit 0;
  - selective module gate: Inventory 1.589, Lab 679 (1 skipped), UI 950, Permission 669;
  - NSF-9 dan NSF-10 lulus.
- Full Suite **tidak dijalankan** (`FULL_SUITE_NOT_REQUESTED`) dan tidak dihitung sebagai PASS.
- Kompatibilitas database: tidak ada migrasi baru, dan lock file dependency mendukung PHP 8.3 yang dipakai
  produksi.
- Prasyarat runtime di produksi: isolasi runtime 105/105 GO, izin file rahasia GO, `backup-dr-check` 13/13
  GO, 0 migrasi tertunda, 0 job gagal.

### Verifikasi produksi setelah deploy

Semua pemeriksaan di bawah hanya membaca. Tidak ada data klinis, keuangan, inventori, atau lab yang
dibuat atau diubah.

| Area | Hasil |
|---|---|
| Rilis | HEAD produksi `755c6bce`, tidak ada file terlacak yang berubah, environment pilot, debug OFF, maintenance OFF |
| HTTP lewat domain kanonik | `/login`, `/health/live`, `/health/ready`, `/health/lb`: 200. `/register`: 404. `/storage/…`: 403. Halaman RME, kasir, lab, inventori, dan dashboard tanpa login: 302 ke login. |
| F-1, F-2 | Route registrasi publik dan hapus akun sendiri tidak ada |
| F-3 | Kelas pengaman sel CSV ada. 24 route export, semuanya di belakang autentikasi. |
| F-4a | `clinical:date-diagnose --strict`: zona Asia/Makassar, kanonik, exit 0 |
| F-6 | Perintah REPL tidak terdaftar dan paketnya tidak terpasang di produksi |
| G7-B | Perintah `security:app-key-reencrypt` terdaftar. Dry run: 8 nilai, semuanya di key saat ini, 0 tidak terbaca. |
| RME, odontogram, consent, kasir | Route utama ada. `rme:invoice-correction-readiness` GO, `rme:billing-dispute-status` GO. |
| Akses dokter dan autentikasi perangkat | Fleet `TRUSTED_PATHS_COMPLETE`. WebAuthn tetap mode pilot, dengan cakupan yang sama seperti sebelum deploy. Enforcement global tetap nonaktif. |
| Integritas ledger inventori | Tiga audit batch dan dokumen sumber GO (10/10, 10/10, 9/9). Audit alur pengadaan strict exit 0. |
| Workflow lab | Pilot readiness GO, audit akun teknisi GO |
| Queue dan backup | PHP-FPM, nginx, dan worker aktif. Timer backup aktif, run terakhir sukses. 0 job gagal. Worker di-restart otomatis dan berjalan di rilis baru. |
| Penyimpanan privat | Isolasi runtime GO. Disk publik tidak memuat file klinis. Direktori bukti klinis privat mode 2770. `foundation:security-compliance-check` 9/9. |
| Log aplikasi | Tidak ada baris baru setelah deploy |

Yang **tidak** diuji secara langsung di produksi, supaya tidak membuat data atau sesi nyata: login dokter
di perangkat, transaksi kasir, dan unduhan CSV. Ketiganya tercakup di critical gate CI.

Satu baris ERROR di log aplikasi berasal dari alat pemeriksaan sendiri, bukan dari aplikasi. Baris itu
tercatat 2026-10-29 09:09 WITA, sebelum deploy: pemeriksaan awal memanggil perintah G7-B yang saat itu
belum ada di produksi. Pemeriksaan sekarang memastikan perintah terdaftar lebih dulu. Baris ini menahan
pemeriksaan log monitoring di WATCH sampai sekitar 24 jam.

### G7-B — kesiapan rotasi application key

Rotasi produksi tetap **NO-GO**. Rincian di
[laporan kesiapan G7-B](g7b-production-readiness-report-2026-10-29.md).

- Perintah re-enkripsi sudah DEPLOYED dan PRODUCTION-VERIFIED (dry run saja).
- Gladi rotasi pada salinan data produksi **lulus**: tulis ulang, pembacaan dengan key baru saja, dan
  rollback. Database produksi tidak diubah.
- Salinan file environment dihitung ulang: **28**, bukan 21. Sebanyak 27 memuat application key yang sedang
  aktif.
- Masih menunggu owner:
  - penunjukan custodian kedua dari manajemen dan seremoni escrow;
  - keputusan retensi salinan;
  - persetujuan eksekusi rotasi.

### F-4b dan F-4c — MERGED, belum DEPLOYED

Kelima PR diperbarui terhadap basis terbaru satu per satu, lalu di-merge berurutan. Setiap PR baru
di-merge setelah semua gate CI wajib pada kandidatnya lulus.

| # | PR | Item | Run CI kandidat | Commit merge | Run CI basis setelah merge |
|---:|---|---|---|---|---|
| 1 | #548 | F-4c — tanggal order lab | `40798373656` lulus | `03415ffb` | `40810992478` lulus |
| 2 | #549 | F-4b1 — ledger inventori | `40811124407` lulus | `24998c71` | `40823410464` lulus |
| 3 | #550 | F-4b2 — kedaluwarsa batch | `40823677696` lulus | `62ee56e4` | `40836499781` lulus |
| 4 | #551 | F-4b3 — laporan inventori | `40837127984` lulus | `bcc793e2` | `40855928295` lulus |
| 5 | #552 | F-4b4 — analitik inventori | `40856285308` lulus | `897efd6b` | `40872747306` lulus |

- Untuk setiap PR, tree hasil merge identik dengan tree kandidat yang diuji CI. Tree itu juga identik
  dengan trial terintegrasi: basis ditambah kelima PR, 1.843 test lulus, 8 skipped, 0 gagal.
- Konflik hanya ada di registry bersama, yaitu filter workflow, `config/ci_runner.php`, dan manifest
  sprint. Konflik diselesaikan dengan aturan yang sama seperti urutan merge sebelumnya. Satu PR (#551)
  juga punya konflik import di samping pengaman CSV, dan kedua sisi dipertahankan.
- Pengaman CSV, aturan kalender klinik, dan invariant ledger inventori tetap utuh. Tidak ada backfill dan
  tidak ada koreksi data historis.
- **Belum DEPLOYED**, karena tidak termasuk rilis `755c6bce`. Deploy memerlukan persetujuan terpisah.
- Basis kanonik setelah merge terakhir: `897efd6b`.

### Durasi CI setelah memo evaluasi governance (#545) dan cache Composer (#546)

Diukur pada run resmi workflow `foundation-evidence-gates` sejak 2026-10-24. Run yang dibandingkan hanya
run yang critical regression step-nya benar-benar berjalan dan lulus. Klasifikasi sebelum atau sesudah
mengikuti isi yang diuji. Run PR menguji PR yang sudah digabung dengan basis saat run dipicu, jadi run PR
setelah #545 masuk basis sudah membawa memo. Klasifikasi ini dicocokkan dengan durasi step governance, dan
tidak ada yang berbeda.

| Ukuran (median) | Sebelum (26 run) | Sesudah (30 run) |
|---|---:|---:|
| Waktu workflow (wall-clock) | 9.377 dtk | 7.401 dtk |
| Menit runner | 12.986 dtk | 10.211 dtk |
| Job critical gate | 8.537 dtk | 7.129 dtk |
| Step critical regression tests | 8.040 dtk | 7.057 dtk |
| Step governance audit (critical gate) | 431 dtk | 15 dtk |
| Step NSF-9 ENT-14/15/16 (terlama) | 96 dtk | 1 dtk |
| Step NSF-10 capture release evidence | 294 dtk | 2 dtk |
| Install Composer di NSF-9 dan NSF-10 | 8 dtk | 4 dtk |

Kesimpulan yang boleh ditarik:

- Evaluasi governance turun dari ratusan detik menjadi satu digit di setiap run. Ini efek langsung memo.
- Cache Composer menghemat sekitar 4 detik per job di NSF-9 dan NSF-10. Efeknya kecil.
- Median waktu workflow dan menit runner turun sekitar 21%. Sebagian penurunan berasal dari step
  governance. Sisanya tidak dapat diatribusikan, karena step test juga berubah.
- **Tidak ada klaim percepatan eksekusi test.** Rentang step critical regression tests masih tumpang tindih:
  5.143–9.416 dtk sebelum, 4.022–8.207 dtk sesudah. Kecepatan runner berbeda-beda, dan himpunan test juga
  bertambah di antara kedua periode.
- Parallel testing tetap **nonaktif**, sampai isolasi bukti bersama di `storage/release-evidence/…`
  diselesaikan.

### Keputusan owner — status per 22:30 WITA

| Kode | Status |
|---|---|
| D4 | PENDING |
| D5 | Disetujui prinsipnya. Implementasi PENDING, visibilitas repository belum diubah. |
| D6 | PENDING |
| D15, D16 | Disetujui 2026-10-28. Implementasinya MERGED, belum DEPLOYED. |
| D17 | PENDING |
| G7-B | Custodian kedua, retensi salinan, dan persetujuan eksekusi: belum diberikan |
| Deploy F-4b dan F-4c | Belum diminta |

## Pembaruan — status merge per 2026-10-29 07:52 WITA

Bagian ini mencatat yang terjadi **setelah** snapshot 2026-10-28 01:34 WITA. Snapshot di bawah tidak
diubah, supaya tetap terbaca sebagai catatan pada waktu itu.

**Produksi tidak berubah: tetap `f9134aac`.** Semua PR di bawah berstatus **MERGED_NOT_DEPLOYED**:
sudah di-merge ke basis kanonik, belum di-deploy, dan belum diverifikasi di produksi. Kode yang sudah
di-merge tidak otomatis dianggap terverifikasi di produksi. Deploy memerlukan persetujuan terpisah.

Basis kanonik setelah merge terakhir di daftar ini: `b07b54b0`.

### Urutan merge

Setiap PR di-merge satu per satu dengan langkah berikut:

1. Basis terbaru di-merge ke branch PR.
2. Diff lengkapnya ditinjau.
3. Semua gate CI wajib dijalankan pada SHA kandidat yang persis akan di-merge.
4. PR di-squash-merge hanya bila semua gate wajib lulus.
5. Tree hasil merge dibandingkan dengan tree kandidat, dan keduanya identik.

| # | PR | Item | Run CI kandidat | Commit merge | Run CI basis setelah merge |
|---:|---|---|---|---|---|
| 1 | #545 | Memo evaluasi governance | `40551686916` lulus | `7d3a86b0` | `40585194638` lulus |
| 2 | #546 | Cache Composer | `40585384993` lulus | `cc470286` | `40602199954` lulus |
| 3 | #544 | Pembaruan dependency | `40602345930` lulus | `a1229ac8` | `40613944439` lulus |
| 4 | #534 | Persiapan G7-B (re-enkripsi application key) | `40614035627` lulus | `5d62faf3` | `40626190812` lulus |
| 5 | #535 | F-1 — registrasi publik ditutup | `40626294749` lulus | `b3434104` | `40638416365` lulus |
| 6 | #536 | F-2 — penghapusan akun sendiri ditolak | `40638576534` lulus | `da897219` | `40650040667` lulus |
| 7 | #537 | F-6 — REPL tidak ikut ke produksi | `40650223934` lulus | `50e5655a` | `40666705791` lulus |
| 8 | #538 | F-7 — guard reset sintetis SATUSEHAT | `40666902306` lulus | `bd1e08d9` | `40683648510` lulus |
| 9 | #539 | F-3 — sel CSV diamankan dari formula | `40684559972` lulus | `da62a57d` | `40704866085` lulus |
| 10 | #540 | F-5 — class dari JavaScript tetap ada di build | `40705147232` lulus | `d0e11ad6` | `40721932691` **dibatalkan** |
| 11 | #541 | F-4a1 — tanggal rekam medis | `40723557594` lulus | `1ee92f4c` | `40740967050` lulus |
| 12 | #542 | F-4a2 — layar keuangan | `40742505433` lulus | `21f2e2e8` | `40754946223` lulus |
| 13 | #543 | F-4a3 — periode adopsi SATUSEHAT | `40755621389` lulus | `b07b54b0` | `40774433531` lulus |

"Lulus" berarti semua gate CI wajib lulus. Full Suite dilewati oleh kebijakan sementara dan **tidak**
dihitung sebagai PASS.

"Dibatalkan" berarti run CI basis dihentikan oleh aturan concurrency workflow: merge berikutnya
membatalkan run yang masih berjalan pada branch yang sama. Run yang dibatalkan **tidak** dihitung
lulus. Tree setiap commit merge sudah diuji lebih dulu lewat run CI kandidatnya, dan run CI basis
terakhir menguji seluruh perubahan sekaligus.

Halaman ini sendiri di-merge lewat #547, setelah urutan di atas.

Catatan sprint yang sudah ada di basis:

- [memo evaluasi governance](../sprints/cicd-governance-evaluation-memo-1.md)
- [cache Composer](../sprints/cicd-composer-cache-release-gates-1.md)
- [pembaruan dependency](../sprints/security-dependency-updates-1.md) dan
  [penilaian keamanan dependency](../governance/dependency-security-assessment-2026-10-27.md)
- [G7-B](../sprints/security-g7b-app-key-reencryption-1.md)
- [F-1](../sprints/security-f1-close-public-registration-1.md)
- [F-2](../sprints/security-f2-deny-self-deletion-1.md)
- [F-3](../sprints/security-f3-csv-cell-guard-1.md)
- [F-4a1](../sprints/security-f4a1-clinic-calendar-clinical-records-1.md)
- [F-4a2](../sprints/security-f4a2-clinic-calendar-financial-dashboards-1.md)
- [F-4a3](../sprints/security-f4a3-clinic-calendar-satusehat-adoption-1.md)
- [F-5](../sprints/security-f5-tailwind-js-content-1.md)
- [F-6](../sprints/security-f6-tinker-require-dev-1.md)
- [F-7](../sprints/security-f7-satusehat-reset-guard-selection-1.md)

### G7-B — rotasi application key produksi: tetap NO-GO

- PR persiapan #534 sudah di-merge (`5d62faf3`), tetapi **belum di-deploy**.
- Rotasi di produksi tetap **NO-GO**. Rotasi memerlukan laporan kesiapan final dan persetujuan
  eksekusi yang terpisah.
- Sudah diverifikasi (2026-10-28, tanpa mengubah database produksi):
  - Backup sebelum rotasi berhasil dipulihkan ke database sementara, lalu database sementara itu
    dihapus.
  - Data terenkripsi yang harus ditulis ulang saat rotasi sudah dihitung.
  - Antrean job kosong, tidak ada job gagal, dan worker aktif. Ini harus diperiksa ulang di dalam
    jendela rotasi.
- Uji terisolasi di #534 lulus di critical gate CI: re-enkripsi data, pembacaan tanpa key lama,
  pemulihan backup dengan key yang di-escrow, dampak ke login dokter, pairing perangkat, dan signature
  payment intent, serta rollback. Uji ini memakai data uji dan **belum** dijalankan terhadap data
  produksi.
- Masih terbuka sebelum rotasi produksi:
  - Penyimpanan key lama (escrow), dengan custodian yang ditetapkan owner.
  - Deploy #534, yang memerlukan persetujuan terpisah.
  - Keputusan retensi untuk salinan file environment yang masih memuat key lama.
  - Rencana rollback final untuk jendela rotasi.
  - Laporan kesiapan final.
- Diperiksa di dalam jendela rotasi:
  - re-enkripsi data produksi berhasil;
  - pemulihan berhasil setelah key lama dilepas;
  - login dokter dan pairing perangkat berfungsi;
  - signature payment intent lama menjadi tidak berlaku;
  - tidak ada transaksi klinis atau kasir yang aktif;
  - queue dan worker siap.

### F-4b dan F-4c — keputusan sudah ada, PR belum di-merge

- **D15 disetujui** (2026-10-28): tanggal bisnis inventori memakai Asia/Makassar. Timestamp event UTC
  dan catatan ledger yang immutable tetap dipertahankan.
- **D16 disetujui** (2026-10-28): tanggal operasional lab memakai Asia/Makassar. Timestamp event,
  urutan workflow, dan due date yang sudah ditetapkan tetap dipertahankan.
- Tidak ada backfill data historis di produksi.
- Implementasi ada di PR terbuka. CI hijau, tetapi PR **belum di-merge**:

  | Item | PR | Run CI |
  |---|---|---|
  | F-4c — tanggal order lab | #548 | `40594041015` |
  | F-4b1 — ledger inventori | #549 | `40594072585` |
  | F-4b2 — kedaluwarsa batch | #550 | `40594083925` |
  | F-4b3 — laporan inventori | #551 | `40594096140` |
  | F-4b4 — analitik inventori | #552 | `40594105262` |

- Di luar cakupan F-4c dan menunggu keputusan owner tersendiri:
  - analitik KPI lab dan perencanaan kapasitas;
  - laporan jatuh tempo invoice lab legacy.

### Durasi CI setelah memo evaluasi governance (#545)

Step perintah governance turun konsisten di setiap run sesudah #545 (diukur pada 28 run):

| Step | Sebelum | Sesudah |
|---|---:|---:|
| Governance audit commands (critical gate) | 284–457 dtk | 10–17 dtk |
| NSF-9 ENT-14, ENT-15, ENT-16 | 50–101 dtk per step | 0–2 dtk per step |
| NSF-10 capture release evidence | 205–293 dtk | 1–3 dtk |

Step critical regression tests **tidak** menunjukkan penghematan agregat yang dapat diklaim:

| Kondisi | Rentang | Jumlah run |
|---|---:|---:|
| Sebelum #545 | 5.396–9.416 dtk | 13 |
| Sesudah #545 | 4.109–8.207 dtk | 28 |

Rentang keduanya tumpang tindih karena kecepatan runner berbeda-beda. Tidak ada target benchmark yang
diklaim tercapai.

Yang tidak diubah:

- Cakupan test wajib tetap lengkap.
- Parallel testing tetap nonaktif.
- Memo dibatasi pada satu evaluasi. Uji dengan dokumen yang sengaja dihapus menghasilkan FAIL yang
  sama dengan dan tanpa memo, sehingga memo tidak menyembunyikan hasil basi.

### Keputusan owner — status terbaru

| Kode | Status |
|---|---|
| D4 | PENDING |
| D5 | Disetujui prinsipnya; implementasi PENDING. Visibilitas repository belum diubah. |
| D6 | PENDING |
| D15 | **Disetujui** 2026-10-28 |
| D16 | **Disetujui** 2026-10-28 |
| D17 | PENDING |
| D20 | Tidak berubah: default yang direkomendasikan tetap dipakai di F-3 |

Perubahan infrastruktur, tagihan, visibilitas repository, dan retensi tidak dilakukan tanpa
persetujuan yang sesuai.

## Belum selesai — jangan dianggap COMPLETE

> Bagian ini adalah snapshot per 2026-10-28 01:34 WITA dan tidak diubah. Status PR, run CI, dan
> keputusan owner yang lebih baru ada di bagian **Pembaruan** di atas.

### G7-B — rotasi application key: PENDING

- Persiapan ada di PR #534 (`SECURITY-G7B-APP-KEY-REENCRYPTION-1`, kandidat `dee15aa1`).
  Status CI: **hijau** — run `40512596020` pada `dee15aa1`.
- **Rotasi di produksi: NO-GO.** Rotasi baru boleh dilakukan setelah verifikasi kesiapan selesai
  dan ada persetujuan eksekusi produksi yang terpisah.
- Uji terisolasi yang sudah ada:
  - re-enkripsi data SATUSEHAT yang terenkripsi;
  - pembacaan tanpa key lama;
  - dampak ke login dokter, pairing perangkat, dan signature payment intent;
  - pemulihan backup;
  - rollback.
- Ketergantungan yang masih terbuka:
  - Backup yang dibuat sebelum rotasi memerlukan key lama. Key lama harus disimpan (escrow)
    sebelum dipensiunkan.
  - Konsumen yang hanya memakai key saat ini akan langsung tidak berlaku saat rotasi: tiket login
    dokter, kode pairing perangkat, dan signature payment intent. Rotasi harus dijadwalkan pada
    jendela tanpa sesi aktif.

### Perbaikan keamanan F-1 sampai F-7: PR terbuka, belum di-merge, belum di-deploy

Setiap perbaikan satu PR. Basis kanonik `f9134aac` sudah di-merge ke setiap branch. Sebelum
dipublikasikan, setiap rentang commit sudah dipindai dari kredensial dan data pasien.

| Item | PR | Status CI |
|---|---|---|
| F-1 — registrasi publik ditutup | #535 | hijau — run `40514442201` |
| F-2 — penghapusan akun sendiri ditolak | #536 | hijau — run `40514457445` |
| F-3 — sel CSV diamankan dari formula | #539 | hijau — run `40517131444` |
| F-4a1 — tanggal rekam medis memakai kalender klinik | #541 | hijau — run `40520290991` |
| F-4a2 — layar keuangan memakai kalender klinik | #542 | hijau — run `40520300582` |
| F-4a3 — periode adopsi SATUSEHAT memakai kalender klinik | #543 | hijau — run `40520308137` |
| F-4b — tanggal ledger inventori | — | **BLOCKED** menunggu keputusan D15 |
| F-4c — tanggal order lab | — | **BLOCKED** menunggu keputusan D16 |
| F-5 — class dari JavaScript tetap ada di build produksi | #540 | hijau — run `40517144937` |
| F-6 — REPL tidak ikut ke produksi | #537 | hijau — run `40514468718` |
| F-7 — test guard reset sintetis SATUSEHAT dipilih di critical gate | #538 | hijau — run `40514484922` |

"Hijau" berarti semua gate CI wajib lulus pada SHA head PR tersebut. Full Suite dilewati oleh
kebijakan sementara dan tidak dihitung sebagai PASS.

F-4a dipecah menjadi tiga PR. Perbaikan awalnya menyentuh tujuh modul, sedangkan
`sprint:scope-audit` menolak `RUNTIME_FIX` yang menyentuh lebih dari tiga modul. Tipe sprint tidak
diubah hanya supaya lolos gate.

### Keamanan dependency: PR terbuka

| Item | PR | Status CI |
|---|---|---|
| Pembaruan dependency di dalam major yang sama | #544 | hijau — run `40522106555` |

- `composer audit`: 30 advisory produksi menjadi 0.
- `npm audit`: 12 menjadi 0.
- Tidak ada major version yang naik.
- `DependencySecurityFloorTest` mengunci versi minimum.

### Optimasi CI/CD: PR terbuka

| Item | PR | Status CI |
|---|---|---|
| Memo evaluasi governance (setiap gate enterprise dihitung sekali per evaluasi) | #545 | hijau — run `40551686916` |
| Cache Composer di NSF-9, NSF-10, dan Full Suite | #546 | hijau — run `40551702141` |

Hasil lokal memo evaluasi governance:

- `foundation:enterprise-closure-check` turun dari 60,4 detik menjadi 0,6 detik.
- `foundation:enterprise-documentation-check` turun dari 63,9 detik menjadi 0,6 detik.
- Output JSON identik byte per byte dengan versi kanonik.
- `architecture:foundation-governance-summary` turun dari 103,3 detik menjadi 0,67 detik.
- Tiga kelas test governance turun dari 1.868 detik menjadi 9,6 detik.

Hasil CI memo evaluasi governance (run `40551686916`), dibandingkan run push basis `40491341757` dan
run PR cache `40551702141` yang berjalan pada waktu yang sama tanpa memo:

| Step CI | Basis | PR cache (tanpa memo) | PR memo |
|---|---:|---:|---:|
| NSF-9 ENT-14 load test scale projection | 51 dtk | 50 dtk | 1 dtk |
| NSF-9 ENT-15 dokumentasi enterprise | 100 dtk | 101 dtk | 0 dtk |
| NSF-9 ENT-16 closure enterprise | 100 dtk | 100 dtk | 1 dtk |
| NSF-10 capture release evidence | 293 dtk | 205 dtk | 2 dtk |
| Critical gate: governance audit commands | 284 dtk | 457 dtk | 16 dtk |
| Critical gate: critical regression tests | 5.603 dtk | 9.293 dtk | 6.983 dtk |

Step perintah governance turun dari ratusan detik menjadi satu digit, konsisten di ketiga job. Step
critical regression tests hanya menunjukkan arah: PR memo 2.310 detik lebih cepat daripada PR cache
yang berjalan bersamaan, tetapi masih lebih lambat daripada run basis, karena variasi runner lebih
besar daripada selisihnya.

Waktu critical test step pada PR keamanan di atas berkisar 5.396–9.416 detik untuk suite yang
setara, karena kecepatan runner berbeda-beda dan sebelas run berjalan bersamaan. Karena itu, satu
run CI hanya menunjukkan arah perubahan, bukan angka penghematan yang presisi.

Parallel testing dievaluasi, tetapi tidak diaktifkan. Lebih dari 30 berkas test governance menulis
ke path bersama yang tetap (`storage/release-evidence/…`), sehingga paralelisasi di satu runner
belum aman. Matrix sharding lintas job sudah siap dan menjadi langkah berikutnya, setelah durasi
critical gate diukur ulang dengan memo terpasang.

## Keputusan owner yang masih terbuka

> Tabel ini adalah snapshot per 2026-10-28 01:34 WITA. D15 dan D16 sudah disetujui sesudahnya;
> lihat bagian **Pembaruan** di atas.

| Kode | Topik | Status | Dampak |
|---|---|---|---|
| D4 | Payload staging pasien legacy (berisi NIK lengkap) | PENDING | Rekomendasi: kosongkan kolom payload hanya untuk batch terminal; baris dan verdict tetap disimpan |
| D5 | Visibilitas repository | Disetujui prinsipnya (PRIVATE); **belum dialihkan** | Prasyarat: deploy key VPS, anggaran CI (D17), pengganti secret scanning, rencana branch protection (D6) |
| D6 | Branch protection | Disetujui untuk disiapkan; penerapan PENDING | Pada repository privat butuh GitHub Pro atau lebih tinggi |
| D15 | Kalender untuk tanggal ledger inventori | PENDING | Memblokir F-4b |
| D16 | Kalender untuk tanggal order lab | PENDING | Memblokir F-4c |
| D17 | Paket GitHub dan anggaran CI jika repository privat | PENDING | Memblokir pengalihan D5 |
| D20 | Aturan angka di CSV | Default yang direkomendasikan, sudah dipakai di F-3 | Owner dapat mengubahnya |

## Larangan yang tetap berlaku

- Jangan merotasi application key produksi sebelum ada persetujuan kesiapan.
- Jangan merge atau deploy otomatis. Setiap PR melewati CI wajib dan tinjauan keamanan.
- Jangan menganggap Full Suite lulus. Full Suite dilewati oleh kebijakan sementara dan tidak pernah
  dicatat sebagai PASS.
- Jangan mengubah catatan medis historis atau transaksi keuangan.
