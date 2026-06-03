# Reporting Workflow Design

## Project

Asia Dental Lab Management System

## Version

1.0

## Sprint

Sprint 8 - Reporting & Dashboard

## Architecture

Laravel 12, PostgreSQL, Modular Monolith

---

## 1. Overview

Reporting ditempatkan setelah Invoice & Payment karena Sprint 8 membutuhkan
data operasional dan finansial yang sudah lengkap dari Sprint 0 sampai Sprint 7.
Setelah Lab Order berjalan dari intake, production, QC, delivery, invoice, dan
payment, sistem sudah memiliki sumber data yang cukup untuk dashboard dan report
manajemen.

Sprint 8 menggunakan data dari modul yang sudah selesai:

```text
Master Data
Lab Order
Production
Quality Control
Delivery & POD
Invoice & Payment
Audit Logs
```

Reporting bersifat read-only. Report tidak boleh mengubah status order,
invoice, payment, delivery, QC, production assignment, atau data operasional
lainnya.

---

## 2. Business Goals

Business goals Sprint 8:

```text
1. Memberikan management visibility atas performa lab.
2. Melacak volume Lab Order.
3. Melacak workload production dan technician.
4. Melacak QC pass, reject, dan remake.
5. Melacak performa delivery dan POD completion.
6. Melacak revenue.
7. Melacak unpaid dan outstanding invoices.
8. Mendukung export untuk review operasional dan finance.
```

Sprint 8 membantu manajemen melihat bottleneck, pekerjaan tertunda, kualitas
produksi, performa pengiriman, dan posisi piutang tanpa mengubah proses bisnis
yang sudah berjalan.

---

## 3. Actors

| Actor | Responsibility |
| --- | --- |
| Admin | Melihat seluruh dashboard dan report sesuai permission. |
| Finance | Melihat report invoice, payment, revenue, outstanding, dan export finance. |
| Lab Manager | Melihat dashboard operasional, order, production, QC, delivery, dan export operasional. |
| Lab Staff | Melihat report operasional terbatas sesuai permission. |
| Clinic Owner / Management | Pihak bisnis yang menggunakan hasil report untuk review performa dan piutang. |
| System | Mengambil data, menghitung agregat, menerapkan filter, dan menghasilkan export read-only. |

---

## 4. Reporting Principles

Prinsip Reporting Sprint 8:

```text
1. Read-only.
2. Filterable.
3. Date-range based.
4. Clinic-aware.
5. Role-based access.
6. Exportable.
7. No mutation of source data.
8. No duplicated reporting tables in Sprint 8.
9. Calculations must be consistent with source modules.
10. Reports query existing Sprint 0-7 tables.
```

Reporting tidak membuat workflow baru. Reporting hanya membaca dan
mengagregasi data dari source-of-truth tables.

---

## 5. Dashboard Overview

Dashboard cards:

```text
Total Lab Orders
Orders In Progress
Orders Completed
Orders Pending QC
Orders Delivered
Total Revenue
Payments Received
Outstanding Amount
Overdue Invoices
Remake Count
```

Dashboard charts and tables:

```text
Orders by Status
Orders by Clinic
Revenue by Month
Payments by Method
QC Pass vs Reject
Delivery Status Summary
```

Recommended dashboard filters:

```text
date_from
date_to
clinic_id
```

Dashboard should show empty states when no data exists for the selected period.

---

## 6. Lab Order Reports

Report types:

```text
Order list report
Order status report
Order aging report
Order by clinic
Order by doctor
Order by service
Order by date range
Completed orders not yet invoiced
```

Primary data sources:

```text
trx_lab_orders
trx_lab_order_items
mst_clinics
mst_doctors
mst_patients
mst_lab_services
trx_invoice_items
```

Filters:

```text
date_from
date_to
clinic_id
doctor_id
status
service_id
```

Notes:

```text
1. Order date range should default to trx_lab_orders.order_date.
2. Aging can be calculated from order_date or due_date depending on report type.
3. Completed-not-invoiced report compares COMPLETED Lab Orders against active invoice items.
```

---

## 7. Production Reports

Report types:

```text
Technician workload
Production assignment report
Production completion report
Work log summary
Overdue production tasks if due dates exist
```

Primary data sources:

```text
trx_lab_order_assignments
trx_lab_work_logs
trx_lab_production_steps
trx_lab_orders
mst_technicians
users
```

Filters:

```text
date range
technician_id
clinic_id
production status
```

Notes:

```text
1. Technician workload should count active assignments and completed work.
2. Work log summary should use work log duration_minutes when available.
3. Production completion should be based on assignment/production status and Lab Order status.
```

