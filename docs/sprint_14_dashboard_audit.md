# Sprint 14.1 Dashboard Existing Audit

Date: 2026-06-06
Scope: docs-only audit sebelum implementasi Dashboard Owner dan Dashboard Cabang.

## Workflow Notes

- `context-mode`: tidak tersedia sebagai skill/tool di sesi ini; audit dilakukan dengan pembacaan selektif file prioritas sesuai aturan context-mode.
- `claude-mem`: tidak tersedia sebagai skill/tool di sesi ini; memory project diambil dari dokumen permanen (`ai_bootstrap_prompt`, `architecture_rules`, `ai_development_guide`, `inventory_rules`, `sprint_history`, dan completion summary Sprint 13).
- `superpower`: tidak tersedia sebagai skill/tool di sesi ini; checklist audit dijalankan manual sebelum menulis dokumen.
- `frontend-design`: tidak digunakan karena tugas ini docs-only.
- `skill-creator` tidak digunakan karena belum ada pola baru yang perlu dijadikan skill.

## Audit Checklist

- [x] Baca konteks arsitektur, branch isolation, inventory ledger-only, permission, dan sprint history.
- [x] Audit route dashboard existing.
- [x] Audit view dashboard existing dan komponen dashboard reusable.
- [x] Audit role/permission yang dapat mengakses dashboard.
- [x] Audit data source, tabel, service, repository yang tersedia.
- [x] Identifikasi gap untuk Dashboard Owner dan Dashboard Cabang.
- [x] Rekomendasikan implementasi Sprint 14 tanpa mengubah kode aplikasi.

## Dashboard Route Existing

### Landing Dashboard

- Route: `GET /dashboard`
- Name: `dashboard`
- Definition: `routes/web.php`
- Handler: closure langsung `return view('dashboard')`
- Middleware: `auth`, `verified`
- Permission middleware: belum tersedia.
- Controller/service/repository khusus: belum tersedia.
- Catatan audit: semua user authenticated + verified dapat membuka route ini. Pemilihan tampilan Owner vs Admin Cabang terjadi di Blade berdasarkan permission operasional user, bukan melalui controller/service/policy.

### Reports Dashboard

- Route: `GET /reports/dashboard`
- Name: `reports.dashboard`
- Definition: `routes/web.php`
- Handler: `App\Modules\Reporting\Controllers\DashboardController@index`
- Middleware: `auth`, `permission:view_dashboard|manage_report`
- Gate/policy: `reporting.dashboard` melalui `ReportingPolicy::viewDashboard()`
- Request: `DashboardRequest`
- Service: `DashboardService`
- Repository: `ReportingRepository`
- View: `reports.dashboard`
- Catatan audit: dashboard ini sudah mengikuti flow controller -> request -> service -> repository -> view, tetapi repository reporting existing belum branch-scoped secara default.

### Inventory Dashboard

- Route: `GET /inventory/dashboard`
- Name: `inventory.dashboard`
- Definition: `routes/web.php`
- Handler: `App\Modules\Inventory\Controllers\InventoryDashboardController@index`
- Middleware: `auth` pada group inventory.
- Permission middleware: belum dipasang pada route dashboard inventory, tetapi controller memanggil policy.
- Policy: `InventoryMovementPolicy::viewAny()`
- Permission efektif: `view_inventory`, `manage_inventory`, atau legacy `manage master data`.
- Service: `InventoryStockService`, `InventoryLocationService`
- Repository: `InventoryMovementRepository`, `InventoryLocationRepository`
- View: `inventory.dashboard`
- Catatan audit: data inventory sudah branch-aware melalui `BranchContext::requireId()` dan repository dengan parameter `int $branchId`.

## View Dashboard Existing

### Landing Owner/Branch Dashboard

- File: `resources/views/dashboard.blade.php`
- Shell/layout: `<x-app-layout>` + `layouts.sidebar`
- Mode Owner: ditampilkan jika user tidak memiliki permission operasional pada daftar `$branchOperationalPermissions`.
- Mode Admin Cabang: ditampilkan jika user memiliki salah satu permission operasional:
  `view_lab_orders`, `manage_lab_orders`, `view_production`, `manage_production`, `view_quality_control`, `manage_quality_control`, `view_delivery`, `manage_delivery`, `view_inventory`, `manage_inventory`, `view_invoice`, `manage_invoice`.
- Data live: belum tersedia di landing dashboard; Blade memakai array default/fallback berisi nilai nol dan empty state.
- Risiko: business/data-preparation logic berada di Blade, bukan service. Ini perlu dipindahkan pada Sprint 14.

### Branch Admin Partial

