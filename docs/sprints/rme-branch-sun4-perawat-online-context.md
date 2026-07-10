# RME-BRANCH-SUN4 — Add SUN4 Cabang Sunu & Enable Perawat RME Branch Selection After Login

- **Branch:** `feature/rme-branch-sun4-perawat-online-context`
- **Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- **Baseline runtime:** `fix-lab-request-rme-branch-searchable-fields-go` @ `4e60047` (+ docs-only `de6f187`)
- **GO tag:** `rme-branch-sun4-perawat-online-context-go`

## Ringkasan

1. **SUN4 — Cabang Sunu** ditambahkan ke registry cabang RME canonical secara
   idempotent (aktif + RME-enabled, bukan MAIN, tidak inventory-enabled otomatis).
2. **Role Perawat memilih Cabang RME setelah login** memakai mekanisme online
   context canonical yang SAMA dengan Admin Klinik (Sprint 66) — bukan engine baru.
3. **BranchContext kini memprioritaskan online context aktif** (Doctor / Admin
   Klinik / Perawat) di atas `users.branch_id` statis, sehingga Lab Request, RME,
   pasien, antrian, dan fitur branch-scoped lain mengikuti cabang online terpilih.

## Registry kode cabang canonical

| Kode | Nama            |
|------|-----------------|
| TKM1 | Cabang Telkomas |
| LDK2 | Cabang Landak   |
| ATG3 | Cabang Antang   |
| SUN4 | Cabang Sunu     |

## Fase 1 — SUN4 (database/seeder)

- **Tanpa migration** — `mst_branches` sudah punya `code` UNIQUE + module flags.
- Seeder baru `Database\Seeders\RmeBranchSeeder` (`CANONICAL_RME_BRANCHES`):
  - `firstOrNew` by `code` **termasuk soft-deleted** (kode unik, tidak pernah duplikat);
  - row baru: aktif + `is_rme_enabled=true` + **`is_inventory_enabled=false`**
    (partisipasi inventory = keputusan bisnis eksplisit via Master Data Cabang);
  - row existing (TKM1/LDK2/ATG3) **tidak pernah** di-rename/di-reconfigure —
    master data produksi menang;
  - khusus SUN4: restore bila soft-deleted + re-assert aktif/RME (konvergen);
  - MAIN tidak pernah disentuh.
- Terdaftar di `DatabaseSeeder` (fresh env); deploy VPS menjalankan
  `php artisan db:seed --class=RmeBranchSeeder --force` secara eksplisit
  (presisi PermissionSeeder ENT-7 — deploy runner tidak menjalankan seed global).
- Rollback: tidak menghapus SUN4 (data cabang yang sudah dipakai transaksi tidak
  boleh dihapus; nonaktifkan via Master Data Cabang bila perlu).

## Fase 2 — Perawat online context

Reuse penuh module `App\Modules\RmeOnlineContext` (Sprint 66):

- `UserOnlineContext::ROLE_PERAWAT = 'perawat'` (kolom `role_context` string —
  tanpa migration).
- `UserOnlineContextService`:
  - `requiresPerawatContext()` / `isPerawatActive()` / `startPerawatSession()` —
    mirror Admin Klinik (branch-only, tanpa ruangan; `assertRmeBranch` menolak
    MAIN/non-RME/inaktif server-side);
  - `resolveActiveBranchForAdmin()` kini mencakup konteks branch-only Perawat —
    registrasi kunjungan Perawat diperlakukan identik dengan Admin Klinik
    (branch dipaksa ke context, `doctor_id` auto dari ruangan);
  - `activeContextBranchId()` (baru) — sumber prioritas BranchContext; fail
    closed (harus online + role match + cabang aktif RME).
- Route baru: `POST rme/online-context/perawat` → `rme.online-context.perawat`
  (`StartPerawatOnlineContextRequest` — rules identik Admin Klinik; controller
  `abort_unless(requiresPerawatContext)`).
- `EnsureRmeOnlineContext` menggating Perawat; halaman selector
  (`rme.online-context.select`) menampilkan form cabang yang sama (label generik
  "Pilih cabang tempat Anda bertugas pada sesi ini."); login redirect + badge
  topbar + tombol Ganti/Offline sama dengan Admin Klinik; expiry 30 menit sama.

## BranchContext priority (baru)

```
1. Active RME online context (Doctor/Admin Klinik/Perawat) — fail closed
2. users.branch_id (Schema::hasColumn guarded)
3. users.branches() relation
4. MAIN aktif → first active branch
```

- Guarded `Schema::hasTable('trx_user_online_contexts')`.
- MAIN tidak pernah bisa jadi context (start selalu `assertRmeBranch`).
- Context stale/cabang dinonaktifkan → resolusi jatuh ke fallback statis dan
  middleware meminta pemilihan ulang.

## Keamanan

- `branch_id` divalidasi scoped (`exists` + aktif + RME) **dan** di-re-assert di
  service; MAIN/inaktif/non-RME → 422.
- Endpoint hanya beroperasi pada `$request->user()` — `user_id` crafted diabaikan
  (unique `user_id` di `trx_user_online_contexts`).
- Role lain (Kasir, Admin Klinik) → 403 di endpoint perawat, dan sebaliknya.
- Branch selection TIDAK menambah permission apa pun (dibuktikan test kasir 403).
- Lab Request: `branch_id` request tetap DITIMPA BranchContext; katalog
  pasien/dokter tetap branch-scoped; crafted id lintas cabang → 422.

## Tests

- Baru: `tests/Feature/MasterData/RmeBranchSeederTest.php` (6),
  `tests/Feature/RME/PerawatOnlineContextTest.php` (28; termasuk integrasi Lab
  Request V2 SUN4 + registrasi kunjungan + isolasi + keamanan).
- Helper baru: `rmeMakePerawatActive()` di `tests/Pest.php`.
- Diperbarui (Perawat kini butuh context di HTTP tests): `SidebarPermissionVisibilityTest`,
  `PilotRouteAuthorizationTest`, `RoomAssignmentWorklistTest` — diberi context
  nyata via helper (presisi Sprint 66, bukan bypass middleware).
- Regression green: RME+LabWorkflow 1142; ClinicVisit|Patient|Branch 997
  (2 skipped); Inventory+Ui+Navigation+Auth 1619.

## Deploy note

1. `scripts/deploy-vps-runner.sh` (backup otomatis; tanpa migration baru).
2. Post-deploy: `php artisan db:seed --class=RmeBranchSeeder --force` (idempotent).
3. Verifikasi: SUN4 tepat satu, aktif+RME; Perawat diarahkan ke selector; MAIN
   tidak selectable.
