# UI_FLOW.md

# Asia Dental Lab Management System

Version: V1
Frontend: Blade + Livewire 3
Architecture: Modular Monolith
Backend: Laravel 12

---

# 1. UI Flow Purpose

Dokumen ini menjelaskan struktur halaman, menu, alur kerja user, dan urutan interaksi pengguna pada Asia Dental Lab Management System.

UI Flow ini menjadi acuan untuk:

```text
Blade View
Livewire Component
Route Web
Policy
Menu Sidebar
Dashboard
Testing UI
```

---

# 2. UI Design Principle

Prinsip utama UI:

```text
User bekerja berdasarkan workflow,
bukan berdasarkan struktur database.
```

Karena itu menu utama disusun mengikuti proses bisnis:

```text
Dashboard
↓
Lab Orders
↓
Production
↓
Quality Control
↓
Delivery
↓
Finance
↓
Reports
```

---

# 3. Main Navigation

## Super Admin / Admin Lab

```text
Dashboard

Operations
├── Lab Orders
├── Production Assignments
├── Quality Control
├── Deliveries

Master Data
├── Clinics
├── Doctors
├── Patients
├── Lab Services
├── Technicians

Finance
├── Invoices
├── Payments
├── Outstanding

Reports
├── Order Reports
├── Delivery Reports
├── QC Reports
├── Revenue Reports

Settings
├── Users
├── Roles
├── Permissions
```

---

## Technician

```text
Dashboard

My Assignments

Completed Jobs
```

---

## Quality Control

```text
Dashboard

Pending QC

QC History
```

---

## Courier

```text
Dashboard

My Deliveries

Delivery History
```

---

## Finance

```text
Dashboard

Invoices

Payments

Outstanding

Revenue Reports
```

---

## Doctor

```text
Dashboard

My Orders

Order History
```

---

# 4. Global Layout

## Layout Structure

```text
Header
├── Logo
├── Search
├── Notification Icon
└── User Profile

Sidebar
├── Dynamic Menu by Role

Main Content
├── Page Title
├── Breadcrumb
├── Action Button
└── Content Area

Footer
└── Version Information
```

---

# 5. Authentication Flow

```text
Login Page
↓
User Input Email & Password
↓
Validate Credential
↓
Redirect by Role
↓
Dashboard
```

## Redirect Rule

| Role            | Redirect          |
| --------------- | ----------------- |
| Super Admin     | Dashboard         |
| Admin Lab       | Dashboard         |
| Technician      | My Assignments    |
| Quality Control | Pending QC        |
| Courier         | My Deliveries     |
| Finance         | Finance Dashboard |
| Doctor          | My Orders         |

---

# 6. Dashboard Flow

## Super Admin / Admin Lab Dashboard

Widgets:

```text
Total Orders Today
Orders In Progress
Pending Assignment
Pending QC
Pending Delivery
Completed Orders
Unpaid Invoices
Revenue This Month
```

Quick Actions:

```text
Create Lab Order
Assign Technician
Open Pending QC
Open Pending Delivery
Generate Invoice
```

---

## Technician Dashboard

Widgets:

```text
My Pending Assignments
My In Progress Jobs
My Completed Jobs Today
Overdue Jobs
```

Quick Actions:

```text
Open My Assignments
Start Work
Complete Work
```

---

## QC Dashboard

Widgets:

```text
Pending QC
Approved Today
Revision Orders
Rejected Orders
```

Quick Actions:

```text
Open Pending QC
Open QC History
```

---

## Courier Dashboard

Widgets:

```text
Deliveries Today
Pending Deliveries
In Transit
Completed Deliveries
```

Quick Actions:

```text
Open My Deliveries
Start Delivery
Complete POD
```

---

## Finance Dashboard

Widgets:

```text
Unpaid Invoices
Partial Paid Invoices
Paid This Month
Outstanding Amount
Revenue This Month
```

Quick Actions:

```text
Create Invoice
Record Payment
Open Outstanding
```

