# LAB ORDER DESIGN DOCUMENT

## Project

Asia Dental Lab Management System

## Version

1.0

## Sprint

Sprint 3 - Lab Order Core

---

# Purpose

Dokumen ini mendefinisikan desain bisnis, workflow, data model, dan aturan implementasi untuk modul Lab Order.

Modul ini menjadi fondasi utama seluruh proses bisnis Asia Dental Lab.

Semua modul berikutnya wajib mengacu pada desain ini:

* Production Workflow
* Quality Control
* Delivery
* Invoice
* Payment
* Reporting

---

# Business Flow

Clinic
↓
Doctor
↓
Patient
↓
Lab Order
↓
Production
↓
Quality Control
↓
Delivery
↓
Invoice
↓
Payment

---

# Scope Sprint 3

Sprint 3 hanya mencakup:

* Lab Order CRUD
* Order Item CRUD
* Attachment Upload
* Status Timeline
* Audit Trail
* Permission
* Policy
* Seeder
* Testing

Sprint 3 tidak mencakup:

* Production Workflow
* QC Workflow
* Delivery Workflow
* Invoice
* Payment

---

# Lab Order Entity

Lab Order merepresentasikan satu kasus pekerjaan laboratorium gigi.

Satu Lab Order:

* milik satu Clinic
* milik satu Doctor
* milik satu Patient
* memiliki satu atau lebih Lab Service

Contoh:

ADL-2026-000001

Patient:
Budi Santoso

Doctor:
drg. Andi

Clinic:
Smile Dental Clinic

Items:

* Zirconia Crown
* Temporary Crown

---

# Order Number Format

Format:

ADL-YYYY-XXXXXX

Contoh:

ADL-2026-000001
ADL-2026-000002
ADL-2026-000003

Rules:

* Unique
* Auto Generated
* Reset setiap tahun
* Tidak boleh diedit manual

---

# Order Status Workflow

## Main Workflow

DRAFT
↓
RECEIVED
↓
ASSIGNED
↓
IN_PRODUCTION
↓
QC_PENDING
↓
QC_PASSED
↓
READY_FOR_DELIVERY
↓
IN_DELIVERY
↓
DELIVERED
↓
COMPLETED

---

## Special Status

ON_HOLD

CANCELLED

REMAKE

---

## Status Enum — Single Source of Truth

Daftar status di atas (Main Workflow + Special Status) adalah **standar resmi
aplikasi** dan menjadi satu-satunya sumber kebenaran. `database_schema.md`
wajib mengikuti daftar ini.

Final enum:

```text
DRAFT
RECEIVED
ASSIGNED
IN_PRODUCTION
QC_PENDING
QC_PASSED
READY_FOR_DELIVERY
IN_DELIVERY
DELIVERED
COMPLETED
ON_HOLD
CANCELLED
REMAKE
```

Legacy status dipetakan sebagai berikut:

```text
IN_PROGRESS       → IN_PRODUCTION
WAITING_MATERIAL  → ON_HOLD
QC                → QC_PENDING
REVISION          → REMAKE
```

Tidak boleh lagi menggunakan `IN_PROGRESS`, `WAITING_MATERIAL`, `QC`, atau
`REVISION` sebagai status Lab Order.

Catatan: `IN_PROGRESS` pada status assignment (`trx_lab_order_assignments`)
dan `REVISION` pada hasil QC (`trx_lab_quality_controls.result`) adalah enum
yang berbeda dan tidak terpengaruh pemetaan ini.

---

## Sprint 3 Status

Implementasi aktif Sprint 3:

* RECEIVED
* CANCELLED

Status lainnya disiapkan sebagai enum untuk sprint berikutnya.

---

# Priority

NORMAL

URGENT

SUPER_URGENT

Default:

NORMAL

---

# Lab Order Header

Field:

* order_number
* clinic_id
* doctor_id
* patient_id
* order_date
* due_date
* priority
* notes
* status

---

# Lab Order Item

Satu order dapat memiliki banyak item.

Contoh:

Order:
ADL-2026-000001

Items:

1. Zirconia Crown
2. Temporary Crown
3. Retainer

---

# Attachment Management

Order dapat memiliki attachment.

Jenis attachment:

* Prescription
* Case Photo
* STL File
* X-Ray
* Other Document

---

# Attachment Table

sys_attachments

Field:

* id
* entity_type
* entity_id
* category
* file_name
* file_path
* mime_type
* file_size
* uploaded_by
* uploaded_at

Rules:

* Mendukung multiple files
* Soft delete
* Audit trail

---

# Status Timeline

Semua perubahan status wajib dicatat.

Contoh:

RECEIVED
↓
ASSIGNED
↓
IN_PRODUCTION

Tabel:

trx_lab_order_status_logs

Field:

* lab_order_id
* old_status
* new_status
* notes
* changed_by
* changed_at

---

# Audit Trail

Semua aktivitas wajib dicatat.

Contoh:

Order Created

Order Updated

Attachment Uploaded

Status Changed

Order Cancelled

Tabel:

sys_audit_logs

Field:

* entity_type
* entity_id
* action
* old_values
* new_values
* performed_by
* performed_at

---

# Delivery Preparation

Meskipun Delivery belum dibuat pada Sprint 3, field berikut wajib disiapkan sejak awal:

* delivery_signature_path
* delivery_photo_path
* received_by_name
* received_at

Tujuan:

Menghindari refactor database besar pada Sprint 6.

---

# Permission

Tambahkan permission berikut:

manage_lab_orders

view_lab_orders

create_lab_orders

update_lab_orders

cancel_lab_orders

---

# UI Structure

## Menu

Operations
└── Lab Orders

---

## Pages

Lab Orders List

Create Lab Order

Edit Lab Order

View Lab Order

---

# Lab Order Detail Tabs

Overview

Items

Attachments

Timeline

Audit Log

---

# Future Tabs

Assignment

Production

QC

Delivery

Invoice

Tabs tersebut cukup placeholder pada Sprint 3.

---

# Validation Rules

Create Order:

* clinic_id required
* doctor_id required
* patient_id required
* due_date required
* minimum 1 item

Update Order:

* status transition harus valid
* tidak boleh edit order yang sudah COMPLETED

Cancel Order:

* wajib notes

---

# Authorization

Gunakan:

* Spatie Permission
* Laravel Policy
* Middleware

Semua endpoint wajib memiliki authorization check.

---

# Definition Of Done

Sprint 3 dianggap selesai apabila:

✓ Lab Order CRUD

✓ Order Item CRUD

✓ Attachment Upload

✓ Status Timeline

✓ Audit Trail

✓ Order Number Generator

✓ Permission

✓ Policy

✓ Seeder

✓ Tests

✓ Migration Success

✓ Build Success

✓ All Tests Passed

✓ Documentation Updated
