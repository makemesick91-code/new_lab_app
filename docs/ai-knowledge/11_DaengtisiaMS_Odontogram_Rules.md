# DaengtisiaMS — Odontogram Rules

## Tujuan
Aturan odontogram terstruktur: tooth map, hasil, kondisi tambahan, print, dan finalisasi.

## Ringkasan
Odontogram disimpan sebagai `tooth_map_payload` JSON di `trx_odontograms`. UI table-first; grid FDI auto-generated. Print memakai formatter terstruktur dompdf-safe.

## Konteks DaengtisiaMS
Odontogram adalah bagian pemeriksaan dokter per visit, di-gate `visit.room` sama seperti RM.

## File / Area Repo Terkait
- `app/Modules/Odontogram/Models/Odontogram.php`
- `app/Modules/Odontogram/Services/OdontogramService.php`
- `app/Modules/Odontogram/Services/OdontogramPrintFormatter.php`
- `app/Modules/Odontogram/Controllers/OdontogramController.php`
- `resources/js/app.js` — Alpine `odontogramEditor`
- `resources/views/rme/visits/odontogram/`
- `resources/views/rme/visits/odontogram/partials/structured-print-template.blade.php`
- `tests/Feature/RME/OdontogramTest.php`
- `tests/Feature/RME/StructuredOdontogramPrintTemplateTest.php`

## Aturan Utama

### Data model
- Tabel: `trx_odontograms`
- `tooth_map_payload` — structured tooth statuses per FDI
- `additional_condition` — field tambahan (migration `add_additional_fields`)
- `finalized_at`, `finalized_by` — retained; Sprint 59: **editable setelah finalize**
  (hanya pada encounter aktif — lihat CORRECTIVE-03 di bawah)
- Relasi: satu odontogram per `clinic_visit_id`

### UI editing (Sprint 59)
- Dokter edit via tabel "Hasil Odontogram yang Dipilih"
- `availableTeeth`, `addRow()`, `setStatus()`, `removeRow()` di Alpine
- Grid FDI = visual auto-generated, bukan drawing manual

### Status gigi & DMF-T
- `Odontogram::dmftCounts()` — D=caries, M=missing, F=filling/crown/root_treated
- normal/untouched/unknown tidak inflasi DMF-T
- unknown → default cell di print

### Print (Sprint 63.1)
- `OdontogramPrintFormatter` — jaw rows center→outer
- Partial shared: `structured-print-template.blade.php`
- **HTML table only** di print — NO flexbox (dompdf)
- Route: `rme.odontograms.print` standalone + included di `rme.visits.print` bundle

### Finalisasi
- Route: `rme.odontograms.finalize` POST
- Policy `update` tidak lagi gate `isFinalized()` (Sprint 59)
- **CORRECTIVE-03 (FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3):** mutasi odontogram
  aktif (`updatePlaceholder`/`finalize`) wajib melewati
  `RmeVisitConsentService::assertOdontogramAuthoringAllowed()` — pasien harus punya
  tepat satu kunjungan `in_progress`, aktor berwenang atasnya, **odontogram yang
  diubah harus milik kunjungan itu**, dan consent sudah ditandatangani. Karena itu
  **odontogram kunjungan lampau permanen hanya-baca** dan consent hari ini tidak
  membukanya kembali. Membuka halaman + pembuatan placeholder kosong tetap diizinkan
  (dokter perlu melihat chart sebelum pasien menyetujui tindakan). Policy `update`
  sengaja tidak diubah, sehingga penolakan membawa pesan yang jelas, bukan 403 kosong.

### Preview
- Sprint 63.1.1: saved result table preview di halaman odontogram

## Workflow / Alur
1. Buka `rme.visits.odontogram.show` (visit harus punya room)
2. Tambah/edit baris gigi di tabel
3. Simpan via PATCH `rme.odontograms.update`
4. Opsional finalize
5. Print standalone atau via visit bundle

## Struktur Teknis
| Komponen | Path |
|---|---|
| Controller | `OdontogramController` |
| Service | `OdontogramService` |
| Print formatter | `OdontogramPrintFormatter` |
| Policy | `OdontogramPolicy` |

**Permissions:** `manage_clinic_visits` untuk update; view untuk print

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan kembali ke manual drawing grid tanpa spec
- Jangan pakai flexbox di template print dompdf
- Jangan expose data klinis sensitif di print di luar odontogram/RM yang sudah disetujui

## Checklist Validasi
- [ ] `OdontogramTest` — CRUD + branch
- [ ] `StructuredOdontogramPrintTemplateTest`
- [ ] Print bundle visit includes odontogram partial
- [ ] Edit setelah finalize allowed (Sprint 59 regression)

## Catatan untuk AI
Odontogram data **tidak** di-mutate saat print — formatter read-only.

Untuk navigasi prev/next visit antar pasien, lihat partial `visit-nav-arrows` di RM/Odontogram views.
