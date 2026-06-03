# Sprint 7 Technical Design — Invoice & Payment

## Project

Asia Dental Lab Management System

## Version

1.0

## Sprint

Sprint 7 - Invoice & Payment

## Architecture

Laravel 12, Blade + Livewire 3, PostgreSQL, Modular Monolith

---

# 1. Overview

Sprint 7 membangun modul Invoice dan Payment setelah Delivery & Proof of
Delivery selesai pada Sprint 6.

Sprint 6 menutup Lab Order pada status `COMPLETED` setelah pekerjaan diterima
oleh clinic dan bukti penerimaan lengkap. Status `COMPLETED` menjadi gate utama
untuk proses billing pada Sprint 7.

Tujuan Sprint 7:

```text
1. Membuat invoice dari satu atau lebih Lab Order yang sudah COMPLETED.
2. Mendukung multi Lab Order per Invoice untuk Clinic yang sama.
3. Menghitung invoice item, total invoice, paid amount, dan outstanding balance.
4. Menerbitkan invoice.
5. Melakukan void invoice sebelum ada payment.
6. Mencatat payment sebagian dan penuh.
7. Mengubah status invoice sesuai payment.
8. Mencatat semua aktivitas finance ke sys_audit_logs.
9. Menyiapkan permissions, policies, seeders, dan feature tests.
```

Out of scope utama Sprint 7 adalah reporting dashboard, accounting ledger,
external payment gateway, refund, dan pengiriman invoice via WhatsApp/email.

---

# 2. Architecture Decision

Sprint 7 mengikuti pola project:

```text
Controller
-> Request
-> Service
-> Repository
-> Model
```

Pembagian tanggung jawab:

| Layer | Responsibility |
| --- | --- |
| Controller | Receive request, authorize action, call service, return view/redirect/response. |
| Request | Validate input shape and basic validation rules. |
| Service | Own business rules, workflow transitions, transactions, audit logs, and total recalculation. |
| Repository | Own query, persistence, filtering, eager loading, and aggregate reads. |
| Model | Own table mapping, fillable fields, casts, and relationships. |

Sprint 7 memakai Modular Monolith di:

```text
app/Modules/Invoice
```

Modul ini menangani Invoice dan Payment bersama-sama karena Payment selalu
dicatat terhadap Invoice pada Sprint 7.

---

# 3. Module Structure

Proposed structure:

```text
app/Modules/Invoice/
├── Controllers/
├── Interfaces/
├── Models/
├── Policies/
├── Repositories/
├── Requests/
├── Services/
└── Tests/
```

Recommended classes:

```text
Controllers/InvoiceController.php
Controllers/PaymentController.php

Interfaces/InvoiceRepositoryInterface.php
Interfaces/PaymentRepositoryInterface.php

Models/Invoice.php
Models/InvoiceItem.php
Models/Payment.php

Policies/InvoicePolicy.php
Policies/PaymentPolicy.php

Repositories/InvoiceRepository.php
Repositories/PaymentRepository.php

Requests/CreateInvoiceRequest.php
Requests/IssueInvoiceRequest.php
Requests/VoidInvoiceRequest.php
Requests/CreatePaymentRequest.php

Services/InvoiceService.php
Services/InvoiceWorkflowService.php
Services/InvoiceNumberGeneratorService.php
Services/PaymentService.php
Services/PaymentNumberGeneratorService.php
```

---

# 4. Models

## Invoice

Table:

```text
trx_invoices
```

Fillable fields:

```text
invoice_number
clinic_id
invoice_date
due_date
status
subtotal
discount_amount
tax_amount
total_amount
paid_amount
outstanding_amount
notes
created_by
issued_at
voided_at
```

Relationships:

```text
belongsTo Clinic via clinic_id
belongsTo User as creator via created_by
hasMany InvoiceItem via invoice_id
hasMany Payment via invoice_id
morphMany sys_attachments via entity_type/entity_id
morphMany sys_audit_logs via entity_type/entity_id
```

