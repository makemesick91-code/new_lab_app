# DaengtisiaMS — Stock Transfer & Opname

## Tujuan
Aturan transfer stok antar lokasi, stock opname, variance, checklist PDF, dan batch/lot.

## Ringkasan
Transfer: workflow submit → ship → receive (Sprint 15.2). Opname: count → review → finalize → ledger adjustment. Checklist PDF untuk transfer.

## Konteks DaengtisiaMS
Keduanya mem-post ke `trx_inventory_movements` dengan type TRANSFER_* atau ADJUSTMENT_*.

## File / Area Repo Terkait
- `app/Modules/Inventory/Services/StockTransferService.php`
- `app/Modules/Inventory/Services/StockOpnameService.php`
- `app/Modules/Inventory/Models/StockTransfer.php`, `StockOpname.php`
- `routes` — `inventory.stock-transfers.*`, `inventory.stock-opnames.*`
- `docs/sprint_17_2_stock_transfer_hardening_design.md`
- `docs/sprint_17_4_stock_transfer_checklist_pdf.md`
- `tests/Feature/Inventory/StockTransferRequestTest.php`
- `tests/Feature/Inventory/StockOpnameModelTest.php`

## Aturan Utama

### Stock Transfer
- Tabel: `trx_stock_transfers`, `trx_stock_transfer_items`
- Workflow (current): draft/edit → **submit** → **ship** (TRANSFER_OUT) → **receive** (TRANSFER_IN) di lokasi tujuan
- Cancel sebelum complete
- Batch: `inventory_batch_id` on transfer items
- Checklist PDF: `inventory.stock-transfers.checklist` — permission `download_stock_transfer_checklist`
- **Jangan** manual transfer via adjustment pair

### Stock Opname
- Tabel: `trx_stock_opnames`, `trx_stock_opname_items`
- Workflow: create → input counted qty per product → review → **finalize**
- Finalize: posting ADJUSTMENT_IN atau ADJUSTMENT_OUT untuk variance
- Cancel route available
- Counted qty update: `inventory.stock-opnames.update-counted-quantity`

### Variance
- variance = counted - system derived (saat finalize)
- System qty dari ledger, bukan kolom produk

### Batch/lot
- Produk `requires_batch_tracking` → wajib batch pada transfer/movement terkait
- Batch master: `inv_inventory_batches`

## Workflow / Alur
**Transfer:**
```text
Create transfer (from_location → to_location)
→ Submit → Ship (outbound movement) → In transit
→ Receive at destination (inbound movement)
```

**Opname:**
```text
Create opname session per location
→ Count products → Review variance
→ Finalize → ledger adjustments
```

## Struktur Teknis
Movement types: `TRANSFER_IN`, `TRANSFER_OUT`, `ADJUSTMENT_IN`, `ADJUSTMENT_OUT`

Permissions:
- `view_stock_transfer`, `manage_stock_transfer`
- `view_stock_opname`, `manage_stock_opname`

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan reintroduce single-step transfer complete (superseded Sprint 15.2)
- Jangan finalize opname tanpa transactional ledger post
- Jangan opname tanpa branch+location scope

## Checklist Validasi
- [ ] Transfer ship/receive ledger pair balance
- [ ] Insufficient stock blocked on ship
- [ ] Opname finalize creates correct adjustment direction
- [ ] Checklist PDF renders (branding Daengtisia per hotfix doc)

## Catatan untuk AI
Branding checklist: `docs/hotfix_stock_transfer_checklist_branding_daengtisia.md`

Status transfer exact names: baca `StockTransfer` model — includes `in_transit`, `received`, dll.
