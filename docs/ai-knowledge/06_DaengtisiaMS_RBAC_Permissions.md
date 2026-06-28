# DaengtisiaMS — RBAC & Permissions

## Tujuan
Dokumentasi role, permission, policy, middleware, dan risiko akses cabang.

## Ringkasan
RBAC via Spatie Permission. Role di-seed `RoleSeeder`; permission di `PermissionSeeder`. Super Admin bypass via `Gate::before`.

## Konteks DaengtisiaMS
UI sidebar memakai `@can` tetapi **bukan** satu-satunya proteksi — route middleware + policy wajib.

## File / Area Repo Terkait
- `database/seeders/PermissionSeeder.php`
- `database/seeders/RoleSeeder.php`
- `app/Providers/RepositoryServiceProvider.php`
- `app/Modules/*/Policies/`
- `routes/web.php`
- `tests/Feature/Auth/SupervisorRmeRolePermissionTest.php`
- `tests/Feature/Navigation/SidebarPermissionVisibilityTest.php`
- `tests/Feature/Pilot/PilotRouteAuthorizationTest.php`

## Aturan Utama

### Konvensi penamaan permission
Dua gaya coexist (jangan rename sembarangan):
- **Spasi:** `manage patients`, `view dashboard`
- **Underscore:** `view_lab_orders`, `manage_inventory`

### Role resmi (`RoleSeeder::ROLE_PERMISSIONS`)
| Role | Ringkasan akses |
|---|---|
| **Super Admin** | Semua permission (`*`) |
| **Admin Lab** | Lab penuh + inventory + RME visit + billing |
| **Admin Klinik** | Pasien + RME visit + billing |
| **Technician** | Production read/work + QC view |
| **Quality Control** | QC workflow |
| **Delivery Coordinator** | Delivery |
| **Courier** | Delivery lapangan |
| **Finance** | Invoice/payment lab + laporan |
| **Doctor** | RME visit (tanpa billing) + treatment worklist |
| **Owner** | Dashboard owner, laporan, inventory executive read, branch master view |
| **Kasir** | RME billing + payment reports |
| **Perawat** | Pasien + RME visit + worklist |
| **Admin Warehouse** | Inventory executive + procurement approve |
| **Supervisor RME** | Seluruh RME (pasien, visit, RM, odontogram, kasir, piutang, laporan RME) — **tanpa** lab/inventory/settings |
| **Laporan Pasien RME** | `view_rme_patient_reports` |
| **Laporan Pembayaran RME** | `view_rme_payment_reports` |

### Permission inventaris granular (contoh)
`view_stock_opname`, `manage_stock_transfer`, `approve_inventory_purchase_request`, `view_goods_receipt`, `view_inventory_executive_dashboard`, `view_inventory_cross_branch_analytics`, dll.

### Permission RME (contoh)
`view_clinic_visits`, `manage_clinic_visits`, `manage_rme_billing`, `view_treatment_worklist`, `view_rme_patient_reports`, `view_rme_payment_reports`

### Permission dashboard
`view dashboard`, `view_owner_dashboard`, `view_branch_dashboard`

### Middleware
```php
->middleware('permission:view_clinic_visits|manage_clinic_visits')
```
Spatie middleware `permission`, `role`, `role_or_permission`

### Policy
Setiap modul domain punya Policy terdaftar di `RepositoryServiceProvider::$policies`. Policy harus cek permission **dan** branch ownership.

## Workflow / Alur
1. Tambah permission baru → `PermissionSeeder::PERMISSIONS`
2. Assign ke role di `RoleSeeder`
3. Gate di route + policy
4. Test denied + allowed paths
5. `php artisan permission:cache-reset` atau seeder di environment dev

## Struktur Teknis
- Guard: `web`
- Tables: `roles`, `permissions`, `model_has_roles`, `role_has_permissions`
- User model: Spatie `HasRoles`

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan hapus permission lama yang masih direferensikan route/test
- Jangan andalkan sidebar saja untuk security
- Supervisor RME sengaja **tidak** bypass gate server-side (Sprint 62.1) — hanya permission luas, bukan superuser

## Checklist Validasi
- [ ] Permission baru ada di seeder
- [ ] Role mapping diperbarui
- [ ] Route middleware match
- [ ] Policy `view/update/delete` cek branch
- [ ] Test 403 untuk role yang tidak berhak
- [ ] Test branch isolation terpisah dari permission

## Catatan untuk AI
**Risiko akses cabang:** Permission `view_inventory_cross_branch_analytics` dan Owner KPI memungkinkan agregat lintas cabang untuk **read-only executive** — jangan samakan dengan operator branch yang harus `BranchContext::requireId()`.

**TODO:** Daftar lengkap policy per model — ekstrak dari `RepositoryServiceProvider::$policies` jika audit menyeluruh diperlukan.
