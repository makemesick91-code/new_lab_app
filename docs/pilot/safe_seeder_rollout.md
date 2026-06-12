# Rollout Seeder Aman — Pilot VPS Sprint 22

## 1. Tujuan

Dokumen ini menjelaskan cara menjalankan seeder **aman dan idempotent** di database pilot/VPS setelah deploy Sprint 22 Phase 22.1 dan 22.2.

**Tujuan utama:**

- Memperbarui permission dan role pilot (Phase 22.1).
- Menyediakan data smoke test RME opsional (Phase 22.2).
- **Tidak** merusak atau menghapus data pilot yang sudah ada.

---

## 2. Daftar Seeder

| Seeder | File | Wajib untuk deploy Phase 22.1/22.2 |
|--------|------|-------------------------------------|
| `PermissionSeeder` | `database/seeders/PermissionSeeder.php` | Ya — permission baru (dashboard owner/branch, dll.) |
| `RoleSeeder` | `database/seeders/RoleSeeder.php` | Ya — role Owner, Kasir, Perawat, hardening Doctor |
| `RmeSmokeTestSeeder` | `database/seeders/RmeSmokeTestSeeder.php` | **Opt-in** — hanya jika tim butuh akun/data uji smoke test |

`RmeSmokeTestSeeder` **tidak** terdaftar di `DatabaseSeeder`. Harus dipanggil eksplisit.

---

## 3. Urutan Perintah

Jalankan **berurutan** di VPS setelah backup database:

```bash
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=RoleSeeder
php artisan db:seed --class=RmeSmokeTestSeeder
```

Langkah 1–2 wajib untuk Phase 22.1. Langkah 3 opsional untuk Phase 22.2 smoke test.

---

## 4. Yang Boleh Dilakukan Setiap Seeder

### PermissionSeeder

- Menambah permission baru via `firstOrCreate` / sync.
- Memperbarui daftar permission yang dipakai role.

### RoleSeeder

- Membuat/memperbarui role (Owner, Kasir, Perawat, Doctor, Admin Lab, dll.).
- Men-sync permission ke role sesuai desain pilot.

### RmeSmokeTestSeeder

- Membuat/memperbarui cabang `MAIN`, klinik smoke test, dokter master, pasien, kunjungan, user smoke test.
- Menetapkan role Spatie ke user smoke test.
- Memanggil `PermissionSeeder` + `RoleSeeder` **hanya jika** role yang dibutuhkan belum ada.

---

## 5. Yang Tidak Boleh Dilakukan Seeder

| Larangan | Semua seeder |
|----------|--------------|
| `delete`, `truncate`, atau wipe tabel | Tidak |
| `migrate:fresh` / `db:wipe` | Tidak |
| Reset sequence PostgreSQL | Tidak |
| Menghapus data pasien/kunjungan pilot nyata | Tidak |
| Menjalankan `DatabaseSeeder` penuh di VPS | Tidak (kecuali direview eksplisit) |
| Mengubah password user smoke test pada re-run | Tidak (hash hanya di create pertama) |

---

## 6. Verifikasi Record Smoke Test

Setelah `RmeSmokeTestSeeder` dijalankan, verifikasi:

### Akun login (password semua: `SmokeTestPilot!`)

| Role | Email |
|------|-------|
| Dokter | `dokter.smoke@pilot-test.local` |
| Perawat | `perawat.smoke@pilot-test.local` |
| Kasir | `kasir.smoke@pilot-test.local` |
| Owner | `owner.smoke@pilot-test.local` |

### Pasien

- **MRN:** `MRN-SMOKE-TEST-RME`
- **Nama:** PASIEN SMOKE TEST RME

### Kunjungan

| Visit number | Status | Kegunaan |
|--------------|--------|----------|
| `VIS-SMOKE-TEST-RME` | `in_progress` | Uji dokter/perawat (draft MR + odontogram) |
| `VIS-SMOKE-CASHIER-RME` | `cashier_pending` | Uji kasir (MR sudah final) |

Cabang aktif: **Klinik Gigi Daengtisia Pusat** (`MAIN`).

---

## 7. Cara Re-run dengan Aman

Semua seeder idempotent. Jika deploy ulang atau permission berubah:

```bash
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=RoleSeeder
php artisan db:seed --class=RmeSmokeTestSeeder
```

Re-run **tidak** seharusnya:

- Menduplikasi pasien dengan MRN yang sama.
- Menduplikasi kunjungan dengan `visit_number` yang sama.
- Menduplikasi user dengan email yang sama.

