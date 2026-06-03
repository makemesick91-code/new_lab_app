# SYSTEM_ARCHITECTURE.md

# Asia Dental Lab Management System

Version: V1

Status: Approved

Architecture Type: Modular Monolith

Last Updated: June 2026

---

# 1. Architecture Overview

Asia Dental Lab Management System menggunakan pendekatan:

```text
Modular Monolith Architecture
```

Tujuan:

* Cepat dikembangkan
* Mudah dipelihara
* Biaya operasional rendah
* Cocok untuk tim kecil
* Mudah di-upgrade menjadi enterprise architecture

---

# 2. High Level Architecture

```text
Browser
│
▼
Laravel Web Application
│
├── Authentication
├── User Management
├── Clinic Module
├── Doctor Module
├── Patient Module
├── Lab Order Module
├── Technician Module
├── Quality Control Module
├── Delivery Module
├── POD Module
├── Invoice Module
├── Payment Module
└── Reporting Module
│
▼
PostgreSQL Database
│
▼
Local File Storage
```

---

# 3. Technology Stack

## Backend

```text
Laravel 12
PHP 8.4
```

Alasan:

* Gratis
* Stabil
* Komunitas besar
* Dokumentasi lengkap

---

## Frontend

```text
Blade
Livewire 3
Alpine.js
```

Alasan:

* Tidak perlu frontend terpisah
* Cepat dikembangkan
* Cocok untuk aplikasi internal

---

## Database

```text
PostgreSQL 16
```

Alasan:

* Open Source
* Relasi kuat
* Cocok untuk transaksi
* Mendukung JSONB

---

## Authentication

```text
Laravel Breeze
```

Fitur:

* Login
* Logout
* Forgot Password
* Session Management

---

## Authorization

```text
Spatie Laravel Permission
```

Role:

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

## Queue

```text
Database Queue
```

Tabel:

```text
jobs
failed_jobs
```

Digunakan untuk:

```text
Generate PDF
Export Excel
Email Notification
```

---

## Cache

```text
File Cache
```

Driver:

```env
CACHE_STORE=file
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

Digunakan untuk:

```text
Foto kasus

Scan intraoral

Foto hasil pekerjaan

Tanda tangan digital

Foto penerimaan barang

Invoice PDF
```

---

# 4. Project Structure

```text
app/
├── Modules/
│
├── Auth/
│
├── Clinic/
│
├── Doctor/
│
├── Patient/
│
├── LabService/
│
├── Technician/
│
├── LabOrder/
│
├── QualityControl/
│
├── Delivery/
│
├── Invoice/
│
├── Payment/
│
└── Report/
```

---

# 5. Module Structure Standard

Setiap module harus memiliki struktur:

```text
ModuleName/
│
├── Controllers/
│
├── Requests/
│
├── Services/
│
├── Repositories/
│
├── Interfaces/
│
├── Models/
│
├── Resources/
│
├── Policies/
│
└── Tests/
```

Contoh:

```text
LabOrder/
│
├── Controllers
├── Requests
├── Services
├── Repositories
├── Interfaces
├── Models
├── Resources
└── Tests
```

---

# 6. Application Layer Architecture

Semua request harus mengikuti alur:

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

Tidak diperbolehkan:

```text
Controller
↓
Model
↓
Database
```

---

# 7. Domain Modules

## Auth Module

Tanggung jawab:

```text
Login
Logout
Reset Password
Session
```

---

## Clinic Module

Tanggung jawab:

```text
Master Klinik
```

---

## Doctor Module

Tanggung jawab:

```text
Master Dokter
```

---

## Patient Module

Tanggung jawab:

```text
Master Pasien
```

---

## Lab Order Module

Tanggung jawab:

```text
Create Order

Edit Order

Order Item

Status Order
```

---

## Technician Module

Tanggung jawab:

```text
Assignment

Progress Pekerjaan
```

---

## Quality Control Module

Tanggung jawab:

```text
QC

Revision

Approval
```

---

## Delivery Module

Tanggung jawab:

```text
Delivery

Proof Of Delivery

Signature

Photo Evidence
```

---

## Invoice Module

Tanggung jawab:

```text
Invoice

Payment

Outstanding
```

---

# 8. Storage Architecture

## Directory Structure

```text
storage/app/public/

├── lab-orders/

├── order-attachments/

├── qc/

├── signatures/

├── delivery-photos/

├── invoices/

└── exports/
```

---

## Signature Storage

Disimpan sebagai:

```text
PNG
```

Contoh:

```text
signatures/

DLV-2026-000001.png
```

---

## Delivery Photo Storage

Contoh:

```text
delivery-photos/

DLV-2026-000001-01.jpg

DLV-2026-000001-02.jpg
```

---

# 9. Security Architecture

## Authentication

```text
Session Based
```

---

## Authorization

```text
Role Based Access Control
```

Menggunakan:

```text
Spatie Permission
```

---

## Password

Hash:

```text
bcrypt
```

---

## File Validation

Wajib:

```text
jpg
jpeg
png
pdf
```

Maksimum:

```text
10 MB
```

---

# 10. Audit Architecture

Semua aktivitas penting masuk:

```text
sys_audit_logs
```

Aktivitas:

```text
Login

Logout

Create

Update

Delete

Assignment

QC

Delivery

Invoice

Payment
```

---

# 11. API Strategy

Walaupun menggunakan Blade + Livewire.

Tetap gunakan:

```text
/api/v1
```

Untuk:

```text
Future Mobile App

Future Customer Portal

Future Integrations
```

Contoh:

```text
/api/v1/lab-orders

/api/v1/deliveries

/api/v1/invoices
```

---

# 12. Development Environment

## Local Development

```text
Windows 11

VS Code

Laravel Herd

PostgreSQL

Git
```

---

## Minimum Specification

```text
CPU 4 Core

RAM 8 GB

SSD 100 GB
```

---

## Recommended Specification

```text
CPU 8 Core

RAM 32 GB

SSD NVMe 500 GB
```

---

# 13. Deployment Architecture

## V1

Single Server

```text
Ubuntu 24.04

Nginx

PHP-FPM

Laravel

PostgreSQL

Queue Worker

Scheduler
```

Diagram:

```text
Internet
↓
Nginx
↓
Laravel App
↓
PostgreSQL
```

---

# 14. Scalability Plan

## V1

```text
Single Server
```

---

## V2

Tambahkan:

```text
Redis

MinIO

Separate Database Server
```

---

## V3

Tambahkan:

```text
Load Balancer

Multiple App Server

Dedicated Queue Server
```

---

# 15. Non Functional Requirements

## Performance

```text
API Response < 2 Detik
```

---

## Availability

```text
99% Uptime
```

---

## Backup

Database:

```text
Daily Backup
```

File:

```text
Weekly Backup
```

---

# 16. Architecture Decisions (ADR)

ADR-001

```text
Menggunakan Modular Monolith
```

ADR-002

```text
Menggunakan PostgreSQL
```

ADR-003

```text
Menggunakan Blade + Livewire
```

ADR-004

```text
Menggunakan Repository Pattern
```

ADR-005

```text
Menggunakan Service Layer
```

ADR-006

```text
Menggunakan Database Queue
```

ADR-007

```text
Menggunakan Local Storage
```

ADR-008

```text
Semua perubahan status wajib masuk status log dan audit log.
```
