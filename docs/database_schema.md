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

| Column     | Type         | Rule         |
| ---------- | ------------ | ------------ |
| id         | BIGINT       | PK           |
| code       | VARCHAR(50)  | UNIQUE       |
| name       | VARCHAR(150) | NOT NULL     |
| phone      | VARCHAR(50)  | NULL         |
| email      | VARCHAR(150) | NULL         |
| address    | TEXT         | NULL         |
| is_active  | BOOLEAN      | DEFAULT TRUE |
| created_at | TIMESTAMP    |              |
| updated_at | TIMESTAMP    |              |
| deleted_at | TIMESTAMP    | NULL         |

---

## mst_doctors

| Column     | Type         | Rule              |
| ---------- | ------------ | ----------------- |
| id         | BIGINT       | PK                |
| clinic_id  | BIGINT       | FK mst_clinics.id |
| name       | VARCHAR(150) | NOT NULL          |
| phone      | VARCHAR(50)  | NULL              |
| email      | VARCHAR(150) | NULL              |
| is_active  | BOOLEAN      | DEFAULT TRUE      |
| created_at | TIMESTAMP    |                   |
| updated_at | TIMESTAMP    |                   |
| deleted_at | TIMESTAMP    | NULL              |

---

## mst_patients

| Column       | Type         | Rule     |
| ------------ | ------------ | -------- |
| id           | BIGINT       | PK       |
| patient_code | VARCHAR(50)  | UNIQUE   |
| name         | VARCHAR(150) | NOT NULL |
| gender       | VARCHAR(20)  | NULL     |
| birth_date   | DATE         | NULL     |
| phone        | VARCHAR(50)  | NULL     |
| address      | TEXT         | NULL     |
| created_at   | TIMESTAMP    |          |
| updated_at   | TIMESTAMP    |          |
| deleted_at   | TIMESTAMP    | NULL     |

---

## mst_lab_services

| Column         | Type          | Rule         |
| -------------- | ------------- | ------------ |
| id             | BIGINT        | PK           |
| code           | VARCHAR(50)   | UNIQUE       |
| name           | VARCHAR(150)  | NOT NULL     |
| category       | VARCHAR(100)  | NULL         |
| estimated_days | INTEGER       | DEFAULT 1    |
| base_price     | DECIMAL(18,2) | DEFAULT 0    |
| is_active      | BOOLEAN       | DEFAULT TRUE |
| created_at     | TIMESTAMP     |              |
| updated_at     | TIMESTAMP     |              |
| deleted_at     | TIMESTAMP     | NULL         |

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

| Column          | Type         | Rule         |
| --------------- | ------------ | ------------ |
| id              | BIGINT       | PK           |
| user_id         | BIGINT       | FK users.id  |
| technician_code | VARCHAR(50)  | UNIQUE       |
| name            | VARCHAR(150) | NOT NULL     |
| phone           | VARCHAR(50)  | NULL         |
| skill_category  | VARCHAR(100) | NULL         |
| is_active       | BOOLEAN      | DEFAULT TRUE |
| created_at      | TIMESTAMP    |              |
| updated_at      | TIMESTAMP    |              |
| deleted_at      | TIMESTAMP    | NULL         |

---

# 4. Transaction Tables

## trx_lab_orders

| Column       | Type        | Rule                    |
| ------------ | ----------- | ----------------------- |
| id           | BIGINT      | PK                      |
| order_number | VARCHAR(50) | UNIQUE                  |
| clinic_id    | BIGINT      | FK mst_clinics.id       |
| doctor_id    | BIGINT      | FK mst_doctors.id       |
| patient_id   | BIGINT      | FK mst_patients.id NULL |
| order_date   | DATE        | NOT NULL                |
| due_date     | DATE        | NULL                    |
| priority     | VARCHAR(20) | DEFAULT NORMAL          |
| status       | VARCHAR(50) | DEFAULT RECEIVED        |
| notes        | TEXT        | NULL                    |
| created_by   | BIGINT      | FK users.id             |
| created_at   | TIMESTAMP   |                         |
| updated_at   | TIMESTAMP   |                         |
| deleted_at   | TIMESTAMP   | NULL                    |

Status order:

