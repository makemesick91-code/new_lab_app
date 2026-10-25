# Usulan Keputusan Owner — Koreksi Tagihan RME Setelah Pembayaran (PP-1 … PP-10)

> **Status dokumen: PROPOSED.** Tidak ada satu pun usulan di sini yang disetujui.
> Kolom "Usulan" adalah rekomendasi desain, **bukan** persetujuan (register:
> "A brief's recommended default or a proposal column is never an approval").
> Sebuah PP baru berlaku setelah Owner menyatakannya dan dicatat di
> `docs/decisions/rme-billing-correction-owner-decisions.md` dengan label
> `OWNER_POLICY_APPROVED`. Sampai saat itu koreksi pasca-bayar tetap
> `NOT_IMPLEMENTED` / `NOT_ACTIVE`.
>
> Disusun oleh `PREPARATION-RME-POSTPAYMENT-INVOICE-CORRECTION-1` (2026-10-25 WITA).
> Desain: `docs/architecture/rme-postpayment-invoice-correction-design.md`.
> ADR: `docs/adr/0012-rme-postpayment-invoice-correction.md`.

## 1. Klasifikasi otoritas yang sudah ada terhadap koreksi pasca-bayar

Label: `ALREADY_APPROVED` (keputusan Owner yang ada sudah mencakupnya),
`PROPOSED` (usulan baru di dokumen ini), `PENDING` (kode register yang masih
terbuka), `CONFLICTING` (teks yang ada bertentangan dengan penerapan pasca-bayar),
`NOT_REQUIRED` (tidak dibutuhkan untuk Sprint 3).

| Kode | Isi | Klasifikasi untuk pasca-bayar | Alasan |
|---|---|---|---|
| D1 | Otoritas approve **pra-bayar**; maker≠checker per akun | `ALREADY_APPROVED` (prinsip maker≠checker) · otoritas pasca-bayar → **PP-2** | D1 eksplisit untuk pra-bayar; otoritas pasca-bayar adalah D2 |
| D1a | Approve = apply, atomik | `PROPOSED` untuk pasca-bayar (PP-2) | Disetujui untuk pra-bayar; penerapan ke tipe baru belum dinyatakan |
| D1b | Front Office pegang request+approve | `NOT_REQUIRED` | Dipersempit D1e; pasca-bayar mengikuti identitas pilot |
| D1c | Pilot SPN4 saja | `PROPOSED` (PP-1) | Disetujui untuk koreksi pra-bayar; berlaku untuk pasca-bayar perlu dinyatakan — fail-closed sampai itu |
| D1d / D1e | Identitas pilot 29 (maker) / 1 (checker) | `PROPOSED` (PP-1) | Sama: berlaku untuk pasca-bayar perlu dinyatakan |
| D2 | Otoritas approve pasca-bayar | `PENDING` → **PP-2** | Belum pernah diputuskan |
| D4, D5, D6, D18, D25 | Refund: approver, metode, kredit pasien, settlement terpisah, settler≠approver | `PENDING`, `NOT_REQUIRED` untuk Sprint 3 | Sprint 3 hanya mencatat kewajiban; tidak ada uang keluar (PP-3) |
| D7 | Versi dokumen v0/vN | `ALREADY_APPROVED` (prinsip) · **`CONFLICTING`** (catatan teknis) → **PP-5** | Catatan teknis D7 menyatakan "only UNPAID invoices are correctable, so no issued receipt is ever invalidated" — tidak berlaku lagi bila PAID dikoreksi |
| D8, D9 | Efek ke omzet historis; bruto vs bersih | `PENDING` → **PP-6** | — |
| D10 | Koreksi aktif menahan pembayaran | `ALREADY_APPROVED` | Ditegakkan tipe-agnostik (`assertNoActiveCorrection`) |
| D12 | Koreksi tagihan dibayar sebagian | `PENDING` → **PP-3/PP-4** | — |
| D13 | Atribusi cabang | `PENDING` → **PP-6** (sebagian sudah ditegakkan OD-1a untuk penagihan) | — |
| D14, D15, D17 | Ambang maksimum, tenggat, dokumen pendukung | `PENDING` → **PP-7** | — |
| D16 | Batal setelah approve | `NOT_REQUIRED` | D1a: APPROVED tidak pernah terlihat transaksi lain |
| D20 | Retensi | `NOT_REQUIRED` | Snapshot/versi imutabel tanpa hapus sudah berlaku |
| D21 | Harga manual kasir | `ALREADY_APPROVED` | Baris yang dikoreksi = harga manual `CORRECTION_MANUAL_ENTRY` |
| D21c | Kebijakan diskon | `PENDING` (`SAFE_TO_DEFER_WITH_EXPLICIT_RESTRICTION`, sama dengan pra-bayar) | Tidak ada plafon yang dikarang |
| D21g, D22 | Rp0 | `PENDING` | D28 tetap; total usulan Rp0 tetap ditolak |
| D23 | Item lab | `PENDING` → **PP-8** | 2 dari 7 tagihan positif berbayar sudah punya kandidat lab |
| D24, D27 | `paid_at` | `NOT_REQUIRED` | Koreksi tidak menulis pembayaran |
| D26 | Reversal pembayaran | `PENDING`, `NOT_REQUIRED` | Bukan koreksi; tidak boleh ditiru dengan koreksi |
| D28 | Penjaga Rp0 asli | `ALREADY_APPROVED` (instruksi Owner, sementara) | Tipe-agnostik |
| OPS-OQ-1, 1a, 1b | Dispute + tahan pembayaran; peninjau cabang; bayar Kontrol saja | `ALREADY_APPROVED` | Interaksi diturunkan dari rule 203/204 (skenario H) |
| OD-1, OD-1a | Kontrol lintas cabang; piutang cabang lain tidak ditagih di sini | `ALREADY_APPROVED` | Sisa tagihan baru ditagih di cabang tagihan |
| Rule 205 | Idempotensi pembayaran | `ALREADY_APPROVED` (teknis) | Form lama ditolak setelah apply |

