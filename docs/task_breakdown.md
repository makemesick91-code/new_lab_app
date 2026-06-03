# TASK_BREAKDOWN.md

# Asia Dental Lab Management System

Version: V1
Stack: Laravel 12 + Livewire 3 + PostgreSQL
Architecture: Modular Monolith

---

# 1. Purpose

Dokumen ini memecah sprint menjadi task kecil agar development mudah dikerjakan dengan:

```text
ChatGPT
Cursor
Claude Code
GitHub Copilot
Developer Manual
```

---

# 2. Task Rules

Setiap task harus:

```text
1. Mengikuti PRD.md
2. Mengikuti DATABASE_SCHEMA_V1.md
3. Mengikuti SYSTEM_ARCHITECTURE.md
4. Mengikuti PROJECT_RULES.md
5. Tidak menambah fitur di luar scope
6. Tidak mengubah schema tanpa update dokumentasi
```

---

# 3. Sprint 0 — Foundation

## TASK-0001 — Create Laravel Project

```text
Install Laravel 12 project.
Setup .env.
Connect PostgreSQL.
Run default migration.
```

Done jika:

```text
php artisan serve berjalan.
Database terkoneksi.
```

---

## TASK-0002 — Install Breeze

```text
Install Laravel Breeze Blade.
Setup login, logout, register disabled if needed.
```

Done jika:

```text
Login page berjalan.
User dapat login.
```

---

## TASK-0003 — Install Spatie Permission

```text
Install Spatie Laravel Permission.
Publish migration.
Run migration.
```

Done jika:

```text
roles dan permissions tersedia.
```

---

## TASK-0004 — Setup Database Queue

```text
Create jobs table.
Create failed_jobs table.
Set QUEUE_CONNECTION=database.
```

Done jika:

```text
php artisan queue:work berjalan.
```

---

## TASK-0005 — Setup Storage

```text
Run storage link.
Create storage folders:
lab-orders
order-attachments
signatures
delivery-photos
invoices
exports
```

Done jika:

```text
File dapat diakses dari public storage.
```

---

## TASK-0006 — Setup Modular Structure

```text
Create app/Modules.
Create base module folders.
```

Modules:

```text
Auth
User
Clinic
Doctor
Patient
LabService
Technician
LabOrder
QualityControl
Delivery
Invoice
Payment
Report
```

Done jika:

```text
Semua folder module tersedia.
```

---

## TASK-0007 — Setup Base Layout

```text
Create dashboard layout.
Create sidebar.
Create header.
Create role-based menu placeholder.
```

Done jika:

```text
User login melihat dashboard layout.
```

---

## TASK-0008 — Create Initial Seeder

```text
Create roles:
Super Admin
Admin Lab
Technician
Quality Control
Courier
Finance
Doctor

Create default admin user.
```

Done jika:

```text
Admin bisa login.
Admin punya Super Admin role.
```

---

# 4. Sprint 1 — User & Access Management

## TASK-0101 — User Migration Review

```text
Ensure users table matches DATABASE_SCHEMA_V1.md.
```

Done jika:

```text
users table sesuai schema.
```

---

## TASK-0102 — User Module

Create:

```text
User Model adjustment
User Repository
User Service
User Controller
User Request
User Livewire Page
```

Done jika:

```text
User CRUD berjalan.
```

---

## TASK-0103 — Role Module

Create:

```text
Role Repository
Role Service
Role Controller
Role Livewire Page
```

Done jika:

```text
Role CRUD berjalan.
```

---

## TASK-0104 — Permission Management

```text
Create permission list.
Assign permissions to roles.
```

Done jika:

```text
Role memiliki permission.
```

---

## TASK-0105 — Assign Role to User

```text
Create assign role form.
Connect user with role.
```

Done jika:

```text
User dapat diberi role.
```

---

## TASK-0106 — Access Policy

```text
Create basic policy and middleware.
Protect menu by role.
```

Done jika:

```text
User hanya melihat menu sesuai role.
```

---

## TASK-0107 — User Access Test

```text
Test create user.
Test assign role.
Test permission access.
```

Done jika:

```text
Semua test Sprint 1 lulus.
```

