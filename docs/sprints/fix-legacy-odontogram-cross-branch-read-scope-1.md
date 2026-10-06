# FIX-LEGACY-ODONTOGRAM-CROSS-BRANCH-READ-SCOPE-1

**Type:** SECURITY_FIX (corrective). **Base authority:** `feature-rme-medical-records-unified-native-legacy-1-go`
@ `4f69d8d7` (production HEAD at start, verified on the VPS), canonical tip
`6c6e59e0` (docs-only on top). The unified medical-record workstream stays
CLOSED / IMMUTABLE; its tag was not touched.

`UPLOAD AUTHORITY != GLOBAL READ AUTHORITY`

## Root cause (defect class D + its consequence on every surface)

`LegacyOdontogramWorkspaceScope::GOVERNANCE_PERMISSIONS` contained
`create_legacy_odontogram_imports`. Holding any governance permission resolves
the actor to the WHOLE RME branch set and admits NULL-branch rows. Admin Klinik
and Front Office both hold `create_…`, so the right to FILE a chart for one's own
clinic became the right to READ every clinic's published archive.

It was not one leaky endpoint. Every read surface consumes that one scope, so all
of them widened together:

| Surface | Path | Widened? |
|---|---|---|
| Record viewer + private source/pages | `LegacyOdontogramRecordController::resolve()` → `findByIdInBranches()`, then `LegacyOdontogramRecordPolicy::view/viewFile` | yes |
| Staging-import viewer (same PDF pages, by import id) | `LegacyOdontogramImportPolicy::view/viewFile` | yes |
| Patient history (incl. native odontogram page) | `LegacyOdontogramPatientHistoryService` | yes |
| Unified medical-record index | `UnifiedMedicalRecordIndexService::scopeFor()` | yes (inherited) |
| Intake binding | `LegacyOdontogramBranchBindingService` | yes |

Not the cause: the policy (it checks branch correctly — against a wrong set),
the repository (`scoped()` already fails closed on an empty set), model binding
(routes take a plain id resolved through the scoped repository), the doctor scope
(`DoctorPatientScopeService` already excludes soft-deleted visits).

