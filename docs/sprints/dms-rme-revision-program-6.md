# DMS-RME-REVISION-PROGRAM-6 — handwriting, odontogram, prescription and PDF audit

- **Programme:** RME revision programme under owner policy `DMS-FULL-DEVELOPMENT-PRODUCTION-TESTING-20261104`
  (rule 221 §7), mission `DMS-RME-REVISION-PROGRAM-6` (P0, CLINICAL_INTEGRITY_AUDIT / SECURITY_FIX / CRITICAL_HOTFIX),
  2026-11-06. Continuation of [[dms-rme-revision-program-1]] … [[dms-rme-revision-program-5]].
- **Status:** GO — merged (#603, `91131671`), deployed to the Biznet production host (`20261106-052449`, `DEPLOY
  RUNNER OK`), verified, tagged `dms-rme-revision-program-6-go`. Full Suite deferred under CG-18, not claimed.
- **Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `c78f15dc` (resolved from
  `origin` after fetch; `devflow:base-ref-check --strict` → `BASE_SOURCE=remote_tracking_ref`; the local base ref was
  stale at `9eabff07` and was not used). Production ran `9479b0ed` (Program 5 GO), read from the Biznet host before
  work began; the base was one docs-only commit (#602) ahead.
- **Branch:** `feature/dms-rme-revision-program-6`.
- **Canonical rule:** `.cursor/rules/224-rme-clinical-evidence-write-integrity.mdc` (new; extends rule 121, which
  gains a cross-reference; supersedes nothing). Domain entries AIR-RMC-42..43 in `.claude/rules/rme-clinical.md`.
- **Work category under the Foundation-First Sprint Lock:** clinical correctness / security fix (allowed before
  Foundation GO). The lock is unchanged. No new clinical feature, no schema, migration, permission, route, JS/CSS or
  production package change.

## 1. Governance preflight

### 1.1 Owner approval (recorded per CG-22)

| Id | Decision | Source |
|---|---|---|
| OD-P6 | Audit handwriting (HW-01/02 incl. residual R5-3), native odontogram (OD-01), prescriptions (RX-01), RME/odontogram/prescription print and PDF (PDF-01..03), clinical evidence storage (SEC-01), end-to-end document regression (QA-01) | Mission brief 2026-11-06, §0, §6–§17 |
| OD-P6-scope | Correct VERIFIED defects that violate existing canonical requirements only; record anything needing a new product decision; do not redesign correct workflows | Same brief, §0 |

### 1.2 Preflight evidence

- Production HEAD `9479b0ed` read from the Biznet host (`DaengtisiaMS`); last deploy `20261105-224743` reported
  `exit=0`; no deploy process running.
- No open PR. Peer sessions: one interactive session idle, two Remote Control sessions offline.
- Mission worktree `/home/fikri/Projects/dms-rme-revision-program-6` (dependencies installed with `composer install`,
  never symlinked; upstream unset).
- DEVFLOW (host PHP for the git-dependent checks, PHP 8.3 image otherwise; a throwaway `devflow` database):
  `devflow:base-ref-check --strict` 0 · `sprint:manifest-check` GO · `sprint:scope-audit --strict` GO (MedicalRecord,
  Odontogram, Prescription) · `foundation:devflow-check --strict` GO · `foundation:shared-service-audit --strict` GO
  (10, 0 errors) · `sprint:test-plan` → full required suite escalation, deferred under CG-18; targeted regression in
  §6 instead.
- **Test environment readiness (Program 5 left GD-dependent PDF tests skipped locally).** The local CLI is PHP 8.5
  without GD. The pinned CI-runtime replica `cicdfix6-php83:latest` (PHP 8.3.33, GD, pdo_pgsql, Poppler) was already
  present; every local test in this programme ran in it against a throwaway PostgreSQL **16.15** container on tmpfs.
  The baseline handwriting/prescription/PDF suites ran **76 passed, 0 skipped**. No production package was changed.

## 2. Architecture map

Graphify (AST graph rebuilt in the mission worktree, 43,337 nodes) and direct reads:

```
POST rme.visits.medical-record.handwriting.store  [permission:manage_clinic_visits, visit.room]
  → MedicalRecordHandwritingController::store
      → PatientRmWorkspaceResolver (canonical visit = earliest non-cancelled RME visit; its record may not exist)
      → RmeVisitConsentService::assertRmeAuthoringAllowedForPatient   (positive authority, rule 109 §2b)
      → MedicalRecordPolicy::update
      → MedicalRecordHandwritingService::save   (NEW — one transaction + object compensation)
          → MedicalRecordService::getOrCreateCanonicalMedicalRecord   (draft, only when the patient has no book)
          → MedicalRecordRepository::lockForHandwriting   (FOR NO KEY UPDATE)
          → ClinicalEvidenceStorage::disk()->put   (private `clinical_evidence` disk)
          → MedicalRecordHandwritingRepository   (page 1 legacy row | page 2+ pages table)
GET  rme.handwritings.image / rme.handwriting-pages.image → MedicalRecordPolicy::view → private stream
GET  rme.visits.print / rme.visits.pdf → ClinicVisitPolicy::print (Doctor denied, rule 138)
      → partials/print-body → MedicalRecord::orderedHandwritingPages(true) → ClinicalEvidenceStorage::dataUri
POST/PATCH prescription → RmePrescriptionService::create/update → PrescriptionCanvasDecoder → storeCanvas
GET  rme.prescriptions.canvas → RmePrescriptionCanvasController::show → RmePrescriptionPolicy::view
GET  rme.prescriptions.print → inline data URIs (no Doctor print denial, rule 138)
GET/PATCH rme.visits.odontogram.* → OdontogramService → RmeVisitConsentService::assertOdontogramAuthoringAllowed
```

## 3. Findings

### 3.1 Verified defects (corrected) — every one RED on the untouched base first

| Id | Workstream | Defect | Canonical requirement violated |
|---|---|---|---|
| P6-HW-01 | HW-02 (R5-3) | A blank or invalid canvas created the canonical medical-record draft before validation. The canonical visit is the patient's earliest visit and often has no record, so this is the ordinary first-save path. RED: record count 2 ≠ 1 | Mission §8 "a rejected request must not leave an unauthorized draft"; rule 109 §2b |
| P6-HW-02 | HW-02 (R5-3) | The object was written before the row, outside a transaction, with no compensation: a failed row write left an orphaned object. RED for page 1 and page 2 | Mission §8 "no orphaned file" |
| P6-HW-03 | HW-02 | Object key `handwriting_p{raw page}_{YmdHis}.png`: a refused or concurrent save in the same second replaced the bytes a committed row pointed at (stored sha256 then mismatched), and two pages posted above the next free page shared one object. RED: hash mismatch; identical keys | Mission §8 "a replacement does not corrupt prior valid evidence" |
| P6-HW-04 | HW-02 | Saves of one book were not serialised: page 1 has no unique index (two concurrent first saves → two rows); a new page N raced `UNIQUE(medical_record_id, page_number)` → HTTP 500. RED: no lock held during the save | Mission §8 concurrency; rule 223 §1 locking pattern |
| P6-RX-01 | RX-01 | `update()` wrote the R/ canvas, then decoded the signature; a refused signature orphaned the R/ object and, in the same second, overwrote the committed canvas. No failure removed any written canvas. RED for update and create | Mission §10 "a failed save does not leave orphaned files"; "a replacement does not corrupt prior valid evidence" |
| P6-RX-02 | RX-01 | A double-submitted create raced `UNIQUE(clinic_visit_id)` into an HTTP 500 instead of the existing "Resep untuk kunjungan ini sudah ada" answer. RED: no visit lock | One-prescription-per-visit contract; mission §8 duplicate submission |
| P6-RX-03 | SEC-01 | `prescriptions/{rmePrescription}/canvas/{kind}` was handled by `show(RmePrescription $prescription, …)`; implicit binding matches by name, so it never ran, an empty model was injected and the policy refused (403). The stored R/ and signature canvases never loaded on the prescription page (print embeds inline, so it was unaffected). RED: 403 for an authorized user | Rule 121 §3 (canvases readable through the policy-gated controller) |
| P6-PDF-01 | PDF-01 | A recorded handwriting page whose object is missing printed `<img src="">`. RED | `ClinicalEvidenceStorage::dataUri()` contract: "render an honest not-available state rather than a broken image" |
| P6-PDF-02 | PDF-01 | An unreadable object (`'throw' => true` disk) escaped `dataUri()` into the print/PDF as an HTTP 500; any bytes were embedded as `image/png`. RED: `UnableToReadFile`; non-null data URI for non-PNG bytes | Same contract |
| DOC-6-1 | OD-01 | Seven code comments (OdontogramService ×5, OdontogramController ×3, OdontogramPolicy ×1) and two test texts still described CORRECTIVE-03's consent gate on the odontogram, superseded by rule 134 | Rule 134 (documentation drift) |

### 3.2 Verified safe (no code change)

- **HW-01 authoring authority:** the positive authority is asserted before any write on every branch of the store;
  front-desk-only accounts are refused at `actorMayWorkOnEncounter()` (rule 223 §3); SOAP stays hidden; page
  ordering (page 1 legacy row, then pages by `page_number`) and the clamp to the next free page are correct.
- **OD-01:** the seven-cell status table holds exactly (registered/waiting/cashier_pending/completed/cancelled DENY;
  in_progress ALLOW with or without consent); the authority is single (no second consent check in controller,
  FormRequest, policy, Blade or JS); GET writes nothing (`draftForVisit()`; `getOrCreateForVisit()` has zero
  production callers); a previous visit's chart is read-only and a finalized live chart stays revisable; history
  excludes the current visit and empty drafts and is branch + doctor-patient scoped; `hasRecordedTeeth()` and the
  Legacy Odontogram cutoff are untouched.
- **PDF-01..03:** `rme.visits.print` and `rme.visits.pdf` share `partials/print-body` with no `Data Pasien &
  Kunjungan` card and no odontogram (rules 127, 100); handwriting embeds inline as base64; the structured odontogram
  print uses tables only; DMF-T comes from `dmftCounts()` and an unknown status never inflates it; the prescription
  print embeds both canvases inline and shows "Belum ada gambar resep." for a missing R/ canvas.
- **Rule 138:** a scoped Doctor is denied `rme.visits.print`, `rme.visits.pdf` and `rme.odontograms.print` in the
  policies; audits are written from controllers only; prescription print stays available to the Doctor.
- **SEC-01:** no clinical evidence writer or reader uses the public disk, `storeAs(..., 'public')`, `Storage::url` or
  `asset('storage')`; the `clinical_evidence` disk is private, `serve => false`, not linked, and its root is declared in
  `DMS_PRIVATE_PATHS` (rule 209); every read route is policy-gated before streaming; streamed paths come from stored
  columns only; `{kind}` is checked against a fixed allow-list (404 otherwise); evidence streams send `image/png`,
  `nosniff` and `private, no-store`.

### 3.3 Requires an owner decision (recorded, not changed — CG-22)

| Id | Finding | Why it is not a defect fix |
|---|---|---|
| OD-6-1 | Two devices adding the same new handwriting page number at once now resolve last-writer-wins (the second save updates the page the first created) — identical to editing an existing page. Before, the second answered HTTP 500 | The client posts `page_number` for both "add" and "edit", so the server cannot tell them apart; page-level optimistic concurrency (a revision token) is a product decision |
| OD-6-2 | Consent signature streams send no explicit `Cache-Control: no-store` (framework default `no-cache, private`) | Consent storage is OD-5-9 territory; no canonical rule requires `no-store` |

### 3.4 Residuals

- **R6-1** Two simultaneous FIRST saves for a patient with no book still race `UNIQUE(clinic_visit_id)` on the draft
  insert (`MedicalRecordService::createDraft()` is a plain create). The loser answers HTTP 500 but persists nothing:
  its transaction rolls the draft back and its compensation removes its object; a retry writes into the winner's book.
  Pre-existing, needs two devices saving a patient's very first page in the same instant, and not in the verified
  defect list. The independent review audited the obvious fix (lock the canonical visit `FOR NO KEY UPDATE` before
  resolving the draft) and found it deadlock-safe against today's lock orders; it is deferred so it can ship with its
  own two-connection test rather than untested.
  **CLOSED by DMS-RME-REVISION-PROGRAM-7** (rule 225): the draft insert runs in a SAVEPOINT and the loser adopts the
  winner's book, so the page lands and nothing answers 500. Closed by savepoint-adopt, NOT by the visit lock, so the
  check-then-write interleaving noted below stays recorded. Closed for the same canonical visit; the cross-visit case
  is R7-1 (rule 225).
- **R6-2** `ClinicalEvidenceStorage::FORBIDDEN_DISKS` is not enforced at runtime (the governance scan is the
  enforcement); the stored sha256 is not verified on read.
- R5-1 and R5-2 stayed open here (not in scope, not changed); both are **CLOSED by DMS-RME-REVISION-PROGRAM-7**
  (rule 225). **R5-3 is CLOSED** by P6-HW-01..04 (forward reference added
  to the Program 5 record; the original finding is unchanged).

## 4. Implementation

| File | Change |
|---|---|
| `app/Modules/MedicalRecord/Services/MedicalRecordHandwritingService.php` | **New.** `save()`: one transaction (resolve/authorize target incl. draft → lock record → clamp page → fresh random-suffixed key → object → row); a failure while the body runs removes the object this call wrote; once the body has returned the object is kept, because the commit outcome is unknown (rule 224 §1a) |
| `app/Modules/MedicalRecord/Controllers/MedicalRecordHandwritingController.php` | Validation and decode before any write; draft creation moved into the save transaction; write delegated to the service; row helpers moved out |
| `app/Modules/MedicalRecord/Interfaces/MedicalRecordRepositoryInterface.php`, `Repositories/MedicalRecordRepository.php` | `lockForHandwriting()` (FOR NO KEY UPDATE) |
| `app/Modules/Prescription/Services/RmePrescriptionService.php` | Decode both canvases before writing either; written keys tracked and removed on a failure before the body returned (same §1a rule); visit row locked on create; random-suffixed canvas keys |
| `app/Modules/Prescription/Controllers/RmePrescriptionCanvasController.php` | Argument renamed to `$rmePrescription` so implicit binding runs |
| `app/Support/Storage/ClinicalEvidenceStorage.php` | `dataUri()` returns null for an unreadable or non-PNG object, each logged as a warning (exception class only, never the key or bytes); the PNG check uses the same `\x89PNG` prefix the writers check |
| `resources/views/rme/visits/partials/print-body.blade.php` | Recorded page without an embeddable image prints "Gambar halaman ini tidak tersedia." (shared by print and PDF — changed once) |
| Odontogram service/controller/policy, two tests | Comments and two test descriptions corrected to rule 134 (no behaviour change) |
| `config/ci_runner.php` | Three new suites declared in `critical_gate_mandatory_suites` |

## 5. Security and clinical review

An independent reviewer read the full diff (`git diff` against the base) without this sprint's conclusions. Result:
**no CRITICAL, HIGH or MEDIUM findings**; four LOW and three informational notes.

| Id | Finding | Disposition |
|---|---|---|
| LOW-1 | Compensation ran on ANY exception, including one raised after the transaction body returned (the commit, or an `afterCommit` callback). At that point the row may be committed, so deleting "this call's object" could delete committed evidence | **Fixed.** `$bodyDone` is set as the body's last statement in both services; compensation runs only while it is false (rule 224 §1a). New test: an `afterCommit` callback throws → 500, and the row's object still exists with a matching hash |
| LOW-2 | R6-1, the concurrent first-save draft race | **Recorded** (§3.4) with the reviewer's deadlock audit of the proposed fix |
| LOW-3 | Status-code precedence shifted in two shapes: when a book exists and a new canonical page is requested, the 404/403 now precede the authoring refusal; when no book exists, canvas validation (422) now precedes the draft's policy 403 | **Recorded** (rule 224). No write is reachable either way; the route middleware and `actorMayWorkOnEncounter()` already refuse the actors who could hit the difference, and the existing consent-refusal tests assert errors without a specific code |
| LOW-4 | Test claims were overstated: the sequential page-1 test was described as proving the race (it passes without the lock); `odmRefused()` treated any exception as a refusal; the prescription same-second case was not isolated from the decode-order fix | **Fixed.** Header corrected; the sequential test renamed as a control; `odmRefused()` accepts only a `ValidationException` carrying the `consent` key; new test makes the prescription row write fail with both canvases valid in the same second |
| INFO-5 | Lock ordering. The new locks are deadlock-free against today's writers. A future path that takes the visit `FOR UPDATE` and then writes the medical record would deadlock against a handwriting save | **Recorded** (rule 224, latent lock-order hazard; rule 223 §1 order applies) |
| INFO-6 | Authoring is checked outside any lock, so a consent void or status transition can interleave between the check and the write | **Recorded.** Existing behaviour on every authoring path, not a regression; re-asserting under a visit lock (the R6-1 follow-up) would make it linearizable |
| Minor | `MedicalRecordHandwritingController` injects `MedicalRecordRepositoryInterface $medicalRecords`, which nothing uses | **Recorded.** Already unused on the base (`c78f15dc`); not changed here |
| INFO-7 | The reader's PNG check used a different prefix from the writers', and a non-PNG object was dropped silently | **Fixed.** Shared `\x89PNG` prefix; non-PNG and unreadable objects are logged |

## 6. Tests

### 6.1 RED → GREEN (PostgreSQL 16.15, PHP 8.3.33 + GD)

| Suite | RED on base | GREEN |
|---|---|---|
| `tests/Feature/RME/RmeClinicalEvidenceWriteIntegrityTest.php` (22) | 17 failed for the intended reasons, 3 regression properties passed; the 2 post-review tests are proven by mutation (§6.3) | 22 passed |
| `tests/Feature/RME/RmeClinicalEvidenceWriteLockConcurrencyTest.php` (6, PostgreSQL only) | 3 lock proofs failed with only the lock line removed (handwriting has no base service method; the prescription mutation is equivalent to base); sanity probe and sequential control passed | 6 passed, 0 skipped |
| `tests/Feature/RME/RmeOdontogramAuthorityMatrixTest.php` (24) | Coverage closure (behaviour already correct); its attempt-counting cases were added after mutation M12 survived (§6.3) | 24 passed |

### 6.2 Regression

| Run | Result |
|---|---|
| `tests/Feature/RME` + `tests/Feature/Storage` + `tests/Feature/ClinicOversight` (before review fixes) | 3,566 passed, 0 failed, 0 skipped (974.9 s) |
| The three Program 6 suites (after review and mutation fixes) | 52 passed, 0 failed, 0 skipped |
| Exact CI critical filter (both variants identical, 2,268 chars), final code `a0bcb6e5`, PHP 8.3.33 container on PostgreSQL 16 | 9,385 tests: 9,334 passed, 15 skipped (14 `ProductionHostIdentityGuardTest` + 1 `PrivateDatabaseDumpCreationTest` host-identity guards), 36 failed — all harness artefacts, see next row |
| The 13 suites holding those 36 failures, re-run on the host (git available, uid 1000 has a name) | 312 passed, 0 failed, 0 skipped |

The 36 container failures were all of one kind: the container cannot read the worktree's `.git` (the file points at
the primary checkout, which is not mounted), and uid 1000 has no user name inside it (`id: cannot find name`). None
of them was an assertion about Program 6 behaviour. `RepositoryArtifactHygieneTest` is the only one of the 13 suites
this change touches (comment and test-text edits), and it passes on the host. All 52 Program 6 tests ran inside the
critical filter and passed.

### 6.3 Mutation matrix

Each mutant was applied as a literal replacement, verified to have changed the file, run against the three Program 6
suites, then reverted **by copy** and checked byte-identical by sha256 (never `git checkout --`, which would discard
uncommitted work).

| Id | Mutant | Result |
|---|---|---|
| M01 | Handwriting: compensate even after the body returned (drop `! $bodyDone`) | KILLED (1) |
| M02 | Handwriting: no compensation | KILLED (4) |
| M03 | Handwriting: second-granular key (drop the random suffix) | KILLED (3) |
| M04 | Handwriting: no record lock | KILLED (2) |
| M05 | Prescription: second-granular key | KILLED (1) |
| M06 | Prescription create: no compensation | KILLED (1) |
| M07 | Prescription create: no visit lock | KILLED (1) |
| M08 | Canvas controller argument renamed back to `$prescription` | KILLED (3) |
| M09 | `dataUri()`: no PNG check | KILLED (1) |
| M10 | `dataUri()`: read guard catches only `LogicException` | KILLED (1 error: `UnableToReadFile` escapes) |
| M11 | Print: render `<img>` even with no image | KILLED (3) |
| M12 | Odontogram first save: drop the pre-insert authority assertion | SURVIVED on the first pass, then KILLED (5) |

**M12 was a real coverage gap, not an equivalent mutant.** With the pre-insert assertion removed, `saveForVisit()`
inserted the row, `updatePlaceholder()` then refused, and the outer transaction rolled the insert back — so every
row-count assertion still passed. The method promises the authority is asserted *before* the insert (it matters as
soon as anything moves the update out of that transaction), so the suite now counts `creating` events: zero for
every closed status, and one for an in-progress control save that proves the listener is live.

## 7. Foundation rule synchronisation

- New canonical rule `.cursor/rules/224-rme-clinical-evidence-write-integrity.mdc`; rule 121 gains a "later amendment"
  cross-reference (rule 121 stays authoritative for where evidence lives).
- `.claude/rules/rme-clinical.md`: AIR-RMC-42, AIR-RMC-43 and the rule-index row.
- Program 5 record: R5-3 marked CLOSED with a forward reference; the original finding text is unchanged.
- No `CLAUDE.md` change (CG-24).

## 8. CI, merge, deploy and production verification

Times UTC; clinic time is WITA (UTC+8).

**CI.** Run 42160724290 on PR head `b1284dc3`. Every required gate succeeded:
- Quality, Classifier and Android Gate;
- NSF-R011 Critical: `Tests: 9385 passed (46714 assertions)`, `critical_test_exit_status=0`;
- Selective Module, NSF-9 and NSF-10.

The second Critical row is skipped by design, because exactly one runner variant runs. The Full Suite job was
skipped under CG-18: not run, not claimed.

**Merge.** Squash-merged with `--match-head-commit b1284dc3` as
`9113167104d0409a617579a14ec2239dadd470d1`; its parent is `c78f15dc`.
- The merge tree `671763cd` is identical to the candidate tree.
- The post-merge push run 42168184443 on `91131671` succeeded on the same jobs.

**Pre-deploy.**
- `production-target-check.sh --remote` → `PRODUCTION_TARGET=VERIFIED`.
- Production was on `9479b0ed` (Program 5 GO), tracked tree clean.
- No deploy process was running, and it was 13:24 WITA, outside the 02:25–02:40 backup window.
- Last scheduled backup: `Result=success`.

**Deploy.** `ssh daengtisiams-biznet-prod 'cd /var/www/asia-dental-lab-v2 && sudo -n bash
scripts/deploy-vps-runner.sh start'`, which executed on the Biznet host. deploy_id `20261106-052449`, 05:24:49–05:26:28.

| Item | Result |
|---|---|
| Lock, target pin, snapshot trust | `DEPLOY_LOCK_ACQUIRED=YES`, `DEPLOY_TARGET_PINNED=91131671…`, `DEPLOY_SNAPSHOT_TRUSTED=PASS` |
| Pre-deploy backup | `pre_auto_deploy_20261106-052451.sql`, backup-verify GO |
| Migrations | `Nothing to migrate.` |
| Runtime isolation | 107 GO / 0 FAIL / 0 SKIP |
| Secret file permissions | GO |
| Final status | `exit=0`, `DEPLOY OK`, `DEPLOY_HEAD_TARGET_MATCH=YES`, snapshot cleaned |
| `verify 20261106-052449` | `final exit=0`, `DEPLOY RUNNER OK` |

Two governance WATCHes appear in the log, both pre-existing:
- the feature-flag check reports risky flags that are deliberately enabled;
- the smoke probe gets 404 on `http://127.0.0.1/login`, because nginx's catch-all server answers loopback. The
  canonical domain returns 200.

**Production verification.** All checks were read-only.

| Check | Result |
|---|---|
| VPS HEAD / tree | `91131671` / `671763cd`, 0 tracked dirty files |
| `/login`, `/health/live`, `/health/ready`, `/health/lb` over `https://daengtisia.online` | 200 ×4 |
| `/storage/<synthetic>` | 403 |
| Guest GET on visits, medical record, odontogram, prescription, visit print, handwriting image, handwriting page image, prescription canvas, prescription print, odontogram print | 302 → `/login` on all ten |
| Clinical counts (read-only transaction, 16 rows incl. handwriting, odontogram, prescription, consent, invoice, payment, legacy, audit max id) | Identical to the pre-deploy baseline |
| Private clinical evidence / public objects | 34 / 0, unchanged |
| `laravel.log` | Byte-identical to the baseline (1,467,295 bytes, 166 ERROR lines); no new log files |
| Pending migrations, failed jobs | 0, 0 |
| Feature flags, doctor enforcement scope | Unchanged; pilot `[9,15,18]`, global enforcement false |
| Fix code on disk | Handwriting lock, PNG prefix check, canvas binding argument, print "not available" state all present |

The queue worker unit is `disabled` and has never run on this host. That is the posture before and after this
deploy, and it was left alone: the mission does not activate disabled services.

**Not verified in production:**
- Authenticated clinical workflows. Clinic devices are frozen, no clinical evidence was created to make a smoke test
  pass, and no patient record was opened.
- Clinical-load stability, because clinic operations are closed.

**GO tag.** `dms-rme-revision-program-6-go`: annotated, on `9113167104d0409a617579a14ec2239dadd470d1`.
- Local peel, remote peel and VPS HEAD agree.
- This evidence commit is docs-only. It is not deployed and is not the tag target.

## 9. Cleanup

| Resource | Disposition |
|---|---|
| Local PostgreSQL 16 test container `dms-p6-pg16` (tmpfs, no volume) | Removed; Docker volume count 106 before and after |
| Mission worktree and branches | Removed after this evidence PR merges |
| Scratch files | Session scratchpad only; contained no patient data |
| Production | No mission process left running. The deploy lock, deploy logs, backups, timers, BK-1/BK-2, rollback databases and existing GO tags were all preserved |
| Protected vault worktree and primary checkout | Untouched |
