# LAB-WORKFLOW-V2 — Phase 1: Foundation, State Machine & Legacy Gate

Tanggal: 2026-07-10
Branch: `feature/lab-workflow-v2-phase-1-foundation-state-machine-legacy-gate`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Baseline sebelum sprint: `fix-po-multi-vendor-item-estimated-arrival-supplier-pdf-go` @ `0ca484e`

## Tujuan

Fase pertama dari LAB-WORKFLOW-V2 (Cabang Pickup, Courier Transport, Internal/External
Lab Production, QC, Delivery Proof & Legacy Workflow Inactivation). Fase ini meletakkan
fondasi runtime yang membuat fase-fase berikutnya (pickup kurir, produksi, QC, delivery
proof) bisa dibangun dengan aman di atas pilot yang sedang live:

1. **Workflow version discriminator** — kolom additive `trx_lab_orders.workflow_version`
   (nullable, indexed `workflow_version,status`), backfill deterministik semua baris lama
   → `LEGACY (1)`. `NULL` diperlakukan sebagai LEGACY di model layer.
2. **Parallel engine strategy (keputusan owner)** — order legacy tetap memakai pipeline
   Sprint 3–7 (readable + legacy-mutable), order baru memakai V2 hanya setelah feature
   flag aktif. Tidak ada retrofit terhadap service/test legacy.
3. **Canonical V2 state machine** — `App\Modules\LabOrder\Workflow\LabWorkflowState`
   (42 status V2 + transition matrix ketat) dan
   `App\Modules\LabOrder\Services\LabWorkflowStateMachine` (otoritas transisi tunggal:
   guard engine/status/edge/terminal/permission/branch, `DB::transaction` +
   `lockForUpdate`, idempotent no-op, append-only `LabOrderStatusLog` + `AuditLog`,
   metadata audit disanitasi — tanpa binary/base64/PII).
4. **Legacy read-only gate** — `LabWorkflowResolver`:
   `assertLegacyCreationAllowed()` (create legacy ditolak server-side saat V2 aktif) dan
   `assertLegacyMutable()` (update/cancel legacy menolak order V2). Enforcement di
   service layer, bukan sekadar menyembunyikan tombol.
5. **Feature-flag governance** — flag `lab.workflow_v2` di `config/feature_flags.php`
   (default **false**, `risk_level: high`, env `FEATURE_LAB_WORKFLOW_V2`, rollback =
   set env false; data V2 tetap readable). Keputusan engine hanya lewat
   `FeatureFlagService::enabled()` (aturan NSF-9), bukan config mentah.
6. **Transition→permission map** — `config/lab_workflow.php` memetakan setiap target
   state V2 ke permission Lab existing (tidak ada permission baru di fase ini).

## Yang TIDAK berubah

- Tidak ada perubahan perilaku produksi selama flag OFF (default): create/update/cancel
  legacy bekerja persis seperti sebelumnya dan tetap men-stamp `LEGACY`.
- Tidak ada route, permission, role, atau UI baru.
- Pipeline Production/QC/Delivery Sprint 3–7 tidak disentuh.
- Integrasi RME → LabCaseCandidate → convert tetap manual dan utuh.
- Tidak ada drop kolom/tabel/status; migration additive murni (PG + SQLite safe).

## File

- `database/migrations/2026_07_10_120001_add_workflow_version_to_trx_lab_orders_table.php`
- `app/Modules/LabOrder/Workflow/LabWorkflowState.php` (baru)
- `app/Modules/LabOrder/Services/LabWorkflowStateMachine.php` (baru)
- `app/Modules/LabOrder/Services/LabWorkflowResolver.php` (baru)
- `app/Modules/LabOrder/Models/LabOrder.php` (+`WORKFLOW_LEGACY/V2`, cast, helpers)
- `app/Modules/LabOrder/Services/LabOrderService.php` (stamp LEGACY + kedua guard)
- `config/lab_workflow.php` (baru), `config/feature_flags.php` (+1 flag)
- `tests/Feature/LabWorkflow/LabWorkflowV2FoundationTest.php` (baru, 16 test)

## Validasi

- `tests/Feature/LabWorkflow/LabWorkflowV2FoundationTest.php`: **16 passed / 166 assertions**
- Regression Lab (`LabOrder|Production|QualityControl|Delivery`): **170 passed / 379 assertions**
- Regression RME→Lab bridge (`LabIntegration|LabCaseCandidateQueue|LabCaseCandidateConversion|RmeLabWorkflowPolish`): **55 passed / 149 assertions**
- FeatureFlag governance: **9 passed / 293 assertions**; NSF-9 release safety: **8 passed**
- `vendor/bin/pint --dirty` bersih; `git diff --check` bersih; graphify updated.

## Fase berikutnya

- Phase 2 — Cabang request (SPK + foto model) + pickup kurir + receive at Lab.
- Phase 3 — Analisa model + produksi internal step 1–4 + QC/rework + external lab.
- Phase 4 — Delivery dengan gate wajib foto pra-pengantaran + tanda tangan kurir,
  recipient signature + delivery proof.
- Phase 5 — Role workspaces UI + notifikasi in-app + reporting + aktivasi flag.
