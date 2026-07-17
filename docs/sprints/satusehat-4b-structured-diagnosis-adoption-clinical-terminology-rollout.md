# SATUSEHAT-4B — Structured Diagnosis Adoption & Clinical Terminology Rollout

- **Branch:** `feature/satusehat-4b-structured-diagnosis-adoption-clinical-terminology-rollout`
- **Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `371ac42` (SATUSEHAT-4A GO)
- **Status target:** internal GO (structured diagnosis adoption + terminology governance only)
- **GO tag:** `satusehat-4b-structured-diagnosis-adoption-clinical-terminology-rollout-go`

## GO semantics (immutable constraints)

SATUSEHAT-4B GO covers **internal** structured diagnosis adoption and clinical
terminology governance only. It does **NOT** mean:

- SATUSEHAT-2 GO — SATUSEHAT-2 **stays WATCH**; its GO tag remains **ABSENT**.
- Any OAuth, sandbox, production, or external FHIR request occurred (none did —
  every new test runs under `Http::preventStrayRequests()`).
- Production activation (still blocked by the SATUSEHAT-3 guard).
- Global diagnosis hard enforcement (impossible by design — see below).
- Retroactive coding of historical RME (legacy records are never auto-coded).

## What shipped

### 1. Clinical terminology review lifecycle (extends `mst_clinical_diagnoses`)

`draft → under_review → approved → active → deprecated` (or `rejected`,
re-submittable). Enforced in `ClinicalDiagnosisService` (all transitions
serialized via `lockForUpdate` + status re-assert):

- New entries (and restored soft-deleted codes) start as **DRAFT** with all
  prior-life review metadata cleared; only **ACTIVE** terminology is selectable
  in the doctor-facing search.
- **Approval/activation requires an official source** (`source` column) and the
  dedicated `review_clinical_terminology` permission; **separation of duties**
  is server-side — the creator/submitter can never approve their own entry.
- Active terminology is immutable (no edit endpoint); corrections deprecate the
  old entry with an optional **ACTIVE replacement pointer**
  (`replacement_diagnosis_id`). Deprecated codes stay historically readable and
  are only excluded from new selection.
- ICD-10 code format is validated against the config-declared pattern
  (`config/clinical_diagnosis_rollout.php` → `code_patterns`); unknown code
  systems are never guessed or format-checked.
- Search **aliases** fold into `normalized_search` only — the official display
  text is never altered.
- Every lifecycle event appends a `trx_satusehat_audit_logs` row
  (`terminology_submitted|approved|rejected|activated|deprecated`).
- The 15 SATUSEHAT-4A WHO ICD-10 seeded entries remain ACTIVE (official source
  present — grandfathered; the seeder is unchanged and idempotent).

### 2. Branch-scoped rollout modes (no global enforcement)

`mst_diagnosis_rollout_settings` (one row per explicitly configured branch) +
`DiagnosisRolloutService`:

- Modes: `disabled | informational | warning | pilot_enforced`.
- **Default** for unconfigured branches: `informational`
  (`CLINICAL_DIAGNOSIS_ROLLOUT_DEFAULT_MODE`); a blocking default is refused at
  runtime — **global hard enforcement is impossible by design**.
- Non-RME branches always resolve `disabled`.
- Configuration (`satusehat.rollout.*` routes, `configure_diagnosis_rollout`)
  requires a written reason (min 10 chars) and is audited
  (`diagnosis_rollout_mode_changed`).
- `pilot_enforced` blocks `MedicalRecordService::finalize()` **server-side**
  until the record carries ≥1 PRIMARY structured diagnosis whose master
  terminology is ACTIVE — or a usable emergency override exists.
  `informational`/`warning` NEVER block (warning shows a banner on the RM page
  and is tracked by the existing `structured_diagnosis` data-quality issue).
- No pilot branch is pre-configured at deploy: **all branches remain
  informational after deployment** until an authorized user explicitly
  configures a pilot branch with a reason. No approval is invented.