Evidence the design always intended branch-pinned intake: the binding service's
own docblock ("a scoped operator may not archive another branch's history — and
could not read the row afterwards anyway"), `LegacyImportHubService` ("contains
only review, publish and void, which in this system no intake role holds"), and
`LegacyRmeWorkspaceScope`, whose governance set never contained `create_…`. The
unified-index sprint recorded this as an inherited LOW and left it for an owner
decision.

## Why one membership change and not a parallel read scope

A read-only scope beside the intake scope would have left the staging-import
viewer cross-branch — and a PUBLISHED staging row renders the very same pages, so
source-import-id substitution would still expose the archive. Removing the
permission from the one list makes every door agree.

## Production permission matrix (measured, read-only, pilot DB)

| Role | Read | Upload | Branch scope (after) | Patient scope |
|---|---|---|---|---|
| Super Admin | all | yes | all RME (governance + `Gate::before`) | — |
| Supervisor RME | `view_…_imports` | no | all RME (review/publish) | — |
| Admin Klinik | `view_…_imports` | yes | own effective branch | — |
| Front Office | `view_…_imports` | yes | own effective branch | — |
| Doctor | none | no | — | — |
| Owner | none | no | — | — |

No role other than Super Admin holds `view_legacy_odontogram_archive`, so no
doctor reads this archive in production today; the doctor rule is enforced and
tested for when it is granted.

Production data: 1 published record (SPN4) uploaded by Admin Sunu (Front Office,
SPN4). **Zero cross-branch uploads have ever occurred**, so the intake narrowing
changed no real upload.

## Write behaviour

Unchanged for own-branch intake, review, publish, batch review/publish, VOID,
mass upload (its batch policy uses the legacy RME scope), single-active-document,
date rules, patient binding, wave admission, quota and SOD. The single change: a
branch-scoped operator filing a foreign branch's chart is now refused with the
existing `CODE_BRANCH_OUT_OF_SCOPE` — the behaviour the binding service already
documented.

**Stated explicitly, because it is the case most likely to surprise someone:**
patient identity is GLOBAL (REVISION-NEW-VISIT-GLOBAL-PATIENT-LOOKUP-1), so an
SPN4 operator may register a visit for a patient whose Nomor RM names TLK1. The
legacy archive's branch is RM-derived (FIX-ROLL2-1), so that operator filing the
patient's legacy odontogram through the visit-bound path is now refused — exactly
as the legacy RME visit-bound path already refuses it. The governance tier, or an
operator of the owning branch, files it instead. Measured on the pilot: the only
legacy odontogram import ever made is a backlog upload by Admin Sunu for an SPN4
patient; no visit-bound import exists, so this has never happened in practice.

## Tests

New `tests/Feature/LegacyOdontogram/LegacyOdontogramCrossBranchReadScopeTest.php`
(21), real roles and real online contexts. Red against unfixed code: 11 failed
(foreign archives returned 200; scopes resolved to every branch). Inverted:
`LegacyPatientArchiveCompletenessAccessTest` "does not reuse the odontogram
workspace governance set", which had pinned the defect itself as THE TRAP.
Declared in `config/ci_runner.php` `critical_gate_mandatory_suites`; selected by
the existing `LegacyOdontogram` token in both critical variants.

## Independent security review

No CRITICAL / HIGH / MEDIUM. Three LOW:
1. **Fixed.** The upload page computed the patient's archive slot status and
   earliest native odontogram date even when the archive belonged to another
   branch, so a global patient lookup told a branch-scoped operator whether a
   foreign patient had a legacy odontogram. Both facts are now withheld unless
   the operator may file for that branch, and the view no longer renders a
   withheld date as "Belum ada" (a false negative statement). Test passes with
   the fix and fails with it reverted.
2. **Documented** above (visit-bound filing for a foreign-RM patient).
3. **Verified absent:** an in-flight foreign-branch import would become
   invisible to its uploader. The pilot's only import is PUBLISHED and
   own-branch, so nothing is stranded.

## Durable rules

`.cursor/rules/174-legacy-odontogram-read-scope.mdc`.

## Shipped + deployed (2026-10-06)

PR #456 squash-merged as `c74f282f` (merge tree `efbac4d7` == CI-validated
candidate `e47aa4a7` tree). CI run `37469095407` green on the exact candidate:
Classifier, NSF-R012 Quality, NSF-R011 Critical (5061 passed, exit 0 — all 21
tests of the new suite present in the job log), Selective Module, NSF-9, NSF-10,
Android gate. NSF-R011 Full Suite **skipped** by the standing temporary policy —
skipped is not passed. Deployed on `srv1730088` via `scripts/deploy-vps-runner.sh`
run on the VPS: `exit=0`, `DEPLOY OK: 20261006-150818`,
`DEPLOY_HEAD_TARGET_MATCH=YES`, nothing to migrate. GO tag
`fix-legacy-odontogram-cross-branch-read-scope-1-go` @ `c74f282f`, exact match
at VPS HEAD.

Production, read-only: deployed governance set is review/publish/void; from the
live role matrix Super Admin + Supervisor RME stay estate-wide and Admin Klinik
+ Front Office are branch-pinned. `laravel.log` byte size and ERROR count
unchanged (0 new errors). Every clinical count unchanged (patients 1519,
visits/medical records/odontograms/invoices/payments/SATUSEHAT 0, legacy
odontogram 1/1, legacy RME 19). Health 200 ×4 over the domain; gated surfaces
302; `/storage/` 403. An authenticated per-role browser walk-through was NOT
performed (no owner-approved credentials); the per-role behaviour is proven by
the CI suite and derived from deployed code + the live permission matrix.
