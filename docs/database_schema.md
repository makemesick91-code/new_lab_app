# DATABASE_SCHEMA_V1.md

# Asia Dental Lab Management System

Version: V1
Database: PostgreSQL
Backend: Laravel 12

---

# 1. Fokus Database V1

Database V1 hanya mencakup fitur inti:

1. User, role, permission
2. Klinik
3. Dokter
4. Pasien
5. Service Lab
6. Teknisi
7. Lab Order
8. Assignment Teknisi
9. Quality Control
10. Delivery
11. Proof of Delivery
12. Invoice
13. Payment
14. Attachment
15. Audit Log

---

# 2. Daftar Tabel V1

```text
users
roles
permissions
model_has_roles
role_has_permissions

mst_clinics
mst_doctors
mst_patients
mst_lab_services
mst_technicians

trx_lab_orders
trx_lab_order_items
trx_lab_order_status_logs
trx_lab_order_assignments
trx_lab_work_logs
trx_lab_production_steps
trx_lab_quality_controls
trx_lab_qc_checklists
trx_lab_remake_requests
trx_lab_deliveries
trx_invoices
trx_invoice_items
trx_payments

sys_attachments
sys_audit_logs
```

---

# 3. Master Tables

## users

| Column        | Type         | Rule         |
| ------------- | ------------ | ------------ |
| id            | BIGINT       | PK           |
| name          | VARCHAR(150) | NOT NULL     |
| email         | VARCHAR(150) | UNIQUE       |
| password      | VARCHAR(255) | NOT NULL     |
| phone         | VARCHAR(50)  | NULL         |
| is_active     | BOOLEAN      | DEFAULT TRUE |
| last_login_at | TIMESTAMP    | NULL         |
| created_at    | TIMESTAMP    |              |
| updated_at    | TIMESTAMP    |              |
| deleted_at    | TIMESTAMP    | NULL         |

---

## roles

| Column     | Type         | Rule        |
| ---------- | ------------ | ----------- |
| id         | BIGINT       | PK          |
| name       | VARCHAR(100) | UNIQUE      |
| guard_name | VARCHAR(50)  | DEFAULT api |
| created_at | TIMESTAMP    |             |
| updated_at | TIMESTAMP    |             |

---

## permissions

| Column     | Type         | Rule        |
| ---------- | ------------ | ----------- |
| id         | BIGINT       | PK          |
| name       | VARCHAR(100) | UNIQUE      |
| guard_name | VARCHAR(50)  | DEFAULT api |
| created_at | TIMESTAMP    |             |
| updated_at | TIMESTAMP    |             |

---

## mst_clinics

> Revised in Sprint 2 (TASK-0201): added `city`, `province`, `postal_code`.

| Column      | Type         | Rule         |
| ----------- | ------------ | ------------ |
| id          | BIGINT       | PK           |
| code        | VARCHAR(50)  | UNIQUE       |
| name        | VARCHAR(150) | NOT NULL     |
| phone       | VARCHAR(50)  | NULL         |
| email       | VARCHAR(150) | NULL         |
| address     | TEXT         | NULL         |
| city        | VARCHAR(100) | NULL         |
| province    | VARCHAR(100) | NULL         |
| postal_code | VARCHAR(20)  | NULL         |
| is_active   | BOOLEAN      | DEFAULT TRUE |
| created_at  | TIMESTAMP    |              |
| updated_at  | TIMESTAMP    |              |
| deleted_at  | TIMESTAMP    | NULL         |

---

## mst_doctors

> Revised in Sprint 2 (TASK-0202): added unique `code`.

| Column     | Type         | Rule              |
| ---------- | ------------ | ----------------- |
| id         | BIGINT       | PK                |
| clinic_id  | BIGINT       | FK mst_clinics.id |
| code       | VARCHAR(50)  | UNIQUE            |
| name       | VARCHAR(150) | NOT NULL          |
| phone      | VARCHAR(50)  | NULL              |
| email      | VARCHAR(150) | NULL              |
| is_active  | BOOLEAN      | DEFAULT TRUE      |
| created_at | TIMESTAMP    |                   |
| updated_at | TIMESTAMP    |                   |
| deleted_at | TIMESTAMP    | NULL              |

---

## mst_patients

> Revised in Sprint 2 (TASK-0203): patient now belongs to a clinic and a doctor
> (`clinic_id`, `doctor_id`); `patient_code` → `medical_record_number`;
> `birth_date` → `date_of_birth`; added `is_active`.

| Column                | Type         | Rule              |
| --------------------- | ------------ | ----------------- |
| id                    | BIGINT       | PK                |
| clinic_id             | BIGINT       | FK mst_clinics.id |
| doctor_id             | BIGINT       | FK mst_doctors.id |
| medical_record_number | VARCHAR(50)  | UNIQUE NULL       |
| name                  | VARCHAR(150) | NOT NULL          |
| gender                | VARCHAR(20)  | NULL              |
| date_of_birth         | DATE         | NULL              |
| phone                 | VARCHAR(50)  | NULL              |
| address               | TEXT         | NULL              |
| is_active             | BOOLEAN      | DEFAULT TRUE      |
| created_at            | TIMESTAMP    |                   |
| updated_at            | TIMESTAMP    |                   |
| deleted_at            | TIMESTAMP    | NULL              |

---

## mst_lab_services

> Revised in Sprint 2 (TASK-0204): added `description`;
> `estimated_days` → `turnaround_days`; `base_price` → `price`.

| Column          | Type          | Rule         |
| --------------- | ------------- | ------------ |
| id              | BIGINT        | PK           |
| code            | VARCHAR(50)   | UNIQUE       |
| name            | VARCHAR(150)  | NOT NULL     |
| category        | VARCHAR(100)  | NULL         |
| description     | TEXT          | NULL         |
| turnaround_days | INTEGER       | DEFAULT 1    |
| price           | DECIMAL(18,2) | DEFAULT 0    |
| is_active       | BOOLEAN       | DEFAULT TRUE |
| created_at      | TIMESTAMP     |              |
| updated_at      | TIMESTAMP     |              |
| deleted_at      | TIMESTAMP     | NULL         |

Contoh service:

```text
Crown Zirconia
Crown PFM
Veneer
Bridge
Denture
Implant Crown
Night Guard
Retainer
```

---

## mst_technicians

> Revised in Sprint 2 (TASK-0205): `technician_code` → `code`;
> `skill_category` → `specialization`; added `email`; `user_id` made nullable
> (a technician may optionally map to a login account, ERD §5.1).

| Column         | Type         | Rule              |
| -------------- | ------------ | ----------------- |
| id             | BIGINT       | PK                |
| user_id        | BIGINT       | FK users.id NULL  |
| code           | VARCHAR(50)  | UNIQUE            |
| name           | VARCHAR(150) | NOT NULL          |
| phone          | VARCHAR(50)  | NULL              |
| email          | VARCHAR(150) | NULL              |
| specialization | VARCHAR(100) | NULL              |
| is_active      | BOOLEAN      | DEFAULT TRUE      |
| created_at     | TIMESTAMP    |                   |
| updated_at     | TIMESTAMP    |                   |
| deleted_at     | TIMESTAMP    | NULL              |

---

# 4. Transaction Tables

## trx_lab_orders

> Revised in Sprint 3 (Lab Order Core): added `updated_by` and the delivery
> preparation fields (`delivery_signature_path`, `delivery_photo_path`,
> `received_by_name`, `received_at`) per `lab_order_design.md` so Sprint 6
> (Delivery & POD) does not require a large schema refactor.

| Column                  | Type         | Rule                    |
| ----------------------- | ------------ | ----------------------- |
| id                      | BIGINT       | PK                      |
| order_number            | VARCHAR(50)  | UNIQUE                  |
| clinic_id               | BIGINT       | FK mst_clinics.id       |
| doctor_id               | BIGINT       | FK mst_doctors.id       |
| patient_id              | BIGINT       | FK mst_patients.id NULL |
| order_date              | DATE         | NOT NULL                |
| due_date                | DATE         | NULL                    |
| priority                | VARCHAR(20)  | DEFAULT NORMAL          |
| status                  | VARCHAR(50)  | DEFAULT RECEIVED        |
| notes                   | TEXT         | NULL                    |
| delivery_signature_path | VARCHAR(255) | NULL                    |
| delivery_photo_path     | VARCHAR(255) | NULL                    |
| received_by_name        | VARCHAR(150) | NULL                    |
| received_at             | TIMESTAMP    | NULL                    |
| created_by              | BIGINT       | FK users.id             |
| updated_by              | BIGINT       | FK users.id NULL        |
| created_at              | TIMESTAMP    |                         |
| updated_at              | TIMESTAMP    |                         |
| deleted_at              | TIMESTAMP    | NULL                    |

Status order (single source of truth — selaras dengan `lab_order_design.md`):

