# ADR 0001 — SATUSEHAT Integration Readiness with a Controlled Submission Filter

- Status: Accepted (SATUSEHAT-1)
- Date: 2026-07-16

## Context

DaengtisiaMS needs to interoperate with SATUSEHAT (the Indonesian national health
data platform) in the future. Integrating a live external clinical API carries
real risks: accidental auto-send of unverified data, PII/secret leakage, coupling
that could roll back clinical/billing transactions, and mixing sandbox with
production. The clinic also requires that **not every treatment is sent** — sending
must be a deliberate, reviewed decision. Credentials and the official FHIR profile
validation are not yet available.

## Decision

Ship a **readiness foundation only** (SATUSEHAT-1) that prepares every integration
seam but performs **no external network call**:

1. A default-disabled gateway (`DisabledSatusehatGateway`) behind a
   `SatusehatGatewayInterface`, selected by `SATUSEHAT_ENABLED` (default `false`),
   failing closed on incomplete config. The real HTTP adapter is a placeholder
   for SATUSEHAT-2.
2. A **controlled submission filter**: a completed visit with a final medical
   record becomes an idempotent *candidate* (post-commit, never rolling back
   clinical/billing transactions). A readiness engine (16 hard gates) plus a
   deterministic source hash drive an explicit human review (approve/exclude) with
   server-side, branch-scoped authorization. There is no auto-send and no
   "Send All".
3. Versioned mapping governance and environment-scoped identifier governance
   (entered manually, no external lookup), keeping sandbox and production separate.
4. Privacy by construction: masked NIK, encrypted sensitive snapshots, an
   append-only PII-free audit trail, and no odontogram/scan/handwriting mapping.

## Consequences

- The app is ready to integrate without any risk of premature external calls.
- Clinical and billing flows are fully decoupled from SATUSEHAT outcomes.
- SATUSEHAT-2 can implement OAuth2 + FHIR submission behind the same interface and
  the existing batch/item/idempotency/dependency-order scaffolding, only after
  sandbox credentials and official profile validation.
- Diagnosis (Condition) is not emitted until structured diagnosis data exists; the
  preview marks it unsupported rather than fabricating a mapping.

## Alternatives considered

- **Direct integration now:** rejected — no credentials/profile validation, and
  it would risk auto-send and tight coupling.
- **Reusing `sys_audit_logs`:** a dedicated append-only `trx_satusehat_audit_logs`
  was chosen to keep a self-contained, PII-free SATUSEHAT trail.
