# ERD.md

# Asia Dental Lab Management System

Version: V1
Database: PostgreSQL
Architecture: Modular Monolith
Backend: Laravel 12

---

# 1. ERD Purpose

Dokumen ini menjelaskan relasi antar tabel utama pada Asia Dental Lab Management System V1.

ERD ini menjadi acuan untuk:

```text
Migration
Model Relationship
Repository
Service Layer
API
Testing
```

---

# 2. Core ERD Concept

Pusat transaksi aplikasi adalah:

```text
trx_lab_orders
```

Semua proses operasional utama berelasi ke order:

```text
Lab Order
↓
Order Items
↓
Assignment Teknisi
-> Work Logs
-> Production Steps
↓
Status Log
↓
Quality Control
↓
Delivery + POD
↓
Invoice
↓
Payment
```

---

# 3. High Level ERD

```text
users
├── mst_technicians
├── trx_lab_orders
├── trx_lab_order_status_logs
├── trx_lab_order_assignments
├── trx_lab_quality_controls
├── trx_lab_deliveries
├── trx_invoices
├── trx_payments
├── sys_attachments
└── sys_audit_logs


mst_clinics
├── mst_doctors
├── mst_patients
├── trx_lab_orders
├── trx_lab_deliveries
└── trx_invoices


mst_doctors
├── mst_patients
└── trx_lab_orders


mst_patients
└── trx_lab_orders


mst_lab_services
└── trx_lab_order_items


mst_technicians
└── trx_lab_order_assignments


trx_lab_orders
├── trx_lab_order_items
├── trx_lab_order_status_logs
├── trx_lab_order_assignments
├── trx_lab_quality_controls
├── trx_lab_deliveries
├── trx_invoice_items (billing link)
├── sys_attachments (polymorphic)
└── sys_audit_logs (polymorphic)


trx_lab_deliveries
└── trx_lab_delivery_photos


trx_invoices
├── trx_invoice_items
└── trx_payments
```

---

Sprint 4 high-level ERD additions:

```text
trx_lab_orders
├── trx_lab_order_items
├── trx_lab_order_status_logs
├── trx_lab_order_assignments
├── trx_lab_production_steps
├── sys_attachments (polymorphic)
└── sys_audit_logs (polymorphic)

trx_lab_order_assignments
└── trx_lab_work_logs
```

---

# 4. Mermaid ERD Diagram

```mermaid
erDiagram

    users ||--o| mst_technicians : "has technician profile"
    users ||--o{ trx_lab_orders : "creates"
    users ||--o{ trx_lab_order_status_logs : "changes status"
    users ||--o{ trx_lab_order_assignments : "assigns"
    users ||--o{ trx_lab_work_logs : "records"
    users ||--o{ trx_lab_quality_controls : "performs qc"
    users ||--o{ trx_lab_deliveries : "courier/created_by"
    users ||--o{ trx_invoices : "creates invoice"
    users ||--o{ trx_payments : "records payment"
    users ||--o{ sys_attachments : "uploads"
    users ||--o{ sys_audit_logs : "performs"

    mst_clinics ||--o{ mst_doctors : "has doctors"
    mst_clinics ||--o{ mst_patients : "has patients"
    mst_clinics ||--o{ trx_lab_orders : "has orders"
    mst_clinics ||--o{ trx_lab_deliveries : "receives deliveries"
    mst_clinics ||--o{ trx_invoices : "has invoices"

    mst_doctors ||--o{ mst_patients : "has patients"
    mst_doctors ||--o{ trx_lab_orders : "submits orders"
    mst_patients ||--o{ trx_lab_orders : "has orders"

    mst_lab_services ||--o{ trx_lab_order_items : "used in items"

    mst_technicians ||--o{ trx_lab_order_assignments : "receives"

    trx_lab_orders ||--o{ trx_lab_order_items : "has items"
    trx_lab_orders ||--o{ trx_lab_order_status_logs : "has status logs"
    trx_lab_orders ||--o{ trx_lab_order_assignments : "assigned_to"
    trx_lab_orders ||--o{ trx_lab_production_steps : "tracks"
    trx_lab_orders ||--o{ trx_lab_quality_controls : "has qc records"
    trx_lab_orders ||--o{ trx_lab_deliveries : "has deliveries"
    trx_lab_orders ||--o{ trx_invoice_items : "billed as invoice items"
    trx_lab_orders ||--o{ sys_attachments : "has attachments (polymorphic)"
    trx_lab_orders ||--o{ sys_audit_logs : "audited by (polymorphic)"

    trx_lab_order_assignments ||--o{ trx_lab_work_logs : "contains"

    trx_lab_deliveries ||--o{ trx_lab_delivery_photos : "has photos"

    trx_invoices ||--o{ trx_invoice_items : "has items"
    trx_invoices ||--o{ trx_payments : "has payments"
```

---

# 5. Entity Relationship Details

## 5.1 users → mst_technicians

```text
Relationship:
users 1 → 0..1 mst_technicians

Foreign Key:
mst_technicians.user_id → users.id
```

Artinya:

```text
Satu user bisa memiliki satu profil teknisi.
Tidak semua user adalah teknisi.
```

---

## 5.2 users → trx_lab_orders

```text
Relationship:
users 1 → many trx_lab_orders

Foreign Key:
trx_lab_orders.created_by → users.id
```

Artinya:

```text
User admin membuat banyak lab order.
```

---

## 5.3 mst_clinics → mst_doctors

```text
Relationship:
mst_clinics 1 → many mst_doctors

Foreign Key:
mst_doctors.clinic_id → mst_clinics.id
```

Artinya:

```text
Satu klinik dapat memiliki banyak dokter.
```

---

## 5.4 mst_clinics → trx_lab_orders

```text
Relationship:
mst_clinics 1 → many trx_lab_orders

Foreign Key:
trx_lab_orders.clinic_id → mst_clinics.id
```

Artinya:

```text
Satu klinik dapat memiliki banyak order lab.
```

---

## 5.5 mst_doctors → trx_lab_orders

```text
Relationship:
mst_doctors 1 → many trx_lab_orders

Foreign Key:
trx_lab_orders.doctor_id → mst_doctors.id
```

Artinya:

```text
Satu dokter dapat membuat banyak order lab.
```

---

## 5.6 mst_patients → trx_lab_orders

```text
Relationship:
mst_patients 1 → many trx_lab_orders

Foreign Key:
trx_lab_orders.patient_id → mst_patients.id nullable
```

Artinya:

```text
Satu pasien dapat memiliki banyak order.
Untuk V1, patient_id boleh kosong.
```

---

## 5.7 trx_lab_orders → trx_lab_order_items

```text
Relationship:
trx_lab_orders 1 → many trx_lab_order_items

Foreign Key:
trx_lab_order_items.lab_order_id → trx_lab_orders.id
```

Artinya:

```text
Satu order wajib memiliki minimal satu item pekerjaan.
```

---

## 5.8 mst_lab_services → trx_lab_order_items

```text
Relationship:
mst_lab_services 1 → many trx_lab_order_items

Foreign Key:
trx_lab_order_items.lab_service_id → mst_lab_services.id
```

Artinya:

```text
Satu service lab bisa digunakan di banyak order item.
```

---

## 5.9 trx_lab_orders → trx_lab_order_status_logs

```text
Relationship:
trx_lab_orders 1 → many trx_lab_order_status_logs

Foreign Key:
trx_lab_order_status_logs.lab_order_id → trx_lab_orders.id
```

Artinya:

```text
Setiap perubahan status order wajib tercatat.
```

---

## 5.10 trx_lab_orders → trx_lab_order_assignments

```text
Relationship:
trx_lab_orders 1 → many trx_lab_order_assignments

Foreign Key:
trx_lab_order_assignments.lab_order_id → trx_lab_orders.id
```

Artinya:

```text
Satu order bisa memiliki satu atau lebih assignment teknisi.
```

---

## 5.11 mst_technicians → trx_lab_order_assignments

```text
Relationship:
mst_technicians 1 → many trx_lab_order_assignments

Foreign Key:
trx_lab_order_assignments.technician_id → mst_technicians.id
```

Artinya:

```text
Satu teknisi bisa mengerjakan banyak order.
```

---

## 5.12 trx_lab_orders → trx_lab_quality_controls

```text
Relationship:
trx_lab_orders 1 → many trx_lab_quality_controls

Foreign Key:
trx_lab_quality_controls.lab_order_id → trx_lab_orders.id
```

Artinya:

```text
Satu order bisa memiliki beberapa record QC, terutama jika ada revisi.
```

---

## 5.13 trx_lab_orders → trx_lab_deliveries

