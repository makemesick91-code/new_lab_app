# SATUSEHAT-1 — Readiness Foundation & Controlled Submission Filter

Branch `feature/satusehat-1-readiness-foundation-controlled-submission-filter`
(base `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`;
do NOT target main). Manifest `.sprint/current.yml` (MODULE_SPRINT).

## Problem

DaengtisiaMS must be ready to integrate with SATUSEHAT when credentials and the
API become available — but not every treatment may be sent, and nothing may be
sent automatically. SATUSEHAT-1 builds the readiness foundation and a controlled
submission filter **without any external network call**.

## Scope

- New bounded context `App\Modules\Satusehat` (models, gateways, repositories,
  services, controllers, requests, policies, observers, provider).
- 6 additive tables: `mst_satusehat_code_mappings`,
  `mst_satusehat_entity_identifiers`, `trx_satusehat_candidates`,
  `trx_satusehat_submission_batches`, `trx_satusehat_submission_items`,
  `trx_satusehat_audit_logs`.
- Gateway contract with `DisabledSatusehatGateway` (default, network-silent),
  `FakeSatusehatGateway` (tests), `HttpSatusehatGateway` (SATUSEHAT-2 placeholder).
- Readiness engine (16 hard gates → ready/incomplete/blocked/source_changed),
  deterministic source hash + approval revocation on drift, local FHIR preview
  (Encounter/Condition/Procedure), post-commit idempotent candidate generation +
  bounded backfill command.
- Controlled filter/review workspace (`/rme/satusehat/submissions`), mapping
  governance (`/rme/satusehat/mappings`), identifier governance
  (`/rme/satusehat/identifiers`).
- 5 separate permissions + policies; Owner = view/review, Supervisor RME = full.
- `config/satusehat.php` (`SATUSEHAT_ENABLED=false` default) + two default-OFF
  governance flags in `config/feature_flags.php`.

## Out of scope (SATUSEHAT-2)

OAuth2 runtime, token refresh, real IHS lookup, sandbox/production API requests,
Encounter/Condition/Procedure POST, KFA/medication, live reconciliation,
odontogram mapping, attachment/DocumentReference upload, production cutover.

## Safety guarantees

- No auto-send from any lifecycle event; events only create/refresh candidates.
- DisabledSatusehatGateway default; `Http::assertNothingSent()` regression test.
- Fail-closed on incomplete config even if `SATUSEHAT_ENABLED=true`.
- SATUSEHAT failure never rolls back a clinical/billing transaction (post-commit).
- NIK always masked; sensitive snapshots `encrypted:array`; audit append-only.
- Branch-scoped, server-side review; no "Send All"; exclude requires reason.
- Source change revokes approval; mappings versioned; sandbox/production isolated.

## Tests

`tests/Feature/Satusehat/` — Foundation (gateway/no-network/hash/audit), Candidate
(generation/eligibility/readiness/source-change/preview/UTC), Observer (post-commit),
SubmissionFilter (auth/branch-IDOR/approve/exclude/bulk/queue-blocked/NIK-masked),
MappingIdentifier (versioning/single-active/env-isolation/format), Backfill
(dry-run/idempotent/bounded). 35 passed.

Regression: RME critical suites 147 passed; broad (ClinicVisit/MedicalRecord/
Odontogram/RolePermissionHardening/SidebarPermissionVisibility/PilotRouteAuthorization/
FeatureFlag/LabIntegration/RoleManagement) 428 passed. pint + `git diff --check`
clean; `npm run build` passed.

## Deploy note

`php artisan migrate --force` (6 additive tables) + `db:seed --class=PermissionSeeder
--force` + `db:seed --class=RoleSeeder --force` + `permission:cache-reset`.
`SATUSEHAT_ENABLED` stays `false`. See
`docs/runbooks/satusehat-integration-readiness-runbook.md`.

## Next sprint

**SATUSEHAT-2 — Sandbox API Adapter & FHIR Submission.**
