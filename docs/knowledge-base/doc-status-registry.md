---
kb_status: current
kb_verified_against: 572a32a9
kb_updated: 2026-10-26
---

# Registry Status Dokumen

Seberapa jauh sebuah dokumen boleh dipercaya, beserta bukti drift yang **terukur**.
Kembali ke [00-INDEX](../00-INDEX.md).

Registry ini **tidak mengubah** dokumen yang dinilainya. Dokumen lama dibiarkan apa adanya.
Statusnya dicatat di sini agar pembaca (manusia maupun AI) tahu cara memperlakukannya.

## Kosakata status

| Status | Arti | Cara memperlakukan |
|---|---|---|
| `AUTHORITATIVE` | Kode, migration, route, test, config | Sumber kebenaran |
| `CURRENT` | Dirawat dan konsisten dengan kode saat diperiksa | Boleh diandalkan; tetap verifikasi untuk perubahan berisiko |
| `SNAPSHOT` | Benar per tanggalnya; drift belum diukur | Gunakan sebagai petunjuk; verifikasi ke kode |
| `STALE` | Drift terhadap kode **sudah diukur** | Hanya untuk konteks historis; jangan diandalkan |
| `HISTORICAL` | Catatan sprint atau keputusan masa lalu | Jangan diperbarui; bisa saja sudah di-supersede |
| `UNVERIFIED` | Belum diperiksa dalam audit ini | Perlakukan seperti `SNAPSHOT` |

Dokumen knowledge base yang baru memakai frontmatter `kb_status`, `kb_verified_against`
(SHA commit), dan `kb_updated`. Lihat [conventions](conventions.md).

## Status default per folder

| Lokasi | Status default | Catatan |
|---|---|---|
| `docs/adr/` | `CURRENT` jika baris Status berisi `Accepted` | ADR 0009 sebagian di-supersede oleh ADR 0010; baca baris Status di setiap ADR |
| `docs/decisions/` | `CURRENT` | Register keputusan owner, dirujuk langsung oleh test (path persis) |
| `docs/architecture/` | `UNVERIFIED` | Kontrak governance; banyak yang dirujuk config/test dengan path persis |
| `docs/runbooks/` | `UNVERIFIED` | Operasional; dipindai `ProductionShellCommandGuardTest` |
| `docs/operations/` | `HISTORICAL` atau `UNVERIFIED` | Bukti dan SOP per tanggal; periksa tanggal pada nama berkas |
| `docs/sprints/` | `HISTORICAL` | Catatan per sprint; sprint yang lebih baru bisa men-supersede |
| `docs/sprint_*.md`, `docs/hotfix_*.md` | `HISTORICAL` | Era Sprint 3–64 |
| `docs/graphify_sprint_*.md` (26 berkas) | `HISTORICAL` | Catatan pembaruan graf; terbaru 2026-06-14 |
| `docs/ai-knowledge/` | `SNAPSHOT` / `STALE` | Lihat tabel per berkas di bawah |
| `docs/knowledge-base/` | sesuai frontmatter | Indeks ini |

## Dokumen hub — status terukur

Tanggal = commit terakhir yang menyentuh berkas (per `572a32a9`).

| Dokumen | Tanggal | Status | Bukti |
|---|---|---|---|
| [database_schema](../database_schema.md) | 2026-06-07 | `STALE` | 93 dari 163 tabel di migration tidak disebut (gabungan dengan dok. 04) |
| [erd](../erd.md) | 2026-06-04 | `STALE` | Lebih tua dari 211 migration; tidak diukur per tabel |
| [system_architecture](../system_architecture.md) | 2026-06-03 | `STALE` | 14 modul (antara lain `Satusehat`, `LegacyRme`, `DoctorDevice`) baru dibuat antara 2026-06-29 dan 2026-10-12 |
| [prd](../prd.md) | 2026-06-03 | `HISTORICAL` | PRD awal (era "Asia Dental Lab") |
| [api_spec](../api_spec.md) | 2026-06-03 | `STALE` | Mendahului `routes/device_api.php` |
| [development_setup](../development_setup.md) | 2026-06-03 | `STALE` | Menyebut Windows dan PHP 8.4; runtime produksi adalah PHP 8.3-FPM |
| [sprint_history](../sprint_history.md) | 2026-06-28 | `STALE` | 325 GO tag dibuat setelah tanggal ini; lihat [release-history](release-history.md) |
| [local_automation_guide](../local_automation_guide.md) | 2026-06-10 | `UNVERIFIED` | — |
| [project_rules](../project_rules.md), [architecture_rules](../architecture_rules.md) | 2026-07-05 | `SNAPSHOT` | Belum diukur |
| [ai_development_guide](../ai_development_guide.md), [ai_bootstrap_prompt](../ai_bootstrap_prompt.md) | 2026-07-05 | `SNAPSHOT` | Belum diukur |

