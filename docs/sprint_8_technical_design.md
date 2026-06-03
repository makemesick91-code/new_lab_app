# Sprint 8 Technical Design — Reporting & Dashboard

## Project

Asia Dental Lab Management System

## Version

1.0

## Sprint

Sprint 8 - Reporting & Dashboard

## Architecture

Laravel 12, Blade UI, PostgreSQL, Modular Monolith

---

# 1. Overview

Sprint 8 membangun Reporting & Dashboard setelah seluruh alur operasional dan
finance dasar selesai pada Sprint 0 sampai Sprint 7.

Sprint 8 bersifat:

```text
READ ONLY
REPORTING ONLY
NO DATA MUTATION
```

Reporting membaca data existing dari:

```text
User & Access Management
Master Data
Lab Order Core
Production Workflow
Quality Control
Delivery & POD
Invoice & Payment
```

Reporting tidak membuat workflow bisnis baru dan tidak mengubah status Lab
Order, Production, QC, Delivery, Invoice, atau Payment.

---

# 2. Architecture Decision

Sprint 8 mengikuti pattern project:

```text
Controller
-> Request
-> Service
-> Repository
-> Query Builder / Existing Models
```

Reporting tidak memiliki ownership data. Reporting hanya membaca existing
tables dan existing models. Reporting service bertanggung jawab untuk
calculation, grouping, summary, chart dataset, dan export dataset.

Rules:

```text
1. Controller menangani request, authorization, dan response/view.
2. Request menangani filter validation.
3. Service menangani calculation dan report composition.
4. Repository menangani query, filter, aggregate, dan pagination.
5. Query Builder / Existing Models membaca source tables Sprint 0-7.
6. Tidak ada mutation ke source tables.
```

---

# 3. Module Structure

Proposed module:

```text
app/Modules/Reporting
```

Structure:

```text
app/Modules/Reporting/
├── Controllers
├── Interfaces
├── Repositories
├── Requests
├── Services
├── Policies
└── Tests
```

No dedicated Reporting models are required. Existing models should be reused
where practical, and Query Builder can be used for aggregate-heavy reports.

Recommended classes:

```text
Controllers/DashboardController.php
Controllers/ReportController.php
Controllers/ExportReportController.php

Requests/DashboardRequest.php
Requests/OrderReportRequest.php
Requests/ProductionReportRequest.php
Requests/QcReportRequest.php
Requests/DeliveryReportRequest.php
Requests/InvoiceReportRequest.php
Requests/PaymentReportRequest.php

Services/DashboardService.php
Services/OrderReportService.php
Services/ProductionReportService.php
Services/QcReportService.php
Services/DeliveryReportService.php
Services/InvoiceReportService.php
Services/PaymentReportService.php
Services/RevenueReportService.php
Services/ExportReportService.php

Repositories/ReportingRepository.php

Policies/ReportingPolicy.php
```

---

# 4. No New Database Tables

Sprint 8 must not create database schema objects.

Explicit exclusions:

```text
No migrations
No new tables
No summary tables
No materialized views
No reporting audit tables
No reporting attachment tables
No reporting cache tables
No report export history tables
```

Sprint 8 reads existing source tables from Sprint 0-7. If performance issues
appear, index additions or materialized views are future optimization only, not
Sprint 8 scope.

---

# 5. Reporting Areas

## Dashboard

Cards:

```text
Total Orders
Orders In Progress
Orders Completed
Orders Delivered
Pending QC
Revenue
Outstanding Amount
Overdue Invoices
Remake Count
```

Charts:

```text
Orders by Status
Orders by Clinic
Revenue by Month
Payment by Method
QC Summary
Delivery Summary
```

Primary source tables:

```text
trx_lab_orders
trx_lab_deliveries
trx_invoices
trx_payments
trx_lab_quality_controls
trx_lab_remake_requests
```

## Order Reports

Source tables:

