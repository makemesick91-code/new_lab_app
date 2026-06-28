# DaengtisiaMS — Branch Context Rules

## Tujuan
Menjelaskan resolusi cabang aktif, isolasi data, cabang RME-enabled, dan larangan bypass.

## Ringkasan
`BranchContext` adalah resolver cabang aktif tunggal. `branch_id` dari request user **tidak** dipercaya. Data operasional harus di-scope per cabang.

## Konteks DaengtisiaMS
Multi-cabang: MAIN + cabang operasional. Modul RME dan Inventory punya flag enable per cabang di `mst_branches`.

## File / Area Repo Terkait
- `app/Modules/Branch/Services/BranchContext.php`
- `app/Modules/Branch/Services/BranchService.php` — `rmeEnabledIds()`, dll.
- `app/Modules/Branch/Models/Branch.php`
- `database/migrations/2026_06_04_081546_create_mst_branches_table.php`
- `database/migrations/2026_06_15_100001_add_module_flags_to_mst_branches_table.php`
- `tests/Feature/BranchScope/`
- `docs/architecture_rules.md` (Branch Isolation)

## Aturan Utama

### API BranchContext
| Method | Penggunaan |
|---|---|
| `id(): ?int` | Cabang aktif user saat ini |
| `requireId(): int` | Wajib untuk write branch-owned — throw jika null |
| `branch(): ?Branch` | Model cabang |
| `forUser(User $user): ?int` | Resolusi per user |
| `rmeBranchId()` / `requireRmeBranchId()` | Fallback cabang RME-enabled |
| `inventoryBranchId()` | Fallback cabang inventory-enabled |

### Urutan resolusi (`forUser`)
1. Kolom `users.branch_id` — **hanya jika kolom ada** (`Schema::hasColumn` guard)
2. Relasi `user->branches()` — cabang aktif pertama
3. Default: MAIN aktif, else cabang aktif pertama

**Catatan:** `users.branch_id` **tidak ada** di migration saat ini — resolver defensive.

### RME multi-branch
- `BranchService::rmeEnabledIds()` — set cabang RME (MAIN excluded dari beberapa audit/filter)
- Pendaftaran pasien/kunjungan: cabang dipilih eksplisit di form (Sprint 23.8) — tidak hanya fallback context

### Inventory multi-branch
- `inventoryBranchId()` prefer MAIN jika `is_inventory_enabled`
- Semua query inventory: `where('branch_id', $branchId)`

### Larangan
```php
// SALAH
$branchId = $request->input('branch_id');
Product::findOrFail($id); // tanpa branch scope
```

### Cross-branch lookup (terbatas)
- `CrossBranchPatientLookupService` — lookup RM **lintas cabang** by `medical_record_number` saja (Sprint 57) — bukan bypass umum
- Owner/executive analytics — read-only agregat dengan permission khusus

## Workflow / Alur
Service branch-owned:
1. `$branchId = $this->branchContext->requireId();`
2. `$record = $this->repo->findInBranch($branchId, $id);`
3. Validasi relasi terkait sama `branch_id`
4. Persist dengan `branch_id` dari context, bukan request

## Struktur Teknis
**Tabel:** `mst_branches`
- `code` — dipakai komposisi RM (`DG-{code}-...`)
- `is_active`
- `is_rme_enabled`
- `is_inventory_enabled`

**Migration branch_id:** `add_branch_id_to_core_transaction_tables`

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan tambah `branch_id` dari `$request` tanpa validasi policy eksplisit cross-branch
- Jangan hapus filter branch di repository
- MAIN branch: jangan selectable sebagai ruangan klinik (`activeRoomsForBranch` exclude MAIN)

## Checklist Validasi
- [ ] Test branch isolation: cabang A tidak lihat/edit data cabang B
- [ ] `requireId()` dipanggil di service write
- [ ] Repository method pertama param `int $branchId`
- [ ] Policy assert same branch
- [ ] Seeder MAIN branch ada untuk dev

## Catatan untuk AI
Sprint Consistency Check wajib: Sprint 10 (BranchContext), Sprint 11 (enforcement), Sprint 12 (inventory branch).

Jika menambah fitur "lintas cabang", butuh permission eksplisit + spec — default tetap isolated.
