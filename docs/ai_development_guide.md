# AI_DEVELOPMENT_GUIDE.md

# Asia Dental Lab Management System

Version: V1

Last Updated: June 2026

---

# 1. PURPOSE

Dokumen ini menjadi aturan utama bagi seluruh AI coding assistant yang digunakan dalam proyek Asia Dental Lab.

Tujuan:

```text
Menghasilkan kode yang konsisten.

Menghindari scope creep.

Menghindari perubahan schema tanpa persetujuan.

Menghindari perubahan arsitektur.

Menjaga konsistensi coding standard.
```

AI yang tercakup:

```text
ChatGPT
Cursor
Claude Code
GitHub Copilot
Windsurf
Continue.dev
Aider
Codeium
```

---

# 2. PROJECT SOURCE OF TRUTH

AI wajib mengikuti urutan dokumen berikut:

```text
1. PRD.md

2. DATABASE_SCHEMA_V1.md

3. SYSTEM_ARCHITECTURE.md

4. PROJECT_RULES.md

5. ERD.md

6. API_SPEC.md

7. UI_FLOW.md

8. SPRINT_PLAN.md

9. TASK_BREAKDOWN.md
```

Jika terjadi konflik:

```text
PRD
mengalahkan
semua dokumen lainnya
```

---

# 3. PROJECT SUMMARY

Nama Proyek:

```text
Asia Dental Lab Management System
```

Tipe:

```text
Dental Laboratory Management System
```

Workflow:

```text
Lab Order
↓
Assignment
↓
Production
↓
Quality Control
↓
Delivery
↓
Proof Of Delivery
↓
Invoice
↓
Payment
```

---

# 4. TECH STACK

AI wajib menggunakan:

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

## Storage

```text
Local Storage
```

---

# 5. ARCHITECTURE RULES

Architecture:

```text
Modular Monolith
```

Folder:

```text
app/Modules/
```

AI tidak boleh:

```text
Mengubah architecture.

Mengubah project structure.

Menambah framework baru.
```

---

# 6. MODULE STRUCTURE RULE

Setiap module wajib:

```text
Controllers
Requests
Services
Repositories
Interfaces
Models
Resources
Policies
Tests
```

Contoh:

```text
LabOrder/

Controllers
Requests
Services
Repositories
Interfaces
Models
Resources
Policies
Tests
```

---

# 7. REQUEST FLOW RULE

AI wajib mengikuti:

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

# 8. CONTROLLER RULES

Controller hanya boleh:

```text
Menerima request.

Memanggil service.

Mengembalikan response.
```

Controller tidak boleh:

```text
Business Logic.

Complex Validation.

Database Query.
```

---

# 9. SERVICE RULES

Semua business logic wajib berada di:

```text
Services/
```

Contoh:

```text
Create Order
Assign Technician
QC Approval
Delivery Completion
Generate Invoice
Record Payment
```

---

# 10. REPOSITORY RULES

Repository hanya untuk:

```text
CRUD

Filtering

Search

Pagination

Database Query
```

Repository tidak boleh:

```text
Business Logic
```

---

# 11. DATABASE RULES

AI wajib mengikuti:

```text
DATABASE_SCHEMA_V1.md
ERD.md
```

AI tidak boleh:

```text
Menambah tabel.

Menghapus tabel.

Menambah kolom.

Menghapus kolom.

Mengubah tipe data.

Mengubah foreign key.
```

Tanpa instruksi eksplisit.

---

# 12. TRANSACTION RULES

Gunakan:

```php
DB::transaction(function () {

});
```

Untuk:

```text
Create Order

Assign Technician

QC

Delivery

Invoice

Payment
```

---

# 13. STATUS RULES

Status resmi order:

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

AI tidak boleh membuat status baru.

---

# 14. AUDIT LOG RULES

Semua aktivitas penting wajib:

```text
Create Audit Log
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

# 15. FILE STORAGE RULES

File disimpan:

```text
storage/app/public
```

Database hanya menyimpan:

```text
file_path
```

AI tidak boleh:

```text
Menyimpan base64 di database.
```

---

# 16. POD RULES

Delivery tidak boleh selesai jika:

```text
receiver_name kosong

receiver_role kosong

signature kosong

foto kosong
```

POD wajib:

```text
Nama Penerima

Jabatan

Tanda Tangan

Minimal 1 Foto
```

---

# 17. RESPONSE FORMAT RULE

Success:

```json
{
  "success": true,
  "message": "Success",
  "data": {}
}
```

Error:

```json
{
  "success": false,
  "message": "Error",
  "errors": {}
}
```

---

# 18. TESTING RULES

Gunakan:

```text
Pest PHP
```

Minimal test:

```text
Validation
Business Rule
Authorization
Happy Path
```

---

# 19. AI OUTPUT RULES

Jika diminta mengerjakan task:

AI hanya boleh output:

```text
Kode yang dibutuhkan task.

Migration.

Model.

Repository.

Service.

Controller.

Request.

Livewire Component.

Test.
```

AI tidak boleh:

```text
Menghasilkan fitur lain.

Mengubah file yang tidak terkait.
```

---

# 20. FORBIDDEN ACTIONS

AI dilarang:

```text
Menambah fitur.

Menambah modul.

Mengubah schema.

Mengubah workflow bisnis.

Mengubah role.

Mengubah permission.

Mengganti PostgreSQL.

Mengganti Laravel.

Mengganti Livewire.

Mengganti arsitektur.
```

Tanpa instruksi eksplisit.

---

# 21. TASK EXECUTION MODE

Setiap task harus mengikuti:

```text
TASK_BREAKDOWN.md
```

AI wajib menyelesaikan:

```text
1 task
=
1 output
```

Tidak boleh mengerjakan sprint penuh sekaligus.

---

# 22. DEFINITION OF DONE

Task dianggap selesai jika:

```text
Code Complete

Validation Complete

Service Complete

Repository Complete

Test Complete

No Error

Follow Architecture
```

---

# 23. MASTER PROMPT TEMPLATE

Gunakan prompt berikut di Cursor, Claude Code, atau ChatGPT:

```text
Ikuti dokumen berikut:

PRD.md
DATABASE_SCHEMA_V1.md
SYSTEM_ARCHITECTURE.md
PROJECT_RULES.md
ERD.md
API_SPEC.md
UI_FLOW.md
SPRINT_PLAN.md
TASK_BREAKDOWN.md
AI_DEVELOPMENT_GUIDE.md

Kerjakan task berikut:

[TASK ID]

Aturan:

- Jangan menambah fitur.
- Jangan mengubah schema.
- Jangan mengubah arsitektur.
- Jangan mengubah workflow bisnis.
- Gunakan Laravel 12.
- Gunakan PostgreSQL.
- Gunakan Blade + Livewire.
- Gunakan Repository Pattern.
- Gunakan Service Layer.
- Gunakan Form Request Validation.
- Gunakan DB Transaction jika diperlukan.
- Output hanya file yang dibutuhkan task.
```

---

# 24. VIBE CODING SAFETY RULE

Sebelum menghasilkan kode, AI wajib memeriksa:

```text
Apakah task ada di TASK_BREAKDOWN.md?

Apakah tabel ada di DATABASE_SCHEMA_V1.md?

Apakah relasi ada di ERD.md?

Apakah endpoint ada di API_SPEC.md?

Apakah halaman ada di UI_FLOW.md?
```

Jika salah satu tidak ada:

```text
STOP

Minta klarifikasi

Jangan mengarang fitur baru
```

---

# 25. FINAL PRINCIPLE

```text
AI adalah implementer.

PRD adalah product owner.

Schema adalah kontrak database.

Architecture adalah kontrak teknis.

AI tidak boleh mengambil keputusan bisnis sendiri.
```
