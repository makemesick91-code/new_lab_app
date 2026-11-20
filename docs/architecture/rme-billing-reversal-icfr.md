---
kb_status: current
kb_verified_against: 3a231c28
kb_updated: 2026-11-20
---

# Spesifikasi FULL_REVERSAL dan ICFR — RME-LAB-FEE-APPROVAL-1

Kembali ke [00-INDEX](../00-INDEX.md). Keputusan:
[Owner Design Lock](../decisions/rme-lab-fee-approval-1-owner-design-lock.md). Rule kanonik:
`.cursor/rules/249-rme-billing-reversal-and-icfr.mdc`; amandemen rule 215/216 (A-19).

**Status:** keputusan bisnis APPROVED (ODR-02, ICFR Opsi 2, T1–T10, A-05, A-06, A-07, A-09, A-10, A-13, A-19, A-20,
A-30). Mekanisme, skema, guard, dan test di bawah ini **PROPOSED / NOT IMPLEMENTED**. Model M1 adalah pilihan desain
yang **memerlukan bukti rekonsiliasi**. Gate 1: NO-GO.

## 1. Kondisi sekarang (diverifikasi di `3a231c28`)

- Belum ada mekanisme reversal. `RmeInvoice::STATUS_VOID` tidak punya penulis.
- Obligation hanya bisa lahir dari koreksi (`source_correction_id` NOT NULL).
- Refund request (`RmeRefundRequestService`) dan settlement (`RmeRefundSettlementService`) hanya menerima invoice
  berstatus PAID.
- Settlement tidak menulis status invoice. `refreshInvoiceStatus()` hanya dipanggil dari jalur pembayaran.
- Versi dokumen v0 ORIGINAL hanya dibekukan saat koreksi pertama diterapkan.

## 2. Batas konsep (ODR-02)

| Konsep | Tabel | Disentuh reversal? |
|---|---|---|
| Kewajiban tagihan | `trx_rme_invoices` (G, status) | Ya: kewajiban aktif menjadi 0, status menjadi REVERSED |
| Pembayaran historis | `trx_rme_payments` | **Tidak pernah** (D26) |
| Kuitansi | `trx_rme_receipt_snapshots` | Tidak diubah; dirender dengan cap DIBATALKAN (T6) |
| Saldo kredit | — | Tidak ada (D6) |
| Kewajiban refund | `trx_rme_overpayment_obligations` | Satu OPEN bila uang bersih > 0 (aktif hanya setelah A-06) |
| Refund dan settlement | rule 215/216 | Tidak dipicu otomatis |
| Rekam klinis dan visit | rekam medis, visit | Tidak disentuh (T3) |
| Kandidat, lab order, invoice lab | domain LAB | Tidak disentuh; dibaca oleh guard A-05 |

## 3. Model M1 (pilihan desain; menunggu bukti rekonsiliasi)

1. Kunci baris-baris terkait sesuai urutan di §6.
2. Bekukan v0 ORIGINAL bila belum ada, lalu tambahkan versi dokumen jenis baru `REVERSAL`. Keduanya imutabel dan
   ber-hash.
3. Catat snapshot reversal yang imutabel: G, L, dan NN sebelum reversal, uang bersih, `correction_version`, sidik jari,
   serta penilaian LAB atau deklarasi A-30.
4. Set `grand_total`, `lab_fee_total`, dan `non_lab_total` menjadi 0; status REVERSED; isi `reversed_at` dan
   `billing_reversal_id`. Baris item dan baris pembayaran **tidak diubah**.
5. Bila uang bersih > 0, buat satu obligation OPEN dengan `source_billing_reversal_id`. Ini aktif hanya setelah A-06
   terpenuhi.
6. `correction_version` tidak dinaikkan.
7. Tidak ada jalur yang menghitung ulang status invoice terminal. Jalur pembayaran menolak invoice REVERSED sebelum
   insert apa pun (`INVOICE_REVERSED`). `statusAfterMoneyReceived(0, x)` = PAID tidak boleh pernah diterapkan pada invoice
   REVERSED.

## 4. Identitas rekonsiliasi (bukti yang wajib sebelum M1 final)

