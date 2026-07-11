# FIX-LAB-TECHNICIAN-ROLE-ASSIGNMENT-UPLOAD-COMPRESSION

Restrict Lab Technician Assignment to Active Technician Accounts and Compress
All Lab Workflow Uploads.

- Branch: `feature/fix-lab-technician-role-assignment-upload-compression`
- Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- Baseline: `rme-branch-sun4-perawat-online-context-go` @ `5e06954`
- GO tag: `fix-lab-technician-role-assignment-upload-compression-go`

## Fix A — Assignment target = akun user aktif ber-role Technician

Root cause: `LabV2ProductionService::assignTechnician()` (dan legacy
`AssignmentService`) hanya memvalidasi master `mst_technicians.is_active`,
sehingga teknisi tanpa akun user, user nonaktif, atau user dengan role lain
tetap bisa menjadi target assignment; dropdown V2 juga membaca master legacy.

Solusi: `App\Modules\Technician\Services\TechnicianAssignmentEligibility`
menjadi single source of truth (dipakai V2 service, legacy assign/reassign,
dropdown V2 `lab-v2-orders.show`, dan form assign legacy `production.show`):

1. Master teknisi aktif + tidak soft-deleted.
2. Ter-link ke akun user (`mst_technicians.user_id`).
3. User ada, tidak soft-deleted, `is_active = true`.
4. User memegang role Spatie canonical **`Technician`** (relasi `roles`,
   bukan permission). `manage_production` adalah override AKTOR, bukan
   kualifikasi target — Super Admin bisa MELAKUKAN assign tetapi tidak bisa
   MENJADI target tanpa role Technician.
5. `technician_id` dari request selalu direvalidasi server-side
   (`assertAssignable`); crafted id ditolak dengan ValidationException.
6. Batasan branch/lab: tidak berlaku — teknisi adalah aktor lab pusat
   (lintas-cabang by design, seperti kurir).
7. Pencabutan role: histori assignment lama tetap terbaca (tidak pernah
   dihapus); assignment BARU ditolak. Board produksi legacy tetap memakai
   `listAll()` untuk filter historis.
8. Konkurensi: re-check assignment aktif di dalam transaksi setelah transisi
   state machine (yang memegang row lock order).

## Fix B — Kompresi canonical semua upload Lab Workflow

Inventaris entry point (semua sudah satu pipa `LabWorkflowEvidenceService`):
SPK photo + model photo (`LabWorkflowRequestService`, create + re-upload),
pickup photo (`LabPickupWorkflowService`), handover photo + courier signature
+ recipient signature + delivery location photo (`LabDeliveryWorkflowService`).
7 tipe `LabWorkflowEvidence::TYPES` → 100% ter-mapping ke profil kompresi
(diassert oleh test). Tidak ada tipe evidence PDF — pipeline image-only by
design; legacy `AttachmentService` (workflow lama, inactive untuk order baru)
tidak disentuh sprint ini.

Arsitektur: `LabEvidenceImageOptimizer` (GD, tanpa vendor baru) +
`config/lab_workflow_uploads.php`:

- Validasi: MIME dari bytes (`getimagesizefromstring`), magic bytes signature,
  input cap 10 MB (signature 512 KB), guard decompression bomb dari header
  (max 50 MP / 12000 px per sisi) SEBELUM full decode.
- Foto: EXIF auto-orient → resize ke long edge profil → re-encode **JPEG**
  (kompatibilitas maksimum; PNG/WebP diratakan ke putih). Re-encode menghapus
  seluruh EXIF/GPS dan menetralkan payload polyglot.
- Profil: `document` (SPK, 2200px, q82→68, target 700KB), `photo` (model/
  pickup/handover, 1800px, q78→62, target 600KB), `delivery` (1600px,
  q75→62, target 500KB) — semua hard max 1 MB; `signature` (PNG lossless,
  canvas 1200×600, hard max 300KB).
