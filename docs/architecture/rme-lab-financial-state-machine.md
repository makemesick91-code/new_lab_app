---
kb_status: current
kb_verified_against: 3a231c28
kb_updated: 2026-11-20
---

# Spesifikasi state machine keuangan RME/LAB — RME-LAB-FEE-APPROVAL-1

Kembali ke [00-INDEX](../00-INDEX.md). Keputusan:
[Owner Design Lock](../decisions/rme-lab-fee-approval-1-owner-design-lock.md). Rule kanonik:
`.cursor/rules/246-rme-lab-fee-approval-and-billing-integrity.mdc`.

**Status:** keputusan bisnis APPROVED (2026-11-20). Seluruh skema, CHECK, service, dan test di bawah ini **PROPOSED /
NOT IMPLEMENTED**. Gate 1: NO-GO.

## 1. Kondisi sekarang (diverifikasi di `3a231c28`)

- **Belum ada klasifikasi biaya LAB.** `trx_rme_invoice_items` tidak punya kolom kategori atau tipe. Baris free-text
  boleh berharga berapa pun (`CreateRmeInvoiceRequest`, `RmeInvoicePricingService`).
- **HAS_LAB dibaca live** dari `mst_treatments.requires_lab` pada saat kandidat dibuat (`RmeLabIntegrationService`).
- **Kontrol uang di database minim.** `trx_rme_invoices` hanya punya tiga CHECK untuk NO_CHARGE; item tidak punya CHECK,
  dan tabel keuangan tidak punya trigger.
- **Status invoice yang ditulis kode:** `UNPAID`, `PARTIAL`, `PAID`, `NO_CHARGE`. Konstanta `DRAFT` dan `VOID` ada,
  tetapi tidak punya penulis.
- **Obligation hanya bisa lahir dari koreksi.** `source_correction_id` bersifat NOT NULL dan unique; statusnya `OPEN` atau
  `SETTLED`.

Semua ini adalah drift implementasi terhadap rule 246 sampai rule itu diimplementasikan.

## 2. Semantik uang

| Aspek | Perilaku |
|---|---|
| Penyimpanan dan hitungan | Rupiah, disimpan `decimal(15,2)`, dihitung sebagai sen integer |
| Pajak | Tidak ada pajak dan tidak ada pembulatan |
| Baris | Bruto = qty × harga; 0 ≤ diskon ≤ bruto (ditegakkan untuk baris baru; data historis bisa melanggar); `item.subtotal` = bruto − diskon = **neto** |
| Invoice | `subtotal` = Σ **bruto**; `discount_total` = Σ diskon; `grand_total` = `subtotal` − `discount_total` = Σ `item.subtotal` |

## 3. Variabel invarian

| Simbol | Definisi |
|---|---|
| L | Σ `item.subtotal` (neto, sen) dari item dengan `line_kind = 'LAB_FEE'` |
| NN | Σ `item.subtotal` (neto) dari item dengan `line_kind <> 'LAB_FEE'` (treatment, termasuk treatment ber-LAB, dan free-text) |
| G | `trx_rme_invoices.grand_total`; untuk invoice terklasifikasi yang tidak REVERSED, G = L + NN |
| HAS_LAB | Ada item dengan `requires_lab_snapshot = true` atau `line_kind = 'LAB_FEE'` (dari snapshot, tidak pernah dari master live) |
| Terklasifikasi | `classification_version IS NOT NULL`. Diisi kode baru saat terbit; tidak di-backfill otomatis |

## 4. Matriks invarian

Keputusan terkait: Inv1 tanpa pengecualian (A-22); Inv3 menghasilkan REVERSED (A-04); subtotal `LAB_FEE` positif (A-24).

