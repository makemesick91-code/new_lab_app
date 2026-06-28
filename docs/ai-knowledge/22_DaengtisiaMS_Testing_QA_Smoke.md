# DaengtisiaMS — Testing & QA Smoke

## Tujuan
Framework test, suite penting, smoke checklist, regression, dan pre-merge gates.

## Ringkasan
Pest untuk feature tests (utama). Laravel Dusk untuk browser smoke RME. Playwright: **TODO: belum ditemukan di repo** sebagai runner resmi.

## Konteks DaengtisiaMS
Setiap perubahan workflow wajib punya test: happy path, validation, authorization, branch isolation (+ ledger untuk inventory).

## File / Area Repo Terkait
- `tests/Pest.php`, `tests/TestCase.php`
- `tests/Feature/` — 250+ feature tests
- `tests/Browser/` — Dusk smoke
- `phpunit.xml` / `pest.php`
- `docs/pilot/rme_smoke_test_developer_notes.md`
- `docs/pilot/vps_pilot_go_no_go_checklist.md`

## Aturan Utama

### Framework
| Tool | Penggunaan |
|---|---|
| **Pest 3** | Feature tests utama — `php artisan test` |
| **Laravel Dusk 8** | Browser smoke — `tests/Browser/` |
| **Pint** | Code style — `./vendor/bin/pint` |
| Playwright | UNKNOWN — tidak ada di `package.json` |

### Test environment
- Feature suite: `RefreshDatabase` (SQLite in-memory per `sprint_history`)
- PostgreSQL untuk migrate manual / VPS

### Suite penting per domain
| Domain | Path test | Filter contoh |
|---|---|---|
| RME | `tests/Feature/RME/` | `php artisan test tests/Feature/RME` |
| Inventory | `tests/Feature/Inventory/` | `--filter=Inventory` |
| Lab | `tests/Feature/LabOrder/` | |
| Branch | `tests/Feature/BranchScope/` | |
| Auth/Permission | `tests/Feature/Auth/`, `AccessControl/` | |
| Owner KPI | `tests/Feature/Owner/` | |
| Pilot | `tests/Feature/Pilot/` | |

### RME regression critical
- `RmeDoctorCashierCompletionGateTest`
- `RmeRoomAssignmentGateTest`
- `MedicalRecordFinalizationTest`, `MedicalRecordHandwritingTest`
- `CashierBillingTest`, `RmePaymentTest`
- `PatientOutstandingReceivableCarryOverTest`
- `PatientCentricRmWorkspaceTest`
- `OdontogramTest`, `StructuredOdontogramPrintTemplateTest`

### Inventory regression critical
- `GoodsReceiptLedgerTest`
- `StockTransferRequestTest`
- `StockOpnameModelTest`
- `InventoryPermissionHardeningTest`

### Dusk smoke
- `tests/Browser/RmeCreateSmokeTest.php`
- `tests/Browser/RmeDetailSmokeTest.php`
- `tests/Browser/RmeSmokeScreenshotsTest.php`

## Workflow / Alur
### Pre-merge checklist
```bash
php artisan test --filter=<Module>
./vendor/bin/pint --dirty
php artisan route:list | rg <route>
git diff --check
```
Frontend changes: `npm run build`

### Smoke manual (pilot)
- `docs/pilot/owner_dashboard_manual_smoke_test_checklist.md`
- `docs/pilot/vps_pilot_go_no_go_checklist.md`
- RME E2E notes: `docs/pilot/rme_lab_candidate_e2e_developer_notes.md`

### Regression checklist
- [ ] Branch A tidak akses data branch B
- [ ] Permission 403 untuk role salah
- [ ] Ledger balance setelah GR/transfer/opname
- [ ] RME: room gate → RM → finalize → kasir → completed
- [ ] Partial payment completes visit + piutang tersisa
- [ ] KTP tidak muncul di export

## Struktur Teknis
Factories: `database/factories/` — match module conventions

Seeders smoke: `RmeSmokeTestSeeder` — **opsional** di VPS, jangan wajibkan di production

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan weaken assertion untuk pass
- Jangan skip branch isolation tests
- Jangan run `migrate:fresh` di VPS untuk "fix" test

## Checklist Validasi
- [ ] Test baru untuk behavior baru
- [ ] Full module suite green sebelum claim done
- [ ] Pint clean
- [ ] Honest report jika test tidak dijalankan

## Catatan untuk AI
Tes berat full suite: jalankan di Ubuntu terminal biasa (bukan IDE terminal) per `docs/pilot/vps_pilot_deployment_checklist.md`.

Sprint closure pernah report: RME dir 800+ tests — angka berubah; jalankan test aktual.
