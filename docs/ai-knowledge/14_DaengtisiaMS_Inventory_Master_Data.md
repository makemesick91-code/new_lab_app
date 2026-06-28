# DaengtisiaMS — Inventory Master Data

## Tujuan
Master data inventory: produk, kategori, gudang/lokasi, supplier, unit, threshold, dan scope cabang.

## Ringkasan
Master inventory branch-scoped di `inv_*` tables. Produk punya reorder point dan optional batch tracking. Lokasi stok per cabang.

## Konteks DaengtisiaMS
Inventory Core Sprint 12+. Semua stok derived dari ledger — master data tidak menyimpan qty akhir.

## File / Area Repo Terkait
- `app/Modules/Inventory/Models/Product.php`, `ProductCategory.php`, `ProductUnit.php`
- `app/Modules/Inventory/Models/InventoryLocation.php`, `Supplier.php`
- `app/Modules/Inventory/Models/InventoryBatch.php`
- `app/Modules/Inventory/Controllers/ProductController.php`
- `app/Modules/Inventory/Controllers/InventoryLocationController.php`
- `database/migrations/2026_06_04_120000_create_inventory_core_tables.php`
- `docs/inventory_rules.md`
- `tests/Feature/Inventory/ProductTest.php`

## Aturan Utama

### Entitas master
| Entitas | Tabel | Catatan |
|---|---|---|
| Kategori produk | `inv_product_categories` | `branch_id` |
| Satuan | `inv_product_units` | |
| Produk/material | `inv_products` | `sku`, `average_cost`, `reorder_point`, `reorder_quantity`, `alert_enabled`, `requires_batch_tracking` |
| Supplier | `inv_suppliers` | |
| Lokasi gudang/ruang | `inv_inventory_locations` | per branch |
| Minimum per lokasi-produk | `inv_location_product_minimums` | threshold |
| Batch/lot | `inv_inventory_batches` | jika `requires_batch_tracking` |

### Branch inventory
- Semua master branch-owned membawa `branch_id`
- Query via `BranchContext::requireId()` / `inventoryBranchId()`
- MAIN preferred untuk inventory fallback context

### Room stock
- Konsep stok per **inventory location** (bukan ruang RME `mst_clinic_rooms`)
- Room stock report: `inventory.reports.room-stock.refill-checklist`

### Product import
- Route: `inventory.products.import`
- CSV native parse (mirip product import pattern)

### Lifecycle
- `is_active` — deactivate, jangan hard delete jika sudah ada movement

## Workflow / Alur
**Setup cabang baru:**
1. Pastikan `mst_branches.is_inventory_enabled = true`
2. Buat lokasi inventory
3. Buat kategori, satuan, supplier
4. Buat produk
5. Opening stock via movement `OPENING`

## Struktur Teknis
Routes prefix `inventory.`:
- `inventory.products.*`
- `inventory.product-categories.*`
- `inventory.product-units.*`
- `inventory.suppliers.*`
- `inventory.locations.*`
- `inventory.batches.*`
- `inventory.location-minimums.*`

**Permissions:** `manage_inventory`, `view_inventory`

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan tambah kolom stok di `inv_products`
- Jangan expose produk cabang lain di dropdown operator
- Jangan hapus produk yang punya ledger history

## Checklist Validasi
- [ ] Branch isolation pada CRUD produk
- [ ] Inactive product ditolak untuk movement baru
- [ ] Import tidak leak branch

## Catatan untuk AI
"Warehouse" operasional = role **Admin Warehouse** dengan landing inventory executive dashboard.

Executive dashboard: `inventory.executive-dashboard` — permission `view_inventory_executive_dashboard`.