| Kelas | Contoh | L | NN | G | HAS_LAB | Hasil |
|---|---|---|---|---|---|---|
| K1 | Rp0 sah tanpa LAB (Konsultasi/Kontrol) | 0 | 0 | 0 | tidak | Terbit normal; tidak terdampak |
| K2 | Invoice positif tanpa LAB | 0 | >0 | >0 | tidak | Normal |
| K3 | Biaya LAB positif dan NN > 0 | >0 | >0 | >0 | ya | Normal, dengan approval sebelum terbit |
| K4 | Treatment ber-LAB berharga, tanpa `LAB_FEE` | 0 | >0 | >0 | ya | Normal |
| K5 | Treatment ber-LAB Rp0, tanpa baris lain | 0 | 0 | 0 | ya | Ditolak, kecuali lewat pengecualian A-02, yang **nonaktif** (A-23) |
| K6 | `LAB_FEE` > 0 dan NN = 0 | >0 | 0 | >0 | ya | **Dilarang** oleh Inv1 (A-22), dengan atau tanpa pengecualian |
| K7 | NN = 0 karena diskon 100% pada baris non-LAB | 0 | 0 | 0 | ya | Sama seperti K5; hanya sah bila daftar alasan Owner (A-25) memuat alasan itu |
| K8 | Baris `LAB_FEE` dengan subtotal 0 | — | — | — | ya | **Dilarang** (A-24) |
| K9 | REVERSED (model M1) | 0 | 0 | 0 | — | Terminal; memenuhi cabang REVERSED di Inv2/Inv3 |
| K10 | Historis tanpa snapshot | n/a | n/a | apa pun | n/a | Dikecualikan dari Inv1/Inv2; Inv3 tetap berlaku |
| K11 | Pengecualian historis yang disetujui | n/a | n/a | apa pun | n/a | Inv3 dikecualikan lewat `historical_exception_id`, hanya pada baris tanpa klasifikasi |
| K12 | NO_CHARGE | 0 | 0 | 0 | tidak (S6-Q2) | Terminal |

Invoice Rp0 awal yang sah (K1, K12) tidak pernah ditolak. Inv3 hanya aktif bila `correction_version > 0`, dan D28
melarang koreksi atas invoice Rp0.

## 5. CHECK aman-database (PROPOSED)

Semua CHECK ditambahkan `NOT VALID`, lalu divalidasi setelah audit data yang dijalankan Owner.

```sql
-- trx_rme_invoices
CHECK (classification_version IS NULL OR status = 'REVERSED'
       OR grand_total = lab_fee_total + non_lab_total)
CHECK (classification_version IS NULL OR lab_fee_total = 0 OR non_lab_total > 0)                    -- Inv1
CHECK (classification_version IS NULL OR NOT has_lab OR non_lab_total > 0
       OR (lab_fee_total = 0 AND (status = 'REVERSED' OR lab_issuance_exception_id IS NOT NULL)))    -- Inv2
CHECK (correction_version = 0 OR grand_total > 0 OR status = 'REVERSED'
       OR historical_exception_id IS NOT NULL)                                                       -- Inv3
CHECK (lab_issuance_exception_id IS NULL OR (classification_version IS NOT NULL AND has_lab
       AND non_lab_total = 0 AND lab_fee_total = 0))
CHECK (historical_exception_id IS NULL OR classification_version IS NULL)
CHECK (status <> 'REVERSED' OR (reversed_at IS NOT NULL AND billing_reversal_id IS NOT NULL
       AND grand_total = 0 AND COALESCE(lab_fee_total, 0) = 0 AND COALESCE(non_lab_total, 0) = 0))
UNIQUE (lab_issuance_exception_id); UNIQUE (billing_reversal_id)
-- trx_rme_invoice_items (baris terklasifikasi)
CHECK (line_kind IS NULL OR (subtotal >= 0 AND discount >= 0 AND discount <= qty * unit_price))
CHECK (line_kind IS DISTINCT FROM 'LAB_FEE'
       OR (lab_for_item_id IS NOT NULL AND lab_fee_approval_id IS NOT NULL AND subtotal > 0))       -- A-24
UNIQUE (lab_fee_approval_id)
```

