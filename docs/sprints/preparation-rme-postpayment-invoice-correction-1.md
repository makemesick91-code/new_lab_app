# PREPARATION-RME-POSTPAYMENT-INVOICE-CORRECTION-1

Program `DAENGTISIAMS-RME-BILLING-CORRECTION` — **persiapan Sprint 3/7**
(`OWNER_DECISION_AND_ARCHITECTURE_PREPARATION`). Fitur target:
`FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1`. Tanggal: 2026-10-25 (WITA).

> Tugas ini **dokumen dan rule saja**. Tidak ada kode aplikasi, migrasi, seeder,
> konfigurasi, flag, atau izin yang berubah. Tidak ada deploy. Tidak ada keputusan
> Owner yang dicatat sebagai disetujui. Koreksi pasca-bayar tetap
> **NOT_IMPLEMENTED / NOT_ACTIVE**.

## 1. Baseline (terverifikasi)

| Item | Nilai |
|---|---|
| Produksi | `20303a5b92f7bc85d0b34abfbc1c880ef49d6112`, tree `058d66ff`, `git describe --exact-match` = `fix-rme-payment-submission-idempotency-1-go`; working tree bersih kecuali `storage/runtime-home/` (untracked, bukan milik tugas ini) |
| Basis kanonik | `origin/feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `97e561b0` (dokumen saja di atas `20303a5b`) |
| Rule terbaru sebelum tugas | 205 (`.cursor/rules/205-rme-payment-submission-idempotency-financial-integrity.mdc`) |
| Rule yang ditambahkan | 206 (persiapan, semua kebijakan `PROPOSED`) |
| Sprint 2/7 | `OPERATIONAL_BLOCKED` menurut brief — **tidak diubah** oleh tugas ini |
| Full Prepayment Pilot | `NO_GO` — **tidak diubah** |

Fondasi yang dipertahankan: D1, D1a, D1c, D1e, D7, D10, D21, D28, OPS-OQ-1/1a/1b,
OD-1/OD-1a, rule 205. D21c, D21g, D22: **PENDING**.

## 2. Alat yang dipakai

| Alat | Pemakaian |
|---|---|
| Limit Saver | skill dimuat; baca terarah (grep/sed per method), tanpa pemindaian vendor/storage |
| Superpowers | skill dimuat; rencana ≤5 langkah, iterasi kecil |
| Graphify | graf di checkout utama berasal dari branch lama → tidak dipakai; graf dibangun ulang di worktree (`graphify update app --no-cluster`, AST-only): 18.974 node / 40.855 edge; `path`/`explain` dijalankan dan dicek silang dengan sumber (desain §2.1) |
| DataViz | tersedia sebagai skill; **tidak dipakai** — deliverable berupa matriks dan diagram state (mermaid), tidak ada deret kuantitatif yang memerlukan grafik |
| MCP | tidak ada MCP yang relevan; tidak dipakai |
| DEVFLOW | lihat §6 |

## 3. Deliverable

| Deliverable (brief Phase 6) | Lokasi |
|---|---|
| Architecture Decision Record | `docs/adr/0012-rme-postpayment-invoice-correction.md` |
| Owner Decision Matrix | `docs/decisions/rme-postpayment-correction-owner-decision-proposals.md` §1–§4 + ringkasan di register |
| Financial Scenario Matrix (A–L) | desain §3 |
| Financial Invariant Matrix | desain §4 (FI-1…FI-16) |
| State Transition Diagram | desain §5 |
| PostgreSQL Concurrency Plan | desain §7 (urutan lock L1→L2→L3, race R1–R8) |
| TDD Test Matrix | desain §12 |
| Security Threat Model | desain §8 (T1–T14) |
| Financial Reconciliation Plan | desain §9 + SOP draf §7 (SQL tervalidasi read-only di produksi) |
| Migration Safety Plan | desain §10 |
| Rollback Compatibility Plan | desain §11 |
| Operator SOP Draft | `docs/operations/rme-postpayment-invoice-correction-sop-draft.md` |
| Implementation Sprint Plan | desain §13 (P0–P9) |
| Acceptance Criteria | desain §14 |
| Production Release Gates | desain §15 (RG-0…RG-7) |
| Rule | `.cursor/rules/206-rme-postpayment-invoice-correction-preparation.mdc` |

## 4. Temuan utama

1. **Koreksi pasca-bayar ditolak di kode** (`CORRECTION_TYPE_NOT_ENABLED`), tetapi
   skema sudah menerima `POST_PAYMENT`. Mengaktifkannya dengan mengubah satu konstanta
   akan melewati `applyLocked()` yang mengasumsikan UNPAID — rule 206 §E melarangnya.
2. **D10 dan D28 sudah tipe-agnostik**; fingerprint sudah memuat pembayaran.
3. **Kwitansi dirender live** — koreksi PAID akan mengubah kwitansi yang sudah terbit
   tanpa snapshot (Fase E). Catatan teknis D7 "no issued receipt is ever invalidated"
   tidak lagi benar untuk pasca-bayar → ditandai `CONFLICTING` dan dijadikan PP-5.
4. **Kelebihan bayar tidak dapat direpresentasikan hari ini** dan harus menjadi
   kewajiban terpisah, bukan piutang negatif (konsumen piutang di-clamp/filter).
5. **D23 berdampak nyata**: 2 dari 7 tagihan positif berbayar (#38, #42) sudah
   memiliki kandidat lab dan akan ditolak.
6. **Bukti produksi baru yang belum tercatat** (desain §1): koreksi pra-bayar nyata
   pertama `RMECOR-202610-000001` (tagihan #42, 11:20–11:23 WITA, maker 29 → checker
   1, D1a atomik, v0+v1), dilanjutkan pembayaran #50 (lunas) — dan penetapan peninjau
   dispute pertama (user 11, SPN4). Sprint `FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1`
   mencatat pembayaran #50 tetapi tidak mencatat koreksinya. Tugas ini **tidak**
   memverifikasinya dan **tidak** mengubah status Sprint 2; alasan koreksi tidak
   memuat referensi persetujuan Owner yang dapat dikenali.

## 5. Keamanan

Review keamanan desain (tidak ada kode): model ancaman T1–T14 (desain §8.2). Residu
yang diterima secara organisasional: kolusi dua akun + Owner (T2), akses database
langsung di luar aplikasi (T7), pengembalian uang di luar sistem (T14 — dilarang di
SOP draf). Tidak ada kebijakan yang memperluas pilot SPN4 atau memberi peninjau
dispute otoritas finansial.

## 6. DEVFLOW, CI, merge, deploy

Diisi setelah PR (lihat bagian *Release evidence* di bawah).

* Klasifikasi CI: semua file berubah adalah `docs/*.md`, `CLAUDE.md`, atau
  `.cursor/rules/*` → profil `docs_only` (diverifikasi dengan
  `scripts/ci/resolve-gates.sh`).
* `.sprint/current.yml` sengaja tidak diubah (file non-Markdown akan mengubah profil).
* **DEPLOY_REQUIRED = NO** — tidak ada perubahan runtime; produksi tidak di-redeploy
  untuk membuat bukti.

## 7. Langkah berikutnya

1. **Owner** meninjau PP-1…PP-10 dan menyatakan keputusan (atau menolak/mengubahnya).
2. **Verifikasi closure Sprint 2** dijalankan ulang atas bukti koreksi #1 (RG-0).
3. Setelah keduanya: `FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1` mulai dari P1
   (uji gagal dulu), dirilis dengan flag pasca-bayar OFF.
