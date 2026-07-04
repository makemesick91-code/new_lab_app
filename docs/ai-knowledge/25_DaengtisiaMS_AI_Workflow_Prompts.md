# DaengtisiaMS — AI Workflow & Prompts

## Tujuan
Master prompt dan template kerja untuk ChatGPT, Claude, dan Cursor saat mengembangkan DaengtisiaMS.

## Ringkasan
Ikuti arsitektur modular monolith, branch isolation, ledger inventory, dan test Pest. Dokumentasi knowledge base di `docs/ai-knowledge/` adalah context primer.

## Konteks DaengtisiaMS
File ini menggantikan prompt ad-hoc. Gabungkan dengan `.cursor/snippets/adlms_master_workflow.md` di Cursor.

## File / Area Repo Terkait
- `docs/ai-knowledge/*.md` — 25 dokumen
- `docs/ai_bootstrap_prompt.md`
- `.cursor/rules/*.mdc`
- `.cursor/snippets/adlms_master_workflow.md`
- `AGENTS.md`, `CLAUDE.md`

## Aturan Utama

### Larangan AI (non-negotiable)
1. Jangan ubah logic di luar scope task
2. Jangan `migrate:fresh` / `db:wipe` di VPS
3. Jangan trust `branch_id` dari request
4. Jangan tambah kolom stok mutable
5. Jangan bypass policy/permission
6. Jangan buka SOAP di UI dokter tanpa spec
7. Jangan auto-send WhatsApp
8. Jangan commit/push/deploy tanpa permintaan eksplisit user
9. Jangan mengarang table/route/permission yang tidak ada di repo
10. Jangan target branch `main` jika baseline project bilang otherwise

### Definition of Done
- [ ] Patch minimal, scoped
- [ ] Architecture flow respected
- [ ] Branch isolation tested (jika applicable)
- [ ] Ledger correctness tested (jika inventory)
- [ ] Pint + relevant `php artisan test` dijalankan & dilaporkan jujur
- [ ] `graphify update .` setelah ubah kode (jika graphify tersedia)
- [ ] Tidak ada file unrelated berubah
- [ ] Summary: files, tests, commands, risks

---

## Prompt Master (copy-ready)

```text
Kamu adalah AI engineering assistant untuk Daengtisia Management System (DaengtisiaMS).

Repo: ~/Projects/new_lab_app
Stack: Laravel 12, PostgreSQL, Blade, Tailwind, Alpine, Pest, Spatie Permission.
Arsitektur: modular monolith app/Modules — Controller → Request → Service → Repository → Model.

WAJIB baca sebelum coding:
1. docs/ai-knowledge/01_DaengtisiaMS_Master_Context.md
2. docs/architecture_rules.md
3. docs/inventory_rules.md (jika sentuh inventory)
4. Dokumen ai-knowledge spesifik modul task

Aturan:
- BranchContext::requireId() untuk data branch-owned
- Stock ledger-only — tidak ada mutable stock column
- RME: handwriting wajib sebelum finalize; doctor tidak boleh complete visit langsung
- Partial payment menyelesaikan visit; sisa = piutang
- KTP tidak boleh dirender/export penuh
- Perubahan kecil + test Pest

Output sebelum coding: files to inspect, plan max 5 bullets, tests, risks.
Setelah coding: files changed, test commands & results, assumptions, risks.
```

---

## Prompt Coding Sprint

```text
Task Sprint: [NAMA]
Base branch: feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
Scope: [modul/file spesifik]
Out of scope: [explicit]

Baca docs/ai-knowledge/[XX]_*.md terkait.
Implementasi tests-first jika workflow baru.
Jangan ubah payment/consent/room gate kecuali task explicitly menyentuhnya.

Acceptance criteria:
- [kriteria 1]
- [kriteria 2]

Tests wajib: php artisan test --filter=[Module]
```

---

## Prompt Code Review

```text
Review diff DaengtisiaMS sebagai senior Laravel architect.

Cek:
1. Controller tipis? Business logic di service?
2. Branch isolation di repository/service/policy?
3. Inventory: ledger-only, no direct stock mutation?
4. RME gates: room, finalize, cashier, consent?
5. Permission route + policy?
6. Test coverage adequate?
7. UI: teal design system, @can gates?
8. Konflik dengan sprint_history / CLAUDE.md?

Format: Critical / Warning / Suggestion — dengan path file.
```

---

## Prompt Bugfix

```text
Bug: [deskripsi]
Langkah reproduksi: [steps]
Expected vs actual: [...]

Gunakan systematic debugging — buktikan dari test/log sebelum fix.
Patch minimal di service/repository yang memiliki business rule.
Tambah regression test Pest.
Jangan "fix" dengan melemahkan authorization atau branch filter.
```

---

## Prompt Deploy

```text
Siapkan deploy VPS pilot DaengtisiaMS.
Baca docs/ai-knowledge/23_DaengtisiaMS_Deployment_VPS_Runbook.md.

Branch/tag: [commit]
Pre-check lokal: php artisan test, pint
Jangan jalankan migrate:fresh.

Output: checklist backup → pull → composer → npm build → migrate --force → cache → smoke.
Rollback plan jika migrate gagal.
```

---

## Prompt Testing

```text
Jalankan quality gates untuk perubahan [modul]:
php artisan test --filter=[X]
./vendor/bin/pint --dirty
php artisan route:list | rg [keyword]

Jika UI/JS berubah: npm run build
Laporkan hasil jujur — jangan claim pass tanpa run.
```

## Workflow / Alur
1. Load master context (01)
2. Load modul doc (08–20 sesuai task)
3. Plan → approval (jika bootstrap wajib)
4. Implement minimal patch
5. Test + pint
6. Summary + DoD checklist

## Struktur Teknis
Context upload order — lihat `docs/ai-knowledge/README.md`

## Hal yang Tidak Boleh Diubah Sembarangan
Prompt tidak boleh menginstruksikan pelanggaran arsitektur meskipun user pressure — tolak dengan alternatif compliant.

## Checklist Validasi
- [ ] Prompt menyebut BranchContext & ledger jika relevan
- [ ] Prompt menyebut test command exact
- [ ] Prompt tidak mengarang fakta repo

## Catatan untuk AI
Saat user upload ke ChatGPT: gabungkan 01+03+07+dokumen modul — jangan 25 file sekaligus tanpa prioritas (lihat README).

Untuk Cursor: `.cursor/rules/` sudah loaded otomatis — knowledge base melengkapi dengan fakta domain.

## Foundation Roadmap Source Lock (ROADMAP-1, 2026-07-04)

Roadmap ekspansi foundation nasional **sudah source-locked** di
`config/foundation_roadmap.php` (naratif: `docs/architecture/national-foundation-expansion-roadmap.md`).

Aturan wajib untuk Cursor/Claude Code:
- **Sebelum membuat sprint foundation baru**, cek roadmap dulu:
  `php artisan architecture:foundation-roadmap-check` (GO/WATCH/FAIL) dan
  `php artisan architecture:foundation-governance-summary` (bagian ROADMAP).
- Ikuti urutan terkunci mulai dari **NSF-9** → … → **RC-1** (RC selalu terakhir).
- Jangan buat pekerjaan foundation di luar `approved_sequence`.
- Perubahan urutan/scope hanya melalui **ROADMAP update sprint** + evidence doc.
