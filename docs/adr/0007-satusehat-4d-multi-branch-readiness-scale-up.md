# ADR 0007 — SATUSEHAT-4D Multi-Branch Readiness Scale-Up & Operational Governance

Status: Accepted (internal governance only; SATUSEHAT-2 stays WATCH).
Date: 2026-07-17.

## Context

SATUSEHAT-4C delivered single-branch internal readiness + pilot operations. To
operate readiness consistently across all RME-enabled branches, we need governed
rollout waves, comparative readiness, promotion control, change control, and —
critically — a real human operator UAT before any operational GO. External
integration remains blocked (no SATUSEHAT sandbox credentials; SATUSEHAT-2 WATCH).

## Decision

1. **Extend, don't duplicate.** Build on the 4C `Services/Pilot/` readiness,
   eligibility, score, issue-SLA, and rehearsal engines. No parallel readiness or
   issue subsystem.
2. **Credential-independent.** No config key, wave, promotion, change request, or
   rehearsal may enable external send or production. Every rehearsal ends at
   `BLOCKED_EXTERNAL_CREDENTIAL`; the matrix always shows the external blocker.
3. **Additive migrations only.** 10 additive migrations; append-only history
   tables; a self-healing re-assert of the 4C partial index (SQLite rebuild had
   flattened it — a latent multi-branch defect).
4. **Human UAT is the GO gate.** Automated tests never substitute. A UAT run is
   `signed_off` only when all six required roles approve and no scenario failed.
   No GO tag until UAT is completed and signed off; the WATCH state is honest.
5. **Least-privilege RBAC + server-side branch scope.** 7 new permissions; all
   reads/writes scoped via `SatusehatWorkspaceScope` (IDOR boundary); MAIN
   excluded; bulk ops bounded; change-control separation of duties; production/
   credential change categories un-approvable.

## Consequences

- Multi-branch readiness is operable, auditable, and privacy-safe now, at pilot
  scale, without touching external integration.
- Per-branch eligibility is O(branches) service calls — acceptable for the
  small RME-enabled set; a future batched engine would be needed for national scale.
- External GO remains gated behind the separate SATUSEHAT-2 Credential Closure
  Campaign.

## Alternatives rejected

- A new standalone multi-branch engine (violates "no parallel subsystem", risks
  drift from the canonical readiness verdict).
- Auto-promoting branches by score (rejected — hard blockers must never be
  score-overridable; promotion stays an explicit, gated, audited action).
- Simulating/fabricating human UAT to reach GO (explicitly forbidden).
