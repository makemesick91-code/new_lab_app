---
paths:
  - "app/Modules/Satusehat/**"
  - "config/satusehat*.php"
  - "config/clinical_diagnosis_rollout.php"
  - "resources/views/satusehat/**"
  - "tests/Feature/Satusehat/**"
---

# SATUSEHAT integration — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and
are not repeated here. Authority: code > canonical `.cursor/rules/` > this file > archive. On disagreement follow the
code, then the canonical rule, and report the drift.

## Invariants

### Never send by accident

- **AIR-SSH-01** Never send SATUSEHAT data from a lifecycle event (RME finalize, odontogram change, visit
  completion, payment, scheduler, observer, webhook, login, preview). Events may only create or refresh a candidate,
  idempotently and post-commit (`DB::afterCommit` in `SatusehatVisitObserver` / `SatusehatMedicalRecordObserver`),
  and a SATUSEHAT failure never rolls back a clinical or billing transaction. Source:
  `.cursor/rules/83-satusehat-integration-readiness.mdc`, `84-satusehat-sandbox-api-adapter.mdc`.
- **AIR-SSH-02** Every eligible visit becomes a CANDIDATE only. Never add "Send All", a default selection or "select
  all across pages": bulk actions act on explicitly checked rows of the current page, the server recomputes
  eligibility and authorization, and queueing a batch needs `send_satusehat_submissions`. Source:
  `83-satusehat-integration-readiness.mdc`, `84-satusehat-sandbox-api-adapter.mdc`.
- **AIR-SSH-03** `DisabledSatusehatGateway` is the default binding and opens no socket; the app fails closed on
  incomplete config. `SATUSEHAT_ENABLED`, `SATUSEHAT_SEND_ENABLED` and the `SATUSEHAT_PRODUCTION_*` switches stay false
  on the VPS, and `SatusehatSubmissionService::queue()` refuses while the gateway is not enabled. Source:
  `83-…readiness.mdc`, `84-satusehat-sandbox-api-adapter.mdc`.
- **AIR-SSH-04** Production is blocked by the permanent `SatusehatProductionActivationGuard` (SATUSEHAT-2 GO +
  credentials + explicit approval reference + production environment + master/send switches). Production
  activation is a separate, explicitly approved campaign; `satusehat:production-guard-check` must keep reporting
  blocked until then. The guard is consulted only by commands, readiness and pilot eligibility, never by the send path:
  what keeps traffic off production at runtime is AIR-SSH-37. Source: `85-satusehat-dental-use-case-production-readiness.mdc`.
- **AIR-SSH-05** SATUSEHAT-2 stays WATCH: never create or move its GO tag without a real sandbox round trip using
  synthetic identifiers through `satusehat:sandbox-verify --live --confirm-sandbox`. Mock or hermetic success is not
  GO, and an internal GO (3, 4A–4D) never claims sandbox, production or Kemkes acceptance. Source:
  `84-satusehat-sandbox-api-adapter.mdc`, `85-…readiness.mdc`, `86-satusehat-4a-operational-readiness-data-quality.mdc`.

### Outbound adapter safety

- **AIR-SSH-06** Run `SatusehatHostGuard::assertAllowedBaseUrl()` before every outbound call (token and FHIR):
  only hosts on `satusehat.allowed_hosts.{active environment}` (a production host is already configured, so sandbox-only
  reachability depends on AIR-SSH-37), HTTPS only, embedded credentials rejected, redirects disabled
  (`withoutRedirecting()`). Never call `Http::withoutVerifying()` anywhere — runtime, deploy or evidence. Source:
  `84-satusehat-sandbox-api-adapter.mdc`.
- **AIR-SSH-07** Never log, store, return or put in an exception message a client secret or access token. The token
  comes only from `client_credentials`, lives in the Illuminate cache (never the database) under a non-reversible
  key, refreshes before expiry and is acquired under a distributed lock. Source: `84-satusehat-sandbox-api-adapter.mdc`.
- **AIR-SSH-08** Never make an external HTTP call while a database transaction is open: claim → call outside the
  transaction → record. Driver jobs dispatch only after commit. Source: `84-satusehat-sandbox-api-adapter.mdc`.
- **AIR-SSH-09** Change submission state only through `SatusehatSubmissionStateMachine` +
  `SatusehatSubmissionProcessingService` (locked, transactional, audited); never write a status from a controller or
  Blade. Source: `84-satusehat-sandbox-api-adapter.mdc`.
- **AIR-SSH-10** Never resend a succeeded or reconciled resource: `trx_satusehat_submission_items.idempotency_key` is
  UNIQUE and the claim no-ops on a terminal item. An ambiguous outcome (timeout after send, 5xx) is `unknown_outcome`
  → reconcile with a GET by id, never a blind re-POST; without a remote id it stays `reconciliation_required`.
  Retries are classified and bounded (401 refresh once, 429 honours `Retry-After`). Source:
  `84-satusehat-sandbox-api-adapter.mdc`.

