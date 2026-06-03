# Invoice & Payment Workflow Design

## Project

Asia Dental Lab Management System

## Version

1.0

## Sprint

Sprint 7 - Invoice & Payment

## Architecture

Laravel 12, PostgreSQL, Modular Monolith

---

## 1. Overview

Invoice dan Payment ditambahkan setelah Delivery & POD karena billing hanya
boleh terjadi ketika pekerjaan benar-benar selesai dan diterima oleh clinic.
Sprint 6 menutup Lab Order pada status `COMPLETED` setelah delivery dan Proof
of Delivery lengkap. Status `COMPLETED` menjadi gate utama Sprint 7.

Sprint 7 mendesain workflow untuk membuat invoice dari Lab Order yang sudah
`COMPLETED`, menerbitkan invoice, mencatat pembayaran, menghitung outstanding
balance, dan menjaga audit trail untuk aktivitas finance.

Invoice dan Payment harus tetap mengikuti arsitektur proyek:

```text
Controller
-> Request
-> Service
-> Repository
-> Model
```

Reusable system yang wajib dipakai:

```text
sys_audit_logs
sys_attachments
trx_lab_order_status_logs
```

Catatan penting:

```text
1. Sprint 7 tidak membuat sistem audit baru untuk invoice atau payment.
2. Bukti pembayaran atau invoice PDF di masa depan menggunakan sys_attachments.
3. Reporting dashboard tetap masuk Sprint 8.
```

---

## 2. Business Goals

Business goals Sprint 7:

```text
1. Finance dapat membuat invoice dari Lab Order yang sudah COMPLETED.
2. Invoice number dibuat otomatis, unik, dan konsisten.
3. Invoice dapat berisi satu atau lebih completed Lab Order.
4. Invoice biasanya dikelompokkan berdasarkan clinic.
5. Invoice item dapat mereferensikan lab_order_id.
6. Payment dapat dicatat terhadap invoice.
7. Partial payment didukung.
8. Full payment mengubah invoice menjadi PAID.
9. Outstanding balance dihitung akurat.
10. Payment tidak boleh melebihi outstanding balance.
11. Invoice void hanya boleh dilakukan sebelum ada payment.
12. Semua aktivitas penting tercatat di sys_audit_logs.
13. Permission dan policy membatasi akses finance.
```

---

## 3. Actors

| Actor | Responsibility |
| --- | --- |
| Admin | Mengawasi keseluruhan invoice dan payment, dapat override sesuai permission. |
| Finance | Membuat, menerbitkan, void invoice, dan mencatat payment. |
| Lab Staff | Melihat status invoice/payment terkait order, tanpa mutasi finance kecuali diberi permission. |
| Clinic | Pihak yang menerima invoice dan melakukan pembayaran. |
| System | Generate number, hitung total/outstanding, update status, dan menulis audit log. |

---

## 4. Invoice Workflow

### Generate Invoice From Completed Lab Orders

Flow:

```text
Open completed Lab Orders
-> Select one or more COMPLETED orders
-> Group by clinic
-> Generate invoice draft
-> Generate invoice number
-> Create invoice items
-> Review totals
-> Issue invoice
```

Rules:

```text
1. Lab Order must be COMPLETED before it can be invoiced.
2. Invoice can contain one or more completed Lab Orders.
3. Invoice is usually grouped by clinic.
4. One invoice item may reference lab_order_id.
5. A Lab Order must not be invoiced twice in active invoices.
6. VOID invoice releases its Lab Orders for re-invoicing only by explicit policy.
```

### Draft Invoice

`DRAFT` adalah status awal invoice setelah invoice dibuat tetapi belum resmi
ditagihkan ke clinic.

Draft invoice boleh:

```text
1. Ditinjau oleh Finance.
2. Diperbaiki item atau notes-nya sebelum issued.
3. Divoid jika belum ada payment.
```

Draft invoice tidak boleh:

```text
1. Menerima payment.
2. Dianggap outstanding resmi.
3. Masuk aging overdue.
```

### Issue Invoice

`ISSUED` berarti invoice sudah resmi ditagihkan ke clinic.

Issue invoice harus:

```text
1. Memiliki invoice number.
2. Memiliki clinic.
3. Memiliki minimal satu invoice item.
4. Memiliki invoice_date.
5. Memiliki due_date.
6. Memiliki total amount lebih dari 0.
7. Membuat audit log INVOICE_ISSUED.
```

### Invoice Item Calculation

Invoice item calculation:

```text
item_subtotal = quantity * unit_price
invoice_subtotal = sum(item_subtotal)
discount_amount = optional
tax_amount = optional for later policy
grand_total = subtotal - discount_amount + tax_amount
paid_amount = sum(valid payments)
outstanding_amount = grand_total - paid_amount
```

Recommended item source:

```text
trx_lab_order_items
```

Invoice item may capture a snapshot:

```text
lab_order_id
lab_service_id
description
quantity
unit_price
subtotal
```

Snapshot is important because invoice totals must not change unexpectedly when
master service prices change later.

### Due Date

Due date rules:

```text
1. Due date is required before invoice is issued.
2. Due date must be same day or after invoice_date.
3. Past due unpaid or partial invoice may become OVERDUE.
```

### Status Transitions

Main transitions:

```text
DRAFT -> ISSUED
ISSUED -> PARTIALLY_PAID
PARTIALLY_PAID -> PAID
ISSUED -> PAID
ISSUED -> OVERDUE
PARTIALLY_PAID -> OVERDUE
DRAFT -> VOID
ISSUED -> VOID
```

Blocked transitions:

```text
PAID -> VOID
VOID -> PAID
VOID -> PARTIALLY_PAID
VOID -> OVERDUE
```

---

## 5. Payment Workflow

### Record Payment

Flow:

```text
Open issued invoice
-> Enter payment amount
-> Enter payment date
-> Select payment method
-> Enter payment reference
-> Save payment
-> Recalculate paid_amount and outstanding_amount
-> Update invoice status
-> Create audit log
```

Payment may only be recorded when invoice status is:

```text
ISSUED
PARTIALLY_PAID
OVERDUE
```

Payment must be blocked when invoice status is:

```text
DRAFT
PAID
VOID
```

### Partial Payment

Partial payment occurs when:

```text
total_paid < grand_total
```

Invoice status becomes:

```text
PARTIALLY_PAID
```

If invoice is past due after partial payment, status may become:

```text
OVERDUE
```

The system must preserve all payment records.

### Full Payment

Full payment occurs when:

```text
total_paid = grand_total
```

Invoice status becomes:

```text
PAID
```

Paid invoice cannot be voided.

### Payment Reference

Payment reference is required for traceability.

Examples:

```text
Bank transfer reference
Cash receipt number
Card transaction number
Manual receipt number
```

If payment reference is missing, payment should be rejected unless payment method
policy explicitly allows it.

### Outstanding Balance Update

After every payment:

```text
paid_amount = sum(non-void payments)
outstanding_amount = grand_total - paid_amount
```

Rules:

```text
1. Payment amount must be greater than 0.
2. Payment amount must not exceed outstanding_amount.
3. Payment must never produce negative outstanding balance.
4. Recalculation must happen in a DB transaction with payment creation.
```

---

## 6. Invoice Status Flow

Main flow:

```text
DRAFT
  |
  v
ISSUED
  |
  v
PARTIALLY_PAID
  |
  v
PAID
```

Direct full payment:

```text
ISSUED
  |
  v
PAID
```

Overdue flow:

```text
ISSUED
  |
  v
OVERDUE

PARTIALLY_PAID
  |
  v
OVERDUE
```

Void flow:

```text
DRAFT
  |
  v
VOID

ISSUED
  |
  v
VOID
```

Void rules:

```text
1. DRAFT invoice can be voided if no payment exists.
2. ISSUED invoice can be voided if no payment exists.
3. PARTIALLY_PAID invoice cannot be voided.
4. PAID invoice cannot be voided.
5. VOID invoice cannot receive payment.
```

Expected invoice statuses:

```text
DRAFT
ISSUED
PARTIALLY_PAID
PAID
OVERDUE
VOID
```

Payment status impact:

```text
No payment      -> ISSUED or UNPAID display state
Partial payment -> PARTIALLY_PAID
Full payment    -> PAID
Past due unpaid -> OVERDUE
Cancelled       -> VOID
```

`UNPAID` may be used as a display label for issued invoices with `paid_amount =
0`, but the recommended stored invoice status is `ISSUED`.

---

## 7. Business Rules

Validation and domain rules:

```text
1. Invoice can only be generated from COMPLETED Lab Orders.
2. Invoice must have at least one invoice item.
3. Invoice usually belongs to one clinic.
4. Selected Lab Orders in one invoice should belong to the same clinic.
5. Invoice number must be unique and auto-generated.
6. Invoice number must not be manually edited.
7. Invoice item totals must be calculated server-side.
8. Grand total must be greater than 0 before issue.
9. Payment can only be recorded against ISSUED, PARTIALLY_PAID, or OVERDUE invoice.
10. Payment amount must be greater than 0.
11. Payment amount must not exceed outstanding balance.
12. Payment reference is required.
13. Paid invoice cannot be voided.
14. Invoice with any payment cannot be voided.
15. Void invoice cannot receive payment.
16. Every invoice/payment mutation must create sys_audit_logs.
17. Payment creation and invoice status update must be transactional.
18. Deleted Lab Orders must not remove invoice history.
19. Invoice and payment records must use soft delete where deletion is allowed.
20. Hard delete is not allowed for finance history.
```

---

## 8. Edge Cases

| Edge Case | Handling |
| --- | --- |
| Duplicate invoice generation | Block if selected Lab Order already belongs to active non-VOID invoice. |
| Payment exceeds outstanding balance | Reject payment and show validation error. |
| Invoice without completed order | Reject invoice creation. |
| Void invoice with payment | Block void action. |
| Payment on void invoice | Block payment creation. |
| Multiple payments | Allow as long as total paid does not exceed grand total. |
| Deleted Lab Order | Preserve invoice item snapshot; do not delete invoice history. |
| Re-generated invoice | Allowed only if previous invoice is VOID and policy permits re-invoicing. |
| Overdue invoice | System may mark `ISSUED` or `PARTIALLY_PAID` invoice as `OVERDUE` after due_date. |
| Incorrect payment amount | Reject zero, negative, non-numeric, or over-outstanding payment. |
| Missing payment reference | Reject unless future payment method policy allows reference-less cash payment. |
| Clinic mismatch | Reject invoice grouping if selected Lab Orders belong to different clinics. |
| Partial payment after overdue | Keep or return to `PARTIALLY_PAID` based on due date; if still past due, keep `OVERDUE`. |
| Payment after paid | Block because outstanding amount is 0. |
| Race condition double payment | Lock invoice row during payment transaction. |

---

## 9. Integration Points

## Lab Order Module

Invoice reads completed Lab Orders:

```text
trx_lab_orders.status = COMPLETED
```

Invoice item may reference:

```text
lab_order_id
lab_order_item_id
lab_service_id
```

The invoice workflow must not reopen or mutate delivery workflow status.

## Clinic Module

Invoice is usually grouped by clinic:

```text
trx_invoices.clinic_id -> mst_clinics.id
```

Clinic is used for billing identity, filtering, and future receivable reports.

## User Module

User relationships:

```text
trx_invoices.created_by -> users.id
trx_invoices.issued_by  -> users.id (recommended if schema adds it)
trx_payments.created_by -> users.id
```

## sys_audit_logs

