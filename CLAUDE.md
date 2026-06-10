## RME Module (Sprint 20)

**Modules:** `ClinicVisit`, `MedicalRecord`, `Odontogram`, `RmeInvoice` under `app/Modules/`.

**Routes:** `rme.*` prefix in `routes/web.php`.

**Workflow:** Admin creates visit → Doctor odontogram + handwriting RME → finalize →
`cashier_pending` → Cashier invoice + full payment → `completed` → print receipt.

**Visit statuses:** `registered`, `waiting`, `in_progress`, `cashier_pending`, `completed`, `cancelled`.

**Invoice statuses:** `DRAFT`, `UNPAID`, `PAID`, `VOID`.

**Rules:**
- Handwriting RM is the **primary doctor-facing clinical input**; SOAP fields are optional legacy structured fields hidden from doctor UI.
- Handwriting PNG **mandatory** before RME finalization; immutable after finalize.
- Initial service is triage-only — **no billing**.
- Cashier billing requires finalized RME + `cashier_pending` visit (`manage_rme_billing`).
- **Full payment only** in pilot — partial/cicilan deferred.
- Print via browser `window.print()` — no server PDF.

**Permissions:** `view_clinic_visits`, `manage_clinic_visits`, `manage_rme_billing`.

**Pilot doc:** `docs/sprint_20_rme_limited_pilot_summary.md`

**Pilot master data import:** use `php artisan rme:import-pilot-backup` (see `docs/rme_pilot_backup_import_guide.md`). Never restore backup SQL directly over the Sprint 20 database.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
