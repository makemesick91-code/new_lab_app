# DaengtisiaMS — Master Knowledge Compact

> Gabungan ringkas dari 25 dokumen `docs/ai-knowledge/*.md` untuk ChatGPT/Claude/Cursor.  
> Sumber kebenaran: kode, migration, route, test — bukan dokumen lama yang konflik.

---

## 1. Konteks Aplikasi

| Aspek | Nilai |
|---|---|
| Nama | **Daengtisia Management System** (dulu ADLMS) |
| Domain | Klinik Gigi Daengtisia + lab gigi multi-cabang |
| Stack | Laravel 12, PHP 8.2+, PostgreSQL, Blade + Tailwind + Alpine, Pest, Spatie Permission |
| Arsitektur | Modular monolith di `app/Modules/` (~26 modul, ~359 route) |
| Branch dev aktif | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| VPS path (docs) | `/var/www/asia-dental-lab-v2` |
| **Jangan target** | `main` — baseline project bukan main |

**Prinsip:** perubahan kecil, ter-scope, ditest; migration additive di production; jangan `migrate:fresh`/`db:wipe` di VPS.

---

## 2. Arsitektur Wajib

```text
HTTP → Controller → FormRequest → Service → RepositoryInterface → Repository → Model
         ↓ authorize via Policy
```

- Business logic **hanya** di Service; multi-write pakai `DB::transaction`
- Controller tipis: authorize + delegate
- Repository branch-scoped: param pertama `int $branchId`
- Wiring: `RepositoryServiceProvider` (binding, policy, `Gate::before` Super Admin)
- Middleware kustom: `visit.room` → gate RM/Odontogram jika visit belum punya ruangan
- **Larangan:** React/Vue, logic di Blade, bypass repository interface, kolom stok mutable

---

## 3. Peta Modul (Ringkas)

| Klaster | Modul |
|---|---|
| Access | AccessControl, User, Branch |
| RME/Klinik | Patient, ClinicVisit, ClinicRoom, MedicalRecord, Odontogram, RmeInvoice, RmeDashboard, Treatment*, Tariff, PaymentMethod |
| Lab | LabOrder, LabService, Production, QualityControl, Delivery, Invoice, Payment |
| Inventory | Inventory (master, ledger, procurement, transfer, opname, analytics) |
| Report | Reporting |

**Pemisahan billing:** `trx_rme_invoices` (kasir RME) ≠ `trx_invoices` (lab).  
**WA:** `WaReminderTemplate` = master template saja — bukan auto-send.

---

## 4. Branch Isolation

- Resolver: `BranchContext::requireId()` untuk write branch-owned
- **Jangan percaya** `branch_id` dari request
- `users.branch_id` **tidak ada** di schema saat ini — resolver defensive
- RME: `BranchService::rmeEnabledIds()`; MAIN excluded dari beberapa audit
- Inventory: `inventoryBranchId()` prefer MAIN jika enabled
- Cross-branch terbatas: `CrossBranchPatientLookupService` (by RM saja), Owner/executive analytics (read-only + permission khusus)
- MAIN tidak selectable sebagai ruangan klinik

---

## 5. RBAC & Permission

- Spatie Permission; Super Admin bypass via `Gate::before`
- Proteksi 3 lapis: route middleware → policy → branch ownership
- Sidebar `@can` **bukan** satu-satunya security

**Role utama:** Super Admin, Admin Lab, Admin Klinik, Doctor, Kasir, Perawat, Owner, Supervisor RME, Admin Warehouse, Technician, QC, Delivery, Finance, dll.

**Permission RME:** `view_clinic_visits`, `manage_clinic_visits`, `manage_rme_billing`, `view_treatment_worklist`, `view_rme_patient_reports`, `view_rme_payment_reports`

**Permission dashboard:** `view dashboard`, `view_owner_dashboard`, `view_branch_dashboard`

**Supervisor RME:** akses RME luas tapi **tidak** bypass gate server-side (Sprint 62.1)

---

## 6. Database Penting

