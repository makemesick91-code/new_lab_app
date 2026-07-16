# Runbook — SATUSEHAT-2 Sandbox Submission

Operational guide for enabling and running the SATUSEHAT-2 sandbox adapter. The
adapter ships **disabled**; nothing is sent until an operator explicitly enables
it AND queues a batch. Production is fail-closed for this sprint.

## 0. Safety invariants (never violate)

- Sandbox only. `SATUSEHAT_SANDBOX_ONLY=true`; never point a host at production.
- TLS verification stays on. Never use an insecure HTTP client anywhere.
- Never print the client secret, the access token, a raw FHIR payload, or a NIK.
- Live verification uses synthetic/test identifiers only — never a real patient.
- The queue worker restart and cache rebuild follow the standard deploy runbook.

## 1. Provision sandbox credentials (prerequisite for any live run)

Set these environment variables on the VPS (via the platform's secret mechanism;
do not echo values, do not commit them). The application's config comments in
`config/satusehat.php` list them; the environment example file is intentionally
left unchanged (harness-blocked):

- `SATUSEHAT_ENV=sandbox`, `SATUSEHAT_SANDBOX_ONLY=true`
- `SATUSEHAT_OAUTH_BASE_URL=https://api-satusehat-stg.dto.kemkes.go.id/oauth2/v1`
- `SATUSEHAT_FHIR_BASE_URL=https://api-satusehat-stg.dto.kemkes.go.id/fhir-r4/v1`
- `SATUSEHAT_CLIENT_ID=…`, `SATUSEHAT_CLIENT_SECRET=…`
- `SATUSEHAT_ORGANIZATION_ID=…`, `SATUSEHAT_LOCATION_ID=…`
- test identifiers for verification: `SATUSEHAT_TEST_PATIENT_IHS`,
  `SATUSEHAT_TEST_PRACTITIONER_IHS`

Then rebuild config cache as the runtime (PHP-FPM) user. Verify presence WITHOUT
printing values:

```
php artisan satusehat:sandbox-verify --json   # dry-run; prints booleans only
```

Expect `enabled`, `send_enabled`, `oauth_host_allowed`, `fhir_host_allowed`,
`*_present=true`, `production_blocked=true`.

## 2. Enable (kill switch)

- `SATUSEHAT_ENABLED=true` binds the real HTTP gateway (only when sandbox + all
  creds present).
- `SATUSEHAT_SEND_ENABLED=true` is the emergency kill switch that permits actual
  outbound requests. Flip it OFF to stop all sending WITHOUT a code deploy
  (change the variable + rebuild config cache).

## 3. Live sandbox verification (the GO gate)

```
php artisan satusehat:sandbox-verify --live --confirm-sandbox \
  --patient-ihs=<synthetic> --practitioner-ihs=<synthetic> --json
```

Proves: token acquired, sandbox host used, synthetic Encounter created + a remote
id returned + GET reconciliation. It never prints the token/NIK/payload and
refuses production. A successful live run is required before a GO tag.

## 4. Controlled submission flow (UI)

RME → SATUSEHAT → Filter Pengiriman: review candidates → approve →
"Siapkan" (prepare a batch) → SATUSEHAT → Batch Pengiriman → open a batch →
"Kirim ke Sandbox". Only approved+ready resources are sent, in dependency order
(Encounter then dependents). There is no "Send All".

## 5. Retry / unknown-outcome / reconciliation

- Retryable failures (429/502/503/504, token failure) are re-attempted with
  backoff (bounded by the ENT-5 job tries + config).
- An ambiguous outcome (500 / timeout after possible send) becomes
  `unknown_outcome` → `ReconcileSatusehatItemJob` GETs the remote id. If it can't
  be confirmed, the item stays `reconciliation_required` for operator review.
  **Never** force a blind re-POST.
- The circuit breaker opens after repeated hard failures and closes on a
  successful half-open probe.

## 6. Identifier verification

RME → SATUSEHAT → Identifiers → "Verifikasi" verifies an existing IHS id against
the sandbox by GET-by-id (rate-limited; NIK never in the URL).

## 7. Rollback (non-destructive)

Set `SATUSEHAT_SEND_ENABLED=false` then `SATUSEHAT_ENABLED=false` and rebuild
config cache — the binding falls back to the disabled gateway and no request is
made. Audit rows, submission state, and successful remote ids are retained; after
a redeploy a succeeded resource is never resent. Do not delete remote sandbox
resources; keep the synthetic ids for traceability. Never run a destructive DB
command.

## 8. Notes

- On the single-VPS pilot the cache driver is `file`, so the OAuth token is
  written to `storage/framework/cache` in plaintext (standard Laravel). Keep the
  cache directory private; revisit when moving to a shared/Redis cache.
- Production SATUSEHAT activation is a separate, explicitly-approved sprint.