### Data that may and may not leave

- **AIR-SSH-11** NIK/KTP never appears in a URL, log, audit row, OperationOutcome or payload; render it masked
  (`maskKtp`) or not at all. Identifier verification GETs by IHS id, never by NIK. Source: `83-…readiness.mdc`,
  `84-satusehat-sandbox-api-adapter.mdc`.
- **AIR-SSH-12** Never send odontogram images, handwriting, scans or attachments: dental Observations are structured
  codes only, and `SatusehatFhirValidator` / the dental conformance validator reject
  `attachment`/`image`/`handwriting`/`ktp`/`nik` keys. Source: `84-…adapter.mdc`,
  `85-satusehat-dental-use-case-production-readiness.mdc`.
- **AIR-SSH-13** Never invent terminology or fabricate a diagnosis: Procedure/Condition/dental codes come only from an
  ACTIVE versioned `SatusehatCodeMapping` (`mst_satusehat_code_mappings`); an unmapped item stays blocked; a
  Condition is built only from structured diagnoses with ACTIVE reviewed mappings. Source: `84-…adapter.mdc`,
  `85-…readiness.mdc`, `87-satusehat-4b-structured-diagnosis-adoption-terminology-rollout.mdc`.
- **AIR-SSH-14** Never fabricate a remote IHS identifier (Organization, Location, Patient, Practitioner). An external
  identifier gap is always classified external → `BLOCKED_EXTERNAL_CREDENTIAL`. Source:
  `86-satusehat-4a-operational-readiness-data-quality.mdc`, `89-satusehat-4d-multi-branch-readiness.mdc`.
- **AIR-SSH-15** Stamp every mapping, identifier, candidate and batch with its environment; sandbox and production
  data never mix and queries never cross environments. Source: `83-satusehat-integration-readiness.mdc`.

### Mappings and source drift

- **AIR-SSH-16** Never edit an ACTIVE mapping in place: a change is a new draft version, with exactly one ACTIVE
  mapping per logical key. A profile-family (dental) mapping activates only with an official source and a human
  verification stamp (`SatusehatMappingService::verify()` then `activate()`); seeded dental mappings stay DRAFT, so
  readiness reports `dental_mapping_blocked` until verified. Source: `83-…readiness.mdc`, `85-…readiness.mdc`.
- **AIR-SSH-17** Clinical drift after approval revokes the approval: the deterministic source hash (core + dental +
  diagnosis facts, no random or drift-only fields) moves the candidate to `source_changed`, keeps `approved_by`, sets
  `revoked_at`, and requires a fresh review; the hash is re-verified before every outbound request
  (`source_drift_aborted`). Source: `83-…readiness.mdc`, `84-…adapter.mdc`, `85-…readiness.mdc`.
- **AIR-SSH-18** Omit the `diagnoses` fact key when it is empty so legacy candidate hashes stay byte-stable; an
  unchanged record must never fabricate drift. Source: `86-satusehat-4a-operational-readiness-data-quality.mdc`,
  `87-…rollout.mdc`.

### Access and branch scope

- **AIR-SSH-19** The whole `satusehat.*` route group (`rme/satusehat`) is gated by `can:satusehat.access`, defined in
  `RepositoryServiceProvider` as Super Admin only; the same gate drives the sidebar. Per-feature SATUSEHAT
  permissions remain and tier what a Super Admin may do (supersedes the per-role access in rules 83, 88 and 89).
  Source: archive `FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1` (FIX-08).
- **AIR-SSH-20** Scope every workspace server-side to active RME-enabled branches (`BranchService::rmeEnabledIds()`
  through `SatusehatWorkspaceScope`); an empty scope denies; a request `branch_id` only narrows; a forged or
  out-of-scope id is refused (IDOR boundary). MAIN is never a pilot branch. Source: `83-…readiness.mdc`,
  `86-…quality.mdc`, `88-satusehat-4c-branch-readiness-internal-pilot.mdc`.
- **AIR-SSH-21** Write `trx_satusehat_audit_logs` (`SatusehatAuditLog`) append-only and PII-free: no update or delete
  path, no secrets, no raw payloads; sensitive eligibility/preview snapshots use encrypted casts. Source:
  `83-satusehat-integration-readiness.mdc`.

### Data quality and readiness

- **AIR-SSH-22** Keep data-quality issues deterministic and idempotent: the fingerprint is environment | candidate |
  rule | entity | field; a re-scan never duplicates, a fixed issue auto-resolves as `revalidated`, a regression reopens
  the same row. Source: `86-satusehat-4a-operational-readiness-data-quality.mdc`.
