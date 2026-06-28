# DaengtisiaMS — Peta Route

## Tujuan
Memetakan route penting, prefix, nama route, controller, middleware, dan permission gate.

## Ringkasan
~359 route terdaftar. Route utama dikelompokkan: `dashboard`, `settings.*`, `rme.*`, `inventory.*`, `lab-*`, `reports.*`, resource lab workflow.

## Konteks DaengtisiaMS
Semua route protected memakai `auth` + Spatie `permission:` middleware. RME clinical routes tambahan `visit.room`.

## File / Area Repo Terkait
- `routes/web.php`
- `routes/auth.php`
- `bootstrap/app.php` — alias middleware
- Controller di `app/Modules/*/Controllers/`

## Aturan Utama
- Jangan ubah nama route publik tanpa permintaan eksplisit (regresi test/link)
- Permission di route adalah lapisan pertama; policy adalah lapisan kedua
- Route model binding harus di-scope branch di repository/policy

## Workflow / Alur
Verifikasi route: `php artisan route:list | rg "keyword"`

## Struktur Teknis

### Dashboard
| Method | URI | Name | Controller | Middleware |
|---|---|---|---|---|
| GET | `/dashboard` | `dashboard` | `HomeDashboardController@index` | `auth`, `verified`, `permission:view dashboard\|view_owner_dashboard` |

### Settings (`settings.*`) — prefix `/settings`
| Grup | Permission contoh | Resource |
|---|---|---|
| Users | `manage users` | `settings.users.*` |
| Roles | `manage roles` | `settings.roles.*` |
| Permissions | `manage permissions` | `settings.permissions.index` |
| Clinics, Doctors, Patients | `manage clinics/doctors/patients` | CRUD + activate |
| Patient import legacy | `manage patients` | `settings.patients.import.*` |
| Lab services, Technicians | `manage lab services/technicians` | CRUD |
| Clinic master | `view_clinic_master_data\|manage_clinic_master_data` | clinic-rooms, treatments, tariffs, payment-methods, wa-reminder-templates |
| Branches | `view_branch_master_data\|manage_branch_master_data` | `settings.branches.*` |

### RME (`rme.*`) — prefix `/rme`
| URI (ringkas) | Name | Permission / Middleware |
|---|---|---|
| `rme/dashboard` | `rme.dashboard` | `view_clinic_visits\|manage_clinic_visits` |
| `rme/patient-queue` | `rme.patient-queue.index` | idem |
| `rme/visits` | `rme.visits.*` | idem |
| `rme/visits/{visit}/room` PATCH | `rme.visits.assign-room` | `manage_clinic_visits` |
| `rme/visits/{visit}/transition` POST | `rme.visits.transition` | `manage_clinic_visits` |
| `rme/visits/{visit}/medical-record` | `rme.visits.medical-record.*` | `manage_clinic_visits` + `visit.room` |
| `rme/visits/{visit}/odontogram` | `rme.visits.odontogram.show` | `visit.room` |
| `rme/odontograms/{odontogram}` PATCH | `rme.odontograms.update` | `manage_clinic_visits` + `visit.room` |
| `rme/odontograms/{odontogram}/print` | `rme.odontograms.print` | `view_clinic_visits\|manage_clinic_visits` |
| `rme/visits/{visit}/print`, `/pdf` | `rme.visits.print`, `rme.visits.pdf` | idem |
| `rme/treatment-room-worklist` | `rme.treatment-room-worklist.index` | `view_treatment_worklist` |
| `rme/medical-records` | `rme.medical-records.index` | `view_clinic_visits\|manage_clinic_visits` |
| `rme/patients/audit` | `rme.patients.audit` | TODO: verifikasi permission di controller/policy |
| `rme/reports/patients` | `rme.reports.patients` | `view_rme_patient_reports` |
| `rme/reports/payments` | `rme.reports.payments` | `view_rme_payment_reports` |
| `rme/cashier/*` | `rme.cashier.*` | `manage_rme_billing` |

### Inventory (`inventory.*`) — prefix `/inventory`
CRUD master: products, categories, units, suppliers, locations, batches, location-minimums

Workflow:
- `inventory/stock-opnames.*` — opname
- `inventory/stock-transfers.*` — transfer (+ ship/receive/cancel/checklist)
- `inventory/purchase-requests.*`, `purchase-orders.*`, `goods-receipts.*`
- `inventory/stock`, `stock-card`, adjust-in/out, opening-stock, receive-stock
- `inventory/analytics`, `executive-dashboard`, `reports`, `alerts`, `activity-logs`

### Lab workflow
| Prefix | Modul |
|---|---|
| `lab-orders` | LabOrder |
| `lab-case-candidates` | Lab case queue RME |
| `production-*`, `quality-control`, `deliveries` | Production, QC, Delivery |
| `invoices`, `payments` | Invoice/Payment lab |

### Reporting
| URI | Name |
|---|---|
| `reports/dashboard` | `reports.dashboard` |
| Export controllers | `ExportReportController` |

**TODO:** Jalankan `php artisan route:list` lengkap untuk daftar lab/production/QC route — tidak semua diekstrak ke dokumen ini.

## Hal yang Tidak Boleh Diubah Sembarangan
- Middleware `visit.room` pada RM/Odontogram — jangan dihapus tanpa spec
- Route cashier RME — gate `manage_rme_billing`
- Route approval procurement — `approve_inventory_purchase_request/order`

## Checklist Validasi
- [ ] `php artisan route:list | rg <nama_route>`
- [ ] Test authorization (`PilotRouteAuthorizationTest`, modul-specific)
- [ ] Sidebar link match `Route::has()` / `@can`
- [ ] Tidak ada route tanpa middleware auth untuk data operasional

## Catatan untuk AI
Gunakan `routes/web.php` sebagai sumber utama. Nama route adalah kontrak untuk Blade `route()` dan test `assertRedirect(route(...))`.