---

## Doctor Dashboard

Widgets:

```text
My Active Orders
My Completed Orders
Pending Delivery
Outstanding Invoices
```

Quick Actions:

```text
View My Orders
View Order Detail
View Invoice
```

---

# 7. Lab Order Flow

## Order List Page

Route:

```text
/lab-orders
```

Main components:

```text
Search
Status Filter
Clinic Filter
Doctor Filter
Date Filter
Create Order Button
Order Table
Pagination
```

Table columns:

```text
Order Number
Clinic
Doctor
Patient
Order Date
Due Date
Priority
Status
Action
```

Actions:

```text
View Detail
Edit
Cancel
Assign Technician
```

---

## Create Order Flow

```text
Dashboard / Lab Orders
↓
Create Order
↓
Select Clinic
↓
Select Doctor
↓
Select Patient
↓
Input Order Date
↓
Input Due Date
↓
Select Priority
↓
Add Order Items
↓
Upload Attachments
↓
Submit
↓
Order Created
↓
Redirect to Order Detail
```

---

## Create Order Page

Route:

```text
/lab-orders/create
```

Fields:

```text
Clinic
Doctor
Patient
Order Date
Due Date
Priority
Notes
```

Order Item Fields:

```text
Lab Service
Tooth Number
Shade Color Text
Material Text
Qty
Price
Discount
Notes
```

Attachment Fields:

```text
File Upload
Description
```

Validation:

```text
Clinic required
Doctor required
Order Date required
Minimal 1 item required
Lab Service required
Qty minimal 1
```

---

## Order Detail Page

Route:

```text
/lab-orders/{id}
```

This is the most important page.

Tabs:

```text
Overview
Items
Attachments
Timeline
Assignments
QC
Delivery
Invoice
```

---

### Overview Tab

Displays:

```text
Order Number
Clinic
Doctor
Patient
Order Date
Due Date
Priority
Current Status
Notes
```

---

### Items Tab

Displays:

```text
Lab Service
Tooth Number
Shade Color
Material
Qty
Price
Subtotal
Notes
```

---

### Attachments Tab

Displays:

```text
Case Photos
Scan Files
Supporting Documents
```

Actions:

```text
Upload Attachment
Download Attachment
Delete Attachment
```

---

### Timeline Tab

Displays:

```text
Status Changes
Assignment Events
QC Events
Delivery Events
Invoice Events
Payment Events
```

---

### Assignments Tab

Actions:

```text
Assign Technician
Reassign Technician
View Assignment Status
```

---

### QC Tab

Actions:

```text
Pass QC
Reject QC
Request Revision
View QC History
```

---

### Delivery Tab

Actions:

```text
Create Delivery
View Delivery Detail
View POD
```

---

### Invoice Tab

Actions:

```text
Generate Invoice
View Invoice
Record Payment
```

---

# 8. Production / Assignment Flow

## Production Assignment List

Route:

```text
/production/assignments
```

Filters:

```text
Technician
Status
Date
Order Number
```

Columns:

```text
Order Number
Technician
Assigned Date
Status
Started At
Completed At
Action
```

---

## Assign Technician Flow

```text
Order Detail
↓
Assignments Tab
↓
Click Assign Technician
↓
Select Technician
↓
Input Notes
↓
Save
↓
Assignment Created
↓
Status Updated
```

---

## Technician My Assignments

Route:

```text
/my-assignments
```

Columns:

```text
Order Number
Clinic
Doctor
Due Date
Status
Action
```

Actions:

```text
View Detail
Start Work
Complete Work
Send To QC
```

---

## Assignment Detail Flow

```text
My Assignments
↓
Assignment Detail
↓
Start Work
↓
Update Progress
↓
Complete Work
↓
Send To QC
```

---

# 9. Quality Control Flow

## Pending QC Page

Route:

```text
/qc/pending
```

Columns:

```text
Order Number
Clinic
Doctor
Technician
Completed At
Due Date
Action
```

Actions:

```text
Open QC
```

---

## QC Detail Page

Route:

```text
/qc/{lab_order_id}
```

Displays:

```text
Order Info
Order Items
Attachments
Technician Notes
Previous QC History
```

Actions:

```text
Pass
Reject
Revision
```

Required field:

```text
QC Notes
```

---

## QC Flow

```text
Pending QC
↓
Open Order
↓
Review Result
↓
Pass / Reject / Revision
↓
Input Notes
↓
Submit
↓
Status Updated
```

Status effect:

```text
Pass → READY_FOR_DELIVERY

Revision → REVISION

Reject → QC REJECTED / STOPPED
```

---

# 10. Delivery & POD Flow

## Delivery List Page

Route:

```text
/deliveries
```

Filters:

```text
Status
Courier
Clinic
Delivery Date
```

Columns:

```text
Delivery Number
Order Number
Clinic
Courier
Delivery Date
Status
Action
```

Actions:

```text
View Detail
Start Delivery
Open POD
```

---

## Create Delivery Flow

```text
Order Detail
↓
Delivery Tab
↓
Create Delivery
↓
Select Courier
↓
Input Delivery Date
↓
Save
↓
Delivery Created
```

Validation:

```text
Order status must be READY_FOR_DELIVERY
Courier required
Delivery date required
```

---

## Courier My Deliveries

Route:

```text
/my-deliveries
```

Columns:

```text
Delivery Number
Order Number
Clinic
Address
Delivery Date
Status
Action
```

Actions:

```text
Start Delivery
Mark Arrived
Open POD
Complete Delivery
```

---

## Delivery Detail Page

Route:

```text
/deliveries/{id}
```

Tabs:

```text
Delivery Info
Order Info
Receiver
Photos
Signature
Timeline
```

---

## POD Form Page

Route:

```text
/deliveries/{id}/pod
```

Fields:

```text
Receiver Name
Receiver Role
Receiver Phone
Delivered At
Notes
Signature Canvas
Photo Upload
```

Photo Types:

```text
PACKAGE
RECEIVED_GOODS
HANDOVER
OTHER
```

Validation:

```text
Receiver Name required
Receiver Role required
Signature required
Minimal 1 photo required
Delivered At required
```

---

## POD Flow

```text
My Deliveries
↓
Delivery Detail
↓
Start Delivery
↓
Arrived
↓
Open POD
↓
Input Receiver Name
↓
Input Receiver Role
↓
Capture Signature
↓
Upload Photo
↓
Complete Delivery
↓
Status DELIVERED
```

---

# 11. Finance Flow

## Invoice List Page

Route:

```text
/invoices
```

Filters:

```text
Status
Clinic
Doctor
Invoice Date
```

Columns:

```text
Invoice Number
Order Number
Clinic
Doctor
Invoice Date
Grand Total
Paid Amount
Outstanding
Status
Action
```

Actions:

```text
View
Print
Void
Record Payment
```

---

## Generate Invoice Flow

```text
Order Detail
↓
Invoice Tab
↓
Generate Invoice
↓
Review Items
↓
Input Discount
↓
Input Tax
↓
Save
↓
Invoice Created
```

Validation:

```text
Order must be DELIVERED
Order must not already have invoice
```

---

## Invoice Detail Page

Route:

```text
/invoices/{id}
```

Tabs:

```text
Invoice Info
Items
Payments
Print
```

Actions:

```text
Print PDF
Record Payment
Void Invoice
```

---

## Payment Flow

```text
Invoice Detail
↓
Record Payment
↓
Input Payment Date
↓
Select Payment Method
↓
Input Amount
↓
Input Reference Number
↓
Save
↓
Outstanding Updated
```

---

## Payment List Page

Route:

```text
/payments
```

Columns:

```text
Payment Number
Invoice Number
Payment Date
Payment Method
Amount
Reference Number
Action
```

---

# 12. Doctor Flow

## My Orders Page

Route:

```text
/doctor/orders
```

Columns:

```text
Order Number
Patient
Order Date
Due Date
Status
Action
```

Actions:

```text
View Detail
```

---

## Doctor Order Detail

Doctor can view:

```text
Order Info
Items
Status Timeline
Delivery Status
Invoice Status
```

Doctor cannot:

```text
Assign Technician
QC
Create Delivery
Complete POD
Create Invoice
Record Payment
```

---

# 13. Master Data Flow

## Master Pages

Routes:

```text
/master/clinics
/master/doctors
/master/patients
/master/lab-services
/master/technicians
```

Common components:

```text
Search
Create Button
Data Table
Edit Modal
Delete Confirmation
Pagination
```

Common actions:

```text
Create
Edit
Delete
View
```

---

# 14. Settings Flow

## Users

Route:

```text
/settings/users
```

Features:

```text
Create User
Edit User
Deactivate User
Assign Role
```

---

## Roles

Route:

```text
/settings/roles
```

Features:

```text
Create Role
Edit Role
Assign Permissions
```

---

# 15. Reports Flow

## Reports Menu

```text
Order Report
Delivery Report
QC Report
Revenue Report
```

Common filters:

```text
Date Range
Clinic
Doctor
Status
Export PDF
Export Excel
```

---

## Order Report

Shows:

```text
Total Orders
Orders by Status
Orders by Clinic
Overdue Orders
```

---

## Delivery Report

Shows:

```text
Total Deliveries
Delivered Orders
Pending Deliveries
POD Completed
```

---

## QC Report

Shows:

```text
QC Passed
QC Rejected
QC Revision
QC by User
```

---

## Revenue Report

Shows:

```text
Total Invoice
Paid Amount
Outstanding Amount
Revenue by Period
```

---

# 16. Page List by Sprint

## Sprint 1

```text
Login
Dashboard
Users
Roles
Permissions
```

---

## Sprint 2

```text
Clinics
Doctors
Patients
Lab Services
Technicians
```

---

## Sprint 3

```text
Lab Order List
Create Lab Order
Lab Order Detail
Upload Attachments
```

---

## Sprint 4

```text
Production Assignment List
My Assignments
Assignment Detail
```

---

## Sprint 5

```text
Pending QC
QC Detail
QC History
```

---

## Sprint 6

```text
Delivery List
My Deliveries
Delivery Detail
POD Form
```

---

## Sprint 7

```text
Invoice List
Invoice Detail
Payment List
Record Payment
```

---

## Sprint 8

```text
Dashboard
Order Report
Delivery Report
QC Report
Revenue Report
```

---

# 17. UI Permission Rules

## Admin Lab

Can access:

```text
Dashboard
Lab Orders
Production
QC View
Delivery
Master Data
Reports
```

---

## Technician

Can access:

```text
Dashboard
My Assignments
Completed Jobs
```

---

## QC

Can access:

```text
Dashboard
Pending QC
QC History
```

---

## Courier

Can access:

```text
Dashboard
My Deliveries
Delivery History
POD Form
```

---

## Finance

Can access:

```text
Dashboard
Invoices
Payments
Outstanding
Revenue Reports
```

---

## Doctor

Can access:

```text
Dashboard
My Orders
Order History
Invoice View
```

---

# 18. Key UI Decisions

```text
1. Order Detail menjadi halaman pusat operasional.
2. Semua aktivitas order dapat dilihat dari Order Detail.
3. Kurir memiliki halaman POD khusus.
4. Dokter hanya memiliki akses read-only.
5. Finance hanya mengelola invoice dan payment.
6. Technician hanya melihat assignment miliknya.
7. Dashboard disesuaikan berdasarkan role.
8. Menu sidebar dinamis berdasarkan permission.
```

---

# 19. V2 UI Candidates

Fitur UI berikut tidak masuk V1:

```text
Customer Portal
Mobile App
WhatsApp Notification Center
GPS Courier Tracking Map
Inventory Material Dashboard
Advanced BI Dashboard
```
