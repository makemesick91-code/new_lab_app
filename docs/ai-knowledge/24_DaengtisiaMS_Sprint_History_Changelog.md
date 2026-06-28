# DaengtisiaMS — Sprint History & Changelog

## Tujuan
Ringkasan sprint dan keputusan penting yang ditemukan di repo — referensi permanen untuk AI.

## Ringkasan
`docs/sprint_history.md` adalah memori proyek utama (Sprint 0–17+ detail). `CLAUDE.md` mencatat sprint RME modern 20–64. Jangan mengarang sprint yang tidak ada di docs/git.

## Konteks DaengtisiaMS
Keputusan sprint lama mengikat kecuali explicitly superseded di hotfix/spec lebih baru.

## File / Area Repo Terkait
- `docs/sprint_history.md` — otoritas timeline arsitektur
- `CLAUDE.md` — sprint RME 20–64 ringkas
- `docs/sprint_*` — 200+ dokumen fase
- `git log --oneline`

## Aturan Utama

### Foundation (dari sprint_history.md)
| Sprint | Tema |
|---|---|
| 0–1 | Laravel shell, Spatie RBAC |
| 2 | Master data |
| 3–8 | Lab order → production → QC → delivery → invoice → reporting |
| 10–11 | Branch context & enforcement |
| 12 | Inventory core ledger |
| 13–17 | Opname, transfer, batch, procurement, analytics, reports |

### RME pilot (CLAUDE.md / docs sprint_20+)
| Sprint/Phase | Highlight |
|---|---|
| 20 | RME core — visit, RM handwriting, odontogram, cashier, permissions |
| 21 | RME→Lab architecture, candidates, queue UI, conversion, PDF print, VPS checklist |
| 22 | Role hardening (Owner, Kasir, Perawat), smoke test, owner dashboard foundation |
| 58.6–58.7 | Treatment worklist, patient queue |
| 59 | Editable RM/odontogram post-finalize, table odontogram |
| 60 | Multi-page handwriting canvas (spec/planning + implementation phases) |
| 60.8 hotfix | Room assignment gate before exam |
| 61.0 | Patient data completeness audit |
| 61.3 | Patient scan document governance |
| 62.0 | Owner KPI dashboard |
| 62.1 | Doctor→cashier completion gate |
| hotfix | Partial payment completes visit |
| 62.2 | Outstanding receivable carry-over |
| 62.3 | Legacy patient batch import |
| 63.1 | Structured odontogram print |
| 64.0 | Patient-centric RM workspace + swipe sheets |
| 64.0.2 hotfix | Canonical handwriting RM pages |

### Stabilization (26+)
- `docs/sprint_26_phase_26_8_stabilization_closure_go_watch_no_go_report.md`
- Branch aktif: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`

### Tags & merge (contoh recent git)
- PR #97: hotfix sprint-64-0-2 canonical handwriting
- PR #96: sprint-64-0 patient-centric RM workspace
- Merge commits: `5a8375e`, `9678694`, `4ac670f`, dll.

### Deployment notes
- Sprint 21 VPS pilot deployed — Hostinger
- Path: `/var/www/asia-dental-lab-v2`
- Pre-Sprint 21 rollback tag: `sprint-20-rme-core-ui-complete`
- **Never** `migrate:fresh` on VPS

## Workflow / Alur
Saat AI diminta implementasi:
1. Cek `CLAUDE.md` + `sprint_history.md` untuk kontrak
2. Cek hotfix/spec lebih baru yang supersede
3. Jika konflik → laporkan di "Catatan Konflik"

## Struktur Teknis
Dokumen fase: `docs/sprint_<N>_*.md`, `docs/sprint_<N>_phase_*.md`

Graphify updates: `docs/graphify_sprint_*_update.md`

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan reopen SOAP doctor UI (Sprint 20 closure)
- Jangan revert doctor→cashier gate tanpa spec
- Jangan hapus ledger inventory untuk "simplifikasi"

## Checklist Validasi
- [ ] Fitur baru tidak melanggar sprint contract tertulis
- [ ] Hotfix terbaru diutamakan atas doc lama
- [ ] Tag/commit disebutkan jika deploy

## Catatan untuk AI
**Catatan Konflik / Perlu Verifikasi:**
- `sprint_history.md` last updated June 2026 — sprint 58+ mungkin hanya di `CLAUDE.md` / docs fase terpisah
- Numbering overlap Sprint 9 branch vs ai_development_guide — ikuti `ai_development_guide` untuk arsitektur

**TODO:** Sprint 23–57 detail lengkap — lihat `docs/` bila perlu; tidak dirangkum penuh di file ini.

Jangan claim "Sprint X complete" tanpa test evidence di chat.