```text
Relationship:
trx_lab_orders 1 → many trx_lab_deliveries

Foreign Key:
trx_lab_deliveries.lab_order_id → trx_lab_orders.id
```

Artinya:

```text
Satu order dapat memiliki lebih dari satu delivery jika terjadi pengiriman ulang.
```

---

## 5.14 trx_lab_deliveries → trx_lab_delivery_photos

```text
Relationship:
trx_lab_deliveries 1 → many trx_lab_delivery_photos

Foreign Key:
trx_lab_delivery_photos.delivery_id → trx_lab_deliveries.id
```

Artinya:

```text
Satu delivery wajib memiliki minimal satu foto penerimaan.
```

---

## 5.15 trx_lab_orders → trx_invoice_items

Sprint 7 update:

```text
The earlier direct trx_lab_orders -> trx_invoices 1:0..1 billing design is
superseded by the Sprint 7 invoice header and invoice item model.

Active Sprint 7 relationship:
trx_lab_orders 1 -> many trx_invoice_items
trx_invoice_items.lab_order_id -> trx_lab_orders.id

One invoice can contain multiple completed Lab Orders from the same clinic.
```

```text
Relationship:
trx_lab_orders 1 -> many trx_invoice_items

Foreign Key:
trx_invoice_items.lab_order_id -> trx_lab_orders.id

Constraint:
trx_invoice_items.lab_order_id should be unique for active/non-void invoices.
```

Artinya:

```text
Untuk Sprint 7, satu invoice dapat berisi banyak Lab Order dari Clinic yang sama.
Satu Lab Order tidak boleh ditagihkan lebih dari sekali pada invoice aktif.
```

---

## 5.16 trx_invoices → trx_invoice_items

```text
Relationship:
trx_invoices 1 → many trx_invoice_items

Foreign Key:
trx_invoice_items.invoice_id → trx_invoices.id
```

Artinya:

```text
Satu invoice memiliki banyak detail item tagihan.
```

---

## 5.17 trx_invoices → trx_payments

```text
Relationship:
trx_invoices 1 → many trx_payments

Foreign Key:
trx_payments.invoice_id → trx_invoices.id
```

Artinya:

```text
Satu invoice dapat memiliki banyak pembayaran karena mendukung partial payment.
```

---

## 5.18 users â†’ trx_lab_order_assignments

```text
Relationship:
users 1 â†’ many trx_lab_order_assignments

Foreign Key:
trx_lab_order_assignments.assigned_by â†’ users.id
```

Artinya:

```text
User Admin Lab atau Production Manager dapat membuat banyak assignment teknisi.
```

---

## 5.19 trx_lab_order_assignments â†’ trx_lab_work_logs

```text
Relationship:
trx_lab_order_assignments 1 â†’ many trx_lab_work_logs

Foreign Key:
trx_lab_work_logs.assignment_id â†’ trx_lab_order_assignments.id
```

Artinya:

```text
Satu assignment dapat memiliki banyak work session.
Work logs mencatat start work, pause work, resume work, dan complete work.
Historical work logs tidak boleh dihapus.
```

---

## 5.20 users â†’ trx_lab_work_logs

```text
Relationship:
users 1 â†’ many trx_lab_work_logs

Foreign Key:
trx_lab_work_logs.performed_by â†’ users.id
```

Artinya:

```text
User teknisi, Admin Lab, atau Production Manager dapat mencatat aktivitas produksi.
```

---

## 5.21 trx_lab_orders â†’ trx_lab_production_steps

```text
Relationship:
trx_lab_orders 1 â†’ many trx_lab_production_steps

Foreign Key:
trx_lab_production_steps.lab_order_id â†’ trx_lab_orders.id
```

Artinya:

```text
Satu order dapat memiliki banyak production step.
Production steps bersifat independen dari assignment records.
Steps membantu mempersiapkan order untuk QC handoff tanpa memodelkan hasil QC.
```

---

## 5.22 Sprint 4 Workflow Relationship Notes

Assignment workflow:

```text
Lab Order
â†“
Assignment
â†“
Work Logs
```

Rules:

```text
1. One order may have multiple assignment records over time.
2. Only one active assignment exists at any moment.
3. Reassignment creates a new assignment record.
4. Historical assignment records are preserved.
```

Work log workflow:

```text
Assignment
â†“
Work Logs
```

Rules:

```text
1. One assignment can have many work sessions.
2. Work logs record start work, pause work, resume work, and complete work.
3. Historical work logs must never be deleted.
```

Production step workflow:

```text
Lab Order
â†“
Production Steps
```

Rules:

```text
1. Production steps are independent from assignment records.
2. Multiple production steps can exist per order.
3. Steps prepare the order for QC.
4. Sprint 4 does not model QC results.
```

---

# 6. Cardinality Summary

| Parent             | Child                     | Cardinality |
| ------------------ | ------------------------- | ----------- |
| users              | mst_technicians           | 1 to 0..1   |
| users              | trx_lab_orders            | 1 to many   |
| users              | trx_lab_order_status_logs | 1 to many   |
| users              | trx_lab_order_assignments | 1 to many   |
| users              | trx_lab_work_logs         | 1 to many   |
| users              | trx_lab_quality_controls  | 1 to many   |
| users              | trx_lab_deliveries        | 1 to many   |
| users              | trx_invoices              | 1 to many   |
| users              | trx_payments              | 1 to many   |
| mst_clinics        | mst_doctors               | 1 to many   |
| mst_clinics        | mst_patients              | 1 to many   |
| mst_doctors        | mst_patients              | 1 to many   |
| mst_clinics        | trx_lab_orders            | 1 to many   |
| mst_clinics        | trx_lab_deliveries        | 1 to many   |
| mst_clinics        | trx_invoices              | 1 to many   |
| mst_doctors        | trx_lab_orders            | 1 to many   |
| mst_patients       | trx_lab_orders            | 1 to many   |
| mst_lab_services   | trx_lab_order_items       | 1 to many   |
| mst_technicians    | trx_lab_order_assignments | 1 to many   |
| trx_lab_orders     | trx_lab_order_items       | 1 to many   |
| trx_lab_orders     | trx_lab_order_status_logs | 1 to many   |
| trx_lab_orders     | trx_lab_order_assignments | 1 to many   |
| trx_lab_orders     | trx_lab_production_steps  | 1 to many   |
| trx_lab_order_assignments | trx_lab_work_logs    | 1 to many   |
| trx_lab_orders     | trx_lab_quality_controls  | 1 to many   |
| trx_lab_orders     | trx_lab_deliveries        | 1 to many   |
| trx_lab_orders     | trx_invoice_items         | 1 to many   |
| trx_lab_deliveries | trx_lab_delivery_photos   | 1 to many   |
| trx_invoices       | trx_invoice_items         | 1 to many   |
| trx_invoices       | trx_payments              | 1 to many   |

---

# 7. Polymorphic Relationships

Dua tabel sistem bersifat **polymorphic** sehingga dapat dipakai ulang oleh
banyak entitas tanpa menambah foreign key per tabel:

```text
sys_attachments
sys_audit_logs
```

Kedua tabel menggunakan pasangan kolom polymorphic yang sama
(sesuai `lab_order_design.md` Sprint 3):

```text
entity_type
entity_id
```

Digunakan untuk (Sprint 3 dan seterusnya):

```text
trx_lab_orders            (Sprint 3)
trx_lab_orders            (Sprint 4 production attachments)
trx_lab_quality_controls  (Sprint 5)
trx_lab_deliveries        (Sprint 6)
trx_invoices              (Sprint 7)
trx_payments              (Sprint 7)
```

Contoh:

```text
entity_type = trx_lab_orders
entity_id   = 1
file_path   = order-attachments/ADL-2026-000001/photo-1.jpg
```

Alasan polymorphic:

```text
Dibuat polymorphic agar attachment dan audit log dapat digunakan ulang
pada sprint berikutnya (QC, Delivery, Invoice, Payment) tanpa refactor besar.
```

> **Naming note (reconciliation).** ERD ini mengikuti `lab_order_design.md`
> yang menggunakan `entity_type` / `entity_id` untuk `sys_attachments` dan
> `sys_audit_logs`. Versi awal `database_schema.md` masih memakai
> `attachable_type` / `attachable_id` (sys_attachments) dan
> `table_name` / `record_id` (sys_audit_logs). Penyelarasan kolom pada
> `database_schema.md` berada di luar scope tugas ini (hanya `erd.md` yang
> diperbarui) dan perlu dilakukan saat implementasi migration Sprint 3.

---

# 8. Special ERD Rules

## 8.1 Lab Order sebagai pusat transaksi

```text
trx_lab_orders
```

