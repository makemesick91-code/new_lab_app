# SATUSEHAT-4C — Branch Readiness Remediation & Internal Pilot Operations

**Type:** MODULE_SPRINT · **Module:** Satusehat · **Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` · **Baseline:** SATUSEHAT-4B GO `1194b46`

**GO scope:** Internal branch-readiness remediation + internal pilot operations **only**. Credential-independent — **no OAuth / sandbox / production / external FHIR request occurs**. **SATUSEHAT-2 stays WATCH (its GO tag remains absent); external submission stays disabled; production stays blocked.** "Pilot ready" here means **internal** readiness; external readiness is a separate blocker owned by the SATUSEHAT-2 External Credential Closure Campaign.

## What shipped

A branch-readiness operating system composed on top of the existing SATUSEHAT-4A operational readiness / data-quality engine and SATUSEHAT-4B diagnosis adoption / rollout — **no duplicate engine**.

- **Config** `config/satusehat_pilot.php` — readiness stages, deterministic + versioned score weights (+ hard-blocker ceiling), versioned pilot thresholds (+ allowlisted overridable keys), issue SLA priority/due-hours, escalation ladder + clinical rule codes, single-primary-pilot rule, rehearsal terminal states.
- **Migrations (additive only — `migrate`, never migrate:fresh/db:wipe):**
  - `mst_satusehat_branch_pilot_profiles` — per-branch pilot status/stage, cached recomputable score + rates, reasoned threshold overrides, approval, reversible suspension.
  - `trx_satusehat_pilot_rehearsal_runs` — credential-independent synthetic rehearsal history (terminal `PILOT_READY_INTERNAL` | `BLOCKED_EXTERNAL_CREDENTIAL`).
  - Extended `trx_satusehat_data_quality_issues` with `priority`, `assigned_role`, `due_at`, `escalation_level`, `escalated_at`, `resolution_evidence`, `reviewed_by`, `reviewed_at` (nullable/default-safe; legacy rows unchanged).
- **Services (`App\Modules\Satusehat\Services\Pilot`):**
  - `SatusehatReadinessScoreService` — deterministic weight-normalized score; null-rate components excluded (N/A, never fake 0); hard blocker caps the score.
  - `SatusehatBranchReadinessProfileService` — computes per-component rates (patient/practitioner/organization/location/diagnosis-adoption/diagnosis-mapping/treatment-mapping/dental/local-conformance/operational-rehearsal) + issue counts, scores, derives stage via eligibility, persists. Dental readiness applies only to candidates that actually have an odontogram (odontogram-less = N/A, never blocking).
  - `SatusehatInternalPilotEligibilityService` — 16 internal gates + 3 external-posture gates → `suspended` / `not_eligible` / `blocked_external_credential`. `internal_ready` is the internal-only verdict.
  - `SatusehatPilotConfigurationService` — select / approve (requires internal_ready) / suspend / resume; MAIN + non-RME rejected; single-primary enforced; transactional + audited.
  - `SatusehatReadinessThresholdService` — allowlisted + bounded + versioned + audited per-branch overrides.
  - `SatusehatIssueSlaService` — SLA-aware assignment (priority + due date), escalate-up-only, review stamp. Never resolves a hard issue.
  - `SatusehatPilotRehearsalService` — branch-scoped synthetic rehearsal wrapping the SATUSEHAT-4A rehearsal; records a run; network-silent.
  - `SatusehatPilotOperationsService` — dashboard overview, issue aging, operator backlog (bounded, PII-free).
- **HTTP:** thin `SatusehatBranchReadinessController` (board, branch detail, pilot-operations dashboard, recalculate, issue SLA actions) + `SatusehatInternalPilotController` (select/approve/suspend/resume/thresholds/rehearse); 3 FormRequests; `SatusehatBranchPilotProfilePolicy` (branch-scoped, least-privilege abilities); routes `satusehat.branches.*` under `/rme/satusehat/branches`; sidebar links.
- **Commands (read-only unless noted):** `satusehat:branch-readiness`, `satusehat:pilot-status`, `satusehat:pilot-eligibility` (`--strict`), `satusehat:pilot-rehearse` (`--confirm` persists), `satusehat:issue-aging`, `satusehat:operator-backlog`.
- **Permissions (6 new):** `view_satusehat_branch_readiness`, `manage_satusehat_branch_remediation`, `configure_satusehat_internal_pilot`, `approve_satusehat_internal_pilot`, `run_satusehat_pilot_rehearsal`, `view_satusehat_pilot_metrics`. Admin Klinik = readiness + remediation; Supervisor RME = full; Owner = metrics read-only; Doctor/Kasir/Admin Lab excluded.

## Guarantees

No external HTTP · branch-scoped server-side · MAIN excluded · no pilot by default · approval = internal GO only · score cannot override hard blockers · hard issues resolve only by revalidation · no fabricated IHS/Org/Location · NIK never rendered · production blocked · SATUSEHAT-2 WATCH · no auto-send / no Send All · additive migrations only.

## Tests

`tests/Feature/Satusehat/Satusehat4cBranchReadinessPilotTest.php` (17) — deterministic score + hard-blocker cap, eligibility (not_eligible + blocked_external_credential), approval gate + single-primary + MAIN exclusion, suspend/resume, threshold governance, SLA assignment + escalation, rehearsal (dry-run + confirmed, network-silent), production-block + WATCH posture, recalculate persistence, board scoping (no network), IDOR 404, commands (no network). `SupervisorRmeRolePermissionTest` exact-list repinned (+6).

## Deploy note

`php artisan migrate --force` (3 additive tables/columns) + `db:seed --class=PermissionSeeder --force` + `db:seed --class=RoleSeeder --force` + `permission:cache-reset`. Keep the SATUSEHAT enable + send switches false. Post-deploy: `satusehat:pilot-status`, `satusehat:branch-readiness`, `satusehat:production-guard-check` (blocked).

## Next

**SATUSEHAT-2 External Credential Closure Campaign** — the only path to external GO. If credentials remain unavailable: **SATUSEHAT-4D — Multi-Branch Readiness Scale-Up & Operational Governance** (do not start automatically).
