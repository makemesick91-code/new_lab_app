# DaengtisiaMS — Procurement (PR / PO / GR)

## Tujuan
Alur Purchase Request, Purchase Order, Goods Receipt, approval, void, dan dampak ledger.

## Ringkasan
Procurement tiga tahap: PR → PO → GR. Approval terpisah per permission. GR posted menciptakan movement `PURCHASE` di ledger.

## Konteks DaengtisiaMS
Modul procurement ada di dalam `app/Modules/Inventory` — bukan modul terpisah.

## File / Area Repo Terkait
- `app/Modules/Inventory/Models/PurchaseRequest.php`, `PurchaseOrder.php`, `GoodsReceipt.php`
- `app/Modules/Inventory/Services/PurchaseRequestService.php`
- `app/Modules/Inventory/Services/PurchaseOrderService.php`
- `app/Modules/Inventory/Services/GoodsReceiptService.php`
- `app/Modules/Inventory/Policies/PurchaseRequestPolicy.php`, `PurchaseOrderPolicy.php`, `GoodsReceiptPolicy.php`
- `tests/Feature/Inventory/PurchaseOrderPolicyTest.php`
- `tests/Feature/Inventory/GoodsReceiptLedgerTest.php`

## Aturan Utama

### Purchase Request (PR)
- Tabel: `trx_purchase_requests`, `trx_purchase_request_items`
- Workflow: draft → submit → approve/reject → cancel
- Routes: `inventory.purchase-requests.*`
- Approve permission: `approve_inventory_purchase_request`

### Purchase Order (PO)
- Tabel: `trx_purchase_orders`, `trx_purchase_order_items`
- Workflow: draft → submit → approve → send → cancel
- `quantity_received` tracked on PO items
- Approve permission: `approve_inventory_purchase_order`
- Routes: `inventory.purchase-orders.*`

### Goods Receipt (GR)
- Tabel: `trx_goods_receipts`, `trx_goods_receipt_items`
- Workflow: create → submit → post (ledger) → void/cancel
- Batch fields on items jika produk requires batch
- Posting → `InventoryMovement::TYPE_PURCHASE`
- Routes: `inventory.goods-receipts.*`
- Permissions: `view_goods_receipt`, `manage_goods_receipt`

### Void & reversal
- Void GR → service harus reverse/adjust ledger (jangan orphan movement)
- Cancel PO/PR — ikuti status guards di service

### Branch
- Semua dokumen procurement branch-scoped
- Supplier & location harus same branch

## Workflow / Alur
```text
1. Operator: buat PR → submit
2. Approver: approve PR
3. Buat PO dari PR (atau manual) → submit → approve → send
4. GR: terima barang against PO → submit → post
5. Ledger PURCHASE movements created
6. PO item quantity_received updated
```

## Struktur Teknis
| Status | Entity | TODO detail status constants |
|---|---|---|
| PR/PO/GR | Masing-masing model | Verifikasi konstanta di model jika implementasi |

Activity logging via `LogsInventoryActivity` concern.

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan post GR tanpa movement ledger
- Jangan increase stock tanpa GR/receive workflow
- Jangan bypass approval permission di controller saja

## Checklist Validasi
- [ ] `GoodsReceiptLedgerTest`
- [ ] PO receive tidak melebihi qty ordered
- [ ] Void GR mengurangi stok dengan benar
- [ ] Branch isolation

## Catatan untuk AI
Hotfix docs: `docs/hotfix_goods_receipt_create_po_batch_panel_rendering.md` — UI batch panel GR.

**TODO:** Daftar status enum lengkap PR/PO/GR — baca model masing-masing saat implementasi.
