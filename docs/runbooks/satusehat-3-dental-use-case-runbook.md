# SATUSEHAT-3 — Dental Use-Case & Production Readiness Runbook

Read-only, credential-independent. Nothing here sends data to SATUSEHAT.

## Deploy (disabled)

```bash
php artisan migrate --force                                   # additive columns only
php artisan db:seed --class=SatusehatDentalMappingSeeder --force   # DRAFT mappings, idempotent
```

Keep `SATUSEHAT_ENABLED=false`, `SATUSEHAT_SEND_ENABLED=false`, and all `SATUSEHAT_PRODUCTION_*`
unset/false.

## Post-deploy verification (no network, no PII)

```bash
php artisan satusehat:dental-profile-audit --json     # decision WATCH while mappings are DRAFT
php artisan satusehat:production-guard-check           # production allowed: NO (blocked) — expected
php artisan satusehat:production-readiness --json      # external=blocked, internal dental=ready/in-progress
php artisan satusehat:dental-readiness <visitId> --json
php artisan satusehat:dental-preview <visitId> --json  # local preview; never sent
```

Expected posture:
```
enabled=false  send_enabled=false  production_blocked=true
SATUSEHAT-2 = WATCH   dental implementation = ready_internal
```

## Verifying + activating a dental mapping (human governance)

1. Open **RME → SATUSEHAT → Mapping Kode**, filter `profile_family=dental`, open a DRAFT mapping.
2. Confirm the code against the official annex
   (`https://satusehat.kemkes.go.id/platform/docs/id/terminology/lampiran-terminologi/rawat-jalan-gigi/`).
3. Fill **Sumber resmi** + **Versi sumber** → **Verifikasi**.
4. **Aktifkan** (blocked server-side without the verification stamp).

Only verified+active mappings make a candidate's `dental_readiness_status` reach `dental_ready`.

## Incident response

- **A wrong dental code shipped in a preview:** deprecate the active mapping (a new version is
  required to change it); the preview immediately reports `dental_mapping_blocked`. No external
  data was ever sent.
- **Someone set a production flag:** `satusehat:production-guard-check` still reports blocked
  (SATUSEHAT-2 GO + credentials are also required). Unset the flag and rebuild config cache.
- **Odontogram edited after approval:** the candidate auto-transitions to `dental_source_changed`
  and the approval is revoked on the next refresh — re-review required.

## Never

`migrate:fresh` / `db:wipe` on any environment; filling fake credentials; enabling send;
creating/moving the `satusehat-2-sandbox-api-adapter-fhir-submission-go` tag; guessing a
clinical code.