Di luar vault:

| Path | Status | Bukti |
|---|---|---|
| `CLAUDE.md` | `CURRENT` (append-only) | Diperbarui setiap sprint. 3.865 baris / 1,56 MB per 2026-10-25 (lihat risiko di bawah) |
| `.cursor/rules/*.mdc` | `CURRENT` | 194 rule; prefiks nomor bertabrakan (13 berkas berprefiks `92-`, 6 berprefiks `100-`) |
| `AGENTS.md` | `STALE` | Memakai nama lama "ADLMS" (3×), tidak menyebut "Daengtisia", merujuk `claude-mem` |
| `README.md` | `STALE` | Berjudul "ADLMS Cursor Package"; 29 baris; terakhir diubah 2026-06-06 |
| `.claude/settings.json` | `STALE` (sebagian) | Hook graphify berlaku; daftar izin merujuk path Windows/XAMPP, sementara mesin dev memakai Ubuntu |

## `docs/ai-knowledge/` — per berkas

README folder tersebut menyebut berkas-berkas ini "berbasis fakta repo". Hal itu benar
**pada tanggal penulisannya**. Sebagian besar ditulis pada 2026-06-28, sebelum puluhan
sprint berikutnya.

| Berkas | Tanggal | Status | Bukti |
|---|---|---|---|
| [01 Master Context](../ai-knowledge/01_DaengtisiaMS_Master_Context.md) | 2026-07-05 | `STALE` | 14 dari 41 modul tidak disebut |
| [02 Module Map](../ai-knowledge/02_DaengtisiaMS_Module_Map.md) | 2026-06-28 | `STALE` | 14 dari 41 modul tidak disebut; gunakan [module-index](module-index.md) |
| [03 Tech Stack](../ai-knowledge/03_DaengtisiaMS_Tech_Stack_Architecture.md) | 2026-06-28 | `SNAPSHOT` | Dicek sekilas: Laravel 12 dan PostgreSQL sesuai `composer.json` |
| [04 Database Schema](../ai-knowledge/04_DaengtisiaMS_Database_Schema.md) | 2026-06-28 | `STALE` | Menyebut 90 migration; ada 211 |
| [05 Routes Map](../ai-knowledge/05_DaengtisiaMS_Routes_Map.md) | 2026-06-28 | `STALE` | Mendahului `routes/device_api.php` dan semua route legacy/SATUSEHAT/device |
| [06 RBAC](../ai-knowledge/06_DaengtisiaMS_RBAC_Permissions.md) | 2026-06-28 | `STALE` | Pengukuran kasar: 167 dari 186 nama permission di `PermissionSeeder` tidak disebut |
| [07 Branch Context](../ai-knowledge/07_DaengtisiaMS_Branch_Context_Rules.md) | 2026-06-28 | `STALE` | Diukur 2026-10-26: 0 penyebutan daily branch lock, front-office pin, maupun doctor branch lock, padahal ketiganya ada di kode (`DailyBranchContextService`, `FrontOfficeBranchPinResolver`, `DoctorEffectiveBranchResolver`) |
| [08 RME Workflow](../ai-knowledge/08_DaengtisiaMS_RME_Workflow.md) | 2026-09-01 | `SNAPSHOT` | — |
| [09 Patient RM KTP](../ai-knowledge/09_DaengtisiaMS_Patient_RM_KTP_Rules.md) | 2026-06-28 | `STALE` | Diukur 2026-10-26: tidak menyebut `TLK1`, `SPN4`, maupun `PatientMerge`, tetapi masih menyebut kode cabang `TKM1` yang sudah deprecated |
| [10 Visit & Medical Record](../ai-knowledge/10_DaengtisiaMS_Visit_Medical_Record_Rules.md) | 2026-06-28 | `SNAPSHOT` | — |
| [11 Odontogram](../ai-knowledge/11_DaengtisiaMS_Odontogram_Rules.md) | 2026-08-21 | `SNAPSHOT` | — |
| [12 Cashier & Receivable](../ai-knowledge/12_DaengtisiaMS_Cashier_Receivable_Rules.md) | 2026-10-16 | `SNAPSHOT` | Paling baru; program koreksi tagihan berubah setelahnya |
| 13–21 (Lab, Inventory, Procurement, Transfer, Reports, KPI, WhatsApp, UI) | 2026-06-28 | `SNAPSHOT` | Belum diukur |
| [22 Testing & QA](../ai-knowledge/22_DaengtisiaMS_Testing_QA_Smoke.md) | 2026-08-19 | `SNAPSHOT` | — |
| [23 Deployment VPS](../ai-knowledge/23_DaengtisiaMS_Deployment_VPS_Runbook.md) | 2026-06-28 | `STALE` | Tidak menyebut `scripts/deploy-vps-runner.sh`, satu-satunya jalur deploy yang didukung saat ini |
| [24 Sprint History](../ai-knowledge/24_DaengtisiaMS_Sprint_History_Changelog.md) | 2026-06-28 | `STALE` | 325 GO tag setelah tanggal ini |
| [25 AI Workflow](../ai-knowledge/25_DaengtisiaMS_AI_Workflow_Prompts.md) | 2026-07-05 | `SNAPSHOT` | — |
| [ChatGPT Master Knowledge Compact](../ai-knowledge/DaengtisiaMS_ChatGPT_Master_Knowledge_Compact.md) | 2026-08-21 | `STALE` | 13 dari 41 modul tidak disebut |