```text
DRAFT
RECEIVED
IN_PROGRESS
WAITING_MATERIAL
QC
REVISION
READY_FOR_DELIVERY
IN_DELIVERY
DELIVERED
COMPLETED
CANCELLED
```

---

## trx_lab_order_items

| Column           | Type          | Rule                   |
| ---------------- | ------------- | ---------------------- |
| id               | BIGINT        | PK                     |
| lab_order_id     | BIGINT        | FK trx_lab_orders.id   |
| lab_service_id   | BIGINT        | FK mst_lab_services.id |
| tooth_number     | VARCHAR(20)   | NULL                   |
| shade_color_text | VARCHAR(100)  | NULL                   |
| material_text    | VARCHAR(100)  | NULL                   |
| qty              | DECIMAL(18,2) | DEFAULT 1              |
| price            | DECIMAL(18,2) | DEFAULT 0              |
| discount         | DECIMAL(18,2) | DEFAULT 0              |
| subtotal         | DECIMAL(18,2) | DEFAULT 0              |
| notes            | TEXT          | NULL                   |
| created_at       | TIMESTAMP     |                        |
| updated_at       | TIMESTAMP     |                        |
| deleted_at       | TIMESTAMP     | NULL                   |

Catatan:

```text
V1 belum memakai tabel mst_materials dan mst_shade_colors.
Material dan shade disimpan sebagai text agar lebih cepat dikembangkan.
```

---

## trx_lab_order_status_logs

| Column       | Type        | Rule                 |
| ------------ | ----------- | -------------------- |
| id           | BIGINT      | PK                   |
| lab_order_id | BIGINT      | FK trx_lab_orders.id |
| status_from  | VARCHAR(50) | NULL                 |
| status_to    | VARCHAR(50) | NOT NULL             |
| notes        | TEXT        | NULL                 |
| changed_by   | BIGINT      | FK users.id          |
| changed_at   | TIMESTAMP   | NOT NULL             |

---

## trx_lab_order_assignments

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

Status assignment:

```text
ASSIGNED
IN_PROGRESS
DONE
CANCELLED
REASSIGNED
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
File pendukung QC
Dokumen invoice
```

| Column          | Type         | Rule        |
| --------------- | ------------ | ----------- |
| id              | BIGINT       | PK          |
| attachable_type | VARCHAR(150) | NOT NULL    |
| attachable_id   | BIGINT       | NOT NULL    |
| file_name       | VARCHAR(255) | NOT NULL    |
| file_path       | VARCHAR(255) | NOT NULL    |
| file_type       | VARCHAR(100) | NULL        |
| file_size       | BIGINT       | NULL        |
| uploaded_by     | BIGINT       | FK users.id |
| uploaded_at     | TIMESTAMP    | NOT NULL    |

---

## sys_audit_logs

Mencatat aktivitas penting sistem.

| Column     | Type         | Rule             |
| ---------- | ------------ | ---------------- |
| id         | BIGINT       | PK               |
| user_id    | BIGINT       | FK users.id NULL |
| action     | VARCHAR(100) | NOT NULL         |
| table_name | VARCHAR(100) | NULL             |
| record_id  | BIGINT       | NULL             |
| old_values | JSONB        | NULL             |
| new_values | JSONB        | NULL             |
| ip_address | VARCHAR(100) | NULL             |
| user_agent | TEXT         | NULL             |
| created_at | TIMESTAMP    | NOT NULL         |

Audit wajib untuk:

```text
LOGIN
LOGOUT
CREATE
UPDATE
DELETE
STATUS_CHANGE
ASSIGNMENT
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

## sys_audit_logs

```text
user_id INDEX
table_name INDEX
record_id INDEX
action INDEX
created_at INDEX
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
```

## Quality Control

```text
1. Order hanya bisa masuk READY_FOR_DELIVERY jika QC PASSED.
2. Jika QC REVISION, status order menjadi REVISION.
3. Jika QC REJECTED, order tidak bisa dikirim.
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
15. trx_lab_quality_controls
16. trx_lab_deliveries
17. trx_lab_delivery_photos
18. trx_invoices
19. trx_invoice_items
20. trx_payments

21. sys_attachments
22. sys_audit_logs
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
IN_PROGRESS
WAITING_MATERIAL
QC
REVISION
READY_FOR_DELIVERY
IN_DELIVERY
DELIVERED
COMPLETED
CANCELLED
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
