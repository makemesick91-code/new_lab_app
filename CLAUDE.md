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

**UI:** All RME views use TailAdmin components (`x-ui.card`, `x-ui.table`, `x-ui.badge`, `x-ui.button`, `x-settings-shell`). UI modernization merged into `feature/sprint-20-rme-core` (merge commit `8246008`, tag `sprint-20-rme-ui-modernization-merged`).

**Sprint 20 closure (2026-06-11):** Final branch `feature/sprint-20-rme-core`. Closure tag `sprint-20-rme-core-ui-complete`. Full suite: 1842 passed / 6290 assertions. RME suite: 283 passed / 718 assertions. Do NOT re-open SOAP in doctor UI — handwriting RM is the primary clinical input and SOAP is hidden by design. Full-payment-only rule remains in force (partial/cicilan deferred to Sprint 21). Closure report: `docs/sprint_20_final_closure_report.md`.

**Sprint 21 planning (2026-06-11):** Planning branch `feature/sprint-21-planning`. Theme: RME Advanced Workflow + Pilot Deployment. Planning doc: `docs/sprint_21_planning.md`. First implementation phase: Phase 21.1 RME → Lab integration architecture (design only), then Phase 21.2 lab order generation (tests-first). SOAP doctor UI remains hidden. Full-payment-only behavior is the baseline — partial/cicilan changes begin only when Phase 21.4 is explicitly approved. No feature code added in planning phase.

**Sprint 21 Phase 21.1 — RME → Lab architecture (2026-06-11):** Branch `feature/sprint-21-rme-lab-architecture`. Tag `sprint-21-phase-21-1-rme-lab-architecture`. Architecture doc: `docs/sprint_21_rme_lab_integration_architecture.md`. Design only — no code changed. Key decisions: (1) RME → Lab trigger is after `RmePaymentService::pay()` sets invoice to `PAID`. (2) Pilot strategy: create `LabCaseCandidate` staging record first (not direct LabOrder) — `LabOrderItem` uses `lab_service_id` but RME items use `treatment_id`; no mapping exists yet. (3) One candidate per eligible `trx_rme_invoice_items` row where `mst_treatments.requires_lab = true` (column already exists). (4) Idempotent via `UNIQUE(rme_invoice_item_id)` on `trx_lab_case_candidates`. (5) Payment commits first; candidate generation is post-commit (failure does not roll back payment). (6) `branch_id` copied from RME invoice; validated against `BranchContext::requireId()`. (7) Do NOT create `trx_payments` (lab billing) records from RME payment — `trx_rme_payments` only. Phase 21.2 unblocked after project owner approves this architecture.

**Sprint 21 Phase 21.2 — Lab Case Candidate generation (2026-06-11):** Branch `feature/sprint-21-lab-case-candidates`. Tag `sprint-21-phase-21-2-lab-case-candidates`. Implements the first functional RME → Lab integration layer. New table `trx_lab_case_candidates` (migration `2026_06_14_210001`). New model `App\Modules\LabOrder\Models\LabCaseCandidate` (status constants: `pending_review`, `converted_to_lab_order`, `rejected`, `cancelled`). New service `App\Modules\RmeInvoice\Services\RmeLabIntegrationService` — `generateForPaidInvoice()` is idempotent via `firstOrCreate(rme_invoice_item_id)`. Post-commit hook in `RmePaymentService::pay()` calls generation after payment transaction commits; generation failure logs warning and does NOT roll back payment. Branch isolation: service validates `invoice.branch_id === BranchContext::id()`. No real `LabOrder` created — candidates await Admin Lab review. 11 tests in `tests/Feature/RME/LabIntegrationTest.php`. Full suite: 1853 passed. Sprint 20 full-payment-only rule preserved.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).

## Sprint 21 Phase 21.3 — Admin Lab Candidate Queue UI (2026-06-11)

**Branch:** `feature/sprint-21-lab-candidate-queue`
**Tag:** `sprint-21-phase-21-3-lab-candidate-queue`

### What was added

- Read-only `LabCaseCandidate` queue for Admin Lab
- Routes: `lab-case-candidates.index` / `lab-case-candidates.show` under `/lab/case-candidates`
- `LabCaseCandidatePolicy` (viewAny + view with branch isolation)
- Registered in `RepositoryServiceProvider.$policies`
- `LabCaseCandidateController` — branch-scoped, filter by status, search
- Views: `resources/views/lab/case-candidates/index.blade.php` and `show.blade.php`
- Sidebar: "Kandidat Lab RME" item, gated by `view_lab_orders | manage_lab_orders`

**Sprint 21 Phase 21.4 — Convert LabCaseCandidate to LabOrder (2026-06-11):** Branch `feature/sprint-21-candidate-to-laborder`. Tag `sprint-21-phase-21-4-candidate-to-laborder`. `LabCaseCandidateConversionService` converts pending candidates to `LabOrder` with explicit `lab_service_id` (no treatment mapping). Route `lab-case-candidates.convert`. Idempotent via row lock + `converted_lab_order_id`. Reuses `create_lab_orders`/`manage_lab_orders`. No lab payment/invoice records. RME payment still does not auto-create LabOrder. 16 tests in `LabCaseCandidateConversionTest.php`.

**Sprint 21 Phase 21.5 — RME Lab Workflow Polish (2026-06-11):** Branch `feature/sprint-21-rme-lab-workflow-polish`. Tag `sprint-21-phase-21-5-rme-lab-workflow-polish`. UI/visibility only — no business logic changes to payment, generation, or conversion. RME invoice/receipt show lab candidate status; candidate queue/show improved for converted state; LabOrder show displays Sumber RME when from candidate. Relations: `RmeInvoice::labCaseCandidates()`, `LabOrder::rmeLabCaseCandidate()`. 16 tests in `RmeLabWorkflowPolishTest.php`.

**Sprint 21 Phase 21.6 — RME PDF Export / Print Hardening (2026-06-11):** Branch `feature/sprint-21-rme-pdf-print-hardening`. Tag `sprint-21-phase-21-6-rme-pdf-print-hardening`. Print/PDF hardening only — visit print bundle includes patient/visit/branch, initial treatment, finalized RM + handwriting (SOAP hidden), odontogram, paid invoice/payment, lab workflow summary. Receipt print includes lab workflow panel. PDF route `rme.visits.pdf` uses existing `barryvdh/laravel-dompdf`. No payment/generation/conversion changes. 21 tests in `RmePdfPrintHardeningTest.php`.

**Sprint 21 Phase 21.7 — VPS Pilot Deployment Checklist (2026-06-11):** Branch `feature/sprint-21-vps-pilot-checklist`. Tag `sprint-21-phase-21-7-vps-pilot-checklist`. Documentation/runbook only — no deployment performed. Checklist: `docs/sprint_21_vps_pilot_deployment_checklist.md`. VPS deploy target: branch `feature/sprint-21-rme-pdf-print-hardening`, tag `sprint-21-phase-21-6-rme-pdf-print-hardening`, commit `327e55f`. **VPS rules:** always backup DB before pull/migrate; use `php artisan migrate --force` only; **never** `migrate:fresh` or `db:wipe` on VPS; reset `storage`/`bootstrap/cache` permissions after deploy.