Main workflow:

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
```

Special status:

```text
ON_HOLD
CANCELLED
REMAKE
```

> Status enum di atas adalah standar resmi aplikasi. Legacy status telah
> dipetakan: `IN_PROGRESS → IN_PRODUCTION`, `WAITING_MATERIAL → ON_HOLD`,
> `QC → QC_PENDING`, `REVISION → REMAKE`.
>
> **Catatan Sprint 3:** seluruh enum disiapkan sejak sekarang, namun transisi
> status yang aktif pada Sprint 3 hanya `RECEIVED` dan `CANCELLED`. Status
> lainnya disiapkan untuk Sprint 4–7.
>
> Catatan: `IN_PROGRESS` (status assignment di `trx_lab_order_assignments`) dan
> `REVISION` (hasil QC di `trx_lab_quality_controls.result`) adalah enum yang
> berbeda dari status Lab Order dan tidak terpengaruh pemetaan ini.

---

## trx_lab_order_items

> Revised in Sprint 3 (Lab Order Core): pricing fields aligned to the Sprint 3
> design — `qty` → `quantity`, `price` → `unit_price` (per `lab_order_design.md`).
> The PRD-mandated case fields (`tooth_number`, `shade_color_text`,
> `material_text`) are retained.

| Column           | Type          | Rule                   |
| ---------------- | ------------- | ---------------------- |
| id               | BIGINT        | PK                     |
| lab_order_id     | BIGINT        | FK trx_lab_orders.id   |
| lab_service_id   | BIGINT        | FK mst_lab_services.id |
| tooth_number     | VARCHAR(20)   | NULL                   |
| shade_color_text | VARCHAR(100)  | NULL                   |
| material_text    | VARCHAR(100)  | NULL                   |
| quantity         | DECIMAL(18,2) | DEFAULT 1              |
| unit_price       | DECIMAL(18,2) | DEFAULT 0              |
| subtotal         | DECIMAL(18,2) | DEFAULT 0              |
| notes            | TEXT          | NULL                   |
| created_at       | TIMESTAMP     |                        |
| updated_at       | TIMESTAMP     |                        |
| deleted_at       | TIMESTAMP     | NULL                   |

Catatan:

```text
V1 belum memakai tabel mst_materials dan mst_shade_colors.
Material dan shade disimpan sebagai text agar lebih cepat dikembangkan.
subtotal = quantity * unit_price (discount dihitung di level invoice pada Sprint 7).
```

---

## trx_lab_order_status_logs

> Revised in Sprint 3 (Lab Order Core): `status_from` → `old_status`,
> `status_to` → `new_status`, and added `created_at` (per ERD §13 /
> `lab_order_design.md`).

| Column       | Type        | Rule                 |
| ------------ | ----------- | -------------------- |
| id           | BIGINT      | PK                   |
| lab_order_id | BIGINT      | FK trx_lab_orders.id |
| old_status   | VARCHAR(50) | NULL                 |
| new_status   | VARCHAR(50) | NOT NULL             |
| notes        | TEXT        | NULL                 |
| changed_by   | BIGINT      | FK users.id          |
| changed_at   | TIMESTAMP   | NOT NULL             |
| created_at   | TIMESTAMP   |                      |

---

## trx_lab_order_assignments

> Revised in Sprint 4 (Production Workflow): table now explicitly supports
> assignment history, reassignment, soft delete, and the handoff from
> `RECEIVED` to `ASSIGNED`. `IN_PROGRESS` remains an assignment status only;
> Lab Order status uses `IN_PRODUCTION`.

| Column        | Type        | Rule                  |
| ------------- | ----------- | --------------------- |
| id            | BIGINT      | PK                    |
| lab_order_id  | BIGINT      | FK trx_lab_orders.id  |
| technician_id | BIGINT      | FK mst_technicians.id |
| assigned_by   | BIGINT      | FK users.id           |
| assigned_at   | TIMESTAMP   | NOT NULL              |
| started_at    | TIMESTAMP   | NULL                  |
| completed_at  | TIMESTAMP   | NULL                  |
| status        | VARCHAR(50) | DEFAULT ASSIGNED      |
| notes         | TEXT        | NULL                  |
| created_at    | TIMESTAMP   |                       |
| updated_at    | TIMESTAMP   |                       |
| deleted_at    | TIMESTAMP   | NULL                  |

Status assignment:

```text
ASSIGNED
IN_PROGRESS
DONE
CANCELLED
REASSIGNED
```

Business rules:

```text
1. One Lab Order can have many assignment records over time.
2. Only one active assignment should exist per Lab Order at any time.
3. Reassignment creates a new assignment record.
4. Historical assignments must be preserved.
5. Cancelled Lab Orders cannot be assigned.
6. Lab Order status changes to ASSIGNED when assignment is created.
```

---

## trx_lab_work_logs

> Added in Sprint 4 (Production Workflow): immutable production work log records
> for technician work sessions. Work logs are tied to assignments and prepare
> traceability for Sprint 8 reporting. They do not store QC results.

| Column           | Type        | Rule                                |
| ---------------- | ----------- | ----------------------------------- |
| id               | BIGINT      | PK                                  |
| assignment_id    | BIGINT      | FK trx_lab_order_assignments.id     |
| event_type       | VARCHAR(50) | NOT NULL                            |
| started_at       | TIMESTAMP   | NULL                                |
| ended_at         | TIMESTAMP   | NULL                                |
| duration_minutes | INTEGER     | DEFAULT 0                           |
| notes            | TEXT        | NULL                                |
| performed_by     | BIGINT      | FK users.id                         |
| created_at       | TIMESTAMP   |                                     |

Event enum:

```text
WORK_STARTED
WORK_PAUSED
WORK_RESUMED
WORK_COMPLETED
STATUS_CHANGED
```

Business rules:

```text
1. Work logs are immutable historical records.
2. One assignment can have many work logs.
3. Start, pause, resume, and complete actions must create work logs.
4. Work logs must be tied to an assignment.
5. Work logs must capture who performed the action.
6. Completion work log prepares order for QC handoff.
```

---

## trx_lab_production_steps

> Added in Sprint 4 (Production Workflow): production progress and milestone
> tracking per Lab Order. Production steps prepare the order for QC handoff but
> do not model QC pass, reject, or remake behavior.

| Column       | Type         | Rule                 |
| ------------ | ------------ | -------------------- |
| id           | BIGINT       | PK                   |
| lab_order_id | BIGINT       | FK trx_lab_orders.id |
| step_name    | VARCHAR(100) | NOT NULL             |
| status       | VARCHAR(50)  | DEFAULT PENDING      |
| started_at   | TIMESTAMP    | NULL                 |
| completed_at | TIMESTAMP    | NULL                 |
| notes        | TEXT         | NULL                 |
| created_at   | TIMESTAMP    |                      |
| updated_at   | TIMESTAMP    |                      |

Recommended production step names:

```text
MODEL_PREPARATION
WAX_DESIGN
MILLING
PRINTING
FINISHING
POLISHING
PACKAGING
```

Step status enum:

```text
PENDING
IN_PROGRESS
COMPLETED
SKIPPED
ON_HOLD
```

Business rules:

```text
1. One Lab Order can have many production steps.
2. Production steps support future reporting.
3. Production steps prepare the Lab Order for QC handoff.
4. Production steps do not store QC result.
5. QC result belongs to Sprint 5, not Sprint 4.
```

---

## trx_lab_quality_controls

> Revised in Sprint 5 (Quality Control Workflow): `qc_by` is replaced by
> `inspected_by`, `qc_date` is replaced by `started_at` / `completed_at`, and
> soft delete is enabled. Older references to `qc_by` should be mapped to
> `inspected_by` during implementation.

| Column       | Type        | Rule                 |
| ------------ | ----------- | -------------------- |
| id           | BIGINT      | PK                   |
| lab_order_id | BIGINT      | FK trx_lab_orders.id |
| inspected_by | BIGINT      | FK users.id          |
| result       | VARCHAR(50) | NOT NULL             |
| notes        | TEXT        | NULL                 |
| started_at   | TIMESTAMP   | NULL                 |
| completed_at | TIMESTAMP   | NULL                 |
| created_at   | TIMESTAMP   |                      |
| updated_at   | TIMESTAMP   |                      |
| deleted_at   | TIMESTAMP   | NULL                 |

QC Result enum:

```text
PASSED
REJECTED
REVISION
```

Business rules:

```text
1. One Lab Order can have many QC reviews.
2. QC review can only start when Lab Order status = QC_PENDING.
3. QC review must capture inspector.
4. QC review must capture result.
5. QC review history must be preserved.
6. Soft delete enabled.
```

---

## trx_lab_qc_checklists

> Added in Sprint 5 (Quality Control Workflow): stores item-level QC checklist
> results for each QC review.

| Column             | Type         | Rule                              |
| ------------------ | ------------ | --------------------------------- |
| id                 | BIGINT       | PK                                |
| quality_control_id | BIGINT       | FK trx_lab_quality_controls.id    |
| checklist_item     | VARCHAR(100) | NOT NULL                          |
| result             | VARCHAR(50)  | NOT NULL                          |
| notes              | TEXT         | NULL                              |
| created_at         | TIMESTAMP    |                                   |
| updated_at         | TIMESTAMP    |                                   |

Checklist result enum:

```text
PASS
FAIL
N/A
```

Recommended checklist items:

```text
FIT_ACCURACY
MARGIN_QUALITY
OCCLUSION
SHADE_MATCH
CONTACT_POINT
SURFACE_FINISH
POLISHING
CLEANLINESS
PACKAGING_READINESS
```

Business rules:

```text
1. One QC review has many checklist records.
2. At least one checklist item must exist before QC completion.
3. Checklist history must be preserved.
```

---

## trx_lab_remake_requests

> Added in Sprint 5 (Quality Control Workflow): stores remake requests generated
> from QC reviews with result REJECTED or REVISION.

| Column             | Type        | Rule                              |
| ------------------ | ----------- | --------------------------------- |
| id                 | BIGINT      | PK                                |
| lab_order_id       | BIGINT      | FK trx_lab_orders.id              |
| quality_control_id | BIGINT      | FK trx_lab_quality_controls.id    |
| requested_by       | BIGINT      | FK users.id                       |
| reason             | VARCHAR(100)| NOT NULL                          |
| notes              | TEXT        | NULL                              |
| status             | VARCHAR(50) | DEFAULT OPEN                      |
| requested_at       | TIMESTAMP   | NOT NULL                          |
| created_at         | TIMESTAMP   |                                   |
| updated_at         | TIMESTAMP   |                                   |
| deleted_at         | TIMESTAMP   | NULL                              |

Remake status enum:

```text
OPEN
IN_PROGRESS
COMPLETED
CANCELLED
```

Business rules:

```text
1. Remake requires reason.
2. Remake preserves QC history.
3. Multiple remake requests may exist for a Lab Order.
4. Remake history must be preserved.
5. Soft delete enabled.
```

---

## trx_lab_deliveries

Purpose:

```text
Stores delivery records, courier assignment, delivery status, and POD
information for Lab Orders.
```

| Column                  | Type         | Rule                       |
| ----------------------- | ------------ | -------------------------- |
| id                      | BIGINT       | PK                         |
| lab_order_id            | BIGINT       | FK trx_lab_orders.id       |
| delivery_number         | VARCHAR(50)  | UNIQUE                     |
| courier_id              | BIGINT       | FK users.id NULL           |
| status                  | VARCHAR(50)  | DEFAULT READY_FOR_DELIVERY |
| delivery_notes          | TEXT         | NULL                       |
| receiver_name           | VARCHAR(150) | NULL                       |
| receiver_signature_path | VARCHAR(255) | NULL                       |
| receiver_photo_path     | VARCHAR(255) | NULL                       |
| received_at             | TIMESTAMP    | NULL                       |
| started_at              | TIMESTAMP    | NULL                       |
| completed_at            | TIMESTAMP    | NULL                       |
| created_by              | BIGINT       | FK users.id                |
| created_at              | TIMESTAMP    |                            |
| updated_at              | TIMESTAMP    |                            |
| deleted_at              | TIMESTAMP    | NULL                       |

Status delivery:

```text
READY_FOR_DELIVERY
IN_DELIVERY
DELIVERED
COMPLETED
CANCELLED
```

Business rule:

```text
1. One Lab Order can have many delivery records over time.
2. Only QC_PASSED orders can enter delivery.
3. Creating delivery changes Lab Order status to READY_FOR_DELIVERY.
4. Starting delivery changes Lab Order status to IN_DELIVERY.
5. Marking delivered changes Lab Order status to DELIVERED.
6. Completing delivery changes Lab Order status to COMPLETED.
7. Courier is required before IN_DELIVERY.
8. POD is required before COMPLETED.
9. Receiver name is required before COMPLETED.
10. Receiver signature is required before COMPLETED.
11. Receiver photo is required before COMPLETED.
12. Delivery history must be preserved.
13. Soft delete enabled.
14. No hard delete.
15. Delivery evidence must reuse sys_attachments.
16. Do not create trx_lab_delivery_photos for Sprint 6 V1.
```

---

## trx_invoices

Sprint 7 update:

```text
The old one-order-one-invoice schema is superseded. trx_invoices is now an
invoice header for one Clinic, and Lab Order billing links are stored in
trx_invoice_items.lab_order_id.
```

| Column             | Type          | Rule                 |
| ------------------ | ------------- | -------------------- |
| id                 | BIGSERIAL     | PRIMARY KEY          |
| invoice_number     | VARCHAR(50)   | UNIQUE NOT NULL      |
| clinic_id          | BIGINT        | FK mst_clinics.id    |
| invoice_date       | DATE          | NOT NULL             |
| due_date           | DATE          | NULL                 |
| status             | VARCHAR(30)   | DEFAULT DRAFT        |
| subtotal           | NUMERIC(15,2) | DEFAULT 0            |
| discount_amount    | NUMERIC(15,2) | DEFAULT 0            |
| tax_amount         | NUMERIC(15,2) | DEFAULT 0            |
| total_amount       | NUMERIC(15,2) | DEFAULT 0            |
| paid_amount        | NUMERIC(15,2) | DEFAULT 0            |
| outstanding_amount | NUMERIC(15,2) | DEFAULT 0            |
| notes              | TEXT          | NULL                 |
| created_by         | BIGINT        | FK users.id          |
| issued_at          | TIMESTAMP     | NULL                 |
| voided_at          | TIMESTAMP     | NULL                 |
| created_at         | TIMESTAMP     | NOT NULL             |
| updated_at         | TIMESTAMP     | NOT NULL             |

Status invoice:

```text
DRAFT
ISSUED
PARTIALLY_PAID
PAID
OVERDUE
VOID
```

---

## trx_invoice_items

| Column          | Type          | Rule                                 |
| --------------- | ------------- | ------------------------------------ |
| id              | BIGSERIAL     | PRIMARY KEY                          |
| invoice_id      | BIGINT        | FK trx_invoices.id ON DELETE CASCADE |
| lab_order_id    | BIGINT        | FK trx_lab_orders.id                 |
| description     | VARCHAR(255)  | NOT NULL                             |
| quantity        | INTEGER       | DEFAULT 1                            |
| unit_price      | NUMERIC(15,2) | DEFAULT 0                            |
| discount_amount | NUMERIC(15,2) | DEFAULT 0                            |
| total_price     | NUMERIC(15,2) | DEFAULT 0                            |
| created_at      | TIMESTAMP     | NOT NULL                             |
| updated_at      | TIMESTAMP     | NOT NULL                             |

---

## trx_payments

| Column           | Type          | Rule               |
| ---------------- | ------------- | ------------------ |
| id               | BIGSERIAL     | PRIMARY KEY        |
| payment_number   | VARCHAR(50)   | UNIQUE NOT NULL    |
| invoice_id       | BIGINT        | FK trx_invoices.id |
| payment_date     | DATE          | NOT NULL           |
| payment_method   | VARCHAR(30)   | NOT NULL           |
| amount           | NUMERIC(15,2) | NOT NULL           |
| reference_number | VARCHAR(100)  | NULL               |
| notes            | TEXT          | NULL               |
| received_by      | BIGINT        | FK users.id        |
| created_by       | BIGINT        | FK users.id        |
| created_at       | TIMESTAMP     | NOT NULL           |
| updated_at       | TIMESTAMP     | NOT NULL           |

Payment method:

```text
CASH
BANK_TRANSFER
QRIS
CARD
OTHER
```

---

# 5. System Tables

## sys_attachments

Digunakan untuk menyimpan file umum:

```text
Foto kasus
Scan intraoral
File pendukung order
File produksi
File pendukung QC
Dokumen invoice
```

> Revised in Sprint 3 (Lab Order Core): polymorphic columns
> `attachable_type` / `attachable_id` → `entity_type` / `entity_id`;
> `file_type` → `mime_type`; added `category` and soft-delete timestamps
> (per ERD §7 / `lab_order_design.md`).

| Column      | Type         | Rule        |
| ----------- | ------------ | ----------- |
| id          | BIGINT       | PK          |
| entity_type | VARCHAR(150) | NOT NULL    |
| entity_id   | BIGINT       | NOT NULL    |
| category    | VARCHAR(100) | NULL        |
| file_name   | VARCHAR(255) | NOT NULL    |
| file_path   | VARCHAR(255) | NOT NULL    |
| mime_type   | VARCHAR(100) | NULL        |
| file_size   | BIGINT       | NULL        |
| uploaded_by | BIGINT       | FK users.id |
| uploaded_at | TIMESTAMP    | NOT NULL    |
| created_at  | TIMESTAMP    |             |
| updated_at  | TIMESTAMP    |             |
| deleted_at  | TIMESTAMP    | NULL        |

Contoh `category`:

```text
Prescription
Case Photo
STL File
X-Ray
Other Document
WORK_PHOTO
DESIGN_FILE
STL_REVISION
REFERENCE_IMAGE
PRODUCTION_NOTE
QC_PHOTO
QC_NOTE
QC_EVIDENCE
QC_REJECTION_EVIDENCE
DELIVERY_PHOTO
POD_SIGNATURE
POD_RECEIVER_PHOTO
DELIVERY_EVIDENCE
```

Catatan Sprint 4:

```text
Production attachments memakai tabel sys_attachments yang sama.
Tidak ada tabel attachment baru untuk production workflow.
```

---

## sys_audit_logs

Mencatat aktivitas penting sistem.

> Revised in Sprint 3 (Lab Order Core): polymorphic columns
> `table_name` / `record_id` → `entity_type` / `entity_id`;
> `user_id` → `performed_by`; added `performed_at` (per ERD §7 /
> `lab_order_design.md`). `entity_type` / `entity_id` are nullable for
> actions not tied to a record (e.g. LOGIN / LOGOUT).

| Column       | Type         | Rule             |
| ------------ | ------------ | ---------------- |
| id           | BIGINT       | PK               |
| entity_type  | VARCHAR(150) | NULL             |
| entity_id    | BIGINT       | NULL             |
| action       | VARCHAR(100) | NOT NULL         |
| old_values   | JSONB        | NULL             |
| new_values   | JSONB        | NULL             |
| performed_by | BIGINT       | FK users.id NULL |
| performed_at | TIMESTAMP    | NOT NULL         |
| ip_address   | VARCHAR(100) | NULL             |
| user_agent   | TEXT         | NULL             |
| created_at   | TIMESTAMP    | NOT NULL         |

Audit wajib untuk:

```text
LOGIN
LOGOUT
CREATE
UPDATE
DELETE
STATUS_CHANGE
ASSIGNMENT
ASSIGN_TECHNICIAN
REASSIGN_TECHNICIAN
START_WORK
PAUSE_WORK
RESUME_WORK
COMPLETE_WORK
SEND_TO_QC
QC_APPROVAL
QC_REJECT
START_QC
PASS_QC
REJECT_QC
REQUEST_REMAKE
UPDATE_QC_CHECKLIST
UPLOAD_QC_EVIDENCE
CREATE_DELIVERY
ASSIGN_COURIER
REASSIGN_COURIER
START_DELIVERY
MARK_DELIVERED
COMPLETE_DELIVERY
UPLOAD_POD
INVOICE_CREATE
PAYMENT_CREATE
```

---

# 6. Relationship V1

```text
mst_clinics
  ├── mst_doctors
  ├── trx_lab_orders
  ├── trx_lab_deliveries
  └── trx_invoices