**Prefix:** `mst_` master, `trx_` transaksi, `inv_` inventory master, `sys_` sistem, `rpt_` summary, `stg_` staging import

| Domain | Tabel kunci |
|---|---|
| RME | `trx_clinic_visits`, `trx_medical_records`, `trx_medical_record_handwritings`, `trx_medical_record_handwriting_pages`, `trx_odontograms`, `trx_rme_invoices`, `trx_rme_invoice_items`, `trx_rme_payments`, `trx_rme_receivable_follow_ups` |
| Lab | `trx_lab_orders`, `trx_lab_case_candidates`, `trx_invoices`, `trx_payments` |
| Inventory | `inv_products`, `inv_inventory_locations`, `trx_inventory_movements` (ledger), `trx_purchase_*`, `trx_goods_receipts`, `trx_stock_transfers`, `trx_stock_opnames` |
| Pasien | `mst_patients`, `mst_patient_documents`, `stg_legacy_patient_import_*` |

**Status visit:** `registered|waiting|in_progress|cashier_pending|completed|cancelled`  
**Status invoice RME:** `DRAFT|UNPAID|PARTIAL|PAID|VOID`  
**Movement types:** `OPENING|PURCHASE|ADJUSTMENT_IN|ADJUSTMENT_OUT|TRANSFER_IN|TRANSFER_OUT`

**RM workspace (Sprint 64):** kolom `canonical_visit_id`, `source_visit_id`, `sheet_number` di `trx_medical_records`

---

## 7. Route Penting

| Prefix | Fungsi | Permission contoh |
|---|---|---|
| `/dashboard` | Owner KPI + snapshot | `view dashboard\|view_owner_dashboard` |
| `/settings/*` | Master data, import pasien legacy | `manage patients`, `manage_clinic_master_data`, dll. |
| `/rme/*` | Visit, antrian, RM, odontogram, kasir, audit | `view_clinic_visits\|manage_clinic_visits`, `manage_rme_billing` |
| `/inventory/*` | Master, stok, procurement, transfer, opname | `view_inventory`, `manage_inventory`, approve permissions |
| `lab-orders`, `lab-case-candidates` | Lab workflow + kandidat RME | `view_lab_orders`, `manage_lab_orders` |
| `/reports/*` | Laporan agregat | `export_report`, `view_*_report` |

**RM/Odontogram:** middleware `visit.room` wajib (kecuali visit terminal/post-exam)  
Verifikasi: `php artisan route:list | rg "keyword"`

---

## 8. RME Workflow (End-to-End)

```text
Pendaftaran → Antrian → Input Ruangan → Mulai Pemeriksaan (in_progress)
→ Consent → RM + Odontogram editable → (tetap in_progress)
→ Selesai Pemeriksaan (cashier_pending) → Kasir/Payment → Visit completed
```

**Aturan kunci:**
- Handwriting PNG **wajib** sebelum finalize RM
- SOAP field ada di schema tapi **hidden** di UI dokter — jangan buka tanpa spec
- RM & odontogram **editable** setelah finalize (Sprint 59+)
- Doctor hanya boleh `in_progress` → `cashier_pending`; **tidak** boleh `completed` langsung
- Visit `completed` hanya via kasir (`RmePaymentService`)
- Gate ruangan: visit pre-exam tanpa `clinic_room_id` → blok RM/Odontogram
- Triage/initial treatment **tidak** auto-billing
- Sprint 64: patient-centric workspace — 1 pasien = buku RM, 1 sheet = 1 visit (UNIQUE `clinic_visit_id`)

**Print:** `rme.visits.print`, `rme.visits.pdf` (dompdf); odontogram standalone `rme.odontograms.print`

---

## 9. Pasien, RM & KTP

**Format RM (locked):** `DG-{KODE_CABANG}-{TAHUN}-{NOMOR_MANUAL}`  
Contoh: `DG-TKM1-2026-0001` — unik global (termasuk soft-deleted)

- KTP unique; **wajib di-mask** di audit/export (`maskKtp()`)
- Full KTP **tidak** dirender di UI/report/export
- Cross-branch lookup: by `medical_record_number` saja (bukan nama/KTP)
- Import legacy: staging → preview → commit; KTP masked; rollback blocked jika pasien punya visit downstream
- Audit: `rme.patients.audit` — MAIN excluded dari filter