## 2. Usulan keputusan inti PP-1 … PP-7

### PP-1 — Cakupan dan pengaktifan (terkait D1c, D1e)

* **Pertanyaan:** Apakah koreksi pasca-bayar boleh dibangun, dan bila diaktifkan,
  di mana dan oleh siapa?
* **Opsi:** (A) saklar terpisah default OFF, pilot SPN4, identitas 29/1 yang sama;
  (B) ikut saklar pra-bayar yang sudah ON; (C) semua cabang.
* **Usulan:** **A.** Flag baru `rme.invoice_postpayment_correction` (default OFF,
  risiko critical), allowlist cabang dan identitas pilot yang sama, source-controlled
  tanpa environment. Pengaktifan adalah tugas terpisah setelah deploy.
* **Konsekuensi:** pilot pra-bayar tidak terpengaruh; pasca-bayar dapat dimatikan
  sendiri.
* **Status:** `PROPOSED`.

### PP-2 — Otoritas persetujuan pasca-bayar (D2, perluasan D1a)

* **Pertanyaan:** Siapa yang menyetujui, apakah persetujuan langsung menerapkan,
  dan apakah setiap kasus butuh persetujuan Owner?
* **Opsi:** (A) checker = user 1 saja, approve = apply atomik, persetujuan Owner per
  kasus wajib dan dicatat sebagai referensi; (B) Admin Klinik cabang; (C) Supervisor
  RME.
* **Usulan:** **A.** Maker user 29, checker user 1, maker≠checker per akun di
  layanan (Super Admin termasuk), approve = apply (D1a), **referensi persetujuan
  Owner per kasus wajib** (formulir rule 202) — checker menerima persetujuan
  langsung dari Owner.
* **Status:** `PROPOSED`.

### PP-3 — Koreksi turun dan kewajiban kelebihan bayar (skenario A2, C, F, K; D12)

* **Pertanyaan:** Bolehkah koreksi turun yang membuat uang diterima melebihi total
  baru, sebelum modul refund ada?
* **Opsi:**
  * (A) Boleh, **kewajiban kelebihan bayar dicatat atomik** (`OPEN`, jumlah
    server), tidak ada uang keluar dari sistem sampai modul refund dibangun, koreksi
    berikutnya ditolak selama kewajiban `OPEN`;
  * (B) Ditolak (`CORRECTION_WOULD_CREATE_OVERPAYMENT`) sampai refund ada — hanya
    koreksi turun sampai sebesar uang diterima yang diizinkan;
  * (C) Kredit saldo pasien (D6).
* **Usulan:** **A**, dengan larangan tegas pengembalian uang di luar sistem.
  Alternatif aman bila Owner ingin bertahap: **B**.
* **Tidak termasuk:** refund otomatis, pembayaran negatif, piutang negatif, offset
  ke tagihan lain, kredit pasien.
* **Status:** `PROPOSED`.

### PP-4 — Koreksi naik (skenario B, D; D12)

* **Pertanyaan:** Bolehkah total dinaikkan setelah pasien membayar (termasuk yang
  sudah LUNAS)?
* **Opsi:** (A) boleh; selisih menjadi sisa tagihan biasa, status diturunkan ulang
  (PAID → PARTIAL), ditagih di cabang tagihan lewat pembayaran normal, pasien diberi
  tahu; (B) hanya untuk PARTIAL; (C) tidak boleh.