menjadi pusat transaksi utama.

---

## 8.2 Invoice Sprint 7: Multi Order One Invoice

Sprint 7 update:

```text
This rule is no longer the active Sprint 7 billing design.

Sprint 7 uses trx_invoices as the invoice header and trx_invoice_items as the
bridge to Lab Orders. One invoice can group multiple completed Lab Orders from
the same clinic.
```

```text
trx_invoices 1 -> many trx_invoice_items
trx_lab_orders 1 -> many trx_invoice_items
```

Rule:

```text
Satu invoice dapat menagihkan beberapa Lab Order yang sudah COMPLETED dari
Clinic yang sama. Lab Order billing link disimpan di trx_invoice_items.
```

---

## 8.3 Delivery boleh lebih dari satu

```text
trx_lab_orders 1 → many trx_lab_deliveries
```

Alasan:

```text
Jika terjadi pengiriman ulang atau koreksi delivery.
```

---

## 8.4 QC boleh lebih dari satu

```text
trx_lab_orders 1 → many trx_lab_quality_controls
```

Alasan:

```text
Jika hasil perlu revisi dan QC ulang.
```

---

## 8.5 POD wajib memiliki foto

```text
trx_lab_deliveries 1 → many trx_lab_delivery_photos
```

Rule:

```text
Minimal 1 foto sebelum status DELIVERED.
```

---

## 8.6 Sprint 4 Production Status Notes

Sprint 4 mengaktifkan status Lab Order berikut:

```text
ASSIGNED
IN_PRODUCTION
ON_HOLD
QC_PENDING
```

Catatan:

```text
1. RECEIVED berasal dari Sprint 3 dan menjadi titik awal assignment.
2. ASSIGNED berarti order sudah memiliki active technician assignment.
3. IN_PRODUCTION berarti teknisi sudah memulai pekerjaan.
4. ON_HOLD berarti pekerjaan tertahan sementara dengan alasan yang dicatat.
5. QC_PENDING berarti produksi selesai dan order siap diterima QC.
6. QC_PASSED dan REMAKE menjadi bagian Sprint 5.
7. Sprint 4 tidak memodelkan QC result behavior.
```

---

## 8.7 Sprint 4 Assignment and Work Log Rules

```text
1. One order may have multiple assignment records over time.
2. Only one active technician assignment exists at any moment.
3. Reassignment creates a new assignment record.
4. Work logs are immutable historical records.
5. Work logs preserve start, pause, resume, complete, and status change events.
```

---

## 8.8 Sprint 4 Production Step Rules

```text
1. trx_lab_production_steps belongs to trx_lab_orders.
2. Production steps are independent from assignment records.
3. Multiple production steps can exist per order.
4. Production steps prepare the order for QC handoff.
5. Production steps do not store QC pass, reject, or remake decisions.
```

---

# 9. Foreign Key Recommendation

## mst_doctors

```text
clinic_id → mst_clinics.id
```

## mst_technicians

```text
user_id → users.id nullable
```

## mst_patients

```text
clinic_id → mst_clinics.id
doctor_id → mst_doctors.id
```

> Added in Sprint 2 (TASK-0203): patient is captured against its referring
> clinic and doctor at registration time.

## trx_lab_orders

```text
clinic_id → mst_clinics.id
doctor_id → mst_doctors.id
patient_id → mst_patients.id nullable
created_by → users.id
```

## trx_lab_order_items

```text
lab_order_id → trx_lab_orders.id
lab_service_id → mst_lab_services.id
```

## trx_lab_order_status_logs

```text
lab_order_id → trx_lab_orders.id
changed_by → users.id
```

## trx_lab_order_assignments

```text
lab_order_id → trx_lab_orders.id
technician_id → mst_technicians.id
assigned_by → users.id
```

## trx_lab_work_logs

```text
assignment_id → trx_lab_order_assignments.id
performed_by → users.id
```

## trx_lab_production_steps

```text
lab_order_id → trx_lab_orders.id
```

## trx_lab_quality_controls

```text
lab_order_id → trx_lab_orders.id
qc_by → users.id
```

## trx_lab_deliveries

```text
lab_order_id → trx_lab_orders.id
courier_id → users.id
clinic_id → mst_clinics.id
created_by → users.id
```

## trx_lab_delivery_photos

```text
delivery_id → trx_lab_deliveries.id
uploaded_by → users.id
```

## trx_invoices

```text
clinic_id → mst_clinics.id
created_by → users.id
```

## trx_invoice_items

```text
invoice_id → trx_invoices.id
lab_order_id → trx_lab_orders.id
```

## trx_payments

```text
invoice_id → trx_invoices.id
received_by → users.id
created_by → users.id
```

## sys_attachments

```text
entity_type + entity_id → polymorphic (e.g. trx_lab_orders.id)
uploaded_by → users.id
```

## sys_audit_logs

```text
entity_type + entity_id → polymorphic (e.g. trx_lab_orders.id)
performed_by → users.id
```

---

# 10. Index Recommendation

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

## trx_lab_work_logs

```text
assignment_id INDEX
performed_by INDEX
event INDEX
performed_at INDEX
```

## trx_lab_production_steps

```text
lab_order_id INDEX
status INDEX
step_name INDEX
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
clinic_id INDEX
created_by INDEX
status INDEX
invoice_date INDEX
due_date INDEX
```

## trx_invoice_items

```text
invoice_id INDEX
lab_order_id INDEX
```

## trx_payments

```text
payment_number UNIQUE
invoice_id INDEX
payment_date INDEX
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
action INDEX
performed_at INDEX
```

---

# 11. Migration Dependency Order

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

# 12. ERD Decisions

```text
1. trx_lab_orders menjadi pusat transaksi.
2. Satu order memiliki banyak order item.
3. Satu order memiliki banyak status log.
4. Satu order bisa memiliki banyak assignment.
5. Satu order bisa memiliki banyak QC.
6. Satu order bisa memiliki banyak delivery.
7. Satu delivery memiliki banyak foto POD.
8. Satu order hanya memiliki maksimal satu invoice pada V1.
9. Satu invoice dapat memiliki banyak pembayaran.
10. Attachment umum menggunakan polymorphic relation.
11. Signature disimpan di trx_lab_deliveries.signature_file_path.
12. Foto POD disimpan di trx_lab_delivery_photos.
13. Assignment teknisi disimpan sebagai histori, bukan ditimpa.
14. Work logs menyimpan sesi kerja produksi.
15. Production steps menyimpan milestone produksi sebelum QC.
```

---

# 13. Sprint 3 ERD — Lab Order Core

Bagian ini menyelaraskan ERD dengan desain **Sprint 3 — Lab Order Core**
(`lab_order_design.md`). Sprint 3 menjadikan `trx_lab_orders` sebagai pusat
transaksi pertama yang benar-benar diimplementasikan.

## 13.1 Tabel dalam scope Sprint 3

```text
trx_lab_orders
trx_lab_order_items
trx_lab_order_status_logs
sys_attachments
sys_audit_logs
```

## 13.2 Relasi yang wajib ada

```text
mst_clinics      1 → many  trx_lab_orders
mst_doctors      1 → many  trx_lab_orders
mst_patients     1 → many  trx_lab_orders
users            1 → many  trx_lab_orders            (created_by)

trx_lab_orders   1 → many  trx_lab_order_items
mst_lab_services 1 → many  trx_lab_order_items

trx_lab_orders   1 → many  trx_lab_order_status_logs
users            1 → many  trx_lab_order_status_logs (changed_by)

users            1 → many  sys_attachments           (uploaded_by)
users            1 → many  sys_audit_logs            (performed_by)

trx_lab_orders   1 → many  sys_attachments           (polymorphic)
trx_lab_orders   1 → many  sys_audit_logs            (polymorphic)
```

## 13.3 Mermaid ERD — Sprint 3

```mermaid
erDiagram
    users ||--o{ trx_lab_orders : creates
    users ||--o{ trx_lab_order_status_logs : changes
    users ||--o{ sys_attachments : uploads
    users ||--o{ sys_audit_logs : performs

    mst_clinics ||--o{ mst_doctors : has
    mst_clinics ||--o{ trx_lab_orders : owns

    mst_doctors ||--o{ mst_patients : handles
    mst_doctors ||--o{ trx_lab_orders : submits

    mst_patients ||--o{ trx_lab_orders : has

    trx_lab_orders ||--o{ trx_lab_order_items : contains
    mst_lab_services ||--o{ trx_lab_order_items : used_in

    trx_lab_orders ||--o{ trx_lab_order_status_logs : tracks
    trx_lab_orders ||--o{ sys_attachments : has
    trx_lab_orders ||--o{ sys_audit_logs : audited_by
```

