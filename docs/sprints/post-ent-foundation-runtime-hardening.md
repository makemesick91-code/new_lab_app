# Sprint — POST-ENT Enterprise Foundation Runtime Hardening

**Branch:** `feature/post-ent-foundation-runtime-hardening-queue-worker-deploy-timeout`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (NOT `main`)
**GO tag:** `post-ent-foundation-runtime-hardening-queue-worker-deploy-timeout-go`

## Why this is NOT ENT-17

ENT-16 closed the ENT-1..ENT-16 Enterprise Foundation Freeze with the final tag
`enterprise-foundation-go`. The roadmap's next recommended sprint is **MON-1**.
This is a **post-closure hardening / follow-up** sprint that inherits the closed
baseline. It adds no new ENT roadmap entry, moves no GO tag, and leaves
`next_recommended_sprint = MON-1` unchanged.

## Scope

1. **ENT-1..ENT-4 audit** — verify those governance/config/docs locks are
   completed, GO-tagged and doc-backed. Canonical scope resolution: they were
   governance/config/docs locks, so **no runtime backfill was required or done**.
2. **Queue worker runtime** — conservative single-process systemd worker on top
   of ENT-5. Worker-ready; the deploy never starts it.
3. **Deploy evidence timeout hardening** — server-side detached runner so the
   slow VPS evidence phase survives an SSH broken pipe (the ENT-16 deploy issue).

## Deliverables

- `config/enterprise_foundation_runtime_hardening.php`
- `app/Support/Foundation/EntFoundationRuntimeHardeningScanner.php`
- `app/Services/Foundation/EntFoundationRuntimeHardeningGovernanceService.php` (rules PEH-R001..R012)
- Commands: `foundation:ent-1-4-audit-check`, `foundation:queue-worker-runtime-check`,
  `foundation:runtime-hardening-check`, `foundation:queue-worker-smoke`
- `app/Jobs/Foundation/QueueWorkerSmokeJob.php`
- `deploy/systemd/daengtisiams-queue-worker.service`
- `scripts/deploy-vps-runner.sh`
- Governance summary section `enterprise_foundation_runtime_hardening_governance` (informational)
- Optional ci/vps evidence artifacts; deploy + CI capture wiring
- Docs: architecture governance doc, 2 runbooks, `.cursor/rules/66-...mdc`, roadmap rule pointers

## Preserved

ENT-5..ENT-16 gates stay GO; `enterprise-foundation-go` and every ENT GO tag
unchanged; MON-1 remains next. Full-payment-only, SOAP-hidden, KTP/NIK privacy,
no auto-WhatsApp, no auto-LabOrder — all untouched.