---

## 8. Jika Data Terlihat Duplikat

1. **Jangan** jalankan `migrate:fresh` atau `db:wipe`.
2. Periksa apakah duplikat benar-benar record baru atau hanya tampilan filter/search.
3. Cek natural key: `medical_record_number`, `visit_number`, `email`.
4. Jika duplikat nyata pada key yang seharusnya unik, hentikan operasi dan laporkan ke developer dengan format insiden (lihat checklist deploy).
5. Restore DB **hanya** jika duplikat/korupsi parah dan backup tersedia.

---

## 9. Jika Login Gagal

1. Pastikan seeder sudah dijalankan (`PermissionSeeder`, `RoleSeeder`, dan `RmeSmokeTestSeeder` jika pakai akun smoke).
2. Pastikan email benar (lihat bagian 6).
3. Password smoke test: `SmokeTestPilot!` (hanya untuk akun `@pilot-test.local`).
4. Cek user punya `branch_id` yang benar (cabang MAIN).
5. Cek role Spatie: `php artisan tinker` → `$user->getRoleNames()` (hanya di lingkungan aman).
6. Clear cache: `php artisan optimize:clear` lalu `config:cache`.
7. Periksa `storage/logs/laravel.log` untuk error autentikasi.

---

## 10. Jika Branch Context Fallback ke MAIN

`BranchContext` dapat fallback ke cabang `MAIN` jika user tidak punya `branch_id` yang valid.

**Tindakan:**

1. Assign `branch_id` user ke cabang pilot yang benar (via admin atau tinker di VPS).
2. Pastikan smoke test user dibuat oleh `RmeSmokeTestSeeder` dengan cabang MAIN.
3. Setelah perbaikan, logout dan login ulang.
4. Verifikasi cabang aktif di UI sesuai **Klinik Gigi Daengtisia Pusat**.

---

## 11. Perintah Terlarang

Dilarang di VPS/pilot:

```bash
php artisan migrate:fresh
php artisan migrate:fresh --seed
php artisan db:wipe
php artisan db:seed                    # tanpa --class= — menjalankan DatabaseSeeder penuh
```

Sebelum menjalankan perintah artisan lain yang menyentuh database, konfirmasi dengan checklist deploy: `docs/pilot/vps_pilot_deployment_checklist.md`.

---

## 12. Dasbor Owner — Filter Cabang & Drilldown KPI (Sprint 22.5–22.6)

Fitur **filter cabang Owner**, **Ringkasan Per Cabang**, dan **drilldown KPI read-only** **tidak memerlukan seeder baru**.

| Kebutuhan | Seeder |
|-----------|--------|
| Permission `view_owner_dashboard`, `view_branch_dashboard`, dll. | `PermissionSeeder` |
| Role Owner, Kasir, Perawat, hardening Doctor/Admin | `RoleSeeder` |
| Data/akun smoke test RME opsional | `RmeSmokeTestSeeder` (opt-in) |

- Jalankan `PermissionSeeder` + `RoleSeeder` setelah deploy Phase 22.5–22.6 — cukup untuk permission dashboard Owner.
- `RmeSmokeTestSeeder` tetap **opsional** — hanya jika tim butuh akun `owner.smoke@pilot-test.local` dan data kunjungan smoke untuk uji manual.
- Jika akun Owner pilot asli sudah ada, assign `view_owner_dashboard` via role/permission sesuai konvensi `RoleSeeder` (role **Owner**).
- Tidak perlu migrasi atau reset data untuk mengaktifkan filter cabang/drilldown.

Smoke test manual setelah deploy: `docs/pilot/owner_dashboard_manual_smoke_test_checklist.md`.

---

## 13. Sprint 22 Release Candidate & Go/No-Go

Sebelum dan sesudah menjalankan seeder di VPS pilot, baca:

| Dokumen | Tujuan |
|---------|--------|
| `docs/pilot/sprint_22_release_candidate_notes.md` | Target deploy RC, urutan perintah aman, perintah terlarang, kriteria GO/NO-GO, rollback |
| `docs/pilot/vps_pilot_go_no_go_checklist.md` | Checklist keputusan GO / GO dengan catatan / NO-GO + sign-off operator |

**Ingat:** `PermissionSeeder` + `RoleSeeder` wajib setelah deploy Sprint 22; `RmeSmokeTestSeeder` tetap opt-in. Jangan jalankan `php artisan db:seed` tanpa `--class=`.
