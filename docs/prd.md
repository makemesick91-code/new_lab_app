# PRODUCT REQUIREMENT DOCUMENT (PRD)

# Asia Dental Lab Management System (ADLMS)

Version: 1.1

Status: Approved Draft

Owner: Asia Dental Lab

Prepared By: Product Team

Last Updated: June 2026

---

# 1. EXECUTIVE SUMMARY

Asia Dental Lab Management System (ADLMS) adalah aplikasi berbasis web yang digunakan untuk mengelola seluruh proses operasional laboratorium gigi mulai dari penerimaan order dokter, proses produksi, quality control, pengiriman hasil pekerjaan, hingga invoicing dan pelaporan.

Sistem dirancang untuk menggantikan proses manual yang saat ini dilakukan melalui WhatsApp, Excel, dan dokumen fisik sehingga seluruh aktivitas dapat dipantau secara real-time, terdokumentasi, dan dapat diaudit.

---

# 2. BUSINESS PROBLEM

Permasalahan saat ini:

* Status pekerjaan sulit dipantau.
* Dokter sering menghubungi laboratorium untuk menanyakan progres.
* Tidak ada monitoring SLA pengerjaan.
* Riwayat revisi tidak terdokumentasi dengan baik.
* Sulit mengetahui pekerjaan yang terlambat.
* Tidak ada pengukuran produktivitas teknisi.
* Pengiriman hasil tidak memiliki bukti penerimaan yang kuat.
* Invoice dan pembayaran masih dilakukan secara manual.
* Sulit melakukan audit historis pekerjaan.

---

# 3. BUSINESS GOALS

## Jangka Pendek

* Digitalisasi seluruh proses laboratorium.
* Monitoring status order secara real-time.
* Mengurangi komunikasi manual.

## Jangka Menengah

* Meningkatkan produktivitas teknisi.
* Mengurangi keterlambatan pengerjaan.
* Meningkatkan kualitas QC.

## Jangka Panjang

* Integrasi dengan sistem klinik.
* Multi cabang laboratorium.
* Dashboard bisnis dan analitik.

---

# 4. SCOPE

## In Scope

### Master Data

* User
* Role
* Permission
* Klinik
* Dokter
* Pasien
* Teknisi
* Service Lab
* Material
* Shade Color

### Operasional

* Lab Order
* Assignment Teknisi
* Progress Pekerjaan
* Quality Control
* Delivery
* Proof of Delivery
* Invoice
* Payment

### Reporting

* Dashboard
* Revenue Report
* Productivity Report
* Delivery Report
* Delay Report
* QC Report

---

## Out of Scope (Versi 1)

* Mobile App
* AI Diagnosis
* BPJS
* Akuntansi Lengkap
* Payroll
* Telemedicine

---

# 5. USER ROLES

## Super Admin

Akses penuh terhadap seluruh sistem.

## Admin Lab

* Membuat order
* Mengelola order
* Monitoring pekerjaan
* Monitoring delivery

## Teknisi

* Melihat assignment
* Update progres pekerjaan

## Quality Control

* Approve hasil
* Reject hasil
* Memberikan catatan revisi

## Kurir

* Melihat daftar pengiriman
* Update status pengiriman
* Upload bukti serah terima

## Finance

* Membuat invoice
* Input pembayaran
* Monitoring piutang

## Dokter

* Melihat status pekerjaan
* Melihat histori order

---

# 6. BUSINESS WORKFLOW

Order Dibuat
↓
Order Diterima
↓
Assignment Teknisi
↓
Pengerjaan
↓
Quality Control
↓
Revisi (jika diperlukan)
↓
Ready For Delivery
↓
Kurir Mengirim
↓
Tanda Tangan Penerima
↓
Upload Foto Barang Diterima
↓
Proof Of Delivery Completed
↓
Invoice
↓
Pembayaran
↓
Completed

---

# 7. MODUL SISTEM

## Authentication

Fitur:

* Login
* Logout
* Forgot Password
* JWT Authentication
* Session Management

---

## User Management

Fitur:

* CRUD User
* Role Assignment
* Permission Assignment

---

## Klinik

Fitur:

* CRUD Klinik
* Data Cabang
* Status Aktif

---

## Dokter

Fitur:

* CRUD Dokter
* Relasi Klinik
* Histori Order

---

## Pasien

Fitur:

* CRUD Pasien
* Histori Kasus

---

## Service Lab

Contoh:

* Crown Zirconia
* Crown PFM
* Veneer
* Bridge
* Denture
* Implant Crown
* Night Guard
* Retainer

Fitur:

* CRUD Service
* Harga
* Estimasi Hari Kerja

---

## Lab Order

Fitur:

* Create Order
* Upload Foto
* Upload Scan
* Multi Item Order
* Priority
* Due Date
* Status Tracking

---

## Assignment Teknisi

Fitur:

* Assign Teknisi
* Reassign Teknisi
* Monitoring Workload

---

## Progress Pekerjaan

Status:

* Received
* In Progress
* Waiting Material
* QC
* Revision
* Ready

---

## Quality Control

Fitur:

* QC Checklist
* Approve
* Reject
* Revision Note

---

## Delivery

Fitur:

* Generate Delivery Number
* Assign Kurir
* Tracking Pengiriman
* Delivery History

---

## Proof Of Delivery (POD)

Fitur wajib.

Data yang harus direkam:

### Data Penerima

* Nama penerima
* Jabatan penerima
* Nomor telepon penerima

### Tanda Tangan Digital

* Wajib sebelum delivery selesai
* Disimpan sebagai file

### Foto Barang Diterima

Minimal:

* 1 foto wajib

Direkomendasikan:

* Foto paket
* Foto barang
* Foto serah terima

### Informasi Pengiriman

* Tanggal
* Jam
* Kurir
* Lokasi klinik

---

## Invoice

Fitur:

* Generate Invoice
* Outstanding Payment
* Payment History

---

## Dashboard

Menampilkan:

* Total Order
* Pending QC
* Pending Delivery
* Revenue
* Outstanding Invoice
* Produktivitas Teknisi

---

# 8. FUNCTIONAL REQUIREMENTS

## Order

FR-001 User dapat login.

FR-002 Admin dapat membuat order laboratorium.

FR-003 Sistem membuat nomor order otomatis.

Format:

ADL-YYYY-XXXXXX

Contoh:

ADL-2026-000001

FR-004 Order dapat memiliki banyak item pekerjaan.

FR-005 Order dapat diassign ke teknisi.

FR-006 Teknisi dapat mengubah status pekerjaan.

FR-007 Setiap perubahan status wajib dicatat.

---

## Quality Control

FR-008 QC dapat approve hasil.

FR-009 QC dapat reject hasil.

FR-010 QC dapat meminta revisi.

---

## Delivery

FR-011 Kurir dapat membuat delivery.

FR-012 Kurir wajib mengisi nama penerima.

FR-013 Kurir wajib mengisi jabatan penerima.

FR-014 Kurir wajib mengunggah tanda tangan digital penerima.

FR-015 Kurir wajib mengunggah minimal satu foto bukti penerimaan.

FR-016 Sistem tidak boleh mengubah status menjadi Delivered jika tanda tangan belum tersedia.

FR-017 Sistem tidak boleh mengubah status menjadi Delivered jika foto belum tersedia.

FR-018 Semua bukti penerimaan harus tersimpan permanen.

---

## Invoice

FR-019 Invoice hanya dapat dibuat setelah POD selesai.

FR-020 Pembayaran dapat dicatat secara parsial maupun penuh.

---

# 9. DELIVERY STATUS

Status delivery:

READY_FOR_DELIVERY

IN_TRANSIT

ARRIVED

POD_COMPLETED

DELIVERED

Keterangan:

READY_FOR_DELIVERY
= Siap dikirim.

IN_TRANSIT
= Sedang dalam perjalanan.

ARRIVED
= Kurir tiba di lokasi.

POD_COMPLETED
= Tanda tangan dan foto sudah lengkap.

DELIVERED
= Pengiriman berhasil.

---

# 10. NON FUNCTIONAL REQUIREMENTS

## Performance

* Response API < 2 detik.
* Mendukung 100 concurrent users.

## Security

* JWT Authentication
* RBAC
* Audit Log

## Availability

* Uptime minimal 99%

## Backup

* Database backup harian
* File backup mingguan

---

# 11. AUDIT TRAIL

Sistem wajib mencatat:

* Login
* Logout
* Create
* Update
* Delete
* Assignment
* Status Change
* QC Approval
* Delivery
* POD
* Invoice
* Payment

Data audit delivery:

* Kurir
* Tanggal
* Jam
* Nama penerima
* Jabatan penerima
* Jumlah foto
* Nama file tanda tangan

---

# 12. SUCCESS METRICS

## Operasional

* 90% order diproses digital.

## Produksi

* Produktivitas teknisi naik 20%.

## Delivery

* Sengketa pengiriman turun 90%.

## Finance

* Outstanding invoice turun 30%.

---

# 13. FUTURE ROADMAP

## Version 2

* Mobile App
* WhatsApp Notification
* Inventory Material Lab
* Multi Branch

## Version 3

* AI Production Forecast
* Customer Portal
* Business Intelligence Dashboard
* GPS Tracking Kurir