Important casts:

```text
invoice_date: date
due_date: date
issued_at: datetime
voided_at: datetime
subtotal: decimal:2
discount_amount: decimal:2
tax_amount: decimal:2
total_amount: decimal:2
paid_amount: decimal:2
outstanding_amount: decimal:2
```

## InvoiceItem

Table:

```text
trx_invoice_items
```

Fillable fields:

```text
invoice_id
lab_order_id
description
quantity
unit_price
discount_amount
total_price
```

Relationships:

```text
belongsTo Invoice via invoice_id
belongsTo LabOrder via lab_order_id
```

Important casts:

```text
quantity: integer
unit_price: decimal:2
discount_amount: decimal:2
total_price: decimal:2
```

## Payment

Table:

```text
trx_payments
```

Fillable fields:

```text
payment_number
invoice_id
payment_date
payment_method
amount
reference_number
notes
received_by
created_by
```

Relationships:

```text
belongsTo Invoice via invoice_id
belongsTo User as receiver via received_by
belongsTo User as creator via created_by
morphMany sys_attachments via entity_type/entity_id
morphMany sys_audit_logs via entity_type/entity_id
```

Important casts:

```text
payment_date: date
amount: decimal:2
```

---

# 5. Controllers

## InvoiceController

Planned actions:

```text
index
show
create
store
issue
void
```

Responsibilities:

```text
index  -> show invoice queue/list with filters.
show   -> show invoice detail, invoice items, payment history, and actions.
create -> show completed Lab Orders available for invoicing.
store  -> create draft invoice from selected COMPLETED Lab Orders.
issue  -> issue draft invoice.
void   -> void DRAFT or ISSUED invoice when no payment exists.
```

Controller must authorize every action through `InvoicePolicy`.

## PaymentController

Planned actions:

```text
store
```

Responsibilities:

```text
store -> record payment against invoice.
```

Payment may be handled inside the Invoice show page in Sprint 7. A separated
Payment page can be introduced later if the workflow grows.

Controller must authorize payment creation through `PaymentPolicy`.

---

# 6. Requests

## Invoice Requests

CreateInvoiceRequest:

```text
Validates clinic_id, invoice_date, due_date, selected lab_order_ids, optional
notes, and optional discount/tax values.
```

Validation responsibility:

```text
clinic_id required
invoice_date required date
due_date nullable date after_or_equal invoice_date
lab_order_ids required array min 1
lab_order_ids.* exists in trx_lab_orders
discount_amount nullable numeric min 0
tax_amount nullable numeric min 0
notes nullable string
```

Business validations such as COMPLETED status, same Clinic, and duplicate active
invoice items remain in `InvoiceService`.

IssueInvoiceRequest:

```text
Validates issue notes if needed and delegates invoice issue eligibility to
InvoiceWorkflowService.
```

VoidInvoiceRequest:

```text
Validates required void notes.
```

Payment request:

CreatePaymentRequest:

```text
Validates payment_date, payment_method, amount, reference_number, and notes.
```

Validation responsibility:

```text
payment_date required date
payment_method required in CASH,BANK_TRANSFER,QRIS,CARD,OTHER
amount required numeric greater than 0
reference_number nullable string max 100
notes nullable string
```

Outstanding balance validation remains in `PaymentService`.

---

# 7. Services

## InvoiceService

Responsibilities:

```text
1. Create invoice from completed Lab Orders.
2. Validate selected Lab Orders are COMPLETED.
3. Validate all selected Lab Orders belong to the same Clinic.
4. Prevent duplicate invoice items for non-VOID invoices.
5. Create invoice header as DRAFT.
6. Create invoice items with pricing snapshot.
7. Calculate invoice item totals.
8. Calculate subtotal, discount_amount, tax_amount, total_amount.
9. Initialize paid_amount and outstanding_amount.
10. Write invoice_created audit log.
```

Calculation:

```text
item_total = quantity * unit_price - discount_amount
subtotal = sum(item_total before invoice-level discount/tax)
total_amount = subtotal - discount_amount + tax_amount
paid_amount = sum(valid payments)
outstanding_amount = total_amount - paid_amount
```

## InvoiceWorkflowService

Responsibilities:

```text
1. Issue invoice.
2. Void invoice.
3. Mark overdue if needed.
4. Update invoice status.
5. Guard invalid transitions.
6. Write workflow audit logs.
```

Important rules:

```text
DRAFT can become ISSUED.
DRAFT can become VOID if no payment exists.
ISSUED can become VOID if no payment exists.
ISSUED or PARTIALLY_PAID can become OVERDUE when due_date is past.
PAID invoice cannot be voided.
VOID invoice cannot receive payment.
```

## InvoiceNumberGeneratorService

Responsibilities:

```text
Generate unique invoice number.
Recommended format: INV-YYYY-XXXXXX.
Reset sequence annually if project numbering standard requires it.
```

## PaymentService

Responsibilities:

```text
1. Record payment.
2. Validate invoice is not VOID.
3. Validate payment amount is greater than zero.
4. Validate payment amount does not exceed outstanding_amount.
5. Create payment record.
6. Recalculate paid_amount and outstanding_amount.
7. Update invoice status to PARTIALLY_PAID or PAID.
8. Write payment_recorded audit log.
9. Write invoice_partially_paid or invoice_paid audit log when status changes.
```

Payment recording should run inside a database transaction and should lock the
invoice row or otherwise prevent concurrent overpayment.

## PaymentNumberGeneratorService

Responsibilities:

```text
Generate unique payment number.
Recommended format: PAY-YYYY-XXXXXX.
Reset sequence annually if project numbering standard requires it.
```

---

# 8. Repositories

## InvoiceRepository

Responsibilities:

```text
find invoice
get invoice queue/list
create invoice
create invoice items
find completed Lab Orders available for invoice
check duplicate active invoice items
update invoice totals/status
load invoice with clinic, items, lab orders, and payments
```

## PaymentRepository

Responsibilities:

```text
create payment
get payments by invoice
sum payments by invoice
check payment existence by invoice
```

Interfaces:

```text
InvoiceRepositoryInterface
PaymentRepositoryInterface
```

Repositories must not own workflow decisions. They provide persistence and
query primitives used by services.

---

# 9. Policies

## InvoicePolicy

Methods:

```text
viewAny
view
create
issue
void
```

Policy mapping:

```text
viewAny -> view_invoice or manage_invoice
view    -> view_invoice or manage_invoice
create  -> create_invoice or manage_invoice
issue   -> issue_invoice or manage_invoice
void    -> void_invoice or manage_invoice
```

## PaymentPolicy

Methods:

```text
viewAny
view
create
```

Policy mapping:

```text
viewAny -> view_payment or manage_payment
view    -> view_payment or manage_payment
create  -> create_payment or manage_payment
```

---

# 10. Permissions

Sprint 7 permissions:

```text
manage_invoice
view_invoice
create_invoice
issue_invoice
void_invoice
manage_payment
view_payment
create_payment
```

Role grants:

| Role | Grants |
| --- | --- |
| Admin | All invoice and payment permissions. |
| Finance | Invoice and payment permissions required to create, issue, void, and record payment. |
| Lab Staff | May receive `view_invoice` and `view_payment` if operational visibility is needed. |
| Courier | No invoice/payment permissions. |

Finance should be the default owner for payment mutation. Lab Staff should not
create payment unless explicitly granted.

---

# 11. Routes

Planned routes only:

```text
GET  /invoices
GET  /invoices/{invoice}
POST /invoices
POST /invoices/{invoice}/issue
POST /invoices/{invoice}/void
POST /invoices/{invoice}/payments
```

Route authorization:

```text
GET /invoices                    -> InvoicePolicy@viewAny
GET /invoices/{invoice}          -> InvoicePolicy@view
POST /invoices                   -> InvoicePolicy@create
POST /invoices/{invoice}/issue   -> InvoicePolicy@issue
POST /invoices/{invoice}/void    -> InvoicePolicy@void
POST /invoices/{invoice}/payments -> PaymentPolicy@create
```

No routes are implemented by this document.

---

# 12. Views

Planned Blade views:

```text
resources/views/invoices/index.blade.php
resources/views/invoices/show.blade.php
resources/views/invoices/create.blade.php
```

View responsibilities:

```text
Invoice list
Invoice detail
Invoice items
Payment history
Payment form
Issue action based on policy
Void action based on policy
```

Create view should show available `COMPLETED` Lab Orders that are not already
included in a non-VOID invoice.

Show view should show invoice status, totals, outstanding amount, payment
history, and allowed finance actions.

---

# 13. Database Migration Plan

Planned migrations:

```text
create_trx_invoices_table
create_trx_invoice_items_table
create_trx_payments_table
```

Do not write migration code in this design.

## create_trx_invoices_table

Expected fields:

```text
id
invoice_number
clinic_id
invoice_date
due_date
status
subtotal
discount_amount
tax_amount
total_amount
paid_amount
outstanding_amount
notes
created_by
issued_at
voided_at
created_at
updated_at
```

FK and indexes:

```text
clinic_id -> mst_clinics.id
created_by -> users.id
invoice_number UNIQUE
clinic_id INDEX
status INDEX
invoice_date INDEX
due_date INDEX
created_by INDEX
```

## create_trx_invoice_items_table

Expected fields:

```text
id
invoice_id
lab_order_id
description
quantity
unit_price
discount_amount
total_price
created_at
updated_at
```

FK and indexes:

```text
invoice_id -> trx_invoices.id ON DELETE CASCADE
lab_order_id -> trx_lab_orders.id
invoice_id INDEX
lab_order_id INDEX
invoice_id, lab_order_id INDEX
```

Uniqueness note:

```text
One Lab Order should not be included in more than one non-VOID invoice.
If strict database enforcement is added later, design partial unique index
carefully because invoice VOID status lives on trx_invoices.
Sprint 7 should enforce this rule in service layer and tests.
```

## create_trx_payments_table

Expected fields:

```text
id
payment_number
invoice_id
payment_date
payment_method
amount
reference_number
notes
received_by
created_by
created_at
updated_at
```

FK and indexes:

```text
invoice_id -> trx_invoices.id
received_by -> users.id
created_by -> users.id
payment_number UNIQUE
invoice_id INDEX
payment_date INDEX
payment_method INDEX
received_by INDEX
created_by INDEX
```

---

# 14. Seeder Plan

PermissionSeeder update:

```text
manage_invoice
view_invoice
create_invoice
issue_invoice
void_invoice
manage_payment
view_payment
create_payment
```

RoleSeeder update:

```text
Admin gets all invoice/payment permissions.
Finance gets invoice/payment permissions.
Lab Staff may get view permissions only if needed.
Courier gets none.
```

Optional seeders:

```text
InvoiceSeeder
PaymentSeeder
```

DatabaseSeeder update:

```text
Call PermissionSeeder and RoleSeeder updates.
Call optional InvoiceSeeder/PaymentSeeder only for local/demo data.
```

---

# 15. Audit Log Strategy

Use:

```text
sys_audit_logs
```

Events to log:

```text
invoice_created
invoice_issued
invoice_voided
invoice_marked_overdue
payment_recorded
invoice_partially_paid
invoice_paid
```

Required audit target:

```text
entity_type = trx_invoices
entity_id   = invoice_id
```

Payment audit target:

```text
entity_type = trx_payments
entity_id   = payment_id
```

Payment-driven invoice status changes should also create an invoice audit entry
when status changes to `PARTIALLY_PAID` or `PAID`.

Do not create:

```text
trx_invoice_audit_logs
trx_payment_audit_logs
```

---

# 16. Attachment Strategy