mst_doctors
  └── trx_lab_orders

mst_patients
  └── trx_lab_orders

mst_lab_services
  └── trx_lab_order_items

users
  ├── mst_technicians
  ├── trx_lab_orders.created_by
  ├── trx_lab_order_status_logs.changed_by
  ├── trx_lab_order_assignments.assigned_by
  ├── trx_lab_quality_controls.inspected_by
  ├── trx_lab_remake_requests.requested_by
  ├── trx_lab_deliveries.courier_id
  ├── trx_invoices.created_by
  ├── trx_payments.received_by
  └── trx_payments.created_by

mst_technicians
  └── trx_lab_order_assignments

trx_lab_orders
  ├── trx_lab_order_items
  ├── trx_lab_order_status_logs
  ├── trx_lab_order_assignments
  ├── trx_lab_quality_controls
  ├── trx_lab_remake_requests
  ├── trx_lab_deliveries
  └── trx_invoice_items

trx_lab_quality_controls
  ├── trx_lab_qc_checklists
  └── trx_lab_remake_requests


trx_invoices
  ├── trx_invoice_items
  └── trx_payments
```

---

## Sprint 4 Relationship Additions

```text
trx_lab_orders
  1 -> many trx_lab_order_assignments
  1 -> many trx_lab_production_steps

mst_technicians
  1 -> many trx_lab_order_assignments

users
  1 -> many trx_lab_order_assignments (assigned_by)
  1 -> many trx_lab_work_logs (performed_by)

trx_lab_order_assignments
  1 -> many trx_lab_work_logs
```

Foreign keys:

```text
trx_lab_order_assignments.lab_order_id  -> trx_lab_orders.id
trx_lab_order_assignments.technician_id -> mst_technicians.id
trx_lab_order_assignments.assigned_by   -> users.id

trx_lab_work_logs.assignment_id         -> trx_lab_order_assignments.id
trx_lab_work_logs.performed_by          -> users.id

trx_lab_production_steps.lab_order_id   -> trx_lab_orders.id
```

---

## Sprint 5 Relationship Additions

```text
trx_lab_orders
  1 -> many trx_lab_quality_controls
  1 -> many trx_lab_remake_requests

users
  1 -> many trx_lab_quality_controls (inspected_by)
  1 -> many trx_lab_remake_requests (requested_by)

trx_lab_quality_controls
  1 -> many trx_lab_qc_checklists
  1 -> many trx_lab_remake_requests

trx_lab_quality_controls
  polymorphic -> sys_attachments as QC evidence

trx_lab_orders
  polymorphic -> sys_attachments as order-level QC evidence
```

Foreign keys:

```text
trx_lab_quality_controls.lab_order_id -> trx_lab_orders.id
trx_lab_quality_controls.inspected_by -> users.id

trx_lab_qc_checklists.quality_control_id -> trx_lab_quality_controls.id

trx_lab_remake_requests.lab_order_id       -> trx_lab_orders.id
trx_lab_remake_requests.quality_control_id -> trx_lab_quality_controls.id
trx_lab_remake_requests.requested_by       -> users.id
```

---

## Sprint 6 Relationship Additions

```text
trx_lab_orders
  1 -> many trx_lab_deliveries

users
  1 -> many trx_lab_deliveries (created_by)
  1 -> many trx_lab_deliveries (courier_id)

trx_lab_deliveries
  polymorphic -> sys_attachments as POD evidence
  polymorphic -> sys_audit_logs as delivery audit trail

