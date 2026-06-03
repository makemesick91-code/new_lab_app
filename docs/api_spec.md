# API_SPEC.md

# Asia Dental Lab Management System

Version: V1
Backend: Laravel 12
Frontend: Blade + Livewire
API Prefix: `/api/v1`
Auth: Laravel Breeze / Session Auth for web, API-ready for future integration

---

# 1. API Purpose

Dokumen ini mendefinisikan endpoint API untuk Asia Dental Lab Management System V1.

API digunakan untuk:

```text
Future Mobile App
Future Customer Portal
Future Integrations
Internal Service Layer Reference
```

Walaupun V1 menggunakan Blade + Livewire, struktur API tetap disiapkan agar aplikasi mudah dikembangkan ke tahap berikutnya.

---

# 2. Base URL

Local:

```http
http://localhost:8000/api/v1
```

Staging:

```http
https://staging.asia-dental-lab.com/api/v1
```

Production:

```http
https://app.asia-dental-lab.com/api/v1
```

---

# 3. Authentication

V1 menggunakan session-based auth untuk web.

Untuk API future-ready:

```text
Header:
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

---

# 4. Standard Response

## Success Response

```json
{
  "success": true,
  "message": "Data berhasil diproses",
  "data": {}
}
```

---

## Error Response

```json
{
  "success": false,
  "message": "Terjadi kesalahan",
  "errors": {}
}
```

---

## Validation Error

```json
{
  "success": false,
  "message": "Validasi gagal",
  "errors": {
    "receiver_name": [
      "Nama penerima wajib diisi"
    ]
  }
}
```

---

## Pagination Response

```json
{
  "success": true,
  "message": "Data berhasil diambil",
  "data": [],
  "meta": {
    "current_page": 1,
    "per_page": 10,
    "total": 100,
    "last_page": 10
  }
}
```

---

# 5. HTTP Status Code

| Code | Meaning          |
| ---- | ---------------- |
| 200  | Success          |
| 201  | Created          |
| 400  | Bad Request      |
| 401  | Unauthorized     |
| 403  | Forbidden        |
| 404  | Not Found        |
| 422  | Validation Error |
| 500  | Server Error     |

---

# 6. Common Query Parameters

## Pagination

```http
?page=1&per_page=10
```

## Search

```http
?search=keyword
```

## Status Filter

```http
?status=RECEIVED
```

## Date Filter

```http
?start_date=2026-06-01&end_date=2026-06-30
```

---

# 7. Auth API

## Login

```http
POST /api/v1/login
```

Request:

```json
{
  "email": "admin@asiadentallab.com",
  "password": "password"
}
```

Response:

```json
{
  "success": true,
  "message": "Login berhasil",
  "data": {
    "user": {
      "id": 1,
      "name": "Super Admin",
      "email": "admin@asiadentallab.com"
    },
    "token": "optional-api-token"
  }
}
```

---

## Logout

```http
POST /api/v1/logout
```

---

## Current User

```http
GET /api/v1/me
```

---

# 8. User & Role API

## Users

```http
GET    /api/v1/users
POST   /api/v1/users
GET    /api/v1/users/{id}
PUT    /api/v1/users/{id}
DELETE /api/v1/users/{id}
```

Create User Request:

```json
{
  "name": "Admin Lab",
  "email": "adminlab@asiadentallab.com",
  "password": "password",
  "phone": "081234567890",
  "role": "Admin Lab"
}
```

---

## Roles

```http
GET    /api/v1/roles
POST   /api/v1/roles
GET    /api/v1/roles/{id}
PUT    /api/v1/roles/{id}
DELETE /api/v1/roles/{id}
```

Create Role Request:

```json
{
  "name": "Courier"
}
```

---

## Permissions

```http
GET /api/v1/permissions
```

---

## Assign Role

```http
POST /api/v1/users/{id}/assign-role
```

Request:

```json
{
  "role": "Courier"
}
```

---

# 9. Master Data API

## Clinics

```http
GET    /api/v1/clinics
POST   /api/v1/clinics
GET    /api/v1/clinics/{id}
PUT    /api/v1/clinics/{id}
DELETE /api/v1/clinics/{id}
```

Request:

```json
{
  "code": "KLINIK-001",
  "name": "Asia Dental Makassar",
  "phone": "0411123456",
  "email": "makassar@asiadental.com",
  "address": "Makassar",
  "is_active": true
}
```

---

## Doctors

```http
GET    /api/v1/doctors
POST   /api/v1/doctors
GET    /api/v1/doctors/{id}
PUT    /api/v1/doctors/{id}
DELETE /api/v1/doctors/{id}
```

Request:

```json
{
  "clinic_id": 1,
  "name": "drg. Andi",
  "phone": "081234567890",
  "email": "andi@asiadental.com",
  "is_active": true
}
```

---

## Patients

```http
GET    /api/v1/patients
POST   /api/v1/patients
GET    /api/v1/patients/{id}
PUT    /api/v1/patients/{id}
DELETE /api/v1/patients/{id}
```

Request:

```json
{
  "patient_code": "P-000001",
  "name": "Budi Santoso",
  "gender": "MALE",
  "birth_date": "1990-01-01",
  "phone": "081234567890",
  "address": "Makassar"
}
```

---

## Lab Services

```http
GET    /api/v1/lab-services
POST   /api/v1/lab-services
GET    /api/v1/lab-services/{id}
PUT    /api/v1/lab-services/{id}
DELETE /api/v1/lab-services/{id}
```

Request:

```json
{
  "code": "ZIR-001",
  "name": "Crown Zirconia",
  "category": "Crown",
  "estimated_days": 4,
  "base_price": 1500000,
  "is_active": true
}
```

---

## Technicians

```http
GET    /api/v1/technicians
POST   /api/v1/technicians
GET    /api/v1/technicians/{id}
PUT    /api/v1/technicians/{id}
DELETE /api/v1/technicians/{id}
```

Request:

```json
{
  "user_id": 5,
  "technician_code": "TECH-001",
  "name": "Teknisi Crown",
  "phone": "081234567890",
  "skill_category": "Crown",
  "is_active": true
}
```

---

# 10. Lab Order API

## List Lab Orders

```http
GET /api/v1/lab-orders
```

Supported filters:

```http
?search=ADL-2026-000001
?status=RECEIVED
?clinic_id=1
?doctor_id=1
?start_date=2026-06-01
?end_date=2026-06-30
?page=1
?per_page=10
```

---

## Create Lab Order

```http
POST /api/v1/lab-orders
```

Request:

```json
{
  "clinic_id": 1,
  "doctor_id": 1,
  "patient_id": 1,
  "order_date": "2026-06-03",
  "due_date": "2026-06-07",
  "priority": "NORMAL",
  "notes": "Kasus crown zirconia",
  "items": [
    {
      "lab_service_id": 1,
      "tooth_number": "11",
      "shade_color_text": "A2",
      "material_text": "Zirconia",
      "qty": 1,
      "price": 1500000,
      "discount": 0,
      "notes": "Margin subgingiva"
    }
  ]
}
```

Business Rules:

```text
Order number auto generate.
Minimal 1 item.
Status awal: RECEIVED.
Status log otomatis dibuat.
```

---

## Detail Lab Order

```http
GET /api/v1/lab-orders/{id}
```

---

## Update Lab Order

```http
PUT /api/v1/lab-orders/{id}
```

---

## Delete Lab Order

```http
DELETE /api/v1/lab-orders/{id}
```

Rule:

```text
Soft delete.
Tidak boleh delete jika sudah DELIVERED atau COMPLETED.
```

---

## Cancel Lab Order

```http
POST /api/v1/lab-orders/{id}/cancel
```

Request:

```json
{
  "notes": "Order dibatalkan oleh dokter"
}
```

---

## Change Status

```http
POST /api/v1/lab-orders/{id}/change-status
```

Request:

```json
{
  "status": "IN_PROGRESS",
  "notes": "Mulai dikerjakan"
}
```

Allowed status:

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

## Upload Attachment

```http
POST /api/v1/lab-orders/{id}/attachments
```

Content-Type:

```text
multipart/form-data
```

Fields:

```text
file
description
```

Allowed file:

```text
jpg, jpeg, png, pdf
max 10 MB
```

---

## Timeline

```http
GET /api/v1/lab-orders/{id}/timeline
```

Returns:

```text
Status logs
Assignments
QC records
Delivery records
Invoice records
```

---

# 11. Assignment API

## Assign Technician

```http
POST /api/v1/lab-orders/{id}/assign-technician
```

Request:

```json
{
  "technician_id": 1,
  "notes": "Dikerjakan teknisi crown"
}
```

---

## Reassign Technician

```http
POST /api/v1/lab-orders/{id}/reassign-technician
```

Request:

```json
{
  "from_technician_id": 1,
  "to_technician_id": 2,
  "notes": "Dipindahkan karena teknisi cuti"
}
```

---

## Technician Assignments

```http
GET /api/v1/technicians/{id}/assignments
```

---

## Start Assignment

```http
POST /api/v1/assignments/{id}/start
```

---

## Complete Assignment

```http
POST /api/v1/assignments/{id}/complete
```

Request:

```json
{
  "notes": "Pekerjaan selesai"
}
```

---

# 12. Quality Control API

## QC Passed

```http
POST /api/v1/lab-orders/{id}/qc/pass
```

Request:

```json
{
  "notes": "Hasil sesuai standar"
}
```

Result:

```text
PASSED
```

Effect:

```text
Order status menjadi READY_FOR_DELIVERY.
```

---

## QC Rejected

```http
POST /api/v1/lab-orders/{id}/qc/reject
```

Request:

```json
{
  "notes": "Margin tidak sesuai"
}
```

Result:

```text
REJECTED
```

---

## QC Revision

```http
POST /api/v1/lab-orders/{id}/qc/revision
```

Request:

```json
{
  "notes": "Perlu revisi warna"
}
```

Result:

```text
REVISION
```

Effect:

```text
Order status menjadi REVISION.
```

---

## QC History

```http
GET /api/v1/lab-orders/{id}/qc-history
```

---

# 13. Delivery & POD API

## List Deliveries

```http
GET /api/v1/deliveries
```

Filters:

```http
?status=READY_FOR_DELIVERY
?courier_id=1
?clinic_id=1
?delivery_date=2026-06-03
```

---

## Create Delivery

```http
POST /api/v1/deliveries
```

Request:

```json
{
  "lab_order_id": 1,
  "courier_id": 6,
  "delivery_date": "2026-06-03",
  "notes": "Kirim sore"
}
```

Rules:

```text
Order harus READY_FOR_DELIVERY.
Delivery number auto generate.
```

---

## Detail Delivery

```http
GET /api/v1/deliveries/{id}
```

---

## Start Delivery

```http
POST /api/v1/deliveries/{id}/start
```

Effect:

```text
Status delivery menjadi IN_TRANSIT.
Order status menjadi IN_DELIVERY.
```

---

## Mark Arrived

```http
POST /api/v1/deliveries/{id}/arrive
```

Effect:

```text
Status delivery menjadi ARRIVED.
```

---

## Upload Signature

```http
POST /api/v1/deliveries/{id}/signature
```

Content-Type:

```text
multipart/form-data
```

Fields:

```text
signature_file
```

Allowed:

```text
png
jpg
jpeg
max 10 MB
```

---

## Upload Delivery Photo

```http
POST /api/v1/deliveries/{id}/photos
```

Content-Type:

```text
multipart/form-data
```

Fields:

```text
photo
photo_type
```

Photo type:

```text
PACKAGE
RECEIVED_GOODS
HANDOVER
OTHER
```

---

## Complete Delivery

```http
POST /api/v1/deliveries/{id}/complete
```

Request:

```json
{
  "receiver_name": "Siti Aminah",
  "receiver_role": "Perawat",
  "receiver_phone": "081234567890",
  "delivered_at": "2026-06-03 14:30:00",
  "notes": "Diterima dalam kondisi baik"
}
```

Rules:

```text
receiver_name wajib.
receiver_role wajib.
signature_file_path wajib.
Minimal 1 delivery photo wajib.
```

Effect:

```text
Delivery status menjadi DELIVERED.
Order status menjadi DELIVERED.
```

---

# 14. Invoice API

## List Invoices

```http
GET /api/v1/invoices
```

Filters:

```http
?status=UNPAID
?clinic_id=1
?doctor_id=1
?start_date=2026-06-01
?end_date=2026-06-30
```

---

## Create Invoice

```http
POST /api/v1/invoices
```

Request:

```json
{
  "lab_order_id": 1,
  "invoice_date": "2026-06-03",
  "due_date": "2026-06-10",
  "discount": 0,
  "tax": 0
}
```

Rules:

```text
Invoice hanya bisa dibuat setelah delivery DELIVERED.
Satu order hanya boleh memiliki satu invoice.
Invoice number auto generate.
```

---

## Detail Invoice

```http
GET /api/v1/invoices/{id}
```

---

## Void Invoice

```http
POST /api/v1/invoices/{id}/void
```

Request:

```json
{
  "notes": "Invoice salah input"
}
```

---

## Print Invoice

```http
GET /api/v1/invoices/{id}/print
```

Returns:

```text
PDF
```

---

# 15. Payment API

## List Payments

```http
GET /api/v1/payments
```

---

## Create Payment

```http
POST /api/v1/payments
```

Request:

```json
{
  "invoice_id": 1,
  "payment_date": "2026-06-03",
  "payment_method": "BANK_TRANSFER",
  "amount": 1000000,
  "reference_number": "BCA-123456",
  "notes": "DP pembayaran"
}
```

Rules:

```text
Payment boleh partial.
Payment number auto generate.
Paid amount dan outstanding otomatis dihitung.
```

---

## Detail Payment

```http
GET /api/v1/payments/{id}
```

---

# 16. Attachment API

## Upload Generic Attachment

```http
POST /api/v1/attachments
```

Content-Type:

```text
multipart/form-data
```

Fields:

```text
attachable_type
attachable_id
file
description
```

---

## Delete Attachment

```http
DELETE /api/v1/attachments/{id}
```

---

# 17. Report API

## Dashboard Summary

```http
GET /api/v1/dashboard/summary
```

Response data:

```text
total_orders
pending_qc
pending_delivery
total_revenue
outstanding_invoice
```

---

## Order Report

```http
GET /api/v1/reports/orders
```

Filters:

```http
?start_date=2026-06-01&end_date=2026-06-30&status=COMPLETED
```

---

## Delivery Report

```http
GET /api/v1/reports/deliveries
```

---

## QC Report

```http
GET /api/v1/reports/qc
```

---

## Revenue Report

```http
GET /api/v1/reports/revenue
```

---

# 18. Permission Matrix

| Module      | Super Admin | Admin Lab | Technician | QC   | Courier | Finance | Doctor |
| ----------- | ----------- | --------- | ---------- | ---- | ------- | ------- | ------ |
| Users       | Full        | View      | -          | -    | -       | -       | -      |
| Roles       | Full        | View      | -          | -    | -       | -       | -      |
| Master Data | Full        | Full      | View       | View | View    | View    | View   |
| Lab Order   | Full        | Full      | View       | View | View    | View    | View   |
| Assignment  | Full        | Full      | Update Own | View | -       | -       | -      |
| QC          | Full        | View      | View       | Full | -       | -       | View   |
| Delivery    | Full        | Full      | -          | View | Full    | View    | View   |
| Invoice     | Full        | View      | -          | -    | -       | Full    | View   |
| Payment     | Full        | -         | -          | -    | -       | Full    | -      |
| Report      | Full        | View      | -          | View | View    | Full    | View   |

---

# 19. Business Rules Summary

## Lab Order

```text
1. Order number wajib unik.
2. Order minimal memiliki 1 item.
3. Status awal order adalah RECEIVED.
4. Semua perubahan status wajib masuk status log.
```

---

## Assignment

```text
1. Order dapat diassign ke teknisi.
2. Reassign wajib tercatat.
3. Assignment wajib masuk audit log.
```

---

## Quality Control

```text
1. Order hanya bisa READY_FOR_DELIVERY jika QC PASSED.
2. QC REVISION mengubah status order menjadi REVISION.
3. QC REJECT tidak boleh langsung delivery.
```

---

## Delivery

```text
1. Delivery hanya bisa dibuat jika order READY_FOR_DELIVERY.
2. Delivery wajib memiliki courier_id.
3. Delivery tidak bisa DELIVERED tanpa POD lengkap.
4. POD wajib berisi nama penerima, jabatan, tanda tangan, dan minimal 1 foto.
```

---

## Invoice

```text
1. Invoice hanya bisa dibuat setelah delivery DELIVERED.
2. Satu order hanya boleh memiliki satu invoice.
3. Invoice dapat VOID.
```

---

## Payment

```text
1. Payment boleh partial.
2. Jika paid_amount = 0 maka status UNPAID.
3. Jika paid_amount < grand_total maka status PARTIAL.
4. Jika paid_amount >= grand_total maka status PAID.
```

---

# 20. Implementation Order

```text
1. Auth API
2. User & Role API
3. Master Data API
4. Lab Order API
5. Assignment API
6. QC API
7. Delivery & POD API
8. Invoice API
9. Payment API
10. Report API
```

---

# 21. V2 API Candidates

Endpoint berikut tidak masuk V1:

```text
WhatsApp Notification API
GPS Courier Tracking API
Customer Portal API
Mobile App API
Inventory Material API
Shade Color API
Material Master API
```
