# DaengtisiaMS — Owner Dashboard KPI

## Tujuan
Dasbor owner, KPI klinik/RME/Lab/payment/receivable, filter periode & cabang, drilldown.

## Ringkasan
Route `dashboard` → `HomeDashboardController` dengan blok KPI executive Sprint 62 (`OwnerDashboardKpiService`) + snapshot RME/Lab pilot existing.

## Konteks DaengtisiaMS
Read-only executive view. Gated `view dashboard|view_owner_dashboard`. Tidak expose KTP, scan, raw notes.

## File / Area Repo Terkait
- `app/Http/Controllers/HomeDashboardController.php`
- `app/Modules/Reporting/Services/OwnerDashboardKpiService.php`
- `app/Modules/Reporting/Services/OwnerDashboardRmeLabKpiService.php` (snapshot)
- `resources/views/dashboard.blade.php`
- `resources/views/dashboards/owner-kpi.blade.php`
- `tests/Feature/Owner/OwnerKpiDashboardTest.php`
- `tests/Feature/Dashboard/OwnerDashboardRmeLabKpiTest.php`
- `docs/pilot/owner_dashboard_manual_smoke_test_checklist.md`

## Aturan Utama

### Akses
- Permission: `view_owner_dashboard` (role Owner)
- Juga: `view dashboard` untuk akses dasar
- **Supervisor RME excluded** dari owner dashboard by design (tidak punya `view_owner_dashboard`)

### KPI cards (Sprint 62 — period-based)
Service: `OwnerDashboardKpiService`
- Periode: `today`, `7d`, `month` (default), `30d`, `custom`
- Filter `branch_id` opsional
- 10 KPI cards + per-branch table + daily trends
- KPI mencakup (dari service): visits, patients, payments, receivables, lab orders aktif, inventory low-stock/value (try/catch fallback "Belum tersedia")
- Drilldown links guarded `Route::has()` + permission

### Privacy
- Tidak ada KTP/NIK
- Tidak ada scanned documents
- Tidak ada raw medical notes
- Latest receivables: privacy-safe summary

### RME/Lab snapshot block
- Existing pilot section "today snapshot"
- `OwnerDashboardRmeLabKpiService`

### Receivable KPI
- Berdasarkan **invoice** status/remaining — bukan visit status
- Completed visit dengan PARTIAL invoice tetap counted as piutang

### Inventory executive
- Low stock / value via `InventoryAnalyticsRepositoryInterface`
- Cross-branch analytics hanya dengan `view_inventory_cross_branch_analytics`

## Workflow / Alur
1. Owner login → redirect `/dashboard`
2. Pilih periode & cabang (optional)
3. Review KPI cards & trends
4. Drilldown ke modul jika link tersedia & authorized

## Struktur Teknis
| Komponen | Path |
|---|---|
| Controller | `HomeDashboardController@index` |
| KPI service | `OwnerDashboardKpiService` |
| View partial | `dashboards/owner-kpi.blade.php` |
| Route name | `dashboard` |

Branch filter: `resolveSelectedBranchId()` — validasi branch exists/active

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan tambah PII ke KPI cards
- Jangan buat route `/owner/dashboard` duplikat (Sprint 62 enhance existing)
- Jangan weaken branch filter untuk operator roles via owner dashboard code reuse

## Checklist Validasi
- [ ] `OwnerKpiDashboardTest` (12 tests)
- [ ] Owner sees KPI; Doctor does not
- [ ] Period custom date parsing
- [ ] Inventory fallback graceful

## Catatan untuk AI
Sprint 62.0 branch: `feature/sprint-62-owner-kpi-dashboard-implementation`.

Manual smoke: `docs/pilot/owner_dashboard_manual_smoke_test_checklist.md`

**TODO:** Daftar exact 10 KPI card labels — baca `owner-kpi.blade.php` untuk UI copy.
