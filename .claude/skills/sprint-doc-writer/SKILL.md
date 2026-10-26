---
name: sprint-doc-writer
description: Use when recording a DaengtisiaMS sprint, fix or ops closure — its sprint doc, durable rule and domain rule index line. Never append sprint records to CLAUDE.md.
---

# Sprint Documentation Writer

Where a sprint's record goes (DEV-AI-CLAUDE-MEMORY-OPTIMIZATION-1, 2026-10-26):

| What | Where |
|---|---|
| The full closure record: status, scope, files, decisions, tests, CI run, deploy evidence, GO tag, limits | `docs/sprints/<sprint-id-in-lowercase>.md` (or the existing doc for that sprint) |
| Each durable rule the sprint establishes | `.cursor/rules/NNN-<topic>.mdc` (next free number; mark what it supersedes) |
| One index line pointing at that rule | the matching `.claude/rules/<domain>.md`: add an invariant (`AIR-<PFX>-NN`, next number) or a row in its canonical-rules table |
| The release itself | the annotated GO tag; `docs/knowledge-base/release-history.md` derives history from tags, so add nothing there unless one of its listed sources changes |

Never append sprint records to CLAUDE.md. It loads into every session, and the 240 records it once held
(1.56 MB, ~659K tokens, ~$6.59 before any work) now live verbatim in `docs/sprints/claude-md-archive/`.
Edit `CLAUDE.md` only when a critical global rule (CG-NN) itself changes.
`tests/Feature/Foundation/DevflowAiInstructionSystemTest.php` fails the critical CI gate if the file regrows.

Never edit `docs/sprints/claude-md-archive/`: it is historical and hashed by its `manifest.json`. Record a
correction in the new sprint doc and cite the archived record by sprint id.

## Text that fails CI

- Never write the literal environment-file name in rule, governance or evidence text; write "the
  environment file". The release-evidence scan bans the literal.
- A runbook line that names a forbidden production command (a REPL, a destructive migration, a wipe) must
  carry this repository's `-- never` marker, or `ProductionShellCommandGuardTest` fails.
- No production identifiers in docs or rules: no user, device, invoice or case ids, no personal names, no
  secrets, no patient data. Business codes (branch codes, permission and class names) are fine.

## Sprint doc format

- Sprint id, title, date, status (GO / WATCH / NO-GO, stated honestly)
- Base and authority SHAs, branch, PR, merge SHA, GO tag
- Scope: what changed and what deliberately did not
- Decisions and the owner approvals behind them
- Tests and quality gates, with real counts (skipped is not passed)
- Deploy and production verification evidence
- Durable rules created or superseded
- Risks, residuals and follow-ups

Update only the relevant section of an existing doc. Do not rewrite another sprint's history.
