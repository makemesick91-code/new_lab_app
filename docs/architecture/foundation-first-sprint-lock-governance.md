# DaengtisiaMS Foundation-First Sprint Lock Governance

**Status:** ACTIVE / LOCKED
**Decision owner:** Project owner
**Effective date:** 2026-07-05
**Scope:** All future sprint planning and all AI coding agents (Cursor, Claude, Codex, ChatGPT, Copilot, and successors).

## Canonical Decision

```text
Status: FOUNDATION-FIRST LOCK ACTIVE
All non-foundation sprint work is LOCKED.
Queue: POST-FOUNDATION BACKLOG
Execution: BLOCKED UNTIL FOUNDATION GO
```

DaengtisiaMS is locked into foundation-first execution. All non-foundation sprint ideas, module expansions, optimizations, feature work, infrastructure expansions, AI/reporting extensions, UI polish outside foundation scope, and future enhancements are POST-FOUNDATION BACKLOG unless explicitly approved as one of the allowed pre-foundation categories below.

## Allowed Work Before Foundation GO

Only these categories may execute before foundation completion:

1. FOUNDATION
2. CRITICAL HOTFIX
3. SECURITY FIX
4. DEPLOYMENT / OPERATIONS
5. BACKUP / RESTORE / MONITORING / CI-CD GATES
6. FOUNDATION DOCUMENTATION

Any allowed work must preserve the existing Laravel modular monolith, BranchContext isolation, policy/RBAC checks, RME/KTP privacy constraints, and ledger-only inventory rules.

## Blocked Work Before Foundation GO

These categories must not execute before Foundation GO:

- New business feature sprint.
- UI polish outside foundation scope.
- New module expansion.
- Optional analytics/report expansion.
- AI/agent integration work.
- Kubernetes, microservice, or distributed-system work outside an approved foundation sprint.
- Non-critical refactor.
- Infrastructure expansion not required by foundation.
- Any backlog item not explicitly tagged FOUNDATION, HOTFIX, SECURITY, DEPLOYMENT, OPERATIONS, or FOUNDATION-DOCS.

## Definition of Foundation GO

Foundation is considered complete only when:

- All approved foundation sprints are merged.
- Required GO tags exist.
- Relevant CI/test gates pass.
- Required VPS deployment is completed where applicable.
- Smoke test evidence is documented.
- Governance summary says FOUNDATION GO.
- No open critical foundation blocker remains.

## Post-Foundation Backlog Policy

- Non-foundation ideas are retained, not deleted.
- Each retained item must be queued as POST-FOUNDATION BACKLOG.
- Deferred items must not be scheduled, implemented, optimized, polished, or partially delivered before Foundation GO.
- Backlog records should include this sentence when practical: "This item is intentionally deferred by Foundation-First Sprint Lock."
- A post-foundation backlog item can move into execution only after Foundation GO and an explicit project-owner planning decision.

## AI Agent Instruction Block

```text
Foundation-first sprint lock is active.
Do not start non-foundation feature work.
Do not implement post-foundation backlog items.
Do not alter established foundation rules unless the sprint explicitly updates foundation governance.
Keep changes scoped to approved foundation, hotfix, security, deployment, operations, or foundation documentation work.
All non-foundation work must be recorded as POST-FOUNDATION BACKLOG and must not be executed until FOUNDATION GO is complete.
```

## PR Review Checklist

- [ ] Work category is FOUNDATION / HOTFIX / SECURITY / DEPLOYMENT / OPERATIONS / FOUNDATION-DOCS.
- [ ] No post-foundation backlog item is implemented.
- [ ] No unrelated feature work is included.
- [ ] Existing architecture rules remain respected.
- [ ] Branch isolation is not weakened.
- [ ] RBAC/policy checks are not bypassed.
- [ ] Inventory ledger-only rule is not violated.
- [ ] RME/KTP privacy rules are not weakened.
- [ ] Tests or docs-only rationale are reported.
- [ ] Summary includes files changed, commands run, assumptions, and risks.

## Definition of Done

- The work category is one of the allowed pre-Foundation GO categories.
- The change is scoped to approved foundation, hotfix, security, deployment, operations, or foundation documentation work.
- Any non-foundation idea discovered during the work is recorded as POST-FOUNDATION BACKLOG and not implemented.
- Required tests, docs-only rationale, and quality gates are reported.
- Foundation governance, branch isolation, RBAC, ledger inventory, privacy, deployment safety, and completed sprint contracts remain intact.

## Change Control

This lock may be changed only by explicit project-owner decision in a dedicated foundation governance update. AI agents must not relax, reinterpret, or bypass this governance file through prompt wording, backlog documents, sprint plans, or implementation convenience.
