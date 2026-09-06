# SATUSEHAT Integration Readiness Runbook (SATUSEHAT-1)

Operational guide for the SATUSEHAT readiness foundation. **In SATUSEHAT-1 the
integration is disabled and makes no external request.**

## 1. Environment configuration

Set these in the server environment file (all safe/blank by default — never fill
real credentials on the pilot in SATUSEHAT-1):

```
SATUSEHAT_ENABLED=false
SATUSEHAT_ENV=sandbox
SATUSEHAT_OAUTH_BASE_URL=
SATUSEHAT_FHIR_BASE_URL=
SATUSEHAT_CLIENT_ID=
SATUSEHAT_CLIENT_SECRET=
SATUSEHAT_ORGANIZATION_ID=
SATUSEHAT_TIMEOUT_SECONDS=15
SATUSEHAT_CONNECT_TIMEOUT_SECONDS=5
SATUSEHAT_MAX_ATTEMPTS=5
SATUSEHAT_CLINIC_TIMEZONE=Asia/Makassar
FEATURE_SATUSEHAT_INTEGRATION_READINESS=false
FEATURE_SATUSEHAT_EXTERNAL_SUBMISSION_ENABLED=false
```

`SATUSEHAT_ENABLED` MUST stay `false` in SATUSEHAT-1. The runtime gateway binds
to `DisabledSatusehatGateway`, which opens no network connection.

## 2. Deployment

Standard VPS deploy (backup-first, `migrate --force`, never `migrate:fresh`/
`db:wipe`). SATUSEHAT-1 adds 6 additive tables and 5 permissions:

```
php artisan migrate --force
php artisan db:seed --class=PermissionSeeder --force   # registers 5 satusehat permissions (idempotent)
php artisan db:seed --class=RoleSeeder --force          # Owner/Supervisor RME assignments (idempotent)
php artisan permission:cache-reset
```

## 3. Verifying disabled state (no external network)

```
php artisan satusehat:diagnose --json
# expect: enabled false, gateway DisabledSatusehatGateway
#
# NOT the interactive REPL. This section runs on the production pilot, and the
# REPL writes ERROR records into the application log that pin the monitoring
# log signal to WATCH for 24 hours. satusehat:diagnose is read-only, prints
# posture booleans only, and never prints a credential.
```

Automated test proof: `php artisan test tests/Feature/Satusehat/SatusehatFoundationTest.php`
(`Http::assertNothingSent()`).

## 4. Backfilling candidates for older visits (bounded, idempotent, no network)

```
php artisan satusehat:backfill-candidates --dry-run                 # count only
php artisan satusehat:backfill-candidates --branch=2 --limit=200    # bounded
php artisan satusehat:backfill-candidates --from=2026-01-01 --to=2026-06-30 --json
```

Always bounded by `--limit` (config-capped). Re-running never duplicates.

## 5. Operator workflow (the controlled submission filter)

`RME → SATUSEHAT → Filter Pengiriman` (`/rme/satusehat/submissions`):
1. Filter by branch/doctor/date/readiness/review status (patient by name/RM;
   NIK is masked).
2. Open a candidate → review the readiness reasons.
3. Open **Preview FHIR (lokal)** — a local, unsent preview labelled
   "belum dikirim dan belum diverifikasi oleh API SATUSEHAT".
4. **Approve** (only READY candidates) or **Exclude** (reason required).
5. **Siapkan untuk SATUSEHAT-2** prepares a local batch — nothing is sent
   (fail-closed; audited as `queue_attempted` + `queue_blocked`).

Mapping governance (`/rme/satusehat/mappings`) and identifier governance
(`/rme/satusehat/identifiers`) require `manage_satusehat_mappings` /
`manage_satusehat_settings`. Mappings are versioned; identifiers are entered
manually (no external lookup) and never mix sandbox/production.

## 6. Rollback

No destructive DB action. Roll back application code to the previous approved
tag; the additive tables are safe to leave in place. SATUSEHAT stays disabled;
menus can be hidden via permissions/config. Candidates, audit logs, and mappings
are never deleted for a runtime rollback.

## 7. Handoff to SATUSEHAT-2

Do NOT enable external submission in SATUSEHAT-1. SATUSEHAT-2 implements the real
`HttpSatusehatGateway` (OAuth2 + FHIR submission) behind `SATUSEHAT_ENABLED=true`
+ `satusehat.external_submission_enabled`, only after sandbox credentials and
official FHIR profile validation.
