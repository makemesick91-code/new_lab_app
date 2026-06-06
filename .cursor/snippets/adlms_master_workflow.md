# ADLMS MASTER WORKFLOW

Paste this snippet at the beginning of every ADLMS Codex/Cursor implementation prompt.

You are working on ADLMS, the Asia Dental Lab Management System: a Laravel modular monolith for multi-branch dental laboratory operations. Treat this repository as production-adjacent. Make small, scoped, tested changes. Preserve completed sprint contracts.

## Mandatory Read Workflow

Before analysis, planning, or coding, read these files in this order:

1. `AGENTS.md`
2. `docs/ai_bootstrap_prompt.md`
3. `docs/inventory_rules.md`
4. `docs/sprint_history.md`
5. `graphify-out/GRAPH_REPORT.md`
6. `.cursor/memory/*` if present

Also inspect the target module, related routes, policies, requests, services, repositories, views, tests, and migrations before changing files. For branch-owned work, inspect `app/Modules/Branch/Services/BranchContext.php`.

If `graphify-out/GRAPH_REPORT.md` is stale compared with the current commit, say so and rely on the current code and docs as source of truth.

## Operating Modes

Use these internal modes whenever relevant:

- CONTEXT-MODE: load project rules, architecture, inventory rules, sprint history, graph context, and target module context before implementation.
- CLAUDE-MEM MODE: read `.cursor/memory/*` and use it as durable project context. If memory conflicts with current docs or code, current docs/code win.
- SUPERPOWER MODE: inspect related files first, make minimal safe changes, preserve completed sprint contracts, add or update tests, and run applicable quality gates.
- FRONTEND-DESIGN MODE: for UI, Blade, sidebar, layout, dashboard, and navigation work, keep the UI operational, permission-gated, mobile responsive, and aligned with the ADLMS design system.

## Architecture Rules

Always preserve:

- Laravel modular monolith under `app/Modules`.
- Flow: Controller -> Form Request -> Service -> Repository -> Model.
- Business logic in Services.
- Queries and persistence in Repositories.
- Validation in Form Requests.
- Authorization through Policies/Gates and Spatie permissions.
- Repository interfaces and provider bindings where the module uses them.
- Branch-owned data resolved through `BranchContext`; never trust submitted `branch_id`.
- Multi-branch isolation; cross-branch leakage is a critical bug.

Never:

- Put business logic in controllers or Blade.
- Query branch-owned data without branch scope.
- Bypass policies or permissions.
- Introduce a new framework or parallel architecture.
- Modify unrelated files or revert unrelated user changes.
- Touch the HR module unless the prompt explicitly scopes HR work.

## Inventory Non-Negotiable Rules

Inventory remains ledger-only.

- Never add mutable stock columns.
- Stock = `SUM(quantity_in) - SUM(quantity_out)`.
- Do not update stock directly.
- Do not read stock from product/location/batch balance columns.
- Do not create inventory movements unless explicitly required by sprint scope.
- Every stock-affecting movement must be branch-owned, location-aware, product-aware, and transactional.
- Use `BranchContext` for branch-owned inventory operations.
- Use Form Requests for validation.
- Use Policies for authorization.
- Use Services for business rules and workflow transitions.
- Preserve multi-branch isolation.
- Preserve location isolation.
- Reject inactive products and inactive locations for stock operations.
- Reject zero or negative quantities.
- Prevent negative stock whenever an outbound operation is in scope.
- Do not implement manual transfer as adjustment-out plus adjustment-in guidance.

## Sprint Baseline

Treat these sprint contracts as completed baselines:

- Sprint 12 Inventory Core complete.
- Sprint 13 Stock Opname complete.
- Sprint 14 Stock Transfer complete.
- Sprint 15.2 Transfer Receiving complete.
- Sprint 15.3 Batch & Lot complete.
- Sprint 15.4 Reorder & Alerts complete.
- Sprint 15.5 Analytics complete.
- Sprint 15.6 Inventory Advanced Hardening complete.

Important transfer baseline:

- Sprint 14 introduced stock transfer.
- Sprint 15.2 superseded the original single-step completion workflow with a two-phase ship/receive workflow.
- Preserve the current repo's transfer statuses, routes, service methods, policies, and tests. Do not reintroduce removed legacy workflows unless explicitly requested and validated against sprint history.

## Output Before Coding

Before making changes, output:

- Files reviewed.
- Existing patterns found.
- Risks.
- Planned implementation.

The plan must identify:

- Affected module(s).
- Affected services.
- Affected repositories/interfaces.
- Affected policies/permissions.
- Affected requests.
- Affected views/components, if UI is touched.
- Tests to add or update.
- Quality gates to run.
- Out-of-scope items.

## Implementation Discipline

When implementation is approved or clearly requested:

1. Keep changes minimal and scoped.
2. Follow existing module conventions.
3. Prefer existing services, repositories, policies, requests, factories, and helpers.
4. Add focused tests for happy path, validation failure, authorization, branch isolation, and ledger correctness when inventory is touched.
5. Run requested gates and report results honestly.
6. Before final response, verify no mutable stock columns, no direct stock mutation, no branch leakage, and no unrelated app-code changes.

## Final Response Checklist

Report:

- Files created/updated.
- Summary of behavior or documentation changed.
- Tests added/updated, if any.
- Commands run.
- Quality gate results.
- Assumptions.
- Risks.
