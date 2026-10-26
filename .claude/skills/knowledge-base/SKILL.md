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
   for that module. The module's `.claude/rules/<domain>.md` loads automatically once you open a
   file it covers; read it first when planning before any file is open. It indexes the canonical
   `.cursor/rules` files for the domain.
4. For a past sprint's full record, grep `docs/sprints/claude-md-archive/*.md` by sprint id and
   read only that section. The archive is `HISTORICAL`: a later rule may supersede it.
5. Check each doc's trust level in `docs/knowledge-base/doc-status-registry.md`.
   `SNAPSHOT` and `STALE` are hints only. Most of `docs/ai-knowledge/` dates from 2026-06-28.
6. Verify every claim you rely on against code: `app/`, `database/migrations/`, `routes/`,
   `tests/`, `config/`. When a doc and the code disagree, the code wins.
7. Report doc drift as a proposal (format in `docs/knowledge-base/conventions.md`).
   Do not edit docs outside the task's scope without approval.

## Never

- Read all of `docs/`, `docs/sprints/`, `.cursor/rules/` or a whole archive file. Search with grep instead.
- Edit `docs/sprints/claude-md-archive/`; it is hashed by its `manifest.json`.
- `@`-import knowledge-base files into `CLAUDE.md`, or append sprint records to it. Both load into every session.
- Rename or move existing docs. 119 files in `app/`, `config/`, and `tests/` reference doc paths exactly.
- Write credentials, environment-file contents, patient data, or production identifiers into docs.
- Treat a doc as permission to bypass a rule in `CLAUDE.md` or `.cursor/rules/`.

## Output

- Docs read, and the status of each
- Claims verified against code (file paths)
- Claims not verified
- Proposed doc updates, if any