| # | Identitas | Berlaku untuk |
|---|---|---|
| R1 | Σ `item.subtotal` = G | UNPAID, PARTIAL, PAID, NO_CHARGE |
| R2 | G = 0 dan Σ `item.subtotal` = G sebelum reversal = total versi dokumen terakhir sebelum reversal | REVERSED |
| R3 | Uang bersih = Σ pembayaran − Σ refund SETTLED | Semua |
| R4 | UNPAID ⇒ tidak ada pembayaran; PARTIAL ⇒ 0 < uang bersih < G; PAID ⇒ uang bersih ≥ G | Status non-terminal |
| R5 | REVERSED dengan obligation OPEN ⇒ nominal obligation = uang bersih saat reversal = `overpaidCents(0, uang bersih)` (ICFR) | Setelah A-06 |
| R6 | REVERSED tanpa obligation ⇒ uang bersih = 0 dan jumlah pembayaran = 0 | Rilis pertama |
| R7 | Jumlah dan nominal seluruh `trx_rme_payments` tidak berubah oleh reversal | Semua (D26) |
| R8 | Obligation punya tepat satu sumber | Semua (A-13) |
| R9 | `obligation.invoice_version` = `correction_version` (tidak diubah oleh reversal) | REVERSED |
| R10 | Invoice ber-`lab_issuance_exception_id` ⇒ pengecualian itu CONSUMED oleh invoice tersebut, dengan sidik jari yang sama | Pengecualian A-02 |
| R11 | Setelah SETTLED: uang bersih = 0, status tetap REVERSED, ICFR tidak berlaku | Setelah A-19 |

- **Nilai historis** = baris item + versi dokumen + snapshot reversal.
- **Kewajiban saat ini** = `grand_total` invoice.
- Laporan omzet tetap Σ pembayaran (D26), sedangkan obligation OPEN ditampilkan terpisah sebagai ICFR.

## 5. Guard reversal (fail-closed)

| Kode | Guard | Dasar |
|---|---|---|
| `REVERSAL_FLAG_OFF` | Flag `rme.billing_reversal` (default OFF) | CG-08 |
| `REVERSAL_ACTOR_UNUSABLE` | Akun pelaku dibaca ulang dari database | AIR-RMB-22 |
| `REVERSAL_BRANCH_OUT_OF_SCOPE` | `RmeWorkingBranchScope::allows`; di luar cakupan → 404 | CG-05 |
| `REVERSAL_STATUS_NOT_ELIGIBLE` | Status UNPAID, PARTIAL, atau PAID | DL-TRANS |
| `REVERSAL_ZERO_TOTAL_NOT_APPLICABLE` | G > 0 | T8, A-10 |
| `REVERSAL_PAID_BLOCKED` | Uang bersih = 0 dan tidak ada pembayaran, sampai A-06 terpenuhi (flag `rme.billing_reversal_paid`) | T5, A-06 |
| `REVERSAL_OPEN_OBLIGATION` | Tidak ada obligation OPEN | T4 |
| `REVERSAL_ACTIVE_CORRECTION` | Tidak ada koreksi atau approval biaya LAB yang aktif | D10 |
| `REVERSAL_INVOICE_DISPUTED` | Tidak ada dispute OPEN, UNDER_REVIEW, atau ESCALATED | A-20 |
| `REVERSAL_LAB_UNRESOLVED` | `RmeLabObligationGate` (§7) mengembalikan `resolved = true` | A-05, T1 |
| `REVERSAL_LAB_DECLARATION_REQUIRED` | Invoice HAS_LAB tanpa tautan kandidat membutuhkan deklarasi maker dan checker yang ditunjuk | A-30 |
| `REVERSAL_PARTIAL_NOT_SUPPORTED` | Selalu seluruh invoice | T2 |
| `SELF_APPROVAL_FORBIDDEN`, `CHECKER_NOT_DESIGNATED`, `CHECKER_IS_ISSUER` | Rule 247 | T9, A-08 |
| `REVERSAL_OWNER_APPROVAL_REQUIRED` | Setiap reversal butuh approval Owner sampai ambang risiko ditetapkan | A-07 |
| `REVERSAL_STALE` | Sidik jari invoice, penilaian LAB, dan dispute dievaluasi ulang saat approve | — |

