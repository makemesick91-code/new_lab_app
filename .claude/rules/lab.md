---
paths:
  - "app/Modules/LabOrder/**"
  - "app/Modules/LabCapacity/**"
  - "app/Modules/LabService/**"
  - "app/Modules/Technician/**"
  - "app/Modules/Delivery/**"
  - "app/Modules/Production/**"
  - "app/Modules/QualityControl/**"
  - "app/Modules/Invoice/**"
  - "resources/views/lab/**"
  - "resources/views/lab-orders/**"
  - "resources/views/lab-workflow/**"
  - "resources/views/deliveries/**"
  - "resources/views/production/**"
  - "resources/views/quality-control/**"
  - "tests/Feature/LabWorkflow/**"
  - "tests/Feature/LabOrder/**"
---

# Lab workflow — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and
are not repeated here. Authority: code > canonical `.cursor/rules/` > this file > archive. On disagreement follow the
code, then the canonical rule, and report the drift.

## Invariants

### Engine and state machine

- **AIR-LAB-01** New lab orders use Lab Workflow V2. The engine is chosen only by `LabWorkflowResolver`, which reads
  flag `lab.workflow_v2` through `FeatureFlagService` (CG-08). `trx_lab_orders.workflow_version`: `1` = legacy
  (`NULL` also reads as legacy), `2` = V2. Source: `.cursor/rules/72-lab-workflow-v2.mdc` (archive `LAB-WORKFLOW-V2`).
- **AIR-LAB-02** Keep the legacy pipeline read-only for new orders while V2 is on: `LabOrderService::create` refuses
  server-side (`assertLegacyCreationAllowed`) and legacy update/cancel refuse V2 orders (`assertLegacyMutable`). Never
  delete the legacy workflow without a dedicated retirement sprint. Source: `72-lab-workflow-v2.mdc`.
- **AIR-LAB-03** Change a V2 status only through `LabWorkflowStateMachine::transition()` (matrix in
  `LabWorkflowState::matrix()`, multi-value permission map in `config/lab_workflow.php`). Never write a status from a
  request, controller or Blade; a forbidden edge is a `ValidationException`, a same-state transition is a no-op.
  Source: `72-lab-workflow-v2.mdc`.
- **AIR-LAB-04** Keep transition history append-only in `trx_lab_order_status_logs` plus `sys_audit_logs`, with
  scalar metadata only — never binary, base64 or PII. Source: `72-lab-workflow-v2.mdc`.
- **AIR-LAB-05** Keep the legacy production-report semantics: legacy reports read `trx_lab_order_assignments.status =
  'DONE'`, so V2 assignment statuses must stay identical in meaning. Source: `72-lab-workflow-v2.mdc` §12.

### Workflow gates

- **AIR-LAB-06** Require the SPK photo and the model photo before `WAITING_PICKUP`. Pickup and delivery are separate
  tasks (`trx_lab_pickup_tasks`, `trx_lab_delivery_tasks`, UNIQUE `lab_order_id`, claim is first-committed-wins under a
  row lock). Only a `manage_lab_orders` holder confirms receipt at the lab — a courier never confirms its own
  delivery. Source: `72-lab-workflow-v2.mdc`.
- **AIR-LAB-07** Record a model analysis (INTERNAL vs EXTERNAL, reason mandatory, append-only
  `trx_lab_model_analyses`) before any assignment. The internal path runs production steps 1–4 strictly in order,
  then QC. Source: `72-lab-workflow-v2.mdc`.
- **AIR-LAB-08** A QC failure needs a reason and an explicit rework target, and the producing technician can never
  pass or fail their own QC. That check lives in the service (`LabV2QualityControlService::assertSegregationOfDuty`)
  so holding QC permissions — Admin Lab included — never bypasses it. Source: `72-lab-workflow-v2.mdc`,
  `75-admin-lab-lab-only-access.mdc`.
- **AIR-LAB-09** On the external path, refuse an inactive or soft-deleted external lab server-side; a result review is
  mandatory; a rejection needs a reason and opens a NEW dispatch round (append-only); never jump straight to
  `MODEL_DONE`. Source: `79-lab-ops-readiness-technician-external-lab.mdc`, `72-lab-workflow-v2.mdc`.
- **AIR-LAB-10** Enforce delivery proof in three layers (FormRequest, service re-assert, matrix with no bypass edge):
  before `IN_TRANSIT_TO_BRANCH` a lab→courier handover photo plus the courier signature; `DELIVERED` needs receiver
  name, receiver signature and a location/proof photo. A disabled button is not the control. Source:
  `72-lab-workflow-v2.mdc` (archive `LAB-WORKFLOW-V2` Phase 4).

### Evidence and uploads

