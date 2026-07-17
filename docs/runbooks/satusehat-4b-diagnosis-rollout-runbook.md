# SATUSEHAT-4B — Diagnosis Rollout & Terminology Governance Runbook

Operational SOP for the structured diagnosis rollout. Credential-independent:
nothing here enables an external SATUSEHAT request (SATUSEHAT-2 stays WATCH;
production stays blocked).

## Roles (RACI)

| Task | R | A | C/I |
|---|---|---|---|
| Add terminology draft | Supervisor RME (`manage_structured_diagnoses`) | Supervisor RME | Owner |
| Review/approve/activate/deprecate terminology | Reviewer (`review_clinical_terminology`, ≠ creator/submitter) | Supervisor RME | Owner |
| Configure branch rollout mode | Supervisor RME (`configure_diagnosis_rollout`) | Owner (business approval) | Doctors of that branch |
| Record diagnoses / primary swap | Doctor | Doctor | Supervisor RME |
| Emergency override | Doctor / Supervisor RME (`override_diagnosis_requirement`) | Supervisor RME | Clinical review queue |
| Monitor adoption | Owner / Supervisor RME (`view_diagnosis_adoption`) | Owner | — |

## Phased rollout SOP

1. **Baseline** — run `php artisan satusehat:diagnosis-adoption-audit --json`
   and store the output (per-branch adoption baseline).
2. **Informational (default)** — no action needed; all branches start here.
   Watch the adoption dashboard (`/rme/satusehat/adoption`) for 1–2 weeks.
3. **Warning** — for a candidate pilot branch, set mode `warning` on
   `/rme/satusehat/rollout` with a written reason. Doctors see a finalization
   warning; nothing blocks. Watch `structured_diagnosis` open issues.
4. **Pilot enforced** — ONLY after explicit owner approval for that branch:
   set `pilot_enforced` with the approval reference in the reason. Finalization
   now requires an ACTIVE primary diagnosis; the reasoned override stays
   available so patient care is never blocked.
5. **Review overrides weekly** — every override is in the audit log
   (`diagnosis_override_granted`) and the record's missing-diagnosis issue
   stays open until remediated.

There is NO global enforcement switch. Never ask for one.

## Terminology change SOP

- New code: create draft (official source + version required for activation) →
  submit review → a DIFFERENT reviewer approves → activate.
- Wrong active code: deprecate with an ACTIVE replacement; never edit in
  place; historical records stay readable; affected records surface as
  `deprecated_diagnosis_selected` issues for re-coding.
- Run `php artisan satusehat:terminology-audit --strict` after any batch of
  terminology work (exit 2 = governance anomaly to fix before GO/deploy).

## Incident playbook

| Symptom | Action |
|---|---|
| Doctors blocked unexpectedly at finalize | `satusehat:diagnosis-rollout-status` — if the branch is `pilot_enforced` unintentionally, set it back to `informational` (reasoned, audited). No deploy needed. |
| Wrong terminology activated | Deprecate with replacement; verify with `satusehat:terminology-audit --strict`; affected candidates auto-drift and return to review. |
| Candidate approvals revoked after deploy | Expected one-time facts-shape drift for candidates that already had diagnoses — re-review and re-approve. |
| Override abuse suspected | Audit log filter `diagnosis_override_granted` per branch/user; overrides are append-only evidence. |

## Rollback (non-destructive)

1. Set every configured branch back to `informational` (UI or DB row update —
   audited). This alone removes all enforcement.
2. If code rollback is required, roll back to the previous GO tag via
   `scripts/rollback-vps.sh` — diagnosis rows, overrides, terminology history,
   and audit logs are all preserved; never `migrate:fresh`/`db:wipe`, never
   roll back migrations by default.
