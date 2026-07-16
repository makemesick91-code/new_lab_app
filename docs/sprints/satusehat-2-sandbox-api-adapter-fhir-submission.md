# SATUSEHAT-2 — Sandbox API Adapter & FHIR Submission

**Status: WATCH (implementation complete + hermetically tested; live sandbox
verification DEFERRED — no sandbox credentials provisioned).**

Branch `feature/satusehat-2-sandbox-api-adapter-fhir-submission` (base
`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`,
baseline `satusehat-1-readiness-foundation-controlled-submission-filter-go` @
`315e289`; do NOT target main). Builds on the SATUSEHAT-1 readiness foundation —
extends it, never a parallel module or duplicate table.

## Why WATCH, not GO

A GO for this sprint requires a **real** sandbox round-trip (OAuth token +
Encounter/Condition/Procedure create + GET reconciliation) with synthetic test
data. A credential-readiness audit on the VPS showed **every** required sandbox
credential is absent (Client ID/Secret, Organization ID, Location ID, Patient/
Practitioner test IHS). Per the sprint's own rules, **mock success ≠ sandbox
success and a GO tag is forbidden without a verified live round-trip**. The
adapter is therefore implemented, fully hermetically tested (`Http::fake`), and
deployed **disabled** (`SATUSEHAT_ENABLED=false`, `SATUSEHAT_SEND_ENABLED=false`);
the live verification + GO tag are a follow-up once Kemkes sandbox onboarding
provisions credentials.

## Official-doc verification note

The Kemkes SATUSEHAT playbook is a JavaScript-rendered SPA and could not be
retrieved field-by-field programmatically during this sprint. Consequently the
exact FHIR profile field set + terminology systems MUST be re-verified against
the official docs by a human before any live sandbox run. To stay honest,
terminology is sourced ONLY from the versioned local `SatusehatCodeMapping`
(never invented), and any resource lacking mandatory data stays `blocked`.

## What shipped

- **Config governance** (`config/satusehat.php`): send kill switch
  (`send_enabled`), `sandbox_only` lock, per-environment host allowlist,
  location id, token refresh/lock, bounded retry, circuit breaker, response-size
  guard, dedicated queue.
- **OAuth2 token provider** (`OAuthClientCredentialsSatusehatTokenProvider`,
  interface-bound): `client_credentials`, cache + distributed lock, no
  secret/token logging, value-free status.
- **Real HTTP gateway** (`HttpSatusehatGateway`): SSRF-safe host allowlist,
  HTTPS + TLS-always, no redirect, bounded retry, 401 refresh-once, sanitized
  OperationOutcome, oversized-response guard, circuit breaker, kill-switch.
  Bound only when fully enabled + sandbox; otherwise the network-silent
  `DisabledSatusehatGateway`. `FakeSatusehatGateway` for tests.
- **FHIR builders** (`SatusehatFhirResourceBuilder` + `SatusehatFhirValidator`):
  Encounter (create + finalization), Procedure (terminology from active
  mapping), Condition (stays empty — no structured diagnosis source). Validator
  bans odontogram/handwriting/scan/attachment/NIK and asserts references/UTC.
- **Submission pipeline**: `SatusehatSubmissionStateMachine` (8 batch / 12 item
  states), `SatusehatSubmissionProcessingService` (claim → network-outside-tx →
  record; idempotent; source-hash-revalidated; dependency-ordered;
  unknown-outcome → reconcile), queue jobs `PrepareSatusehatSubmissionBatchJob`
  / `SubmitSatusehatItemJob` / `ReconcileSatusehatItemJob` (ENT-5 base, after
  commit, bounded retry).
- **Identifier verification** (`SatusehatIdentifierVerificationService`): GET by
  IHS id (NIK never in URL), sandbox-only, rate-limited, audited.
- **Additive migration** `2026_07_16_110001_extend_satusehat_submission_tables_for_sandbox_adapter`
  (correlation id, request/response hashes, remote version, http status,
  sanitized OperationOutcome, outcome classification, retry/lock scheduling,
  reconciliation metadata; widen status; the existing `idempotency_key` UNIQUE
  is the DB duplicate guard). Additive only — no drop, no destructive change.