- File: `resources/views/dashboards/branch-admin.blade.php`
- Komponen reusable:
  - `branch-dashboard.daily-summary-card`
  - `branch-dashboard.dashboard-section`
  - `branch-dashboard.queue-card`
  - `branch-dashboard.workload-widget`
  - `branch-dashboard.inventory-alert-widget`
  - `branch-dashboard.finance-alert-widget`
  - `branch-dashboard.quick-action-panel`
  - `owner-dashboard.alert-panel`
- Data live: belum tersedia; menerima array dari parent Blade dan menampilkan empty state bila kosong.

### Reports Dashboard View

- File: `resources/views/reports/dashboard.blade.php`
- Data live: tersedia dari `Reporting\DashboardService`.
- Filter: date range dan clinic.
- Catatan audit: cocok untuk reuse pola KPI/reporting, tetapi perlu branch-scope sebelum dipakai sebagai sumber Dashboard Cabang.

### Inventory Dashboard View

- File: `resources/views/inventory/dashboard.blade.php`
- Data live: tersedia dari `InventoryStockService`.
- Komponen reusable:
  - `inventory.dashboard-section`
  - `inventory.stock-value-card`
  - `inventory.location-card`
  - `inventory.low-stock-widget`
  - `inventory.movement-timeline`
- Catatan audit: ini sumber paling siap untuk widget persediaan cabang karena sudah ledger-derived dan branch-aware.

## Role yang Bisa Akses Dashboard

### Landing `/dashboard`

Semua user authenticated + verified dapat mengakses `/dashboard`. Tidak ada permission middleware pada route landing.

Mapping dari seeder:

- `Super Admin`: punya semua permission. Karena punya permission operasional, landing saat ini cenderung masuk mode Admin Cabang, bukan Owner.
- `Admin Lab`: punya permission operasional, reporting, invoice, inventory; masuk mode Admin Cabang.
- `Technician`: punya `view_lab_orders`, `view_production`, `view_quality_control`, `view_delivery`, `view_invoice`, `view_payment`, `view_dashboard`, `view_production_report`, `view_inventory`; masuk mode Admin Cabang.
- `Quality Control`: punya permission operasional QC/produksi/delivery/inventory; masuk mode Admin Cabang.
- `Delivery Coordinator`: punya permission delivery/lab order/report; masuk mode Admin Cabang.
- `Courier`: punya `view_lab_orders`, `view_delivery`; masuk mode Admin Cabang.
- `Finance`: punya `view_invoice`, payment, report; masuk mode Admin Cabang.
- `Doctor`: punya `view_lab_orders`; masuk mode Admin Cabang.
- User dengan `manage_report` saja pada test existing masuk mode Owner.

Catatan audit: belum ada role/permission eksplisit untuk "Owner Dashboard". Jika Sprint 14 membutuhkan dashboard owner khusus, sebaiknya gunakan permission/gate eksplisit atau policy service yang jelas, bukan hanya "tidak punya permission operasional".

### Reports Dashboard

Role yang punya `view_dashboard` atau `manage_report` bisa mengakses `reports.dashboard`.

Seeder saat ini memberi `view_dashboard`/`manage_report` kepada:

- `Super Admin`
- `Admin Lab`
- `Technician` (`view_dashboard`)
- `Quality Control` (`view_dashboard`)
- `Delivery Coordinator` (`view_dashboard`)
- `Finance` (`view_dashboard`)

`Courier` dan `Doctor` tidak terlihat mendapat `view_dashboard` underscore pada `RoleSeeder`, walaupun punya legacy `view dashboard`.

### Inventory Dashboard

User dengan `view_inventory`, `manage_inventory`, atau legacy `manage master data` dapat lolos policy `InventoryMovementPolicy::viewAny()`.

Seeder memberi akses inventory kepada:

- `Super Admin`
- `Admin Lab`
- `Technician` (`view_inventory`)
- `Quality Control` (`view_inventory`)

Role lain bisa mengakses jika diberikan permission secara manual.

## Data Source Tersedia

### Tersedia dan Sudah Branch-Aware

- `BranchContext`: resolver cabang aktif melalui `requireId()`.
- Inventory summary:
  - nilai persediaan
  - low stock count
  - out of stock count
  - stock by location
  - low stock products
  - recent inventory movements
- Stock opname Sprint 13:
  - header stock opname
  - item variance
  - status `DRAFT`, `COUNTING`, `COMPLETED`, `CANCELLED`
  - finalisasi posting ledger adjustment

### Tersedia tetapi Branch Scope Perlu Diperketat

- Reporting dashboard summary dari `DashboardService`.
- Order status count, order by status, order by clinic.
- Production workload.
- QC result summary dan remake count.
- Delivery status summary dan courier performance.
- Invoice revenue, outstanding, overdue invoice.
- Payment by method/user.

Catatan audit: query `ReportingRepository` membaca tabel transaksi lama tanpa default `BranchContext::requireId()` dan tanpa filter `branch_id` otomatis. Untuk Dashboard Cabang, query ini belum aman dipakai apa adanya.

