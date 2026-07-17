# SATUSEHAT-4D — Multi-Branch Readiness Scale-Up & Operational Governance

**Branch:** `feature/satusehat-4d-multi-branch-readiness-scale-up-operational-governance`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Baseline:** SATUSEHAT-4C GO `89d71e1`.
**Status:** implementation complete + hermetically tested; **WATCH pending real human operator UAT** (no GO tag until UAT is executed + signed off).

## Scope

Scales the SATUSEHAT internal-readiness workflow from single-pilot into a governed
multi-branch operating model — rollout waves, comparative readiness matrix,
promotion/demotion, change-control, cross-branch issue governance, executive
dashboard, human operator UAT, multi-branch synthetic rehearsal, and incident
drills — across all RME-enabled branches.

**Credential-independent.** SATUSEHAT-2 stays WATCH (GO tag absent); external
submission stays disabled; production stays blocked; no OAuth/sandbox/production/
external FHIR request occurs. Every new test uses `Http::preventStrayRequests()` +
`Http::assertNothingSent()`.

## What GO means / does not mean

GO (when UAT-signed) = internal multi-branch readiness + operational governance
are implemented, human UAT is signed off, dashboards are operational + privacy-safe.
GO does **not** mean SATUSEHAT-2 GO, live OAuth/sandbox/FHIR success, production
activation, Kemkes acceptance, all-branches-externally-ready, or full remediation.

## Architecture (extends SATUSEHAT-4C — no parallel subsystem)

- **Migrations (additive):** `2026_07_19_100001..100010` — rollout waves,
  wave-branch memberships (+ single-active partial unique), UAT runs/scenarios/
  signoffs, change-requests, append-only score snapshots + transitions, incident
  drill runs, profile wave/UAT columns, and a **self-healing re-assert of the 4C
  single-primary-pilot partial index** (see note below).
- **Config:** `config/satusehat_pilot.php` gains `multi_branch`, `uat`,
  `change_control`, `incident_drills` sections — no key enables external send/production.
- **Services (`App\Modules\Satusehat\Services\Pilot\`):** MultiBranchReadiness
  (matrix, reuses 4C readiness/eligibility), RolloutWave (lifecycle),
  BranchPromotion (promote/demote/suspend/resume + transitions + snapshots),
  ScoreSnapshot (append-only history), ChangeControl (SoD, blocked categories),
  CrossBranchIssue (bounded bulk), Executive (aggregate + daily/weekly/monthly),
  Uat (6-role sign-off gate), MultiBranchRehearsal (per-branch isolation),
  IncidentDrill (safety invariants + recorder).
- **HTTP:** 5 thin controllers + 4 FormRequests; 23 routes under `/rme/satusehat`;
  every write route `permission:`-gated + server-side branch-scoped
  (`SatusehatWorkspaceScope`, never a request branch_id).
- **RBAC:** 7 new permissions (Supervisor RME all; Owner 2 read-only; Admin Klinik
  matrix read; Kasir/Admin Lab/Doctor/Perawat none).
- **Views:** matrix, executive dashboard, waves index/show, change-control, UAT
  index/show; every page carries WATCH + external-blocked notices; no PII.
- **Commands:** `satusehat:multi-branch-readiness`, `wave-status`, `uat-status`,
  `governance-audit` (GO/WATCH/FAIL), `multi-branch-rehearse` (dry-run default).

## Notable fix — latent 4C SQLite index defect

The 4C `mst_ss_pilot_single_approved_uq` index is a PARTIAL unique
(`WHERE pilot_status='approved'`). Adding an FK column on SQLite rebuilds the
table and Laravel recreates indexes without the WHERE clause, flattening it to a
full unique on `environment` — which caps the environment to ONE branch profile
and breaks multi-branch. 4C never tested 2 branches so it was latent. Fixed:
`active_wave_id` is a plain indexed pointer (no FK rebuild) + migration
`100010` re-asserts the partial index. PostgreSQL/VPS unaffected (ADD COLUMN
never rebuilds). Fully covered by tests.

## Testing

Hermetic 4D suites: `Satusehat4dMultiBranchMatrixTest` (5),
`Satusehat4dWaveGovernanceTest` (6), `Satusehat4dExecutiveGovernanceTest` (4),
`Satusehat4dUatRehearsalTest` (5), `Satusehat4dHttpAccessTest` (5). Full SATUSEHAT
dir: 244 passed. Broad RME regression (MedicalRecordFinalization / RmeDoctorCashierCompletionGate /
RmeRoomAssignmentGate / CashierBilling / RmePayment / PatientOutstandingReceivableCarryOver /
PatientCentricRmWorkspace / Odontogram / ClinicalDiagnosis / StructuredDiagnosis):
330 passed. Permission/role/sidebar: 30 passed. pint + `git diff --check` clean.

## Performance

Matrix wave-attribution + overdue counts are batched (no N+1). Per-branch
eligibility reuses the 4C engine (O(branches) service calls, bounded by the small
RME-enabled set; MAIN excluded) — appropriate for the ≤5-branch pilot scale; the
matrix paginates display and caps exports.

## Human operator UAT (mandatory for GO)

See `docs/operations/satusehat-4d-uat-kit.md` (scenario catalog per role + findings
log + sign-off record) and the runbook RACI. No GO tag until all six roles sign off.

## Next

SATUSEHAT-2 External Credential Closure Campaign (the only path to external GO).
