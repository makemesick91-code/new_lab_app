---
kb_status: current
kb_verified_against: 53d76643
kb_updated: 2026-10-26
---

# DaengtisiaMS — Indeks Knowledge Base Developer & AI

Titik masuk tunggal untuk dokumentasi teknis DaengtisiaMS. Folder `docs/` dapat
dibuka sebagai **vault Obsidian**, dan semua tautan di sini adalah tautan Markdown
relatif biasa, sehingga tetap berfungsi di GitHub, VS Code, dan saat diunggah ke ChatGPT.

> **Aturan nomor satu:** kode, migration, route, dan test adalah sumber kebenaran.
> Dokumen adalah peta, bukan wilayahnya. Cek status dokumen di
> [doc-status-registry](knowledge-base/doc-status-registry.md) sebelum mengandalkannya.

## Cara pakai dalam 60 detik

1. Cari modul yang relevan di [module-index](knowledge-base/module-index.md).
2. Buka **paling banyak 3–5** dokumen yang ditautkan di sana, ditambah rule durable-nya.
3. Cek statusnya di [doc-status-registry](knowledge-base/doc-status-registry.md). `SNAPSHOT`
   dan `STALE` hanya petunjuk, bukan fakta.
4. Verifikasi setiap klaim penting terhadap kode sebelum bertindak.
5. Jika dokumen ternyata keliru, **usulkan** perbaikannya. Jangan mengeditnya diam-diam
   (lihat [conventions](knowledge-base/conventions.md)).

### Urutan otoritas

| Prioritas | Sumber | Lokasi |
|---|---|---|
| 1 | Kode, migration, route, test, config | `app/`, `database/migrations/`, `routes/`, `tests/`, `config/` (di luar vault) |
| 2 | Rule durable & ADR yang `Accepted` | `.cursor/rules/*.mdc` (di luar vault), `docs/adr/` (daftar di bawah); aturan global kritis CG-01…CG-24 di `CLAUDE.md` |
| 3 | Ringkasan aturan per domain (dimuat otomatis sesuai `paths`) | `.claude/rules/*.md` (di luar vault); jika bertentangan, nomor 1 lalu 2 yang menang |
| 4 | Kontrak desain & runbook | `docs/architecture/`, `docs/runbooks/`, `docs/operations/` |
| 5 | Catatan penutupan sprint | `docs/sprints/`, termasuk [arsip catatan lama `CLAUDE.md`](sprints/claude-md-archive/README.md) (verbatim, `HISTORICAL`) |
| 5a | Ringkasan untuk AI (snapshot) | `docs/ai-knowledge/` |
| 6 | Dokumen era awal (Sprint 3–64) | berkas `docs/*.md` di root folder docs |

Jika dua sumber bertentangan, sumber dengan prioritas lebih tinggi menang. Di antara catatan
sprint (termasuk arsip `CLAUDE.md`), bagian yang lebih baru dan **secara eksplisit** menyatakan
`SUPERSEDED` mengalahkan bagian yang lebih lama.

## Dokumen knowledge base

| Dokumen | Isi |
|---|---|
| [module-index](knowledge-base/module-index.md) | 41 modul `app/Modules` → rule, ADR, desain, runbook, dan sprint terkait |
| [doc-status-registry](knowledge-base/doc-status-registry.md) | Status kepercayaan dokumen, drift yang terukur, dan duplikasi yang diketahui |
| [conventions](knowledge-base/conventions.md) | Lokasi dokumen baru, gaya tautan, larangan, dan protokol usulan pembaruan |
| [claude-code-workflow](knowledge-base/claude-code-workflow.md) | Cara Claude Code membaca knowledge base tanpa memuat semuanya |
| [chatgpt-workflow](knowledge-base/chatgpt-workflow.md) | Cara menyediakan dokumen yang sama untuk ChatGPT |
| [obsidian-setup](knowledge-base/obsidian-setup.md) | Instalasi Obsidian, membuka vault, dan pengaturan wajib |
| [release-history](knowledge-base/release-history.md) | Riwayat rilis yang diturunkan dari GO tag git |

## Peta topik

### Arsitektur sistem

- Baseline yang berlaku: [enterprise-architecture-baseline-lock](architecture/enterprise-architecture-baseline-lock.md),
  [enterprise-foundation-freeze-rules](architecture/enterprise-foundation-freeze-rules.md),
  [foundation-first-sprint-lock-governance](architecture/foundation-first-sprint-lock-governance.md).
