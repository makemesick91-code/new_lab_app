# DaengtisiaMS AI Knowledge Base

## Tujuan folder

Folder `docs/ai-knowledge/` berisi **25 dokumen knowledge base wajib** (plus README ini) untuk membantu ChatGPT, Claude, dan Cursor memahami aplikasi **Daengtisia Management System** (DaengtisiaMS) secara menyeluruh — tanpa harus memindai seluruh repo setiap sesi.

Semua dokumen:
- Bahasa Indonesia, teknis-operasional
- Berbasis fakta repo (kode, migration, route, test, docs)
- Memuat `TODO` / `UNKNOWN` jika informasi belum diverifikasi
- Memuat checklist validasi per dokumen

**Jangan mengubah logic aplikasi** — folder ini hanya dokumentasi konteks AI.

**Foundation-first sprint lock:** ACTIVE. Baca
`docs/architecture/foundation-first-sprint-lock-governance.md`; pekerjaan
non-foundation adalah POST-FOUNDATION BACKLOG dan tidak boleh dieksekusi sebelum
FOUNDATION GO.

---

## Daftar 25 sumber

| # | File | Topik |
|---|---|---|
| 01 | [01_DaengtisiaMS_Master_Context.md](01_DaengtisiaMS_Master_Context.md) | Konteks utama aplikasi |
| 02 | [02_DaengtisiaMS_Module_Map.md](02_DaengtisiaMS_Module_Map.md) | Peta modul |
| 03 | [03_DaengtisiaMS_Tech_Stack_Architecture.md](03_DaengtisiaMS_Tech_Stack_Architecture.md) | Stack & arsitektur |
| 04 | [04_DaengtisiaMS_Database_Schema.md](04_DaengtisiaMS_Database_Schema.md) | Schema database |
| 05 | [05_DaengtisiaMS_Routes_Map.md](05_DaengtisiaMS_Routes_Map.md) | Peta route |
| 06 | [06_DaengtisiaMS_RBAC_Permissions.md](06_DaengtisiaMS_RBAC_Permissions.md) | Role & permission |
| 07 | [07_DaengtisiaMS_Branch_Context_Rules.md](07_DaengtisiaMS_Branch_Context_Rules.md) | Branch isolation |
| 08 | [08_DaengtisiaMS_RME_Workflow.md](08_DaengtisiaMS_RME_Workflow.md) | Alur RME |
| 09 | [09_DaengtisiaMS_Patient_RM_KTP_Rules.md](09_DaengtisiaMS_Patient_RM_KTP_Rules.md) | Pasien, RM, KTP |
| 10 | [10_DaengtisiaMS_Visit_Medical_Record_Rules.md](10_DaengtisiaMS_Visit_Medical_Record_Rules.md) | Visit & RM |
| 11 | [11_DaengtisiaMS_Odontogram_Rules.md](11_DaengtisiaMS_Odontogram_Rules.md) | Odontogram |
| 12 | [12_DaengtisiaMS_Cashier_Receivable_Rules.md](12_DaengtisiaMS_Cashier_Receivable_Rules.md) | Kasir & piutang |
| 13 | [13_DaengtisiaMS_Lab_Module_Workflow.md](13_DaengtisiaMS_Lab_Module_Workflow.md) | Modul lab |
| 14 | [14_DaengtisiaMS_Inventory_Master_Data.md](14_DaengtisiaMS_Inventory_Master_Data.md) | Master inventory |
| 15 | [15_DaengtisiaMS_Inventory_Ledger_Rules.md](15_DaengtisiaMS_Inventory_Ledger_Rules.md) | Ledger stok |
| 16 | [16_DaengtisiaMS_Procurement_PR_PO_GR.md](16_DaengtisiaMS_Procurement_PR_PO_GR.md) | PR / PO / GR |
| 17 | [17_DaengtisiaMS_Stock_Transfer_Opname.md](17_DaengtisiaMS_Stock_Transfer_Opname.md) | Transfer & opname |
| 18 | [18_DaengtisiaMS_Reports_Exports.md](18_DaengtisiaMS_Reports_Exports.md) | Laporan & export |
| 19 | [19_DaengtisiaMS_Owner_Dashboard_KPI.md](19_DaengtisiaMS_Owner_Dashboard_KPI.md) | Dasbor owner |
| 20 | [20_DaengtisiaMS_WhatsApp_Reminder_Workflow.md](20_DaengtisiaMS_WhatsApp_Reminder_Workflow.md) | Reminder WA manual |
| 21 | [21_DaengtisiaMS_UI_UX_Guidelines.md](21_DaengtisiaMS_UI_UX_Guidelines.md) | UI/UX |
| 22 | [22_DaengtisiaMS_Testing_QA_Smoke.md](22_DaengtisiaMS_Testing_QA_Smoke.md) | Testing & QA |
| 23 | [23_DaengtisiaMS_Deployment_VPS_Runbook.md](23_DaengtisiaMS_Deployment_VPS_Runbook.md) | Deploy VPS |
| 24 | [24_DaengtisiaMS_Sprint_History_Changelog.md](24_DaengtisiaMS_Sprint_History_Changelog.md) | Riwayat sprint |
| 25 | [25_DaengtisiaMS_AI_Workflow_Prompts.md](25_DaengtisiaMS_AI_Workflow_Prompts.md) | Prompt & workflow AI |

