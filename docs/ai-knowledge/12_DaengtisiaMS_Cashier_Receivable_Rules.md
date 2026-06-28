# DaengtisiaMS — Cashier & Receivable Rules

## Tujuan
Aturan invoice RME, pembayaran, status piutang, consent gate, aging, export, dan metode bayar.

## Ringkasan
Billing RME terpisah dari invoice lab (`trx_rme_invoices` vs `trx_invoices`). Status invoice: DRAFT/UNPAID/PARTIAL/PAID/VOID. Pembayaran >0 menyelesaikan visit; sisa tagihan tetap piutang.

## Konteks DaengtisiaMS
Modul kasir adalah gate akhir pipeline RME setelah pemeriksaan dokter dan consent.

## File / Area Repo Terkait
- `app/Modules/RmeInvoice/Models/RmeInvoice.php`
- `app/Modules/RmeInvoice/Models/RmePayment.php`
- `app/Modules/RmeInvoice/Models/RmeReceivableFollowUp.php`
- `app/Modules/RmeInvoice/Services/RmeInvoiceService.php`
- `app/Modules/RmeInvoice/Services/RmePaymentService.php`
- `app/Modules/RmeInvoice/Services/RmeControlReceivableService.php`
- `app/Modules/RmeInvoice/Controllers/RmePaymentController.php`
- `app/Modules/RmeInvoice/Controllers/RmeInvoiceController.php`
- `resources/views/rme/cashier/`
- `tests/Feature/RME/CashierBillingTest.php`
- `tests/Feature/RME/RmePaymentTest.php`
- `tests/Feature/RME/PatientOutstandingReceivableCarryOverTest.php`

## Aturan Utama

### Invoice status (`RmeInvoice`)
| Status | Arti |
|---|---|
| `DRAFT` | Belum diterbitkan ke kasir |
| `UNPAID` | Belum dibayar |
| `PARTIAL` | Dibayar sebagian — masih piutang |
| `PAID` | Lunas |
| `VOID` | Batal |

### Gate create billing (`RmeInvoiceService`)
- Visit harus `cashier_pending`
- Medical record harus `final` (jika ada)
- Pesan error: "Pembayaran belum dapat diproses karena pemeriksaan dokter belum selesai."

### Payment (`RmePaymentService`)
- `pay()` — pembayaran invoice kunjungan saat ini
- `allocateVisitPayment()` — dengan piutang terpilih (Sprint 62.2)
- `allocateControlPayment()` — control chain (stricter completion)
- Setelah payment sukses: `completeVisitAfterCashierPayment()` — `cashier_pending` → `completed` untuk **PAID atau PARTIAL**
- Full payment only **tidak** lagi enforced untuk menyelesaikan visit (hotfix partial) — partial tetap complete visit

### Consent gate
- `CreateRmePaymentRequest` — validasi consent pasien/dokter sebelum bayar

### Receivable / Piutang
- Daftar: `rme.cashier.receivables`
- Filter status: `UNPAID`, `PARTIAL`
- Remaining amount derived dari invoice — bukan visit status
- Follow-up: `trx_rme_receivable_follow_ups` — status `NEW|CONTACTED|PROMISED|FOLLOW_UP_LATER|ESCALATED|CLOSED`
- Export: `rme.cashier.receivables.export`

### Payment method
- Master: `mst_payment_methods`
- Dipilih saat create payment

### Control visit receivable
- Visit type control — aturan allocation berbeda (`RmeControlReceivableService`)
- Control chain: invoice control harus settle untuk completion tertentu

### Zero grand total
- Visit dapat selesai jika `remainingAmount() == 0` tanpa payment riil

## Workflow / Alur
```text
1. Visit cashier_pending
2. Kasir: GET rme.cashier.create
3. Buat invoice + items (treatment_id optional, description required)
4. GET rme.cashier.payment.create
5. Opsional: centang piutang sebelumnya (unchecked default)
6. POST payment → invoice status update → visit completed
7. Receipt: rme.cashier.receipt
8. Piutang tersisa → muncul di receivables + Owner KPI
```

## Struktur Teknis
| Tabel | Fungsi |
|---|---|
| `trx_rme_invoices` | Header invoice |
| `trx_rme_invoice_items` | Line items; `treatment_id` nullable |
| `trx_rme_payments` | Pembayaran; `payment_batch_uuid` |
| `trx_rme_receivable_follow_ups` | Catatan follow-up manual |

**Permission:** `manage_rme_billing`

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan buat `trx_payments` (lab) dari payment RME
- Jangan merge invoice lama ke invoice baru
- Jangan bypass consent/room/finalize gates di controller
- Jangan kembalikan "visit tetap cashier_pending saat partial" tanpa spec (hotfix sudah mengubah ini)

## Checklist Validasi
- [ ] `RmeDoctorCashierCompletionGateTest`
- [ ] `RmePaymentTest` partial completion
- [ ] `PatientOutstandingReceivableCarryOverTest` IDOR
- [ ] Receivable export branch-scoped
- [ ] Owner KPI piutang match invoice remaining

## Catatan untuk AI
**Catatan Konflik / Perlu Verifikasi:** Sprint 20 awal: full-payment-only. Hotfix 2026-06-27: partial payment completes visit. Ikuti `RmePaymentService` + test terbaru.

Lab case candidate generation: hanya saat invoice `PAID`, post-commit, idempotent.
