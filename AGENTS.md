# ADLMS Codex Agent Instructions

You are working on Asia Dental Lab Management System.

## Enabled Skill Modes

Always use these internal modes when relevant:

- context-mode
- claude-mem
- frontend-design
- skill-creator
- superpower

## context-mode

Before coding:
- Read docs/project_rules.md
- Read docs/system_architecture.md
- Read docs/inventory_rules.md
- Read docs/sprint_history.md
- Read docs/ai_bootstrap_prompt.md
- Understand current sprint status before modifying files.
- Do not break completed sprint contracts.

## claude-mem

Use these memory files as long-term project context:

- .cursor/memory/project.md
- .cursor/memory/architecture.md
- .cursor/memory/inventory.md
- .cursor/memory/sprint-history.md

If memory files are missing, infer only from docs and existing code. Do not invent facts.

## frontend-design

For UI, Blade, sidebar, layout, and dashboard work:
- Keep UI clean and operational-friendly.
- Do not show too many menus in sidebar.
- Preserve permission gates.
- Preserve mobile responsiveness.
- Prevent sidebar flicker or layout jump on refresh.
- Canonical sidebar file: resources/views/layouts/sidebar.blade.php

## skill-creator

When asked to create a new skill:
- Create reusable instructions.
- Store Cursor rules in .cursor/rules/
- Store memory in .cursor/memory/
- Store skill docs in .cursor/skills/
- Keep instructions specific to ADLMS.

## superpower

Before finalizing work:
- Inspect related files first.
- Make minimal safe changes.
- Add or update tests.
- Run quality gates when possible:
  - php artisan test
  - ./vendor/bin/pint
  - php artisan route:list
  - npm run build for frontend changes

## ADLMS Core Rules

- Laravel modular monolith.
- PostgreSQL.
- Multi-branch system.
- Use BranchContext when applicable.
- Never leak branch data.
- Inventory must be ledger-based.
- Do not add mutable stock columns.
- Stock = SUM(in) - SUM(out).
- Use Form Requests for validation.
- Use Policies for authorization.
- Use Services for business logic.
- Follow existing module conventions.

## Output Format

After every task, report:
- Files changed
- Tests added/updated
- Commands run
- Assumptions
- Risks

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

When the user types `/graphify`, invoke the `skill` tool with `skill: "graphify"` before doing anything else.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- Dirty graphify-out/ files are expected after hooks or incremental updates; dirty graph files are not a reason to skip graphify. Only skip graphify if the task is about stale or incorrect graph output, or the user explicitly says not to use it.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
