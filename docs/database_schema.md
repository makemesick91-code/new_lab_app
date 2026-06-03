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
trx_lab_deliveries
trx_lab_delivery_photos
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

| Column       | Type        | Rule                 |
| ------------ | ----------- | -------------------- |
| id           | BIGINT      | PK                   |
| lab_order_id | BIGINT      | FK trx_lab_orders.id |
| qc_by        | BIGINT      | FK users.id          |
| qc_date      | TIMESTAMP   | NOT NULL             |
| result       | VARCHAR(50) | NOT NULL             |
| notes        | TEXT        | NULL                 |
| created_at   | TIMESTAMP   |                      |
| updated_at   | TIMESTAMP   |                      |

Result:

```text
PASSED
REJECTED
REVISION
```

---

## trx_lab_deliveries

| Column              | Type         | Rule                       |
| ------------------- | ------------ | -------------------------- |
| id                  | BIGINT       | PK                         |
| delivery_number     | VARCHAR(50)  | UNIQUE                     |
| lab_order_id        | BIGINT       | FK trx_lab_orders.id       |
| courier_id          | BIGINT       | FK users.id                |
| clinic_id           | BIGINT       | FK mst_clinics.id          |
| delivery_date       | DATE         | NOT NULL                   |
| status              | VARCHAR(50)  | DEFAULT READY_FOR_DELIVERY |
| receiver_name       | VARCHAR(150) | NULL                       |
| receiver_role       | VARCHAR(100) | NULL                       |
| receiver_phone      | VARCHAR(50)  | NULL                       |
| signature_file_path | VARCHAR(255) | NULL                       |
| delivered_at        | TIMESTAMP    | NULL                       |
| notes               | TEXT         | NULL                       |
| created_by          | BIGINT       | FK users.id                |
| created_at          | TIMESTAMP    |                            |
| updated_at          | TIMESTAMP    |                            |
| deleted_at          | TIMESTAMP    | NULL                       |

Status delivery:

```text
READY_FOR_DELIVERY
IN_TRANSIT
ARRIVED
POD_COMPLETED
DELIVERED
CANCELLED
```

Business rule:

```text
Delivery tidak boleh menjadi DELIVERED jika:
1. receiver_name kosong
2. signature_file_path kosong
3. belum ada minimal 1 foto di trx_lab_delivery_photos
```

---

## trx_lab_delivery_photos

| Column      | Type         | Rule                     |
| ----------- | ------------ | ------------------------ |
| id          | BIGINT       | PK                       |
| delivery_id | BIGINT       | FK trx_lab_deliveries.id |
| photo_type  | VARCHAR(50)  | DEFAULT RECEIVED_GOODS   |
| file_path   | VARCHAR(255) | NOT NULL                 |
| uploaded_by | BIGINT       | FK users.id              |
| uploaded_at | TIMESTAMP    | NOT NULL                 |

Photo type:

```text
PACKAGE
RECEIVED_GOODS
HANDOVER
OTHER
```

---

## trx_invoices

| Column             | Type          | Rule                 |
| ------------------ | ------------- | -------------------- |
| id                 | BIGINT        | PK                   |
| invoice_number     | VARCHAR(50)   | UNIQUE               |
| lab_order_id       | BIGINT        | FK trx_lab_orders.id |
| clinic_id          | BIGINT        | FK mst_clinics.id    |
| doctor_id          | BIGINT        | FK mst_doctors.id    |
| invoice_date       | DATE          | NOT NULL             |
| due_date           | DATE          | NULL                 |
| subtotal           | DECIMAL(18,2) | DEFAULT 0            |
| discount           | DECIMAL(18,2) | DEFAULT 0            |
| tax                | DECIMAL(18,2) | DEFAULT 0            |
| grand_total        | DECIMAL(18,2) | DEFAULT 0            |
| paid_amount        | DECIMAL(18,2) | DEFAULT 0            |
| outstanding_amount | DECIMAL(18,2) | DEFAULT 0            |
| status             | VARCHAR(50)   | DEFAULT UNPAID       |
| created_by         | BIGINT        | FK users.id          |
| created_at         | TIMESTAMP     |                      |
| updated_at         | TIMESTAMP     |                      |
| deleted_at         | TIMESTAMP     | NULL                 |

Status invoice:

```text
UNPAID
PARTIAL
PAID
VOID
```

---

## trx_invoice_items