trx_lab_orders
  polymorphic -> sys_attachments as order-level delivery evidence
  1 -> many trx_lab_order_status_logs
```

Foreign keys:

```text
trx_lab_deliveries.lab_order_id -> trx_lab_orders.id
trx_lab_deliveries.courier_id   -> users.id
trx_lab_deliveries.created_by   -> users.id
```

---

# 7. Index Recommendation

## trx_lab_orders

```text
order_number UNIQUE
clinic_id INDEX
doctor_id INDEX
patient_id INDEX
status INDEX
order_date INDEX
due_date INDEX
```

## trx_lab_order_items

```text
lab_order_id INDEX
lab_service_id INDEX
```

## trx_lab_order_status_logs

```text
lab_order_id INDEX
changed_by INDEX
changed_at INDEX
```

## trx_lab_order_assignments

```text
lab_order_id INDEX
technician_id INDEX
assigned_by INDEX
status INDEX
assigned_at INDEX
```

## trx_lab_work_logs

```text
assignment_id INDEX
event_type INDEX
performed_by INDEX
created_at INDEX
```

## trx_lab_production_steps

```text
lab_order_id INDEX
step_name INDEX
status INDEX
```

## trx_lab_quality_controls

```text
lab_order_id INDEX
inspected_by INDEX
result INDEX
completed_at INDEX
```

## trx_lab_qc_checklists

```text
quality_control_id INDEX
checklist_item INDEX
result INDEX
```

## trx_lab_remake_requests

```text
lab_order_id INDEX
quality_control_id INDEX
requested_by INDEX
status INDEX
requested_at INDEX
```

## trx_lab_deliveries

```text
delivery_number UNIQUE
lab_order_id INDEX
courier_id INDEX
status INDEX
received_at INDEX
created_by INDEX
```

## trx_invoices

```text
invoice_number UNIQUE
clinic_id INDEX
status INDEX
invoice_date INDEX
due_date INDEX
created_by INDEX
```

## trx_invoice_items

```text
invoice_id INDEX
lab_order_id INDEX
invoice_id, lab_order_id INDEX
```

## trx_payments

```text
payment_number UNIQUE
invoice_id INDEX
payment_date INDEX
payment_method INDEX
received_by INDEX
created_by INDEX
```

## sys_attachments

```text
entity_type INDEX
entity_id INDEX
uploaded_by INDEX
```

## sys_audit_logs

```text
entity_type INDEX
entity_id INDEX
performed_by INDEX
performed_at INDEX
action INDEX
```

---

# 8. Business Rules V1

## Lab Order

```text
1. Nomor order wajib unik.
2. Order minimal memiliki 1 item.
3. Semua perubahan status wajib masuk status log.
4. Order tidak boleh COMPLETED sebelum delivery DELIVERED.
```

## Assignment

```text
1. Order dapat diassign ke satu atau lebih teknisi.
2. Assignment dapat direassign.
3. Reassign wajib masuk audit log.
4. One Lab Order can have many assignment records over time.
5. Only one active assignment should exist per Lab Order at any time.
6. Reassignment creates a new assignment record.
7. Historical assignments must be preserved.
8. Cancelled Lab Orders cannot be assigned.
9. Lab Order status changes to ASSIGNED when assignment is created.
10. IN_PROGRESS adalah status assignment, bukan status Lab Order.
```

## Production Workflow

```text
1. Sprint 4 mengaktifkan status ASSIGNED, IN_PRODUCTION, ON_HOLD, dan QC_PENDING.
2. RECEIVED dan CANCELLED berasal dari Sprint 3.
3. QC_PASSED dan REMAKE tetap reserved untuk Sprint 5.
4. Creating assignment changes Lab Order status to ASSIGNED.
5. Starting work changes Lab Order status to IN_PRODUCTION.
6. Putting work on hold changes Lab Order status to ON_HOLD.
7. Completing production changes Lab Order status to QC_PENDING.
8. All Lab Order status changes must create trx_lab_order_status_logs.
9. All production actions must create sys_audit_logs.
10. Sprint 4 tidak mendefinisikan QC result workflow.
```

## Quality Control

```text
1. QC review can only start when Lab Order status = QC_PENDING.
2. Cancelled Lab Orders cannot be reviewed.
3. QC review must capture inspected_by.
4. QC review must capture result.
5. QC must have at least one checklist result before completion.
6. QC result PASSED changes Lab Order status to QC_PASSED.
7. QC result REJECTED changes Lab Order status to REMAKE.
8. QC result REVISION changes Lab Order status to REMAKE.
9. QC Result and Lab Order Status are separate concepts.
10. Remake requires reason.
11. Remake preserves original production history.
12. Remake preserves QC history.
13. Every QC action must create sys_audit_logs.
14. Every Lab Order status change must create trx_lab_order_status_logs.
15. Delivery cannot start before QC_PASSED.
```

## Delivery

```text
1. Delivery hanya bisa dibuat dari Lab Order QC_PASSED.
2. Membuat delivery mengubah Lab Order menjadi READY_FOR_DELIVERY.
3. Delivery wajib memiliki kurir sebelum IN_DELIVERY.
4. POD wajib berisi nama penerima.
5. POD wajib berisi tanda tangan digital sebagai file path.
6. POD wajib berisi foto penerima atau bukti penerimaan sebagai file path.
7. POD evidence wajib memakai sys_attachments.
8. Status DELIVERED hanya boleh jika POD lengkap.
9. Status COMPLETED hanya boleh jika delivery/POD sudah lengkap.
10. Every delivery action must create sys_audit_logs.
11. Every Lab Order status change must create trx_lab_order_status_logs.
12. No hard delete for delivery history.
```

## Invoice

```text
1. Invoice hanya bisa dibuat dari Lab Order dengan status COMPLETED.
2. Satu invoice dapat berisi beberapa Lab Order dari Clinic yang sama.
3. Lab Order billing link disimpan di trx_invoice_items.lab_order_id.
4. Invoice dapat dibayar sebagian.
5. Jika paid_amount = 0 dan invoice sudah issued, status ISSUED.
6. Jika paid_amount > 0 dan paid_amount < total_amount, status PARTIALLY_PAID.
7. Jika paid_amount = total_amount, status PAID.
8. Invoice VOID tidak boleh menerima payment.
```

---

# 9. Numbering Format

## Order Number

```text
ADL-YYYY-XXXXXX
```

Contoh:

```text
ADL-2026-000001
```

## Delivery Number

```text
DLV-YYYY-XXXXXX
```

Contoh:

```text
DLV-2026-000001
```

## Invoice Number

```text
INV-YYYY-XXXXXX
```

Contoh:

```text
INV-2026-000001
```

## Payment Number

```text
PAY-YYYY-XXXXXX
```

Contoh:

```text
PAY-2026-000001
```

---

# 10. Suggested Migration Order

```text
1. users
2. roles
3. permissions
4. model_has_roles
5. role_has_permissions

6. mst_clinics
7. mst_doctors
8. mst_patients
9. mst_lab_services
10. mst_technicians

11. trx_lab_orders
12. trx_lab_order_items
13. trx_lab_order_status_logs
14. trx_lab_order_assignments
15. trx_lab_work_logs
16. trx_lab_production_steps
17. trx_lab_quality_controls
18. trx_lab_qc_checklists
19. trx_lab_remake_requests
20. trx_lab_deliveries
21. trx_invoices
22. trx_invoice_items
23. trx_payments

24. sys_attachments
25. sys_audit_logs
```

---

# 11. Initial Seed Data

## Roles

```text
Super Admin
Admin Lab
Technician
Quality Control
Courier
Finance
Doctor
```

## Order Status

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

## Delivery Status

```text
READY_FOR_DELIVERY
IN_DELIVERY
DELIVERED
COMPLETED
CANCELLED
```

## Invoice Status

```text
DRAFT
ISSUED
PARTIALLY_PAID
PAID
OVERDUE
VOID
```

---

# 12. Fitur yang Ditunda ke V2

```text
mst_materials
mst_shade_colors
sys_notifications
inventory_material_lab
whatsapp_notification
gps_tracking_kurir
customer_portal
mobile_app
```

---

# 13. Catatan Penting

```text
1. Tanda tangan digital disimpan sebagai file.
2. Foto penerimaan disimpan sebagai file.
3. Database hanya menyimpan file_path.
4. Semua transaksi penting wajib menggunakan DB transaction.
5. Semua perubahan status wajib tercatat di status log.
6. Semua aksi penting wajib tercatat di audit log.
7. V1 harus fokus sampai order, QC, delivery, POD, invoice, dan payment berjalan stabil.
```

---

# 14. Sprint 3 Lab Order Core Schema

Bagian ini menyelaraskan database schema dengan **ERD Sprint 3** (source of truth)
dan `lab_order_design.md`.

## 14.1 Tujuan Tabel

```text
trx_lab_orders            Header order lab — pusat transaksi pertama.
trx_lab_order_items       Item pekerjaan (service) dalam sebuah order.
trx_lab_order_status_logs Riwayat setiap perubahan status order.
sys_attachments           File polymorphic (prescription, foto kasus, STL, X-ray, dll).
sys_audit_logs            Audit trail polymorphic untuk seluruh aktivitas penting.
```

## 14.2 Relasi

```text
mst_clinics      1 → many  trx_lab_orders            (clinic_id)
mst_doctors      1 → many  trx_lab_orders            (doctor_id)
mst_patients     1 → many  trx_lab_orders            (patient_id, nullable)
users            1 → many  trx_lab_orders            (created_by, updated_by)

trx_lab_orders   1 → many  trx_lab_order_items       (lab_order_id)
mst_lab_services 1 → many  trx_lab_order_items       (lab_service_id)

trx_lab_orders   1 → many  trx_lab_order_status_logs (lab_order_id)
users            1 → many  trx_lab_order_status_logs (changed_by)

users            1 → many  sys_attachments           (uploaded_by)
users            1 → many  sys_audit_logs            (performed_by)

trx_lab_orders   1 → many  sys_attachments           (polymorphic: entity_type/entity_id)
trx_lab_orders   1 → many  sys_audit_logs            (polymorphic: entity_type/entity_id)
```

## 14.3 Foreign Keys

```text
trx_lab_orders.clinic_id            → mst_clinics.id
trx_lab_orders.doctor_id            → mst_doctors.id
trx_lab_orders.patient_id           → mst_patients.id (nullable)
trx_lab_orders.created_by           → users.id
trx_lab_orders.updated_by           → users.id (nullable)

trx_lab_order_items.lab_order_id    → trx_lab_orders.id
trx_lab_order_items.lab_service_id  → mst_lab_services.id

trx_lab_order_status_logs.lab_order_id → trx_lab_orders.id
trx_lab_order_status_logs.changed_by   → users.id