All invoice/payment actions use:

```text
sys_audit_logs
entity_type
entity_id
action
old_values
new_values
performed_by
performed_at
```

Recommended entity targets:

```text
entity_type = trx_invoices
entity_id   = invoice_id

entity_type = trx_payments
entity_id   = payment_id
```

## sys_attachments

Future attachment usage:

```text
Invoice PDF
Payment proof
Receipt document
Bank transfer proof
```

Categories may include:

```text
INVOICE_PDF
PAYMENT_PROOF
PAYMENT_RECEIPT
```

Do not create separate attachment tables for invoice or payment.

## Permissions and Policies

Invoice and Payment routes must use:

```text
auth middleware
permission middleware
Laravel Policy
```

---

## 10. Permissions

Proposed Sprint 7 permissions:

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

Recommended grants:

| Role | Invoice Permissions | Payment Permissions |
| --- | --- | --- |
| Super Admin | All | All |
| Admin Lab | All | All |
| Finance | All invoice permissions | All payment permissions |
| Lab Staff | `view_invoice` only if needed | `view_payment` only if needed |
| Clinic | Future portal view only | Future portal view only |
| System | Internal status update only | Internal status update only |

---

## 11. Policy Rules

## Invoice Policy

Recommended operations:

```text
viewAny
view
create
issue
void
updateDraft
```

Rules:

| Operation | Rule |
| --- | --- |
| `viewAny` | User has `view_invoice` or `manage_invoice`. |
| `view` | User has `view_invoice` or `manage_invoice`; future clinic users only see their clinic invoices. |
| `create` | User has `create_invoice` or `manage_invoice`; selected orders must be `COMPLETED`. |
| `issue` | User has `issue_invoice` or `manage_invoice`; invoice must be `DRAFT`. |
| `void` | User has `void_invoice` or `manage_invoice`; invoice has no payments and is not `PAID`. |
| `updateDraft` | User has `create_invoice` or `manage_invoice`; invoice must be `DRAFT`. |

## Payment Policy

Recommended operations:

```text
viewAny
view
create
```

Rules:

| Operation | Rule |
| --- | --- |
| `viewAny` | User has `view_payment` or `manage_payment`. |
| `view` | User has `view_payment` or `manage_payment`; future clinic users only see their clinic payments. |
| `create` | User has `create_payment` or `manage_payment`; invoice must allow payment. |

Policy notes:

```text
1. Policies decide who may attempt an action.
2. Services enforce business rules and status transitions.
3. Super Admin may use Gate::before bypass as in previous sprints.
```

---

## 12. Data Model Summary

## trx_invoices

Purpose:

```text
Stores invoice header, billing clinic, invoice number, dates, totals, paid
amount, outstanding amount, and invoice status.
```