**Batas CHECK.** CHECK hanya memeriksa fakta di dalam satu baris, misalnya bahwa sebuah referensi *ada*. CHECK tidak
bisa memvalidasi tabel lain. Yang di luar jangkauannya:

- validitas approval dan pengecualian (status, kedaluwarsa, sidik jari, konsumsi);
- total lintas baris;
- tautan `lab_for_item_id` ke invoice yang sama.

Ketiganya diperiksa oleh service di bawah lock, ditambah constraint trigger `DEFERRABLE INITIALLY DEFERRED` milik owner
objek (rule 245) yang dievaluasi saat commit. Sebelum rule 245 diterapkan, semua kontrol database bisa dimatikan oleh
role runtime, karena role itulah owner objeknya saat ini.

## 6. State machine

### 6.1 Invoice (`trx_rme_invoices.status`)

```
UNPAID ──► PARTIAL ──► PAID
UNPAID ──────────────► PAID         (bayar penuh)
UNPAID ──► NO_CHARGE                (rule 217; terminal)
UNPAID | PARTIAL | PAID ──► REVERSED (mesin reversal; terminal; PARTIAL/PAID dorman sampai A-06 terpenuhi)
PAID ⇄ PARTIAL                      (koreksi pasca-bayar rule 207, flag OFF; tetap berlaku, A-11)
DRAFT, VOID                         (konstanta tanpa penulis; tetap tidak ditulis; REVERSED bukan VOID)
```

Transisi yang tidak valid: keluar dari REVERSED atau NO_CHARGE, NO_CHARGE → REVERSED, dan reversal invoice dengan G = 0
(A-10).

### 6.2 Approval biaya LAB (`trx_rme_lab_fee_approvals`)

`REQUESTED → APPROVED | REJECTED | CANCELLED (oleh maker) | STALE`, lalu `APPROVED → CONSUMED` (dipakai sekali saat
penerbitan).

### 6.3 Pengecualian penerbitan LAB (`trx_rme_lab_issuance_exceptions`)

`REQUESTED → GRANTED → CONSUMED | REJECTED | REVOKED | EXPIRED`.

### 6.4 Reversal tagihan dan obligation

- Reversal (`trx_rme_billing_reversals`): `SUBMITTED → APPLIED | REJECTED | CANCELLED | STALE`. Approve dan apply
  terjadi atomik.
- Obligation (`trx_rme_overpayment_obligations`, sudah ada): `OPEN → SETTLED` lewat rule 215/216.
- ICFR (turunan, tidak disimpan): `status = 'REVERSED'` dan ada obligation OPEN yang bersumber dari reversal itu.

## 7. Model pengecualian A-02 (PROPOSED)

| Sifat wajib dari Owner | Kontrol |
|---|---|
| 1. Referensi approval Owner | `owner_approval_reference_id` → registry rule 214 dengan scope baru `LAB_ISSUANCE_EXCEPTION` (satu referensi, satu keputusan) |
| 2. Terikat ke permintaan penerbitan | `clinic_visit_id` + `scope_fingerprint` (SHA-256 kanonik dari setiap baris yang dihargai) |
| 3. Alasan dan cakupan | `reason_code` dari daftar tertutup Owner (A-25; **isinya belum ditetapkan**), `reason_text` tanpa PII, `exception_kind = 'HAS_LAB_ZERO_NON_LAB'` |
| 4. Nominal dan klasifikasi persis | Nilai yang diharapkan: `lab_fee_total = 0` (Inv1), `non_lab_total = 0`, dan `grand_total` |
| 5. Konteks | `branch_id`, `patient_id`, `clinic_visit_id` |
| 6. Identitas dan waktu approval | `owner_approver_id`, `owner_approved_at`, diisi oleh aksi in-system. Registry rule 214 hanya menyimpan operator, jadi tidak cukup |
| 7. Konsumsi terkendali | `consumed_by_invoice_id` UNIQUE dan `trx_rme_invoices.lab_issuance_exception_id` UNIQUE; transisi GRANTED→CONSUMED di bawah `FOR UPDATE` |
| 8. Bukti imutabel | Baris dan event append-only (rule 248) |
| 9. Fail-closed | `expires_at` ≤ `owner_approved_at` + 7 hari (A-25). Penerbitan ditolak bila pengecualian tidak ada, kedaluwarsa, dicabut, tidak cocok, atau sudah dipakai |
| 10. Tanpa bypass Super Admin | Approver diverifikasi lewat identitas di allowlist Owner yang source-controlled (tanpa `env()`; daftar kosong berarti tidak ada siapa pun), bukan lewat `can()`. CHECK `owner_approver_id <> requested_by` |