sys_attachments.uploaded_by         → users.id
sys_audit_logs.performed_by         → users.id (nullable)
```

## 14.4 Polymorphic Strategy

```text
sys_attachments : entity_type, entity_id
sys_audit_logs  : entity_type, entity_id
```

Kedua tabel dibuat polymorphic agar **reusable tanpa refactor schema** untuk:

```text
Lab Order  (Sprint 3)
QC         (Sprint 5)
Delivery   (Sprint 6)
Invoice    (Sprint 7)
Payment    (Sprint 7)
```

## 14.5 Business Rules

```text
1. order_number wajib unik, auto-generate, format ADL-YYYY-XXXXXX, reset per tahun.
2. Satu order memiliki tepat satu clinic, satu doctor, dan satu patient.
3. Satu order wajib memiliki minimal satu item.
4. subtotal item = quantity * unit_price.
5. Setiap perubahan status wajib masuk trx_lab_order_status_logs (old_status, new_status, changed_by).
6. Setiap aksi penting wajib masuk sys_audit_logs (entity_type, entity_id, action, performed_by).
7. File tidak disimpan di database; database hanya menyimpan file_path.
8. Sprint 3 hanya mengaktifkan transisi status RECEIVED dan CANCELLED.
9. Cancel order wajib menyertakan notes.
```

## 14.6 Future Extensibility (Sprint 4–7)

```text
1. delivery_signature_path, delivery_photo_path, received_by_name, received_at sudah
   disiapkan di trx_lab_orders agar Sprint 6 (Delivery & POD) tanpa refactor besar.
2. Status enum lengkap sudah didefinisikan; sprint berikutnya tinggal mengaktifkan transisi.
3. sys_attachments & sys_audit_logs polymorphic siap dipakai QC, Delivery, Invoice, Payment.
4. trx_lab_order_assignments, trx_lab_quality_controls, dan trx_lab_deliveries
   tetap terhubung langsung ke trx_lab_orders sesuai ERD.
5. Sprint 7 menagihkan Lab Order melalui trx_invoice_items.lab_order_id, bukan
   melalui trx_invoices.lab_order_id.
```

## 14.7 Validation Checklist — Sprint 3 Schema

```text
✓ Lab Order Table          (trx_lab_orders + updated_by + delivery prep fields)
✓ Lab Order Item Table     (trx_lab_order_items: quantity, unit_price, subtotal)
✓ Status Log Table         (trx_lab_order_status_logs: old_status, new_status)
✓ Attachment Table         (sys_attachments: entity_type, entity_id, category, mime_type)
✓ Audit Log Table          (sys_audit_logs: entity_type, entity_id, performed_by, performed_at)
✓ Polymorphic Columns      (entity_type / entity_id pada kedua tabel sys_*)
✓ Foreign Keys             (clinic, doctor, patient, lab_service, users)
✓ Index Recommendation     (order_number UNIQUE, status, clinic, doctor, patient, due_date, entity_*)
✓ Delivery Preparation Fields (delivery_signature_path, delivery_photo_path, received_by_name, received_at)
✓ Sprint 3 Ready
```

---

# 15. Sprint 4 Production Workflow Schema

Bagian ini memperluas database schema untuk **Sprint 4 - Production Workflow**
berdasarkan `production_workflow_design.md` dan ERD Sprint 4.

## 15.1 Purpose

```text
Mendukung assignment teknisi, pencatatan work log produksi, production step,
dan QC handoff preparation dari Lab Order status RECEIVED sampai QC_PENDING.
```

## 15.2 Scope

Sprint 4 mencakup:

```text
1. Technician Assignment
2. Work Logs
3. Production Steps
4. Lab Order status integration
5. Audit integration
6. Attachment reuse
7. QC handoff preparation
```

Sprint 4 tidak mencakup:

```text
1. QC result workflow
2. Delivery workflow
3. Invoice workflow
4. Payment workflow
```

## 15.3 New Tables

```text
trx_lab_order_assignments   Tracks assignment of Lab Orders to technicians.
trx_lab_work_logs           Tracks technician work events for each assignment.
trx_lab_production_steps    Tracks production progress and milestones per Lab Order.
```

## 15.4 trx_lab_order_assignments

Purpose:

```text
Tracks assignment of Lab Orders to technicians.
```

Required fields:

```text
id
lab_order_id
technician_id
assigned_by
assigned_at
status
notes
created_at
updated_at
deleted_at
```

Status enum:

```text
ASSIGNED
IN_PROGRESS
DONE
CANCELLED
REASSIGNED
```

Foreign keys:

```text
lab_order_id  -> trx_lab_orders.id
technician_id -> mst_technicians.id
assigned_by   -> users.id
```

Recommended indexes:

```text
lab_order_id
technician_id
assigned_by
status
assigned_at
```

Business rules:

```text
1. One Lab Order can have many assignment records over time.
2. Only one active assignment should exist per Lab Order at any time.
3. Reassignment creates a new assignment record.
4. Historical assignments must be preserved.
5. Cancelled Lab Orders cannot be assigned.
6. Lab Order status changes to ASSIGNED when assignment is created.
```

## 15.5 trx_lab_work_logs

Purpose:

```text
Tracks technician work events for each assignment.
```

Required fields:

```text
id
assignment_id
event_type
started_at
ended_at
duration_minutes
notes
performed_by
created_at
```

Event enum:

```text
WORK_STARTED
WORK_PAUSED
WORK_RESUMED
WORK_COMPLETED
STATUS_CHANGED
```

Foreign keys:

```text
assignment_id -> trx_lab_order_assignments.id
performed_by  -> users.id
```

Recommended indexes:

```text
assignment_id
event_type
performed_by
created_at
```

Business rules:

```text
1. Work logs are immutable historical records.
2. One assignment can have many work logs.
3. Start, pause, resume, and complete actions must create work logs.
4. Work logs must be tied to an assignment.
5. Work logs must capture who performed the action.
6. Completion work log prepares order for QC handoff.
```

## 15.6 trx_lab_production_steps

Purpose:

```text
Tracks production progress and milestones per Lab Order.
```

Required fields:

```text
id
lab_order_id
step_name
status
started_at
completed_at
notes
created_at
updated_at
```

Recommended production step names:

```text
MODEL_PREPARATION
WAX_DESIGN
MILLING
PRINTING
FINISHING
POLISHING
PACKAGING
```

Step status enum:

```text
PENDING
IN_PROGRESS
COMPLETED
SKIPPED
ON_HOLD
```

Foreign keys:

```text
lab_order_id -> trx_lab_orders.id
```

Recommended indexes:

```text
lab_order_id
step_name
status
```

Business rules:

```text
1. One Lab Order can have many production steps.
2. Production steps support future reporting.
3. Production steps prepare the Lab Order for QC handoff.
4. Production steps do not store QC result.
5. QC result belongs to Sprint 5, not Sprint 4.
```

## 15.7 Relationships

```text
trx_lab_orders
  1 -> many trx_lab_order_assignments
  1 -> many trx_lab_production_steps

mst_technicians
  1 -> many trx_lab_order_assignments

users
  1 -> many trx_lab_order_assignments (assigned_by)
  1 -> many trx_lab_work_logs (performed_by)

trx_lab_order_assignments
  1 -> many trx_lab_work_logs