## 13.4 Aturan Polymorphic

`sys_attachments` dan `sys_audit_logs` memakai pasangan kolom polymorphic
(lihat bagian 7):

```text
sys_attachments : entity_type, entity_id
sys_audit_logs  : entity_type, entity_id
```

Keduanya dibuat polymorphic agar dapat **digunakan ulang pada sprint berikutnya**
tanpa refactor besar:

```text
QC        (Sprint 5)
Delivery  (Sprint 6)
Invoice   (Sprint 7)
Payment   (Sprint 7)
```

## 13.5 Sprint 3 ERD Decisions

```text
1.  trx_lab_orders menjadi pusat transaksi awal.
2.  Satu order memiliki satu clinic.
3.  Satu order memiliki satu doctor.
4.  Satu order memiliki satu patient.
5.  Satu order memiliki banyak item.
6.  Satu order memiliki banyak attachment.
7.  Satu order memiliki banyak status log.
8.  Satu order memiliki banyak audit log.
9.  sys_attachments menggunakan polymorphic relation.
10. sys_audit_logs menggunakan polymorphic relation.
11. Delivery, QC, Invoice, dan Payment tidak diimplementasikan pada Sprint 3.
12. ERD tetap disiapkan agar Sprint 4–7 dapat melanjutkan workflow tanpa refactor besar.
```

---

# 14. Sprint 4 ERD — Production Workflow

Bagian ini memperluas ERD Sprint 3 agar mendukung **Sprint 4 — Production Workflow**
(`production_workflow_design.md`). Sprint 4 memindahkan Lab Order dari
`RECEIVED` ke `QC_PENDING` melalui assignment teknisi, work logs, dan production
steps.

## 14.1 Tabel dalam scope Sprint 4

```text
trx_lab_order_assignments
trx_lab_work_logs
trx_lab_production_steps
trx_lab_order_status_logs
sys_attachments
sys_audit_logs
```

Catatan:

```text
trx_lab_order_status_logs, sys_attachments, dan sys_audit_logs sudah disiapkan
di Sprint 3 dan digunakan ulang pada Sprint 4.
```

Entity purpose:

| Entity | Purpose |
| --- | --- |
| `trx_lab_order_assignments` | Assignment of Lab Orders to technicians. |
| `trx_lab_work_logs` | Tracking technician work sessions. |
| `trx_lab_production_steps` | Tracking production progress and milestones. |

## 14.2 Relasi yang wajib ada

```text
trx_lab_orders
  1 -> many trx_lab_order_assignments

mst_technicians
  1 -> many trx_lab_order_assignments

users
  1 -> many trx_lab_order_assignments (assigned_by)

trx_lab_order_assignments
  1 -> many trx_lab_work_logs

users
  1 -> many trx_lab_work_logs (performed_by)

trx_lab_orders
  1 -> many trx_lab_production_steps
```

## 14.3 Assignment Workflow Relationships

```text
Lab Order
-> Assignment
-> Work Logs
```

Rules:

```text
1. One order may have multiple assignment records over time.
2. Only one active assignment exists at any moment.
3. Reassignment creates a new assignment record.
4. Historical assignment records are preserved.
```

## 14.4 Work Log Relationships

```text
Assignment
-> Work Logs
```

Rules:

```text
1. One assignment can have many work sessions.
2. Work logs record start work, pause work, resume work, and complete work.
3. Historical work logs must never be deleted.
```

## 14.5 Production Step Relationships

```text
Lab Order
-> Production Steps
```

Rules:

```text
1. Production steps are independent from assignment records.
2. Multiple production steps can exist per order.
3. Steps prepare the order for QC.
4. QC results are not modeled in Sprint 4.
```

## 14.6 Mermaid ERD — Sprint 4

```mermaid
erDiagram
    trx_lab_orders ||--o{ trx_lab_order_assignments : assigned_to
    mst_technicians ||--o{ trx_lab_order_assignments : receives
    users ||--o{ trx_lab_order_assignments : assigns

    trx_lab_order_assignments ||--o{ trx_lab_work_logs : contains
    users ||--o{ trx_lab_work_logs : records

    trx_lab_orders ||--o{ trx_lab_production_steps : tracks
```

## 14.7 Sprint 4 Status Notes

Sprint 4 activates:

```text
ASSIGNED
IN_PRODUCTION
ON_HOLD
QC_PENDING
```

Notes:

```text
1. RECEIVED comes from Sprint 3.
2. ASSIGNED, IN_PRODUCTION, ON_HOLD, and QC_PENDING are active in Sprint 4.
3. QC_PENDING is only QC handoff preparation.
4. QC_PASSED and REMAKE belong to Sprint 5.
5. Sprint 4 does not model Sprint 5 QC workflow.
```

## 14.8 Sprint 4 ERD Decisions

```text
1. Assignment history must be preserved.
2. Only one active technician assignment per order.
3. Reassignment creates a new assignment record.
4. Work logs are immutable historical records.
5. Production steps support future reporting.
6. Production steps prepare QC handoff.
7. QC entities are not part of Sprint 4.
8. Delivery entities are not part of Sprint 4.
9. Invoice entities are not part of Sprint 4.
10. Payment entities are not part of Sprint 4.
```

## 14.9 Sprint 4 ERD Validation Checklist

```text
✓ Assignment entity present
✓ Work Log entity present
✓ Production Step entity present
✓ Assignment relationships documented
✓ Work Log relationships documented
✓ Production Step relationships documented
✓ Mermaid diagram updated
✓ Existing Sprint 1-3 relations preserved
✓ Sprint 5 entities excluded from Sprint 4 scope
✓ Sprint 6 entities excluded from Sprint 4 scope
```

---

# 15. Sprint 5 ERD - Quality Control Workflow

Bagian ini memperluas ERD Sprint 3 dan Sprint 4 agar mendukung **Sprint 5 -
Quality Control Workflow** (`qc_workflow_design.md`). Sprint 5 memproses Lab
Order dari `QC_PENDING` menjadi `QC_PASSED` atau `REMAKE`.

Sprint 5 tidak mengimplementasikan Delivery, Proof of Delivery, Invoice,
Payment, atau Reporting dashboard.

## 15.1 Tabel dalam scope Sprint 5

```text
trx_lab_quality_controls
trx_lab_qc_checklists
trx_lab_remake_requests
trx_lab_order_status_logs
sys_attachments
sys_audit_logs
```

Catatan:

```text
trx_lab_order_status_logs, sys_attachments, dan sys_audit_logs sudah disiapkan
di Sprint 3 dan digunakan ulang pada Sprint 5.
```

Entity purpose:

| Entity | Purpose |
| --- | --- |
| `trx_lab_quality_controls` | Stores each QC review session for a Lab Order. |
| `trx_lab_qc_checklists` | Stores checklist items and results per QC review. |
| `trx_lab_remake_requests` | Stores remake request details when QC result requires remake. |
| `sys_attachments` | Stores QC evidence by polymorphic `entity_type` and `entity_id`. |

## 15.2 High-Level ERD Update

```text
trx_lab_orders
|-- trx_lab_quality_controls
|-- trx_lab_remake_requests
|-- sys_attachments (QC evidence via polymorphic)
`-- sys_audit_logs

trx_lab_quality_controls
|-- trx_lab_qc_checklists
|-- trx_lab_remake_requests
`-- sys_attachments (QC evidence via polymorphic)
```

QC photos/evidence:

```text
QC evidence uses sys_attachments.
trx_lab_qc_photos is not used in V1 unless later required by schema or compliance.
```

## 15.3 Required Relationships

```text
trx_lab_orders
  1 -> many trx_lab_quality_controls

users
  1 -> many trx_lab_quality_controls (inspected_by / qc_by)

trx_lab_quality_controls
  1 -> many trx_lab_qc_checklists

trx_lab_quality_controls
  1 -> many trx_lab_remake_requests

trx_lab_orders
  1 -> many trx_lab_remake_requests

users
  1 -> many trx_lab_remake_requests (requested_by)

trx_lab_quality_controls
  polymorphic -> sys_attachments as QC evidence

trx_lab_orders
  polymorphic -> sys_attachments as order-level QC evidence
```

Implementation naming note:

```text
The existing schema uses qc_by on trx_lab_quality_controls.
Sprint 5 ERD treats qc_by as the inspector/inspected_by relationship.
```

## 15.4 Mermaid ERD - Sprint 5