---

## 10. Kasir & Piutang

- Billing gate: visit `cashier_pending` + RM `final`
- Pembayaran sebagian (**PARTIAL**) **menyelesaikan visit** — sisa = piutang aktif (hotfix 2026-06-27)
- Carry-over piutang (Sprint 62.2): opt-in di kunjungan baru; server-side intersection IDs; tidak merge invoice
- Consent wajib sebelum payment (`CreateRmePaymentRequest`)
- Piutang keyed by **invoice** status/remaining — bukan visit status
- Follow-up: `trx_rme_receivable_follow_ups` — catatan internal manual
- **Jangan** buat `trx_payments` (lab) dari payment RME

---

## 11. Lab Workflow

**RME → Lab:**
1. Trigger: invoice RME `PAID` (post-commit, idempotent)
2. `LabCaseCandidate` per item dengan `requires_lab = true`
3. Admin Lab review → konversi manual ke `LabOrder` (pilih `lab_service_id` eksplisit)
4. Failure generation **tidak** rollback payment

**Lab order status:** `DRAFT` → `RECEIVED` → `ASSIGNED` → `IN_PRODUCTION` → `QC_PENDING` → `QC_PASSED` → `READY_FOR_DELIVERY` → `DELIVERY` → `COMPLETED` (+ `REMAKE`, `CANCELLED`)

Billing lab terpisah via modul Invoice/Payment — tidak auto dari RME payment.

---

## 12. Inventory (Ledger-Only)

**Formula:** `stok = SUM(quantity_in) - SUM(quantity_out)` per branch + location + product (+ batch)

- Sumber kebenaran: `trx_inventory_movements`
- **Larangan keras:** kolom `current_stock`, `qty_on_hand`, update stok langsung di produk
- Procurement: PR → PO → GR → movement `PURCHASE`
- Transfer: submit → ship (`TRANSFER_OUT`) → receive (`TRANSFER_IN`) — jangan manual adjustment pair
- Opname: count → review → finalize → `ADJUSTMENT_IN/OUT`
- Outbound: reject jika stok tidak cukup
- Batch tracking wajib jika `requires_batch_tracking`

---

## 13. Laporan & Owner KPI

**RME reports:** `rme.reports.patients`, `rme.reports.payments` (+ export/print)  
**Receivable export:** `rme.cashier.receivables.export`  
**Inventory reports:** `inventory.reports.*`  
**Owner dashboard:** route `dashboard` — `OwnerDashboardKpiService` (periode today/7d/month/30d/custom)

- Read-only; mask PII; tidak expose KTP/raw notes/scans
- Piutang KPI dari invoice remaining
- Supervisor RME **excluded** dari owner dashboard
- Cross-branch analytics hanya dengan permission khusus

---

## 14. WhatsApp Reminder

- **Ada:** CRUD template `mst_wa_reminder_templates` + SOP manual
- **Tidak ada:** API WA, bot, scheduler auto-send, queue notification
- Operator kirim manual di luar app; catat follow-up di receivables jika perlu

---

## 15. UI/UX (Ringkas)

- Otoritas: `docs/ui_design_system.md`
- Primary teal-700; komponen `x-ui.*`, `x-settings-shell`
- Font Figtree; sidebar canonical: `layouts/sidebar.blade.php`
- Print dompdf: HTML `<table>` — **no flexbox** (odontogram)
- RME: swipeable sheets, handwriting overlay, room gate banner
- **Tidak** introduce React/Vue; `npm run build` jika ubah JS/Alpine

---

## 16. Testing & QA

| Tool | Penggunaan |
|---|---|
| Pest 3 | Feature tests — `php artisan test` |
| Pint | `./vendor/bin/pint` |
| Dusk 8 | Browser smoke — `tests/Browser/` |

**Wajib per perubahan:** happy path, validasi, authorization, branch isolation (+ ledger jika inventory)

