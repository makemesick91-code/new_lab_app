# PROJECT_RULES.md

# Asia Dental Lab Management System

Version: 1.0

Status: Approved

Last Updated: June 2026

---

# 1. PURPOSE

Dokumen ini mendefinisikan standar teknis, aturan pengembangan, struktur kode, dan praktik implementasi yang wajib diikuti oleh seluruh developer, AI assistant, Cursor, Claude Code, ChatGPT, maupun contributor proyek.

Tujuan utama:

* Konsistensi kode
* Kemudahan maintenance
* Scalability
* Auditability
* Menghindari technical debt

---

# 2. PROJECT SOURCES OF TRUTH

Urutan prioritas dokumen:

```text
1. PRD.md

2. DATABASE_SCHEMA_V1.md

3. SYSTEM_ARCHITECTURE.md

4. PROJECT_RULES.md
```

Jika terjadi konflik:

```text
PRD
mengalahkan
DATABASE_SCHEMA

DATABASE_SCHEMA
mengalahkan
PROJECT_RULES
```

---

# 3. TECH STACK

## Backend

```text
Laravel 12
PHP 8.4
```

---

## Frontend

```text
Blade
Livewire 3
Alpine.js
```

---

## Database

```text
PostgreSQL 16
```

---

## Authentication

```text
Laravel Breeze
```

---

## Authorization

```text
Spatie Laravel Permission
```

---

## Queue

```text
Database Queue
```

---

## Cache

```text
File Cache
```

---

## File Storage

```text
Local Storage
```

Path:

```text
storage/app/public
```

---

# 4. ARCHITECTURE RULES

Architecture Type:

```text
Modular Monolith
```

Semua module wajib berada di:

```text
app/Modules/
```

---

# 5. MODULE STRUCTURE

Setiap module wajib memiliki struktur:

```text
ModuleName/

├── Controllers
├── Requests
├── Services
├── Repositories
├── Interfaces
├── Models
├── Resources
├── Policies
└── Tests
```

Contoh:

```text
LabOrder/

├── Controllers
├── Requests
├── Services
├── Repositories
├── Interfaces
├── Models
├── Resources
├── Policies
└── Tests
```

---

# 6. LAYER RULES

Request Flow wajib:

```text
Controller
↓
Request Validation
↓
Service
↓
Repository
↓
Model
↓
Database
```

Dilarang:

```text
Controller
↓
Model
↓
Database
```

---

# 7. CONTROLLER RULES

Controller hanya boleh:

```text
1. Menerima request

2. Memanggil service

3. Mengembalikan response
```

Controller tidak boleh:

```text
Business Logic

Complex Validation

Database Query Langsung
```

---

# 8. SERVICE RULES

Business logic wajib berada di Service Layer.

Contoh:

```text
Create Order

Assign Technician

QC Approval

Delivery

Invoice

Payment
```

---

# 9. REPOSITORY RULES

Repository hanya bertugas:

```text
CRUD

Search

Filtering

Pagination

Database Query
```

Repository tidak boleh:

```text
Business Rule
```

---

# 10. DATABASE RULES

Semua tabel transaksi wajib menggunakan:

```text
created_at
updated_at
deleted_at
```

---

Soft Delete wajib:

```text
Lab Orders

Order Items

Deliveries

Invoices

Payments
```

---

Nomor transaksi wajib unik:

```text
Order Number

Delivery Number

Invoice Number

Payment Number
```

---

Semua nominal wajib:

```text
decimal(18,2)
```

---

Semua relasi wajib memakai:

```text
Foreign Key
```

---

# 11. DATABASE TRANSACTION RULES

Wajib menggunakan:

```php
DB::transaction()
```

Untuk:

```text
Create Lab Order

Assign Technician

Update Status

QC

Delivery

Invoice

Payment
```

---

# 12. STATUS MANAGEMENT RULES

Status order resmi:

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

Status tidak boleh dibuat di luar daftar tersebut.

---

# 13. STATUS LOG RULES

Setiap perubahan status wajib masuk:

```text
trx_lab_order_status_logs
```

Data wajib:

```text
status_from

status_to

changed_by

changed_at
```

---

# 14. AUDIT LOG RULES

Semua aktivitas penting wajib dicatat.

