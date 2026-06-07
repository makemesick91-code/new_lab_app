# Sprint 16.6 — Inventory Audit Trail & Activity Log

**Status:** COMPLETED — `sprint-16.6-complete`  
**Baseline:** Sprint 16.5 Procurement Hardening complete  
**Last updated:** 2026-06-07  
**Completion doc:** `docs/sprint_16_6_inventory_audit_trail_completion.md`

## Tujuan

Menambahkan audit trail dan activity log untuk seluruh aktivitas penting Inventory dan Procurement tanpa mengubah ledger stock, workflow procurement, branch isolation, atau permission Sprint 16.5.

## Scope

Fokus pada pencatatan aktivitas:

- Purchase Request created/submitted/approved/rejected/cancelled
- Purchase Order created/submitted/approved/rejected/cancelled
- Goods Receipt created/completed/cancelled
- Stock Transfer created/submitted/approved/received/cancelled
- Stock Opname created/completed/cancelled
- Inventory Movement created
- Batch/Lot created atau received jika sudah tersedia

## Aturan Wajib

- Jangan ubah perhitungan stok.
- Jangan tambah mutable stock column.
- Jangan ubah workflow bisnis existing.
- Jangan lemahkan BranchContext.
- Jangan hardcode role.
- Gunakan user id, branch id, subject type, subject id, action, metadata.
- Activity log harus aman untuk production.
- Activity log tidak boleh memblokir transaksi utama jika logging gagal, kecuali ada pola existing yang mewajibkan rollback.

## Keputusan Arsitektur (Step 2 — Approved)

Inventory Activity Log menggunakan **tabel dedicated baru**:

- `inv_inventory_activity_logs`

**Bukan** reuse:

- `sys_audit_logs`
- `AuditLogService` existing (LabOrder module)

Alasan singkat:

- Domain Inventory/Procurement punya action vocabulary, filter, dan metadata berbeda dari Lab Order audit.
- Branch-scoped activity log membutuhkan `branch_id` eksplisit di setiap baris.
- Menghindari coupling ke polymorphic `entity_type`/`entity_id` Lab Order dan permission model yang berbeda.

---

## Correlation ID

Inventory Activity Log mendukung `correlation_id` nullable UUID.

Fungsi:

- Menghubungkan event yang berasal dari satu alur workflow.
- Memudahkan audit end-to-end procurement.
- Memudahkan investigasi selisih stok.
- Memudahkan tracking PR → PO → GR → Inventory Movement.
- Tidak wajib untuk semua log.
- Jika belum tersedia, boleh null.

Contoh:

PR Approved, PO Created, PO Approved, GR Completed, dan Inventory Movement Created dapat memiliki `correlation_id` yang sama sehingga seluruh rangkaian procurement dapat ditelusuri dari satu identifier.

---

## Struktur Tabel

**Table:** `inv_inventory_activity_logs`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | Auto increment |
| `branch_id` | bigint FK | Branch owner; wajib |
| `user_id` | bigint FK nullable | Actor; null jika system/job |
| `action` | string | Action vocabulary Inventory/Procurement |
| `subject_type` | string | Polymorphic model class |
| `subject_id` | bigint | Polymorphic model id |
| `correlation_id` | UUID nullable, indexed | Optional workflow chain identifier |
| `description` | text nullable | Human-readable summary |
| `metadata` | JSON nullable | Context payload (bukan correlation_id) |
| `ip_address` | string nullable | Request IP saat log dibuat |
| `user_agent` | text nullable | Request user agent |
| `created_at` | timestamp | Immutable; no `updated_at` |

Catatan:

- Tidak ada mutable stock column.
- Tidak ada `updated_at` — log append-only.
- `branch_id` selalu dari `BranchContext` atau parameter eksplisit di `logForBranch()`.

---

## Index

Index yang direncanakan:

- `branch_id`
- `user_id`
- `action`
- `subject_type`, `subject_id` (composite)
- `correlation_id`
- `created_at`

---

## Service Design

**Class:** `App\Modules\Inventory\Services\InventoryActivityLogService`

### Signature

```php
log(
    string $action,
    Model $subject,
    array $metadata = [],
    ?string $description = null,
    ?User $user = null,
    ?string $correlationId = null
): InventoryActivityLog

logForBranch(
    int $branchId,
    string $action,
    Model $subject,
    array $metadata = [],
    ?string $description = null,
    ?User $user = null,
    ?string $correlationId = null
): InventoryActivityLog
```

