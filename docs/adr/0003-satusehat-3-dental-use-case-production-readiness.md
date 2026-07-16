# ADR 0003 — SATUSEHAT-3: Dental Use-Case Expansion & Production Readiness

- Status: Accepted (2026-07-16)
- Supersedes: none. Builds on ADR 0001 (readiness foundation) + ADR 0002 (sandbox adapter, WATCH).

## Context

SATUSEHAT-1 shipped the controlled-submission readiness foundation; SATUSEHAT-2 shipped a
real sandbox OAuth+FHIR adapter but stays **WATCH** (no sandbox credentials → no live
round-trip → no GO tag). We need to expand the dental (odontogram) use case to the official
SATUSEHAT "Rawat Jalan Gigi" profile and stand up production-readiness governance — **without
any credential, external call, or change to SATUSEHAT-2's WATCH status**.

## Decision

1. **Credential-independent dental expansion.** Model odontogram data as local FHIR
   Observations using only official codes stored in the versioned mapping table. No network,
   no credential, hermetic tests.
2. **Official-source terminology governance.** Add `profile_family` + provenance columns; a
   dental mapping activates only after an official source citation + human verification. Seed
   the official codes as DRAFT (nothing auto-activated).
3. **Honest coverage matrix.** Each dental variable is classified `supported` …
   `unsupported_local_schema`. Variables the local schema can't support (structured diagnosis,
   prognosis) are reported blocked — never faked.
4. **Separate dental readiness axis + source hash** on the candidate; dental drift after
   approval revokes the approval.
5. **Permanent production activation guard.** Production can only ever activate with a
   SATUSEHAT-2 GO + credentials + explicit approval + production env. On SATUSEHAT-3 it is
   always blocked, asserted by test. Production activation is a separate future sprint.

## Consequences

- DaengtisiaMS can preview + locally validate a dental FHIR bundle and govern its terminology,
  ready for a future credentialed submission — with zero external exposure now.
- SATUSEHAT-2 remains WATCH; its GO tag remains absent; external submission stays disabled.
- Local conformance is explicitly **not** SATUSEHAT acceptance.

## Alternatives rejected

- Auto-activating the seeded dental codes (rejected: unverified clinical codes must not go
  active).
- Embedding the odontogram image / handwriting (rejected: out of official scope + PII risk).
- A parallel dental readiness/mapping subsystem (rejected: extend the existing engines).