### Belum Tersedia

- `OwnerDashboardController`, `OwnerDashboardService`, `OwnerDashboardRepository`.
- `BranchDashboardController`, `BranchDashboardService`, `BranchDashboardRepository`.
- Service khusus untuk menyusun data landing `/dashboard`.
- Permission/gate eksplisit untuk dashboard owner.
- Permission/gate eksplisit untuk dashboard cabang.
- User-to-branch assignment permanen di schema `users` atau relasi `users()->branches()`; `BranchContext` sudah mendukungnya jika ada, tetapi schema/model current belum menyediakan kolom/relasi tersebut.
- Filter branch resmi untuk reports dashboard.
- KPI owner lintas cabang yang benar-benar live dan terkonsolidasi.
- KPI cabang live yang mengambil antrean operasional lintas modul secara branch-safe.

## Tabel yang Bisa Dipakai

### Branch dan Auth

- `mst_branches`
- `users`
- Spatie tables: `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`

### Operasional Order dan Produksi

- `trx_lab_orders`
- `trx_lab_order_items`
- `trx_lab_order_status_logs`
- `trx_lab_order_assignments`
- `trx_lab_work_logs`
- `trx_lab_production_steps`

### QC

- `trx_lab_quality_controls`
- `trx_lab_qc_checklists`
- `trx_lab_remake_requests`

### Delivery

- `trx_lab_deliveries`

### Finance

- `trx_invoices`
- `trx_invoice_items`
- `trx_payments`

### Master Data

- `mst_clinics`
- `mst_doctors`
- `mst_patients`
- `mst_lab_services`
- `mst_technicians`

### Inventory

- `inv_product_categories`
- `inv_product_units`
- `inv_products`
- `inv_suppliers`
- `inv_inventory_locations`
- `trx_inventory_movements`
- `trx_stock_opnames`
- `trx_stock_opname_items`

## Service/Repository yang Bisa Di-Reuse

### Siap Reuse Langsung

- `BranchContext`
  - `requireId()`
  - `branch()`
  - `forUser(User $user)`
- `BranchRepository`
  - `listActive()`
  - `findById()`
  - `defaultBranch()`
- `InventoryStockService`
  - `getBranchSummary()`
  - `getStockByLocationSummary()`
  - `getLowStockProducts()`
  - `getRecentMovements()`
  - `getInventoryValue()`
- `InventoryMovementRepository`
  - `stockByLocationSummary(int $branchId)`
  - `lowStockProducts(int $branchId, ?int $locationId = null)`
  - `inventoryValue(int $branchId, ?int $locationId = null)`
  - `recentMovements(int $branchId, int $limit = 10)`
- `InventoryLocationService`
  - `listActive()`

### Reuse dengan Penyesuaian Branch Scope

- `Reporting\DashboardService`
- `ReportingRepository`
- `OrderReportService`, `ProductionReportService`, `QcReportService`, `DeliveryReportService`, `InvoiceReportService`, `PaymentReportService`, `RevenueReportService`

Rekomendasi: jangan reuse query reporting existing untuk Dashboard Cabang sebelum ditambah jalur branch-aware, misalnya `branch_id` berasal dari `BranchContext::requireId()` di service dashboard, bukan dari request.

### Reuse Terbatas

- `LabOrderRepository`, `DeliveryRepository`, `InvoiceRepository` memiliki komentar TODO branch-scope dan filter `branch_id` opt-in. Mereka bisa menjadi referensi pola table/query, tetapi belum cukup aman untuk agregasi cabang tanpa wrapper service yang memaksa branch_id.
- Repository Production/QC lama perlu dicek per method saat implementasi karena sebagian join melewati `trx_lab_orders`; branch scope harus diterapkan di root table atau join order yang memiliki `branch_id`.

## Gap Implementasi

1. Landing dashboard belum punya controller, form request, service, repository, atau policy/gate khusus.
2. Data live Owner Dashboard belum tersedia; semua KPI owner di landing masih fallback nol/empty state.
3. Data live Branch Dashboard belum tersedia; ringkasan harian, queues, workload, inventory alert, dan finance alert masih array kosong/default di Blade.
4. Pemilihan Owner vs Admin Cabang memakai daftar permission operasional di Blade. Ini mudah salah untuk Super Admin dan user multi-permission.
5. Route `/dashboard` hanya dilindungi `auth` dan `verified`, belum oleh permission/policy dashboard.
6. Reporting repository existing belum branch-aware secara default, sehingga tidak boleh langsung menjadi sumber Dashboard Cabang.
7. User branch assignment belum tersedia secara schema/model. `BranchContext` fallback ke MAIN branch jika user tidak punya kolom/relasi branch.
8. Belum ada permission eksplisit seperti `view_owner_dashboard` atau `view_branch_dashboard`.
9. Belum ada tests untuk data aggregation dashboard owner/cabang, branch isolation dashboard, atau role-based dashboard selection.
10. Belum ada service/repository khusus untuk branch performance lintas cabang.
11. Belum ada query owner lintas cabang yang aman dan intentional; owner dashboard lintas cabang harus membedakan kebutuhan "semua cabang" dari branch-scoped dashboard.
12. Belum ada dokumentasi final Sprint 14 untuk KPI mana yang wajib live vs masih empty-state-safe.