- **AIR-LAB-11** Store V2 evidence as typed `trx_lab_workflow_evidence` rows (sha256, soft-delete only — audit evidence
  is never hard-deleted) on the private disk `LabWorkflowEvidenceService::DISK`, served only through
  `lab-workflow-evidence.show` + `LabWorkflowEvidencePolicy`. Signatures are PNG files validated by magic bytes, never
  base64 in the database. Source: `72-lab-workflow-v2.mdc`.
- **AIR-LAB-12** Route every V2 upload through `LabWorkflowEvidenceService` → `LabEvidenceImageOptimizer`
  (`config/lab_workflow_uploads.php`); never add per-controller compression. Photos become JPEG, signatures stay PNG;
  MIME comes from bytes, a dimension bomb guard runs before decode, EXIF/GPS is stripped by re-encode, SVG/PDF are not
  evidence types. Over the hard cap after bounded iterations → refuse (fail closed); without GD → honest
  `original_no_gd`. Source: `74-lab-technician-assignment-upload-compression.mdc`.
- **AIR-LAB-13** Legacy lab attachments (`LabOrder\Services\AttachmentService`) and delivery POD
  (`Delivery\Services\PodService`) write through `ClinicalEvidenceStorage` (CG-11) and are served only via
  `attachments.download` (entity type → owning policy, fail closed when unmapped) and
  `deliveries.receiver-signature`. Never return them to a public disk. Source: archive
  `STORAGE-PUBLIC-CLINICAL-EVIDENCE-1`.

### Technicians and external labs

- **AIR-LAB-14** An assignment target must be an active, non-deleted user linked through `mst_technicians.user_id`
  and holding the Spatie role `Technician`. `TechnicianAssignmentEligibility` is the single source; its
  `assertAssignable` re-validates every request `technician_id`. `manage_production` qualifies the ACTOR, never the
  target. Revoking the role blocks new assignments only; history stays readable. Source:
  `74-lab-technician-assignment-upload-compression.mdc`.
- **AIR-LAB-15** Never delete a technician master that was ever used. Deactivate with `lab:technician-deactivate
  --reason=…` (dry run by default; refuses while an `ASSIGNED`/`IN_PROGRESS` assignment sits on a NON-terminal order —
  a stale active row on a terminal or missing order does not block). Link with `lab:technician-link-user` dry run,
  then `--apply`; it never changes a role and refuses ambiguous links. No fuzzy matching. Source:
  `79-lab-ops-readiness-technician-external-lab.mdc`, `78-lab-workflow-v2-pilot-uat-operational-readiness.mdc`.
- **AIR-LAB-16** Gate on ACTIVE masters only: an inactive orphan is not an anomaly; `active_orphan_count` is the
  readiness signal, and deactivating orphans must never drop eligible technicians to zero. Source:
  `79-lab-ops-readiness-technician-external-lab.mdc`.
- **AIR-LAB-17** Create external labs only for real, owner-approved vendors via `lab:external-lab-upsert` (allowlisted
  fields, idempotent by unique name, dry run by default, never deletes — restores a soft-deleted same-name vendor).
  Vendor management is `manage_lab_orders` only. Source: `79-lab-ops-readiness-technician-external-lab.mdc`.

### Branch scope and roles

- **AIR-LAB-18** "Klinik" on Buat Permintaan Lab is an active RME-enabled branch taken from `BranchContext`:
  `StoreLabWorkflowRequestRequest` overwrites any request `branch_id`, the service re-asserts with `assertRmeBranch`,
  and `trx_lab_orders.clinic_id` is nullable (V2 form orders store `NULL`). Catalogs are scoped server-side in
  `LabWorkflowRequestService::formOptionsForActiveBranch()` (patients never carry KTP/NIK, capped by
  `PATIENT_OPTION_LIMIT`); every hidden id is re-validated (crafted id → 422). A searchable dropdown is not
  authorization. Source: `72-lab-workflow-v2.mdc` §13.
- **AIR-LAB-19** Only actor-branch transitions listed in `branch_scoped_transitions` are equality-scoped; courier and
  lab staff are cross-branch by design and are bounded by task ownership. Branch workspaces always filter through
  `BranchContext`. Source: `72-lab-workflow-v2.mdc`.