Recommended key fields:

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
grand_total
paid_amount
outstanding_amount
notes
created_by
issued_at
voided_at
voided_by
created_at
updated_at
deleted_at
```

## trx_invoice_items

Purpose:

```text
Stores invoice line items and snapshots of billed Lab Order/service data.
```

Recommended key fields:

```text
id
invoice_id
lab_order_id
lab_order_item_id
lab_service_id
description
quantity
unit_price
subtotal
created_at
updated_at
deleted_at
```

## trx_payments

Purpose:

```text
Stores payment records against invoices and supports multiple payments per invoice.
```

Recommended key fields:

```text
id
payment_number
invoice_id
payment_date
payment_method
payment_reference
amount
notes
created_by
created_at
updated_at
deleted_at
```

Do not create full migration code in this document.

---

## 13. Audit Log Strategy

Audit logs use:

```text
sys_audit_logs
```

Events that must be logged:

```text
INVOICE_CREATED
INVOICE_UPDATED
INVOICE_ISSUED
INVOICE_VOIDED
PAYMENT_RECORDED
INVOICE_MARKED_PARTIALLY_PAID
INVOICE_MARKED_PAID
INVOICE_MARKED_OVERDUE
```

Audit payload expectations:

| Event | Payload |
| --- | --- |
| `INVOICE_CREATED` | invoice_id, invoice_number, clinic_id, selected lab_order_ids, total |
| `INVOICE_UPDATED` | changed fields on draft invoice |
| `INVOICE_ISSUED` | old_status, new_status, issued_by, issued_at |
| `INVOICE_VOIDED` | old_status, new_status, void_reason, voided_by |
| `PAYMENT_RECORDED` | payment_id, invoice_id, amount, payment_method, payment_reference |
| `INVOICE_MARKED_PARTIALLY_PAID` | old_status, new_status, paid_amount, outstanding_amount |
| `INVOICE_MARKED_PAID` | old_status, new_status, paid_amount, outstanding_amount |
| `INVOICE_MARKED_OVERDUE` | old_status, new_status, due_date |

Rules:

```text
1. Audit log must be written in the same transaction as mutation when possible.
2. Audit log must not store raw files or binary payment proof.
3. Payment proof files use sys_attachments.
4. Payment reversal/refund audit is future scope unless implemented later.
```

---

## 14. Testing Scenarios

Required Sprint 7 feature tests:

```text
Invoice Queue/List
- authorized user can view invoice list
- guest redirected
- unauthorized user forbidden

Invoice Creation
- creates invoice from one COMPLETED Lab Order
- creates invoice from multiple COMPLETED Lab Orders in the same clinic
- rejects non-COMPLETED Lab Order
- rejects duplicate active invoice for same Lab Order
- generates unique invoice number
- creates invoice items
- calculates subtotal and grand_total
- creates audit log

Invoice Issue
- issues DRAFT invoice
- rejects issue without items
- rejects issue without due_date
- changes status to ISSUED
- creates audit log

Payment
- records partial payment
- records full payment
- rejects payment exceeding outstanding balance
- rejects zero or negative amount
- rejects missing payment reference
- rejects payment on DRAFT invoice
- rejects payment on VOID invoice
- updates paid_amount and outstanding_amount
- changes status to PARTIALLY_PAID or PAID
- creates audit log

Invoice Void
- voids DRAFT invoice with no payment
- voids ISSUED invoice with no payment
- rejects void when payment exists
- rejects void on PAID invoice
- creates audit log

Overdue
- marks ISSUED invoice overdue after due_date
- marks PARTIALLY_PAID invoice overdue after due_date
- does not mark PAID invoice overdue

Authorization
- permission checks enforced
- policy checks enforced
- Finance can manage invoice/payment
- Lab Staff cannot mutate finance records without permission

Regression
- Sprint 1-6 tests still pass
- Delivery COMPLETED remains invoice gate
- sys_audit_logs and sys_attachments are reused
```

Target after Sprint 7:

```text
Add focused tests while preserving all Sprint 1-6 tests.
```

---

## 15. Out of Scope

Sprint 7 explicitly excludes:

```text
Reporting dashboard
Accounting ledger
Tax reporting
External payment gateway
WhatsApp/email invoice sending
PDF template customization
Multi-currency
Refund handling unless needed later
Payment reversal workflow
Clinic customer portal
Automated bank reconciliation
```

---

## 16. Readiness Checklist

Sprint 7 workflow is ready for implementation when:

```text
- Workflow approved
- Status flow clear
- Tables identified
- Permissions identified
- Policy rules identified
- Edge cases covered
- Audit strategy clear
- Attachment strategy clear
- Tests planned
- No overlap with Sprint 8 Reporting
- No separate invoice/payment audit tables planned
- No separate invoice/payment attachment tables planned
```

Implementation handoff checklist:

```text
- Confirm whether Sprint 7 V1 allows one invoice for multiple Lab Orders.
- Update ERD if current ERD still states one-order-one-invoice.
- Update database_schema.md before migration implementation.
- Create sprint_7_technical_design.md before coding.
```