```mermaid
erDiagram
    trx_lab_orders ||--o{ trx_lab_quality_controls : has_qc_reviews
    users ||--o{ trx_lab_quality_controls : inspects

    trx_lab_quality_controls ||--o{ trx_lab_qc_checklists : contains
    users ||--o{ trx_lab_qc_checklists : checks

    trx_lab_quality_controls ||--o{ trx_lab_remake_requests : may_create
    trx_lab_orders ||--o{ trx_lab_remake_requests : has_remakes
    users ||--o{ trx_lab_remake_requests : requests

    trx_lab_quality_controls ||--o{ sys_attachments : has_evidence
    trx_lab_orders ||--o{ sys_attachments : has_qc_evidence
```

This Mermaid diagram extends the main ERD and must be merged with existing
Sprint 1-4 relationships during implementation. Existing relationships must not
be removed.

## 15.5 Entity Relationship Details

### trx_lab_orders -> trx_lab_quality_controls

```text
Relationship:
trx_lab_orders 1 -> many trx_lab_quality_controls

Foreign Key:
trx_lab_quality_controls.lab_order_id -> trx_lab_orders.id
```

Meaning:

```text
One Lab Order can have many QC review records, especially when remake requires
another QC cycle.
```

### users -> trx_lab_quality_controls

```text
Relationship:
users 1 -> many trx_lab_quality_controls

Foreign Key:
trx_lab_quality_controls.qc_by -> users.id
```

Meaning:

```text
One QC Inspector can perform many QC reviews.
```

### trx_lab_quality_controls -> trx_lab_qc_checklists

```text
Relationship:
trx_lab_quality_controls 1 -> many trx_lab_qc_checklists

Foreign Key:
trx_lab_qc_checklists.qc_id -> trx_lab_quality_controls.id
```

Meaning:

```text
One QC review has many checklist item results.
```

### trx_lab_quality_controls -> trx_lab_remake_requests

```text
Relationship:
trx_lab_quality_controls 1 -> many trx_lab_remake_requests

Foreign Key:
trx_lab_remake_requests.qc_id -> trx_lab_quality_controls.id
```

Meaning:

```text
One QC review may create one or more remake request records when result is
REJECTED or REVISION.
```

### trx_lab_orders -> trx_lab_remake_requests

```text
Relationship:
trx_lab_orders 1 -> many trx_lab_remake_requests

Foreign Key:
trx_lab_remake_requests.lab_order_id -> trx_lab_orders.id
```

Meaning:

```text
One Lab Order can have many remake requests across repeated QC cycles.
```

### users -> trx_lab_remake_requests

```text
Relationship:
users 1 -> many trx_lab_remake_requests

Foreign Key:
trx_lab_remake_requests.requested_by -> users.id
```

Meaning:

```text
One QC Inspector or authorized Admin Lab user can request many remakes.
```

### trx_lab_quality_controls -> sys_attachments

```text
Relationship:
trx_lab_quality_controls polymorphic -> many sys_attachments

Polymorphic fields:
sys_attachments.entity_type = trx_lab_quality_controls
sys_attachments.entity_id   = trx_lab_quality_controls.id
```

Meaning:

```text
QC evidence such as photos, rejection proof, reference images, and QC documents
are stored through sys_attachments.
```

### trx_lab_orders -> sys_attachments for QC evidence

```text
Relationship:
trx_lab_orders polymorphic -> many sys_attachments

Polymorphic fields:
sys_attachments.entity_type = trx_lab_orders
sys_attachments.entity_id   = trx_lab_orders.id
```

Meaning:

```text
Order-level QC evidence can also be attached directly to Lab Order when the file
needs to be visible across Production, QC, and future Delivery handoff.
```

## 15.6 Recommended Entity Fields

### trx_lab_quality_controls

```text
id
lab_order_id
qc_by
qc_date
started_at
completed_at
result
notes
created_at
updated_at
```

Allowed QC result:

```text
PASSED
REJECTED
REVISION
```

### trx_lab_qc_checklists

```text
id
qc_id
checklist_item
result
notes
checked_by
checked_at
created_at
updated_at
```

Allowed checklist result:

```text
PASS
FAIL
N/A
```

### trx_lab_remake_requests

```text
id
lab_order_id
qc_id
reason
notes
requested_by
requested_at
assigned_technician_id
status
created_at
updated_at
deleted_at
```

Recommended remake request status:

```text
OPEN
ASSIGNED
IN_PRODUCTION
RETURNED_TO_QC
CLOSED
```

## 15.7 QC Evidence Categories

QC evidence uses:

```text
sys_attachments
```

Recommended `category` values:

```text
QC_PHOTO
QC_REJECTION_PROOF
QC_REFERENCE_IMAGE
QC_NOTE
QC_DOCUMENT
```

Rules:

```text
1. Do not create trx_lab_qc_photos in V1.
2. Store file path only in sys_attachments.
3. Attach decision-specific evidence to trx_lab_quality_controls.
4. Attach general order evidence to trx_lab_orders.
5. Upload and delete actions must be audited.
```

## 15.8 Status Flow Notes

Sprint 5 status flow:

```text
QC_PENDING
   |
   v
QC REVIEW
   |
   |-- PASSED   -> QC_PASSED
   |
   |-- REJECTED -> REMAKE
   |
   |-- REVISION -> REMAKE
```

Important distinction:

```text
QC Result:
PASSED, REJECTED, REVISION

Lab Order Status:
QC_PASSED, REMAKE
```

Rules:

```text
1. QC REVIEW is an operational activity, not a Lab Order status.
2. PASSED, REJECTED, and REVISION are QC result values.
3. QC_PASSED and REMAKE are Lab Order statuses.
4. REVISION must not be used as Lab Order status.
5. REMAKE is used for both REJECTED and REVISION QC results.
```

## 15.9 Remake Flow Notes

Remake flow:

```text
QC_PENDING
-> REMAKE
-> ASSIGNED
-> IN_PRODUCTION
-> QC_PENDING
```

Rules:

```text
1. Remake preserves QC history.
2. Remake creates a trx_lab_remake_requests record.
3. Remake does not delete original production data.
4. Remake does not delete original assignment records.
5. Remake does not delete original work logs.
6. Remake reuses Sprint 4 Production Workflow.
7. Returning to QC creates another QC review cycle.
```

## 15.10 Foreign Key Additions

```text
trx_lab_qc_checklists.qc_id       -> trx_lab_quality_controls.id
trx_lab_qc_checklists.checked_by  -> users.id

trx_lab_remake_requests.lab_order_id -> trx_lab_orders.id
trx_lab_remake_requests.qc_id        -> trx_lab_quality_controls.id
trx_lab_remake_requests.requested_by -> users.id
```

Optional reference:

```text
trx_lab_remake_requests.assigned_technician_id -> mst_technicians.id
```

## 15.11 Index Additions

```text
trx_lab_qc_checklists.qc_id INDEX
trx_lab_qc_checklists.result INDEX
trx_lab_qc_checklists.checked_by INDEX

trx_lab_remake_requests.lab_order_id INDEX
trx_lab_remake_requests.qc_id INDEX
trx_lab_remake_requests.requested_by INDEX
trx_lab_remake_requests.status INDEX
trx_lab_remake_requests.requested_at INDEX

sys_attachments.entity_type INDEX
sys_attachments.entity_id INDEX
sys_attachments.category INDEX
```

## 15.12 Migration Dependency Order Additions

Sprint 5 entities should be created after `trx_lab_quality_controls` and before
Delivery entities:

```text
17. trx_lab_quality_controls
18. trx_lab_qc_checklists
19. trx_lab_remake_requests
20. trx_lab_deliveries
```

`sys_attachments` and `sys_audit_logs` remain shared system tables.

## 15.13 Sprint 5 ERD Decisions

```text
1. QC review is stored in trx_lab_quality_controls.
2. QC checklist items are stored in trx_lab_qc_checklists.
3. QC evidence reuses sys_attachments.
4. trx_lab_qc_photos is not used in V1 unless later required.
5. QC result and Lab Order status are separate concepts.
6. PASSED, REJECTED, and REVISION are QC results.
7. QC_PASSED and REMAKE are Lab Order statuses.
8. Remake requests are stored in trx_lab_remake_requests.
9. Remake preserves original QC, production, and order history.
10. Remake reuses Sprint 4 Production Workflow.
11. Delivery is not part of Sprint 5.
12. Invoice is not part of Sprint 5.
13. Payment is not part of Sprint 5.
14. Reporting is not part of Sprint 5.
```

## 15.14 Sprint 5 ERD Validation Checklist

```text
- QC review entity present
- QC checklist entity present
- Remake request entity present
- QC evidence uses sys_attachments
- QC photos table excluded
- QC result vs Lab Order status documented
- Remake flow documented
- Mermaid diagram updated
- Existing Sprint 1-4 relationships preserved
- Delivery excluded
- Invoice excluded
- Payment excluded
- Reporting excluded
```

