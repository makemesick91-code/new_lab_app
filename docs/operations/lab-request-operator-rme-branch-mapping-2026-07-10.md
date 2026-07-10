# Operational Repair Evidence — Lab Request Operator → Cabang RME Mapping (2026-07-10)

Status: **operational data repair — TANPA perubahan kode runtime, TANPA GO tag baru, TANPA deploy.**
Baseline tetap: tag `fix-lab-request-rme-branch-searchable-fields-go` @ commit
`4e60047d4be52fc06faed77c597f8a730443e973` (lokal dan VPS exact-match, worktree bersih).

## Masalah

Halaman **Buat Permintaan Lab** (`lab-workflow-requests.create`) hanya menerima Cabang RME aktif
via `BranchContext`. Seluruh 11 user di VPS pilot memiliki `users.branch_id = NULL`, sehingga
`BranchContext` jatuh ke fallback MAIN (id 4, non-RME) dan operator sah diblokir dengan pesan
"belum terhubung ke Cabang RME aktif". Ini murni masalah data/konfigurasi — bukan bug kode:
gate yang memblokir bekerja sesuai desain.

## Identitas lingkungan

| | Lokal | VPS pilot |
|---|---|---|
| Path | `~/Projects/new_lab_app` | `/var/www/asia-dental-lab-v2` |
| Env | `local` | `pilot` |
| DB (pgsql) | `asia_dental_lab` | `asia_dental_lab_pilot` |
| Users | **0** (dev DB kosong) | 11 |
| Branches | **0** | 4 (TKM1/LDK2/ATG3 RME aktif; MAIN non-RME) |
| Permission `create_lab_branch_requests` | tidak ada di tabel (seed lama) | ada, via role |

Kesimpulan: **DB lokal bukan production-like** — user ID 7 memang tidak ada di lokal; query lokal
kosong bukan anomali. ID user/branch TIDAK BOLEH disalin antar environment.

## Model canonical

- User: `App\Models\User` (`app/Models/User.php`)
- Branch: `App\Modules\Branch\Models\Branch` (`app/Modules/Branch/Models/Branch.php`) — BUKAN `App\Models\Branch`.
- Resolver cabang: `App\Modules\Branch\Services\BranchContext` (kolom `users.branch_id` → relasi → fallback MAIN).

## Audit effective permission VPS (`$user->can()`, bukan direct-only)

Pemegang efektif `create_lab_branch_requests`:

| ID | Nama | Role | Sumber permission | branch_id sebelum |
|---|---|---|---|---|
| 1 | Super Admin | Super Admin, Owner | `Gate::before` | NULL |
| 3 | Lab Admin | Super Admin | `Gate::before` | NULL |
| 7 | Yuni FO | Admin Klinik | role | NULL |
| 10 | Rahmi | Perawat | role | NULL |

Role-permission matrix VPS sudah benar (Phase 2 LAB-WORKFLOW-V2: Perawat/Admin Klinik/Admin Lab).
**Tidak ada permission/role repair yang diperlukan atau dilakukan.**

## Cabang RME VPS

| ID | Code | Nama | Aktif | RME |
|---|---|---|---|---|
| 1 | TKM1 | Cabang Telkomas | ya | ya |
| 2 | LDK2 | Cabang Landak | ya | ya |
| 3 | ATG3 | Cabang Antang | ya | ya |
| 4 | MAIN | Main Fallback Branch | ya | **tidak** |

## Evidence mapping per user

- **Yuni FO (7)** → **LDK2 (2)**. Bukti: (a) `trx_user_online_contexts` — pilihan online context
  miliknya sendiri = branch 2, role_context `admin_clinic`, last_seen 2026-07-09; (b) 2 kunjungan
  terakhir yang ia buat (`trx_clinic_visits.created_by=7`) 2026-06-29 & 2026-06-30 di branch 2
  (2 kunjungan lebih lama 23/28 Jun di branch 3). Bukti terbaru + pilihan eksplisit user konsisten LDK2.
