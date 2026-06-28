# DaengtisiaMS — Lab Module Workflow

## Tujuan
Alur lab order, integrasi RME, produksi, QC, delivery, dan status.

## Ringkasan
Lab order adalah spine operasi laboratorium. Integrasi RME via `LabCaseCandidate` staging setelah pembayaran RME PAID. Konversi ke LabOrder manual oleh Admin Lab.

## Konteks DaengtisiaMS
Modul lab terpisah dari RME billing. Invoice lab (`trx_invoices`) ≠ invoice RME (`trx_rme_invoices`).

## File / Area Repo Terkait
- `app/Modules/LabOrder/` — orders, candidates, conversion
- `app/Modules/Production/`
- `app/Modules/QualityControl/`
- `app/Modules/Delivery/`
- `app/Modules/Invoice/`, `Payment/`
- `app/Modules/RmeInvoice/Services/RmeLabIntegrationService.php`
- `docs/sprint_21_rme_lab_integration_architecture.md`
- `tests/Feature/LabOrder/`
- `tests/Feature/RME/LabIntegrationTest.php`
- `tests/Feature/RME/LabCaseCandidateConversionTest.php`

## Aturan Utama

### Lab order status (`LabOrder`)
`DRAFT` → `RECEIVED` → `ASSIGNED` → `IN_PRODUCTION` → (`ON_HOLD`) → `QC_PENDING` → `QC_PASSED` → `READY_FOR_DELIVERY` → `IN_DELIVERY` → `DELIVERED` → `COMPLETED`

Off-ramps: `REMAKE`, `CANCELLED`

### RME → Lab (Sprint 21)
1. Trigger: setelah `RmePaymentService::pay()` sukses & invoice `PAID`
2. `RmeLabIntegrationService::generateForPaidInvoice()` — post-commit
3. Satu `LabCaseCandidate` per `trx_rme_invoice_items` where `mst_treatments.requires_lab = true`
4. Idempotent: `UNIQUE(rme_invoice_item_id)` on `trx_lab_case_candidates`
5. Failure generation **tidak** rollback payment
6. Status candidate: `pending_review`, `converted_to_lab_order`, `rejected`, `cancelled`

### Konversi candidate → LabOrder (Sprint 21.4)
- `LabCaseCandidateConversionService`
- Admin pilih `lab_service_id` eksplisit — **no** auto mapping treatment→lab service
- Idempotent via row lock + `converted_lab_order_id`

### Production
- Assignments, work logs, production steps
- Permissions: `manage_production`, `assign_technicians`, `complete_production_work`, dll.

### Quality Control
- QC records, checklists, evidence, remake requests
- Permissions: `start_qc`, `pass_qc`, `reject_qc`, `request_remake`

### Delivery & POD
- `trx_lab_deliveries` — courier workflow, signature
- Permissions: `mark_delivered`, `upload_pod`

### Lab billing
- `trx_invoices`, `trx_payments` — modul Invoice/Payment
- **Tidak** dibuat otomatis dari RME payment

### Hubungan pasien/visit
- Lab order link ke patient; MRN on lab orders migration ada
- Candidate menyimpan referensi `rme_invoice_item_id`

## Workflow / Alur
```text
[RME] Payment PAID → LabCaseCandidate pending_review
[Admin Lab] Queue lab-case-candidates → review → convert
LabOrder RECEIVED → Production → QC → Delivery → COMPLETED
[Finance] Invoice lab + Payment (terpisah)
```

## Struktur Teknis
| Route prefix | Fungsi |
|---|---|
| `lab-orders` | CRUD + workflow lab order |
| `lab-case-candidates` | Queue kandidat RME |
| `production-*` | Langkah produksi |
| QC routes | Quality control |
| `deliveries` | Delivery lifecycle |

**Permissions contoh:** `view_lab_orders`, `manage_lab_orders`, `create_lab_orders`

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan auto-create LabOrder saat RME payment tanpa spec baru
- Jangan mapping otomatis `treatment_id` → `lab_service_id` tanpa master mapping resmi
- Jangan campur branch_id candidate dengan branch aktif tanpa validasi

## Checklist Validasi
- [ ] `LabIntegrationTest` idempotency
- [ ] `LabCaseCandidateConversionTest`
- [ ] Branch isolation pada lab orders
- [ ] Payment RME rollback tidak menghapus candidate yang sudah committed

## Catatan untuk AI
Queue UI: "Kandidat Lab RME" di sidebar — gated `view_lab_orders|manage_lab_orders`.

**TODO:** Daftar route name lengkap production/QC/delivery — ekstrak `php artisan route:list | rg lab`.
