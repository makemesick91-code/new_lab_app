# ADR 0006 — SATUSEHAT-4C Branch Readiness Remediation & Internal Pilot Operations

- **Status:** Accepted (internal readiness only)
- **Date:** 2026-07-18
- **Supersedes:** none · **Builds on:** ADR 0004 (SATUSEHAT-4A), ADR 0005 (SATUSEHAT-4B)

## Context

DaengtisiaMS needs an operator-usable way to make a clinic branch **internally** ready for a future SATUSEHAT pilot — profile readiness, remediate data-quality issues, select/approve/suspend an internal pilot branch, measure readiness KPIs, and rehearse — **without any SATUSEHAT credential and without any external request**. SATUSEHAT-2 remains WATCH; production remains blocked.

## Decision

1. **Compose, do not duplicate.** Branch readiness is a thin layer over the shipped SATUSEHAT-4A operational readiness / data-quality engine and SATUSEHAT-4B diagnosis adoption / rollout. No parallel readiness/issue/diagnosis engine is created.
2. **Internal vs external is explicit.** The eligibility gate separates internal gates from external-posture gates. Passing all internal gates yields `blocked_external_credential` (internally ready, externally blocked) — never `externally_ready`. `internal_ready` is the internal-only verdict; the external blocker is always surfaced.
3. **Deterministic, versioned readiness score.** A transparent weight-normalized average of component rates; null-rate components are excluded (N/A, never a fabricated 0); any hard blocker caps the score and prevents `pilot_ready_internal`.
4. **Branch-scoped, least-privilege, IDOR-safe.** Scope resolves server-side from `BranchService::rmeEnabledIds()` (MAIN excluded). Executive tiers see all RME branches; Admin Klinik is branch-pinned. A request `branch_id` can only narrow within scope. Six least-privilege permissions; no role gains external send / production activation.
5. **Additive schema only.** New profile + rehearsal tables and additive issue SLA/escalation columns. Nullable/default-safe; no destructive migration; never `migrate:fresh`/`db:wipe`.
6. **Credential-independent rehearsal.** Reuses the SATUSEHAT-4A marker-scoped synthetic pack; network-silent; terminal result is honestly `PILOT_READY_INTERNAL` or `BLOCKED_EXTERNAL_CREDENTIAL`.

## Consequences

- Operators can drive a branch from profiling → remediation → internal pilot approval and measure it, with a full audit trail (append-only, PII-free).
- Nothing here changes payment / visit-completion / inventory-ledger behavior, and nothing enables external submission or production.
- External GO still requires the SATUSEHAT-2 External Credential Closure Campaign — SATUSEHAT-4C never asserts external readiness.

## Alternatives rejected

- A single global readiness switch (rejected — branch isolation is mandatory; no global enforcement).
- An opaque/ML readiness score (rejected — the score must be deterministic, versioned, and auditable).
- Direct `LabOrder`-style external candidate creation from readiness (rejected — out of scope; external stays disabled).
