---
kb_status: historical
kb_verified_against: 53d76643
kb_updated: 2026-10-26
---
# CLAUDE.md archive — Global instructions (pre-optimization verbatim)

> **HISTORICAL — not loaded into any Claude Code session.** These are the verbatim sprint
> records that lived in `CLAUDE.md` until commit `53d76643`. They were moved here,
> unchanged, by DEV-AI-CLAUDE-MEMORY-OPTIMIZATION-1. Do not edit them: record corrections
> in a newer document. Current rules live in `CLAUDE.md`, `.claude/rules/global.md` and
> `.cursor/rules/`. Code, migrations, tests and config remain the authority.
> Integrity: every section below is hashed in [manifest.json](manifest.json).

## Foundation-First Sprint Lock

Status: **FOUNDATION-FIRST LOCK ACTIVE**. All non-foundation work is POST-FOUNDATION BACKLOG and blocked until FOUNDATION GO. Read `docs/architecture/foundation-first-sprint-lock-governance.md` before planning or implementing sprint work.

## Developer Knowledge Base (Obsidian vault: `docs/`)

DEV-AI-OBSIDIAN-KNOWLEDGE-BASE-1 (2026-10-25). When a task needs documentation, start at `docs/00-INDEX.md` and read it **on demand**. It is deliberately **not** `@`-imported: imports load into every session, and this file is already ~1.56 MB.
- Module → docs map: `docs/knowledge-base/module-index.md`. Trust levels and measured drift: `docs/knowledge-base/doc-status-registry.md` (most of `docs/ai-knowledge/` is a 2026-06-28 snapshot, and several of those files are STALE).
- Code, migrations, routes, tests and config remain the source of truth. Verify a doc against them before relying on it.
- Doc drift found during a task is **reported as a proposal** (format in `docs/knowledge-base/conventions.md`), never edited silently outside the task's scope. Never rename or move existing docs: 119 code/config/test files reference doc paths exactly.
- Procedure: project skill `.claude/skills/knowledge-base/SKILL.md`.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