---

## 8. Quality Control Reports

Report types:

```text
QC pending report
QC passed report
QC rejected report
Remake report
QC pass rate
QC reject reason summary if available
```

Primary data sources:

```text
trx_lab_quality_controls
trx_lab_qc_checklists
trx_lab_remake_requests
trx_lab_orders
mst_clinics
mst_technicians
users
```

Filters:

```text
date range
clinic_id
QC status
technician_id
```

Notes:

```text
1. QC pass rate should be calculated from QC result records.
2. Remake report should use trx_lab_remake_requests.
3. QC pending report should include Lab Orders with status QC_PENDING.
4. QC rejected report should include rejected QC records and remake context when available.
```

---

## 9. Delivery Reports

Report types:

```text
Delivery queue report
In delivery report
Delivered report
Completed delivery report
Courier performance
POD completion report
```

Primary data sources:

```text
trx_lab_deliveries
trx_lab_orders
mst_clinics
users as courier
sys_attachments
```

Filters:

```text
date range
courier_id
clinic_id
delivery status
```

Notes:

```text
1. Delivery queue should use READY_FOR_DELIVERY records.
2. Courier performance can count assigned, delivered, and completed deliveries.
3. POD completion should check receiver_name, receiver_signature_path, receiver_photo_path, and received_at.
4. Delivery reports should not upload or modify POD evidence.
```

---

## 10. Invoice Reports

Report types:

```text
Invoice list report
Invoice status report
Invoice by clinic
Invoice aging report
Invoice due date report
Void invoice report if needed
```

Primary data sources:

```text
trx_invoices
trx_invoice_items
trx_lab_orders
mst_clinics
users
```

Filters:

```text
invoice_date range
due_date range
clinic_id
status
```

Notes:

```text
1. VOID invoices should be excluded from revenue reports unless explicitly requested.
2. Invoice aging should use due_date and outstanding_amount.
3. Invoice by clinic should group by trx_invoices.clinic_id.
```

---

## 11. Payment Reports

Report types:

```text
Payment list report
Payment by method
Payment by date
Payment by invoice
Payment received by user
```

Primary data sources:

```text
trx_payments
trx_invoices
mst_clinics
users as received_by
users as created_by
```

Filters:

```text
payment_date range
payment_method
clinic_id
received_by
```

Notes:

```text
1. Payment totals must come from trx_payments.
2. Payment by clinic joins trx_payments to trx_invoices.
3. Payment received by user uses trx_payments.received_by.
```

---

## 12. Outstanding Invoice Reports

Report types:

```text
Outstanding invoices
Overdue invoices
Partially paid invoices
```

Aging buckets:

```text
0-7 days
8-14 days
15-30 days
> 30 days
```

Primary data sources:

```text
trx_invoices
mst_clinics
trx_payments
```

Rules:

```text
1. Outstanding amount comes from trx_invoices.outstanding_amount.
2. Overdue invoices are ISSUED, PARTIALLY_PAID, or OVERDUE with due_date before today.
3. PAID invoices should not appear in outstanding report.
4. VOID invoices should not appear in outstanding report unless explicitly filtered.
```

---

## 13. Revenue Reports

Report types:

```text
Revenue by month
Revenue by clinic
Revenue by service
Revenue from paid invoices
Invoice total vs payment total
```

Data source rules:

```text
1. Payment received report uses trx_payments.
2. Invoice revenue report uses trx_invoices.
3. Revenue by service uses trx_invoice_items joined to trx_lab_orders and trx_lab_order_items when needed.
4. Invoice total vs payment total compares trx_invoices.total_amount to trx_payments.amount.
5. VOID invoices are excluded from revenue unless explicitly shown in a void report.
```

Clarification:

```text
Revenue should be based on invoices or payments depending on report type.
Invoice-based revenue measures billed value.
Payment-based revenue measures cash received.
```

---

## 14. Export Workflow

Supported export:

```text
CSV export
Excel export if package already available
Simple CSV fallback when Excel package is not available
```

Export rules:

```text
1. Export respects filters.
2. Export respects permissions.
3. Export is read-only.
4. Export must not mutate source data.
5. Export should use the same query/calculation rules as on-screen reports.
6. Large exports should use pagination, chunking, or queued export only if needed later.
```

Out of Sprint 8 export scope:

```text
PDF report generation unless already simple
Scheduled email report
WhatsApp report sending
```

---

## 15. Permissions

Proposed permissions:

```text
manage_report
view_dashboard
view_order_report
view_production_report
view_qc_report
view_delivery_report
view_invoice_report
view_payment_report
export_report
```

Role suggestions:

| Role | Suggested Access |
| --- | --- |
| Admin | All report permissions. |
| Finance | Dashboard, invoice, payment, revenue, outstanding, export. |
| Lab Manager | Dashboard, order, production, QC, delivery, export. |
| Lab Staff | Limited operational reports. |
| Courier | No reporting access unless specifically needed. |

Notes:

```text
1. manage_report can be used as an override for Admin and Lab Manager.
2. Financial report access should not be granted broadly to operational users by default.
3. Export permission should be separate because exports can expose larger datasets.
```

---

## 16. Policy Rules

Policy rules:

```text
1. Admin can access all reports.
2. Finance can access financial reports.
3. Lab Manager can access operational reports.
4. Lab Staff has limited access.
5. Courier should not access reporting by default.
6. Export requires export_report or manage_report.
```

Suggested report groups:

```text
Operational reports:
order, production, QC, delivery

Financial reports:
invoice, payment, outstanding, revenue
```

---

## 17. Data Accuracy Rules

Data accuracy rules:

```text
1. Report calculations must use source-of-truth tables.
2. Payment totals must come from trx_payments.
3. Outstanding amount must come from trx_invoices.outstanding_amount.
4. QC report must use QC tables.
5. Delivery report must use trx_lab_deliveries.
6. Date filters should be inclusive and timezone-safe.
7. Deleted or void records should be handled consistently.
8. VOID invoices should be excluded from revenue unless explicitly shown in void report.
9. Reporting must not directly update cached totals or source records.
10. Calculations should match the business rules in source modules.
```

Date handling:

```text
date_from >= startOfDay in application timezone
date_to <= endOfDay in application timezone
```

Soft delete and void handling:

```text
1. Soft-deleted operational records are excluded by default.
2. VOID invoices are excluded from revenue and outstanding reports by default.
3. VOID invoices may appear in a dedicated invoice status report.
```

---

## 18. Edge Cases

Edge cases:

```text
1. No data for selected period.
2. Invalid date range.
3. User without report permission.
4. Clinic with no orders.
5. Invoice without payment.
6. Partially paid invoice.
7. VOID invoice.
8. Overdue invoice.
9. Orders not yet delivered.
10. Completed orders not yet invoiced.
11. Export large dataset.
12. Report totals mismatch due to manual data update.
```

Expected handling:

```text
1. Show empty state, not error, when no data exists.
2. Reject invalid date ranges with validation message.
3. Return forbidden response for unauthorized report access.
4. Keep calculation source visible or traceable for finance review.
5. Use consistent filters between screen and export.
```

---

## 19. Suggested Module Design

Future implementation module:

```text
app/Modules/Reporting
```

Components:

```text
Controllers
Services
Repositories
Requests
Policies
Tests
```

Suggested services:

```text
DashboardService
OrderReportService
ProductionReportService
QcReportService
DeliveryReportService
InvoiceReportService
PaymentReportService
ExportReportService
```

Suggested repository responsibilities:

```text
1. Build query for each report area.
2. Apply date, clinic, status, user, and method filters.
3. Return paginated data for screens.
4. Return filtered collections/chunks for export.
5. Avoid business mutations.
```

---

## 20. Testing Scenarios

Planned tests:

```text
dashboard can be viewed by authorized user
unauthorized user cannot access reports
order report filters by date and clinic
invoice report filters by status
payment report filters by method
outstanding report shows unpaid and partial invoices
revenue report calculates totals correctly
export respects filters
finance can access financial reports
courier cannot access reports
```

Additional recommended tests:

```text
invalid date range is rejected
VOID invoices are excluded from revenue by default
empty report period shows empty result
export requires export_report permission
```

---

## 21. Out of Scope

Sprint 8 explicitly excludes:

```text
Accounting ledger
Tax reporting
BI warehouse
Scheduled reports
Email/WhatsApp sending
PDF designer
Predictive analytics
Inventory reporting
HR reporting
RME reporting
Multi-branch consolidated enterprise BI beyond current lab data
```

Also excluded:

```text
reporting audit tables
reporting attachment tables
reporting summary tables
source data mutation from reports
```

---

## 22. Readiness Checklist

```text
- Reporting scope defined
- Dashboard cards defined
- Operational reports defined
- Financial reports defined
- Export workflow defined
- Permissions defined
- Edge cases covered
- No source data mutation
- No new summary tables required
- Ready for ERD review/update
```
