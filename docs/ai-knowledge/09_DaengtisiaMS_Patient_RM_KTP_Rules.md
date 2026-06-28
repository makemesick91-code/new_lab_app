# DaengtisiaMS — Patient, RM & KTP Rules

## Tujuan
Aturan nomor rekam medis (RM), format, duplikasi, privasi KTP, dan lookup RM.

## Ringkasan
Format RM resmi: `DG-{KODE_CABANG}-{TAHUN}-{NOMOR_MANUAL}`. KTP unik dan harus di-mask di UI/export. Lookup lintas cabang hanya by RM penuh/suffix.

## Konteks DaengtisiaMS
Pasien adalah anchor data RME. RM dan KTP punya aturan komposisi dan privasi ketat.

## File / Area Repo Terkait
- `app/Modules/Patient/Services/PatientMedicalRecordNumberService.php`
- `app/Modules/Patient/Services/PatientCodeGenerator.php` — legacy auto-sequence
- `app/Modules/Patient/Services/PatientService.php`
- `app/Modules/Patient/Services/PatientDataCompletenessService.php` — `maskKtp()`
- `app/Modules/Patient/Services/CrossBranchPatientLookupService.php`
- `app/Modules/Patient/Requests/StorePatientRequest.php`, `UpdatePatientRequest.php`
- `app/Modules/Patient/Services/LegacyPatientImportService.php`
- `mst_patients` migration
- `tests/Feature/MasterData/PatientCodeGenerationTest.php`
- `tests/Feature/RME/PatientKtpScanTest.php`
- `docs/sprint_57_cross_branch_rm_lookup_spec.md`

## Aturan Utama

### Format RM (Sprint 23.8 — locked)
```
DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}
```
Contoh: `DG-TKM1-2026-0001`, `DG-LDK2-2026-25`

- Prefix tetap `DG`
- Kode cabang: uppercase, dari `mst_branches.code`
- Tahun: 4 digit dari tanggal registrasi
- Nomor manual: **tidak** auto-pad oleh composer (leading zero dipertahankan verbatim)
- Unik global di `mst_patients` (termasuk soft-deleted saat cek collision)

### Sumber nomor
1. Explicit `medical_record_number` di form → dipakai apa adanya jika diisi
2. Compose via `composeForRegistration(branchCode, registeredAt, manualRmNumber)`
3. Legacy fallback: `PatientCodeGenerator::generate()` jika tidak pakai format baru

### KTP
- Unique di `mst_patients` (validasi request)
- **Privasi:** `PatientDataCompletenessService::maskKtp()` — hanya digit terakhir yang ditampilkan di audit/export
- Full KTP **tidak** dirender di halaman audit CSV/export
- Scan dokumen KTP: `mst_patient_documents` — governance Sprint 61.3

### Duplicate prevention
- RM collision → error (import legacy: hard block)
- KTP collision → error
- Import legacy: warning untuk name+DOB match, unresolved doctor, dll.

### Cross-branch RM lookup (Sprint 57)
- `CrossBranchPatientLookupService` — match `medical_record_number` only (bukan nama/telepon/KTP)
- Suffix lookup: `LIKE %suffix` dengan escape
- Untuk registrasi kunjungan di cabang lain — bukan edit data cabang asal tanpa policy

### RM gap review & audit
- `rme.patients.audit` — completeness score, missing fields
- MAIN branch excluded dari filter audit

## Workflow / Alur
**Registrasi pasien baru:**
1. Pilih cabang (eksplisit di form)
2. Input nomor RM manual + data demografi
3. Service compose `DG-...` atau terima explicit RM
4. Validasi unique RM & KTP
5. Simpan `mst_patients` dengan `branch_id`

## Struktur Teknis
| Field | Tabel | Catatan |
|---|---|---|
| `medical_record_number` | `mst_patients` | indexed, unique |
| `ktp_number` | `mst_patients` | sensitive |
| `branch_id` | `mst_patients` | nullable di legacy import |
| `doctor_id` | `mst_patients` | nullable (Sprint 62.3) |
| `import_batch_id` | `mst_patients` | FK batch import legacy |

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan tampilkan KTP penuh di Blade/report/export
- Jangan longgarkan unique RM/KTP untuk "mempermudah" import
- Jangan ubah format `DG-` tanpa persetujuan product owner
- Jangan enable search pasien lintas cabang by KTP/nama di operator UI tanpa spec

## Checklist Validasi
- [ ] `PatientDataCompletenessAuditTest`
- [ ] `LegacyPatientBatchImportTest` collision paths
- [ ] Audit export tidak mengandung KTP plain
- [ ] Compose RM dengan cabang & tahun benar

## Catatan untuk AI
`PatientCodeGenerator` adalah jalur legacy terpisah — jangan campur dengan composer `DG-` tanpa memahami form flow.

**TODO:** Field exact length/index KTP di migration — verifikasi `create_mst_patients` jika audit schema.
