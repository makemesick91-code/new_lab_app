# SPRINT_PLAN.md

# Asia Dental Lab Management System

Version: 1.0

Status: Approved

Last Updated: June 2026

---

# FOUNDATION-FIRST SPRINT LOCK

Status: **FOUNDATION-FIRST LOCK ACTIVE**.

This legacy sprint plan is historical unless a future item is explicitly approved
as FOUNDATION, HOTFIX, SECURITY, DEPLOYMENT, OPERATIONS, or FOUNDATION-DOCS.
All non-foundation planning is POST-FOUNDATION BACKLOG and blocked until
FOUNDATION GO. See
[`docs/architecture/foundation-first-sprint-lock-governance.md`](architecture/foundation-first-sprint-lock-governance.md).

# 1. Sprint Strategy

Metode pengembangan menggunakan:

```text
Vertical Slice Development
```

Setiap sprint harus menghasilkan fitur yang:

* Dapat digunakan user
* Dapat diuji
* Dapat di-demo
* Dapat di-deploy ke staging

---

# 2. Sprint Timeline

| Sprint    | Nama                  | Durasi |
| --------- | --------------------- | ------ |
| Sprint 0  | Foundation            | 3 Hari |
| Sprint 1  | User & Access         | 3 Hari |
| Sprint 2  | Master Data           | 3 Hari |
| Sprint 3  | Lab Order Core        | 5 Hari |
| Sprint 4  | Assignment & Workflow | 4 Hari |
| Sprint 5  | Quality Control       | 3 Hari |
| Sprint 6  | Delivery & POD        | 4 Hari |
| Sprint 7  | Invoice & Payment     | 3 Hari |
| Sprint 8  | Dashboard & Reporting | 4 Hari |
| Sprint 9  | Stabilization & UAT   | 3 Hari |
| Sprint 10 | Production Release    | 2 Hari |

Estimasi total:

```text
34 Hari Kerja
```

---

# Sprint 0

# Foundation

## Goal

Menyiapkan fondasi proyek.

---

## Tasks

### Environment

```text
Install Laravel 12
Install PostgreSQL
Setup Git Repository
Setup Branching Strategy
Setup Environment Variables
```

### Packages

```text
Laravel Breeze

Spatie Permission

Laravel Debugbar

Pest PHP
```

### Project Structure

```text
Modules Structure

Service Layer

Repository Layer

Request Validation Layer
```

---

## Deliverables

```text
Project Running

Login Page

Database Connected

Module Structure Ready
```

---

## Definition of Done

```text
Application dapat dijalankan
Developer dapat login
Database terkoneksi
```

---

# Sprint 1

# User & Access Management

## Goal

Mengelola user dan hak akses.

---

## Database

```text
users

roles

permissions

model_has_roles

role_has_permissions
```

---

## Features

### User Management

```text
Create User

Edit User

Delete User

View User
```

### Role Management

```text
Create Role

Assign Role

Permission Assignment
```

---

## Deliverables

```text
User CRUD

Role CRUD

Permission Management
```

---

## Definition of Done

```text
Admin dapat membuat user baru
Role berjalan dengan benar
Permission berjalan dengan benar
```

---

# Sprint 2

# Master Data

## Goal

Menyediakan seluruh master data.

---

## Database

```text
mst_clinics

mst_doctors

mst_patients

mst_lab_services

mst_technicians
```

---

## Features

### Clinic

```text
CRUD Clinic
```

### Doctor

```text
CRUD Doctor
```

### Patient

```text
CRUD Patient
```

### Lab Service

```text
CRUD Lab Service
```

### Technician

```text
CRUD Technician
```

---

## Deliverables

```text
Semua master data tersedia
```

---

## Definition of Done

```text
Admin dapat mengelola seluruh master data
```

---

# Sprint 3

# Lab Order Core

## Goal

Membuat order laboratorium.

---

## Database

```text
trx_lab_orders

trx_lab_order_items

sys_attachments
```

---

## Features

### Order

```text
Create Order

Edit Order

View Order

Cancel Order
```

### Attachment

```text
Upload Foto Kasus

Upload Scan

Upload Dokumen Pendukung
```

### Order Detail

```text
Multi Service

Tooth Number

Material Text

Shade Color Text
```

---

## Business Rules

```text
Order Number Auto Generate

Minimal 1 Item

Order Status Log Otomatis
```

---

## Deliverables

```text
Lab Order Berjalan
```