**Konsekuensi T5 untuk rilis pertama:** hanya invoice UNPAID tanpa pembayaran yang bisa di-reverse. Kandidat LAB hanya
ada untuk invoice PAID, sehingga invoice HAS_LAB pada rilis pertama selalu melalui deklarasi A-30.

## 6. Urutan lock

`invoice FOR UPDATE → reversal → dispute (FOR SHARE) → kandidat (FOR SHARE, urut id) → lab order (FOR SHARE, urut id)
→ invoice lab (FOR SHARE, urut id) → obligation FOR UPDATE`

Urutan ini sama dengan koreksi (invoice → correction → obligation). Pembayaran dan settlement juga mengunci invoice
lebih dulu, jadi tidak ada inversi baru. Klaim ini statis; uji dua koneksi di PostgreSQL 16 wajib.

## 7. Guard kewajiban LAB (A-05)

**Pemisahan konsep:**
- **Penyelesaian operasional:** pekerjaan lab berada di state terminal **dan** ada bukti.
- **Penyelesaian keuangan:** invoice domain lab (ditagihkan ke klinik) lunas, atau di-void dengan bukti. Di sisi RME:
  tidak ada kandidat `pending_review` dan tidak ada approval biaya LAB yang belum final.

Membaca status keuangan lab sebagai guard tidak mencampur pendapatan (AIR-RMB-01).

**Kontrak lintas modul (PROPOSED):**

```php
namespace App\Modules\LabOrder\Contracts;

interface RmeLabObligationGate
{
    /**
     * Read-only. Within the caller's transaction it locks, FOR SHARE and in id order, the candidates of the
     * invoice, their lab orders and the lab-domain invoices of those orders (lab invoices through the Invoice
     * module's own reader contract). It never cancels a lab order, never writes a lab-domain record, never mutates.
     * Any unknown state, missing link, missing evidence or exception => resolved = false (fail-closed).
     */
    public function assessForRmeInvoice(int $rmeInvoiceId): RmeLabObligationAssessment;
}
// RmeLabObligationAssessment: bool $resolved; list<string> $blockingCodes; list<array> $evidence; string $fingerprint
```

Kontrak di-bind di `RepositoryServiceProvider`. Modul `Invoice` menyediakan reader-nya sendiri, sehingga tidak ada akses
database lintas modul secara ad hoc (CG-04).

**Fakta yang mendasari (diverifikasi di `3a231c28`):**

| Objek | Fakta |
|---|---|
| Kandidat | Status `pending_review`, `converted_to_lab_order`, `rejected`, `cancelled`. Status `rejected` dan `cancelled` tidak punya penulis. `converted_lab_order_id` **tanpa FK** |
| Lab Workflow V2 | 35 konstanta state; terminal hanya `DELIVERED` dan `CANCELLED` (`LabWorkflowState::TERMINAL`). Tidak ada `COMPLETED`. Siklus `REWORK_REQUIRED` ada |
| Lab legacy V1 | `COMPLETED`, `DELIVERED`, `REMAKE`, `CANCELLED`, dan lainnya; kode tidak mendefinisikan terminal V1 |
| Bukti transisi | `trx_lab_order_status_logs` (status lama/baru, `notes`, `changed_by`, `changed_at`); FK `cascadeOnDelete` |
| Kewajiban domain lab | `trx_invoice_items.lab_order_id` → `trx_invoices` (DRAFT, ISSUED, PARTIALLY_PAID, PAID, OVERDUE, VOID; hanya ada `voided_at`) → `trx_payments` |

**Aturan "selesai" yang bisa dibuktikan:**