**Regression RME critical:** `RmeDoctorCashierCompletionGateTest`, `RmeRoomAssignmentGateTest`, `MedicalRecordFinalizationTest`, `CashierBillingTest`, `RmePaymentTest`, `PatientOutstandingReceivableCarryOverTest`, `PatientCentricRmWorkspaceTest`

**Pre-merge:**
```bash
php artisan test --filter=<Module>
./vendor/bin/pint --dirty
php artisan route:list | rg <route>
git diff --check
```

---

## 17. Deploy VPS

1. Backup DB wajib (`pg_dump`) — stop jika gagal
2. Pull branch/tag approved
3. `composer install --no-dev`, `npm ci && npm run build`
4. `php artisan migrate --force` — **HANYA migrate**
5. Cache + permission storage/bootstrap/cache
6. Smoke RME + cek logs

**Terlarang di VPS:** `migrate:fresh`, `db:wipe`, restore SQL legacy mentah (pakai `rme:import-pilot-backup`)

---

## 18. Sprint & Kontrak Penting

| Sprint/Phase | Keputusan |
|---|---|
| 10–12 | BranchContext, branch enforcement, inventory ledger |
| 20 | RME pilot: handwriting primary, SOAP hidden, full-payment baseline |
| 21 | RME→Lab via LabCaseCandidate staging |
| 59 | RM/odontogram editable post-finalize |
| 60.8 | Room gate before exam |
| 62.1 | Doctor→cashier gate; visit completed hanya via kasir |
| hotfix | Partial payment completes visit |
| 62.2 | Receivable carry-over opt-in |
| 62.3 | Legacy patient batch import |
| 63.1 | Structured odontogram print |
| 64.0 | Patient-centric RM workspace |

**Konflik doc lama vs kode:** ikuti kode + test terbaru (contoh: RM immutable → editable Sprint 59; full-payment-only → partial completes visit hotfix).

---

## 19. Larangan AI (Non-Negotiable)

1. Jangan ubah logic di luar scope task
2. Jangan `migrate:fresh` / `db:wipe` di VPS
3. Jangan trust `branch_id` dari request
4. Jangan tambah kolom stok mutable
5. Jangan bypass policy/permission
6. Jangan buka SOAP di UI dokter tanpa spec
7. Jangan auto-send WhatsApp
8. Jangan commit/push/deploy tanpa permintaan eksplisit user
9. Jangan mengarang table/route/permission yang tidak ada di repo
10. Jangan target branch `main` jika baseline project bilang otherwise
11. Jangan auto-create LabOrder dari RME payment
12. Jangan expose KTP/NIK penuh di UI/export

---

## 20. Definition of Done

- [ ] Patch minimal, scoped
- [ ] Arsitektur Controller → Service → Repository dihormati
- [ ] Branch isolation tested (jika applicable)
- [ ] Ledger correctness tested (jika inventory)
- [ ] Pint + relevant `php artisan test` dijalankan & dilaporkan jujur
- [ ] `graphify update .` setelah ubah kode (jika graphify tersedia)
- [ ] Tidak ada file unrelated berubah
- [ ] Summary: files changed, tests, commands, assumptions, risks

---

## 21. SATUSEHAT Integration Readiness (SATUSEHAT-1)

Fondasi kesiapan integrasi SATUSEHAT — **integrasi eksternal NONAKTIF, tanpa
request ke API SATUSEHAT.** Modul `App\Modules\Satusehat`.

- **Aturan inti:** tidak ada auto-send; setiap kunjungan selesai + RM final hanya
  menjadi **kandidat** (idempotent, post-commit); wajib review eksplisit di halaman
  Filter; tidak ada "Send All"/"select all across pages"; approve/exclude
  server-side + branch-scoped (RME-enabled, IDOR-safe); exclude wajib alasan.