```text
trx_lab_orders
trx_lab_order_items
trx_lab_order_status_logs
mst_clinics
mst_doctors
mst_patients
mst_lab_services
```

Report types:

```text
order list
order status summary
order aging
order by clinic
order by doctor
order by service
completed orders not yet invoiced
```

## Production Reports

Source tables:

```text
trx_lab_order_assignments
trx_lab_work_logs
trx_lab_production_steps
mst_technicians
users
trx_lab_orders
```

Report types:

```text
technician workload
production assignment report
production completion report
work log summary
overdue production tasks if due dates exist
```

## QC Reports

Source tables:

```text
trx_lab_quality_controls
trx_lab_qc_checklists
trx_lab_remake_requests
sys_attachments if evidence counts are needed
```

Report types:

```text
QC pending
QC passed
QC rejected
remake report
pass/reject summary
reject reason summary if available
```

## Delivery Reports

Source tables:

```text
trx_lab_deliveries
trx_lab_orders
mst_clinics
users as courier
sys_attachments if POD/evidence counts are needed
```

Report types:

```text
delivery queue
in delivery
delivered
completed delivery
courier performance
POD completion statistics
```

## Invoice Reports

Source tables:

```text
trx_invoices
trx_invoice_items
mst_clinics
trx_lab_orders
```

Report types:

```text
invoice list
invoice status
invoice by clinic
invoice aging
due date report
void invoice report if explicitly filtered
```

## Payment Reports

Source tables:

```text
trx_payments
trx_invoices
mst_clinics
users as received_by
```

Report types:

```text
payment list
payment by method
payment by date
payment by invoice
payment received by user
```

## Outstanding Reports

Source tables:

```text
trx_invoices
trx_payments
mst_clinics
```

Report types:

```text
outstanding invoices
overdue invoices
partially paid invoices
aging buckets
```

## Revenue Reports

Source tables:

```text
trx_invoices
trx_invoice_items
trx_payments
mst_clinics
mst_lab_services if revenue by service is required
```

Revenue rules:

```text
Invoice revenue uses trx_invoices.
Payment received revenue uses trx_payments.
VOID invoices are excluded unless explicitly shown.
```

---

# 6. Controllers

## DashboardController

Methods:

```text
index
```

Responsibilities:

```text
dashboard metrics
dashboard charts
dashboard summaries
filter handling
authorization through ReportingPolicy
```

## ReportController

Methods:

```text
orders
production
qualityControl
delivery
invoices
payments
outstanding
revenue
```

Responsibilities:

```text
report rendering
filter handling
pagination
summary panels
authorization per report type
```

## ExportReportController

Methods:

```text
exportOrders
exportProduction
exportQualityControl
exportDelivery
exportInvoices
exportPayments
exportOutstanding
exportRevenue
```

Responsibilities:

```text
export generation
permission validation
reuse same filters as screen reports
stream CSV response
avoid file persistence
```

---

# 7. Requests

## DashboardRequest

Optional filters:

```text
date_from
date_to
clinic_id
```

## OrderReportRequest

Filters:

```text
date_from
date_to
clinic_id
doctor_id
status
service_id
```

## ProductionReportRequest

Filters:

```text
date_from
date_to
technician_id
status
clinic_id
```

## QcReportRequest

Filters:

```text
date_from
date_to
clinic_id
qc_status
technician_id
```

## DeliveryReportRequest

Filters:

```text
date_from
date_to
clinic_id
courier_id
delivery_status
```

## InvoiceReportRequest

Filters:

```text
date_from
date_to
clinic_id
invoice_status
```

## PaymentReportRequest

Filters:

```text
date_from
date_to
clinic_id
payment_method
received_by
```

Validation rules shared by all report requests:

```text
date_from nullable date
date_to nullable date after_or_equal date_from
foreign keys nullable exists in source tables
status filters nullable and constrained to known enums
```

Date filters should be inclusive and timezone-safe.

---

# 8. Services

## DashboardService

Responsibilities:

```text
dashboard cards
dashboard chart datasets
KPI calculations
empty-state-safe dashboard summaries
```

## OrderReportService

Responsibilities:

```text
order reports
order summaries
order aging
completed-not-invoiced analysis
```

## ProductionReportService

Responsibilities:

```text
workload reports
production completion reports
work log summaries
technician productivity summaries
```

## QcReportService

Responsibilities:

```text
pass/reject summaries
remake summaries
QC pending summaries
QC evidence count if needed
```

## DeliveryReportService

Responsibilities:

```text
courier performance
POD completion statistics
delivery status summaries
delivery aging if needed
```

## InvoiceReportService

Responsibilities:

```text
invoice reports
invoice aging
overdue analysis
invoice status summaries
```

## PaymentReportService

Responsibilities:

```text
payment reports
payment summaries
payment method analysis
payment received by user summaries
```

## RevenueReportService

Responsibilities:

```text
revenue calculation
monthly revenue
clinic revenue
invoice total vs payment total
```

## ExportReportService

Responsibilities:

```text
CSV export
Excel export if supported
shared export formatting
streamed downloads
permission-safe export orchestration
```

---

# 9. Repositories

Two possible repository approaches:

## Option A: Single ReportingRepository

Responsibilities:

```text
shared reporting queries
reusable filters
aggregation helpers
date range helpers
CSV dataset builders
```

Pros:

```text
less class sprawl
consistent filter behavior
simple for Sprint 8 read-only scope
```

Cons:

```text
can become large if reports grow
harder to isolate domain-specific query complexity
```

## Option B: Dedicated Report Repositories

```text
OrderReportRepository
ProductionReportRepository
QcReportRepository
DeliveryReportRepository
InvoiceReportRepository
PaymentReportRepository
RevenueReportRepository
```

Pros:

```text
clear domain boundaries
smaller query classes
easier to test per report area
```

Cons:

```text
more files
more bindings
possible duplicate filter helpers
```

Recommended approach:

```text
Use a single ReportingRepository for Sprint 8 plus small private/shared filter
helpers. Split into dedicated repositories later only when query complexity
or test setup becomes difficult to maintain.
```

---

# 10. Permissions

Sprint 8 permissions:

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

```text
Admin: all reporting permissions
Finance: dashboard, invoice report, payment report, outstanding report, revenue report, export
Lab Manager: dashboard, order report, production report, QC report, delivery report, export
Lab Staff: limited operational reports
Courier: no reporting access by default
```

---

# 11. Policies

## ReportingPolicy

Methods:

```text
viewDashboard
viewOrderReport
viewProductionReport
viewQcReport
viewDeliveryReport
viewInvoiceReport
viewPaymentReport
exportReport
```

Policy mapping:

```text
viewDashboard        -> view_dashboard or manage_report
viewOrderReport      -> view_order_report or manage_report
viewProductionReport -> view_production_report or manage_report
viewQcReport         -> view_qc_report or manage_report
viewDeliveryReport   -> view_delivery_report or manage_report
viewInvoiceReport    -> view_invoice_report or manage_report
viewPaymentReport    -> view_payment_report or manage_report
exportReport         -> export_report or manage_report
```

Finance access:

```text
Finance should access dashboard, invoice, payment, outstanding, revenue, and export.
```

Courier access:

```text
Courier should be denied reporting access by default.
```

---

# 12. Views

Planned views:

```text
resources/views/reports/dashboard.blade.php

resources/views/reports/orders.blade.php
resources/views/reports/production.blade.php
resources/views/reports/qc.blade.php
resources/views/reports/delivery.blade.php
resources/views/reports/invoices.blade.php
resources/views/reports/payments.blade.php
resources/views/reports/outstanding.blade.php
resources/views/reports/revenue.blade.php
```

View requirements:

```text
filter form
summary cards where useful
paginated table
empty state
export action where permitted
clear totals
status badges for report readability
```

Dashboard view should prioritize cards and compact charts/tables. Report views
should prioritize filter clarity, totals, and scan-friendly rows.