| Objek | Selesai bila | Belum selesai (kode blokir) |
|---|---|---|
| Kandidat | `converted_to_lab_order`, `converted_lab_order_id` menunjuk lab order yang ada, dan lab order itu selesai | `pending_review` (`LAB_CANDIDATE_PENDING_REVIEW`); id menggantung (`LAB_ORDER_LINK_DANGLING`); `rejected`/`cancelled` tanpa bukti event (`LAB_CANDIDATE_STATUS_UNEVIDENCED`) |
| Lab order V2 | State ∈ `TERMINAL` **dan** log terakhir sesuai state saat ini, **dan**: DELIVERED → rantai log memuat `RECIPIENT_SIGNATURE_CAPTURED` dan `DELIVERY_LOCATION_PHOTO_CAPTURED`; CANCELLED → log punya `changed_by` dan `notes` tidak kosong | Non-terminal (`LAB_ORDER_NOT_TERMINAL`); bukti hilang (`LAB_ORDER_EVIDENCE_MISSING`) |
| Lab order V1 | Belum ditetapkan (A-26). Sementara: hanya COMPLETED atau CANCELLED dengan bukti log | `LAB_ORDER_LEGACY_UNRESOLVED` |
| `workflow_version` tidak dikenal | — | `LAB_ORDER_WORKFLOW_UNKNOWN` |
| Invoice lab per lab order | PAID **dan** Σ `trx_payments` ≥ `total_amount` (dihitung ulang dari pembayaran); atau VOID dengan bukti sah (A-27) | DRAFT/ISSUED/PARTIALLY_PAID/OVERDUE (`LAB_INVOICE_UNSETTLED`); PAID dengan pembayaran kurang (`LAB_INVOICE_PAYMENT_MISMATCH`); VOID tanpa bukti (`LAB_INVOICE_VOID_UNEVIDENCED`); tidak ada invoice lab (`LAB_INVOICE_MISSING`, A-27) |
| Invoice HAS_LAB tanpa tautan kandidat | Deklarasi maker + checker yang ditunjuk bahwa tidak ada pekerjaan lab, imutabel (A-30) | `REVERSAL_LAB_DECLARATION_REQUIRED` |
| Kesalahan apa pun | — | `LAB_GATE_ERROR` |

Integritas bukti (backlog LAB-T02): FK untuk `converted_lab_order_id` setelah audit id menggantung; log status lab
append-only (rule 248); pelaku dan alasan VOID invoice lab.

**Matriks test state:**

| # | Kondisi | Hasil |
|---|---|---|
| S1 | Invoice tanpa kandidat dan tanpa HAS_LAB | resolved |
| S2 | HAS_LAB tanpa kandidat, tanpa deklarasi / dengan deklarasi maker saja / dengan deklarasi lengkap | diblokir / diblokir / resolved |
| S3 | Kandidat `pending_review` | Diblokir |
| S4 | Kandidat converted dengan id menggantung | Diblokir |
| S5 | V2: setiap state non-terminal (data-driven dari `matrix()`) | Diblokir |
| S6 | V2 DELIVERED dengan rantai bukti lengkap dan invoice lab PAID yang seimbang | resolved |
| S7 | V2 DELIVERED tanpa log tanda tangan atau foto | Diblokir |
| S8 | V2 CANCELLED dengan alasan dan pelaku, tanpa invoice lab | Diblokir (`LAB_INVOICE_MISSING`) sampai A-27 |
| S9 | V2 CANCELLED tanpa `notes` | Diblokir |
| S10 | V1 COMPLETED / DELIVERED / REMAKE / CANCELLED | Hanya COMPLETED dan CANCELLED dengan bukti yang lolos, sampai A-26 |
| S11 | Invoice lab DRAFT, ISSUED, PARTIALLY_PAID, OVERDUE | Diblokir |
| S12 | Invoice lab PAID tetapi Σ pembayaran < total | Diblokir |
| S13 | Invoice lab VOID (hanya `voided_at`) | Diblokir sampai A-27 |
| S14 | Dua lab order, satu selesai dan satu belum | Diblokir |
| S15 | Kontrak melempar exception | Diblokir (`LAB_GATE_ERROR`) |
| S16 | Setelah reversal: lab order, log, invoice lab, pembayaran lab, dan rekam klinis | Tidak berubah (hash atau jumlah baris sama) |

**Matriks test konkurensi (PostgreSQL 16, dua koneksi):**

| # | Lomba | Perilaku yang diharapkan |
|---|---|---|
| C1 | Reversal vs transisi lab order | Diserialkan oleh `FOR SHARE`, atau reversal STALE/diblokir; tidak pernah REVERSED saat state lab berubah di tengah |
| C2 | Reversal vs konversi kandidat (`FOR UPDATE`) | Diserialkan |
| C3 | Reversal vs backfill kandidat | Diserialkan oleh lock invoice |
| C4 | Reversal vs pembayaran atau void invoice lab | UPDATE invoice lab menunggu commit reversal |
| C5 | Reversal vs pembayaran RME | Lock invoice RME; pembayaran setelah reversal ditolak (`INVOICE_REVERSED`) |
| C6 | Dua reversal atas invoice yang sama | Partial unique: hanya satu yang aktif |
| C7 | Reversal vs patient merge | Risiko 40P01 (CON-01); butuh retry + blocker merge (CON-T01) |
| C8 | Reversal vs pembukaan dispute | Diserialkan oleh lock invoice |