- **Gateway:** `SatusehatGatewayInterface` → default `DisabledSatusehatGateway`
  (tanpa koneksi jaringan), `FakeSatusehatGateway` (test), `HttpSatusehatGateway`
  (placeholder SATUSEHAT-2). Master switch `SATUSEHAT_ENABLED=false`; fail-closed
  bila konfigurasi tidak lengkap. Config `config/satusehat.php` +
  `config/feature_flags.php` (`satusehat.integration_readiness`/
  `satusehat.external_submission_enabled`, keduanya default OFF, risk critical).
- **Readiness engine:** status `ready|incomplete|blocked|source_changed`, 16 gate,
  reason kode PII-free; **source hash deterministik** → perubahan sumber klinis
  setelah approve → `source_changed` + approval dicabut (`approved_by` disimpan,
  `revoked_at`), review ulang. Preview FHIR lokal (Encounter/Condition/Procedure),
  UTC dari WITA, NIK di-mask, tanpa odontogram/scan/handwriting, label jujur
  "belum dikirim dan belum diverifikasi oleh API SATUSEHAT".
- **Schema additive:** `mst_satusehat_code_mappings`,
  `mst_satusehat_entity_identifiers`, `trx_satusehat_candidates`,
  `trx_satusehat_submission_batches`, `trx_satusehat_submission_items`,
  `trx_satusehat_audit_logs` (append-only, no updated_at). Mapping berversi (satu
  aktif per key); identifier single-active per environment; sandbox ≠ production.
- **Privasi:** NIK selalu masked (`maskKtp`); snapshot sensitif `encrypted:array`;
  audit tanpa NIK/token/payload mentah. Kegagalan SATUSEHAT tidak me-rollback
  transaksi klinis/billing.
- **RBAC:** `view/review/send_satusehat_submissions`, `manage_satusehat_mappings`,
  `manage_satusehat_settings`. Owner=view+review; Supervisor RME=full; Super Admin
  via `Gate::before`; Doctor/Kasir/Perawat none.
- **Route:** `/rme/satusehat/{submissions,mappings,identifiers}` (name
  `satusehat.*`). Command `satusehat:backfill-candidates` (dry-run, bounded, idempotent).
- **Docs:** `docs/architecture/satusehat-integration-readiness-governance.md`,
  runbook `docs/runbooks/satusehat-integration-readiness-runbook.md`,
  ADR `docs/adr/0001-satusehat-integration-readiness.md`, rule
  `.cursor/rules/83-satusehat-integration-readiness.mdc`.
- **Next:** SATUSEHAT-2 (Sandbox API Adapter & FHIR Submission) — hanya setelah
  kredensial sandbox + validasi profil resmi; aktifkan adapter HTTP di belakang
  `SATUSEHAT_ENABLED=true` + flag external submission.

---

## TODO / UNKNOWN

| Item | Status |
|---|---|
| Daftar index lengkap per tabel | TODO — ekstrak dari migration jika audit mendalam |
| Permission exact `rme.patients.audit` | TODO — verifikasi controller/policy |
| Daftar route lengkap production/QC/delivery | TODO — `php artisan route:list \| rg lab` |
| Daftar policy per model lengkap | TODO — ekstrak `RepositoryServiceProvider::$policies` |
| Status enum lengkap PR/PO/GR | TODO — baca model masing-masing |
| Field exact length/index KTP di migration | TODO — verifikasi `create_mst_patients` |
| Daftar export columns per report | TODO — baca controller masing-masing |
| Label exact 10 KPI cards owner dashboard | TODO — baca `owner-kpi.blade.php` |
| PHP-FPM version & nginx config path VPS | TODO — verifikasi manual di server |
| Baseline VPS production vs branch dev aktif | UNKNOWN — mungkin berbeda; ikuti branch user approve |
| Vendor WA API pilihan klinik | UNKNOWN — verifikasi manual dengan owner |
| Playwright sebagai runner resmi | UNKNOWN — tidak ada di `package.json` |
| Sprint 23–57 detail lengkap | TODO — lihat `docs/` bila perlu |
| `docs/database_schema.md` | Outdated — tidak lengkap untuk RME/inventory modern; gunakan migration |

---

*Dibuat dari gabungan 25 dokumen `docs/ai-knowledge/01–25`. Untuk detail modul, buka dokumen sumber spesifik.*
