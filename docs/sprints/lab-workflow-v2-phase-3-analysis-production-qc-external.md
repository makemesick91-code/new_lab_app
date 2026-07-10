# LAB-WORKFLOW-V2 — Phase 3: Analisa Model, Produksi Internal, QC/Rework & Lab Eksternal

Tanggal: 2026-07-10
Branch: `feature/lab-workflow-v2-phase-3-analysis-production-qc-external-lab`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Baseline: Phase 2 GO `lab-workflow-v2-phase-2-cabang-request-courier-pickup-receive-go` @ `21f86f3`

## Runtime yang dikirim

1. **Registrasi & Analisa Model** — `LabModelAnalysisService`: `RECEIVED_AT_LAB →
   MODEL_REGISTERED → MODEL_ANALYSIS_PENDING` (satu aksi operator, dua transisi teraudit),
   lalu keputusan analisa **wajib** (append-only `trx_lab_model_analyses`: decision
   INTERNAL/EXTERNAL, alasan wajib, actor, timestamp, lab eksternal tujuan saat EXTERNAL —
   lab wajib aktif). Assign teknisi sebelum analisa ditolak oleh matrix.
2. **Produksi Internal** — `LabV2ProductionService`: assign teknisi (chain
   `INTERNAL_APPROVED → TECHNICIAN_ASSIGNMENT_PENDING → TECHNICIAN_ASSIGNED`) memakai
   tabel legacy `trx_lab_order_assignments` (semantik status identik → report produksi
   tetap menghitung baris V2) + seed 4 step V2 di `trx_lab_production_steps` (step_name
   string bebas — tanpa migrasi tabel itu). Step 1–4 berurutan ketat (matrix menolak
   lompatan); start/complete meng-update baris step + `trx_lab_work_logs`.
   **Aturan aktor V2 diperketat**: hanya user teknisi yang ditugaskan (via
   `mst_technicians.user_id`) atau supervisor `manage_production` — loophole
   non-teknisi legacy DITUTUP di jalur V2. Kirim ke QC menutup assignment (`DONE`).
3. **QC + Rework** — `LabV2QualityControlService`: pass → `QC_PASSED → MODEL_DONE` +
   baris `trx_lab_quality_controls` (PASSED). Fail → **alasan wajib + target rework
   eksplisit wajib** (default diagram: STEP_2) → `QC_FAILED → REWORK_REQUIRED → target
   step`, baris QC REJECTED, step target..4 dibuka kembali (target IN_PROGRESS,
   sisanya PENDING), assignment diaktifkan lagi, nomor revisi = hitungan log QC_FAILED
   (append-only — tidak ada histori ditimpa). **SEGREGATION OF DUTY (baru, tidak ada
   di legacy)**: teknisi yang mengerjakan tidak pernah bisa pass/fail QC pekerjaannya
   sendiri, apa pun permission-nya.
4. **Lab Eksternal (greenfield)** — master `mst_external_labs` (BUKAN `inv_suppliers`)
   + `trx_lab_external_dispatches` (satu baris per pengiriman; putaran resend =
   baris baru, append-only). `LabExternalDispatchService`: prepare → sent (kurir/metode,
   no. resi, estimasi, biaya) → in progress → returned (langsung masuk result review) →
   review: ACCEPTED → `MODEL_DONE`; REJECTED → catatan wajib + putaran kirim-ulang baru.
   `MODEL_DONE` langsung dari status external ditolak matrix. Tracking manual, tanpa API.
5. **Permission map multi-nilai** — target transisi kini bisa dipetakan ke beberapa
   permission (`MODEL_DONE` ← pass_qc ATAU manage_lab_orders; step start-state ←
   start_production_work ATAU reject_qc untuk entry rework) — state machine memakai
   `canAny`; route middleware tetap membatasi endpoint langsungnya.
6. **UI hub** — `GET /lab/v2-orders` (pipeline lintas-cabang, filter status) +
   `GET /lab/v2-orders/{order}` (hub aksi: register/analisa/assign/step/QC/eksternal
   berdasarkan status + permission) + `GET/POST /lab/external-labs` (master, gated
   manage_lab_orders). Sidebar "Pipeline Lab V2". **Tanpa permission baru.**

## Tidak berubah

- Flag `lab.workflow_v2` tetap OFF default; pipeline legacy tidak disentuh; modul
  Production/QC legacy tetap melayani order legacy.
- Migration additive murni (3 tabel baru), tanpa drop/rewrite.
- RME/payment/inventory tidak disentuh.

## Validasi

- `tests/Feature/LabWorkflow`: **64 passed / 342 assertions** (Phase 3 baru: 19)
- Lab + Reporting regression (LabOrder/Production/QC/Delivery/Reporting): **251 passed / 528 assertions**
- `pint --dirty` + `git diff --check` bersih; `npm run build` pass; graphify updated.

## Deploy note

`php artisan migrate --force` (3 tabel baru). Tanpa perubahan permission/seeder.
