# DaengtisiaMS Master Workflow (Cursor Snippet)

Paste snippet ini di awal setiap task implementasi besar di Cursor.

Kamu bekerja pada **Daengtisia Management System (DaengtisiaMS)** — sebelumnya ADLMS. Laravel 12 modular monolith untuk Klinik Gigi Daengtisia multi-cabang. Repo: `~/Projects/new_lab_app`. Perlakukan sebagai production-adjacent: patch kecil, ter-scope, ditest.

---

## Knowledge base

Baca sesuai task (urutan minimum):
1. `docs/ai-knowledge/01_DaengtisiaMS_Master_Context.md`
2. `docs/ai-knowledge/03_DaengtisiaMS_Tech_Stack_Architecture.md`
3. `docs/ai-knowledge/07_DaengtisiaMS_Branch_Context_Rules.md`
4. Dokumen modul di `docs/ai-knowledge/` (08–20) sesuai domain
5. `docs/architecture_rules.md`, `docs/inventory_rules.md` (jika inventory)
6. `docs/sprint_history.md`, `CLAUDE.md` untuk kontrak sprint

Index lengkap: `docs/ai-knowledge/README.md`

---

## Aturan kerja sprint

- Base branch stabil: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` — **jangan target `main`** kecuali diinstruksikan
- Scope sprint eksplisit — no scope creep ke sprint future
- Hotfix/spec terbaru mengalahkan dokumen lama (contoh: partial payment completes visit)
- Jangan reopen SOAP di UI dokter; handwriting RM adalah input klinis primer
- Dokumentasi sprint baru → update `docs/ai-knowledge/24_*` jika keputusan permanen

---

## Aturan sebelum coding

1. `git status --short` + `git diff --stat` pada file relevan
2. Identifikasi modul: `app/Modules/<Module>/`
3. Cek route: `php artisan route:list | rg keyword`
4. Cek policy, permission, migration, test existing
5. Rencana max 5 bullet + file list + test plan + risk
6. **Tunggu approval** jika workflow bootstrap (`docs/ai_bootstrap_prompt.md`) aktif

---

## Aturan membaca konteks

- Prioritas: **kode > migration > test > docs/ai-knowledge > docs lama**
- `docs/database_schema.md` V1 tidak lengkap untuk RME/inventory modern
- Gunakan `graphify query "..."` untuk navigasi arsitektur
- Jangan scan vendor, node_modules, storage, graphify-out penuh
- Context-mode: `ctx_execute` untuk output terminal besar

---

## Aturan tidak merusak ledger inventory

```text
STOCK = SUM(quantity_in) - SUM(quantity_out)
```

- **Jangan** tambah kolom `current_stock`, `qty_on_hand`, dll.
- **Jangan** update stok langsung di model produk
- Semua perubahan stok via `trx_inventory_movements`
- Types: OPENING, PURCHASE, ADJUSTMENT_IN/OUT, TRANSFER_IN/OUT
- Opname: ledger post hanya saat finalize
- Transfer: ship/receive via `StockTransferService` — bukan adjustment manual
- Test ledger wajib untuk perubahan inventory

---

## Aturan branch isolation

- Resolver: `app/Modules/Branch/Services/BranchContext.php`
- `requireId()` di setiap service write branch-owned
- **Jangan** percaya `$request->input('branch_id')`
- Repository: param pertama `int $branchId`, `findInBranch()`
- Cross-branch hanya dengan permission eksplisit (Owner analytics, RM lookup Sprint 57)
- Test branch isolation wajib

---

## Aturan RME / consent / payment

Pipeline: Pendaftaran → Antrian → Ruangan → RM + Odontogram → `cashier_pending` → Kasir → `completed`

- Middleware `visit.room` sebelum RM/Odontogram (pre-exam)
- Handwriting wajib sebelum finalize RM
- Doctor **tidak** boleh `in_progress` → `completed` langsung
- Visit `completed` via `RmePaymentService` setelah payment (PAID atau PARTIAL)
- Consent gate pada payment
- Piutang carry-over visit baru: opt-in, server-side IDOR protection
- KTP: mask di UI/export — tidak render penuh
- Lab: `LabCaseCandidate` setelah RME PAID — bukan auto LabOrder

---

## Aturan testing

```bash
php artisan test --filter=<Module>
./vendor/bin/pint --dirty
```

- Pest feature tests: happy path, validation, auth, branch isolation
- Inventory: ledger correctness
- Tes berat full suite: Ubuntu terminal biasa (bukan IDE terminal)
- Jangan claim pass tanpa run
- Dusk smoke: `tests/Browser/Rme*` jika UI kritis

---

## Aturan PR

- PR kecil, reviewable — satu concern per PR jika memungkinkan
- Summary: files, tests, risks, manual checks
- Tidak commit otomatis kecuali user minta
- `gh pr create` dengan test plan checklist
- Quality gates hijau sebelum merge claim

---

## Aturan deploy

- Baca `docs/ai-knowledge/23_DaengtisiaMS_Deployment_VPS_Runbook.md`
- **Backup DB wajib** sebelum pull/migrate di VPS
- `php artisan migrate --force` only — **never** `migrate:fresh` / `db:wipe` di VPS
- Path contoh: `/var/www/asia-dental-lab-v2`
- `composer install`, `npm run build`, cache, storage permissions, php-fpm/nginx reload
- Smoke test pasca deploy
- Rollback plan documented

---

## Checklist final (sebelum claim done)

- [ ] Hanya file scope yang berubah
- [ ] Controller → Service → Repository dihormati
- [ ] BranchContext & policy OK
- [ ] Ledger tidak dilanggar (jika inventory)
- [ ] RME gates intact (jika RME)
- [ ] Test relevan dijalankan & dilaporkan jujur
- [ ] Pint clean
- [ ] `graphify update .` jika kode berubah
- [ ] Tidak ada KTP/PII leak di UI/export baru
- [ ] Summary: files, tests, commands, assumptions, risks

---

## Output format respons

Setelah task:
- Files changed
- Tests added/updated
- Commands run (dengan hasil jujur)
- Assumptions
- Risks / follow-up

Prompt templates lengkap: `docs/ai-knowledge/25_DaengtisiaMS_AI_Workflow_Prompts.md`