---

# 16. Sprint 6 ERD - Delivery & Proof of Delivery

Bagian ini memperluas ERD untuk **Sprint 6 - Delivery & Proof of
Delivery (POD)**. Sprint 6 dimulai setelah Sprint 5 menghasilkan Lab Order
berstatus `QC_PASSED`, lalu mengaktifkan delivery queue, courier assignment,
delivery tracking, POD, receiver data, delivery evidence, dan delivery
history.

Sprint 6 tidak memodelkan Invoice, Payment, atau Reporting dashboard.
Status `COMPLETED` hanya menjadi gate data yang bersih untuk sprint
berikutnya.

## 16.1 Tabel dalam Scope Sprint 6

```text
trx_lab_deliveries
sys_attachments
sys_audit_logs
trx_lab_order_status_logs
users
trx_lab_orders
```

`trx_lab_deliveries` menjadi entity utama Sprint 6.

Purpose:

```text
Stores delivery records, courier assignment, delivery status, receiver data,
Proof of Delivery information, and delivery history for Lab Orders.
```

`sys_attachments`, `sys_audit_logs`, dan `trx_lab_order_status_logs` sudah
disiapkan di sprint sebelumnya dan digunakan ulang pada Sprint 6.

## 16.2 High-Level ERD Update

```text
trx_lab_orders
|-- trx_lab_deliveries
|-- sys_attachments (order-level delivery evidence via polymorphic)
`-- sys_audit_logs

trx_lab_deliveries
|-- sys_attachments (POD evidence via polymorphic)
`-- sys_audit_logs (delivery audit via polymorphic)
```

Catatan:

```text
1. trx_lab_orders already has polymorphic attachment support through sys_attachments.
2. New Sprint 6 POD evidence should attach primarily to trx_lab_deliveries.
3. Order-level delivery evidence remains allowed when evidence belongs to the whole Lab Order.
```

## 16.3 Required Relationships

```text
trx_lab_orders 1 -> many trx_lab_deliveries
users          1 -> many trx_lab_deliveries as created_by
users          1 -> many trx_lab_deliveries as courier
trx_lab_deliveries polymorphic -> many sys_attachments as delivery evidence
trx_lab_deliveries polymorphic -> many sys_audit_logs as audit trail
trx_lab_orders      polymorphic -> many sys_attachments as order-level evidence
trx_lab_orders      polymorphic -> many sys_audit_logs as order audit trail
trx_lab_orders 1 -> many trx_lab_order_status_logs
```

## 16.4 Mermaid ERD - Sprint 6

Diagram berikut adalah extension untuk digabungkan dengan ERD utama tanpa
menghapus relasi Sprint 1 sampai Sprint 5.

```mermaid
erDiagram
    trx_lab_orders ||--o{ trx_lab_deliveries : has_deliveries
    users ||--o{ trx_lab_deliveries : creates
    users ||--o{ trx_lab_deliveries : delivers
    trx_lab_deliveries ||--o{ sys_attachments : has_pod_evidence
    trx_lab_deliveries ||--o{ sys_audit_logs : audited_by
```

## 16.5 Entity Relationship Details

### trx_lab_orders -> trx_lab_deliveries

```text
Relationship:
trx_lab_orders 1 -> many trx_lab_deliveries

Foreign key:
trx_lab_deliveries.lab_order_id -> trx_lab_orders.id
```

Reason:

```text
One Lab Order can have many delivery records over time.
```

Examples:

```text
1. Normal one-time delivery.
2. Rescheduled delivery after failed delivery attempt.
3. Replacement delivery after operational correction.
```

### users -> trx_lab_deliveries as created_by

```text
Relationship:
users 1 -> many trx_lab_deliveries

Foreign key:
trx_lab_deliveries.created_by -> users.id
```

Reason:

```text
Admin Lab or Delivery Coordinator creates the delivery record.
```

### users -> trx_lab_deliveries as courier

```text
Relationship:
users 1 -> many trx_lab_deliveries

Foreign key:
trx_lab_deliveries.courier_id -> users.id
```

Reason:

```text
Courier is represented by users for V1.
Separate courier master table is not required for small-to-medium lab delivery operations.
```

### trx_lab_deliveries -> sys_attachments

```text
Relationship:
trx_lab_deliveries polymorphic -> many sys_attachments

Polymorphic keys:
sys_attachments.entity_type = trx_lab_deliveries
sys_attachments.entity_id   = trx_lab_deliveries.id
```

Reason:

```text
Delivery evidence, receiver signature, receiver photo, and supporting POD files
reuse Sprint 3 polymorphic attachment strategy.
```

### trx_lab_deliveries -> sys_audit_logs

```text
Relationship:
trx_lab_deliveries polymorphic -> many sys_audit_logs

Polymorphic keys:
sys_audit_logs.entity_type = trx_lab_deliveries
sys_audit_logs.entity_id   = trx_lab_deliveries.id
```

Reason:

```text
Every delivery action must be auditable without creating a delivery-specific
audit table.
```

## 16.6 POD Data

Required POD data:

```text
receiver_name
receiver_signature_path
receiver_photo_path
received_at
delivery_notes
```

Storage guidance:

```text
1. receiver_name is stored on trx_lab_deliveries.
2. receiver_signature_path is stored as a file path, not binary data.
3. receiver_photo_path is stored as a file path or represented by sys_attachments.
4. received_at stores the timestamp when the receiver accepts the delivery.
5. delivery_notes stores handoff notes, exception notes, or receiver context.
```

Schema compatibility notes:

```text
1. Existing schema may name the signature path as signature_file_path.
2. Existing schema may name the received timestamp as delivered_at.
3. Existing schema may name delivery_notes as notes.
4. ERD semantics remain POD receiver signature, receiver photo, received timestamp, and delivery notes.
```

POD completion rule:

```text
POD data and evidence must be complete before the Lab Order can be completed.
```

## 16.7 Delivery Evidence Categories

Sprint 6 delivery evidence reuses:

```text
sys_attachments
```

Recommended `category` values:

```text
DELIVERY_PHOTO
POD_SIGNATURE
POD_RECEIVER_PHOTO
DELIVERY_EVIDENCE
```

Preferred target:

```text
entity_type = trx_lab_deliveries
entity_id   = delivery_id
```

Order-level fallback:

```text
entity_type = trx_lab_orders
entity_id   = lab_order_id
```

Rules:

```text
1. Do not create a new POD attachment system.
2. Do not add trx_lab_delivery_photos for V1 Sprint 6 evidence.
3. Existing trx_lab_delivery_photos references may remain for backward schema compatibility.
4. New Sprint 6 POD evidence should use sys_attachments.
5. Attachment upload, replacement, and soft delete must be audited.
```

## 16.8 Status Flow Notes

Sprint 6 Lab Order status flow:

```text
QC_PASSED
-> READY_FOR_DELIVERY
-> IN_DELIVERY
-> DELIVERED
-> COMPLETED
```

Status ownership:

```text
1. QC_PASSED comes from Sprint 5.
2. READY_FOR_DELIVERY is activated in Sprint 6.
3. IN_DELIVERY is activated in Sprint 6.
4. DELIVERED is activated in Sprint 6.
5. COMPLETED is activated in Sprint 6 as delivery workflow closure.
```

Rules:

```text
1. Delivery can start only after QC_PASSED.
2. READY_FOR_DELIVERY requires a delivery record.
3. IN_DELIVERY requires a courier.
4. DELIVERED requires complete POD data and evidence.
5. COMPLETED requires delivery/POD review to be complete.
6. Every status transition must create trx_lab_order_status_logs.
7. Invoice is not created in Sprint 6.
8. Payment is not created in Sprint 6.
9. Reporting dashboard is not implemented in Sprint 6.
```

## 16.9 Foreign Key Additions

```text
trx_lab_deliveries.lab_order_id -> trx_lab_orders.id
trx_lab_deliveries.courier_id   -> users.id
trx_lab_deliveries.created_by   -> users.id
```

Optional existing relationship:

```text
trx_lab_deliveries.clinic_id -> mst_clinics.id
```

Polymorphic references:

```text
sys_attachments.entity_type / entity_id -> trx_lab_deliveries
sys_audit_logs.entity_type / entity_id  -> trx_lab_deliveries
```

## 16.10 Index Additions

Recommended indexes:

```text
trx_lab_deliveries.lab_order_id INDEX
trx_lab_deliveries.courier_id INDEX
trx_lab_deliveries.created_by INDEX
trx_lab_deliveries.status INDEX
trx_lab_deliveries.delivery_date INDEX
trx_lab_deliveries.received_at INDEX
sys_attachments.entity_type INDEX
sys_attachments.entity_id INDEX
sys_attachments.category INDEX
sys_audit_logs.entity_type INDEX
sys_audit_logs.entity_id INDEX
```