- Model domain: [canonical-data-dictionary](architecture/canonical-data-dictionary.md),
  [canonical-entity-workflow-inventory](architecture/canonical-entity-workflow-inventory.md),
  [ontology-relationship-map](architecture/ontology-relationship-map.md).
- Aturan proyek lama (2026-07-05): [architecture_rules](architecture_rules.md), [project_rules](project_rules.md).
- Ringkasan & dokumen awal (cek status): [03 Tech Stack](ai-knowledge/03_DaengtisiaMS_Tech_Stack_Architecture.md),
  [system_architecture](system_architecture.md) (`STALE`, 2026-06-03).

### Database

- Sumber kebenaran: `database/migrations/` (211 migration per 2026-10-25).
- Kontrak: [database-performance-contract](architecture/database-performance-contract.md),
  [database-scale-governance-rules](architecture/database-scale-governance-rules.md),
  [dq-1-acid-constraint-data-quality-audit](architecture/dq-1-acid-constraint-data-quality-audit.md).
- `STALE` (93 dari 163 tabel tidak disebut): [database_schema](database_schema.md), [erd](erd.md),
  [04 Database Schema](ai-knowledge/04_DaengtisiaMS_Database_Schema.md).

### Route, RBAC, dan konteks cabang

- Sumber kebenaran: `routes/web.php`, `routes/device_api.php`, `routes/auth.php`,
  `database/seeders/PermissionSeeder.php`, `database/seeders/RoleSeeder.php`.
- Ringkasan (snapshot 2026-06-28): [05 Routes](ai-knowledge/05_DaengtisiaMS_Routes_Map.md),
  [06 RBAC](ai-knowledge/06_DaengtisiaMS_RBAC_Permissions.md),
  [07 Branch Context](ai-knowledge/07_DaengtisiaMS_Branch_Context_Rules.md).

### Dokumentasi modul

- Mulai dari [module-index](knowledge-base/module-index.md). Indeks ini dikelompokkan per domain
  dan menandai **10 modul tanpa dokumentasi khusus**.
- Ringkasan per domain: [README ai-knowledge](ai-knowledge/README.md) (25 dokumen; sebagian besar snapshot 2026-06-28).

### Architecture Decision Records (ADR)

| ADR | Topik |
|---|---|
| [0001](adr/0001-satusehat-integration-readiness.md) – [0007](adr/0007-satusehat-4d-multi-branch-readiness-scale-up.md) | SATUSEHAT (readiness, sandbox, dental, data quality, diagnosis, pilot, multi-branch) |
| [0008](adr/0008-legacy-rme-pdf-archive-foundation.md) | Arsip PDF RME legacy |
| [0009](adr/0009-android-production-signing-distribution-and-device-management.md) | Signing & distribusi Android (sebagian **superseded** oleh 0010) |
| [0010](adr/0010-android-direct-apk-signing-and-distribution.md) | Distribusi APK langsung dengan signing mandiri |
| [0011](adr/0011-doctor-device-authorization-and-app-only-login.md) | Otorisasi device dokter & login khusus aplikasi |
| [0012](adr/0012-rme-postpayment-invoice-correction.md) | Koreksi tagihan RME setelah pembayaran |

Register keputusan owner (bukan format ADR):
[rme-billing-correction-owner-decisions](decisions/rme-billing-correction-owner-decisions.md),
[rme-postpayment-correction-owner-decision-proposals](decisions/rme-postpayment-correction-owner-decision-proposals.md).
Nomor ADR berikutnya yang tersedia: **0013**.

### Spesifikasi fitur dan catatan sprint

- `docs/sprints/` berisi 311 dokumen. Prefiks nama berkas menunjukkan jenisnya: `feature-` dan
  `revision-` (49), `sprint-` (37), `uix-` (22), `legacy-` (25), `doctor-` (17),
  `satusehat-` (7), `ent-` (12), `lab-` (11).
- Era awal: 156 berkas `docs/sprint_*.md` di root folder docs (Sprint 3–64), misalnya
  [sprint_20_final_closure_report](sprint_20_final_closure_report.md).
- Templat: [sprint-runtime-template](engineering/sprint-runtime-template.md),
  [foundation-sprint-template](engineering/foundation-sprint-template.md),
  [hotfix-runtime-template](engineering/hotfix-runtime-template.md).

### Dokumentasi bugfix

- 36 dokumen `docs/sprints/` berprefiks `bugfix-`, `fix-`, atau `hotfix-`, misalnya
  [bugfix-mass-upload-request-entity-too-large-1](sprints/bugfix-mass-upload-request-entity-too-large-1.md).
