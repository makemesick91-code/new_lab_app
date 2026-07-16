# ADR 0002 — SATUSEHAT-2 Sandbox API Adapter

- Status: Accepted (implementation) / WATCH (live verification deferred)
- Date: 2026-07-16
- Supersedes/extends: ADR 0001 (SATUSEHAT-1 readiness foundation)

## Context

SATUSEHAT-1 delivered the readiness foundation (candidates, readiness engine,
mappings, identifiers, controlled filter) with a network-silent disabled gateway.
SATUSEHAT-2 must make **real** sandbox submission possible while keeping
production disabled and never sending anything automatically.

## Decision

1. **Fail-closed, sandbox-only adapter.** The real `HttpSatusehatGateway` is
   bound only when `SATUSEHAT_ENABLED=true`, the environment is sandbox, and all
   credentials are present; otherwise the disabled gateway is bound. A separate
   `SATUSEHAT_SEND_ENABLED` kill switch gates every outbound request. Production
   is fail-closed (`sandbox_only=true`).
2. **Single SSRF chokepoint** (`SatusehatHostGuard`): HTTPS-only, exact-match
   host allowlist, sandbox lock, no redirect following — enforced before every
   outbound call.
3. **OAuth2 `client_credentials`** with cache + distributed lock; secret/token
   never logged, stored, or returned.
4. **Terminology is never invented.** Procedure/Condition codes come only from an
   active versioned `SatusehatCodeMapping`; Condition stays blocked (no
   structured diagnosis source). Exact FHIR profiles/terminology require human
   verification against the official (SPA) docs before a live run.
5. **Idempotency + unknown-outcome safety.** DB-unique idempotency key; a
   succeeded resource is never resent; an ambiguous outcome is
   `unknown_outcome` → reconciliation via GET-by-id, never a blind re-POST.
6. **No HTTP inside a DB transaction**: claim → network-outside-tx → record.
7. **No GO without a real sandbox round-trip.** Because no sandbox credentials
   are provisioned, this sprint closes as WATCH: implemented, hermetically
   tested, deployed disabled, no GO tag.

## Consequences

- The app is production-quality ready to integrate but sends nothing until an
  operator provisions credentials and enables the kill switch.
- Live verification (`satusehat:sandbox-verify --live --confirm-sandbox`) + the
  GO tag are a follow-up. Production cutover is a separate, approved sprint.
