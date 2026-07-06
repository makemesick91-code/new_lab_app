# ENT-7 — Developer Assistance Console

- Branch: `feature/ent-7-developer-assistance-console`
- Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- GO tag (planned): `ent-7-developer-assistance-console-go`
- Policy doc: `docs/architecture/developer-assistance-console-governance.md`

## Ringkasan

Sprint implementasi runtime: konsol diagnostik **read-only** untuk Super Admin
di `GET /dev-console` (route `developer-console.index`), digating permission
baru `view_developer_console`, setiap akses diaudit ke `sys_audit_logs`, dan
semua excerpt melewati masking PII/secret. **Tanpa migrasi** (tabel
`sys_audit_logs`, `failed_jobs`, `jobs` sudah ada), tanpa perubahan driver
runtime, tanpa vendor baru, tanpa perubahan alur RME/pembayaran/inventory.

## Panel Console

1. Kesehatan runtime — DB ping, driver cache/session/queue, job pending/gagal,
   object storage flag, environment/debug.
2. Status izin storage — path wajib-tulis STATELESS-1.
3. Disk & backup — kapasitas disk + file backup deploy terakhir.
4. Job gagal — ringkasan `failed_jobs` (ENT-5), excerpt exception dimasking.
5. Audit events — metadata `sys_audit_logs` (tanpa payload old/new values).
6. Slow query summary — layanan NSF-3 `pg_stat_statements` (query ternormalisasi).
7. Deploy evidence — daftar artefak `storage/release-evidence/latest` + keputusan
   governance terakhir.
8. Log aplikasi — tail dimasking, dibatasi baris & panjang.

## Komponen Baru

- `config/developer_console.php`
- `App\Support\DeveloperConsole\DeveloperConsoleService` (agregator read-only)
- `App\Support\DeveloperConsole\SensitiveValueMasker`
- `App\Http\Controllers\DeveloperConsoleController` (thin)
- `App\Services\Foundation\DeveloperConsoleGovernanceService` (ENT7-DC001..DC010)
- `php artisan foundation:developer-console-check` (`--json`, `--strict`)
- View `resources/views/dev-console/index.blade.php` + item sidebar
- Permission `view_developer_console` (PermissionSeeder; Super Admin via `*`)

## Integrasi Governance

- Section `developer_console_governance` di `architecture:foundation-governance-summary`
  (informasional; tidak mengubah combinedDecision blocking).
- ENT-5 `queue_retry_governance` dan ENT-6 `idempotency_outbox_governance`
  tetap utuh dan diverifikasi ulang oleh check ENT-7.
- Artefak `developer-console-check.json` wajib pada profil CI dan VPS;
  dihasilkan CI gates + `scripts/deploy-vps.sh`.
- Roadmap: ENT-7 `completed`; next recommended sprint → **ENT-8**.

## Catatan Deploy VPS

- Jalankan `php artisan db:seed --class=PermissionSeeder --force` sekali setelah
  deploy (idempotent, `firstOrCreate`) supaya permission `view_developer_console`
  terdaftar; Super Admin tetap lolos via `Gate::before` tanpa seeding.
- Tidak ada migrasi; tidak ada worker yang diaktifkan.

## Test

- `tests/Feature/Architecture/Ent7DeveloperAssistanceConsoleTest.php`
- `tests/Feature/Foundation/DeveloperConsoleGovernanceCommandTest.php`
- `tests/Feature/DeveloperConsole/DeveloperConsoleTest.php`
