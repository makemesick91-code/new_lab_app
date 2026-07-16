# SATUSEHAT-2 — WATCH Evidence

**Decision: WATCH (no GO tag).** A GO requires a verified live sandbox round-trip;
no sandbox credentials are provisioned, so the live gate cannot be executed and a
GO tag is forbidden per the sprint rules (mock ≠ sandbox).

## Baseline

- Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- SATUSEHAT-1 anchor: `satusehat-1-readiness-foundation-controlled-submission-filter-go` → peeled `315e289` (verified exact)
- Feature branch: `feature/satusehat-2-sandbox-api-adapter-fhir-submission`
- Feature commit: `aa0da98`
- PR: #259 (base = sprint branch, not main)

## Credential Readiness Gate (the hard blocker) — presence only, no values

```
SATUSEHAT_CLIENT_ID_present=false
SATUSEHAT_CLIENT_SECRET_present=false
SATUSEHAT_ORGANIZATION_ID_present=false
SATUSEHAT_LOCATION_ID_present=false
SATUSEHAT_OAUTH_BASE_URL_present=false
SATUSEHAT_FHIR_BASE_URL_present=false
```

No Patient/Practitioner test IHS provisioned. → live sandbox verification is a
hard blocker; the crown GO gate is deferred.

## Official-doc verification

Kemkes SATUSEHAT playbook is a JavaScript-rendered SPA; field-level FHIR
profiles/terminology could not be scraped programmatically. Terminology is
therefore sourced ONLY from the versioned local `SatusehatCodeMapping` (never
invented); Condition stays blocked (no structured diagnosis source). Exact
profiles must be human-verified before any live run.

## Tests (hermetic, no network)

- SATUSEHAT dir: **87 passed** (62 new SATUSEHAT-2 hermetic `Http::fake` tests +
  25 preserved SATUSEHAT-1).
- Critical RME regression: **158 passed** (MedicalRecordFinalization,
  RmeDoctorCashierCompletionGate, RmeRoomAssignmentGate, CashierBilling,
  RmePayment, PatientOutstandingReceivableCarryOver, PatientCentricRmWorkspace,
  LabIntegration).
- Permission/flag regression: **46 passed** (RolePermissionHardening,
  SidebarPermissionVisibility, FeatureFlag, PilotRouteAuthorization).
- `vendor/bin/pint --dirty`: clean. `git diff --check`: clean.
  `npm run build`: pass. `graphify update`: 24316 nodes.

## Security & privacy review

Independent review of the adapter: **no CRITICAL/HIGH**. Verified clean on
secret/token leakage, TLS-always-on, SSRF/host-allowlist, duplicate prevention,
unknown-outcome (no blind re-POST), PII (NIK/odontogram/scan/handwriting never
sent, NIK never in URL/log/audit), branch isolation/IDOR, no-HTTP-in-transaction,
and disabled/production fail-closed. 2 LOW: latent NIK-in-URL in the unused legacy
`verifyIdentifier` path (hardened with a NIK-pattern guard) + OAuth token in the
single-VPS `file` cache (standard Laravel; keep private).

## No-secret / no-PII proof

Secrets are env-only (never committed/logged). The environment example file was
NOT modified (harness-blocked). Token/secret never logged (explicit code
comments + tests assert `status()`/exceptions never contain the secret). NIK is
masked everywhere and never placed in a URL.

## Authoritative CI

- Run `29480112877` on candidate SHA `aa0da98`. All required gates PASS:
  CICD-CTRL Classifier, NSF-R012 Quality, NSF-R011 Critical Test Gate (24m41s),
  CICD-CTRL Selective Module, NSF-9 Release Safety & Smoke, NSF-10 Release
  Evidence. NSF-R011 Full Suite = skipping (standing pattern).
- Merged: PR #259 squash → base branch commit `b841c485`.

## VPS deploy (DISABLED)

- Deploy runner ran ON the VPS (`srv1730088`, `/var/www/asia-dental-lab-v2`),
  SSH-safe detached, `deploy-vps.sh` (`set -euo pipefail`) exit=0 → all
  governance gates + automated smoke passed.
- VPS HEAD `b841c48519d0a0f10bdffd59fccfbe6829f54dba` == merge commit (exact).
- Migration `2026_07_16_110001_extend_satusehat_submission_tables_for_sandbox_adapter`
  = **Ran** (additive; `migrate` only).
- Disabled/no-network proof (dry-run, no socket opened): `environment=sandbox`,
  `sandbox_only=true`, `enabled=false`, `send_enabled=false`,
  `client_id_present=false`, `client_secret_present=false`,
  `organization_id_present=false`, `location_id_present=false`,
  `production_blocked=true`. Gateway binding = `DisabledSatusehatGateway`
  (enabled=false).
- env=pilot, debug=OFF, maintenance=OFF; queue worker active.
- HTTP smoke: `/login` 200, `/health/live` 200, `/health/ready` 200,
  `/rme/satusehat/{submissions,mappings,identifiers}` 302 (guest auth redirect,
  no 500).
- Co-tenant nginx intact: `aishpos.online` → 301 (DaengtisiaMS `default_server`
  preserved).
- 0 new Laravel errors from this deploy. No SATUSEHAT credentials set; no live
  call made; production never contacted.

## GO decision

**NO GO tag.** Follow-up: provision sandbox credentials → run
`satusehat:sandbox-verify --live --confirm-sandbox` (synthetic data) → verify
Encounter/Condition/Procedure create + GET reconciliation + duplicate-prevention
+ production-never-called → then tag `satusehat-2-sandbox-api-adapter-fhir-submission-go`.
