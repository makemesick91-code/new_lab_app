# DaengtisiaMS — Visit & Medical Record Rules

## Tujuan
Aturan kunjungan klinik, rekam medis, status, finalisasi, cancel, follow-up, dan carry-over piutang.

## Ringkasan
Satu visit (`trx_clinic_visits`) memiliki maksimal satu medical record sheet. RM editable pasca-finalize; finalize butuh handwriting; workspace patient-centric (Sprint 64).

## Konteks DaengtisiaMS
Visit menggerakkan pipeline RME. Medical record tied to visit — finalize RM tidak sama dengan complete visit.

## File / Area Repo Terkait
- `app/Modules/ClinicVisit/Models/ClinicVisit.php`
- `app/Modules/ClinicVisit/Services/ClinicVisitService.php`
- `app/Modules/MedicalRecord/Models/MedicalRecord.php`
- `app/Modules/MedicalRecord/Services/MedicalRecordService.php`
- `app/Modules/MedicalRecord/Services/PatientRmWorkspaceResolver.php`
- `app/Modules/MedicalRecord/Controllers/MedicalRecordController.php`
- `app/Modules/MedicalRecord/Controllers/MedicalRecordHandwritingController.php`
- `resources/views/rme/visits/medical-record/`
- `tests/Feature/RME/ClinicVisitTest.php`
- `tests/Feature/RME/MedicalRecordFinalizationTest.php`
- `tests/Feature/RME/PatientCentricRmWorkspaceTest.php`
- `tests/Feature/RME/Sprint6402CanonicalHandwritingRmTest.php`

## Aturan Utama

### ClinicVisit
- **Visit types:** `new`, `control`, `continued_treatment`, `emergency`
- **Follow-up types:** subset untuk kunjungan lanjutan
- **Room:** `clinic_room_id` nullable saat create; wajib sebelum exam (middleware)
- **Cancel:** `cancelled_at` column; terminal
- **Control visit:** alur piutang/control chain — lihat `RmeControlReceivableService`

### MedicalRecord
- Status: `draft` / `final` (konstanta model `MedicalRecord::STATUS_*`)
- `finalized_at`, `finalized_by` — retained untuk kompatibilitas
- Sprint 59: **tidak ada edit-lock** setelah finalize — dokter boleh revisi
- Finalize masih memvalidasi handwriting ada
- Partial save `updateDraft` — hanya key yang dikirim yang di-update

### Patient-centric workspace (Sprint 64)
- Kolom: `canonical_visit_id`, `source_visit_id`, `sheet_number`
- Canonical URL: kunjungan paling awal non-cancelled yang punya MR
- Redirect non-canonical → canonical + `?source_visit_id=`
- Active sheet via `?sheet=` query
- Handwriting pages canonical visit (Sprint 64.0.2)

### Handwriting
- Legacy page 1: `trx_medical_record_handwritings.handwriting_path`
- Page 2+: `trx_medical_record_handwriting_pages`
- 1 canvas = 1 halaman RM (`?rm_page=`)

### Carry-over receivable (Sprint 62.2)
- Kunjungan baru: kasir boleh **opt-in** bayar piutang invoice lama pasien
- Server-side intersection eligible IDs — cegah IDOR
- `payment_batch_uuid` mengelompokkan pembayaran multi-invoice
- Visit baru tetap `completed` setelah payment batch (partial OK)

### SOAP
- Field legacy ada di schema — **hidden** dari UI dokter by design

## Workflow / Alur
1. Create visit → status `registered` (roomless default di factory/production create)
2. Assign room → dokter buka RM
3. `createDraft` / update notes + handwriting
4. `finalize` → MR status final + visit transition ke `cashier_pending` (via visit transition terpisah — verifikasi controller flow)
5. Kasir create invoice → payment → visit `completed`

## Struktur Teknis
| Route | Aksi |
|---|---|
| `rme.visits.medical-record.show` | Workspace RM |
| `rme.visits.medical-record.store` | Create draft |
| `rme.visits.medical-record.update` | PATCH draft |
| `rme.visits.medical-record.finalize` | Finalize |
| `rme.visits.medical-record.handwriting.store` | Simpan canvas |
| `rme.visits.transition` | Ubah status visit |

**Middleware:** `visit.room` pada RM routes

## Hal yang Tidak Boleh Diubah Sembarangan
- UNIQUE `clinic_visit_id` pada medical record (1 sheet per visit sprint 64)
- Jangan kembalikan lock edit pasca-finalize tanpa spec
- Jangan bypass handwriting requirement pada finalize
- Jangan merge piutang ke invoice baru (carry-over = payment allocation terpisah)

## Checklist Validasi
- [ ] `Sprint59EditableWorkflowTest`
- [ ] `Sprint60MultiPageHandwritingTest`
- [ ] `PatientOutstandingReceivableCarryOverTest`
- [ ] Canonical redirect tidak loop
- [ ] Finalize tanpa handwriting → ditolak

## Catatan untuk AI
**Catatan Konflik / Perlu Verifikasi:** Dokumen Sprint 20 awal menyebut RM immutable setelah finalize — **kode saat ini (Sprint 59+)** mengizinkan edit. Ikuti kode/test terbaru.

Dokter **tidak** boleh set visit `completed` langsung — hanya `cashier_pending` dari `in_progress`.