- Era awal: 5 berkas `docs/hotfix_*.md`, misalnya
  [hotfix_inventory_unified_branch_master](hotfix_inventory_unified_branch_master.md).

### Infrastruktur, deployment, dan operasi

- Deploy & rollback: [vps-deploy-rollback-runbook](runbooks/vps-deploy-rollback-runbook.md),
  [vps-deploy-evidence-timeout-recovery-runbook](runbooks/vps-deploy-evidence-timeout-recovery-runbook.md),
  [release-evidence-smoke-runbook](runbooks/release-evidence-smoke-runbook.md),
  [enterprise-operations-runbook](runbooks/enterprise-operations-runbook.md).
- Keamanan runtime: [dedicated-runtime-identity-runbook](runbooks/dedicated-runtime-identity-runbook.md),
  [production-secret-file-permissions-runbook](runbooks/production-secret-file-permissions-runbook.md),
  [security-pii-compliance-hardening-governance](architecture/security-pii-compliance-hardening-governance.md).
- Status remediasi keamanan pasca-audit (snapshot 2026-10-27, dengan pembaruan status merge dan rilis
  produksi `755c6bce` 2026-10-29):
  [security-remediation-status-2026-10-27](operations/security-remediation-status-2026-10-27.md);
  kesiapan rotasi application key:
  [g7b-production-readiness-report-2026-10-29](operations/g7b-production-readiness-report-2026-10-29.md).
- Rilis produksi F-4b/F-4c `fb3726c5` (2026-10-30) dan kesiapan final G7-B:
  [F4BC-PRODUCTION-RELEASE-REPORT](operations/release-cicd-phase2-2026-10-29/F4BC-PRODUCTION-RELEASE-REPORT.md),
  [G7B-FINAL-READINESS-REPORT](operations/release-cicd-phase2-2026-10-29/G7B-FINAL-READINESS-REPORT.md),
  [G7B-ESCROW-TOOLS](operations/release-cicd-phase2-2026-10-29/G7B-ESCROW-TOOLS.md).
- Penutupan gate keamanan G7-B (2026-10-30; rotasi produksi tetap NO-GO, custodian kedua BLOCKED):
  [G7B-SECURITY-GATE-CLOSURE](operations/security-ci-2026-10-30/G7B-SECURITY-GATE-CLOSURE.md),
  [G7B-ENV-COPY-INVENTORY](operations/security-ci-2026-10-30/G7B-ENV-COPY-INVENTORY.md),
  [G7B-SECURITY-GATE-TOOLS](operations/security-ci-2026-10-30/G7B-SECURITY-GATE-TOOLS.md).
- Full Suite otoritatif terkendali (2026-10-30, satu run atas izin owner, PASS 11.953 lulus / 0 gagal):
  [FULL-SUITE-CONTROLLED-AUTHORITATIVE-1](operations/security-ci-2026-10-30/FULL-SUITE-CONTROLLED-AUTHORITATIVE-1.md).
- CI-PERF-04 pengujian paralel terkendali di Critical Gate (2026-10-30; dibangun dan dievaluasi, 4 worker 2,50× lebih cepat, tetap OFF tanpa persetujuan aktivasi owner):
  [CI-PERF-04-CONTROLLED-PARALLEL-TESTING](operations/security-ci-2026-10-30/CI-PERF-04-CONTROLLED-PARALLEL-TESTING.md).
- CI/CD Fase 2 (2026-10-30; durasi workflow −60%, paralel diukur tetapi belum diaktifkan):
  [CICD-PHASE2-FINAL-SUMMARY](operations/release-cicd-phase2-2026-10-29/CICD-PHASE2-FINAL-SUMMARY.md),
  [CICD-PHASE2-BASELINE](operations/release-cicd-phase2-2026-10-29/CICD-PHASE2-BASELINE.md),
  [CICD-SLOW-TEST-PROFILING](operations/release-cicd-phase2-2026-10-29/CICD-SLOW-TEST-PROFILING.md),
  [CICD-EVIDENCE-ISOLATION-REPORT](operations/release-cicd-phase2-2026-10-29/CICD-EVIDENCE-ISOLATION-REPORT.md),
  [CICD-PARALLEL-TESTING-BENCHMARK](operations/release-cicd-phase2-2026-10-29/CICD-PARALLEL-TESTING-BENCHMARK.md).