---

# 5. Sprint 2 — Master Data

## TASK-0201 — Clinic Module

Create:

```text
Migration mst_clinics
Model
Repository
Service
Request
Controller
Livewire CRUD
Test
```

Done jika:

```text
Clinic CRUD berjalan.
```

---

## TASK-0202 — Doctor Module

Create:

```text
Migration mst_doctors
Model
Repository
Service
Request
Controller
Livewire CRUD
Test
```

Done jika:

```text
Doctor CRUD berjalan.
Doctor terhubung ke Clinic.
```

---

## TASK-0203 — Patient Module

Create:

```text
Migration mst_patients
Model
Repository
Service
Request
Controller
Livewire CRUD
Test
```

Done jika:

```text
Patient CRUD berjalan.
```

---

## TASK-0204 — Lab Service Module

Create:

```text
Migration mst_lab_services
Model
Repository
Service
Request
Controller
Livewire CRUD
Test
```

Done jika:

```text
Lab Service CRUD berjalan.
```

---

## TASK-0205 — Technician Module

Create:

```text
Migration mst_technicians
Model
Repository
Service
Request
Controller
Livewire CRUD
Test
```

Done jika:

```text
Technician CRUD berjalan.
Technician terhubung ke user.
```

---

## TASK-0206 — Master Data Seeder

```text
Create sample clinics.
Create sample doctors.
Create sample lab services.
Create sample technicians.
```

Done jika:

```text
Data master awal tersedia.
```

---

# 6. Sprint 3 — Lab Order Core

## TASK-0301 — Lab Order Migration

Create migrations:

```text
trx_lab_orders
trx_lab_order_items
sys_attachments
```

Done jika:

```text
Migration berhasil.
Foreign key benar.
```

---

## TASK-0302 — Lab Order Models

Create:

```text
LabOrder
LabOrderItem
Attachment
```

Relationships:

```text
LabOrder hasMany Items
LabOrder belongsTo Clinic
LabOrder belongsTo Doctor
LabOrder belongsTo Patient
LabOrder hasMany Attachments
```

Done jika:

```text
Relationship berjalan.
```

---

## TASK-0303 — Lab Order Repository

Create:

```text
LabOrderRepositoryInterface
LabOrderRepository
```

Methods:

```text
paginate
findById
create
update
delete
```

Done jika:

```text
Repository berjalan.
```

---

## TASK-0304 — Lab Order Service

Business rules:

```text
Generate order number
Create order
Create order items
Create initial status log
Use DB transaction
```

Done jika:

```text
Order bisa dibuat lengkap dengan item.
```

---

## TASK-0305 — Create Order UI

Create Livewire page:

```text
Lab Order List
Create Lab Order
Order Detail
```

Done jika:

```text
Admin dapat membuat order dari UI.
```

---

## TASK-0306 — Attachment Upload

```text
Upload case photo.
Upload scan file.
Store file_path only.
Validate file type and size.
```

Done jika:

```text
Attachment tersimpan.
```

---

## TASK-0307 — Order Timeline

```text
Show status logs.
Show attachment history.
Show basic order events.
```

Done jika:

```text
Timeline tampil di order detail.
```

---

## TASK-0308 — Lab Order Test

Test:

```text
Create order
Create order without item should fail
Upload attachment
Cancel order
```

Done jika:

```text
Semua test Sprint 3 lulus.
```

---

# 7. Sprint 4 — Assignment & Workflow

## TASK-0401 — Assignment Migration

Create migrations:

```text
trx_lab_order_assignments
trx_lab_order_status_logs
```

Done jika:

```text
Tabel assignment dan status log tersedia.
```

---

## TASK-0402 — Assignment Model

Create:

```text
LabOrderAssignment
LabOrderStatusLog
```

Relationships:

```text
Assignment belongsTo LabOrder
Assignment belongsTo Technician
StatusLog belongsTo LabOrder
```

---

## TASK-0403 — Assignment Service

Business rules:

```text
Assign technician
Reassign technician
Create audit log
Create status log
Use DB transaction
```

Done jika:

```text
Order dapat diassign.
```

---

## TASK-0404 — Assignment UI