### 3. Emergency override governance

`trx_diagnosis_requirement_overrides` (append-only; no update/delete endpoint):

- Route `rme.visits.medical-record.diagnosis-override` gated by the dedicated
  `override_diagnosis_requirement` permission **and**
  `MedicalRecordPolicy::update` (IDOR-safe nested binding re-check).
- Reason mandatory (min 10 chars), time-boxed
  (`CLINICAL_DIAGNOSIS_OVERRIDE_TTL_HOURS`, default 24h), audited
  (`diagnosis_override_granted`).
- Only relevant on a `pilot_enforced` branch; **never** makes the SATUSEHAT
  candidate ready — the missing-diagnosis issue stays open for clinical review.

### 4. Data-quality rules (extend the 4A engine — no second engine)

- `deprecated_diagnosis_selected` (SOFT) — recorded diagnosis references
  non-active terminology; auto-resolves by revalidation after re-coding.
- `duplicate_primary_diagnosis` (HARD, unwaivable) — historical/import guard.
- `diagnosis_code_invalid` (HARD, unwaivable) — master code fails the official
  config-declared format.
- Synthetic rehearsal terminology (`synthetic_rehearsal`) is lifecycle-exempt
  everywhere (isolated campaign branch only).

### 5. Source hash & approval revocation

`SatusehatReadinessService::collectDiagnoses()` now fingerprints
`master_status`, `mapping_system`, `mapping_display` (plus the existing
id/code/role/mapping_code/mapping_version). Consequences:

- Deprecating terminology, changing the mapping, adding/removing a diagnosis,
  or swapping the primary **after approval** drifts the hash →
  `source_changed` → approval revoked → re-review required.
- Unchanged records never fabricate drift (hash byte-stable across refreshes;
  legacy candidates without diagnoses keep the omitted-key byte-stable hash).
- **One-time deploy effect:** any existing candidate that already carries
  structured diagnoses will drift once after deploy (facts shape change) and
  need re-review. On the pilot, structured diagnosis usage is ~zero (4A
  deployed 2026-07-16), so impact is nil-to-minimal — reviewers should still
  expect and re-approve any such candidate.

### 6. Local FHIR Condition preview (structured diagnoses only)

`SatusehatFhirPreviewBuilder` renders **one Condition per structured
diagnosis** using terminology exclusively from the ACTIVE reviewed mapping
(`mst_satusehat_code_mappings`); primary/secondary is carried as
`local_diagnosis_role` supporting context (no unverified FHIR rank extension is
fabricated). Unmapped → honest `mapping_blocked`; inactive terminology →
unsupported; no diagnosis → single honest placeholder. Label stays
**"Preview lokal — belum dikirim dan belum diverifikasi oleh API SATUSEHAT"**;
NIK never appears; no external HTTP.

### 7. Adoption analytics

`SatusehatDiagnosisAdoptionService` (read-only, PII-free, bounded, RME-branch
scoped; a crafted out-of-scope `branch_id` is dropped server-side):
eligible visits, with-diagnosis, with-primary, adoption/primary rates
(**null → "N/A" when the denominator is zero, never a fabricated 0%**),
secondary usage, deprecated-terminology usage (synthetic-exempt), override
count, `source_changed` candidates, open diagnosis issues by rule, rollout
modes, per-branch + per-doctor tables (capped, labeled as operational quality
indicators — not a punitive ranking).

- Dashboard: `GET /rme/satusehat/adoption` (`view_diagnosis_adoption`).
- Commands (read-only, JSON-capable, no PII, no network, VPS-safe):
  - `satusehat:diagnosis-adoption-audit [--branch= --from= --to= --doctor= --json]`
  - `satusehat:terminology-audit [--json --strict]` (strict exit 2 on: active
    without official source, active invalid code format, ambiguous duplicate
    active display)
  - `satusehat:diagnosis-rollout-status [--json]`

