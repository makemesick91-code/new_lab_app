# DaengtisiaMS — Reports & Exports

## Tujuan
Laporan RME, pembayaran, inventory, CSV/PDF/print, filter, dan aturan export.

## Ringkasan
Reporting read-only via modul Reporting, RmeInvoice reports, dan Inventory reports. Export gated permission `export_report` / report-specific.

## Konteks DaengtisiaMS
Laporan tidak boleh expose KTP penuh, catatan medis raw, atau data lintas cabang tanpa permission.

## File / Area Repo Terkait
- `app/Modules/Reporting/` — `ExportReportController`, dashboard reports
- `app/Modules/RmeInvoice/Controllers/RmeReportController.php`
- `app/Modules/Inventory/Controllers/InventoryReportController.php`
- `app/Modules/Inventory/Services/InventoryReportService.php`
- `routes/web.php` — `reports.*`, `rme.reports.*`, `inventory.reports.*`
- `tests/Feature/Reporting/`

## Aturan Utama

### RME reports
| Route | Output | Permission |
|---|---|---|
| `rme.reports.patients` | Daftar pasien kunjungan | `view_rme_patient_reports` |
| `rme.reports.patients.export` | CSV | idem |
| `rme.reports.patients.print` | Print | idem |
| `rme.reports.payments` | Pembayaran RME | `view_rme_payment_reports` |
| `rme.reports.payments.export` | CSV | idem |
| `rme.reports.payments.print` | Print | idem |

### Receivable export
- `rme.cashier.receivables.export` — piutang aging/list
- Branch-scoped; tanpa KTP

### Patient audit export
- `rme.patients.audit.export` — completeness CSV, KTP masked

### Inventory reports
- `inventory.reports.index` — hub laporan inventory
- `inventory.reports.export` — export data
- `inventory.reports.room-stock.refill-checklist` — checklist refill ruang

### Lab / legacy reports
- `reports.dashboard` — `Reporting` module
- Order, production, QC, delivery, invoice, payment reports — permissions `view_*_report`
- `export_report` — export umum

### PDF
- RME visit bundle: dompdf via `rme.visits.pdf`
- Odontogram print: HTML table template
- Inventory transfer checklist: PDF download

### Filter umum
- Date range, branch (where applicable), status
- Owner KPI: period `today|7d|month|30d|custom`

### Export rules
- Read-only queries — tidak mutate data
- Mask PII (KTP)
- Respect branch context kecuali executive permission
- CSV encoding: ikuti implementasi controller existing

## Workflow / Alur
1. User buka halaman report dengan filter
2. Authorize permission + policy
3. Service aggregate branch-scoped
4. Export/print generate response download atau view print-friendly

## Struktur Teknis
Summary tables (inventory analytics): `rpt_inventory_daily_summaries`, dll. — refreshed via `InventoryAnalyticsSummaryRefreshService` / artisan command

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan export KTP/NIK plain
- Jangan export raw medical notes di report operasional
- Jangan bypass permission dengan direct URL tanpa middleware

## Checklist Validasi
- [ ] Export denied untuk role tanpa permission
- [ ] Branch filter applied
- [ ] CSV tidak contain PII terlarang
- [ ] Print view dompdf-safe (no flexbox where documented)

## Catatan untuk AI
`docs/sprint_40_reporting_export_owner_dashboard_improvement.md` — konteks peningkatan reporting.

**TODO:** Daftar lengkap export columns per report — baca controller masing-masing saat audit.