Create:

```text
Production Assignment List
Assign Technician Modal
My Assignments Page
Assignment Detail
```

Done jika:

```text
Admin dapat assign.
Technician dapat melihat assignment.
```

---

## TASK-0405 — Start Work

```text
Technician can start assignment.
Assignment status becomes IN_PROGRESS.
Order status becomes IN_PROGRESS.
```

Done jika:

```text
Teknisi dapat mulai kerja.
```

---

## TASK-0406 — Complete Work

```text
Technician can complete assignment.
Assignment status becomes DONE.
Order status becomes QC.
```

Done jika:

```text
Teknisi dapat mengirim pekerjaan ke QC.
```

---

## TASK-0407 — Assignment Test

Test:

```text
Assign technician
Start work
Complete work
Reassign technician
```

---

# 8. Sprint 5 — Quality Control

## TASK-0501 — QC Migration

Create:

```text
trx_lab_quality_controls
```

---

## TASK-0502 — QC Model

Create:

```text
LabQualityControl
```

Relationship:

```text
QC belongsTo LabOrder
QC belongsTo User
```

---

## TASK-0503 — QC Service

Business rules:

```text
Pass QC → order READY_FOR_DELIVERY
Revision → order REVISION
Reject → order remains QC/REJECTED note
All actions create status log
All actions create audit log
Use DB transaction
```

---

## TASK-0504 — QC UI

Create:

```text
Pending QC Page
QC Detail Page
QC History
```

---

## TASK-0505 — QC Actions

Create buttons:

```text
Pass
Reject
Revision
```

Validation:

```text
QC notes required.
```

---

## TASK-0506 — QC Test

Test:

```text
QC Pass
QC Revision
QC Reject
Order cannot delivery without QC Pass
```

---

# 9. Sprint 6 — Delivery & POD

## TASK-0601 — Delivery Migration

Create:

```text
trx_lab_deliveries
trx_lab_delivery_photos
```

---

## TASK-0602 — Delivery Models

Create:

```text
LabDelivery
LabDeliveryPhoto
```

Relationships:

```text
Delivery belongsTo LabOrder
Delivery hasMany Photos
Delivery belongsTo Courier
```

---

## TASK-0603 — Delivery Service

Business rules:

```text
Create delivery only if order READY_FOR_DELIVERY
Generate delivery number
Assign courier
Start delivery
Mark arrived
Use DB transaction
```

---

## TASK-0604 — POD Service

Business rules:

```text
Upload signature
Upload photo
Complete delivery
Require receiver_name
Require receiver_role
Require signature
Require minimum 1 photo
Set delivery status DELIVERED
Set order status DELIVERED
```

---

## TASK-0605 — Delivery UI

Create:

```text
Delivery List
Delivery Detail
My Deliveries
```

---

## TASK-0606 — POD UI

Create:

```text
POD Form
Signature Canvas
Photo Upload
Receiver Form
Complete Delivery Button
```

---

## TASK-0607 — Delivery Test

Test:

```text
Create delivery
Cannot create delivery before QC passed
Upload signature
Upload photo
Cannot complete without POD
Complete delivery successfully
```

---

# 10. Sprint 7 — Invoice & Payment

## TASK-0701 — Invoice Migration

Create:

```text
trx_invoices
trx_invoice_items
trx_payments
```

---

## TASK-0702 — Invoice Models

Create:

```text
Invoice
InvoiceItem
Payment
```

Relationships:

```text
Invoice belongsTo LabOrder
Invoice hasMany InvoiceItems
Invoice hasMany Payments
Payment belongsTo Invoice
```

---

## TASK-0703 — Invoice Service

Business rules:

```text
Invoice only after delivery DELIVERED
One order one invoice
Generate invoice number
Copy order items to invoice items
Calculate subtotal, discount, tax, grand_total
```

---

## TASK-0704 — Payment Service

Business rules:

```text
Generate payment number
Allow partial payment
Update paid_amount
Update outstanding_amount
Update invoice status
```

---

## TASK-0705 — Invoice UI

Create:

```text
Invoice List
Invoice Detail
Generate Invoice
Print Invoice
Void Invoice
```