---

## Definition of Done

```text
Order berhasil dibuat
Order dapat dilihat
Attachment tersimpan
```

---

# Sprint 4

# Assignment & Workflow

## Goal

Order dapat dikerjakan teknisi.

---

## Database

```text
trx_lab_order_assignments

trx_lab_order_status_logs
```

---

## Features

### Assignment

```text
Assign Technician

Reassign Technician

Assignment History
```

### Workflow

```text
Received

In Progress

Waiting Material

QC
```

---

## Business Rules

```text
Status Change Masuk Log

Assignment Masuk Audit Log
```

---

## Deliverables

```text
Workflow Produksi Berjalan
```

---

## Definition of Done

```text
Order dapat diproses teknisi
Status berubah sesuai workflow
```

---

# Sprint 5

# Quality Control

## Goal

Membangun proses QC.

---

## Database

```text
trx_lab_quality_controls
```

---

## Features

### QC

```text
Approve

Reject

Revision
```

### QC History

```text
QC Notes

QC Timeline
```

---

## Business Rules

```text
Order tidak boleh delivery
jika QC belum PASSED
```

---

## Deliverables

```text
QC Workflow Berjalan
```

---

## Definition of Done

```text
QC dapat approve
QC dapat reject
QC dapat revision
```

---

# Sprint 6

# Delivery & Proof Of Delivery

## Goal

Membangun proses pengiriman lengkap.

---

## Database

```text
trx_lab_deliveries

trx_lab_delivery_photos
```

---

## Features

### Delivery

```text
Create Delivery

Assign Courier

Delivery Tracking
```

### POD

```text
Receiver Name

Receiver Role

Digital Signature

Delivery Photos
```

---

## Business Rules

```text
Signature wajib

Foto wajib

Nama penerima wajib

Jabatan wajib
```

---

## Deliverables

```text
POD Berjalan
```

---

## Definition of Done

```text
Kurir dapat menyelesaikan pengiriman
Status DELIVERED hanya jika POD lengkap
```

---

# Sprint 7

# Invoice & Payment

## Goal

Membangun proses keuangan.

---

## Database

```text
trx_invoices

trx_invoice_items

trx_payments
```

---

## Features

### Invoice

```text
Generate Invoice

Invoice Detail

Invoice History
```

### Payment

```text
Create Payment

Partial Payment

Full Payment
```

---

## Business Rules

```text
Invoice hanya setelah DELIVERED

Payment dapat partial
```

---

## Deliverables

```text
Invoice dan Payment Berjalan
```

---

## Definition of Done

```text
Invoice dapat dibuat
Payment dapat dicatat
Outstanding otomatis dihitung
```

---

# Sprint 8

# Dashboard & Reporting

## Goal

Memberikan informasi bisnis.

---

## Features

### Dashboard

```text
Total Order

Pending QC

Pending Delivery

Outstanding Invoice

Revenue
```

### Reports

```text
Order Report

Delivery Report

QC Report

Revenue Report
```

---

## Export

```text
PDF

Excel
```

---

## Deliverables

```text
Dashboard Management
Reporting
```

---

## Definition of Done

```text
Owner dapat melihat performa bisnis
```

---

# Sprint 9

# Stabilization & UAT

## Goal

Persiapan Go Live.

---

## Activities

```text
Bug Fixing

Performance Testing

Security Testing

UAT
```

---

## Deliverables

```text
Bug List Closed

UAT Approved
```

---

## Definition of Done

```text
Tidak ada bug critical
UAT disetujui
```

---

# Sprint 10

# Production Release

## Goal

Go Live.

---

## Activities

```text
Production Deployment

Backup Setup

Monitoring Setup

User Training
```

---

## Deliverables

```text
Production Environment

Backup System

Monitoring
```

---

## Definition of Done

```text
Sistem digunakan operasional harian
```

---

# Release Milestones

## MVP Release

Setelah Sprint 6

Fitur:

```text
Auth

Master Data

Lab Order

Assignment

QC

Delivery

POD
```

---

## Business Release

Setelah Sprint 8

Fitur:

```text
Invoice

Payment

Dashboard

Reporting
```

---

# Success Criteria

## Technical

```text
Test Coverage ≥ 80%

No Critical Bug

Audit Log Active
```

---

## Business

```text
100% Order Digital

100% Delivery Menggunakan POD

Invoice Tracking Berjalan

Status Order Real-Time
```