- **Rahmi (10, Perawat)** → **TIDAK DIPETAKAN**. Nihil jejak: 0 kunjungan dibuat, 0 baris
  `sys_audit_logs`, tidak ada online context. Kandidat: TKM1/LDK2/ATG3 — **butuh konfirmasi owner**.
- **Super Admin (1) & Lab Admin (3)** → **sengaja TIDAK dipetakan**. Keduanya aktor lintas-cabang
  (kunjungan dibuat di 3 cabang; seluruh lab order legacy dibuat mereka). Pembuatan Lab Request adalah
  fungsi operator cabang; akun admin tetap penuh untuk sisi lab/manajemen. Mem-pin admin ke satu cabang
  akan mengubah default cabang mereka di seluruh modul (inventory, RME, dsb.) — ditolak tanpa keputusan
  owner. Jika owner ingin admin bisa membuat request atas nama cabang, itu butuh fitur branch-switch
  (code change tersendiri).

## Backup sebelum mutasi

`scripts/backup-vps.sh` (canonical ENT-12): `storage/app/backups/deploy/auto_backup_20260710-150150.sql`,
617.205 bytes, header pg_dump valid, `foundation:backup-verify` OK.

## Mutasi yang diterapkan

Satu mutasi, transaksional (`DB::transaction` + `lockForUpdate` + validasi branch aktif+RME):

| User | Before | After |
|---|---|---|
| 7 Yuni FO | `branch_id = NULL` | `branch_id = 2` (LDK2) |

Catatan: update `users.branch_id` tidak menghasilkan baris `sys_audit_logs` otomatis (tidak ada
observer untuk kolom ini) — dokumen ini adalah audit record manualnya.

## Verifikasi sesudah mapping (runtime VPS, service path riil)

- User 7: `BranchContext->branch()` → LDK2 (aktif, RME); `can('create_lab_branch_requests')` = true;
  `LabWorkflowRequestService::formOptionsForActiveBranch()` → branch LDK2, **8 pasien** (7 branch-2 +
  1 legacy NULL; 2 pasien ATG3 tereksklusi — isolasi cabang benar), **4 dokter** (pivot
  `mst_doctor_branches` kompatibel), **10 layanan lab** aktif.
- Negative check: user 1/3/10 tetap resolve MAIN → form blocked (`branch=null`) — tidak ada
  kebocoran MAIN/lintas cabang.
- `permission:cache-reset` dijalankan; `php8.3-fpm`/`nginx`/`daengtisiams-queue-worker` semuanya active;
  `queue:failed` kosong; 0 entri log Laravel tanggal 2026-07-10.
- Smoke HTTP: `/health/live` 200, `/health/ready` 200, `/login` 200; guest
  `/lab/workflow-requests/create` dan index → 302 login (terlindungi).

## Tests (lokal, commit yang sama dengan VPS)

- `tests/Feature/LabWorkflow` — 111 passed / 520 assertions.
- `--filter=LabWorkflowRequestRmeBranchSearchableFields` — 21 passed / 89.
- `--filter=Permission` — 252 passed / 959.
- `--filter=BranchContext` — 14 passed / 36.

## Rollback plan

Restore `auto_backup_20260710-150150.sql` via `scripts/restore_postgres.sh` (langkah eksplisit,
tidak otomatis), atau cukup set kembali `users.branch_id = NULL` untuk user 7 dengan transaksi yang sama
(mutasi tunggal, mudah di-revert).

## Keputusan owner yang tersisa

1. **Rahmi (10)**: pilih cabang RME (TKM1/LDK2/ATG3). Setelah dikonfirmasi, jalankan mutasi
   transaksional yang sama (pola di atas) — jangan update mentah tanpa lock/validasi.
2. **Super Admin (1) / Lab Admin (3)**: konfirmasi apakah akun admin memang tidak perlu membuat
   Lab Request (status quo, direkomendasikan), atau perlu fitur branch-switch (sprint code tersendiri).
