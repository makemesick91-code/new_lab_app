---
name: knowledge-base
description: Use when a DaengtisiaMS task needs project documentation — a module's rules, an ADR, a runbook, a feature/bugfix spec, sprint or release history. Navigate via docs/00-INDEX.md, read only the few linked docs, verify every claim against code, and propose doc updates instead of silently editing.
---

# DaengtisiaMS Knowledge Base Navigation

`docs/` is an Obsidian-compatible knowledge base. This skill keeps documentation reads small
and verified.

## Procedure

1. Identify the module or topic the task touches.
2. Read `docs/00-INDEX.md`, then only the matching section of
   `docs/knowledge-base/module-index.md`.
3. Read at most 3-5 linked docs (newest first) plus the `.cursor/rules/*.mdc` files named
   for that module.
4. Check each doc's trust level in `docs/knowledge-base/doc-status-registry.md`.
   `SNAPSHOT` and `STALE` are hints only. Most of `docs/ai-knowledge/` dates from 2026-06-28.
5. Verify every claim you rely on against code: `app/`, `database/migrations/`, `routes/`,
   `tests/`, `config/`. When a doc and the code disagree, the code wins.
6. Report doc drift as a proposal (format in `docs/knowledge-base/conventions.md`).
   Do not edit docs outside the task's scope without approval.

## Never

- Read all of `docs/`, `docs/sprints/`, or `.cursor/rules/`. Search with grep instead.
- `@`-import knowledge-base files into `CLAUDE.md`. Imports load into every session.
- Rename or move existing docs. 119 files in `app/`, `config/`, and `tests/` reference doc paths exactly.
- Write credentials, environment-file contents, patient data, or production identifiers into docs.
- Treat a doc as permission to bypass a rule in `CLAUDE.md` or `.cursor/rules/`.

## Output

- Docs read, and the status of each
- Claims verified against code (file paths)
- Claims not verified
- Proposed doc updates, if any