## Duplikasi dan konflik yang diketahui

Dicatat dan **tidak** diperbaiki dalam sprint ini. Perbaikan apa pun memerlukan review.

| # | Temuan | Dampak |
|---|---|---|
| D1 | Keputusan tersebar di `docs/adr/` (ADR bernomor) dan `docs/decisions/` (register owner) | Keduanya dirujuk test dengan path persis, jadi **jangan dipindahkan** |
| D2 | Dokumen sprint ada di `docs/sprint_*.md` (lama, 156) dan `docs/sprints/` (baru, 311) | Telusuri keduanya untuk sejarah suatu topik |
| D3 | Ringkasan AI ada di tiga tempat: `docs/ai-knowledge/`, `.cursor/memory/`, dan bagian-bagian `CLAUDE.md` | `CLAUDE.md` dan `.cursor/rules` paling baru |
| D4 | Tautan rusak (sudah ada sebelumnya): `docs/ai-knowledge/README.md` menautkan `../.cursor/snippets/...`; seharusnya `../../.cursor/snippets/...` | Satu-satunya tautan relatif rusak dari 166 tautan per 2026-10-25 |
| D5 | Prefiks nomor `.cursor/rules` bertabrakan (`92-` ×13, `100-` ×6, `97-`/`93-`/`101-`/`121-`/`143-`/`172-` ×2) | Rule dimuat berdasarkan nama berkas; tabrakan hanya membingungkan rujukan "rule NN" |
| D6 | 723 dari 782 dokumen `docs/*.md` tidak memiliki tautan masuk | Hanya bisa ditemukan lewat pencarian; indeks ini mengurangi masalah tersebut |

## Risiko: ukuran `CLAUDE.md`

Dokumentasi resmi Claude Code menyarankan `CLAUDE.md` **di bawah 200 baris** dan
**melewati (skip) seluruh berkas** yang lebih besar dari **4 MiB**
([sumber](https://code.claude.com/docs/en/memory.md)).

| Tanggal | Ukuran |
|---|---|
| 2026-07-01 | 34 KB |
| 2026-08-01 | 334 KB |
| 2026-09-01 | 896 KB |
| 2026-10-01 | 1,28 MB |
| 2026-10-25 | 1,56 MB (3.865 baris) |

Laju pertumbuhan 30 hari terakhir sekitar 10 KB/hari. Pada laju itu, batas 4 MiB tercapai
dalam kira-kira 255 hari. Setelah itu Claude Code **tidak akan memuat aturan proyek sama
sekali**. Penataan ulang `CLAUDE.md` adalah keputusan owner dan **tidak** dilakukan di sini.
Usulannya ada di [conventions](conventions.md).

## Audit konten sensitif (2026-10-25)

Pindaian atas semua `.md`/`.mdc` yang ter-track. Nilai sengaja tidak dicantumkan.

| Kategori | Hasil | Klasifikasi |
|---|---|---|
| Private key, nilai `APP_KEY`, token GitHub/OpenAI | 0 | — |
| Password default database untuk pengembangan lokal | 1 (`development_setup.md`) | Kredensial lokal; jangan disalin ke dokumen baru |
| IP publik server produksi | 20 kemunculan di 17 berkas | Bukan rahasia, tetapi jangan disebarkan lebih jauh |
| Email login berbasis peran (`admin…@`) | 15 berkas | Identitas akun, tanpa password |
| Angka 16 digit | 12 | Sentinel terdokumentasi (`000…`, `999…`), KTP sintetis, dan potongan SHA git; **tidak ada** NIK asli |

Dokumen knowledge base yang baru tidak memuat satu pun nilai dari kategori-kategori tersebut.
