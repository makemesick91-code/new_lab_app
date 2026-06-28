# DaengtisiaMS — Inventory Ledger Rules

## Tujuan
Aturan ledger-only stock, tipe movement, perhitungan stok, stock card, dan valuasi.

## Ringkasan
Sumber kebenaran stok: `trx_inventory_movements`. Stok = SUM(quantity_in) - SUM(quantity_out) per branch + location + product (+ batch jika applicable).

## Konteks DaengtisiaMS
Konstitusi inventory — pelanggaran ledger adalah bug kritis.

## File / Area Repo Terkait
- `app/Modules/Inventory/Models/InventoryMovement.php`
- `app/Modules/Inventory/Services/InventoryStockService.php`
- `app/Modules/Inventory/Repositories/InventoryMovementRepository.php`
- `docs/inventory_rules.md`
- `tests/Feature/Inventory/GoodsReceiptLedgerTest.php`
- `tests/Feature/Inventory/StockTransferRequestTest.php`

## Aturan Utama

### Movement types (`InventoryMovement`)
| Type | Arah |
|---|---|
| `OPENING` | Saldo awal (in) |
| `PURCHASE` | Masuk dari goods receipt |
| `ADJUSTMENT_IN` | Koreksi masuk |
| `ADJUSTMENT_OUT` | Koreksi keluar |
| `TRANSFER_IN` | Terima transfer |
| `TRANSFER_OUT` | Kirim transfer |

### Formula stok
```sql
current_stock = COALESCE(SUM(quantity_in) - SUM(quantity_out), 0)
```
Scope wajib: `branch_id`, `product_id`, `inventory_location_id` (dan `inventory_batch_id` jika batch tracked)

### Larangan keras
- **Tidak boleh** kolom mutable: `current_stock`, `qty_on_hand`, `stock`, dll.
- **Tidak boleh** `$product->update(['stock' => ...])`
- Opname: hitung fisik di opname header/items; posting ledger hanya saat **finalize**

### Stock card
- Route: `inventory.products.stock-card`
- Filter movement type & periode

### Current stock index
- Route: `inventory.stock.index`
- Derived real-time / analytics summary

### Valuation
- `average_cost` on product × derived qty
- Analytics: `InventoryAnalyticsRepository`, summary tables `rpt_inventory_*`

### Adjustment out
- Cek stok lokasi sebelum outbound — reject insufficient

### Void/reversal
- Goods receipt void → movement reversal via service (bukan hapus sembarangan)
- Transfer cancel sebelum ship/receive — ikuti `StockTransferService`

### Activity log
- `inv_inventory_activity_logs` — audit trail operasi inventory

## Workflow / Alur
**Receive stock manual:**
1. `inventory.products.receive-stock` → movement PURCHASE atau dedicated receive
2. Atau via Goods Receipt posted → PURCHASE movements

**Adjust:**
- `adjust-in` / `adjust-out` routes per product

## Struktur Teknis
Tabel ledger: `trx_inventory_movements`
- `quantity_in`, `quantity_out` (decimal 18,4)
- `unit_cost`, `movement_date`
- `inventory_batch_id` nullable
- `reference_type` / `reference_id` — polymorphic ke sumber (GR, transfer, opname)

Service utama: `InventoryStockService`

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan implement transfer sebagai sepasang adjustment manual tanpa `StockTransferService`
- Jangan skip branch_id pada movement insert
- Jangan allow negative stock pada outbound (kecuali domain explicitly allows — inventory tidak)

## Checklist Validasi
- [ ] Ledger test: GR posting, transfer ship/receive, opname finalize
- [ ] Branch + location isolation
- [ ] Batch tracked product requires batch on movement
- [ ] Analytics SUM match manual ledger query

## Catatan untuk AI
Baca `docs/inventory_rules.md` sebelum **setiap** perubahan inventory.

Sprint 12–16 baseline: opening, purchase, adjustment, transfer, opname, GR semua harus konsisten ledger.
