# DaengtisiaMS — RME Workflow

## Tujuan
Mendokumentasikan alur end-to-end Rekam Medis Elektronik dari pendaftaran hingga print bundle.

## Ringkasan
Pipeline: Pendaftaran → Antrian → Input Ruangan → Dokter "Mulai Pemeriksaan" (`in_progress`) → Consent (membuka penulisan RME) → Pemeriksaan Dokter (RM + Odontogram) → Dokter "Selesai Pemeriksaan" (`cashier_pending`) → Kasir/Pembayaran (tanpa cek consent) → Visit `completed`.

> FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 memindahkan consent ke AWAL pemeriksaan
> (gate penulisan RME, bukan gate pembayaran) dan memisahkan finalisasi dokumen dari
> penyelesaian pemeriksaan. Lihat `.cursor/rules/109-rme-exam-consent-odontogram-history.mdc`.

## Konteks DaengtisiaMS
RME adalah modul pilot utama klinik. Handwriting RM adalah input klinis primer; SOAP tersembunyi di UI dokter.

## File / Area Repo Terkait
- `app/Modules/ClinicVisit/` — visit, antrian, transition
- `app/Modules/MedicalRecord/` — RM, handwriting, finalize
- `app/Modules/Odontogram/`
- `app/Modules/Patient/`
- `app/Modules/RmeInvoice/` — billing & payment
- `app/Modules/ClinicVisit/Middleware/EnsureVisitRoomAssigned.php`
- `routes/web.php` — grup `rme.*`
- `resources/views/rme/`
- `docs/sprint_20_rme_limited_pilot_summary.md`
- `tests/Feature/RME/`

## Aturan Utama

### Status visit (`ClinicVisit`)
| Status | Label operasional (contoh) |
|---|---|
| `registered` | Terdaftar |
| `waiting` | Menunggu |
| `in_progress` | Dalam pemeriksaan |
| `cashier_pending` | Selesai pemeriksaan / menunggu kasir |
| `completed` | Selesai visit |
| `cancelled` | Dibatalkan |

### Transisi valid (`VALID_TRANSITIONS`)
- `registered` → `waiting`, `cancelled`
- `waiting` → `in_progress`, `cancelled`
- `in_progress` → `cashier_pending`, `cancelled`
- `cashier_pending` → `completed` (**hanya** via pembayaran kasir, bukan manual doctor)
- `completed`, `cancelled` → terminal

### Gate ruangan (Hotfix 60.8)
- Visit pre-exam tanpa `clinic_room_id` → middleware `visit.room` blok RM/Odontogram
- `cashier_pending` & terminal exempt (editing visit lama tidak terblokir)

### Rekam medis
- Handwriting PNG **wajib** sebelum finalize
- Sprint 59: RM **tetap editable** setelah finalize (kolom `finalized_at` retained)
- Sprint 60: multi-page canvas (`trx_medical_record_handwriting_pages`)
- Sprint 64: patient-centric workspace — canonical visit URL, sheet per kunjungan

### Odontogram
- Table-first editing (Sprint 59)
- Structured print template (Sprint 63.1)

### Kasir
- Billing hanya jika visit `cashier_pending` + RM finalized
- Pembayaran sebagian **menyelesaikan visit** (hotfix partial payment) — sisa jadi piutang
- Carry-over piutang visit baru (Sprint 62.2) — opt-in, tidak merge invoice

### Consent
- Gate pembayaran: TIDAK ada pemeriksaan consent. Consent diambil di awal pemeriksaan dan menggate penulisan RME (`RmeVisitConsentService::assertRmeAuthoringAllowed`)

### Lab integration
- Setelah invoice RME `PAID` → `LabCaseCandidate` idempotent (bukan LabOrder langsung)

### Print
- `rme.visits.print`, `rme.visits.pdf` — bundle visit
- Browser print `window.print()` — tidak server PDF wajib untuk semua

## Workflow / Alur
```text
1. Admin/Perawat: daftar pasien (settings.patients) atau kunjungan baru (rme.visits.create)
2. Antrian: rme.patient-queue.index
3. Assign ruangan: PATCH rme.visits.assign-room
4. Dokter: buka RM (rme.visits.medical-record.show) — workspace sheets jika multi-visit
5. Isi catatan + handwriting canvas + odontogram
6. Finalize RM → HANYA mengubah status dokumen. Visit TETAP `in_progress`.
7. Dokter klik "Selesai Pemeriksaan" → transition ke `cashier_pending` (satu-satunya jalur)
7. Kasir: rme.cashier.create → payment → visit completed
8. Opsional: print bundle / PDF
```

## Struktur Teknis
| Entitas | Tabel |
|---|---|
| Visit | `trx_clinic_visits` |
| Medical Record | `trx_medical_records` |
| Handwriting | `trx_medical_record_handwritings`, `trx_medical_record_handwriting_pages` |
| Odontogram | `trx_odontograms` |
| Invoice RME | `trx_rme_invoices`, `trx_rme_invoice_items` |
| Payment | `trx_rme_payments` |

**Permissions:** `view_clinic_visits`, `manage_clinic_visits`, `manage_rme_billing`

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan buka kembali SOAP di UI dokter tanpa spec product
- Jangan izinkan `in_progress` → `completed` langsung (Sprint 62.1)
- Jangan hapus gate handwriting sebelum finalize
- Jangan auto-create LabOrder dari payment RME

## Checklist Validasi
- [ ] `RmeDoctorCashierCompletionGateTest`
- [ ] `RmeRoomAssignmentGateTest`
- [ ] `MedicalRecordFinalizationTest`
- [ ] `CashierBillingTest`, `RmePaymentTest`
- [ ] `PatientCentricRmWorkspaceTest`
- [ ] Manual: room gate → RM → finalize → kasir → print

## Catatan untuk AI
Triage awal visit **tidak** membuat billing. Initial treatment di visit bukan invoice otomatis.

Untuk detail RM/KTP/odontogram/kasir, lihat dokumen 09–12.