```

## 15.8 Lab Order Status Integration

Existing Sprint 3 status:

```text
RECEIVED
CANCELLED
```

Sprint 4 activates:

```text
ASSIGNED
IN_PRODUCTION
ON_HOLD
QC_PENDING
```

Sprint 5 statuses remain reserved:

```text
QC_PASSED
REMAKE
```

Rules:

```text
1. Creating assignment changes Lab Order status to ASSIGNED.
2. Starting work changes Lab Order status to IN_PRODUCTION.
3. Putting work on hold changes Lab Order status to ON_HOLD.
4. Completing production changes Lab Order status to QC_PENDING.
5. All Lab Order status changes must create trx_lab_order_status_logs.
6. All production actions must create sys_audit_logs.
7. Sprint 4 does not define QC result workflow.
```

## 15.9 Audit Integration

Sprint 4 production actions must be audited using:

```text
sys_audit_logs
entity_type
entity_id
```

Required audit actions:

```text
ASSIGN_TECHNICIAN
REASSIGN_TECHNICIAN
START_WORK
PAUSE_WORK
RESUME_WORK
COMPLETE_WORK
SEND_TO_QC
STATUS_CHANGE
```

Rules:

```text
1. Every production action must create sys_audit_logs.
2. Audit logs use polymorphic entity_type and entity_id.
3. Assignment and reassignment audit values must identify old and new technician context.
4. Work lifecycle audit values must identify assignment, event, actor, and timestamp.
```

## 15.10 Attachment Integration

Production attachments reuse:

```text
sys_attachments
entity_type
entity_id
```

Production attachment categories:

```text
WORK_PHOTO
DESIGN_FILE
STL_REVISION
REFERENCE_IMAGE
PRODUCTION_NOTE
```

Rules:

```text
1. Do not create a new attachment table for production.
2. Production attachments use entity_type = trx_lab_orders.
3. File path only is stored in database.
4. Upload and delete actions must be audited.
```

## 15.11 Business Rules

```text
1. Cancelled Lab Orders cannot be assigned.
2. One Lab Order can have many assignment records over time.
3. Only one active assignment should exist per Lab Order at any time.
4. Reassignment creates a new assignment record.
5. Historical assignments must be preserved.
6. Work logs are immutable historical records.
7. Start, pause, resume, and complete actions must create work logs.
8. Production steps do not store QC result.
9. QC handoff ends at QC_PENDING.
10. Delivery, Invoice, and Payment workflows are not part of Sprint 4.
```

## 15.12 Validation Checklist - Sprint 4 Schema

```text
✓ Assignment table documented
✓ Work log table documented
✓ Production step table documented
✓ Foreign keys documented
✓ Indexes documented
✓ Lab Order status integration documented
✓ Audit integration documented
✓ Attachment reuse documented
✓ QC workflow excluded
✓ Delivery workflow excluded
✓ Invoice workflow excluded
✓ Payment workflow excluded
```

---

# 16. Sprint 5 Quality Control Schema

Bagian ini memperluas database schema untuk **Sprint 5 - Quality Control
Workflow** berdasarkan `qc_workflow_design.md` dan ERD Sprint 5.

## 16.1 Purpose

```text
Mendukung QC review, QC checklist, QC result, QC evidence, remake request,
dan QC history dari Lab Order status QC_PENDING sampai QC_PASSED atau REMAKE.
```

## 16.2 Scope

Sprint 5 mencakup:

```text
1. QC Review
2. QC Checklist
3. QC Result
4. QC Evidence
5. Remake Request
6. QC History
7. Lab Order status integration
8. Audit integration
9. Attachment reuse
```

Sprint 5 tidak mencakup:

```text
1. Delivery workflow
2. Invoice workflow
3. Payment workflow
4. Reporting implementation
```

## 16.3 Tables

```text
trx_lab_quality_controls Stores QC review sessions.
trx_lab_qc_checklists    Stores QC checklist item results.
trx_lab_remake_requests  Stores remake requests generated from QC review.
```

QC evidence uses:

```text
sys_attachments
```

Do not create:

```text
trx_lab_qc_photos
```

## 16.4 QC Review Design - trx_lab_quality_controls

Purpose:

```text
Stores QC review sessions.
```

Required fields:

```text
id
lab_order_id
inspected_by
result
notes
started_at
completed_at
created_at
updated_at
deleted_at
```

QC Result enum:

```text
PASSED
REJECTED
REVISION
```

Foreign keys:

```text
lab_order_id -> trx_lab_orders.id
inspected_by -> users.id
```

Recommended indexes:

```text
lab_order_id
inspected_by
result
completed_at
```

Business rules:

```text
1. One Lab Order can have many QC reviews.
2. QC review can only start when Lab Order status = QC_PENDING.
3. QC review must capture inspector.
4. QC review must capture result.
5. QC review history must be preserved.
6. Soft delete enabled.
```

## 16.5 Checklist Design - trx_lab_qc_checklists

Purpose:

```text
Stores QC checklist results.
```

Required fields:

```text
id
quality_control_id
checklist_item
result
notes
created_at
updated_at
```

Checklist result enum:

```text
PASS
FAIL
N/A
```

Recommended checklist items:

```text
FIT_ACCURACY
MARGIN_QUALITY
OCCLUSION
SHADE_MATCH
CONTACT_POINT
SURFACE_FINISH
POLISHING
CLEANLINESS
PACKAGING_READINESS
```

Foreign keys:

```text
quality_control_id -> trx_lab_quality_controls.id
```

Recommended indexes:

```text
quality_control_id
checklist_item
result
```

Business rules:

```text
1. One QC review has many checklist records.
2. At least one checklist item must exist before QC completion.
3. Checklist history must be preserved.
```

## 16.6 Remake Design - trx_lab_remake_requests

Purpose:

```text
Stores remake requests generated from QC review.
```

Required fields:

```text
id
lab_order_id
quality_control_id
requested_by
reason
notes
status
requested_at
created_at
updated_at
deleted_at
```

Remake status enum:

```text
OPEN
IN_PROGRESS
COMPLETED
CANCELLED
```

Foreign keys:

```text
lab_order_id       -> trx_lab_orders.id
quality_control_id -> trx_lab_quality_controls.id
requested_by       -> users.id
```

Recommended indexes:

```text
lab_order_id
quality_control_id
requested_by
status
requested_at
```

Business rules:

```text
1. Remake requires reason.
2. Remake preserves QC history.
3. Multiple remake requests may exist for a Lab Order.
4. Remake history must be preserved.
5. Soft delete enabled.
```

## 16.7 Lab Order Status Integration

QC Results:

```text
PASSED
REJECTED
REVISION
```

Lab Order Statuses:

```text
QC_PENDING
QC_PASSED
REMAKE
```

Mapping:

```text
PASSED   -> QC_PASSED
REJECTED -> REMAKE
REVISION -> REMAKE
```

Important:

```text
QC Result and Lab Order Status are separate concepts.
REVISION is a QC result, not a Lab Order status.
REMAKE is the Lab Order status for REJECTED and REVISION QC results.
```

Status log rules:

```text
1. QC_PENDING -> QC_PASSED must create trx_lab_order_status_logs.
2. QC_PENDING -> REMAKE must create trx_lab_order_status_logs.
3. REMAKE -> ASSIGNED reuses Sprint 4 assignment workflow.
4. IN_PRODUCTION -> QC_PENDING reuses Sprint 4 send-to-QC workflow.
```

## 16.8 Remake Workflow Integration

Remake workflow:

```text
QC_PENDING
-> REMAKE
-> ASSIGNED
-> IN_PRODUCTION
-> QC_PENDING
```

Rules:

```text
1. Remake reuses Sprint 4 production workflow.
2. Remake does not create a new Lab Order.
3. Remake preserves original production history.
4. Remake preserves QC history.
5. Remake creates trx_lab_remake_requests.
```

## 16.9 Audit Integration

Sprint 5 QC actions use:

```text
sys_audit_logs
entity_type
entity_id
```

Required audit actions:

```text
START_QC
PASS_QC
REJECT_QC
REQUEST_REMAKE
UPDATE_QC_CHECKLIST
UPLOAD_QC_EVIDENCE
STATUS_CHANGE
```

Columns:

```text
entity_type
entity_id
action
old_values
new_values
performed_by
performed_at
```

Rules:

```text
1. Every QC action must create sys_audit_logs.
2. Every QC-related Lab Order status change must create STATUS_CHANGE audit.
3. Audit records must not store raw file content.
4. Audit records must identify Lab Order, QC review, actor, timestamp, and result.
```

## 16.10 Attachment Integration

QC evidence reuses:

```text
sys_attachments
entity_type
entity_id
```

Attachment categories:

```text
QC_PHOTO
QC_NOTE
QC_EVIDENCE
QC_REJECTION_EVIDENCE
```

Preferred QC evidence target:

```text
entity_type = trx_lab_quality_controls
entity_id   = trx_lab_quality_controls.id
```

Order-level evidence target:

```text
entity_type = trx_lab_orders
entity_id   = trx_lab_orders.id
```

Rules:

```text
1. Do not create a separate QC photo table.
2. Do not create trx_lab_qc_photos.
3. File path only is stored in database.
4. Upload and delete actions must be audited.
5. Attachment metadata uses soft delete.
```

## 16.11 Foreign Key Recommendation

```text
trx_lab_quality_controls
  lab_order_id -> trx_lab_orders.id
  inspected_by -> users.id

trx_lab_qc_checklists
  quality_control_id -> trx_lab_quality_controls.id

trx_lab_remake_requests
  lab_order_id       -> trx_lab_orders.id
  quality_control_id -> trx_lab_quality_controls.id
  requested_by       -> users.id
```

## 16.12 Index Recommendation

```text
trx_lab_quality_controls
  lab_order_id
  inspected_by
  result
  completed_at

trx_lab_qc_checklists
  quality_control_id
  checklist_item
  result

trx_lab_remake_requests
  lab_order_id
  quality_control_id
  requested_by
  status
  requested_at
```

## 16.13 Business Rules

```text
1. Only QC_PENDING orders can be reviewed.
2. Cancelled orders cannot be reviewed.
3. QC review must capture inspector.
4. QC review must capture result.
5. QC must have at least one checklist result before completion.
6. QC pass changes Lab Order status to QC_PASSED.
7. QC reject or revision changes Lab Order status to REMAKE.
8. Remake requires reason.
9. Remake does not create a new Lab Order.
10. Remake preserves original production history.
11. Remake preserves QC history.
12. Every QC action must be audited.
13. Every status change must be logged.
14. Delivery cannot start before QC_PASSED.
15. Delivery, Invoice, Payment, and Reporting are not part of Sprint 5.
```

## 16.14 Validation Checklist - Sprint 5 Schema

```text
- QC review table documented
- QC checklist table documented
- Remake request table documented
- Foreign keys documented
- Indexes documented
- QC result documented
- Lab Order status mapping documented
- Remake workflow documented
- Audit integration documented
- Attachment reuse documented
- QC photo table excluded
- Delivery excluded
- Invoice excluded
- Payment excluded
- Reporting excluded
```

---

# 17. Sprint 6 Delivery & POD Schema

## 17.1 Purpose

Sprint 6 memperluas database schema untuk mendukung Delivery dan Proof of
Delivery setelah Lab Order lulus Quality Control.

Tujuan schema:

```text
1. Menyimpan delivery record per Lab Order.
2. Menyimpan courier assignment.
3. Melacak status delivery.
4. Menyimpan data receiver.
5. Menyimpan file path POD.
6. Menghubungkan delivery evidence ke sys_attachments.
7. Menyediakan delivery history yang dapat diaudit.
```

## 17.2 Scope

Sprint 6 mencakup:

```text
Delivery Queue
Courier Assignment
Delivery Tracking
Proof of Delivery
Receiver Name
Receiver Signature
Receiver Photo
Delivery Evidence
Delivery History
```

Sprint 6 tidak mencakup:

```text
Invoice workflow
Payment workflow
Reporting
Separate courier master table
Separate delivery photo table
```

## 17.3 Delivery Table Design - trx_lab_deliveries

Purpose:

```text
Stores delivery records, courier assignment, delivery status, and POD
information for Lab Orders.
```

Required fields:

```text
id
lab_order_id
delivery_number
courier_id
status
delivery_notes
receiver_name
receiver_signature_path
receiver_photo_path
received_at
started_at
completed_at
created_by
created_at
updated_at
deleted_at
```

Column rules:

| Column                  | Rule                                  |
| ----------------------- | ------------------------------------- |
| `id`                    | Primary key                           |
| `lab_order_id`          | FK to `trx_lab_orders.id`, required   |
| `delivery_number`       | Unique, auto-generated                |
| `courier_id`            | FK to `users.id`, required before `IN_DELIVERY` |
| `status`                | Delivery status enum                  |
| `delivery_notes`        | Nullable text                         |
| `receiver_name`         | Required before `COMPLETED`           |
| `receiver_signature_path` | File path only, no binary/blob/base64 |
| `receiver_photo_path`   | File path only, no binary/blob/base64 |
| `received_at`           | Required before `COMPLETED`           |
| `started_at`            | Set when delivery starts              |
| `completed_at`          | Set when delivery workflow completes  |
| `created_by`            | FK to `users.id`                      |
| `deleted_at`            | Soft delete timestamp                 |

Delivery status enum:

```text
READY_FOR_DELIVERY
IN_DELIVERY
DELIVERED
COMPLETED
CANCELLED
```

## 17.4 Lab Order Status Integration

Previous status from Sprint 5:

```text
QC_PASSED
```

Sprint 6 active Lab Order statuses:

```text
READY_FOR_DELIVERY
IN_DELIVERY
DELIVERED
COMPLETED
```

Allowed transitions:

```text
QC_PASSED -> READY_FOR_DELIVERY
READY_FOR_DELIVERY -> IN_DELIVERY
IN_DELIVERY -> DELIVERED
DELIVERED -> COMPLETED
```

Rules:

```text
1. Every transition must create trx_lab_order_status_logs.
2. Every delivery action must create sys_audit_logs.
3. Invoice is not created by Sprint 6.
4. Payment is not created by Sprint 6.
5. Reporting is not implemented by Sprint 6.
```

## 17.5 POD Strategy

POD fields:

```text
receiver_name
receiver_signature_path
receiver_photo_path
received_at
delivery_notes
```

Rules:

```text
1. Store signature as file path, not binary/blob/base64.
2. Store receiver photo as file path, not binary/blob/base64.
3. POD evidence must use sys_attachments.
4. POD must be completed before Lab Order becomes COMPLETED.
5. Receiver name is required before COMPLETED.
6. Receiver signature is required before COMPLETED.
7. Receiver photo is required before COMPLETED.
```

## 17.6 Attachment Integration

Delivery evidence reuses:

```text
sys_attachments
```

Delivery attachment categories:

```text
DELIVERY_PHOTO
POD_SIGNATURE
POD_RECEIVER_PHOTO
DELIVERY_EVIDENCE
```

Preferred polymorphic target:

```text
entity_type = trx_lab_deliveries
entity_id   = delivery_id
```

Rules:

```text
1. Do not create a separate delivery photo table.
2. Store file metadata in sys_attachments.
3. Store file content in storage, not database binary columns.
4. Upload, replace, and delete actions must be audited.
5. Soft delete applies to attachment metadata.
```

## 17.7 Audit Log Integration

Sprint 6 uses existing:

```text
sys_audit_logs
```

Required audit actions:

```text
CREATE_DELIVERY
ASSIGN_COURIER
REASSIGN_COURIER
START_DELIVERY
MARK_DELIVERED
COMPLETE_DELIVERY
UPLOAD_POD
STATUS_CHANGE
```

Required audit columns:

```text
entity_type
entity_id
action
old_values
new_values
performed_by
performed_at
```

Recommended audit target:

```text
entity_type = trx_lab_deliveries
entity_id   = delivery_id
```

Lab Order status changes may also audit:

```text
entity_type = trx_lab_orders
entity_id   = lab_order_id
```

## 17.8 Foreign Key Recommendation

```text
trx_lab_deliveries
  lab_order_id -> trx_lab_orders.id
  courier_id   -> users.id
  created_by   -> users.id
