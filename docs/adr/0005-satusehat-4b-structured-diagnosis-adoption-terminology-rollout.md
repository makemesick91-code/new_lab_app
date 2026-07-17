# ADR 0005 — SATUSEHAT-4B: Structured Diagnosis Adoption & Clinical Terminology Rollout

- Status: Accepted (2026-07-17)
- Context sprint: SATUSEHAT-4B
- Builds on: ADR 0001 (SATUSEHAT readiness), ADR 0003 (dental use-case), ADR 0004 (4A operational readiness)

## Context

SATUSEHAT-4A shipped the structured diagnosis foundation
(`mst_clinical_diagnoses`, `trx_medical_record_diagnoses`) but adoption was
optional and ungoverned: any manager could create ACTIVE terminology directly,
no branch could require a diagnosis before finalization, adoption was
unmeasured, and the local Condition preview was still a placeholder.

## Decisions

1. **Reuse the 4A tables — no parallel diagnosis subsystem.** The lifecycle is
   additive columns on `mst_clinical_diagnoses`; the issue engine is the 4A
   fingerprint-idempotent engine with three added rules.
2. **Terminology lifecycle with separation of duties.**
   `draft → under_review → approved → active → deprecated/rejected`; approval
   and activation need `review_clinical_terminology`, an official source, and
   an approver different from the creator/submitter. Active terminology is
   immutable; corrections deprecate with an ACTIVE replacement pointer.
   Transitions are row-locked (TOCTOU-safe).
3. **Branch-scoped phased rollout, never global.** Modes
   `disabled|informational|warning|pilot_enforced` per branch
   (`mst_diagnosis_rollout_settings`); the config default must be non-blocking
   and a blocking default is refused at runtime. Enforcement lives in
   `MedicalRecordService::finalize()` (the only finalize path) — clinical flow
   on non-pilot branches is untouched.
4. **Emergency override as a first-class audited record**, not a permission
   bypass: reasoned, time-boxed, append-only, pilot-branches-only, never makes
   SATUSEHAT readiness ready.
5. **Terminology state joins the clinical fingerprint.** Diagnosis facts now
   hash `master_status` + mapping system/display, so post-approval terminology
   deprecation or mapping changes revoke the approval. Accepted trade-off: a
   one-time drift for candidates that already carry structured diagnoses
   (≈ zero on the pilot at ship time).
6. **Condition preview only from reviewed mappings.** One local Condition per
   structured diagnosis; role is carried as local context because no official
   rank extension has been human-verified (no guessed profile capability).
7. **Adoption analytics are operational quality indicators**, PII-free,
   branch-scope-validated server-side, with null (N/A) rates on zero
   denominators — explicitly not a punitive doctor ranking.

## Consequences

- Doctors on informational/warning branches see indicators but are never
  blocked; a pilot branch can be turned on only deliberately, per branch, with
  a reasoned audited change.
- Terminology can no longer become selectable without official source +
  independent clinical review.
- SATUSEHAT-2 remains WATCH; no external request path was added; production
  stays blocked by the SATUSEHAT-3 guard.