- Backup & DR: [backup-dr-restore-rehearsal-runbook](runbooks/backup-dr-restore-rehearsal-runbook.md),
  [roll-5-backup-restore-drill-runbook](runbooks/roll-5-backup-restore-drill-runbook.md),
  [backup-disaster-recovery-automation-governance](architecture/backup-disaster-recovery-automation-governance.md).
- CI/CD: [ci-runtime-control-runbook](runbooks/ci-runtime-control-runbook.md),
  [self-hosted-runner](runbooks/self-hosted-runner.md),
  [cicd-enterprise-gate-governance](architecture/cicd-enterprise-gate-governance.md),
  [global-temporary-full-suite-policy](governance/global-temporary-full-suite-policy.md).
- Queue, cache, storage, observability:
  [queue-producer-consumer-contract](architecture/queue-producer-consumer-contract.md),
  [queue-worker-activation-runbook](runbooks/queue-worker-activation-runbook.md),
  [redis-cache-enterprise-policy](architecture/redis-cache-enterprise-policy.md),
  [storage-governance-rules](architecture/storage-governance-rules.md),
  [observability-console](architecture/observability-console.md),
  [mon-1-foundation-monitoring-observability-runbook](runbooks/mon-1-foundation-monitoring-observability-runbook.md).
- Android & device dokter: [android-production-signing-governance](governance/android-production-signing-governance.md),
  [android-clinic-device-provisioning](runbooks/android-clinic-device-provisioning.md),
  [doctor-pwa-webauthn](architecture/doctor-pwa-webauthn.md).
- Seluruh `docs/runbooks/` (49) dan `docs/operations/` (21), per snapshot 2026-10-25, dipetakan per modul di
  [module-index](knowledge-base/module-index.md).

### Pengujian dan kualitas

- [release-definition-of-done](engineering/release-definition-of-done.md),
  [devflow-quick-start](engineering/devflow-quick-start.md),
  [global-temporary-full-suite-policy](governance/global-temporary-full-suite-policy.md),
  [22 Testing & QA](ai-knowledge/22_DaengtisiaMS_Testing_QA_Smoke.md) (snapshot 2026-08-19).
- Sumber kebenaran: `tests/Feature/` (87 folder), `phpunit.xml`, dan
  `.github/workflows/foundation-evidence-gates.yml`.

### UI/UX

- [daengtisiams-ui-foundation](ui/daengtisiams-ui-foundation.md),
  [daengtisiams-design-tokens](ui/daengtisiams-design-tokens.md),
  [daengtisiams-ui-governance](ui/daengtisiams-ui-governance.md),
  [ui_design_system](ui_design_system.md).

### Riwayat rilis

- [release-history](knowledge-base/release-history.md): diturunkan dari GO tag git (421 GO tag per 2026-10-25).
- [sprint_history](sprint_history.md): hanya mencakup sampai **2026-06-28**.

## Di luar vault (buka di editor, bukan di Obsidian)

Path berikut sengaja ditulis sebagai teks kode, bukan tautan. Obsidian tidak dapat membuka
berkas di luar folder vault.

| Path | Isi |
|---|---|
| `CLAUDE.md` | Aturan global kritis CG-01…CG-24 dan peta otoritas (±11 KB, dimuat setiap sesi Claude Code). Sejak 2026-10-26 tidak lagi memuat catatan sprint; catatan lama ada di [arsip](sprints/claude-md-archive/README.md) |
| `.claude/rules/*.md` | 16 berkas aturan domain dengan frontmatter `paths`; dimuat hanya saat Claude membuka berkas yang cocok |
| `.cursor/rules/*.mdc` | 195 rule durable (sebagian besar adalah mirror rule sprint) |
| `.claude/skills/*/SKILL.md` | Skill proyek Claude Code, termasuk `knowledge-base` |
| `AGENTS.md` | Instruksi agen Codex (masih memakai nama lama "ADLMS"; lihat registry) |
| `app/Modules/` | 41 modul domain (kode) |
| `graphify-out/` | Graf pengetahuan lokal, tidak di-commit; mungkin tidak ada di worktree baru |

## Larangan keamanan dokumentasi

Jangan pernah menulis ke dokumen mana pun: kredensial, private key, isi file environment,
token, dump database, data pasien (nama + identitas, KTP/NIK asli, isi rekam medis),
atau tangkapan layar yang memuat data pasien. Rinciannya ada di bagian "Larangan keamanan"
pada [conventions](knowledge-base/conventions.md).