## Rekomendasi Implementasi Sprint 14

### Prinsip Umum

- Tetap docs/code additive, jangan mengubah business rule Sprint 0-13.
- Pertahankan modular monolith.
- Untuk semua data cabang, gunakan `BranchContext::requireId()` di service.
- Jangan membaca `branch_id` dari request user.
- Jangan menghitung stock dari kolom produk; inventory KPI wajib memakai ledger `trx_inventory_movements`.
- Jangan memindahkan query ke Blade.

### Struktur yang Disarankan

Pilihan paling konsisten adalah membuat module/dashboard layer kecil di module yang sudah ada atau module baru hanya jika ownership jelas. Rekomendasi awal:

- Controller landing dashboard menggantikan route closure `/dashboard`.
- `DashboardRequest` jika ada filter periode.
- `DashboardService` khusus landing untuk memilih mode dashboard dan menyusun view model.
- Repository read-only dashboard untuk agregasi lintas modul.
- Policy/gate dashboard eksplisit.
- Tests feature untuk owner, cabang, permission, empty state, dan branch isolation.

Jika dibuat module baru, gunakan `app/Modules/Dashboard`. Jika tidak, letakkan di module `Reporting` hanya bila owner/branch dashboard dianggap read-only reporting surface. Hindari menaruh query dashboard di `routes/web.php` atau Blade.

### Dashboard Cabang

Implementasi cabang harus:

- Resolve `$branchId = BranchContext::requireId()`.
- Filter semua query transaksi dengan `branch_id`.
- Untuk tabel yang tidak memiliki `branch_id`, join melalui transaksi induk yang punya `branch_id`.
- Reuse `InventoryStockService` untuk persediaan.
- Buat query antrean:
  - order masuk hari ini
  - perlu penugasan
  - perlu QC
  - siap pengiriman
  - invoice belum dibayar
- Tambahkan tests cross-branch leakage untuk setiap widget utama.

### Dashboard Owner

Implementasi owner harus:

- Punya permission/gate eksplisit.
- Menentukan apakah owner melihat semua cabang atau subset cabang secara intentional.
- Jika lintas cabang, gunakan agregasi by branch dari tabel branch-owned, bukan fallback branch MAIN.
- Reuse report services hanya setelah query dapat menerima branch scope/all-branches mode yang jelas.
- Menampilkan branch performance berdasarkan `mst_branches` + transaksi branch-owned.
- Tetap empty-state-safe jika data belum tersedia.

### Permission

Rekomendasi permission baru jika Sprint 14 menyentuh authorization:

- `view_owner_dashboard`
- `view_branch_dashboard`

Alternatif konservatif:

- Owner Dashboard: `manage_report` atau Super Admin.
- Branch Dashboard: permission operasional existing, tetapi keputusan mode tetap di service/policy, bukan Blade.

Jika menambah permission, update `PermissionSeeder`, `RoleSeeder`, policy/gate, dan tests.

### Quality Gate untuk Sprint 14 Implementasi

Saat implementasi dashboard live nanti:

- `php artisan test`
- focused tests dashboard jika full suite lambat
- `./vendor/bin/pint`
- `php artisan route:list --name=dashboard`
- `php artisan route:list --path=dashboard`
- `git diff --check`
- `npm run build` hanya jika menyentuh frontend asset, bukan Blade-only.

## Known Limitations Audit

- Audit ini tidak mengubah aplikasi dan tidak membuktikan query runtime baru.
- `claude-mem` tidak bisa dibaca/ditulis karena tool/skill tidak tersedia di sesi ini.
- Tidak ada route list runtime yang dijalankan untuk audit ini karena user hanya meminta `git diff -- docs/sprint_14_dashboard_audit.md` dan `git diff --check`.
- Reporting branch-scope belum diverifikasi lewat test baru karena tugas docs-only.

## Kesimpulan

Dashboard existing sudah punya fondasi UI yang baik untuk Owner dan Admin Cabang, plus dashboard Reports dan Inventory yang sudah berjalan. Gap terbesar sebelum Sprint 14 adalah pemindahan landing dashboard dari Blade fallback ke controller/service/repository branch-aware, penentuan permission dashboard yang eksplisit, dan hardening agregasi reporting agar tidak bocor lintas cabang.