---

# 13. Export Strategy

Baseline:

```text
CSV Export
```

Optional:

```text
Excel Export
```

Rules:

```text
1. Export respects filters.
2. Export respects permissions.
3. Export uses existing query result.
4. Export does not persist files.
5. Export should not mutate source data.
6. Export should stream response where practical.
```

CSV fallback should be the default if no Excel package is available.

Out of Sprint 8 export:

```text
PDF export
persisted export files
scheduled export jobs
email/WhatsApp report sending
```

---

# 14. Data Accuracy Rules

```text
Revenue must come from source tables.
Payment totals must come from trx_payments.
Outstanding totals must come from trx_invoices.outstanding_amount.
Invoice revenue must come from trx_invoices.
Payment received revenue must come from trx_payments.
Delivery metrics must come from trx_lab_deliveries.
QC metrics must come from QC tables.
Lab Order status history must come from trx_lab_order_status_logs.
Reports must respect role permissions.
Reports must handle empty datasets.
Reports must handle VOID invoices correctly.
```

VOID invoice handling:

```text
VOID invoices are excluded from revenue and outstanding reports by default.
VOID invoices may appear in invoice status reports when explicitly filtered.
```

Date handling:

```text
date_from includes start of day in application timezone.
date_to includes end of day in application timezone.
```

---

# 15. Risks

```text
Large query performance
Missing indexes
Incorrect aggregations
Revenue mismatch
Date range errors
Permission leakage
Exporting large datasets
Dashboard slow loading
N+1 query problems
VOID invoice handling mistakes
Empty dataset display issues
```

Mitigation:

```text
1. Keep filters explicit and validated.
2. Reuse source-of-truth columns.
3. Use aggregate queries instead of loading huge collections.
4. Paginate screen reports.
5. Stream or chunk export if dataset grows.
6. Test permissions and financial calculations carefully.
```

---

# 16. Future Optimization

Future work only, not Sprint 8 scope:

```text
Query optimization
Additional indexes
Materialized views
Cached dashboard metrics
Scheduled report generation
Persisted export history
Queued large exports
BI warehouse integration
```

Sprint 8 should start with direct filtered queries against existing tables. Add
optimization only after real usage shows performance bottlenecks.

---

# 17. Testing Plan

Dashboard tests:

```text
authorized user can view dashboard
unauthorized user cannot view dashboard
dashboard shows expected cards
dashboard handles empty datasets
```

Report tests:

```text
order report filters correctly
invoice report filters correctly
payment report filters correctly
revenue report calculates correctly
outstanding report calculates correctly
QC report uses QC tables
delivery report uses delivery tables
```

Export tests:

```text
export respects filters
export respects permissions
export returns CSV response
```

Permission tests:

```text
admin access
finance access
lab manager access
lab staff limited access
courier denied
```

---

# 18. Quality Gates

Required commands:

```bash
php artisan migrate:fresh --seed
php artisan test
npm.cmd run build
```

Windows note:

```text
Use npm.cmd run build if PowerShell blocks npm.ps1.
```

Expected target:

```text
280+ tests
700+ assertions
Migration success
Build success
```

---

# 19. Out Of Scope

Sprint 8 explicitly excludes:

```text
Accounting Ledger
Tax Reporting
Inventory Reporting
HR Reporting
RME Reporting
Data Warehouse
BI Platform
Scheduled Reports
Email Reports
WhatsApp Reports
Predictive Analytics
Machine Learning Forecasting
PDF Report Designer
Materialized Views
Reporting Summary Tables
```

---

# 20. Implementation Readiness Checklist

```text
- Workflow design completed
- ERD updated
- Database schema updated
- Technical design completed
- No migration required
- No new tables required
- Permissions identified
- Policies identified
- Views identified
- Controllers identified
- Services identified
- Repositories identified
- Tests planned
- Export strategy defined
- Data accuracy rules defined
- Ready for Sprint 8 implementation
```