Kode penolakan: `LAB_EXCEPTION_REQUIRED`, `_NOT_GRANTED`, `_EXPIRED`, `_REVOKED`, `_MISMATCH`, `_CONSUMED`,
`_CONTEXT_MISMATCH`, `_APPROVER_NOT_OWNER`, `_ROUTE_DISABLED`. Semua diaudit setelah rollback, dengan payload berisi id
dan kode saja.

**Status jalur: NONAKTIF (A-23).** Invoice K5/K7 bernilai G = 0, sehingga tidak bisa dibayar. NO_CHARGE juga terlarang
untuk baris LAB (S6-Q2), dan kandidat hanya terbentuk untuk invoice PAID. Akibatnya penutupan visit dan pembentukan
kandidat belum punya jalur. Desain dan implementasi alur ini dicatat sebagai **DES-LAB-RP0**. Sebelum itu selesai,
setiap upaya pengecualian ditolak dengan `LAB_EXCEPTION_ROUTE_DISABLED`.

## 8. Matriks test (PROPOSED)

| # | Skenario | Hasil yang diharapkan |
|---|---|---|
| P1 | K1 (Rp0 sah tanpa LAB) | Terbit normal |
| P2 | K3 dengan approval biaya LAB sebelum terbit | Terbit; approval CONSUMED |
| P3 | Historis tanpa klasifikasi | `VALIDATE CONSTRAINT` lulus (dikecualikan) |
| P4 | K5 dengan pengecualian GRANTED yang cocok (setelah DES-LAB-RP0) | Terbit; pengecualian CONSUMED |
| N1 | K5 tanpa pengecualian | `LAB_EXCEPTION_REQUIRED` |
| N2 | Jalur nonaktif (sekarang) | `LAB_EXCEPTION_ROUTE_DISABLED` |
| N3 | Pengecualian kedaluwarsa, dicabut, tidak cocok, atau sudah dipakai | Kode masing-masing; pemakaian ulang lewat SQL langsung → 23505 |
| N4 | Visit, pasien, atau cabang lain | `_CONTEXT_MISMATCH` |
| N5 | Super Admin di luar allowlist menyetujui | `_APPROVER_NOT_OWNER` |
| N6 | Pemohon = approver | Ditolak oleh service dan CHECK |
| N7 | K6, dengan atau tanpa pengecualian | Ditolak (Inv1); SQL langsung → 23514 |
| N8 | K8 (`LAB_FEE` bernilai 0) | Ditolak; SQL langsung → 23514 |
| N9 | Biaya LAB lewat free-text atau koreksi | Ditolak dengan kode stabil |
| N10 | Koreksi mengganti treatment ke/dari treatment ber-LAB | Ditolak (A-29) |
| N11 | Pengecualian GRANTED (belum CONSUMED) ditautkan lewat SQL langsung | Constraint trigger menolak saat commit |
| N12 | Dua kasir memakai pengecualian yang sama bersamaan | Satu berhasil, satu `_CONSUMED` |
| N13 | `requires_lab` diubah setelah invoice terbit | HAS_LAB, kandidat, dan total invoice lama tidak berubah |