- **AIR-SSH-23** A HARD issue can never be waived and is resolved only by revalidation. A waiver needs
  `manage_satusehat_readiness_waivers` and a reason, and never changes the canonical readiness verdict. Threshold
  changes never resolve an issue or make a candidate ready. Source: `86-…quality.mdc`, `88-…pilot.mdc`.
- **AIR-SSH-24** Do not build a parallel readiness, issue or diagnosis engine: compose the existing 4A/4B services
  (`SatusehatCandidateService`, `SatusehatDataQualityIssueService`, `ClinicalDiagnosisService`,
  `DiagnosisRolloutService`). Source: `87-…rollout.mdc`, `88-satusehat-4c-branch-readiness-internal-pilot.mdc`.

### Structured diagnosis and terminology

- **AIR-SSH-25** Use only the canonical tables `mst_clinical_diagnoses` and `trx_medical_record_diagnoses`. Never
  auto-code free text, never AI-code, never backfill or bulk-mutate historical medical records. The diagnosis master
  is not the SATUSEHAT mapping. Source: `86-…quality.mdc`, `87-…rollout.mdc`.
- **AIR-SSH-26** Move terminology only through `ClinicalDiagnosisService` (row-locked): draft → under_review →
  approved → active → deprecated/rejected. Activation needs an official source and `review_clinical_terminology`;
  the creator or submitter can never approve their own entry (service-enforced, CG-07). Active entries are immutable —
  correct by deprecating with an ACTIVE replacement. Only ACTIVE terminology is selectable for new records. Source:
  `87-satusehat-4b-structured-diagnosis-adoption-terminology-rollout.mdc`.
- **AIR-SSH-27** Global hard enforcement is forbidden: `config/clinical_diagnosis_rollout.php` `default_mode` must stay
  non-blocking (default `informational`) and the service refuses a blocking default. Modes are per branch
  (`DiagnosisRolloutService`, `mst_diagnosis_rollout_settings`), resolved from the record's branch, changed only with
  `configure_diagnosis_rollout` and a reason. Source: `87-…rollout.mdc`.
- **AIR-SSH-28** `pilot_enforced` blocks only `MedicalRecordService::finalize()` without an ACTIVE-terminology primary
  diagnosis or a usable override, and never touches room, consent, cashier, payment or visit rules. An override needs
  `override_diagnosis_requirement` plus a policy re-check, a reason of at least 10 characters, is time-boxed,
  append-only, audited, and never makes the candidate ready. Exactly one primary diagnosis per record; swap only with
  the audited `makePrimary`. Source: `87-…rollout.mdc`.
- **AIR-SSH-29** Treat adoption analytics (`SatusehatDiagnosisAdoptionService`, `satusehat:diagnosis-adoption-audit`)
  as read-only, PII-free, branch-scoped quality indicators with null-not-zero rates — never a punitive ranking.
  Source: `87-…rollout.mdc`.

### Pilot, waves and UAT

- **AIR-SSH-30** No branch is a pilot and no wave is active by default. Pilot approval needs every internal gate to
  pass; the readiness score (`config/satusehat_pilot.php`) is deterministic and versioned and a hard blocker caps it.
  A branch belongs to at most one active wave (partial unique index `trx_ss_wave_membership_active_uq`); promotion
  needs zero open hard blockers. Source: `88-…pilot.mdc`, `89-satusehat-4d-multi-branch-readiness.mdc`.
- **AIR-SSH-31** Route threshold, weight, wave, pilot, suspension, terminology and rollout changes through change
  control with requester ≠ approver; the `blocked_categories` `production_guard_config` and `credential_state` can be
  logged but never approved or applied. Bulk issue actions stay bounded (`SatusehatCrossBranchIssueService::MAX_BULK`)
  and never bulk-resolve HARD issues. Source: `89-satusehat-4d-multi-branch-readiness.mdc`.
- **AIR-SSH-32** An operational GO needs human operator UAT sign-off: a run reaches `signed_off` only when every
  required role approves and no scenario failed. Automated tests never substitute, and operator sign-offs are never
  fabricated. Source: `89-satusehat-4d-multi-branch-readiness.mdc`.
- **AIR-SSH-33** Rehearsals are synthetic only: `satusehat:rehearse --synthetic`, `satusehat:pilot-rehearse`,
  `satusehat:multi-branch-rehearse` are dry run by default, network-silent, marker-scoped to the synthetic branch
  `SYN4A`, and end honestly at `BLOCKED_EXTERNAL_CREDENTIAL` (or `INCOMPLETE`) — never submitted. Synthetic reset
  deletes only campaign records. Source: `86-…quality.mdc`, `88-…pilot.mdc`, `89-…readiness.mdc`.