If existing physical schema uses `delivered_at` instead of `received_at`, index
the physical timestamp column used for delivery completion queries.

## 16.11 Sprint 6 ERD Decisions

```text
1. Delivery records are stored in trx_lab_deliveries.
2. One Lab Order can have many delivery records over time.
3. Courier is represented by users for V1.
4. Separate courier master table is not required for V1.
5. POD evidence reuses sys_attachments.
6. trx_lab_delivery_photos is not used in V1 Sprint 6 evidence flow.
7. Receiver signature is stored as a file path, not binary data.
8. Receiver photo is stored as a file path, not binary data.
9. Delivery audit uses sys_audit_logs.
10. Delivery status changes use trx_lab_order_status_logs.
11. Invoice is not part of Sprint 6.
12. Payment is not part of Sprint 6.
13. Reporting is not part of Sprint 6.
```

## 16.12 Sprint 6 ERD Validation Checklist

```text
- Delivery entity present
- Delivery relationships documented
- Courier relationship documented
- POD fields documented
- POD evidence uses sys_attachments
- Delivery photo table excluded from V1 Sprint 6 evidence flow
- Delivery status flow documented
- Mermaid diagram updated
- Existing Sprint 1-5 relationships preserved
- Invoice excluded
- Payment excluded
- Reporting excluded
```

---

# 17. Sprint 7 - Invoice & Payment ERD

## Sprint 7 — Invoice & Payment ERD

Sprint 7 menambahkan model invoice dan payment setelah Lab Order selesai.
Desain aktif Sprint 7 mengganti batasan lama one-order-one-invoice dengan
invoice header dan invoice item.

Business model:

```text
Completed Lab Orders
-> Invoice Items
-> Invoice Header
-> Payments
```

Rules utama:

```text
1. Invoice dibuat dari satu atau lebih Lab Order dengan status COMPLETED.
2. Satu Invoice hanya boleh berisi Lab Order dari Clinic yang sama.
3. Satu Invoice belongs to one Clinic.
4. Satu Invoice has many Invoice Items.
5. Satu Invoice Item references one Lab Order.
6. Satu Invoice has many Payments.
7. Outstanding amount = total_amount - paid_amount.
8. Invoice PDF dan bukti pembayaran memakai sys_attachments polymorphic.
9. Invoice/payment audit memakai sys_audit_logs polymorphic.
10. trx_lab_order_status_logs tetap khusus untuk status Lab Order.
```

## 17.1 Entity: trx_invoices

Purpose:

```text
Menyimpan invoice header untuk tagihan Clinic. Satu invoice dapat menagihkan
beberapa Lab Order yang sudah COMPLETED dari Clinic yang sama.
```

Suggested fields:

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

Foreign keys:

```text
mst_clinics.id -> trx_invoices.clinic_id
users.id       -> trx_invoices.created_by
```

Status enum:

```text
DRAFT
ISSUED
PARTIALLY_PAID
PAID
OVERDUE
VOID
```

Notes:

```text
invoice_number must be unique.
trx_invoices does not store lab_order_id in the active Sprint 7 design.
Lab Order billing link is stored in trx_invoice_items.lab_order_id.
```

## 17.2 Entity: trx_invoice_items

Purpose:

```text
Menyimpan detail tagihan per Lab Order. Tabel ini menjadi bridge agar satu
Invoice dapat memuat banyak Lab Order dari Clinic yang sama.
```

Suggested fields:

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

Foreign keys:

```text
trx_invoices.id   -> trx_invoice_items.invoice_id
trx_lab_orders.id -> trx_invoice_items.lab_order_id
```

Notes:

```text
One invoice item belongs to one invoice.
One invoice item references one Lab Order.
One Lab Order should not be billed more than once unless the previous invoice is VOID.
```

## 17.3 Entity: trx_payments

Purpose:

```text
Mencatat pembayaran yang diterima untuk Invoice. Satu Invoice dapat memiliki
banyak Payment sampai total pembayaran mencapai total invoice.
```

Suggested fields:

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

Foreign keys:

```text
trx_invoices.id -> trx_payments.invoice_id
users.id        -> trx_payments.received_by
users.id        -> trx_payments.created_by
```

Payment method enum:

```text
CASH
BANK_TRANSFER
QRIS
CARD
OTHER
```

Notes:

```text
payment_number must be unique.
Payment amount must be greater than 0.
Payment cannot be recorded for VOID invoices.
Total paid amount cannot exceed invoice total_amount.
```

## 17.4 Required Relationships

Relationship text:

```text
mst_clinics  1 -> many trx_invoices
trx_invoices many -> 1 mst_clinics
users        1 -> many trx_invoices as created_by

trx_invoices     1 -> many trx_invoice_items
trx_invoice_items many -> 1 trx_invoices
trx_lab_orders   1 -> many trx_invoice_items
trx_invoice_items many -> 1 trx_lab_orders

trx_invoices 1 -> many trx_payments
trx_payments many -> 1 trx_invoices
users        1 -> many trx_payments as received_by
users        1 -> many trx_payments as created_by

trx_invoices polymorphic -> many sys_attachments as invoice files
trx_payments polymorphic -> many sys_attachments as payment proof
trx_invoices polymorphic -> many sys_audit_logs as invoice audit trail
trx_payments polymorphic -> many sys_audit_logs as payment audit trail
```

Implementation relationship mapping:

```text
Clinic hasMany Invoice
Invoice belongsTo Clinic
Invoice belongsTo User as created_by
Invoice hasMany InvoiceItem
Invoice hasMany Payment
Invoice morphMany Attachment
Invoice morphMany AuditLog

InvoiceItem belongsTo Invoice
InvoiceItem belongsTo LabOrder

LabOrder hasMany InvoiceItem

Payment belongsTo Invoice
Payment belongsTo User as received_by
Payment belongsTo User as created_by
Payment morphMany Attachment
Payment morphMany AuditLog
```

## 17.5 Mermaid ERD - Sprint 7

Diagram berikut adalah extension Sprint 7 untuk digabungkan dengan ERD utama.
Relasi polymorphic ke attachments dan audit logs adalah logical relationship,
bukan foreign key langsung.

```mermaid
erDiagram
    mst_clinics ||--o{ trx_invoices : has_invoices
    users ||--o{ trx_invoices : creates

    trx_invoices ||--o{ trx_invoice_items : has_items
    trx_lab_orders ||--o{ trx_invoice_items : billed_as_items

    trx_invoices ||--o{ trx_payments : has_payments
    users ||--o{ trx_payments : receives
    users ||--o{ trx_payments : creates

    trx_invoices ||--o{ sys_attachments : has_invoice_files
    trx_payments ||--o{ sys_attachments : has_payment_proof
    trx_invoices ||--o{ sys_audit_logs : audited_by
    trx_payments ||--o{ sys_audit_logs : audited_by
```

## 17.6 Cardinality Notes

```text
1. One Clinic can have many Invoices.
2. One Invoice belongs to exactly one Clinic.
3. One Invoice can have many Invoice Items.
4. One Invoice Item belongs to exactly one Invoice.
5. One Invoice Item references exactly one Lab Order.
6. One Lab Order can appear in invoice items only once for active/non-void invoices.
7. One Invoice can have many Payments.
8. One Payment belongs to exactly one Invoice.
9. One Invoice can have many attachments, such as invoice PDF.
10. One Payment can have many attachments, such as payment proof.
```

## 17.7 Data Integrity Notes

```text
invoice_number must be unique.
payment_number must be unique.
invoice_items.lab_order_id should be unique for active/non-void invoices.
payment.amount must be greater than 0.
total paid amount must not exceed invoice total_amount.
outstanding_amount should equal total_amount - paid_amount.
invoice cannot be generated from non-COMPLETED Lab Orders.
invoice cannot mix Lab Orders from different Clinics.
payment cannot be recorded for VOID invoice.
invoice cannot be voided if payments already exist.
Lab Order status logs remain in trx_lab_order_status_logs.
Invoice and Payment activity is recorded in sys_audit_logs.
```

Suggested indexes:

```text
trx_invoices.invoice_number UNIQUE
trx_invoices.clinic_id INDEX
trx_invoices.status INDEX
trx_invoices.invoice_date INDEX
trx_invoices.due_date INDEX

trx_invoice_items.invoice_id INDEX
trx_invoice_items.lab_order_id INDEX

trx_payments.payment_number UNIQUE
trx_payments.invoice_id INDEX
trx_payments.payment_date INDEX
trx_payments.payment_method INDEX
trx_payments.received_by INDEX
trx_payments.created_by INDEX
```