| Column            | Type          | Rule                      |
| ----------------- | ------------- | ------------------------- |
| id                | BIGINT        | PK                        |
| invoice_id        | BIGINT        | FK trx_invoices.id        |
| lab_order_item_id | BIGINT        | FK trx_lab_order_items.id |
| description       | VARCHAR(255)  | NOT NULL                  |
| qty               | DECIMAL(18,2) | DEFAULT 1                 |
| price             | DECIMAL(18,2) | DEFAULT 0                 |
| subtotal          | DECIMAL(18,2) | DEFAULT 0                 |
| created_at        | TIMESTAMP     |                           |
| updated_at        | TIMESTAMP     |                           |

---

## trx_payments

| Column           | Type          | Rule               |
| ---------------- | ------------- | ------------------ |
| id               | BIGINT        | PK                 |
| payment_number   | VARCHAR(50)   | UNIQUE             |
| invoice_id       | BIGINT        | FK trx_invoices.id |
| payment_date     | DATE          | NOT NULL           |
| payment_method   | VARCHAR(50)   | NOT NULL           |
| amount           | DECIMAL(18,2) | NOT NULL           |
| reference_number | VARCHAR(100)  | NULL               |
| notes            | TEXT          | NULL               |
| created_by       | BIGINT        | FK users.id        |
| created_at       | TIMESTAMP     |                    |
| updated_at       | TIMESTAMP     |                    |
| deleted_at       | TIMESTAMP     | NULL               |

Payment method:

```text
CASH
BANK_TRANSFER
QRIS
DEBIT_CARD
CREDIT_CARD
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
DELIVERY
POD_UPLOAD
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
  ├── trx_lab_orders
  └── trx_invoices

mst_patients
  └── trx_lab_orders

mst_lab_services
  └── trx_lab_order_items

users
  ├── mst_technicians
  ├── trx_lab_orders.created_by
  ├── trx_lab_order_status_logs.changed_by
  ├── trx_lab_order_assignments.assigned_by
  ├── trx_lab_quality_controls.qc_by
  ├── trx_lab_deliveries.courier_id
  ├── trx_invoices.created_by
  └── trx_payments.created_by

mst_technicians
  └── trx_lab_order_assignments

trx_lab_orders
  ├── trx_lab_order_items
  ├── trx_lab_order_status_logs
  ├── trx_lab_order_assignments
  ├── trx_lab_quality_controls
  ├── trx_lab_deliveries
  └── trx_invoices

trx_lab_deliveries
  └── trx_lab_delivery_photos

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
qc_by INDEX
result INDEX
```

## trx_lab_deliveries

```text
delivery_number UNIQUE
lab_order_id INDEX
courier_id INDEX
clinic_id INDEX
status INDEX
delivery_date INDEX
```

## trx_lab_delivery_photos

```text
delivery_id INDEX
uploaded_by INDEX
```

## trx_invoices

```text
invoice_number UNIQUE
lab_order_id INDEX
clinic_id INDEX
doctor_id INDEX
status INDEX
invoice_date INDEX
```

## trx_payments

```text
payment_number UNIQUE
invoice_id INDEX
payment_date INDEX
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
1. Order hanya bisa masuk READY_FOR_DELIVERY jika hasil QC PASSED (status QC_PASSED).
2. Jika hasil QC REVISION, status order menjadi REMAKE.
3. Jika hasil QC REJECTED, order tidak bisa dikirim.
```

## Delivery

```text
1. Delivery hanya bisa dibuat jika order READY_FOR_DELIVERY.
2. Delivery wajib memiliki kurir.
3. POD wajib berisi nama penerima.
4. POD wajib berisi tanda tangan digital.
5. POD wajib memiliki minimal 1 foto barang diterima.
6. Status DELIVERED hanya boleh jika POD lengkap.
```

## Invoice

```text
1. Invoice hanya bisa dibuat setelah delivery DELIVERED.
2. Invoice dapat dibayar sebagian.
3. Jika paid_amount = 0, status UNPAID.
4. Jika paid_amount < grand_total, status PARTIAL.
5. Jika paid_amount >= grand_total, status PAID.
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
18. trx_lab_deliveries
19. trx_lab_delivery_photos
20. trx_invoices
21. trx_invoice_items
22. trx_payments

23. sys_attachments
24. sys_audit_logs
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
IN_TRANSIT
ARRIVED
POD_COMPLETED
DELIVERED
CANCELLED
```

## Invoice Status

```text
UNPAID
PARTIAL
PAID
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
4. trx_lab_order_assignments, trx_lab_quality_controls, trx_lab_deliveries, trx_invoices,
   trx_payments tetap terhubung ke trx_lab_orders sesuai ERD.
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