* **Usulan:** **A**, tanpa penagihan otomatis dan tanpa membuka kembali kunjungan.
* **Status:** `PROPOSED`.

### PP-5 — Dokumen dan kwitansi (penerapan D7 pada tagihan yang sudah dibayar)

* **Pertanyaan:** Apa yang terjadi pada kwitansi yang sudah diserahkan ke pasien?
* **Opsi:** (A) kwitansi di-snapshot saat terbit (Fase E); kwitansi lama dirender
  dari snapshot dengan cap "DIREVISI — lihat versi ke-N"; dokumen kewajiban
  "KELEBIHAN BAYAR — BELUM DIKEMBALIKAN"; (B) kwitansi tetap dinamis (status quo) —
  kwitansi lama akan menampilkan angka baru.
* **Usulan:** **A**, sebagai prasyarat aktivasi. Konfirmasi bahwa D7 berlaku juga untuk
  kwitansi yang sudah terbit (menyelesaikan teks D7 yang `CONFLICTING`).
* **Status:** `PROPOSED`.

### PP-6 — Pelaporan dan atribusi (D8, D9, D13)

* **Pertanyaan:** Bagaimana koreksi pasca-bayar tampil di laporan?
* **Usulan:** KPI kas (Σ pembayaran per `paid_at`) **tidak berubah**; angka "ditagih"
  memakai versi terkini dengan daftar koreksi yang terlihat; periode lampau yang
  ditutup tidak di-restate; kewajiban `OPEN` dilaporkan terpisah (bukan pengurang
  omzet sampai refund SETTLED — Model C desain refund); atribusi ke **cabang
  tagihan**.
* **Status:** `PROPOSED`.

### PP-7 — Batas dan penjaga (D14, D15, D17, D21c, D28)

* **Usulan:** tidak ada ambang nominal yang dikarang (persetujuan Owner per kasus
  menggantikannya, PP-2); tidak ada tenggat otomatis (STALE hanya karena perubahan
  tagihan nyata); dokumen pendukung opsional (referensi persetujuan wajib); diskon
  tunduk pada batasan pra-bayar yang sama (D21c tetap PENDING); D28 tetap.
* **Status:** `PROPOSED`.

## 3. Keputusan tambahan yang tidak dapat diturunkan dari otoritas yang ada

### PP-8 — Kandidat lab (D23)

* **Pertanyaan:** (a) Apakah tagihan yang sudah menghasilkan kandidat lab boleh
  dikoreksi? (b) Bila koreksi membuat tagihan menjadi PAID (skenario E), apakah
  kandidat lab dibuat?
* **Usulan:** (a) tetap ditolak (fail-closed) — produksi: #38 dan #42 ditolak;
  (b) tidak dibuat oleh koreksi di Sprint 3 (kandidat tetap dipicu jalur pembayaran
  saja); keputusan berbeda butuh pernyataan Owner.
* **Status:** `PROPOSED`.

### PP-9 — Visibilitas kewajiban

* **Pertanyaan:** Siapa yang melihat daftar kewajiban kelebihan bayar?
* **Usulan:** Owner dan peran oversight (read-only, `ClinicOversightScope`), kasir
  cabang tagihan melihat status kewajiban pada tagihan; tidak ada peran yang
  mendapat izin tulis atas kewajiban; peninjau dispute (rule 204) tidak mendapat
  otoritas finansial tambahan.
* **Status:** `PROPOSED`.

### PP-10 — Prasyarat urutan program

* **Pertanyaan:** Apakah implementasi pasca-bayar menunggu closure Sprint 2 (pilot
  pra-bayar terverifikasi)?
* **Usulan:** Ya untuk **aktivasi** (RG-0); implementasi dengan flag OFF boleh
  berjalan setelah PP-1…PP-9 dicatat. Bukti baru 2026-10-25 (koreksi nyata #1 pada
  tagihan #42) harus diverifikasi oleh tugas verifikasi closure Sprint 2 — termasuk
  ada atau tidaknya persetujuan Owner per kasus — **bukan** oleh tugas ini.
* **Status:** `PROPOSED`.

## 4. Ringkasan status

| Kode | Status |
|---|---|
| PP-1 … PP-10 | `PROPOSED` (tidak ada yang `OWNER_POLICY_APPROVED`) |
| D21c, D21g, D22 | `PENDING` (tidak berubah) |
| D2, D8, D9, D12, D13, D14, D15, D17, D23 | `PENDING` (dijawab oleh PP terkait bila Owner menyetujui) |
| Koreksi pasca-bayar di produksi | `NOT_IMPLEMENTED` · `NOT_ACTIVE` |