Tabel:

```text
sys_audit_logs
```

Aktivitas:

```text
LOGIN

LOGOUT

CREATE

UPDATE

DELETE

ASSIGNMENT

STATUS_CHANGE

QC_APPROVAL

QC_REJECT

DELIVERY

POD_UPLOAD

INVOICE_CREATE

PAYMENT_CREATE
```

---

# 15. DELIVERY & POD RULES

Delivery tidak boleh menjadi:

```text
DELIVERED
```

Jika:

```text
receiver_name kosong

receiver_role kosong

signature_file_path kosong

foto penerimaan kurang dari 1
```

---

Proof Of Delivery wajib memiliki:

```text
Nama Penerima

Jabatan

Tanda Tangan

Minimal 1 Foto

Tanggal

Jam
```

---

# 16. FILE STORAGE RULES

File tidak boleh disimpan dalam database.

Database hanya menyimpan:

```text
file_path
```

---

Storage:

```text
storage/app/public
```

---

Folder:

```text
lab-orders/

order-attachments/

signatures/

delivery-photos/

invoices/

exports/
```

---

Format file:

```text
jpg

jpeg

png

pdf
```

---

Maksimum:

```text
10 MB
```

---

# 17. SECURITY RULES

Password:

```text
bcrypt
```

---

Role Based Access Control wajib.

Menggunakan:

```text
Spatie Permission
```

---

Role resmi:

```text
Super Admin

Admin Lab

Technician

Quality Control

Courier

Finance

Doctor
```

---

# 18. API RULES

Walaupun menggunakan Livewire.

Semua endpoint tetap menggunakan:

```text
/api/v1
```

Contoh:

```text
/api/v1/lab-orders

/api/v1/deliveries

/api/v1/invoices
```

---

# 19. RESPONSE STANDARD

Success:

```json
{
  "success": true,
  "message": "Success",
  "data": {}
}
```

---

Error:

```json
{
  "success": false,
  "message": "Validation Failed",
  "errors": {}
}
```

---

# 20. TESTING RULES

Framework:

```text
Pest PHP
```

---

Minimal coverage:

```text
80%
```

---

Module wajib test:

```text
LabOrder

QualityControl

Delivery

Invoice

Payment
```

---

Skenario wajib:

```text
Create

Update

Delete

Validation

Business Rules
```

---

# 21. GIT RULES

Branch:

```text
main

develop

feature/*
```

Contoh:

```text
feature/auth

feature/lab-order

feature/delivery

feature/invoice
```

---

Tidak boleh commit langsung ke:

```text
main
```

---

# 22. DOCUMENTATION RULES

Jika ada perubahan:

```text
Database
API
Workflow
Business Rule
```

Maka wajib update:

```text
DATABASE_SCHEMA_V1.md

PROJECT_RULES.md

API_SPEC.md
```

---

# 23. AI CODING RULES

Jika menggunakan:

```text
ChatGPT

Cursor

Claude Code

Github Copilot
```

Prompt wajib mengandung:

```text
Ikuti:

PRD.md

DATABASE_SCHEMA_V1.md

SYSTEM_ARCHITECTURE.md

PROJECT_RULES.md

Jangan:

Menambah tabel

Mengubah schema

Mengubah arsitektur

Menambah fitur

Tanpa persetujuan
```

---

# 24. DEFINITION OF DONE

Module dianggap selesai jika:

```text
Migration selesai

Model selesai

Repository selesai

Service selesai

Request Validation selesai

Controller selesai

Policy selesai

Testing lulus

Audit Log aktif

Dokumentasi diperbarui
```

Jika salah satu belum selesai:

```text
Module dianggap BELUM SELESAI
```

---

# 25. NON NEGOTIABLE RULES

```text
1. Business Logic hanya di Service.

2. Semua perubahan status wajib dicatat.

3. Semua transaksi penting wajib DB Transaction.

4. Semua file disimpan di Storage.

5. Semua delivery wajib POD.

6. Semua invoice wajib terkait order.

7. Semua payment wajib terkait invoice.

8. Tidak boleh bypass Audit Log.

9. Tidak boleh hard delete transaksi.

10. Tidak boleh menambah fitur tanpa update PRD.
```