### 8. Doctor RME UX

The structured diagnosis card gains: rollout banner (warning/pilot), an
explicit **"Jadikan Utama"** primary swap (atomic demote+promote, audited
`diagnosis_role_changed`, drifts an approved candidate), terminology status
badges per row, and the reasoned override form (pilot branches, permission +
policy gated). SOAP stays hidden; handwriting RM stays the primary clinical
input.

### 9. RBAC (4 new permissions — least privilege)

| Permission | Roles |
|---|---|
| `review_clinical_terminology` | Supervisor RME |
| `configure_diagnosis_rollout` | Supervisor RME |
| `view_diagnosis_adoption` | Owner, Supervisor RME |
| `override_diagnosis_requirement` | Doctor, Supervisor RME |

Kasir/Admin Lab/Perawat gain nothing. Super Admin passes via `Gate::before`
but separation-of-duties is still enforced in the service layer. Enforcement
is route middleware → policy → service (sidebar is never the boundary).

### 10. Migrations (additive only — `migrate`, never `migrate:fresh`/`db:wipe`)

1. `2026_07_17_200001_extend_mst_clinical_diagnoses_for_terminology_lifecycle`
   — nullable lifecycle/review/replacement/alias columns (hasColumn-guarded).
2. `2026_07_17_200002_create_mst_diagnosis_rollout_settings_table`.
3. `2026_07_17_200003_create_trx_diagnosis_requirement_overrides_table`.

No drop/rename/NOT NULL, no backfill, no data mutation.

## Validation

- New tests: `tests/Feature/Satusehat/Satusehat4b{TerminologyLifecycle(9),RolloutEnforcement(9),DataQualityAndDrift(6),ConditionPreview(4),Adoption(5)}Test.php` — 33 passed.
- Full SATUSEHAT dir: 199 passed (SATUSEHAT-1/2/3/4A invariants intact).
- Full RME dir: 1031 passed. MedicalRecord|Odontogram: 288 passed.
- Critical gates (`MedicalRecordFinalization|RmeDoctorCashierCompletionGate|RmeRoomAssignmentGate|CashierBilling|RmePayment`): 104 passed.
- Permission suites: 298 passed (Supervisor RME pin repinned +4).
- `pint --dirty --test` + `git diff --check` clean; `npm run build` + `view:cache` pass.
- Independent security review: no CRITICAL/HIGH; 4 LOW findings fixed
  (lifecycle TOCTOU locks, setMode first-config race, synthetic-exempt
  deprecated-usage metric, restore-path stale review metadata), 1 LOW
  operational note recorded above (one-time facts-shape drift).

## Deploy notes

- `php artisan migrate --force` (3 additive migrations).
- `php artisan db:seed --class=PermissionSeeder --force && php artisan db:seed --class=RoleSeeder --force && php artisan permission:cache-reset`.
- Keep `SATUSEHAT_ENABLED=false`, `SATUSEHAT_SEND_ENABLED=false`; production
  guard stays blocked.
- Post-deploy verify: `satusehat:diagnosis-rollout-status` (all branches
  informational/default), `satusehat:terminology-audit --strict`,
  `satusehat:diagnosis-adoption-audit --json`, `satusehat:production-guard-check`,
  `satusehat:diagnose --json`.
- Rollback: config/mode rollback = set branches back to `informational`
  (`satusehat.rollout` UI) — no DB destruction; code rollback to the prior GO
  tag keeps all diagnosis/audit/terminology history (never rollback migrations
  by default).

## Out of scope (unchanged)

OAuth/sandbox/production requests, real IHS lookup, automatic submission,
"Send All", AI/free-text auto-coding, guessed terminology, destructive
backfill, global hard enforcement, medication/radiology integration. External
GO remains dependent on the **SATUSEHAT-2 External Credential Closure
Campaign**.
