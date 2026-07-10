# LAB-WORKFLOW-V2 — Phase 5: Notifikasi In-App, Label Legacy, KPI Pipeline & Persiapan Aktivasi

Tanggal: 2026-07-10
Branch: `feature/lab-workflow-v2-phase-5-notifications-legacy-labels-activation`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Baseline: Phase 4 GO `lab-workflow-v2-phase-4-delivery-proof-gates-go` @ `8fd2ba0`

## Runtime yang dikirim

1. **Notifikasi in-app (fondasi pertama di DaengtisiaMS)** — tabel `notifications`
   (skema standar Laravel, additive), `LabWorkflowEvent` (channel `database` SAJA —
   tidak pernah auto-WhatsApp/mail), `LabWorkflowNotificationService`
   (fan-out ke pemegang permission via Spatie, cap 100 penerima, **kegagalan di-log dan
   tidak pernah membatalkan transisi** — dibuktikan test drop-table). Hook post-commit:
   submit pickup → `manage_lab_pickups`; transit ke lab → `manage_lab_orders`;
   assign teknisi → user teknisi; kirim ke QC → `pass_qc`/`manage_quality_control`;
   QC lulus & review eksternal diterima → `create_delivery`/`manage_lab_orders`;
   QC gagal → user teknisi; tugas pengiriman dibuat → `start_delivery`;
   delivered → `create_lab_branch_requests` (idempotent — no-op tidak re-notify).
2. **Inbox notifikasi** — `GET /notifications` (auth, strictly self-scoped: id milik
   user lain = 404), mark read (+ redirect ke url terkait), mark-all-read; item sidebar
   "Notifikasi" dengan badge unread (semua user terautentikasi).
3. **Label legacy (UI; gate server sudah hidup sejak Phase 1)** — saat flag V2 aktif:
   halaman Order Lab legacy menampilkan badge "Legacy Workflow — Inactive for New Orders"
   + alert pengarah ke Permintaan Lab Cabang, tombol "+ Tambah Order Lab" disembunyikan
   (header + empty-state); detail order menampilkan badge "Lab Workflow V2" (order V2)
   atau "Legacy Workflow" (order lama saat V2 aktif). Riwayat legacy tetap readable.
4. **KPI pipeline V2** — 8 kartu stage (Menunggu Pickup / Menuju Lab / Perlu Analisa /
   Produksi Internal / QC Pending / Lab Eksternal / Pengiriman / Terkirim) dari satu
   query grouped-count (bucketing PHP) di `lab-v2-orders.index`.
5. **Rules canonical** — bagian LAB-WORKFLOW-V2 di `CLAUDE.md` (12 aturan permanen) +
   mirror `.cursor/rules/72-lab-workflow-v2.mdc`.

## Aktivasi (langkah pasca-deploy Phase 5, dieksekusi di VPS)

1. Tambahkan `FEATURE_LAB_WORKFLOW_V2=true` di environment file VPS.
2. `php artisan config:clear && php artisan config:cache`.
3. Smoke produksi: legacy create ditolak/tersembunyi, workspace Cabang/Pickup/Pipeline/
   Pengiriman terbuka, konversi kandidat RME → V2 DRAFT, tidak ada error log baru.
4. Rollback aktivasi: set `FEATURE_LAB_WORKFLOW_V2=false` + config:cache — order V2
   tetap readable, order baru kembali ke alur legacy (tanpa migrasi data).

## Validasi

- `tests/Feature/LabWorkflow`: **90 passed / 431 assertions** (Phase 5 baru: 11 —
  dispatch notifikasi, notification-failure-never-blocks, inbox self-scope/404,
  badge legacy + tombol tersembunyi saat aktif, badge V2/legacy di detail, KPI, terminal DELIVERED)
- Legacy LabOrder + Delivery regression: **82 passed / 206**; RME→Lab bridge: **27 passed / 64**
- `pint --dirty` + `git diff --check` bersih; `npm run build` pass; graphify updated.

## Deploy note

`php artisan migrate --force` (tabel `notifications`). Tanpa seed/permission change.
Aktivasi flag adalah langkah eksplisit terpisah setelah smoke deploy (lihat atas).
