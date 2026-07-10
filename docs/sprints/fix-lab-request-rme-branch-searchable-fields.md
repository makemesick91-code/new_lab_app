# FIX-LAB-REQUEST-RME-BRANCH-SEARCHABLE-FIELDS — Lab Request Clinic Context ke Cabang RME & Searchable Pasien/Dokter/Layanan (2026-07-10)

Branch `feature/fix-lab-request-rme-branch-searchable-fields` (base `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`; JANGAN target main). Baseline: `lab-workflow-v2-courier-production-qc-delivery-proof-go` @ `1664f14`. GO tag: `fix-lab-request-rme-branch-searchable-fields-go`.

## Root cause

Halaman **Buat Permintaan Lab** (`lab-workflow-requests.create`, LAB-WORKFLOW-V2 Phase 2) masih:

1. Menampilkan **seluruh `mst_clinics`** (legacy dental-lab master) sebagai "Klinik" — bukan Cabang RME canonical (semantik Sprint 23.9.1: "Klinik" untuk RME = branch dengan `is_rme_enabled`).
2. Memuat **semua dokter** dan **500 pasien lintas cabang** sebagai `<select>` biasa (tanpa scoping cabang, tanpa search).
3. `StoreLabWorkflowRequestRequest` hanya `exists:...,id` — pasien/dokter cabang lain, dokter nonaktif, dan layanan nonaktif/terhapus dapat di-inject via crafted request.
4. Laten: `LabCaseCandidateConversionService` sudah mengirim `clinicVisit?->clinic_id` (nullable sejak 23.9.1) ke `createV2Draft()` padahal `trx_lab_orders.clinic_id` masih NOT NULL → potensi constraint violation.

## Perubahan

- **Migration (additive, `migrate` saja — JANGAN `migrate:fresh`/`db:wipe`)**: `2026_07_10_220001_make_trx_lab_orders_clinic_id_nullable_for_rme_branch_source` — relaksasi NOT NULL `trx_lab_orders.clinic_id` (mirror persis pola 23.9.1; kolom/index/FK dipertahankan; data existing tidak disentuh).
- **`StoreLabWorkflowRequestRequest`**: `prepareForValidation()` **menimpa `branch_id` dengan `BranchContext`** (hidden input/query string/crafted request tidak pernah dipercaya); rule `branch_id` = exists `mst_branches` aktif + `is_rme_enabled` (pesan: "Permintaan lab hanya dapat dibuat dari Cabang RME aktif."); `patient_id` = aktif + non-deleted + (branch aktif ATAU legacy `branch_id NULL`); `doctor_id` = aktif + branch-compatible (kolom `branch_id`/pivot `mst_doctor_branches`, atau dokter legacy unbound); `items.*.lab_service_id` = aktif + non-soft-deleted; `clinic_id` kini nullable-compat (bukan input form lagi).
- **`LabWorkflowRequestService`**: `createDraft()` + `assertRmeBranch()` (re-assertion service-level, defense-in-depth ENT-1); `createV2Draft()` `clinic_id ?? null`; baru `formOptionsForActiveBranch()` — katalog opsi (branch terkunci, pasien cap `PATIENT_OPTION_LIMIT=500` tanpa KTP/NIK, dokter branch-compatible, layanan aktif) untuk dropdown.
- **Controller `create()`**: thin — hanya `formOptionsForActiveBranch()`.
- **View `lab-workflow/requests/create.blade.php`**: Klinik = field read-only "Klinik (Cabang RME)" (nama + kode cabang aktif); tanpa Cabang RME aktif → `x-ui.alert` warning, form disembunyikan (UX; enforcement tetap server-side). Pasien/Dokter/Layanan Lab = `x-inventory.searchable-product-select` (pola persis dropdown Produk Inventory: ketik-untuk-cari kode/nama/label, arrow/Enter/Escape, clear, old-input); layanan per item row via pola PO `alpine-name` + `model`; old input items direstore via `Js::from(old('items', ...))` + `x-model`.
- **Komponen `x-inventory.searchable-product-select`**: prop baru `notFoundLabel` + `clearLabel` (default lama "Produk tidak ditemukan."/"Hapus pilihan produk" — semua pemakai Inventory tidak berubah).
- **Fixture test**: `branchRequestPayload()` menyelaraskan dokter fixture ke MAIN branch (rule dokter-branch baru).

## Aturan permanen

1. Buat Permintaan Lab hanya memakai **Cabang RME aktif**; `branch_id` request TIDAK PERNAH dipercaya (server-side override + validasi + service assert).
2. MAIN/non-RME/inactive branch tidak selectable dan ditolak server-side.
3. Pasien Lab Request branch-scoped (branch aktif atau legacy unassigned); **KTP/NIK tidak pernah masuk katalog dropdown**.
4. Dokter harus aktif + valid untuk Cabang RME (kolom/pivot) atau dokter legacy unbound.
5. Layanan Lab harus aktif + tidak soft-deleted; ID inactive crafted → 422.
6. Pasien/Dokter/Layanan memakai searchable dropdown canonical yang sama dengan Produk Inventory; dropdown search BUKAN pengganti authorization — semua hidden ID divalidasi ulang server-side.
7. Order baru tetap `workflow_version=2`; legacy create tetap blocked; redirect sukses tetap ke `lab-workflow-requests.show` (V2, branch-scoped).
8. Katalog dropdown dibatasi (cap) — jangan pernah memuat seluruh tabel ke HTML.

## Validasi

- Baru: `tests/Feature/LabWorkflow/LabWorkflowRequestRmeBranchSearchableFieldsTest.php` — 21 passed / 89 assertions (Klinik lock + non-RME blocked + guest, katalog pasien/dokter/layanan scoped + KTP tidak bocor + cap, injeksi branch/pasien/dokter/layanan ditolak, old input, pickup idempotent, conversion-path nullable clinic).
- Regression: `tests/Feature/LabWorkflow` 111 passed / 520; `--filter='LabOrder|LabIntegration|LabCaseCandidate|LabService'` 89 passed / 211; Inventory pemakai komponen (`StockOpname|PurchaseOrder|StockTransfer|LocationMinimum|BatchDirectory`) 327 passed / 1255; Ui Inventory 19 passed / 122.
- `pint --dirty` clean; `git diff --check` clean; `view:cache` compile OK.

## Operational follow-up (2026-07-10) — Operator → Cabang RME mapping

Pasca-deploy ditemukan seluruh user VPS pilot ber-`branch_id NULL` → `BranchContext` jatuh ke MAIN
(non-RME) dan operator sah terblokir dari halaman Buat Permintaan Lab. **Murni data repair, tanpa
code change, tanpa GO tag baru.** Yuni FO (user 7, Admin Klinik) dipetakan ke LDK2 (branch 2)
berbasis evidence (online context miliknya 2026-07-09 + 2 kunjungan terakhir di LDK2), transaksional
+ lockForUpdate, setelah backup `auto_backup_20260710-150150.sql`. Rahmi (user 10, Perawat) belum
dipetakan — nihil jejak aktivitas, menunggu konfirmasi cabang dari owner. Super Admin/Lab Admin
sengaja tidak di-pin (aktor lintas-cabang; create adalah fungsi operator cabang). Verifikasi runtime:
BranchContext → LDK2, form options 8 pasien/4 dokter/10 layanan, isolasi cabang benar, smoke +
permission cache reset + services green. Evidence lengkap:
`docs/operations/lab-request-operator-rme-branch-mapping-2026-07-10.md`.