### Tests and gotchas

- **AIR-SSH-34** Keep every SATUSEHAT test hermetic: `Http::preventStrayRequests()` and `Http::assertNothingSent()`,
  synthetic data, no credential. Workspace HTTP tests with role users need
  `withoutMiddleware(EnsureRmeOnlineContext::class)` and `seedAccessControl()`. `expectsOutputToContain` consumes one
  written line per expectation, so assert multi-line JSON output with `Artisan::call` + `Artisan::output()`. Source:
  `85-…readiness.mdc`, archive `SATUSEHAT-4A`.
- **AIR-SSH-35** On SQLite, adding a constrained foreign-key column rebuilds the table and drops a partial index's
  `WHERE` clause; keep pointer columns plain-indexed and re-assert partial unique indexes in a migration (4D did this
  for the single-primary-pilot index). Source: archive `SATUSEHAT-4D`.
- **AIR-SSH-36** Keep the primary health endpoints healthy while SATUSEHAT is disabled or credentials are empty, and
  keep diagnostic commands bounded and read-only by default (`--apply` / `--confirm` for writes). Source:
  `86-…quality.mdc`, `89-…readiness.mdc`.
- **AIR-SSH-37** The runtime production lock is `satusehat.sandbox_only` (`SATUSEHAT_SANDBOX_ONLY`, default true)
  together with `satusehat.environment` = `sandbox` (`SATUSEHAT_ENV`), enforced by
  `SatusehatServiceProvider::externalGatewayAllowed()` and `SatusehatHostGuard::assertAllowedBaseUrl()`. Keep both until a
  separately approved production campaign. Source: `84-satusehat-sandbox-api-adapter.mdc` §1.

## Current posture (snapshot 2026-10-25 — verify before relying on it)

- External submission DISABLED and production BLOCKED: `satusehat.enabled`, `satusehat.send_enabled`, the
  production switches and flags `satusehat.integration_readiness` / `satusehat.external_submission_enabled` are all
  off by default; the pilot binds `DisabledSatusehatGateway`.
- SATUSEHAT-2 is WATCH with no GO tag: no sandbox credentials are provisioned. The only path to an external GO is the
  SATUSEHAT-2 External Credential Closure Campaign; the Kemkes playbook payload instances and Condition/Procedure
  terminology still need human verification before any live run.
- GO tags exist for SATUSEHAT-1, -3, -4A, -4B and -4C. SATUSEHAT-4D was WATCH pending human operator UAT; no 4D GO
  tag was present in the local tag list at snapshot.
- All branches were left `informational` for diagnosis rollout; no pilot branch is configured. Seeded dental
  mappings (`SatusehatDentalMappingSeeder`) are DRAFT and need human verification and activation before readiness
  reports dental coverage; the `ClinicalDiagnosisSeeder` WHO ICD-10 master entries were seeded ACTIVE.
- The `satusehat` queue is declared in the ENT-5 allowlist; its consumer requirement is suspended dynamically while
  `satusehat.enabled` is false (`config/queue_governance.php` `consumer_required_when`; archive
  `BUGFIX-LEGACY-ODONTOGRAM-QUEUE-CONSUMER-1`).
- `tests/Feature/Satusehat` matched no NSF-R011 critical filter token and no Selective Module Gate filter at
  snapshot, so with the Full Suite deferred (CG-18) these suites do not run in CI. Add a token per CG-19 when you touch
  this area.

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/83-satusehat-integration-readiness.mdc` | Candidate-only flow, no auto-send, disabled gateway, audit log |
| `.cursor/rules/84-satusehat-sandbox-api-adapter.mdc` | Host guard, TLS, tokens, idempotency, reconciliation, sandbox verify |
| `.cursor/rules/85-satusehat-dental-use-case-production-readiness.mdc` | Dental mappings, conformance, permanent production guard |
| `.cursor/rules/86-satusehat-4a-operational-readiness-data-quality.mdc` | Data-quality fingerprints, waivers, synthetic pack, rehearsal |
| `.cursor/rules/87-satusehat-4b-structured-diagnosis-adoption-terminology-rollout.mdc` | Terminology lifecycle, rollout modes, override, Condition preview |
| `.cursor/rules/88-satusehat-4c-branch-readiness-internal-pilot.mdc` | Branch readiness score, pilot approval, thresholds |
| `.cursor/rules/89-satusehat-4d-multi-branch-readiness.mdc` | Waves, promotion, change control, UAT sign-off |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/11-satusehat.md` (SATUSEHAT-1 … SATUSEHAT-4D). The
Super-Admin-only gate is recorded under `FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1` in `03-branch-context.md`. Grep by
sprint id; never read a whole file.
