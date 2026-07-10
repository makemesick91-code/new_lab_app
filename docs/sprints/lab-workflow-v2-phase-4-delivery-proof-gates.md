# LAB-WORKFLOW-V2 — Phase 4: Delivery dengan Gate Bukti Wajib

Tanggal: 2026-07-10
Branch: `feature/lab-workflow-v2-phase-4-delivery-proof-gates`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Baseline: Phase 3 GO `lab-workflow-v2-phase-3-analysis-production-qc-external-lab-go` @ `ee0c800`

## Runtime yang dikirim

1. **Tugas pengiriman** — tabel baru additive `trx_lab_delivery_tasks` (UNIQUE
   `lab_order_id` = idempotent; tujuan = cabang asal order; recipient_name/role,
   milestone timestamps). Dibuat eksplisit dari hub V2 setelah `MODEL_DONE`
   (chain `MODEL_DONE → DELIVERY_PENDING → COURIER_NOTIFIED`); ditolak selama
   produksi belum selesai (matrix).
2. **Klaim kurir** — first-committed-wins di bawah `lockForUpdate` (kurir kedua
   ditolak; retry kurir sama idempotent); order masuk `LAB_HANDOVER_PENDING`.
3. **ATURAN OWNER — bukti pra-pengantaran (server-side, bukan UI-only)**:
   sebelum `IN_TRANSIT_TO_BRANCH` WAJIB ada **foto serah terima lab→kurir DAN
   tanda tangan kurir**. Ditegakkan 3 lapis: FormRequest (`handover_photo` +
   `courier_signature` keduanya required — foto-saja atau ttd-saja ditolak),
   service (`submitHandoverProof` atomic: simpan kedua artefak lalu re-assert
   keduanya ada sebelum `READY_FOR_TRANSIT_TO_BRANCH`; `startTransit`
   re-verifikasi lagi), dan matrix (edge `LAB_HANDOVER_PENDING → IN_TRANSIT`
   tidak ada — status milestone harus dilewati berurutan). Signature = PNG dari
   kanvas Alpine, divalidasi regex data-URL + magic bytes PNG + size, disimpan
   sebagai file di disk privat (bukan base64 di DB).
4. **ATURAN OWNER — bukti serah terima penerima**: `DELIVERED` WAJIB
   **nama penerima + tanda tangan penerima + foto lokasi/bukti** (role opsional).
   FormRequest ketiganya required; service atomic + re-assert sebelum edge
   final; double-submit idempotent (tanpa duplikasi bukti); `DELIVERED`
   terminal (tidak ada transisi keluar).
5. **UI** — antrian `GET /lab/delivery-tasks` (Aktif/Tugas Saya/Semua) + detail
   dengan kanvas tanda tangan Alpine (kurir & penerima), galeri 4 bukti, CTA
   per status; kartu "Buat Tugas Pengiriman" di hub V2 saat `MODEL_DONE`;
   sidebar "Pengiriman Lab V2". **Tanpa permission baru** (pakai
   view/create/start_delivery + mark_delivered + manage_delivery existing).

## Tidak berubah

- Flag `lab.workflow_v2` tetap OFF; delivery legacy (`deliveries.*`) tidak disentuh.
- Migration additive murni (1 tabel); tanpa perubahan seeder/permission.

## Validasi

- `tests/Feature/LabWorkflow`: **79 passed / 395 assertions** (Phase 4 baru: 15,
  termasuk foto-saja ditolak, ttd-saja ditolak, polyglot PNG ditolak, transit
  tanpa bukti ditolak, DELIVERED tanpa masing-masing bukti ditolak, double-complete
  idempotent, non-owner kurir 403, terminal state)
- Delivery legacy + LabOrder regression: **82 passed / 206 assertions**
- `pint --dirty` + `git diff --check` bersih; `npm run build` pass; graphify updated.

## Deploy note

`php artisan migrate --force` (1 tabel baru). Tanpa seed/permission change.
