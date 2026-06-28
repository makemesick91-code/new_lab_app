# DaengtisiaMS — Tech Stack & Arsitektur

## Tujuan
Menjelaskan stack teknis, struktur folder Laravel modular monolith, dan pola implementasi wajib.

## Ringkasan
Laravel 12 modular monolith, PostgreSQL, Blade+Tailwind+Alpine, Pest, Spatie Permission. Alur wajib: Controller → Request → Service → Repository → Model.

## Konteks DaengtisiaMS
Semua fitur baru harus mengikuti pola yang sama dengan modul existing. UI mengikuti `docs/ui_design_system.md`.

## File / Area Repo Terkait
- `composer.json`, `package.json`
- `app/Modules/<Module>/`
- `app/Providers/RepositoryServiceProvider.php`
- `bootstrap/app.php` — middleware alias (mis. `visit.room`)
- `resources/views/`, `resources/js/app.js`
- `vite.config.js`, `tailwind.config.js`
- `config/` — database, permission, cache, queue
- `docs/architecture_rules.md`

## Aturan Utama

### Stack
| Layer | Teknologi |
|---|---|
| Backend | PHP 8.2+, Laravel 12 |
| DB | PostgreSQL |
| Auth | Laravel Breeze + session |
| RBAC | spatie/laravel-permission 6.25 |
| PDF | barryvdh/laravel-dompdf |
| CSS | Tailwind 3 + @tailwindcss/forms |
| JS | Alpine 3, Vite 6 |
| Test | Pest 3, Laravel Dusk 8 (dev) |

### Pola layer
```text
HTTP → Controller → FormRequest → Service → RepositoryInterface → Repository → Model
         ↓ authorize via Policy
```

### Folder penting
```text
app/Modules/<Module>/Controllers|Requests|Services|Repositories|Interfaces|Models|Policies
routes/web.php
database/migrations/
database/seeders/
resources/views/
tests/Feature/
tests/Browser/          # Dusk smoke
docs/
.cursor/rules/
```

### Build asset
```bash
npm run dev    # development
npm run build  # production (wajib jika ubah JS/CSS)
```

### Cache & environment
- Driver cache default: `file` (`php artisan about`)
- Session: `database`
- Queue: `database`
- Timezone app: `UTC` (perhatikan saat laporan harian)
- Locale default: `en` (UI banyak teks Indonesia di Blade)

## Workflow / Alur
### Scaffolding fitur baru
1. Identifikasi modul pemilik
2. FormRequest untuk validasi
3. Service + transaksi DB untuk multi-write
4. Repository dengan `int $branchId` pertama untuk data branch-owned
5. Policy + route middleware permission
6. View Blade dengan komponen `x-ui.*`
7. Pest feature test: happy path, validasi, auth, branch isolation

## Struktur Teknis
- **Middleware kustom:** `visit.room` → `EnsureVisitRoomAssigned` (gate RM/Odontogram jika visit belum punya ruangan)
- **Morph map:** `RepositoryServiceProvider` — entity_type pakai nama tabel
- **Super Admin bypass:** `Gate::before` di `RepositoryServiceProvider`
- **Factories:** `database/factories/` — `ClinicVisitFactory` default punya `clinic_room_id`

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan introduce React/Vue/framework baru
- Jangan bypass Repository interface jika modul sudah punya pola tersebut
- Jangan taruh business logic di Blade atau Controller
- Jangan ubah `users.branch_id` — kolom **tidak ada** di schema saat ini (`BranchContext` guarded)

## Checklist Validasi
- [ ] Controller tipis (authorize + delegate)
- [ ] Service memakai `DB::transaction` untuk workflow multi-step
- [ ] Repository branch-scoped
- [ ] Policy terdaftar
- [ ] Route punya middleware `auth` + `permission:...`
- [ ] Pint + test subset hijau

## Catatan untuk AI
- `php artisan about` menampilkan nama **Daengtisia Management System**.
- Test suite memakai SQLite in-memory (lihat `docs/sprint_history.md`); migrate production pakai PostgreSQL.
- Graphify (`graphify-out/`) tersedia untuk navigasi arsitektur — jalankan `graphify update .` setelah ubah kode.
