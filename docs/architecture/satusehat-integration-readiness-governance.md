# SATUSEHAT Integration Readiness Governance (SATUSEHAT-1)

Status: **Readiness foundation shipped. External integration DISABLED.**

SATUSEHAT-1 prepares DaengtisiaMS to integrate with the SATUSEHAT national health
platform when credentials and the API are available, **without sending any request
to the external SATUSEHAT API**. It introduces a controlled submission filter so
that not every treatment is sent — each eligible visit becomes a *candidate* that
must pass an explicit review before it is ever prepared for submission.

## Non-negotiable rules

1. **No auto-send.** No RME finalization, odontogram change, visit completion,
   payment, invoice payment, scheduler, queue, model observer, domain event,
   webhook, login, dashboard load, or preview may send SATUSEHAT data. These
   events may only create/update a candidate idempotently, post-commit.
2. **Candidate-only pipeline:** `Visit completed + MR final → candidate (idempotent)
   → filter page → readiness validation → local preview → approve/exclude →
   queue preparation → awaits SATUSEHAT-2`.
3. **Disabled-by-default gateway.** The runtime binding is
   `DisabledSatusehatGateway`; `SATUSEHAT_ENABLED=false`. It opens no network
   connection. The container fails closed to the disabled gateway when config is
   incomplete. `HttpSatusehatGateway` is a SATUSEHAT-2 placeholder with no
   production submission implementation and never runs here.
4. **Data/billing independence.** A candidate/readiness/preview/audit/mapping/
   gateway failure never cancels a payment, changes an invoice/visit status,
   fails a valid RME finalization, or rolls back a clinical transaction.
   Generation runs post-commit (`DB::afterCommit`) + guarded `try/catch`.
5. **PII & secrets.** NIK is always masked (`PatientDataCompletenessService::maskKtp`).
   No access token, client secret, OAuth response, raw clinical payload, or full
   NIK is stored/rendered/logged. Sensitive eligibility/preview snapshots use
   Laravel `encrypted:array` casts. `trx_satusehat_audit_logs` is append-only.
6. **Odontogram/scans/handwriting are unsupported** and never auto-mapped. No
   fabricated diagnosis Condition is emitted.
7. **Server-side controlled review.** Approval/exclusion/queue are recomputed and
   authorized server-side, branch-scoped to RME-enabled branches (IDOR-safe). No
   "Send All", no "select all across all pages". Exclusion requires a reason.
8. **Source-change revocation.** A deterministic source hash detects clinical
   drift after approval; the candidate becomes `source_changed`, the approval is
   revoked (`approved_by` retained, `revoked_at` recorded), and re-review is
   required.
9. **Mappings versioned; identifiers single-active per environment.** Sandbox and
   production are never mixed.
10. **Architecture:** Controller → FormRequest → Service → RepositoryInterface →
    Repository → Model; branch from `BranchContext`; additive migrations only.
11. **SATUSEHAT-1 performs no external submission.** The external adapter is only
    enabled in **SATUSEHAT-2** after sandbox credentials + official FHIR profile
    validation.

## Runtime configuration

All settings live in `config/satusehat.php` and read `SATUSEHAT_*` environment
variables (documented in the environment example file / runbook). Every value has
a safe fail-closed default, so the app boots normally with all URLs/credentials
blank while `SATUSEHAT_ENABLED=false`. Governance markers
`satusehat.integration_readiness` and `satusehat.external_submission_enabled`
(both default OFF, risk `critical`) are registered in `config/feature_flags.php`.

Required environment variables (all safe/blank by default, never filled on the
pilot): `SATUSEHAT_ENABLED=false`, `SATUSEHAT_ENV=sandbox`,
`SATUSEHAT_OAUTH_BASE_URL`, `SATUSEHAT_FHIR_BASE_URL`, `SATUSEHAT_CLIENT_ID`,
`SATUSEHAT_CLIENT_SECRET`, `SATUSEHAT_ORGANIZATION_ID`,
`SATUSEHAT_TIMEOUT_SECONDS=15`, `SATUSEHAT_CONNECT_TIMEOUT_SECONDS=5`,
`SATUSEHAT_MAX_ATTEMPTS=5`, `SATUSEHAT_CLINIC_TIMEZONE=Asia/Makassar`.

## Schema (additive only)

`mst_satusehat_code_mappings`, `mst_satusehat_entity_identifiers`,
`trx_satusehat_candidates`, `trx_satusehat_submission_batches`,
`trx_satusehat_submission_items`, `trx_satusehat_audit_logs`.

## RBAC

`view_satusehat_submissions` (Owner, Supervisor RME), `review_satusehat_submissions`
(Owner, Supervisor RME), `send_satusehat_submissions` (Supervisor RME),
`manage_satusehat_mappings` (Supervisor RME), `manage_satusehat_settings`
(Supervisor RME). Super Admin bypasses via `Gate::before`. Doctor/Kasir/Perawat
receive none by default.

## Handoff to SATUSEHAT-2

Implement the real `HttpSatusehatGateway` (OAuth2 + FHIR submission), real IHS
lookup, and batch send/reconciliation behind `SATUSEHAT_ENABLED=true` +
`satusehat.external_submission_enabled` — only after sandbox credentials and
official profile validation. The submission batch/item tables, idempotency keys,
dependency ordering, and readiness/source-hash contract are already in place.