- Iterasi adaptif terbatas (max 6): quality turun bertahap sampai
  `min_quality`, lalu dimensi menyusut 0.85× — tidak pernah infinite loop;
  masih > hard cap → DITOLAK (fail closed, tidak pernah blur di bawah
  min_quality). Output diverifikasi decodable sebelum diterima.
- File kecil tidak diperbesar: original dipertahankan bila lebih kecil DAN
  tanpa resize/rotasi/perubahan format/EXIF (`original_kept_smaller_v1`).
- Fallback terdokumentasi (bukan kompresi palsu): runtime tanpa ekstensi GD
  menyimpan original tervalidasi dengan method `original_no_gd` + warning
  log. CI dan VPS pilot ber-GD sehingga produksi selalu terkompresi.
- Storage tetap privat (`disk local`, serve hanya via
  `lab-workflow-evidence.show` + policy); nama file generated; checksum
  sha256; konsistensi file↔DB dipertahankan (cleanup on failure).
- Metadata audit baru (migration additive nullable
  `2026_07_11_100001`): `original_file_size`, `width`, `height`,
  `compression_method` — bukti ukuran sebelum/sesudah per evidence; juga
  dicatat di `sys_audit_logs`. Original besar TIDAK disimpan setelah output
  terkompresi valid (tidak ada kebutuhan audit menyimpan original).
- Evidence lama TIDAK di-recompress massal (butuh sprint khusus); file
  hasil versi baru tetap terbaca versi aplikasi lama (JPEG/PNG standar).

## Validasi

- Harness GD nyata (container PHP 8.3 + GD): 10/10 PASS — foto noisy
  2600×2000 2021KB→478KB@1800px; SPK 4000×3000 2423KB→513KB@2200px; EXIF
  Orientation=6 diputar 400×200→200×400 + EXIF hilang; polyglot `<?php`
  hilang dari output; signature 2400×1200→1200×600 alpha 127 utuh; file
  kecil tidak membesar; corrupt ditolak; WebP→JPEG.
- Tests: `LabTechnicianAssignmentEligibilityTest` (12),
  `LabWorkflowUploadCompressionTest` (15; 8 GD-guarded berjalan di CI/VPS).
- CI: job test menambah ekstensi `gd, exif` (memastikan test GD benar-benar
  jalan di CI).
- Test-infra: `TechnicianFactory::assignable()`; helper `assignOrder()`/
  `technicianActor()`/`technicianWithUser()` kini menghasilkan target
  eligible; test Production legacy di-sed ke `assignable()`.

## Keterbatasan jujur

- PHP lokal dev (8.5.4) tanpa GD → 8 test GD skip lokal; dieksekusi di CI
  (gd,exif dipasang) dan divalidasi manual via container harness di atas.
- Tidak ada browser automation — bukti visual berbasis decode/dimensi/EXIF
  assertions + harness; readability dijaga lewat floor `min_quality` (68
  dokumen / 62 foto) dan resolusi minimum profil.

## Catatan regression lokal

Paket filter `QualityControl|Delivery|Evidence|Upload|Storage` menghasilkan 7
failure yang seluruhnya kelas **pre-existing lokal** "release evidence
check ci/vps → WATCH": 3 warning non-blocking `Optional artifact missing:
ent-1-4-audit-check.json / queue-worker-runtime-check.json /
runtime-hardening-check.json` (capture map lokal memang tidak memproduksi 3
artifact opsional POST-ENT — terdokumentasi sejak UIX-8 di CLAUDE.md; di CI
artifact diproduksi oleh gates script sehingga GO). Diverifikasi via
`ReleaseEvidenceService::capture('ci')` + `check('ci')` langsung: 27 checks,
24 passed, 3 warnings tersebut, 0 errors. Tidak ada file release-evidence/
release-safety yang disentuh sprint ini. Suite terdampak fix ini semuanya
hijau: LabWorkflow 130, Production|Technician 148, QC+Delivery 86, Storage 5,
LabOrder 40, test baru 19 (+8 GD-guarded di CI).
