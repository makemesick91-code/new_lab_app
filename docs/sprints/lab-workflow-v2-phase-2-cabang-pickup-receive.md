# LAB-WORKFLOW-V2 — Phase 2: Cabang Request, Courier Pickup & Receive at Lab

Tanggal: 2026-07-10
Branch: `feature/lab-workflow-v2-phase-2-cabang-request-courier-pickup-receive`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Baseline: Phase 1 GO `lab-workflow-v2-phase-1-foundation-state-machine-legacy-gate-go` @ `9116274`

## Runtime yang dikirim

1. **Cabang (Perawat/Admin Klinik) — Permintaan Lab** (`/lab/workflow-requests`, permission
   baru `create_lab_branch_requests`): buat order V2 DRAFT (branch di-stamp server-side via
   `BranchContext`, tidak pernah dari request), **foto SPK + foto model WAJIB** saat create
   (FormRequest + guard service `getimagesizefromstring` anti MIME-spoof/polyglot), submit
   pickup → transisi `DRAFT → WAITING_PICKUP` via state machine + pickup task idempotent
   (`UNIQUE lab_order_id`). Workspace list/show branch-scoped (cross-branch = 404).
2. **Kurir — Pickup** (`/lab/pickup-tasks`, permission baru `manage_lab_pickups`, role
   Courier): antrian tugas (Aktif/Tugas Saya/Semua), claim first-committed-wins
   (`lockForUpdate`, kurir kedua ditolak, retry kurir sama idempotent), konfirmasi pickup
   **wajib foto** (`PICKUP_PHOTO`), mulai transit (`IN_TRANSIT_TO_LAB`) hanya setelah bukti
   pickup ada. Kurir non-pemilik ditolak (policy `progress` + re-assert di service).
3. **Lab — Receive** : konfirmasi penerimaan hanya oleh `manage_lab_orders`
   (kurir TIDAK bisa mengkonfirmasi sendiri — aturan owner), catatan ketidaksesuaian
   opsional, idempotent, `RECEIVED_AT_LAB`.
4. **Bukti privat bertipe** — tabel baru `trx_lab_workflow_evidence` (tipe eksplisit
   SPK_PHOTO/MODEL_PHOTO_BRANCH/PICKUP_PHOTO + tipe delivery Phase 4; sha256 checksum;
   soft-delete only). Binary di **disk privat `local`** (BUKAN symlink publik — berbeda
   dari `sys_attachments` lama yang publik) dengan nama file tergenerasi; disajikan hanya
   via `GET /lab/workflow-evidence/{evidence}` + `LabWorkflowEvidencePolicy` (lab/kurir =
   semua, aktor cabang = cabangnya saja). Konsistensi disk↔DB: gagal DB → file dihapus.
5. **Konversi kandidat RME → V2**: saat flag `lab.workflow_v2` aktif,
   `LabCaseCandidateConversionService` membuat order **V2 DRAFT** (via
   `LabWorkflowRequestService::createV2Draft`) alih-alih jalur legacy yang kini di-guard;
   review manual kandidat tidak berubah. Saat flag off → jalur legacy verbatim.
6. **Guard cabang state machine dipertajam** (config `branch_scoped_transitions`):
   equality cabang hanya untuk transisi aktor-cabang (`WAITING_PICKUP`); transisi
   kurir/lab lintas-cabang by design dan dijaga lewat kepemilikan task.

## Permissions & roles (PermissionSeeder + RoleSeeder, idempotent reseed di VPS)

- `create_lab_branch_requests` → Perawat, Admin Klinik, Admin Lab.
- `manage_lab_pickups` → Courier, Admin Lab.

## Tidak berubah

- Flag `lab.workflow_v2` tetap **OFF** default — pipeline legacy berjalan persis sama.
- Tidak ada perubahan payment/RME/inventory; Production/QC/Delivery legacy tidak disentuh.
- Migration additive murni (2 tabel baru; tanpa drop/rewrite).

## File utama

Migrations `2026_07_10_130001` (trx_lab_pickup_tasks) + `2026_07_10_130002`
(trx_lab_workflow_evidence); models `LabPickupTask`, `LabWorkflowEvidence` (+ relasi di
`LabOrder`); services `LabWorkflowRequestService`, `LabPickupWorkflowService`,
`LabWorkflowEvidenceService`; repo `LabPickupTaskRepository` (+interface, binding);
policies `LabPickupTaskPolicy`, `LabWorkflowEvidencePolicy`; controllers
`LabWorkflowRequestController`, `LabPickupTaskController`, `LabWorkflowEvidenceController`;
4 FormRequests; routes grup `/lab`; views `lab-workflow/{requests,pickups}/*` +
`partials/v2-status-badge`; sidebar "Permintaan Lab Cabang" + "Pickup Lab".

## Validasi

- `tests/Feature/LabWorkflow` (foundation + branch request + pickup): **45 passed / 259 assertions**
- Lab regression (LabOrder/Production/QC/Delivery): **170 passed / 379 assertions**
- RME→Lab bridge: **55 passed / 149 assertions**
- Role/permission/sidebar: `RolePermissionHardening|SidebarPermissionVisibility|PilotRouteAuthorization` **22 passed / 124 assertions**
- `pint --dirty` + `git diff --check` bersih; `npm run build` pass; graphify updated.
- Catatan test-infra: helper `fakeEvidencePhoto()` (PNG bytes riil tanpa GD) di
  `tests/Pest.php` — CLI lokal tanpa ekstensi GD tetap bisa menjalankan test upload.

## Deploy note

`php artisan migrate --force` (2 tabel baru) + `php artisan db:seed --class=PermissionSeeder --force`
+ `php artisan db:seed --class=RoleSeeder --force` (idempotent) di VPS pasca-deploy.
