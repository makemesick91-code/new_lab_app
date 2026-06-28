# DaengtisiaMS — Database Schema

## Tujuan
Merangkum tabel utama, relasi, field penting, dan risiko perubahan schema berdasarkan migration repo.

## Ringkasan
Satu database PostgreSQL. Prefix: `mst_` (master), `trx_` (transaksi), `inv_` (inventory master), `sys_` (sistem), `rpt_` (ringkasan laporan), `stg_` (staging import).

## Konteks DaengtisiaMS
Semua modul berbagi DB yang sama; isolasi via `branch_id` + policy, bukan DB terpisah.

## File / Area Repo Terkait
- `database/migrations/` (90 file migration)
- `docs/database_schema.md` — schema V1 lab (legacy; tidak lengkap untuk RME/inventory modern)
- `docs/erd.md`
- Model di `app/Modules/*/Models/`

## Aturan Utama
- Migration **additive only** di production/VPS — jangan `migrate:fresh` / `db:wipe`
- `branch_id` wajib di tabel transaksi branch-owned (migration `2026_06_04_104614_add_branch_id_to_core_transaction_tables.php`)
- Stok **tidak** disimpan sebagai kolom mutable di `inv_products`
- KTP/NIK pasien: field sensitif — jangan expose di export/UI tanpa masking

## Workflow / Alur
Perubahan schema: buat migration baru → update model fillable/casts → update repository → update test.

## Struktur Teknis

### Tabel sistem & auth
| Tabel | Fungsi |
|---|---|
| `users` | User aplikasi |
| `roles`, `permissions`, `model_has_roles`, `role_has_permissions` | Spatie RBAC |
| `sessions`, `cache`, `jobs`, `failed_jobs`, `job_batches` | Laravel infra |
| `sys_attachments` | Lampiran polimorfik |
| `sys_audit_logs` | Audit log |

### Master (`mst_*`)
| Tabel | Modul |
|---|---|
| `mst_branches` | Branch — `is_rme_enabled`, `is_inventory_enabled`, `code` |
| `mst_clinics`, `mst_doctors`, `mst_patients` | Master data |
| `mst_lab_services`, `mst_technicians` | Lab |
| `mst_clinic_rooms` | Ruangan RME |
| `mst_treatment_categories`, `mst_treatments`, `mst_tariffs` | Tindakan klinik |
| `mst_payment_methods` | Kasir |
| `mst_wa_reminder_templates` | Template WA manual |
| `mst_patient_documents` | Dokumen scan pasien (KTP) |

### RME / Klinik (`trx_*` terkait RME)
| Tabel | Catatan |
|---|---|
| `trx_clinic_visits` | Status visit, `clinic_room_id`, `initial_treatment_id` |
| `trx_medical_records` | RM per visit; kolom workspace: `canonical_visit_id`, `source_visit_id`, `sheet_number` |
| `trx_medical_record_handwritings` | Handwriting legacy page 1 |
| `trx_medical_record_handwriting_pages` | Halaman canvas RM 2+ |
| `trx_odontograms` | `tooth_map_payload`, finalized columns |
| `trx_rme_invoices`, `trx_rme_invoice_items` | Billing kasir |
| `trx_rme_payments` | Pembayaran; `payment_batch_uuid` untuk batch allocation |
| `trx_rme_receivable_follow_ups` | Follow-up piutang |

### Lab (`trx_lab_*`, `trx_invoices`)
| Tabel | Catatan |
|---|---|
| `trx_lab_orders`, `trx_lab_order_items`, `trx_lab_order_status_logs` | Order lab |
| `trx_lab_case_candidates` | Staging RME→Lab; UNIQUE `rme_invoice_item_id` |
| `trx_lab_order_assignments`, `trx_lab_work_logs`, `trx_lab_production_steps` | Produksi |
| `trx_lab_quality_controls`, `trx_lab_qc_checklists`, `trx_lab_remake_requests` | QC |
| `trx_lab_deliveries` | Delivery & POD |
| `trx_invoices`, `trx_invoice_items`, `trx_payments` | Billing **lab** (bukan RME) |

### Inventory (`inv_*`, `trx_inventory_*`, procurement, transfer, opname)
| Tabel | Catatan |
|---|---|
| `inv_product_categories`, `inv_product_units`, `inv_products` | Master produk; `reorder_point`, `requires_batch_tracking` |
| `inv_suppliers`, `inv_inventory_locations` | Supplier & lokasi per cabang |
| `inv_inventory_batches` | Batch/lot |
| `inv_location_product_minimums` | Minimum stok per lokasi |
| `trx_inventory_movements` | **Ledger stok** — sumber kebenaran |
| `trx_stock_transfers`, `trx_stock_transfer_items` | Transfer antar lokasi |
| `trx_stock_opnames`, `trx_stock_opname_items` | Stock opname |
| `trx_purchase_requests`, `trx_purchase_request_items` | PR |
| `trx_purchase_orders`, `trx_purchase_order_items` | PO; `quantity_received` |
| `trx_goods_receipts`, `trx_goods_receipt_items` | GR → movement PURCHASE |
| `inv_inventory_activity_logs` | Audit trail inventory |

### Reporting summary (`rpt_*`)
`rpt_inventory_daily_summaries`, `rpt_inventory_branch_summaries`, `rpt_inventory_product_summaries`, `rpt_procurement_daily_summaries`

### Staging import (`stg_*`)
`stg_legacy_patient_import_batches`, `stg_legacy_patient_imports` — import pasien legacy (Sprint 62.3)

### Relasi utama (konseptual)
```text
mst_patients 1—* trx_clinic_visits 1—1 trx_medical_records
trx_clinic_visits 1—1 trx_odontograms
trx_clinic_visits 1—* trx_rme_invoices 1—* trx_rme_invoice_items
trx_rme_invoices 1—* trx_rme_payments
trx_rme_invoice_items 1—0..1 trx_lab_case_candidates

mst_branches 1—* (semua trx branch-owned)
inv_products 1—* trx_inventory_movements *—1 inv_inventory_locations
```

### Field & constraint penting
- `trx_clinic_visits.status`: `registered|waiting|in_progress|cashier_pending|completed|cancelled`
- `trx_rme_invoices.status`: `DRAFT|UNPAID|PARTIAL|PAID|VOID`
- `trx_inventory_movements.movement_type`: `OPENING|PURCHASE|ADJUSTMENT_IN|ADJUSTMENT_OUT|TRANSFER_IN|TRANSFER_OUT`
- `mst_patients.medical_record_number`: unique (termasuk soft-deleted saat validasi import)
- `mst_patients.ktp_number`: unique constraint (validasi di request/service)

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan drop kolom/tabel production tanpa migrasi rollback terencana
- Jangan NOT NULL/backfill paksa pada kolom workspace RM tanpa spec
- Jangan tambah kolom `current_stock` / `qty_on_hand` di produk
- Jangan gabungkan `trx_invoices` (lab) dengan `trx_rme_invoices`

## Checklist Validasi
- [ ] Migration additive & reversible jika memungkinkan
- [ ] Index pada FK dan filter frequent (`branch_id`, `status`, dates)
- [ ] Factory/test diperbarui
- [ ] `docs/database_schema.md` mungkin outdated — verifikasi ke migration

## Catatan untuk AI
**Catatan Konflik / Perlu Verifikasi:** `docs/database_schema.md` hanya mencakup schema V1 lab dan **tidak** memuat tabel RME, inventory modern, procurement. Gunakan migration sebagai sumber kebenaran.

**TODO:** Daftar index lengkap per tabel — ekstrak manual dari file migration jika diperlukan audit mendalam.