- **AIR-LAB-20** A lab-request operator needs a resolvable RME branch (the active online context first, then
  `users.branch_id`); MAIN or a non-RME branch is refused. Map operators to branches in production only from evidence
  or an owner decision, transactionally with `lockForUpdate`, never by auto-assignment, and never by copying ids
  between environments (supersedes rule 72 §14's `users.branch_id`-only wording). Source: `72-lab-workflow-v2.mdc`
  §14, archive `OPS — Lab Request Operator → Cabang RME Mapping`, archive `RME-BRANCH-SUN4`.
- **AIR-LAB-21** Admin Lab is Lab-only: its grants come only from `RoleSeeder::ROLE_PERMISSIONS['Admin Lab']`, its
  revoked set is `RoleSeeder::ADMIN_LAB_REVOKED_NON_LAB`, it never holds Super Admin, and it lands on
  `lab-v2-orders.index`. Non-Lab access must be a 403 at route middleware or the controller policy. Audit with
  `rbac:admin-lab-lab-only-audit`; repairs (`--strip-direct`, `--demote=`) are explicit and guarded. Source:
  `75-admin-lab-lab-only-access.mdc`.

### Notifications

- **AIR-LAB-22** Send lab notifications as in-app database notifications only (`LabWorkflowNotificationService`) —
  never auto-WhatsApp — and never let a notification failure undo a transition. Source: `72-lab-workflow-v2.mdc`.
- **AIR-LAB-23** Never build a notification URL ad hoc: resolve per recipient through
  `LabWorkflowNotificationDestinationResolver` and send with `notifyPermissionHoldersRouted()` /
  `notifyUsersRouted()`. Admin Lab destinations are only `lab-v2-orders.*`, `lab-pickup-tasks.*`,
  `lab-delivery-tasks.*` — never `lab-workflow-requests.*`; fixing a link never widens a permission; no safe target →
  link-less. Repair old links with `lab-workflow:repair-notification-destinations` (dry run, then `--apply`; rewrites
  only `data.url`). Source: `76-lab-v2-notification-destination-routing.mdc`.

### RME → Lab integration

- **AIR-LAB-24** Generate `LabCaseCandidate` rows only after `RmePaymentService::pay()` leaves the invoice `PAID`
  (`RmeLabIntegrationService::generateForPaidInvoice`; a `PARTIAL` invoice generates none), one per invoice item whose
  treatment has `requires_lab`, idempotent through UNIQUE `rme_invoice_item_id` + `firstOrCreate`, post-commit. A
  failure is logged and never rolls back the payment. The invoice branch must be an active RME-enabled branch (code
  checks `BranchService::rmeEnabledIds()`; supersedes Phase 21.2's `BranchContext` equality). Source: archive
  `Sprint 21 Phase 21.1/21.2`, `72-lab-workflow-v2.mdc` §11.
- **AIR-LAB-25** Convert a candidate only by manual review: `LabCaseCandidateConversionService` requires an explicit
  `lab_service_id` (no treatment→service mapping exists), is row-locked and idempotent through
  `converted_lab_order_id`, and produces a V2 DRAFT order while V2 is on. RME payment never auto-creates a `LabOrder`
  and never writes `trx_payments` lab billing rows. Source: archive `Sprint 21 Phase 21.4`, `72-lab-workflow-v2.mdc`.

### Analytics, SLA and capacity

- **AIR-LAB-26** Build KPIs, SLA and the operational dashboard from V2 data only (`workflow_version = 2`) and from
  `trx_lab_order_status_logs.changed_at`, never `updated_at`. Formulas live in `LabOperationalAnalyticsService` with
  the registry `config/lab_operational_analytics.php`; cycle time is reused from `LabWorkflowSlaBaselineService`. Source:
  `80-lab-operational-analytics-kpi.mdc`, `78-lab-workflow-v2-pilot-uat-operational-readiness.mdc`.
- **AIR-LAB-27** The SLA deadline is `trx_lab_orders.due_date` only; orders without one are excluded and shown in the
  data-quality panel; delivery at end of the due day is on time. Missing data is excluded/WATCH and a zero
  denominator is `null` ("N/A") — never a fake 0 or percentage. Source: `80-lab-operational-analytics-kpi.mdc`.
- **AIR-LAB-28** Analytics access is server-side: tier `full` (`view_lab_operational_analytics` or
  `manage_lab_orders`) reads all RME branches with the branch filter validated against `rmeEnabledIds()`; tier `own`
  is forced to the caller's linked technician; otherwise 403. Dashboard/SLA scope fails closed (an unresolved branch
  operator gets an empty scope; SLA uses the effective branch id). Exports use the identical scope and carry no PII.
  Source: `80-lab-operational-analytics-kpi.mdc`, `78-lab-workflow-v2-pilot-uat-operational-readiness.mdc`.
- **AIR-LAB-29** Capacity planning is decision support only: never auto-assign, never rank employees,
  `planning_unit` explicit, a missing profile is UNCONFIGURED/UNPLANNABLE (never 0), profiles are effective-dated
  with overlap refused. Reuse `LabTechnicianCapacityPlanningService` and the LAB-PROD-2 calculators; never write a
  parallel calculator. Source: `81-lab-technician-capacity-planning.mdc`.
- **AIR-LAB-30** Lab analytics time is the application timezone (UTC), not `ClinicalClock` — a deliberate exception
  to CG-12. Tests pin the clock with `pinTestClock()` / `freeTestClock()` and anchor deliveries to their own due day;
  never "fix" a boundary flake by weakening `>` to `>=`. Source: archive
  `FIX-LAB-ANALYTICS-MEDIAN-LATENESS-DAY-BOUNDARY-1`.

### Gates, evidence and tests

- **AIR-LAB-31** Before a lab GO or deploy run the strict gates and accept exit 0 only on GO:
  `lab:technician-account-audit --strict`, `lab-workflow:pilot-readiness-audit --strict`,
  `lab-workflow:operational-kpi-audit --strict`, `lab-workflow:operational-kpi-go-no-go --strict`,
  `lab-workflow:technician-capacity-audit --strict`, `lab-workflow:technician-capacity-go-no-go --strict`. Never print a
  fake GO; an errored check is UNKNOWN; a WATCH closure is not a GO tag. Source: rules 78–81.
- **AIR-LAB-32** Pilot closure needs real-role UAT (`docs/operations/lab-workflow-v2-pilot-uat-checklist.md`);
  automated tests never substitute, and operator/screenshot evidence is never fabricated. Source:
  `78-lab-workflow-v2-pilot-uat-operational-readiness.mdc`.
- **AIR-LAB-33** In tests, build evidence files with `fakeEvidencePhoto()` (real PNG bytes), never
  `UploadedFile::fake()->image()` (the local CLI has no GD); let GD-dependent compression tests skip honestly; keep
  `gd, exif` in CI extensions; build assignment targets with `Technician::factory()->assignable()`. Lab suites run in
  CI through the Selective Module Gate `--filter='Lab'` when the classifier sets `run_lab_tests`. Source:
  `74-lab-technician-assignment-upload-compression.mdc`, archive `LAB-WORKFLOW-V2`,
  `FIX-LAB-ANALYTICS-MEDIAN-LATENESS-DAY-BOUNDARY-1`.

## Current posture (snapshot 2026-10-25 — verify before relying on it)

- `lab.workflow_v2` is ON in production (environment override, activated at LAB-WORKFLOW-V2 Phase 5); the committed
  default stays `false`.
- Pilot readiness closed at strict GO (LAB-OPS-READINESS-1): unused technician masters deactivated, exactly one
  eligible technician at closure, owner-approved external labs active. Real-role UAT was owner-attested PASS.
- LAB-PROD-2 KPI gate GO on the pilot. LAB-PROD-3 capacity audit was WATCH at closure because no technician capacity
  or service workload profiles had been entered — a data-entry step on the capacity configuration page.
- 20 legacy `sys_attachments` rows (delivery POD signature/photo, QC photo) reference objects that no longer exist;
  classified ACCEPTED_RISK, access fails closed with 404. Reopen if the count grows or another table is affected.
- Lab suites are not in the NSF-R011 critical filter; they rely on the Selective Module Gate.
- The `local` disk used by `LabWorkflowEvidenceService` is configured with `serve => true` in
  `config/filesystems.php`; do not generate framework temporary URLs for evidence — serve through the policy route.

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/72-lab-workflow-v2.mdc` | V2 engine, state machine, gates, evidence, branch, RME candidates |
| `.cursor/rules/74-lab-technician-assignment-upload-compression.mdc` | Technician eligibility and evidence upload compression |
| `.cursor/rules/75-admin-lab-lab-only-access.mdc` | Admin Lab is Lab-only, never Super Admin |
| `.cursor/rules/76-lab-v2-notification-destination-routing.mdc` | Recipient-aware notification destinations and link repair |
| `.cursor/rules/78-lab-workflow-v2-pilot-uat-operational-readiness.mdc` | Pilot UAT, readiness gate, dashboard and SLA baseline |
| `.cursor/rules/79-lab-ops-readiness-technician-external-lab.mdc` | Technician master governance, external lab provisioning |
| `.cursor/rules/80-lab-operational-analytics-kpi.mdc` | KPI contract, SLA deadline, analytics authorization tiers |
| `.cursor/rules/81-lab-technician-capacity-planning.mdc` | Capacity planning as read-only decision support |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/08-lab.md` and the Sprint 21 section of
`docs/sprints/claude-md-archive/01-rme-clinical.md` (RME → Lab candidates). Cross-domain records cited above live in
`14-deploy-infra-security.md` (`STORAGE-PUBLIC-CLINICAL-EVIDENCE-1`), `13-cicd-testing.md`
(`FINAL-STABILIZATION-RESIDUAL-AUDIT-1`) and `03-branch-context.md` (`RME-BRANCH-SUN4`). Grep by sprint id; never
read a whole file.
