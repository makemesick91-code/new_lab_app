# Paket Keputusan Owner — Refund RME: Pengajuan & Persetujuan (Sprint 4/7)

> **Status: USULAN. Tidak satu pun keputusan di bawah ini disetujui.**
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
| **D4** | Otoritas persetujuan refund | **`PENDING_OWNER_DECISION`** | register "Pending decisions": "D4, D5, D6, D18, D25 and D26 … remain PENDING" |
| **D5** | Metode refund | **`PENDING_OWNER_DECISION`** | idem; teks tercetak "dikembalikan di akhir bulan" adalah teks dokumen, bukan keputusan register |
| **D6** | Saldo kredit pasien | **`PENDING_OWNER_DECISION`** | idem |
| **D18** | Pemisahan persetujuan dan pembayaran | **`PENDING_OWNER_DECISION`** | idem; lihat catatan §2.1 |
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