Sprint 7 does not require invoice PDF generation or payment proof upload.

If needed later, reuse:

```text
sys_attachments
```

Suggested future categories:

```text
INVOICE_PDF
PAYMENT_PROOF
```

Polymorphic targets:

```text
entity_type = trx_invoices
entity_id   = invoice_id

entity_type = trx_payments
entity_id   = payment_id
```

Do not create invoice/payment attachment tables in Sprint 7.

---

# 17. Status Flow

Main invoice flow:

```text
DRAFT
↓
ISSUED
↓
PARTIALLY_PAID
↓
PAID
```

Additional transitions:

```text
ISSUED / PARTIALLY_PAID -> OVERDUE
DRAFT / ISSUED -> VOID
```

Rules:

```text
1. VOID only allowed if no payment exists.
2. PAID invoice cannot be voided.
3. VOID invoice cannot receive payment.
4. DRAFT invoice should not receive payment.
5. Payment amount must not exceed outstanding_amount.
6. Full payment changes invoice status to PAID.
7. Partial payment changes invoice status to PARTIALLY_PAID.
8. Past due unpaid/partial invoice can become OVERDUE.
```

`trx_lab_order_status_logs` remains only for Lab Order status tracking and is
not used for Invoice status.

---

# 18. Edge Cases and Risks

Edge cases:

```text
1. Duplicate invoice generation.
2. Lab Order from different Clinic.
3. Lab Order not COMPLETED.
4. Payment exceeds outstanding balance.
5. Payment on VOID invoice.
6. Void invoice after payment exists.
7. Race condition during payment.
8. Invoice total mismatch.
9. Manual status manipulation.
10. Duplicate invoice number.
11. Duplicate payment number.
12. Missing payment reference.
13. Overdue invoice calculation.
```

Risk controls:

```text
1. Use service-layer transaction when creating invoice and payment.
2. Check duplicate active invoice items before insert.
3. Lock invoice or re-read current outstanding amount during payment.
4. Recalculate totals from persisted invoice items and payments.
5. Keep status mutation inside InvoiceWorkflowService and PaymentService.
6. Cover invalid paths with feature tests.
```

---

# 19. Testing Plan

## Invoice Feature Tests

```text
invoice list can be viewed by authorized user
invoice can be created from completed lab orders
invoice cannot be created from non-completed lab orders
invoice cannot mix lab orders from different clinics
invoice cannot include duplicate lab order
invoice can be issued
invoice can be voided if unpaid
invoice cannot be voided after payment
```

## Payment Feature Tests

```text
payment can be recorded
partial payment updates status to PARTIALLY_PAID
full payment updates status to PAID
payment cannot exceed outstanding amount
payment cannot be recorded for VOID invoice
payment creates audit log
```

## Authorization Tests

```text
unauthorized user cannot manage invoice
unauthorized user cannot record payment
Finance role can manage invoice/payment
Courier role cannot access invoice/payment
```

## Seeder Tests

```text
permissions are seeded
roles receive correct permissions
```

---

# 20. Quality Gates

Required commands:

```text
php artisan migrate:fresh --seed
php artisan test
npm.cmd run build
```

Windows note:

```text
Use npm.cmd run build if PowerShell blocks npm.ps1.
```

Target after Sprint 7:

```text
280-320 tests passed
650+ assertions
Build success
Migration success
```

---

# 21. Out of Scope

Sprint 7 explicitly excludes:

```text
Reporting dashboard
Accounting ledger
Journal entries
Tax reporting
External payment gateway
Refund handling
Multi-currency
WhatsApp/email invoice sending
PDF template customization
Payment allocation table
Reporting summary tables
```

---

# 22. Implementation Readiness Checklist

```text
- Workflow design completed
- ERD updated
- Database schema updated
- Technical design completed
- Tables identified
- Models identified
- Services identified
- Permissions identified
- Policies identified
- Tests planned
- Out-of-scope clear
- Ready for Sprint 7 implementation prompt
```