```

No separate courier master table is required for Sprint 6 V1.

## 17.9 Index Recommendation

```text
trx_lab_deliveries
  delivery_number UNIQUE
  lab_order_id
  courier_id
  status
  received_at
  created_by
```

## 17.10 Suggested Migration Order Update

Recommended order around Sprint 6:

```text
trx_lab_quality_controls
trx_lab_qc_checklists
trx_lab_remake_requests
trx_lab_deliveries
trx_invoices
trx_invoice_items
trx_payments
```

`trx_lab_deliveries` appears after Quality Control tables and before
Invoice/Payment tables.

## 17.11 Business Rules

```text
1. One Lab Order can have many delivery records over time.
2. Only QC_PASSED orders can enter delivery.
3. Creating a delivery changes Lab Order status to READY_FOR_DELIVERY.
4. Starting delivery changes Lab Order status to IN_DELIVERY.
5. Marking delivered changes Lab Order status to DELIVERED.
6. Completing delivery changes Lab Order status to COMPLETED.
7. Courier is required before IN_DELIVERY.
8. POD is required before COMPLETED.
9. Receiver name is required before COMPLETED.
10. Receiver signature is required before COMPLETED.
11. Receiver photo is required before COMPLETED.
12. Delivery history must be preserved.
13. Soft delete enabled.
14. No hard delete.
15. Delivery evidence must reuse sys_attachments.
16. Every delivery action must create sys_audit_logs.
17. Every delivery status change must create trx_lab_order_status_logs.
18. trx_lab_delivery_photos is excluded from Sprint 6 V1.
19. Separate courier master table is excluded from Sprint 6 V1.
20. Invoice workflow is excluded from Sprint 6.
21. Payment workflow is excluded from Sprint 6.
22. Reporting is excluded from Sprint 6.
```

## 17.12 Validation Checklist - Sprint 6 Schema

```text
- Delivery table documented
- Delivery status enum documented
- Foreign keys documented
- Indexes documented
- Lab Order status mapping documented
- POD fields documented
- POD uses sys_attachments
- Signature stored as file path
- Receiver photo stored as file path
- Delivery photo table excluded
- Courier master table excluded
- Invoice excluded
- Payment excluded
- Reporting excluded
```

---

# 18. Sprint 7 Invoice & Payment Schema

## Sprint 7 — Invoice & Payment Schema

Sprint 7 menambahkan schema Invoice dan Payment setelah Lab Order selesai.
Schema ini mengikuti `docs/invoice_payment_workflow_design.md` dan `docs/erd.md`.

Business model:

```text
COMPLETED Lab Orders
-> trx_invoice_items
-> trx_invoices
-> trx_payments
```

Rules utama:

```text
1. One invoice belongs to one Clinic.
2. One invoice can contain multiple completed Lab Orders from the same Clinic.
3. One invoice has many invoice items.
4. One invoice item references one Lab Order.
5. One invoice has many payments.
6. Payment is recorded against invoice.
7. outstanding_amount = total_amount - paid_amount.
8. Lab Order must be COMPLETED before it can be included in invoice.
9. One Lab Order should not be invoiced more than once unless the previous invoice is VOID.
10. Invoice cannot be voided if payment already exists.
11. Payment cannot be recorded for VOID invoice.
12. Payment amount must not exceed outstanding amount.
```

## 18.1 Table: trx_invoices

Purpose:

```text
Stores invoice header data for one Clinic and supports grouping multiple
completed Lab Orders into one invoice.
```

Fields:

```text
id                 BIGSERIAL PRIMARY KEY
invoice_number     VARCHAR(50) UNIQUE NOT NULL
clinic_id          BIGINT NOT NULL REFERENCES mst_clinics(id)
invoice_date       DATE NOT NULL
due_date           DATE NULL
status             VARCHAR(30) NOT NULL DEFAULT 'DRAFT'
subtotal           NUMERIC(15,2) NOT NULL DEFAULT 0
discount_amount    NUMERIC(15,2) NOT NULL DEFAULT 0
tax_amount         NUMERIC(15,2) NOT NULL DEFAULT 0
total_amount       NUMERIC(15,2) NOT NULL DEFAULT 0
paid_amount        NUMERIC(15,2) NOT NULL DEFAULT 0
outstanding_amount NUMERIC(15,2) NOT NULL DEFAULT 0
notes              TEXT NULL
created_by         BIGINT NOT NULL REFERENCES users(id)
issued_at          TIMESTAMP NULL
voided_at          TIMESTAMP NULL
created_at         TIMESTAMP NOT NULL
updated_at         TIMESTAMP NOT NULL
```

Note:

```text
The prompt may refer to clinics(id). In this project schema, the Clinic master
table is documented as mst_clinics.
```

Indexes:

```text
UNIQUE(invoice_number)
INDEX(clinic_id)
INDEX(status)
INDEX(invoice_date)
INDEX(due_date)
INDEX(created_by)
```

Constraints / notes:

```text
status values: DRAFT, ISSUED, PARTIALLY_PAID, PAID, OVERDUE, VOID
total_amount must be >= 0
paid_amount must be >= 0
outstanding_amount must be >= 0
paid_amount must not exceed total_amount
outstanding_amount should equal total_amount - paid_amount
VOID invoice should not receive payment
PAID invoice should have outstanding_amount = 0
trx_invoices does not store lab_order_id in Sprint 7
```

## 18.2 Table: trx_invoice_items

Purpose:

```text
Stores invoice detail lines and links invoices to completed Lab Orders.
This table enables one invoice to contain multiple Lab Orders from the same
Clinic.
```

Fields:

```text
id              BIGSERIAL PRIMARY KEY
invoice_id      BIGINT NOT NULL REFERENCES trx_invoices(id) ON DELETE CASCADE
lab_order_id    BIGINT NOT NULL REFERENCES trx_lab_orders(id)
description     VARCHAR(255) NOT NULL
quantity        INTEGER NOT NULL DEFAULT 1
unit_price      NUMERIC(15,2) NOT NULL DEFAULT 0
discount_amount NUMERIC(15,2) NOT NULL DEFAULT 0
total_price     NUMERIC(15,2) NOT NULL DEFAULT 0
created_at      TIMESTAMP NOT NULL
updated_at      TIMESTAMP NOT NULL
```

Indexes:

```text
INDEX(invoice_id)
INDEX(lab_order_id)
INDEX(invoice_id, lab_order_id)
UNIQUE(lab_order_id) if strict one active invoice per Lab Order is enforced at application level
```

Constraints / notes:

```text
quantity must be greater than 0
unit_price must be >= 0
discount_amount must be >= 0
total_price must be >= 0
total_price should equal quantity * unit_price - discount_amount
lab_order_id must reference a COMPLETED Lab Order
all Lab Orders in one invoice must belong to the same Clinic
one Lab Order should not be included in multiple non-VOID invoices
if PostgreSQL partial unique index is later used, enforce uniqueness only for
non-VOID invoices through application/migration design carefully
```

## 18.3 Table: trx_payments

Purpose:

```text
Stores payment records against invoices.
```

Fields:

```text
id               BIGSERIAL PRIMARY KEY
payment_number   VARCHAR(50) UNIQUE NOT NULL
invoice_id       BIGINT NOT NULL REFERENCES trx_invoices(id)
payment_date     DATE NOT NULL
payment_method   VARCHAR(30) NOT NULL
amount           NUMERIC(15,2) NOT NULL
reference_number VARCHAR(100) NULL
notes            TEXT NULL
received_by      BIGINT NOT NULL REFERENCES users(id)
created_by       BIGINT NOT NULL REFERENCES users(id)
created_at       TIMESTAMP NOT NULL
updated_at       TIMESTAMP NOT NULL
```

Indexes:

```text
UNIQUE(payment_number)
INDEX(invoice_id)
INDEX(payment_date)
INDEX(payment_method)
INDEX(received_by)
INDEX(created_by)
```

Constraints / notes:

```text
payment_method values: CASH, BANK_TRANSFER, QRIS, CARD, OTHER
amount must be greater than 0
payment amount must not exceed invoice outstanding amount
payment cannot be recorded for VOID invoice
payment updates invoice.paid_amount and invoice.outstanding_amount
partial payment changes invoice status to PARTIALLY_PAID
full payment changes invoice status to PAID
```

## 18.4 Relationship Summary

```text
clinics.id
-> trx_invoices.clinic_id

mst_clinics.id
-> trx_invoices.clinic_id

users.id
-> trx_invoices.created_by

trx_invoices.id
-> trx_invoice_items.invoice_id

trx_lab_orders.id
-> trx_invoice_items.lab_order_id

trx_invoices.id
-> trx_payments.invoice_id

users.id
-> trx_payments.received_by

users.id
-> trx_payments.created_by
```

## 18.5 Audit and Attachment Notes

Reusable systems:

```text
sys_audit_logs
sys_attachments
```

Audit events:

```text
Invoice created, issued, voided, marked overdue, and marked paid events must be
logged in sys_audit_logs.

Payment created events must be logged in sys_audit_logs.
```

Attachment strategy:

```text
Future invoice PDF, payment proof, or supporting files can use sys_attachments
with entity_type/entity_id.