## 17.8 Attachment and Audit Reuse

Attachment usage:

```text
sys_attachments.entity_type / entity_id -> trx_invoices
sys_attachments.entity_type / entity_id -> trx_payments
```

Audit usage:

```text
sys_audit_logs.entity_type / entity_id -> trx_invoices
sys_audit_logs.entity_type / entity_id -> trx_payments
```

Important separation:

```text
trx_lab_order_status_logs records Lab Order status changes only.
Invoice status and Payment actions use sys_audit_logs, not Lab Order status logs.
```

## 17.9 Out Of Scope - Sprint 7 ERD

The following entities are not part of Sprint 7 ERD:

```text
accounting ledger
tax reporting tables
external payment gateway tables
refund handling
invoice email history
WhatsApp notification logs
reporting aggregation tables
payment allocation table
```

Payment allocation table is not required because each payment is recorded
against a single invoice in Sprint 7.

## 17.10 Sprint 7 ERD Validation Checklist

```text
- trx_invoices entity documented
- trx_invoice_items entity documented
- trx_payments entity documented
- One invoice can contain multiple Lab Orders from the same Clinic
- Lab Order billing link uses trx_invoice_items.lab_order_id
- Invoice belongs to Clinic
- Invoice has many Invoice Items
- Invoice has many Payments
- Payment belongs to Invoice
- Invoice and Payment attachments reuse sys_attachments
- Invoice and Payment audit reuse sys_audit_logs
- Lab Order status logs remain separate
- One-order-one-invoice rule marked as superseded for Sprint 7
- Accounting ledger excluded
- Refund handling excluded
- External payment gateway excluded
```

---

# 18. Sprint 8 - Reporting & Dashboard ERD

## Sprint 8 — Reporting & Dashboard ERD

Sprint 8 Reporting & Dashboard adalah logical read-only module. Sprint ini
tidak membutuhkan tabel fisik baru karena seluruh report membaca data
operasional dan finansial dari Sprint 0 sampai Sprint 7.

Core decisions:

```text
1. No new physical database tables are required for Sprint 8.
2. Reporting is a logical read-only module.
3. Reporting reads existing operational and financial data.
4. Reporting must not mutate source data.
5. Reporting must not duplicate data into summary tables in Sprint 8.
```

Reporting tidak memiliki foreign key ke source tables karena tidak ada table
`reports` atau table summary yang dibuat pada Sprint 8. Semua relationship pada
section ini adalah logical read relationships.

## 18.1 Data Sources

Operational data sources:

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

Production data sources:

```text
trx_lab_order_assignments
trx_lab_work_logs
trx_lab_production_steps
```

Quality Control data sources:

```text
trx_lab_quality_controls
trx_lab_qc_checklists
trx_lab_remake_requests
sys_attachments for QC evidence
```

Delivery data sources:

```text
trx_lab_deliveries
sys_attachments for delivery/POD evidence
```

Financial data sources:

```text
trx_invoices
trx_invoice_items
trx_payments
```

Audit data sources:

```text
sys_audit_logs
trx_lab_order_status_logs for Lab Order status history
```

## 18.2 Logical Relationship Notes

Reporting module logical reads:

```text
Reporting Module
├── reads users
├── reads mst_clinics / clinics
├── reads mst_doctors / doctors
├── reads mst_patients / patients
├── reads mst_technicians / technicians
├── reads mst_lab_services / lab_services
├── reads trx_lab_orders
├── reads trx_lab_order_items
├── reads trx_lab_order_assignments
├── reads trx_lab_work_logs
├── reads trx_lab_production_steps
├── reads trx_lab_quality_controls
├── reads trx_lab_qc_checklists
├── reads trx_lab_remake_requests
├── reads trx_lab_deliveries
├── reads trx_invoices
├── reads trx_invoice_items
├── reads trx_payments
├── reads sys_audit_logs if audit-based reports are needed
└── reads trx_lab_order_status_logs for status timeline reports
```

ERD interpretation:

```text
Reporting has no ownership relationship with source tables.
Reporting reads many operational records.
Reporting reads many financial records.
Reporting does not own Lab Orders, Deliveries, Invoices, or Payments.
Reporting does not create child records.
Reporting does not change business statuses.
```

## 18.3 Report To Source Mapping

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
sys_attachments if evidence count is needed
```

Delivery Reports:

```text
trx_lab_deliveries
users as courier
mst_clinics
trx_lab_orders
sys_attachments if POD/evidence count is needed
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

## 18.4 Logical Mermaid ERD

Diagram berikut menunjukkan logical read dependencies. Ini bukan physical FK
diagram dan tidak menambah tabel Sprint 8.

```mermaid
flowchart TD
    Reporting[Reporting Module]

    Reporting -. reads .-> Users[users]
    Reporting -. reads .-> Clinics[mst_clinics]
    Reporting -. reads .-> Doctors[mst_doctors]
    Reporting -. reads .-> Patients[mst_patients]
    Reporting -. reads .-> Technicians[mst_technicians]
    Reporting -. reads .-> Services[mst_lab_services]
    Reporting -. reads .-> Orders[trx_lab_orders]
    Reporting -. reads .-> OrderItems[trx_lab_order_items]
    Reporting -. reads .-> StatusLogs[trx_lab_order_status_logs]
    Reporting -. reads .-> Assignments[trx_lab_order_assignments]
    Reporting -. reads .-> WorkLogs[trx_lab_work_logs]
    Reporting -. reads .-> ProductionSteps[trx_lab_production_steps]
    Reporting -. reads .-> QC[trx_lab_quality_controls]
    Reporting -. reads .-> QCChecklist[trx_lab_qc_checklists]
    Reporting -. reads .-> Remake[trx_lab_remake_requests]
    Reporting -. reads .-> Delivery[trx_lab_deliveries]
    Reporting -. reads .-> Invoices[trx_invoices]
    Reporting -. reads .-> InvoiceItems[trx_invoice_items]
    Reporting -. reads .-> Payments[trx_payments]
    Reporting -. reads .-> Attachments[sys_attachments]
    Reporting -. reads .-> Audit[sys_audit_logs]
```

## 18.5 Schema Decision Notes

```text
No new tables for Sprint 8.
No materialized views in Sprint 8.
No aggregate/cache tables in Sprint 8.
No reporting-specific audit tables.
No reporting-specific attachment tables.
CSV export can be generated directly from filtered queries.
Excel export can be added only if implementation uses an existing package or simple fallback.
```

Forbidden Sprint 8 reporting tables:

```text
reporting_summary
report_snapshots
reporting_audit_logs
reporting_attachments
report_cache
report_exports
```

## 18.6 Permission Notes

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
Finance: financial reports, dashboard, export
Lab Manager: operational reports, dashboard, export
Lab Staff: limited operational reports
Courier: no reporting access by default
```

Permission design notes:

```text
1. manage_report can be used as broad reporting override.
2. export_report should be separate from view permissions.
3. Financial report permissions should be separate from operational report permissions.
```

## 18.7 Data Integrity Notes

```text
Reports must use source-of-truth tables.
VOID invoices should be excluded from revenue unless explicitly shown.
Outstanding amount should come from trx_invoices.outstanding_amount.
Payment totals should come from trx_payments.
Delivery reports should come from trx_lab_deliveries.
QC reports should come from QC tables.
Date filters should be inclusive and timezone-safe.
Reports must handle empty result sets safely.
Soft-deleted records should follow the source module's default visibility rules.
Reporting must not update operational or financial status columns.
```

## 18.8 Out Of Scope - Sprint 8 ERD

Do not add ERD tables for:

```text
reporting_summary
report_snapshots
data warehouse
BI star schema
fact tables
dimension tables
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

## 18.9 Sprint 8 ERD Validation Checklist

```text
- Sprint 8 Reporting & Dashboard ERD section added
- No new physical database tables required
- Reporting documented as logical read-only module
- Operational data sources documented
- Production data sources use existing Sprint 4 table names
- QC data sources documented
- Delivery data sources documented
- Financial data sources documented
- Audit/status data sources documented
- Report-to-source mapping documented
- Permissions documented
- Data integrity notes documented
- Reporting summary tables excluded
- Reporting audit tables excluded
- Reporting attachment tables excluded
- Source data mutation excluded
```

---

# 19. V2 ERD Candidates

Fitur berikut tidak masuk ERD V1:

```text
mst_materials
mst_shade_colors
sys_notifications
inventory_material_lab
whatsapp_notifications
gps_tracking
customer_portal
mobile_app
```
