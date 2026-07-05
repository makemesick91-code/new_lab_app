# DaengtisiaMS — Master Context

## Tujuan
Memberikan konteks utama aplikasi Daengtisia Management System (DaengtisiaMS) agar AI memahami identitas proyek, ruang lingkup bisnis, stack, dan prinsip pengembangan sebelum menyentuh kode.

## Ringkasan
DaengtisiaMS (sebelumnya ADLMS — Asia Dental Lab Management System) adalah aplikasi Laravel modular monolith untuk operasional Klinik Gigi Daengtisia dan laboratorium gigi multi-cabang. Aplikasi mencakup RME, kasir/piutang, lab order, produksi, QC, delivery, inventory ledger, procurement, transfer stok, opname, laporan, dan dasbor owner.

## Konteks DaengtisiaMS
Dokumen ini adalah pintu masuk knowledge base. Baca sebelum dokumen modul-spesifik (08–20). Semua modul mematuhi arsitektur Controller → Request → Service → Repository → Model dan isolasi cabang via `BranchContext`.

**Foundation-first sprint lock:** ACTIVE. Baca `docs/architecture/foundation-first-sprint-lock-governance.md`.
Semua pekerjaan non-foundation adalah POST-FOUNDATION BACKLOG dan tidak boleh dieksekusi sebelum FOUNDATION GO.

## File / Area Repo Terkait
- `composer.json` — Laravel 12, PHP ^8.2, Spatie Permission, dompdf
- `package.json` — Vite, Tailwind, Alpine
- `docs/architecture_rules.md`, `docs/ai_development_guide.md`, `docs/inventory_rules.md`
- `docs/sprint_history.md`, `CLAUDE.md`, `AGENTS.md`
- `app/Modules/` — 26 modul domain
- `routes/web.php` — routing utama
- `database/migrations/` — skema PostgreSQL
- `tests/Feature/` — Pest feature tests

## Aturan Utama
| Aspek | Nilai (dari repo) |
|---|---|
| Nama aplikasi | **Daengtisia Management System** (`php artisan about`) |
| Organisasi | Klinik Gigi Daengtisia — operasi klinik + lab gigi multi-cabang |
| Database | PostgreSQL (`DB_CONNECTION=pgsql`) |
| Framework | Laravel 12.61.0 |
| PHP | 8.2+ (runtime lokal terdeteksi 8.5.4) |
| Auth & RBAC | Laravel Breeze + Spatie Permission |
| Frontend | Blade + Tailwind + Alpine (bukan React/Vue) |
| Arsitektur | Modular monolith di `app/Modules/<Module>/` |
| Branch | `BranchContext::requireId()` — jangan percaya `branch_id` dari request |
| Inventory | Ledger-only: `SUM(quantity_in) - SUM(quantity_out)` |
| Testing | Pest (feature), Laravel Dusk (browser smoke) |

**Branch stabil saat ini (git):** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`

**VPS path (ditemukan di docs):** `/var/www/asia-dental-lab-v2` — lihat `docs/sprint_23_phase_23_10_7_vps_deploy_combined_medical_record_print_smoke.md`, `docs/pilot/sprint_22_release_candidate_notes.md`

**Prinsip pengembangan:**
1. Perubahan kecil, ter-scope, dan ditest.
2. Jangan bypass sprint contract yang sudah selesai.
3. Jangan `migrate:fresh` / `db:wipe` di VPS.
4. Dokumentasi konstitusi (`architecture_rules`, `inventory_rules`) mengalahkan preferensi implementasi.
5. Foundation-first sprint lock mengalahkan backlog non-foundation sampai FOUNDATION GO.

## Workflow / Alur
1. Baca dokumen konstitusi (`docs/architecture_rules.md`, `docs/inventory_rules.md`, `docs/sprint_history.md`).
2. Identifikasi modul terdampak di `app/Modules/`.
3. Cek route, policy, permission, migration, dan test terkait.
4. Rencanakan patch minimal → implement → jalankan quality gates.
5. Jangan commit/deploy tanpa persetujuan eksplisit user.

## Struktur Teknis
**Modul utama (`app/Modules/`):**
AccessControl, Branch, Clinic, ClinicRoom, ClinicVisit, Delivery, Doctor, Inventory, Invoice, LabOrder, LabService, MedicalRecord, Odontogram, Patient, PaymentMethod, Production, QualityControl, Reporting, RmeDashboard, RmeInvoice, Tariff, Technician, Treatment, TreatmentCategory, User, WaReminderTemplate

**Provider pusat:** `app/Providers/RepositoryServiceProvider.php` — binding repository, policy, Gate::before Super Admin.

**Route count:** ~359 route terdaftar (`php artisan route:list`).

## Hal yang Tidak Boleh Diubah Sembarangan
- Alur arsitektur Controller → Service → Repository
- `BranchContext` dan isolasi cabang
- Ledger inventory (tanpa kolom stok mutable)
- Kontrak sprint RME: handwriting RM wajib sebelum finalize; SOAP tersembunyi di UI dokter
- Gate doctor→cashier→payment→completed visit (Sprint 62.1+)
- Permission/role seeding tanpa analisis regresi
- Target branch `main` — baseline aktif bukan `main` (lihat `CLAUDE.md`)

## Checklist Validasi
- [ ] Perubahan hanya di modul yang relevan
- [ ] Tidak ada query cross-branch tanpa permission eksplisit
- [ ] Tidak ada kolom stok mutable baru
- [ ] Test feature ditambah/diperbarui untuk workflow baru
- [ ] `php artisan test` (subset modul) dijalankan
- [ ] `./vendor/bin/pint` bersih
- [ ] Tidak ada perubahan di luar scope task

## Catatan untuk AI
- Gunakan dokumen ini sebagai **file pertama** saat onboarding ke proyek.
- Nama resmi aplikasi di runtime: **Daengtisia Management System**; dokumen lama masih menyebut ADLMS — keduanya merujuk repo yang sama.
- Jika informasi deploy VPS spesifik tidak ditemukan, tulis `TODO: belum ditemukan di repo` — jangan mengarang path server.
- Prioritaskan fakta dari kode/migration/test di atas dokumen lama.
