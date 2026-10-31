# Paket Keputusan Owner — Refund RME: Pengajuan & Persetujuan (Sprint 4/7)

> **Status (diperbarui 2026-10-31): DIJAWAB OWNER — RF-1 … RF-14, RF-1a dan RF-8a.** Jawaban pertama
> (RF-1/D4, RF-2/D18, RF-4, tanpa persetujuan diri sendiri, audit permanen) ada di **§7.1**; jawaban
> kedua pada hari yang sama menyetujui seluruh rekomendasi paket dengan perubahan yang dicatat di
> **§7.4**; laporan kontradiksi di **§7.5**. Dari sisi keputusan Owner, syarat implementasi §6 terpenuhi;
> yang tersisa adalah gerbang repositori dan teknis (**§7.6**). Tidak ada yang dibangun atau diaktifkan.
> Dokumen ini disiapkan oleh `PREPARATION-RME-BILLING-REFUND-REQUEST-APPROVAL-1`
> (program `DAENGTISIAMS-RME-BILLING-CORRECTION`, Sprint 4/7) pada 2026-10-31 (WITA).
> Kolom "Rekomendasi teknis" adalah rekomendasi, **bukan persetujuan Owner**. Sebuah usulan
> menjadi kebijakan hanya bila Owner menyatakannya dan pernyataan itu dicatat di
> `docs/decisions/rme-billing-correction-owner-decisions.md` dengan label
> `OWNER_POLICY_APPROVED`.

Dokumen terkait:

* Desain teknis: `docs/architecture/rme-refund-request-approval-design.md`
* Kontrak rekonsiliasi: `docs/architecture/rme-refund-financial-reconciliation-contract.md`
* Model ancaman: `docs/architecture/rme-refund-request-approval-threat-model.md`
* ADR: `docs/adr/0013-rme-refund-request-approval.md`
* Laporan persiapan: `docs/sprints/preparation-rme-billing-refund-request-approval-1.md`

---

## 1. Ringkasan untuk Owner

Sprint 3 (koreksi tagihan setelah pembayaran) sudah dibangun dan **dimatikan** (flag
`rme.invoice_postpayment_correction` OFF). Bila koreksi itu menurunkan total tagihan di bawah
uang yang sudah diterima, sistem mencatat **kewajiban kelebihan bayar** (`OPEN`) — uang yang
harus dikembalikan — tetapi **tidak ada cara untuk mengembalikannya**. Sprint 4 adalah langkah
pertama ke sana: **pengajuan dan persetujuan** refund. Uang baru benar-benar keluar di Sprint 5.

Tiga fakta yang perlu Owner ketahui sebelum memutuskan:

1. **Di produksi saat ini ada 0 kewajiban kelebihan bayar** (diperiksa read-only 2026-10-31).
   Kewajiban hanya lahir dari koreksi setelah pembayaran, yang masih OFF dan aktivasinya
   diblokir PP-10 karena pilot Sprint 2 masih NO_GO. Jadi walaupun Sprint 4 dibangun, ia tidak
   akan punya apa pun untuk diproses sampai Sprint 3 diaktifkan.
2. **Persetujuan refund tidak sama dengan membayar refund.** Desain ini memisahkan keduanya.
   "Disetujui" artinya klinik mengakui akan membayar; kas belum berkurang.
3. **Kewajiban kelebihan bayar tidak bisa diubah** (dikunci di kode sejak Sprint 3). Refund
   mencatat dirinya di tabel sendiri dan tidak pernah menyunting kewajiban, pembayaran, atau
   tagihan.

## 2. Status keputusan register yang diminta (diperiksa 2026-10-31)

Label mengikuti register: `OWNER_POLICY_APPROVED` · `REPOSITORY_POLICY_RECORDED` ·
`TECHNICAL_ENFORCEMENT_DEPLOYED` · `PENDING_OWNER_DECISION`.

| Kode | Pokok | Status | Bukti |
|---|---|---|---|
| **D4** | Otoritas persetujuan refund | **`OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`** (2026-10-31, lewat RF-1; sub-pertanyaan RF-1a tetap PENDING) | register, entri "D4 — refund approval authority"; §7 |
| **D5** | Metode refund | **`PENDING_OWNER_DECISION`** | idem; teks tercetak "dikembalikan di akhir bulan" adalah teks dokumen, bukan keputusan register |
| **D6** | Saldo kredit pasien | **`PENDING_OWNER_DECISION`** | idem |
| **D18** | Pemisahan persetujuan dan pembayaran | **`OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`** sebatas pemisahan (2026-10-31, lewat RF-2 = A); siapa yang membayar tetap D25/RF-10, PENDING | register, entri "D18"; §2.1, §7 |
| **D25** | Pembayar ≠ penyetuju | **`PENDING_OWNER_DECISION`** | idem |
| **D26** | Pembalikan pembayaran | **`PENDING_OWNER_DECISION`** | idem |

Keputusan lain yang menjadi dependensi langsung:

| Kode | Pokok | Status | Arti untuk refund |
|---|---|---|---|
| PP-3 | Penurunan di bawah uang diterima → kewajiban `OPEN`, tanpa refund | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`; kode terpasang tetapi flag OFF | sumber satu-satunya saldo yang boleh direfund |
| PP-2 | Otoritas koreksi setelah bayar + referensi persetujuan Owner wajib | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED` | pola yang diusulkan untuk RF-1 |
| PP-6 | Pelaporan: KPI kas tidak berubah; kewajiban terpisah; netting baru saat refund `SETTLED` | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED` | menjawab sebagian D8/D9/D13 untuk refund (lihat RF-8) |
| PP-9 | Visibilitas kewajiban read-only | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED` | diusulkan diperluas ke pengajuan refund (RF-8) |
| PP-10 | Aktivasi menunggu closure Sprint 2 | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED` | Sprint 2 masih NO_GO → kewajiban belum bisa terbentuk |
| OPS-OQ-1 | Tagihan yang diketahui salah: dilaporkan, tidak ditagih, tidak diubah sepihak | `OWNER_POLICY_APPROVED`, …, `TECHNICAL_ENFORCEMENT_DEPLOYED` | dasar RF-7 |
| D2, D8, D9, D12, D13 | (lihat register) | dijawab **hanya untuk koreksi setelah bayar** oleh PP-2…PP-6 | tidak otomatis berlaku untuk refund |
| D16 | Membatalkan koreksi yang disetujui | `PENDING_OWNER_DECISION` | analog RF-6 |
| D19 | Visibilitas oversight | `APPROVED_SCOPE` (rule 186), diperluas PP-9 | RF-8 |
| D24, D27 | Rentang mundur & zona waktu `paid_at` | `PENDING_OWNER_DECISION` | analog RF-11 (waktu uang keluar, Sprint 5) |

### 2.1 Catatan tentang D18 dan pembagian Sprint 4/Sprint 5

Brief tugas ini menyatakan Sprint 4 = pengajuan/persetujuan dan Sprint 5 = pembayaran keluar.
Itu adalah **instruksi lingkup tugas** dari Owner (seperti D28 `OWNER_TASK_INSTRUCTION`), dan
desain ini mematuhinya. Itu **tidak** dicatat sebagai persetujuan kebijakan D18, karena D18 juga
menyangkut siapa boleh membayar dan bagaimana — hal yang dijawab RF-2 dan RF-10. Status D18
tetap `PENDING_OWNER_DECISION` sampai Owner menyatakannya.

*Diperbarui 2026-10-31:* Owner menyatakan **"settlement terpisah"**, yang menjawab RF-2 = A. D18
dicatat `OWNER_POLICY_APPROVED` **sebatas pemisahan persetujuan dari pembayaran keluar**. Siapa yang
boleh membayar (D25/RF-10) dan bagaimana (D5/RF-9) tetap `PENDING_OWNER_DECISION`.

---

## 3. Keputusan yang memblokir implementasi Sprint 4 (Tingkat A)

### RF-1 — Siapa boleh menyetujui atau menolak refund (menjawab **D4**)

**Pertanyaan.** Akun mana yang boleh menyetujui/menolak pengajuan refund, dan apakah perlu
referensi persetujuan Owner per kasus?

| Pilihan | Isi |
|---|---|
| **A** | Pemeriksa = akun yang **ditunjuk per cabang** (daftar sumber-terkontrol saat pilot, tabel penunjukan per akun+cabang sesudahnya — pola OPS-OQ-1a), pembuat ≠ pemeriksa per akun (Super Admin termasuk), **referensi persetujuan Owner per kasus WAJIB** dan tidak boleh dipakai ulang (pola PP-2) |
| B | Sama seperti A tetapi **tanpa** referensi Owner per kasus |
| C | Hanya Owner yang menyetujui di sistem (Owner menjadi aktor keuangan) |
| D | Hanya Super Admin (teks asli D4 "Super Admin required for refund") |

* **Konsekuensi finansial.** A: setiap rupiah yang akan keluar punya dua kontrol (pemeriksa +
  Owner). B: satu kontrol untuk uang keluar, lebih lemah dari koreksinya sendiri (PP-2 wajib
  referensi). C: konsisten tetapi mengubah peran Owner yang selama ini read-only. D: menaruh
  otoritas keuangan pada akun IT.
* **Risiko keamanan.** `Gate::before` memberi Super Admin semua izin, sehingga izin saja tidak
  cukup sebagai batas; pilihan A dan B menegakkan penunjukan dan pembuat ≠ pemeriksa di
  service, termasuk untuk Super Admin. Pilihan D memusatkan kemampuan pada satu akun.
* **Dampak operasional.** A menambah satu langkah (mendapatkan referensi Owner). C menuntut
  Owner masuk ke sistem untuk setiap refund.
* **Rekomendasi teknis: A.** Uang keluar adalah tindakan paling berisiko di program ini; ia
  tidak boleh punya kontrol lebih lemah dari koreksi yang melahirkannya. **Satu referensi Owner =
  satu keputusan:** referensi refund tidak boleh pernah tercatat sebelumnya — dibandingkan tanpa
  membedakan huruf besar/kecil — pada refund mana pun **atau** pada koreksi setelah bayar mana pun
  (tagihan mana pun, termasuk koreksi sumbernya), dan sebaliknya koreksi setelah bayar juga harus
  menolak referensi yang sudah dipakai refund. Tanpa itu, satu persetujuan Owner bisa dipakai untuk
  dua kali uang keluar. Saat ini pemeriksaan koreksi hanya memindai koreksi dan tidak punya
  cadangan di database; desain mengusulkan satu tabel bersama referensi terpakai, yang tetap
  mengizinkan pengajuan ulang koreksi pada **tagihan yang sama** sesuai PP-2. Catatan: huruf
  besar/kecil dan spasi di tepi diabaikan, tetapi tanda pemisah tidak (`OWN-001` ≠ `OWN_001`) —
  sebaiknya Owner menerbitkan referensi dari satu urutan nomor.
* **Pengaruh.** Sprint 4: skema kolom referensi + CHECK DB + validasi; daftar identitas pilot.
  Sprint 5: tidak berubah.

**Sub-pertanyaan RF-1a.** Untuk pilot, apakah identitas sama dengan koreksi (pembuat 29,
pemeriksa 1)? **Catatan penting:** pada Sprint 2 pasangan akun ini masih
`HUMAN_SEPARATION=RED_FLAG_UNRESOLVED`. Bila dipakai ulang untuk refund, tanda merah yang sama
ikut terbawa. Rekomendasi: aktivasi refund menunggu tanda merah itu terselesaikan (lihat RF-3).

### RF-2 — Persetujuan dipisah dari pembayaran keluar (menjawab **D18**)

| Pilihan | Isi |
|---|---|
| **A** | Persetujuan **tidak pernah** memindahkan uang. Pembayaran keluar adalah catatan terpisah (tabel, izin, aksi, aktor sendiri) di Sprint 5 |
| B | Persetujuan = pembayaran dalam satu klik |

* **Konsekuensi finansial.** A: ada keadaan "disetujui — belum dibayar" yang harus dilaporkan
  sebagai liabilitas. B: tidak ada keadaan antara, tetapi satu orang dapat menyetujui sekaligus
  mengeluarkan uang.
* **Risiko keamanan.** B menghapus segregasi tugas atas uang keluar.
* **Dampak operasional.** A: dua langkah; cocok dengan teks tercetak "dikembalikan di akhir
  bulan" (persetujuan sekarang, pembayaran nanti).
* **Rekomendasi teknis: A.**
* **Pengaruh.** Sprint 4 tidak membuat tabel, izin, rute, atau tombol pembayaran sama sekali.

### RF-3 — Lingkup, flag, pilot, dan urutan aktivasi (memperluas PP-1, D1c, D1e)

**Usulan.** Flag terpisah `rme.billing_refund_request` (default OFF, risiko critical,
override environment ditangkap saat config dibangun). Pilot hanya cabang SPN4; identitas
pilot sumber-terkontrol (tanpa `env()`), gagal-tertutup. Aktivasi menunggu, berurutan:
(1) closure Sprint 2 terverifikasi termasuk penyelesaian `HUMAN_SEPARATION`, (2) aktivasi
Sprint 3 oleh keputusan Owner tersendiri, (3) **satu kewajiban nyata** tertangkap — tidak
pernah dibuat-buat — lalu (4) keputusan aktivasi refund oleh Owner.

* **Konsekuensi finansial.** Tidak ada uang keluar sampai Sprint 5 aktif juga.
* **Risiko keamanan.** Flag terpisah memastikan mematikan refund tidak mematikan koreksi, dan
  sebaliknya.
* **Rekomendasi teknis:** setujui seperti usulan.
* **Pengaruh.** Sprint 4 boleh diimplementasikan dengan flag OFF setelah Tingkat A diputuskan;
  aktivasinya menunggu urutan di atas.

### RF-4 — Jumlah refund: penuh atau sebagian

| Pilihan | Isi |
|---|---|
| **A** | Satu pengajuan per kewajiban, **sebesar seluruh kewajiban**, dihitung server. Tidak ada input jumlah |
| B | Boleh sebagian; beberapa pengajuan berjumlah ≤ kewajiban |
| C | Boleh sebagian dan sisanya dianggap dilepas (pasien mengikhlaskan) |

* **Konsekuensi finansial.** A: kewajiban selalu "terutang penuh" atau "lunas penuh" — tidak
  ada sisa. B: memerlukan keadaan "dibayar sebagian" pada kewajiban dan perhitungan sisa. C:
  menghapus utang kepada pasien — butuh bukti persetujuan pasien dan pertimbangan hukum.
* **Risiko keamanan.** A menghapus seluruh kelas serangan manipulasi jumlah (jumlah tidak
  pernah berasal dari input).
* **Rekomendasi teknis: A.** B atau C dapat ditambahkan kemudian sebagai sprint tersendiri.

### RF-5 — Bukti pendukung pada pengajuan

| Pilihan | Isi |
|---|---|
| A | Hanya alasan + tautan ke kewajiban (kewajiban sudah dibuktikan oleh koreksi + referensi Owner) |
| **B** | A + **referensi permintaan pasien** wajib (teks 6–120 karakter, tanpa NIK) — mis. nomor formulir permintaan. Tidak boleh dipakai ulang untuk kewajiban lain (tanpa membedakan huruf besar/kecil); pengajuan ulang untuk kewajiban yang sama setelah ditolak boleh memakai referensi yang sama karena itu permintaan pasien yang sama |
| C | B + unggahan formulir bertanda tangan ke penyimpanan privat |

* **Konsekuensi/risiko.** C menyimpan dokumen pasien (privasi, penyimpanan privat, kebijakan
  retensi). A tidak membuktikan bahwa pasien memang meminta refund.
* **Rekomendasi teknis: B** untuk Sprint 4; C dipertimbangkan bersama D5 di Sprint 5 (bukti
  pembayaran keluar memang dokumen).
* **Catatan privasi.** Sprint 4 **tidak** mengumpulkan nomor rekening pasien. Data tujuan
  transfer adalah urusan Sprint 5 dan memerlukan keputusan penyimpanan tersendiri.

### RF-6 — Arti penolakan dan pembatalan (analog **D16**)

**Usulan.**
* **Ditolak** (`REJECTED`): hanya pengajuan itu yang ditolak, dengan catatan wajib.
  **Kewajiban tetap `OPEN`** — uang tetap terutang — dan pengajuan baru (intent baru) boleh
  dibuat. Penolakan tidak pernah menghapus utang.
* **Dibatalkan pembuat**: pembuat boleh menarik pengajuan `REQUESTED` dengan alasan.
* **Dicabut sesudah disetujui**: pengajuan `APPROVED` (belum dibayar) hanya boleh dibatalkan
  oleh akun tingkat pemeriksa yang **bukan** pembuat, dengan alasan. Setelah ada pembayaran
  keluar (Sprint 5), pembatalan tidak lagi mungkin.
* Menghapus utang kepada pasien (pelepasan/forfeiture) **bukan** bagian dari Sprint 4; bila
  dibutuhkan, itu keputusan Owner tersendiri.

**Rekomendasi teknis:** setujui seperti usulan.

### RF-7 — Interaksi dengan laporan ketidaksesuaian (dispute) aktif

**Temuan.** Laporan ketidaksesuaian boleh dibuat untuk tagihan berstatus Lunas, termasuk yang
punya kewajiban `OPEN`. Selama kewajiban `OPEN`, koreksi berikutnya ditolak (PP-3), sehingga
laporan yang dibuat **sesudah** kewajiban terbentuk hanya dapat diselesaikan sebagai "tagihan asli
benar" (`ORIGINAL_CONFIRMED_CORRECT`) sampai kewajibannya selesai. (Laporan yang dibuat **sebelum**
koreksi sumbernya diterapkan masih dapat diselesaikan sebagai `CORRECTION_APPLIED`.)

**Usulan.** Pengajuan dan persetujuan refund **ditolak** selama tagihannya punya laporan
ketidaksesuaian aktif (`REFUND_INVOICE_DISPUTED`) — konsisten dengan OPS-OQ-1 ("dibiarkan belum
terselesaikan secara finansial").

* **Risiko bila tidak.** Uang dikembalikan berdasarkan jumlah yang sedang dipersoalkan.
* **Rekomendasi teknis:** setujui; dan Owner diminta menyadari kebuntuan penyelesaian di atas
  (bukan diperbaiki di Sprint 4).

### RF-8 — Visibilitas dan pelaporan di Sprint 4 (memperluas **D19**, PP-6, PP-9; D8/D9/D13)

**Usulan.** Pengajuan refund dapat dibaca (read-only) oleh Owner dan peran oversight melalui
`ClinicOversightScope` area koreksi tagihan; kasir cabang tagihan melihat status pada tagihan.
Tidak ada KPI yang berubah di Sprint 4 (tidak ada uang bergerak). Keadaan "Refund disetujui —
belum dibayar" ditampilkan **terpisah** dari omzet dan piutang. Atribusi = cabang tagihan.
Netting refund pada pendapatan baru terjadi saat `SETTLED` (Sprint 5, sesuai PP-6).

**Fakta yang perlu diketahui.** Saat ini hanya Finance Klinik dan Data Analyst yang memegang izin
oversight koreksi tagihan (`view_billing_correction_oversight`). **Owner tidak memegangnya**, dan
halaman oversight itu dikunci dengan izin tersebut, sehingga Owner belum bisa melihat kewajiban
kelebihan bayar maupun (nantinya) pengajuan refund di sana.

**Sub-pertanyaan RF-8a.** Apakah Owner diberi izin baca `view_billing_correction_oversight`?

Evaluasi (diperiksa pada kode 2026-10-31):

| Pertanyaan | Jawaban |
|---|---|
| Rute yang terbuka | 9 rute oversight koreksi tagihan, **semuanya GET** (baca saja); kelak ditambah daftar pengajuan refund (GET) |
| Cabang yang terlihat | semua cabang RME aktif selain MAIN (`ClinicOversightScope`); detail di luar cakupan dijawab 404 |
| Data yang terlihat | riwayat koreksi, versi dokumen, kewajiban kelebihan bayar, dan (kelak) pengajuan refund per pasien/tagihan; NIK tetap bertopeng |
| Ekspor | **tidak** ikut — ekspor memerlukan izin terpisah `export_oversight_data`, yang **tidak** diusulkan untuk Owner |
| Kuasa menulis | **tidak ada**: izin ini tidak membuka pengajuan atau persetujuan koreksi, `manage_rme_billing`, pengajuan/persetujuan refund, maupun pembayaran keluar; tidak ada rute POST di area ini |

Bila ya, perubahan hanya menambah satu izin baca ke peran Owner (diterapkan pada sprint implementasi,
setelah membandingkan izin produksi dengan `RoleSeeder`). Bila tidak, Owner tetap tanpa akses ke area ini
walaupun PP-9 menyebutnya sebagai pembaca.

**Rekomendasi teknis:** setujui RF-8; untuk RF-8a, beri Owner **hanya** `view_billing_correction_oversight`
(tanpa `export_oversight_data`, tanpa izin pengajuan, persetujuan, atau pembayaran). Tidak ada izin baru
yang diciptakan.

---

## 4. Keputusan yang memblokir Sprint 5 (Tingkat B) — diputuskan sebelum Sprint 5

Keputusan ini tidak menghalangi Sprint 4, tetapi Owner sebaiknya memutuskannya lebih awal agar
Sprint 4 tidak membuat skema yang harus diubah.

### RF-9 — Metode refund (menjawab **D5**)

| Pilihan | Isi |
|---|---|
| A | Transfer bank saja; nomor referensi bank wajib |
| B | Tunai saja di kasir; tanda terima ditandatangani pasien |
| **C** | Transfer dan tunai, dengan bukti sesuai metode |
| D | Mengikuti metode pembayaran asli |

Teks tercetak saat ini: *"Dana refund yang melalui proses pengajuan akan dikembalikan di akhir
bulan pengajuan refund."* Itu menunjukkan pembayaran dikelompokkan di akhir bulan, tetapi belum
pernah dicatat sebagai keputusan register.

* **Risiko keamanan.** Tunai di kasir paling mudah disalahgunakan; transfer meninggalkan jejak
  bank. Nomor rekening pasien adalah data sensitif (disimpan privat, ditampilkan bertopeng).
* **Rekomendasi teknis: C** dengan bukti wajib per metode; pengelompokan akhir bulan diperlakukan
  sebagai jadwal operasional, bukan aturan sistem. Sprint 4 hanya boleh mencatat **metode yang
  diminta pasien** bila Owner menyetujuinya; metode sebenarnya adalah fakta pembayaran (Sprint 5).

### RF-10 — Pembayar ≠ penyetuju (menjawab **D25**)

| Pilihan | Isi |
|---|---|
| A | Tiga akun berbeda: pembuat, penyetuju, pembayar |
| **B** | Pembayar ≠ penyetuju (pembuat boleh sama dengan pembayar) |
| C | Tanpa batasan |

* **Dampak operasional.** A membutuhkan tiga akun aktif per cabang; ukur dulu kepegawaian per
  cabang. **Rekomendasi teknis: B minimum**, A bila kepegawaian memungkinkan.

### RF-11 — Waktu uang keluar (analog **D24**, **D27**)

Usulan: `settled_at` diisi dari jam server saat pencatatan, atau dimasukkan dalam rentang mundur
terbatas yang diputuskan Owner; tidak pernah di masa depan; dilaporkan sebagai hari WITA.
Keputusan Sprint 5.

---

## 5. Konfirmasi pengecualian lingkup (Tingkat C)

| Kode | Usulan | Rekomendasi |
|---|---|---|
| **RF-12 (D6)** | **Tidak ada saldo kredit pasien** di Sprint 4/5. Kewajiban hanya diselesaikan dengan uang. Kredit membutuhkan buku besar baru dan aturan offset antartagihan | Setujui pengecualian |
| **RF-13 (D26)** | **Pembalikan pembayaran bukan refund.** Kesalahan input pembayaran (uang tidak pernah diterima / tercatat ganda) adalah pembalikan tanpa uang bergerak, alur kerja tersendiri dengan keputusan tersendiri. Refund Sprint 4 hanya menerima kewajiban `OPEN` dari koreksi yang diterapkan, tidak pernah pembayaran langsung | Setujui pengecualian |
| **RF-14** | Ketentuan refund tercetak (Kawat Gigi ≤ 2×24 jam; dikembalikan akhir bulan) **tidak** ditegakkan sistem di Sprint 4 (PP-7: tidak ada tenggat yang dikarang). Tetap sebagai SOP konter | Setujui |

Offset antartagihan (memakai kelebihan bayar untuk melunasi tagihan lain) juga **tidak**
dibangun tanpa keputusan tersendiri.

---

## 6. Cara Owner menjawab

Owner cukup menyatakan, per kode, salah satu: "setuju pilihan X", "setuju dengan perubahan …",
atau "tunda". Contoh: *"RF-1 = A; RF-1a = identitas pilot sama, aktivasi menunggu tanda merah
selesai; RF-2 = A; RF-3 = setuju; RF-4 = A; RF-5 = B; RF-6 = setuju; RF-7 = setuju; RF-8 =
setuju; RF-8a = ya/tidak; RF-12/13/14 = setuju; RF-9/10/11 = tunda sampai Sprint 5."*

Jawaban dicatat apa adanya di register, tanpa tanda tangan, referensi, atau waktu yang
dikarang. **Implementasi Sprint 4 dapat dimulai hanya setelah RF-1, RF-2, RF-3, RF-4, RF-5,
RF-6, RF-7 dan RF-8 dijawab.**

---

## 7. Jawaban Owner 2026-10-31 dan rekonsiliasi

**Sumber.** Dua pernyataan Owner pada 2026-10-31 (WITA) yang disampaikan dalam brief tugas:
(S1) bagian keputusan Owner dalam brief `FIX-CLINIC-OVERSIGHT-DATE-RANGE-TEST-DETERMINISM-1`, dan
(S2) "Sprint 4 Owner Decision Addendum" (Decision A dan Decision B). Dicatat apa adanya — tanpa tanda
tangan, referensi, atau waktu yang dikarang, dan tanpa bukti persetujuan berlaku surut. Penomoran Owner
di S1 ("RF-1 … RF-6") **tidak** sama dengan penomoran paket ini; pemetaan di bawah mengikuti **isi**.

### 7.1 Pemetaan

| # | Pernyataan Owner (isi) | Sumber | Item paket | Status |
|---|---|---|---|---|
| O-1 | Hanya **Front Office** yang mengajukan refund, dan hanya untuk **cabang kerja yang ditugaskan**. Akun Kasir dan Admin Klinik biasa **tidak** memperoleh wewenang pembuat lewat izin warisan | S1, S2-B | sisi pembuat RF-1; mengganti usulan desain "Kasir, Front Office" | `OWNER_POLICY_APPROVED` |
| O-2 | Peninjau (setuju/tolak): **Super Admin** — semua cabang; **Supervisor RME** — cabang dalam cakupan sah; **Kepala Cabang** — hanya cabang yang dikelolanya, dengan izin refund yang sempit dan **tanpa** akses RME luas | S1, S2-B | RF-1 (D4), sisi pemeriksa — opsi **berbasis peran dengan cakupan cabang** (bukan A/B/C/D) | `OWNER_POLICY_APPROVED` |
| O-3 | **Tidak ada persetujuan oleh diri sendiri**; pemisahan pembuat-pemeriksa dipertahankan | S1, S2-B | klausul pembuat ≠ pemeriksa di RF-1 (pemisahan yang sama berlaku pada pencabutan, tetapi apakah pencabutan ada adalah RF-6, PENDING) | `OWNER_POLICY_APPROVED` |
| O-4 | Setiap pengajuan refund wajib membawa **referensi persetujuan Owner** yang sah, spesifik kasus dan dapat diaudit; persetujuan Owner **mendahului** pemrosesan pengajuan; referensi **terkait dengan tagihan dan kewajiban kelebihan bayar** yang bersangkutan; **tidak dipakai ulang** untuk keputusan finansial lain menurut kontrak keunikan; divalidasi di **aplikasi dan database** | S2-A | RF-1, bagian referensi = **opsi A** (bukan B) | `OWNER_POLICY_APPROVED` |
| O-5 | **Settlement terpisah** — persetujuan bukan pembayaran | S1 | RF-2 (D18) = **A** | `OWNER_POLICY_APPROVED` (sebatas pemisahan) |
| O-6 | **Refund sebagian tidak diperbolehkan** | S1 | RF-4 = **A** | `OWNER_POLICY_APPROVED` |
| O-7 | **Audit permanen** | S1 | prinsip desain (event append-only, tanpa hapus, riwayat finansial tidak diubah) — bukan RF bernomor | `OWNER_POLICY_APPROVED` |

S2 juga menegaskan, tanpa kebijakan baru: hardening F-17 tetap terpisah; koreksi setelah bayar **tidak**
diaktifkan; refund tidak diaktifkan; data finansial produksi tidak diubah.

### 7.2 Konsekuensi desain yang mengikuti (bukan kebijakan baru)

1. **Referensi Owner ada sejak pengajuan.** Pembuat mencantumkannya saat mengajukan; kolomnya `NOT NULL`;
   referensi dikonsumsi di registri bersama saat pengajuan dibuat; peninjau memverifikasinya dan **tidak**
   memasukkan referensi baru. Desain sebelumnya (referensi saat persetujuan) diganti.
2. **Wewenang pembuat diperiksa dari peran, bukan izin saja.** `Gate::before` memberi Super Admin semua
   izin dan Supervisor RME memegang banyak izin RME; keduanya **bukan** pembuat. Service memeriksa peran
   Front Office + cabang kerja akun. Izin `request_rme_refund` hanya diberikan kepada Front Office dan tidak
   diturunkan dari `manage_rme_billing`.
3. **Wewenang peninjau = peran + cakupan cabang yang diselesaikan server**, tidak pernah dari `branch_id`
   permintaan: Super Admin → semua cabang RME aktif; Supervisor RME → cakupan sah akun itu; Kepala Cabang →
   cabang yang dikelolanya. Kepala Cabang hanya menerima `approve_rme_refund` dan halaman tinjau yang sempit.
4. **Melihat cabang tetap bukan wewenang.** Owner, Finance Klinik, Data Analyst dan peran lain tidak
   meninjau refund.
5. Akun yang memegang dua peran (mis. Front Office dan Kepala Cabang) boleh mengajukan dan meninjau,
   **tetapi tidak pengajuan yang sama**.

### 7.3 Yang tetap PENDING setelah jawaban pertama

*Catatan sejarah: seluruh daftar di bawah dijawab oleh jawaban kedua pada hari yang sama (§7.4). Teks dibiarkan
seperti saat dicatat.*


* **RF-1a** — identitas pilot (tanda merah `HUMAN_SEPARATION` Sprint 2 belum selesai).
* **Pemakaian ulang referensi pada pengajuan ulang** untuk kewajiban yang sama setelah ditolak atau
  dicabut. Default desain sampai Owner memutuskan bersama RF-6: **tidak boleh** — pengajuan ulang membawa
  referensi baru.
* **RF-3, RF-5, RF-6, RF-7, RF-8, RF-8a**, serta **RF-9 (D5), RF-10 (D25), RF-11, RF-12 (D6), RF-13 (D26),
  RF-14**. D5, D6, D25 dan D26 tetap `PENDING_OWNER_DECISION`.
* **Implementasi Sprint 4 tetap terblokir**: §6 mensyaratkan RF-1 … RF-8 terjawab; RF-3, RF-5, RF-6, RF-7
  dan RF-8 belum.
* Hardening F-17 (`HARDENING-RME-OWNER-REFERENCE-REGISTRY-1`) tetap terpisah dan **NOT_STARTED**; koreksi
  setelah bayar tetap OFF.

### 7.4 Jawaban Owner kedua 2026-10-31 — seluruh rekomendasi disetujui, dengan perubahan

**Sumber (S3).** "Master Execution Prompt — Sprint 4/7 — Refund Request & Approval — Owner Decisions Approved"
2026-10-31 (WITA), bagian 7: *"The Owner now expressly approves the recommendations contained in the
canonical Sprint 4 refund decision package"*, dengan perubahan per kode. Dicatat apa adanya — tanpa tanda
tangan, nomor dokumen, waktu, atau bukti persetujuan yang dikarang. Persetujuan kebijakan **bukan** izin
implementasi transaksi keuangan lain, dan **bukan** izin aktivasi.

| Kode | Keputusan Owner (isi) | Terhadap rekomendasi paket | Status |
|---|---|---|---|
| **RF-1 (D4)** | Seperti §7.1 O-1…O-4 (pembuat hanya Front Office di cabang kerjanya; peninjau Super Admin — semua cabang, Supervisor RME — cakupan sah, Kepala Cabang — cabangnya sendiri dengan izin sempit; referensi Owner wajib **saat pengajuan**, diverifikasi peninjau dan **tidak** diganti peninjau; pembuat ≠ peninjau) | opsi berbasis peran, bukan A | `OWNER_POLICY_APPROVED` |
| **RF-1a** | Pasangan pilot 29/1 **tidak** dipakai ulang otomatis; kasus #42 tidak memenuhi kriteria penerimaan pra-otorisasi. Pilot refund mensyaratkan pembuat Front Office nyata yang ditugaskan, peninjau yang diidentifikasi terpisah, identitas akun berbeda, pemisahan manusia yang **terverifikasi**, otorisasi cabang nyata, persetujuan Owner sebelumnya yang nyata, dan persetujuan aktivasi yang berlaku. Bila identitas tidak dapat dibuktikan: `PILOT_ACTIVATION = NO_GO`. Tanpa penugasan operator palsu, tanpa pilot sintetis di produksi | lebih ketat dari rekomendasi | `OWNER_POLICY_APPROVED` (`NO_AUTOMATIC_PILOT_PAIR_REUSE`, `REAL_HUMAN_SEPARATION_REQUIRED`) |
| **RF-2 (D18)** | **A**: persetujuan tidak pernah memindahkan uang; Sprint 4 tanpa rute, izin, tombol, proses, atau transaksi pembayaran keluar; pembayaran keluar = Sprint 5 | = rekomendasi | `OWNER_POLICY_APPROVED` |
| **RF-3** | Flag terpisah `rme.billing_refund_request`, default OFF, gagal-tertutup; pilot SPN4 kecuali Owner kelak mengubahnya secara sah. Urutan aktivasi: (1) Sprint 2 Full Prepayment Pilot GO; (2) F-17 dideploy dan terverifikasi; (3) Sprint 3 diizinkan dan diaktifkan tersendiri; (4) minimal satu kewajiban kelebihan bayar `OPEN` yang **nyata**; (5) pembuat Front Office dan peninjau independen terkonfirmasi; (6) persetujuan aktivasi refund oleh Owner. Flag tidak dinyalakan selama implementasi Sprint 4; pilot tidak diperluas | = rekomendasi, urutan ditambah langkah (2) dan (5) | `OWNER_POLICY_APPROVED` |
| **RF-4** | **A**: refund penuh; satu pengajuan = seluruh jumlah kewajiban `OPEN` yang otoritatif, dihitung server; tanpa refund sebagian, tanpa pemutihan utang, tanpa offset kredit otomatis | = rekomendasi | `OWNER_POLICY_APPROVED` |
| **RF-5** | **B**: referensi permintaan pasien **wajib** (6–120 karakter; tanpa NIK atau varian nomor identitas yang jelas), selain alasan dan kewajiban; tanpa pengumpulan nomor rekening di Sprint 4; tidak boleh dipakai ulang untuk kewajiban lain; pengajuan ulang yang sah untuk kewajiban yang **sama** setelah ditolak boleh memakai referensi yang sama. Referensi pasien ≠ referensi Owner — kontrak keunikannya berbeda | = rekomendasi | `OWNER_POLICY_APPROVED` |
| **RF-6** | Penolakan: hanya pengajuan itu, alasan wajib, klaim asli tetap, kewajiban tetap `OPEN`, utang tidak dihapus atau dianggap lunas. Penarikan oleh pembuat: hanya saat `REQUESTED`, alasan diaudit, terotorisasi. Pencabutan `APPROVED` yang belum dibayar: hanya lewat alur tingkat peninjau, oleh akun **selain** pembuat, dengan cakupan cabang yang benar dan alasan diaudit. Setelah benar-benar dibayar (Sprint 5): tidak ada pembatalan/pencabutan biasa. **Aturan pengajuan ulang yang lebih aman:** pengajuan baru sesudah penolakan, penarikan, atau pencabutan **wajib membawa referensi persetujuan Owner yang BARU**; referensi lama tidak dapat mengotorisasi keputusan refund baru. Referensi pasien yang sama boleh dipakai lagi untuk kewajiban yang sama sesuai RF-5 | = rekomendasi + aturan referensi | `OWNER_POLICY_APPROVED` |
| **RF-7** | Pengajuan **dan** persetujuan refund ditolak selama tagihan sumbernya punya laporan ketidaksesuaian aktif (`REFUND_INVOICE_DISPUTED`); perilaku OPS-OQ-1 dipertahankan; dispute tidak diselesaikan otomatis, hold pembayaran tidak dilepas, status koreksi tidak diubah; ketergantungan penyelesaian dispute dicatat (§7.5 K-7), mesin dispute tidak diubah | = rekomendasi | `OWNER_POLICY_APPROVED` |
| **RF-8** | Oversight baca-saja atas pengajuan refund; "disetujui — belum dibayar" ditampilkan terpisah dari omzet, piutang, kas, dan saldo bank; persetujuan tidak mengubah KPI; atribusi = cabang tagihan sumber; tidak ada netting pendapatan karena persetujuan | = rekomendasi | `OWNER_POLICY_APPROVED` |
| **RF-8a** | Owner diberi **hanya** izin yang sudah ada `view_billing_correction_oversight` — **bukan** `export_oversight_data`, dan bukan izin pembuat, peninjau, pembayaran keluar, harga, koreksi tagihan, atau tulis RME umum. Rute yang dilindungi diperiksa ulang sebelum diterapkan; tanpa NIK atau detail pasien tanpa batas | = rekomendasi | `OWNER_POLICY_APPROVED` (izin diterapkan pada sprint implementasi) |
| **RF-9 (D5)** | **C** sebagai kebijakan Sprint 5: transfer bank dan tunai, bukti sesuai metode; nomor rekening = data sensitif; referensi bank dan bukti pembayaran milik alur pembayaran keluar; tidak ada transfer, pembayaran tunai, atau settlement di Sprint 4; pengelompokan akhir bulan tetap SOP operasional | = rekomendasi | `OWNER_POLICY_APPROVED`, implementasi Sprint 5 PENDING |
| **RF-10 (D25)** | **B**: pembayar ≠ penyetuju; pembuat boleh menjadi pembayar hanya bila kelak memenuhi syarat izin pembayaran keluar dan semua kontrol lulus. Tidak menunjuk pembayar dan tidak memberi izin pembayaran keluar kepada peran mana pun | = rekomendasi (minimum) | `OWNER_POLICY_APPROVED`, aktor pembayaran keluar PENDING |
| **RF-11** | Waktu pembayaran keluar milik Sprint 5: cap waktu server, tanggal laporan WITA, tidak pernah di masa depan, **tanpa** penanggalan mundur oleh pengguna kecuali diizinkan tersendiri (rentang mundur **tidak** ditetapkan, sehingga tidak dikarang) | lebih ketat dari usulan (tidak ada rentang mundur) | `OWNER_POLICY_APPROVED` (aturan konservatif) |
| **RF-12 (D6)** | Tanpa saldo kredit pasien, tanpa offset antartagihan, tanpa memakai kelebihan bayar untuk tagihan lain; sistem kredit butuh otorisasi tersendiri | = rekomendasi | `OWNER_POLICY_APPROVED_EXCLUSION` |
| **RF-13 (D26)** | Pembalikan pembayaran bukan refund; pembayaran salah/ganda butuh alur pembalikan dan otorisasi tersendiri; Sprint 4 hanya menerima kewajiban `OPEN` dari koreksi yang memenuhi syarat | = rekomendasi | `OWNER_POLICY_APPROVED_EXCLUSION` |
| **RF-14** | Ketentuan refund tercetak (Kawat Gigi, akhir bulan) tetap SOP operasional; tidak ada tenggat sistem yang dikarang atau ditegakkan otomatis di Sprint 4; teks kontraktual tercetak tidak dihapus tanpa keputusan tersendiri | = rekomendasi | `OWNER_POLICY_APPROVED` |

### 7.5 Laporan kontradiksi

| # | Titik | Temuan | Penyelesaian |
|---|---|---|---|
| K-1 | RF-5 vs desain §9 | Teks Owner menyebut pengajuan ulang "setelah ditolak"; desain juga mengizinkan pengulangan referensi pasien sesudah `CANCELLED`/`STALE` untuk kewajiban yang sama | Bukan konflik: kontrak keunikan RF-5 adalah **per kewajiban** ("tidak boleh dipakai ulang untuk kewajiban lain") dengan alasan "permintaan pasien yang sama", yang juga berlaku sesudah penarikan atau kedaluwarsa. Referensi pasien tidak mengotorisasi apa pun; otorisasinya adalah referensi Owner, yang selalu baru (RF-6). Owner dapat mempersempitnya kelak |
| K-2 | RF-6 vs PP-2 | Refund: referensi Owner baru untuk setiap pengajuan baru. Koreksi setelah bayar (PP-2): pengajuan ulang pada **tagihan yang sama** boleh mengulang referensinya | Bukan konflik: dua kontrak, dua cakupan registri (`REFUND_REQUEST` per pengajuan; `POST_PAYMENT_CORRECTION_INVOICE` per tagihan). Keduanya dipertahankan; tidak ada referensi yang dapat berpindah antar-alur |
| K-3 | RF-1a vs PP-1/D1e | Pasangan 29/1 adalah identitas pilot koreksi | Tidak diubah untuk koreksi. Refund tidak mewarisinya; tanda merah `HUMAN_SEPARATION` Sprint 2 tetap ada dan tidak ditulis ulang. Kronologi kasus #42 dipertahankan apa adanya |
| K-4 | RF-3 vs usulan paket | Owner menambah F-17 terverifikasi dan konfirmasi staf ke urutan aktivasi | Urutan Owner dipakai; usulan paket adalah himpunan bagiannya |
| K-5 | RF-11 vs usulan paket | Paket membuka kemungkinan rentang mundur yang diputuskan Owner | Owner memilih tanpa rentang mundur kecuali diizinkan tersendiri. D24/D27 (`paid_at` pembayaran masuk) tetap PENDING — bidang dan domain lain |
| K-6 | RF-8a vs PP-9 | PP-9 menyebut Owner sebagai pembaca, tetapi Owner tidak memegang izinnya | Diselesaikan RF-8a. Diperiksa ulang pada kode 2026-10-31: grup rute `view_billing_correction_oversight` = 9 rute, **semuanya GET**; 4 di antaranya (CSV, cetak daftar, cetak dan PDF per koreksi) juga mensyaratkan `export_oversight_data`, sehingga Owner tanpa ekspor mencapai 5 rute baca (daftar, pasien, detail koreksi, daftar dan detail kewajiban). §3 RF-8a ("Ekspor tidak ikut") tetap benar |
| K-7 | RF-7 vs mesin dispute | Selama kewajiban `OPEN`, PP-3 menolak koreksi berikutnya, sehingga dispute yang diajukan sesudah kewajiban terbentuk hanya dapat diselesaikan sebagai `ORIGINAL_CONFIRMED_CORRECT` | Ketergantungan dicatat, mesin dispute **tidak** diubah; pasien menunggu sampai dispute selesai (model ancaman §3) |
| K-8 | D4 teks asli | "Super Admin required for refund" | Digantikan jawaban Owner RF-1 (sudah dicatat di register D4) |
| K-9 | RF-10 vs RF-1 | Pembuat (Front Office) boleh menjadi pembayar kelak | Bukan konflik: Sprint 4 tidak memberi izin pembayaran keluar kepada siapa pun; aktor pembayar ditetapkan sebelum Sprint 5 |

**Hasil: `OWNER_DECISION_SYNC = PASS`; kontradiksi yang belum terselesaikan: 0.**

### 7.6 Yang tersisa — bukan keputusan kebijakan Sprint 4

* **Syarat §6 terpenuhi dari sisi Owner** (RF-1 … RF-8 dan RF-8a terjawab). Implementasi tetap menunggu
  gerbang repositori dan teknis: PR #567 (perbaikan tes tanggal) dan paket ini digabung melalui review yang
  sah; sisi koreksi dari registri F-17 (`HARDENING-RME-OWNER-REFERENCE-REGISTRY-1`) dideploy dan diverifikasi
  sebelum refund menjadi konsumen keduanya.
* **Aktivasi refund** = keputusan Owner tersendiri (RF-3 langkah 6), dengan identitas pilot nyata (RF-1a).
* **Sprint 5:** penunjukan aktor pembayar (RF-10), implementasi metode dan bukti (RF-9), aturan waktu (RF-11).

### 7.7 Catatan implementasi Sprint 5 (FEATURE-RME-REFUND-SETTLEMENT-1, 2026-10-31)

| Keputusan | Implementasi | Status |
|---|---|---|
| **RF-9 (D5) = C** | Metode `BANK_TRANSFER` dan `CASH` saja, masing-masing dengan bukti sesuai metode (bukti transfer bank; tanda terima bertanda tangan penerima). Hanya 4 digit terakhir rekening yang disimpan. Tidak ada API bank. | `IMPLEMENTED`, flag OFF |
| **RF-10 (D25) = B** | Pembayar ≠ penyetuju di service dan CHECK PostgreSQL; pembuat boleh menjadi pembayar bila ditunjuk tersendiri. Izin `settle_rme_refund` tidak diberikan ke peran mana pun (termasuk `*` Super Admin); daftar pembayar `pilot.settlers` kosong. | `IMPLEMENTED`; **penunjukan pembayar: `PENDING_OWNER_DESIGNATION`** |
| **RF-11** | `settled_at` = waktu server; tidak ada isian tanggal oleh pengguna dan tidak ada rentang mundur. Waktu transfer sebenarnya ada di bukti. | `IMPLEMENTED` |

Yang masih memerlukan keputusan Owner sebelum aktivasi: (1) nama orang yang ditunjuk sebagai pembayar per
cabang, (2) pelatihan SOP `docs/runbooks/rme-refund-settlement-sop.md`, (3) persetujuan aktivasi tersendiri
(`ACTIVATION-RME-REFUND-SETTLEMENT-1`). Implementasi tidak menunjuk siapa pun.
