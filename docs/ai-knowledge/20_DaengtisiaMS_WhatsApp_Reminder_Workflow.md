# DaengtisiaMS — WhatsApp Reminder Workflow

## Tujuan
Template WA, reminder manual, follow-up piutang, SOP operator — **bukan** auto-send.

## Ringkasan
Fitur resmi: master template `mst_wa_reminder_templates` + SOP manual pilot. **Tidak ada** integrasi WhatsApp API, queue, atau pengiriman otomatis dari aplikasi.

## Konteks DaengtisiaMS
Reminder adalah proses operasional di luar aplikasi; sistem hanya menyediakan template teks dan data referensi.

## File / Area Repo Terkait
- `app/Modules/WaReminderTemplate/` — CRUD template
- `mst_wa_reminder_templates` migration
- `settings/wa-reminder-templates` routes (clinic master permission)
- `docs/sprint_29_phase_29_3_whatsapp_reminder_manual_pilot_sop.md`
- `docs/sprint_41_whatsapp_manual_reminder_operationalization_follow_up_workflow.md`
- `tests/Feature/ClinicMasterData/WaReminderTemplateTest.php`

## Aturan Utama

### Yang ADA di repo
- CRUD template pesan WA (master data klinik)
- SOP manual dua jalur:
  - **Lane A:** Appointment reminder / konfirmasi kehadiran
  - **Lane B:** Receivable / piutang follow-up
- Follow-up piutang di aplikasi: `trx_rme_receivable_follow_ups` — catatan internal, **bukan** pengiriman WA

### Yang TIDAK ADA (eksplisit di docs)
- WhatsApp API integration
- Bot / scheduler auto-send
- Queue/job notification untuk WA
- Pengiriman dari controller/service

### SOP operator (ringkas)
1. Operator verifikasi data di aplikasi (nama, jadwal, saldo piutang)
2. Salin template dari master atau SOP
3. Kirim manual via WhatsApp pribadi/bisnis klinik
4. Catat bukti di log harian / follow-up record (Lane B → form follow-up di receivables)
5. **Jangan** kirim KTP, detail klinis berlebihan, atau nominal yang tidak diverifikasi kasir

### Privasi
- No. KTP tidak boleh di pesan WA
- Minimalkan detail medis
- Piutang: hanya setelah verifikasi saldo di sistem

### Roles
- Front office: appointment reminder
- Kasir/admin: piutang follow-up
- Supervisor/owner: eskalasi
- Dokter: bukan owner default untuk piutang follow-up

## Workflow / Alur
```text
[App] Kelola template teks (settings)
[App] Lihat jadwal visit / daftar piutang
[Manual] Operator kirim WA di luar app
[App] Catat follow-up piutang (opsional)
```

## Struktur Teknis
| Entity | Tabel |
|---|---|
| Template | `mst_wa_reminder_templates` |

Route: `settings.wa-reminder-templates.*` under `view_clinic_master_data|manage_clinic_master_data`

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan implement auto-send tanpa spec security & privacy review
- Jangan expose KTP/NIK di template default
- Jangan ubah aturan cashier/receivable untuk "memudahkan" reminder

## Checklist Validasi
- [ ] `WaReminderTemplateTest`
- [ ] Tidak ada job/command send WA di repo (grep `WhatsApp` sebelum claim)
- [ ] SOP docs masih align dengan runtime

## Catatan untuk AI
Jika user minta "integrasi WhatsApp API" — itu **out of scope** baseline; mulai dari spec baru + Phase terpisah.

Future automation: lihat `docs/sprint_28_phase_28_3_whatsapp_reminder_receivable_follow_up_workflow_planning.md` (planning only).

**UNKNOWN:** Vendor WA API pilihan klinik — perlu verifikasi manual dengan owner.