Tambahan Cursor: [`.cursor/snippets/adlms_master_workflow.md`](../.cursor/snippets/adlms_master_workflow.md)

---

## Urutan prioritas upload ke ChatGPT

**Jangan upload 25 file sekaligus tanpa konteks** — gunakan batch berurutan:

### Batch 1 — Wajib (setiap sesi baru)
1. `01_DaengtisiaMS_Master_Context.md`
2. `docs/architecture/foundation-first-sprint-lock-governance.md`
3. `03_DaengtisiaMS_Tech_Stack_Architecture.md`
4. `07_DaengtisiaMS_Branch_Context_Rules.md`
5. `25_DaengtisiaMS_AI_Workflow_Prompts.md`

### Batch 2 — Sesuai domain task
| Task | Upload tambahan |
|---|---|
| RME / klinik | 08, 09, 10, 11, 12 |
| Lab | 13 (+ 02, 05) |
| Inventory | 14, 15, 16, 17 |
| Laporan / owner | 18, 19 |
| UI | 21 (+ 03) |
| Deploy | 23 (+ 24) |
| QA | 22 |

### Batch 3 — Referensi
- `02` Module map
- `04` Database schema
- `05` Routes map
- `06` RBAC
- `24` Sprint history

---

## Cara menggunakan di ChatGPT / Claude / Cursor

### ChatGPT / Claude (custom instructions / project knowledge)
1. Buat Project "DaengtisiaMS"
2. Upload Batch 1 sebagai file knowledge permanen
3. Per task, attach 2–4 dokumen Batch 2 yang relevan
4. Paste master prompt dari `25_DaengtisiaMS_AI_Workflow_Prompts.md`
5. Sebutkan branch git aktif dan scope task eksplisit

### Cursor
1. Rules otomatis dari `.cursor/rules/` — sudah loaded
2. @-mention file `docs/ai-knowledge/XX_*.md` yang relevan
3. Paste snippet `.cursor/snippets/adlms_master_workflow.md` di awal task besar
4. Untuk navigasi kode: `graphify query "..."` jika `graphify-out/` ada

### Catatan penting
- **Jangan upload source mentah tanpa konteks** — selalu sertakan scope task, branch, dan file yang boleh diubah
- Dokumen ini **melengkapi** bukan mengganti `docs/architecture_rules.md` dan `docs/inventory_rules.md`
- Jika AI menemukan konflik dokumen vs kode → **kode + test menang**, laporkan di "Catatan Konflik"
- Refresh knowledge base setelah sprint besar (update file terkait, bukan seluruh repo)

---

## Pemeliharaan

Update dokumen terkait saat:
- Migration schema baru
- Permission/role baru
- Perubahan workflow RME/kasir/inventory
- Deploy baseline branch/tag berubah

Setelah ubah kode aplikasi: `graphify update .`

**Baseline repo saat pembuatan KB:** branch `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`, HEAD `5a8375e`.