### Aturan `correlation_id`

- `correlation_id` nullable — tidak wajib di setiap pemanggilan.
- `correlation_id` **tidak boleh** berasal langsung dari request user tanpa validasi.
- Jika dipakai antar service, `correlation_id` harus **dibuat di service layer** (mis. UUID baru di awal workflow, lalu diteruskan ke service berikutnya).
- Gunakan UUID valid (RFC 4122).
- Jangan memaksa semua workflow punya `correlation_id` pada Sprint 16.6.
- Implementasi awal cukup **menyimpan jika parameter diberikan**; propagasi ke seluruh workflow procurement boleh incremental.

### Perilaku logging

- Resolve `branch_id` dari `BranchContext::requireId()` di `log()`; gunakan `$branchId` eksplisit di `logForBranch()`.
- Resolve `user_id` dari parameter `$user` atau `auth()->user()`.
- Capture `ip_address` dan `user_agent` dari request saat tersedia.
- Logging failure tidak boleh rollback transaksi bisnis utama (best-effort), kecuali pola module existing mewajibkan sebaliknya.

---

## Repository Design

**Interface:** `InventoryActivityLogRepositoryInterface`  
**Implementation:** `InventoryActivityLogRepository`

### Method utama

```php
create(array $data): InventoryActivityLog

paginate(int $branchId, array $filters, int $perPage = 25): LengthAwarePaginator
```

### Filter `paginate()`

| Filter | Keterangan |
|---|---|
| `user_id` | Filter by actor |
| `action` | Exact atau prefix match action |
| `subject_type` | Polymorphic class |
| `subject_id` | Polymorphic id |
| `correlation_id` | Filter by workflow chain UUID |
| `date_from` | `created_at >=` |
| `date_to` | `created_at <=` |

Aturan:

- Semua query **wajib** scoped `branch_id` pertama.
- `paginate()` harus mendukung filter `correlation_id` jika tersedia di `$filters`.
- Urutan default: `created_at DESC`, `id DESC`.

---

## Metadata Guideline

Simpan di `metadata` JSON:

- Status sebelum/sesudah (jika relevan)
- Document number terkait
- Quantity / location context
- Reference ids tambahan (PO id, GR id, movement id)
- Alasan reject/cancel

**Jangan** simpan `correlation_id` di `metadata` jika sudah ada kolom dedicated — gunakan kolom `correlation_id` saja.

---

## Target Teknis (Step 3+)

Buat sistem audit log Inventory yang reusable:

- Model `InventoryActivityLog`
- Migration `inv_inventory_activity_logs`
- Service `InventoryActivityLogService`
- Repository + interface
- Trait/helper jika diperlukan
- Controller/view untuk melihat log
- Policy permission
- Tests

> **PRE-STEP 3:** Belum ada migration atau source code. Dokumen ini hanya design revision.

---

## Permission

Gunakan permission existing jika memungkinkan:

- `view_inventory`
- `manage_inventory`
- `view_inventory_analytics`

Jika perlu permission baru:

- `view_inventory_activity_log`

Permission baru harus ditambahkan ke PermissionSeeder dan Role Management grouping.

---

## Risiko

| Risiko | Mitigasi |
|---|---|
| Logging gagal memblokir transaksi utama | Best-effort logging; wrap dalam try/catch jika perlu |
| Cross-branch log leakage | Wajib `branch_id` scope di repository; policy + BranchContext |
| Metadata terlalu besar / sensitif | Guideline metadata; jangan simpan secret/password |
| `correlation_id` berasal dari input user tidak tervalidasi | Hanya terima UUID valid dari service layer; jangan trust request field |
| `correlation_id` tidak konsisten antar workflow | Dokumentasikan pola propagasi per workflow; incremental adoption OK di 16.6 |
| `correlation_id` duplikat | **Bukan masalah** — dipakai untuk grouping, bukan unique constraint |

---

## Output Akhir

- Audit log tersimpan di `inv_inventory_activity_logs`
- Log bisa dilihat dari halaman Inventory Activity Log
- Log bisa difilter berdasarkan branch, user, action, subject, tanggal, **correlation_id**
- Test lulus

---

## Quality Gates (Step 3+)

```bash
php artisan test
./vendor/bin/pint
php artisan route:list
```
