# DaengtisiaMS — Enterprise Architecture Baseline Lock (ENT-1)

> **Registrasi kanonik (ENT-1, 2026-07-05):** Dokumen ini mengunci baseline arsitektur enterprise
> DaengtisiaMS sebagai governance yang durable dan dapat diaudit. Sprint ENT-1 — *Enterprise
> Architecture Baseline Lock* — melengkapi Bagian 2 (Architecture Freeze) pada
> `docs/architecture/enterprise-foundation-freeze-rules.md`. Semua sprint ENT-2..ENT-16 wajib
> konsisten dengan baseline ini. Dokumen ini bersifat governance/read-only — tidak ada perubahan
> perilaku runtime, skema, route, atau permission yang diperkenalkan oleh ENT-1.

## Status

- Sprint: **ENT-1 — Enterprise Architecture Baseline Lock**
- Kategori: `architecture` (governance, read-only)
- Pointer kanonik: `config/foundation_roadmap.php` → `rules.enterprise_architecture_baseline_doc`
- Validasi otomatis: `tests/Feature/Architecture/Ent1EnterpriseArchitectureBaselineLockTest.php`
  dan `php artisan foundation:roadmap-check --strict`
- Dokumen induk: `docs/architecture/enterprise-foundation-freeze-rules.md` (Bagian 2)

## 1. Mandatory Request Flow (LOCKED)

Semua fitur HTTP DaengtisiaMS wajib mengikuti alur berikut, tanpa pengecualian dan tanpa pola baru
sebelum Enterprise Foundation Closure GO:

```text
HTTP → Controller → FormRequest → Service → RepositoryInterface → Repository → Model
         ↓ authorize via Policy
```

## 2. Baseline Rules (ENT1-R001..ENT1-R014)

### ENT1-R001 — Mandatory flow
Setiap request flow wajib melewati rantai `HTTP → Controller → FormRequest → Service →
RepositoryInterface → Repository → Model`. Tidak boleh ada jalur pintas (controller → query
langsung, Blade → query, helper global yang menembus layer).

### ENT1-R002 — Controller thin rule
Controller hanya menangani request/response, authorization (Policy/Gate), dan delegasi ke Service.
Controller tidak boleh berisi business logic, query builder/Eloquent query kompleks, atau kalkulasi
domain.

### ENT1-R003 — Service business logic rule
Seluruh business logic wajib berada di Service class per modul (`app/Modules/*/Services` atau
`app/Services`). Service adalah satu-satunya tempat orkestrasi lintas-repository.

### ENT1-R004 — RepositoryInterface boundary rule
Akses data wajib melalui Repository yang terikat RepositoryInterface (binding di
`RepositoryServiceProvider`). Tidak boleh bypass RepositoryInterface dari Controller/Blade/command
untuk domain yang sudah memiliki repository.

### ENT1-R005 — BranchContext / branch isolation rule
Semua data branch-owned wajib difilter melalui `BranchContext` di layer service/repository.
Repository branch-owned menerima `int $branchId` sebagai parameter awal. `branch_id` dari request
TIDAK boleh dipercaya — selalu validasi terhadap `BranchContext`.

### ENT1-R006 — Policy/Gate/RBAC 3-layer rule
Authorization wajib 3 lapis: (1) route middleware/permission group, (2) Policy/Gate di
controller/service, (3) visibilitas UI via `@can`. Menyembunyikan tombol di sidebar/Blade saja
BUKAN authorization.

### ENT1-R007 — DB::transaction multi-write rule
Setiap write multi-table / multi-step wajib dibungkus `DB::transaction` (dengan `lockForUpdate`
untuk alur yang rawan race, mengikuti pola pembayaran/receivable yang sudah ada).

### ENT1-R008 — Additive migration rule
Migration wajib additive (kolom nullable/default-safe, tabel baru, index baru). Dilarang
`migrate:fresh` / `db:wipe` pada VPS/production. Perubahan destruktif membutuhkan rencana
backup + rollback yang disetujui terlebih dahulu.

### ENT1-R009 — No mutable inventory stock rule
Dilarang membuat kolom stok inventory yang mutable. Stok selalu diturunkan dari ledger
(inventory ledger sebagai source of truth).

### ENT1-R010 — No PII leakage rule
KTP/NIK dan PII sensitif tidak boleh dirender penuh, diekspor penuh, di-log, atau dimasukkan ke
summary/report. Gunakan masking yang sudah ada. Dokumen scan dan catatan medis mentah tidak boleh
bocor ke dashboard/evidence.

### ENT1-R011 — No React/Vue rule
Stack frontend terkunci: Blade + Alpine.js + Tailwind (TailAdmin). Dilarang memperkenalkan
React/Vue atau SPA framework lain selama Enterprise Foundation Lock.

### ENT1-R012 — No logic in Blade rule
Blade hanya untuk presentasi. Dilarang menaruh business logic, query, atau kalkulasi domain di
Blade view/component.

### ENT1-R013 — No deploy shortcut rule
Deploy wajib mengikuti checklist/runbook yang ada: backup DB sebelum pull/migrate,
`php artisan migrate --force` saja, rebuild cache eksplisit (`optimize:clear` + `config:cache` +
`route:cache` + `view:cache` + `event:cache`), reset permission `storage`/`bootstrap/cache`, dan
smoke check pasca-deploy. Dilarang deploy tanpa backup atau tanpa verifikasi GO tag.

### ENT1-R014 — No feature scope outside freeze areas
Selama Enterprise Foundation Lock, tidak boleh ada feature work di luar scope freeze
(lihat `docs/architecture/enterprise-foundation-freeze-rules.md` Bagian 3 dan 20). Tidak boleh
membuat pola arsitektur baru sebelum Enterprise Foundation Closure GO.

## 3. Enforcement

1. Pointer kanonik terdaftar di `config/foundation_roadmap.php` (`rules` array).
2. Test governance `Ent1EnterpriseArchitectureBaselineLockTest` memverifikasi dokumen ini ada,
   memuat istilah kunci baseline, bebas dari literal evidence terlarang, dan roadmap tidak stale.
3. `php artisan foundation:roadmap-check --strict` wajib GO.
4. Pelanggaran baseline pada sprint ENT-2..ENT-16 = NO-GO untuk sprint tersebut.

## 4. Relasi Dokumen

- `docs/architecture/enterprise-foundation-freeze-rules.md` — dokumen induk freeze (ENT-0).
- `config/foundation_roadmap.php` — roadmap kanonik + pointer baseline (ENT-1).
- `docs/architecture/database-performance-contract.md` — kontrak performa database (ENT-2, DBPERF-R001..DBPERF-R014).
- `docs/architecture/reporting-materialized-summary-contract.md` — kontrak reporting summary (ENT-3, RPTSUM-R001..RPTSUM-R016).
- `docs/architecture/redis-cache-enterprise-policy.md` — policy Redis/cache enterprise (ENT-4, CACHE-R001..CACHE-R018).
- `.cursor/rules/50-enterprise-architecture-baseline.mdc` — ringkasan rule untuk AI assistant.
