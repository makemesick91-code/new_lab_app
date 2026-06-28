# DaengtisiaMS — Peta Modul

## Tujuan
Memetakan semua modul domain, fungsinya, dan hubungan antar modul agar AI tahu di mana menempatkan perubahan.

## Ringkasan
DaengtisiaMS memiliki 26 folder modul di `app/Modules/`. Modul dikelompokkan menjadi akses kontrol, master data, RME/klinik, lab workflow, inventory/procurement, keuangan, dan pelaporan.

## Konteks DaengtisiaMS
Setiap modul mengikuti pola: Controllers, Requests, Services, Repositories/Interfaces, Models, Policies. Wiring di `RepositoryServiceProvider`.

## File / Area Repo Terkait
- `app/Modules/*`
- `app/Providers/RepositoryServiceProvider.php`
- `routes/web.php`

## Aturan Utama
- Modul hanya berkoordinasi lintas domain via service/repository interface publik.
- Jangan buat service global acak untuk logic modul-spesifik.
- MAIN branch (`mst_branches`) adalah cabang pusat; modul RME/Inventory memakai flag `is_rme_enabled` / `is_inventory_enabled`.

## Workflow / Alur
### Hubungan modul utama (operasional)

```text
Patient → ClinicVisit → MedicalRecord + Odontogram
                     → RmeInvoice → RmePayment → (visit completed)
                     → LabCaseCandidate (post PAID) → LabOrder (konversi manual)

LabOrder → Production → QualityControl → Delivery → Invoice/Payment (lab billing)

Inventory: Product → Movement Ledger ← GoodsReceipt / Transfer / Opname / Adjustment
Procurement: PurchaseRequest → PurchaseOrder → GoodsReceipt → Ledger PURCHASE
```

## Struktur Teknis

| Modul | Fungsi | Hubungan |
|---|---|---|
| **AccessControl** | Role, permission UI | Spatie; `RoleSeeder`, `PermissionSeeder` |
| **User** | Manajemen user | Auth Breeze |
| **Branch** | Master cabang, `BranchContext` | Semua data branch-owned |
| **Clinic** | Master klinik (lab legacy) | Lab orders |
| **ClinicRoom** | Ruangan perawatan RME | `ClinicVisit.clinic_room_id` |
| **ClinicVisit** | Kunjungan pasien, antrian, status | RME pipeline |
| **Patient** | Master pasien, RM, KTP, import legacy | ClinicVisit |
| **Doctor** | Master dokter | Visit, RM |
| **Treatment / TreatmentCategory / Tariff** | Master tindakan & tarif klinik | Visit initial treatment, billing |
| **PaymentMethod** | Metode bayar kasir | RmePayment |
| **MedicalRecord** | RM elektronik, handwriting canvas | ClinicVisit (1 sheet per visit) |
| **Odontogram** | Peta gigi terstruktur | ClinicVisit |
| **RmeDashboard** | Dashboard RME ringkas | ClinicVisit metrics |
| **RmeInvoice** | Billing kasir, payment, piutang, follow-up | ClinicVisit, Patient |
| **LabOrder** | Order lab, kandidat RME, status workflow | Patient, Production |
| **LabService** | Master layanan lab | LabOrder items |
| **Production** | Assignment teknisi, langkah produksi | LabOrder |
| **QualityControl** | QC, checklist, remake | LabOrder |
| **Delivery** | Pengiriman & POD | LabOrder |
| **Invoice / Payment** | Invoice & payment **lab** (bukan RME) | Lab workflow |
| **Inventory** | Produk, lokasi, ledger, transfer, opname, procurement | Branch-scoped |
| **Reporting** | Laporan agregat, export, Owner KPI | Read-only |
| **Technician** | Master teknisi | Production |
| **WaReminderTemplate** | Template teks WA (master data) | Manual SOP — bukan auto-send |

### Klaster fungsional

| Klaster | Modul |
|---|---|
| Klinik / RME | Patient, ClinicVisit, ClinicRoom, MedicalRecord, Odontogram, RmeInvoice, RmeDashboard, Treatment*, Tariff, PaymentMethod |
| Lab | LabOrder, LabService, Production, QualityControl, Delivery, Invoice, Payment |
| Inventory | Inventory (semua sub-workflow di dalamnya) |
| Cashier / Piutang | RmeInvoice (controllers: RmePayment, RmeInvoice, receivables) |
| Report / Owner | Reporting, RmeInvoice (RmeReportController) |
| Access | AccessControl, User, Branch |

## Hal yang Tidak Boleh Diubah Sembarangan
- Batas modul: jangan query tabel modul lain langsung dari controller
- `trx_rme_*` vs `trx_invoices` — billing RME dan billing lab terpisah
- Konversi `LabCaseCandidate` tidak otomatis saat payment — butuh review admin lab

## Checklist Validasi
- [ ] Perubahan ditempatkan di modul pemilik domain
- [ ] Binding repository/policy terdaftar di `RepositoryServiceProvider`
- [ ] Route baru mengikuti konvensi prefix modul (`rme.*`, `inventory.*`, `settings.*`)
- [ ] Test modul ada di `tests/Feature/<Domain>/`

## Catatan untuk AI
- Modul **RmeInvoice** menangani kasir RME; modul **Invoice** menangani invoice lab — jangan dicampur.
- `WaReminderTemplate` hanya master template; pengiriman WA masih manual (lihat dokumen 20).
- Untuk daftar tabel per modul, lihat dokumen 04.