---

## TASK-0706 — Payment UI

Create:

```text
Payment List
Record Payment Form
Payment History
```

---

## TASK-0707 — Finance Test

Test:

```text
Generate invoice
Prevent duplicate invoice
Create partial payment
Create full payment
Update invoice status
```

---

# 11. Sprint 8 — Dashboard & Reporting

## TASK-0801 — Dashboard Summary

Create widgets:

```text
Total Orders Today
Orders In Progress
Pending QC
Pending Delivery
Revenue This Month
Outstanding Invoice
```

---

## TASK-0802 — Role Dashboard

Create dashboards for:

```text
Admin Lab
Technician
QC
Courier
Finance
Doctor
```

---

## TASK-0803 — Order Report

Filters:

```text
Date range
Clinic
Doctor
Status
```

Export:

```text
Excel
PDF
```

---

## TASK-0804 — Delivery Report

Filters:

```text
Date range
Courier
Clinic
Status
```

---

## TASK-0805 — QC Report

Filters:

```text
Date range
QC user
Result
```

---

## TASK-0806 — Revenue Report

Filters:

```text
Date range
Clinic
Doctor
Invoice status
```

---

## TASK-0807 — Report Test

Test:

```text
Dashboard count
Order report filter
Revenue calculation
```

---

# 12. Sprint 9 — Stabilization & UAT

## TASK-0901 — Bug Fixing

```text
Fix all critical and high bugs.
```

---

## TASK-0902 — Performance Review

Check:

```text
Slow query
Pagination
Large upload
Dashboard load
```

---

## TASK-0903 — Security Review

Check:

```text
Role access
File upload validation
Unauthorized access
CSRF
```

---

## TASK-0904 — UAT Scenario

Test real workflow:

```text
Create order
Assign technician
Complete work
QC pass
Create delivery
Complete POD
Generate invoice
Record payment
```

---

## TASK-0905 — User Training

Prepare:

```text
Admin guide
Technician guide
Courier guide
Finance guide
```

---

# 13. Sprint 10 — Production Release

## TASK-1001 — Production Server Setup

```text
Ubuntu 24.04
Nginx
PHP-FPM
PostgreSQL
Supervisor
```

---

## TASK-1002 — Deploy Application

```text
Clone repo
Setup .env
composer install --no-dev
npm run build
php artisan migrate --force
php artisan storage:link
```

---

## TASK-1003 — Queue Worker

Setup supervisor for:

```text
php artisan queue:work
```

---

## TASK-1004 — Scheduler

Setup cron:

```text
* * * * * php artisan schedule:run
```

---

## TASK-1005 — Backup

Setup:

```text
Daily PostgreSQL backup
Weekly storage backup
```

---

## TASK-1006 — Go Live Checklist

```text
APP_ENV=production
APP_DEBUG=false
HTTPS active
Backup tested
Admin user ready
Roles ready
Seed data ready
```

---

# 14. Master Prompt for AI Coding

Gunakan prompt ini untuk setiap task:

```text
Ikuti dokumen berikut:

PRD.md
DATABASE_SCHEMA_V1.md
SYSTEM_ARCHITECTURE.md
PROJECT_RULES.md
SPRINT_PLAN.md
ERD.md
API_SPEC.md
UI_FLOW.md
DEVELOPMENT_SETUP.md
TASK_BREAKDOWN.md

Kerjakan hanya task berikut:
[TULIS TASK ID DAN DESKRIPSI]

Aturan:
- Jangan menambah fitur.
- Jangan menambah tabel.
- Jangan mengubah schema.
- Jangan mengubah arsitektur.
- Gunakan Laravel 12.
- Gunakan Livewire 3.
- Gunakan PostgreSQL.
- Gunakan Repository Pattern.
- Gunakan Service Layer.
- Gunakan Form Request Validation.
- Gunakan DB::transaction untuk transaksi penting.
- Output hanya kode/file yang dibutuhkan task ini.
```

---

# 15. Recommended First Task

Mulai dari:

```text
TASK-0001 — Create Laravel Project
```

Lalu lanjut berurutan sampai Sprint 0 selesai sebelum masuk Sprint 1.
