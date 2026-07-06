# Release Evidence & Smoke Runbook (ENT-15)

Durable governance: `docs/architecture/enterprise-documentation-runbook-governance.md`.
Verified by: `php artisan foundation:enterprise-documentation-check --strict`.

## Purpose

Produce and validate the non-sensitive release-evidence artifact pack (NSF-10 +
the ENT chain), run the security/PII compliance gate (ENT-9), and run the automated
smoke both locally and on the VPS. Covers the **release_evidence, smoke, and
security_pii** topics.

## When to Use

- In CI on every PR (evidence gates run automatically).
- Before and after a VPS deploy, to confirm evidence and smoke are GO.
- When auditing that a release met the enterprise gate.

## Prerequisites

- Local: a working app environment and the foundation commands registered.
- VPS: `ssh daengtisiams-vps`, deploy already run (which captures the artifacts).

## Safe Commands

```
php artisan foundation:security-compliance-check
php artisan foundation:enterprise-documentation-check
php artisan release:evidence-check --profile=ci
php artisan release:evidence-check --profile=vps
php artisan release:automated-smoke --base-url=http://127.0.0.1
php artisan architecture:foundation-governance-summary
```

- Evidence artifacts are written under `storage/release-evidence/latest/` (and
  `storage/ci-evidence/` in CI). They are **non-sensitive** and are **not**
  committed to git.
- The `enterprise-documentation-check.json` artifact is required in the ci and vps
  profiles alongside the ENT-12..14 siblings.

## Forbidden Commands

Never run these while gathering evidence or smoking a release:

- `php artisan migrate:fresh`
- `php artisan db:wipe`
- `php artisan schema:drop`
- `php artisan migrate:reset`
- Any command that writes a secret, credential, or unmasked KTP/NIK into an
  evidence artifact, and any high-volume load test against the pilot/production.

## Evidence

- All required artifacts present and each passes the forbidden-pattern / forbidden
  16-digit-run scan (secret/PII safe).
- VPS profile GO count matches the expected artifact count for the sprint.

## Rollback / Fallback

- Evidence and smoke are read-only; nothing to roll back.
- If a gate FAILs, treat the release as NO-GO and fix forward or roll back the
  deploy per the deploy/rollback runbook.

## Troubleshooting

- Evidence artifact missing → re-run the matching `foundation:*-check --json`
  redirect from the deploy script / CI gate.
- A `RELEASE-SAFETY-EVIDENCE-CHAIN` WATCH on the local profile is non-blocking when
  optional evidence is not captured; the combined decision stays GO.

## Smoke Verification

- Automated smoke reports all checks GO (HTTP 200 login included).
- Health endpoints 200; `/dev-console` guest → 302; `queue:failed` empty.
- App shows env pilot, debug OFF, maintenance OFF; no new log errors after deploy.

## Security / PII Notes

- Documentation and evidence never contain secrets, credentials, the environment
  file, or unmasked KTP/NIK; the ENT-9 gate scans Blade views for unmasked display.
- Full KTP/NIK stays server-side; the sidebar is never a security boundary.

## Owner / Reviewer

- Owner: Release engineer / Security compliance owner. Reviewer: governance owner.

## Review Cadence

- Review each release-safety / security sprint (ENT-9/ENT-10) and at least quarterly.
