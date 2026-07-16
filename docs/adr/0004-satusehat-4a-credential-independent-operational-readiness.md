# ADR 0004 — SATUSEHAT-4A: Credential-Independent Operational Readiness & Data Quality Closure

Date: 2026-07-17 · Status: Accepted

## Context

SATUSEHAT-1 shipped the controlled candidate/review foundation; SATUSEHAT-2
shipped the sandbox adapter but remains **WATCH** (no credentials → no live
verification, GO tag absent); SATUSEHAT-3 shipped the internal dental use-case.
The remaining internal gaps before any external campaign: no structured
diagnosis source, no systematic data-quality remediation, no per-branch
operational readiness view, and no rehearsable end-to-end pipeline proof.

## Decision

1. **Reuse the canonical readiness engine, never duplicate it.** The
   data-quality rule engine consumes the candidate's stored readiness verdict
   (refreshed via `SatusehatCandidateService::refresh`) plus direct validity
   checks; readiness truth stays in `SatusehatReadinessService`.
2. **Deterministic, fingerprint-idempotent issues** in an additive
   `trx_satusehat_data_quality_issues` table; auto-resolve on revalidation;
   resolve-by-revalidation only; hard issues unwaivable; waivers are triage
   only and never alter readiness.
3. **Structured diagnosis as clinical master + explicit entry**
   (`mst_clinical_diagnoses`, `trx_medical_record_diagnoses`), separate from
   SATUSEHAT mapping governance. Never auto-coded from free text; legacy
   records never backfilled. Readiness facts include `diagnoses` **only when
   non-empty** so legacy candidate hashes stay byte-stable, while any
   post-approval diagnosis change drifts the hash and revokes the approval.
4. **One operational status per candidate** via
   `SatusehatOperationalStatusResolver`: internal gaps outrank the external
   wall; IHS identifier gaps are always classified external
   (`BLOCKED_EXTERNAL_CREDENTIAL`) — remote ids are never fabricated. A visit
   without an odontogram is dental-informational, not blocking (no dental
   Observation is emitted for it).
5. **Synthetic rehearsal isolated by branch** (`SYN4A` + explicit markers,
   synthetic-only mappings keyed to synthetic entity ids, no factories —
   VPS runs `--no-dev`); reset removes only campaign records. The rehearsal
   stops honestly at `BLOCKED_EXTERNAL_CREDENTIAL` and never reports
   submitted/succeeded.
6. **Least-privilege RBAC**: only 4 new permissions actually gate surfaces
   (`view_satusehat_readiness`, `manage_satusehat_remediation`,
   `manage_satusehat_readiness_waivers`, `manage_structured_diagnoses`);
   candidate-permission set unchanged; Doctor/Kasir/Admin Lab gain nothing.

## Consequences

- The clinic can drive every internal readiness item to done before any
  credential exists; the external wall is visible, honest, and filterable.
- SATUSEHAT-2's WATCH semantics and the SATUSEHAT-3 production guard are
  untouched invariants, re-asserted by tests.
- Future SATUSEHAT-2 credential closure consumes this workspace as-is: once
  identifiers verify externally, candidates flip from
  BLOCKED_EXTERNAL_CREDENTIAL to READY_INTERNAL→approve→prepare without new
  code.