## 8. Amandemen rule 215/216 (A-19; dependensi P0)

**Diskriminasi sumber:** `source_kind` diturunkan dari kolom sumber obligation: CORRECTION bila `source_correction_id`
terisi, REVERSAL bila `source_billing_reversal_id` terisi. CHECK menjamin tepat satu terisi (A-13).

| Aspek | Sumber CORRECTION (perilaku sekarang, tidak berubah) | Sumber REVERSAL (baru) |
|---|---|---|
| Status invoice yang layak | PAID | REVERSED |
| Pasangan lain | `OBLIGATION_SOURCE_STATUS_MISMATCH` | `OBLIGATION_SOURCE_STATUS_MISMATCH` |
| Rekonsiliasi nominal | `overpaidCents(G, net) == amount` | Sama; G = 0 sehingga amount = uang bersih |
| Snapshot request | Format dan hash lama dipertahankan | Format berversi baru yang menambahkan sumber |
| Post-condition settlement | Seimbang, invoice tetap PAID | Seimbang (G = 0, uang bersih = 0), invoice tetap REVERSED |
| Maker, reviewer, settler, allowlist, flag | Tidak berubah | Tidak berubah |
| Refund otomatis | Tidak ada | Tidak ada |
| Penulisan pembayaran | Tidak pernah | Tidak pernah |

**Test regresi:**
- (+) Suite refund dan settlement yang ada lulus tanpa perubahan untuk sumber CORRECTION.
- (+) Golden hash snapshot format lama tetap sama.
- (+) Request dan settlement bersumber REVERSAL pada invoice REVERSED berhasil.
- (−) Sumber REVERSAL pada invoice PAID ditolak; sumber CORRECTION pada invoice REVERSED ditolak.
- (−) Nominal tidak cocok → STALE; settler = approver ditolak.
- (−) Settlement tidak pernah menulis status invoice.

**Rollback:**
- Flag `rme.billing_reversal_paid` OFF menghentikan pembuatan obligation bersumber reversal.
- Rollback kode hanya aman bila jumlah obligation OPEN bersumber reversal = 0. Bila tidak nol, hanya forward-fix.

## 9. Dispute aktif (A-20)

- Di bawah lock invoice, dispute aktif dibaca. Status OPEN, UNDER_REVIEW, atau ESCALATED → `REVERSAL_INVOICE_DISPUTED`.
  Kesalahan repository juga berujung tolak.
- Guard dievaluasi ulang saat approve: dispute yang dibuka setelah submit membuat permintaan menjadi STALE.
- Reversal tidak pernah menutup atau menyelesaikan dispute; penyelesaiannya tetap hanya `CORRECTION_APPLIED`
  (AIR-RMB-27).
- **Test:** dispute aktif (tiga status) → ditolak; RESOLVED → lolos; dispute dibuka di antara submit dan approve →
  STALE; pembukaan dispute bersamaan dengan approve → diserialkan; status dispute tidak berubah setelah reversal.

## 10. Tampilan, laporan, dan penagihan ulang

- **Tampilan (T6):** dokumen invoice dan setiap kuitansi milik invoice REVERSED dirender dari snapshot dengan cap
  DIBATALKAN. Snapshot tidak ditulis ulang.
- **Antrean kasir (A-09):** visit dengan invoice REVERSED tampil "Dibatalkan" secara read-only, tanpa mengubah status
  visit.
- **Penagihan ulang (REV-04, T7):** invoice REVERSED tetap memblokir invoice baru untuk visit yang sama; CON-02 mencakup
  REVERSED. Penagihan ulang luar biasa adalah alur terpisah yang belum didesain.

## 11. Masih terbuka

A-26 (terminal lab V1), A-27 (invoice lab tidak ada atau VOID), A-28 (definisi "dibatalkan secara sah"), dan bukti
rekonsiliasi M1.