No dedicated invoice/payment attachment tables are needed in Sprint 7.
```

Do not create:

```text
trx_invoice_audit_logs
trx_payment_audit_logs
invoice-specific attachment tables
payment-specific attachment tables
```

Important separation:

```text
trx_lab_order_status_logs remains only for Lab Order status tracking.
Invoice and Payment actions use sys_audit_logs.
```

## 18.6 Schema Design Notes

```text
1. Use decimal/NUMERIC for monetary values, not floating point.
2. Store calculated totals for historical consistency.
3. Recalculate invoice totals through service layer.
4. Avoid direct manual mutation of paid_amount and outstanding_amount outside PaymentService.
5. Keep reporting aggregation out of Sprint 7.
6. Store invoice header data in trx_invoices.
7. Store Lab Order billing links in trx_invoice_items.
8. Store payment receipts in trx_payments.
```

## 18.7 Out Of Scope - Sprint 7 Schema

Do not add:

```text
accounting ledger tables
journal entry tables
tax reporting tables
external payment gateway tables
refund tables
payment allocation tables
reporting summary tables
invoice email history tables
WhatsApp notification tables
```

## 18.8 Validation Checklist - Sprint 7 Schema

```text
- Sprint 7 Invoice & Payment Schema section added
- trx_invoices documented
- trx_invoice_items documented
- trx_payments documented
- One invoice can contain multiple completed Lab Orders from the same Clinic
- Invoice belongs to one Clinic
- Invoice item references one Lab Order
- Payment is recorded against invoice
- outstanding_amount = total_amount - paid_amount
- sys_audit_logs reused for invoice and payment audit events
- sys_attachments reused for invoice PDF and payment proof
- trx_lab_order_status_logs remains only for Lab Order status tracking
- Dedicated invoice/payment audit log tables excluded
- Dedicated invoice/payment attachment tables excluded
- Accounting ledger excluded
- Payment allocation tables excluded
- Reporting aggregation excluded
```

---

# 19. Sprint 8 Reporting & Dashboard Schema

## Sprint 8 — Reporting & Dashboard Schema

Sprint 8 Reporting & Dashboard adalah modul read-only yang membaca data dari
tabel Sprint 0 sampai Sprint 7. Sprint ini tidak menambah tabel fisik,
migration, trigger, materialized view, atau tabel aggregate/cache.

Core decisions:

```text
1. No new physical database tables are required for Sprint 8.
2. No new migrations are required for Sprint 8.
3. Reporting reads existing Sprint 0-7 source tables.
4. Reporting must not mutate source data.
5. Reporting must not duplicate data into summary/cache/reporting tables in Sprint 8.
```

Reporting schema bersifat logical. Implementasi report harus memakai filtered
SELECT queries melalui service/repository layer dan tidak boleh melakukan write
operation ke source tables.

## 19.1 Source Table Groups

Operational source tables:

```text
users
mst_clinics        (clinics)
mst_doctors        (doctors)
mst_patients       (patients)
mst_technicians    (technicians)
mst_lab_services   (lab_services)
trx_lab_orders
trx_lab_order_items
trx_lab_order_status_logs
```

Production source tables:

```text
trx_lab_order_assignments
trx_lab_work_logs
trx_lab_production_steps
```

Quality Control source tables:

```text
trx_lab_quality_controls
trx_lab_qc_checklists
trx_lab_remake_requests
sys_attachments for QC evidence counts if needed
```

Delivery source tables:

```text
trx_lab_deliveries
sys_attachments for delivery/POD evidence counts if needed
```

Financial source tables:

```text
trx_invoices
trx_invoice_items
trx_payments
```

Audit / status source tables:

```text
sys_audit_logs
trx_lab_order_status_logs
```

## 19.2 Report To Schema Mapping

Dashboard Overview:

```text
trx_lab_orders
trx_lab_deliveries
trx_invoices
trx_payments
trx_lab_quality_controls
trx_lab_remake_requests
```

Order Reports:

```text
trx_lab_orders
trx_lab_order_items
mst_clinics
mst_doctors
mst_patients
mst_lab_services
trx_lab_order_status_logs
```

Production Reports:

```text
trx_lab_order_assignments
trx_lab_work_logs
trx_lab_production_steps
users
mst_technicians
trx_lab_orders
```

QC Reports:

```text
trx_lab_quality_controls
trx_lab_qc_checklists
trx_lab_remake_requests
sys_attachments if evidence counts are needed
```

Delivery Reports:

```text
trx_lab_deliveries
users as courier
mst_clinics
trx_lab_orders
sys_attachments if POD/evidence counts are needed
```

Invoice Reports:

```text
trx_invoices
trx_invoice_items
mst_clinics
trx_lab_orders
```

Payment Reports:

```text
trx_payments
trx_invoices
mst_clinics
users as received_by
```

Outstanding Invoice Reports:

```text
trx_invoices
trx_payments
mst_clinics
```

Revenue Reports:

```text
trx_invoices
trx_invoice_items
trx_payments
mst_clinics
mst_lab_services if revenue by service is required
```

## 19.3 Schema Decision Notes

```text
1. Use filtered SELECT queries against existing tables.
2. Use service/repository layer for report calculations.
3. Avoid direct write operations from Reporting module.
4. Avoid calculated reporting tables in Sprint 8.
5. Avoid materialized views in Sprint 8.
6. Avoid database triggers for reporting in Sprint 8.
7. Export should be generated from filtered queries.
8. CSV export is acceptable as baseline.
9. Excel export may be implemented only if a package already exists or simple implementation is available.
10. PDF export is out of scope unless explicitly added later.
```

Reporting must not create:

```text
reporting_summary
report_snapshots
report_cache
report_exports
reporting_audit_logs
reporting_attachments
```

## 19.4 Data Accuracy Notes

```text
Payment totals must come from trx_payments.
Outstanding amount must come from trx_invoices.outstanding_amount.
Invoice revenue must come from trx_invoices.
Payment received revenue must come from trx_payments.
VOID invoices should be excluded from revenue calculations unless the report explicitly includes VOID invoices.
Delivery reports must use trx_lab_deliveries.
QC reports must use QC source tables.
Lab Order status history must use trx_lab_order_status_logs.
Date filters must be inclusive and timezone-safe.
Reports must handle empty datasets safely.
Reports must respect role and permission access.
```

Date filtering recommendation:

```text
date_from should include start of day in application timezone.
date_to should include end of day in application timezone.
```

Void and deleted record handling:

```text
1. VOID invoices are excluded from revenue and outstanding reports by default.
2. VOID invoices may appear in invoice status reports when explicitly filtered.
3. Soft-deleted records should follow the source module's default query behavior.
```

## 19.5 Index Review Notes

Sprint 8 should not add indexes immediately unless performance issues are
proven. Implementation should review whether existing indexes support these
fields:

```text
trx_lab_orders.status
trx_lab_orders.clinic_id
trx_lab_orders.doctor_id
trx_lab_orders.created_at
trx_lab_deliveries.status
trx_lab_deliveries.courier_id
trx_lab_deliveries.created_at
trx_invoices.status
trx_invoices.clinic_id
trx_invoices.invoice_date
trx_invoices.due_date
trx_payments.payment_date
trx_payments.payment_method
trx_payments.received_by
```

Index rules:

```text
1. If indexes already exist, do not duplicate them.
2. If indexes are missing, document them as future optimization only.
3. Missing indexes are not mandatory Sprint 8 migrations.
4. Do not create reporting-specific indexes without measured performance need.
```

## 19.6 Export Schema Notes

```text
Export does not require new tables.
Export should use current filtered query result.
Export should respect user permissions.
Export should not persist generated files in Sprint 8.
If persisted export files are needed later, use sys_attachments or a future export history table in a later sprint.
```

Baseline export:

```text
CSV export
```

Optional export:

```text
Excel export if a package already exists or simple implementation is available.
```

Out of scope export:

```text
PDF export
Persisted export file history
Scheduled export jobs
Email/WhatsApp report sending
```

## 19.7 Permission Notes

Planned permissions:

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

Role-based access:

```text
Admin: all reporting permissions
Finance: dashboard, invoice report, payment report, outstanding report, revenue report, export
Lab Manager: dashboard, order report, production report, QC report, delivery report, export
Lab Staff: limited operational reports
Courier: no reporting access by default
```

Permission design notes:

```text
1. manage_report can act as an admin override.
2. export_report should be separate from view permissions.
3. Financial report permissions should remain separate from operational report permissions.
4. Courier should not receive reporting permissions by default.
```

## 19.8 Out Of Scope - Sprint 8 Schema

Do not add tables for:

```text
reporting_summary
report_snapshots
report_cache
report_exports
data warehouse
BI star schema
fact tables
dimension tables
materialized views
scheduled report jobs
report email history
report WhatsApp logs
PDF report templates
accounting ledger
tax reporting
inventory reports
HR reports
RME reports
```

Also out of scope:

```text
new migrations for reporting
database triggers for reporting
reporting-specific audit tables
reporting-specific attachment tables
source data mutation from reports
```

## 19.9 Validation Checklist - Sprint 8 Schema

```text
- Sprint 8 Reporting & Dashboard Schema section added
- No new physical tables required
- No new migrations required
- Reporting documented as read-only
- Existing Sprint 0-7 source tables documented
- Report-to-source table mapping documented
- Data accuracy notes documented
- Export schema notes documented
- Permissions documented
- Index review documented as future optimization only
- Reporting summary/cache tables excluded
- Materialized views excluded
- Source data mutation excluded
```

---

# 20. Sprint 16.8 — Inventory Analytics Reporting Summaries

Read-only derived cache tables (`rpt_*`). **Not** source of truth for stock; ledger authority remains `trx_inventory_movements`.

## 20.1 Tables

| Table | Granularity | Purpose |
| ----- | ----------- | ------- |
| `rpt_inventory_daily_summaries` | `branch_id` × `summary_date` | Daily movement in/out, inbound/outbound value, PURCHASE subset |
| `rpt_inventory_branch_summaries` | `branch_id` × `snapshot_date` | Branch KPI snapshot (value, SKU counts, low/dead stock, procurement open counts) |
| `rpt_inventory_product_summaries` | `branch_id` × `product_id` × `snapshot_date` | Per-product derived stock cache, movement windows, aging, reorder flags |
| `rpt_procurement_daily_summaries` | `branch_id` × `summary_date` [× `supplier_id`] | Daily PO/GR aggregates; `supplier_id` NULL = branch rollup |

## 20.2 Constraints

- All tables: `branch_id` → `mst_branches` (`restrictOnDelete`).
- Product summary: `product_id` → `inv_products`, optional `product_category_id`, `preferred_supplier_id`.
- Procurement: optional `supplier_id` → `inv_suppliers`; partial unique indexes for branch rollup vs supplier slice.
- No mutable stock columns added to `inv_products` or `inv_inventory_locations`.

## 20.3 Analytics composite indexes (Sprint 16.8.2)

Added on source tables: `trx_inventory_movements` (`branch_id` + `movement_date` / `movement_type` / `product_id`), `trx_purchase_orders` (`branch_id`, `supplier_id`, `status`), `trx_goods_receipts` (`branch_id`, `status`, `posted_at`), `inv_products` (`branch_id`, `is_active`, `alert_enabled`).

Authority: `docs/sprint_16_8_analytics_optimization_design.md`.