- **Controlled UI**: batch list + batch detail with an explicit sandbox badge,
  kill-switch status, and a per-batch "Kirim ke Sandbox" action (guarded by the
  send permission AND the runtime kill switch; refused while disabled).
- **Operational command** `satusehat:sandbox-verify` (dry-run + live, sandbox
  only, synthetic identifiers, never prints secret/NIK/payload).

## Environment variables (documented here — the environment example file is
harness-blocked, so it was NOT modified)

`SATUSEHAT_ENABLED=false`, `SATUSEHAT_SEND_ENABLED=false`,
`SATUSEHAT_ENV=sandbox`, `SATUSEHAT_SANDBOX_ONLY=true`,
`SATUSEHAT_OAUTH_BASE_URL=`, `SATUSEHAT_FHIR_BASE_URL=`,
`SATUSEHAT_CLIENT_ID=`, `SATUSEHAT_CLIENT_SECRET=`,
`SATUSEHAT_ORGANIZATION_ID=`, `SATUSEHAT_LOCATION_ID=`,
`SATUSEHAT_TIMEOUT_SECONDS=15`, `SATUSEHAT_CONNECT_TIMEOUT_SECONDS=5`,
`SATUSEHAT_TOKEN_REFRESH_BUFFER_SECONDS=60`, `SATUSEHAT_MAX_ATTEMPTS=5`,
`SATUSEHAT_CIRCUIT_BREAKER_THRESHOLD=5`,
`SATUSEHAT_CIRCUIT_BREAKER_COOLDOWN_SECONDS=300`,
`SATUSEHAT_RETRY_BASE_DELAY_SECONDS=5`, `SATUSEHAT_RETRY_MAX_DELAY_SECONDS=300`,
`SATUSEHAT_RETRY_AFTER_CAP_SECONDS=600`, `SATUSEHAT_MAX_RESPONSE_BYTES=1048576`,
`SATUSEHAT_QUEUE=satusehat`. Live verification also reads
`SATUSEHAT_TEST_PATIENT_IHS`, `SATUSEHAT_TEST_PRACTITIONER_IHS`.

## Security review

Independent review of the adapter found **no CRITICAL/HIGH** issues on
secret/token leakage, TLS, SSRF/host allowlist, duplicate prevention, PII, branch
isolation, transaction safety, and disabled/production fail-closed. Two LOW items:
(a) a latent NIK-in-URL in the unused legacy `verifyIdentifier` path — hardened
with a NIK-pattern guard; (b) the OAuth token sits in the file cache on the
single-VPS pilot (standard Laravel; keep the cache backend private, revisit for
shared/Redis cache).

## Tests

`tests/Feature/Satusehat/Satusehat2*` — 62 new hermetic tests (host guard/SSRF,
retry classifier, state machine, FHIR builder/validator, OAuth token provider,
HTTP gateway outcomes incl. unknown-outcome/401-refresh/circuit-breaker,
submission pipeline incl. idempotency/source-drift/reconciliation, identifier
verification, sandbox-verify command, disabled/production invariants). Full
SATUSEHAT dir: 87 passed. Critical RME regression (MedicalRecordFinalization,
RmeDoctorCashierCompletionGate, RmeRoomAssignmentGate, CashierBilling,
RmePayment, PatientOutstandingReceivableCarryOver, PatientCentricRmWorkspace,
LabIntegration): 158 passed. Permission/flag regression: 34 passed. pint +
`git diff --check` clean; `npm run build` passes.

## Deploy note (WATCH)

`php artisan migrate --force` (1 additive migration). Keep `SATUSEHAT_ENABLED`
and `SATUSEHAT_SEND_ENABLED` **false** on the VPS. No GO tag until credentials
are provisioned and a real sandbox round-trip is verified via
`satusehat:sandbox-verify --live --confirm-sandbox`.

## Out of scope (future)

Encounter finalization as a mandatory second PUT (needs official-doc
confirmation), structured diagnosis → Condition, production cutover, KFA/
medication, DocumentReference, real IHS auto-create.
