# DMS-RME-REVISION-PROGRAM-8D — consent signature payload bound and read-scope docblocks

**Date:** 2026-11-12 (WITA = UTC+8). **Type:** SECURITY_FIX. **Base:** `4a1930fc` (canonical base after the Sprint 0
docs merge, #620; last recorded production runtime: the Program 8C release `b0c0bd62`, not verified from this
workstation). **Rule amended:**
`.cursor/rules/228-rme-clinical-write-authority-and-prescription-integrity.mdc` §10.3, §15. **Source of the finding:**
rule 228 §15 (8C independent review round 2, OPEN LOW) and
[[dms-rme-revision-sprint-0-evidence-recovery]] §6 and §8.

Foundation-First Sprint Lock stays ACTIVE (allowed category: security fix / clinical correctness). Full Suite
deferred under the global temporary policy (CG-18): **not run, not passed**.

## 1. Owner approval (CG-22) — 2026-11-12

Approved, with a LIMITED scope (mission `DMS-RME-REVISION-PROGRAM-8D-IMPLEMENTATION-1`):

1. The 1,000,000-character signature payload bound on the consent signature payloads, in the FormRequest AND in the
   service.
2. Correct the two docblocks that still describe the pre-8C clinical-document read scope.
3. Add and run the regression tests.
4. DEVFLOW, quality gates, independent review, PR and CI under governance.
5. Synchronise the documentation and canonical rules actually affected.

P8R-S6 (invoice / payment numbering month) is approved for a **read-only audit only** (§8). Not approved: production
deployment, the deploy runner, any production database change or migration, queue or scheduler activation, the
authoritative Full Suite, final RME readiness closure, a GO tag without the release gate, the unsigned-prescription
rule, OD-5-6, OD-5-9 consent storage, implementing P8R-S6, D4/D5, deleting branches or worktrees, and global device
enforcement. Approval to implement is not approval to merge or to deploy.

## 2. Change

| Item | Change | File |
|---|---|---|
| Request bound | `consenter_signature` and `doctor_signature` gain `max:` `PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH`, with the same wording the prescription request uses (`Ukuran gambar tanda tangan melebihi batas yang diizinkan.`) | `app/Modules/Consent/Requests/StoreRmeVisitConsentRequest.php` |
| Service bound | Both `decode()` calls in `sign()` pass `maxLength: PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH`, so a caller that skips the request (a job, a command, a future endpoint) is bounded the same way (CG-06) | `app/Modules/Consent/Services/RmeVisitConsentService.php` |
| Docblocks | `mayReadDocumentAt()` now describes the rule 228 §5 allowlist: context-bound front desk and cashier keep their working branch; only `CROSS_BRANCH_DOCUMENT_READ_ROLES` (clinical roles + Owner) keep the RME-enabled set, never wider; any other account, an unclassified role included, reads none; the doctor-patient scope still applies | `app/Modules/MedicalRecord/Policies/MedicalRecordPolicy.php`, `app/Modules/Odontogram/Policies/OdontogramPolicy.php` |
| Tests | New suite (§4) | `tests/Feature/RME/RmeVisitConsentSignatureBoundTest.php` |
| CI selection | The suite is declared in `critical_gate_mandatory_suites`; it is selected by the existing `RmeVisitConsent` token in BOTH critical-gate variants (no token added) | `config/ci_runner.php` |
| Rules and docs | Rule 228 §10.3 rewritten, §14 lists the suite, both §15 items marked CLOSED, request added to its globs; rule 224's stale consent `no-store` line marked closed by 8C; AIR-RMC-53 names consent signatures; this record; module index; sprint manifest | `.cursor/rules/228-…`, `.cursor/rules/224-…`, `.claude/rules/rme-clinical.md`, `docs/knowledge-base/module-index.md`, `.sprint/current.yml` |

**The unit.** The bound applies to the submitted data URI (prefix included), not to the decoded image: Laravel's
string `max` counts characters at the request and the decoder's `strlen` counts bytes in the service, which is the
same number for a base64 data URI and stricter for anything else. It is not 1 MiB and not an inferred binary size:
the oversized test fixture decodes to about 840 KB and is still refused.

**Fail-closed, nothing left behind.** Both decodes run before the transaction, so a refused signature is rejected
before the visit row lock, the consent number, any object write on the private disk and any row. A refused attempt
leaves the visit row's attributes, the consent table (no row), the disk (no object) and the finance tables' rows
unchanged (tests A-02, A-03, B-01, B-02).

**Deliberately unchanged:** the decoder (both constants, the IHDR, dimension and pixel caps, the blank check), the
storage disk and paths (OD-5-9 stays open), consent wording and template versions, the consenter signature staying
required and the doctor signature optional, identity taken from the visit and the patient record (AIR-RMC-35), the
timing gate, routes, permissions, roles, policies' code, schema, migrations, payment and invoice code, prescription
signature authority, and the front end (the canvases already draw far below the bound).

## 3. TDD record

RED first, the new suite against the untouched code at `4a1930fc` (SQLite, JUnit): **13 tests, 6 failed, 7 passed**
(51 assertions). Each failed for the reason it names: A-01 found no `max:1000000`; A-02 / A-03 saw the oversized
payload pass the request (the RED run used a null-returning decoder spy, which was reached; the tests now use the
real decoder, and mutants M1, M2 and M5 show they still fail without the request bound); B-01 / B-02 saw `sign()` accept a valid 1.12M-character PNG;
B-03 saw the over-long payload decoded and refused as "Data gambar tidak valid." instead of for its size. The seven
that passed are the behaviour 8D must keep (near-bound acceptance, malformed / blank / missing refusals, 403, branch,
timing gate).

GREEN after the change: **13 tests, 0 failed, 0 skipped, 78 assertions** (SQLite and PostgreSQL 16). After the
independent review's test findings (§7): **13 tests, 0 failed, 0 skipped, 81 assertions** on SQLite and on
PostgreSQL 16, where the adjacent suites (`RmeVisitConsent|RmePrescription|RmeClinicalWriteAuthority|
RmeClinicalDocumentReadScope`) also ran: **186 tests, 693 assertions, 0 failed, 0 skipped**.

Mutation check (SQLite; each mutant restored from the commit afterwards), run before and again after the review
round with the same result: **5 mutants, 5 killed, 0 survived**.

| Mutant | Killed by |
|---|---|
| request `max` removed from `consenter_signature` | A-01, A-02 |
| request `max` removed from `doctor_signature` | A-01, A-03 |
| service `maxLength` removed from the consenter decode | B-01, B-03 |
| service `maxLength` removed from the doctor decode | B-02, B-03 |
| request bound loosened to the generic `MAX_PAYLOAD_LENGTH` on both fields | A-01, A-02, A-03 |

## 4. The suite — `RmeVisitConsentSignatureBoundTest` (13 tests)

| Id | Proves |
|---|---|
| S-01 | Fixture honesty: the near-bound canvas (700×220 stored, ≈822K characters) is under 1,000,000 and the oversized one (700×300 stored, ≈1.12M characters, ≈840 KB decoded) is over it and under 3,500,000; both are valid, non-blank PNGs the decoder accepts under its generic limit, so only the signature bound can refuse the oversized one |
| A-01 | Both fields carry `max:1000000`; exactly 1,000,000 characters passes, 1,000,001 fails with the request message |
| A-02 / A-03 | An oversized consenter / doctor signature is refused at the request and nothing is written: consent rows, disk objects, the visit row and the finance tables (an existing receivable on another visit) are unchanged. The decoder is the REAL one, recording its calls (a pass-through partial mock): it is never reached, and without the request bound it would have accepted the canvas, so "nothing written" is earned by the request |
| A-04 | Consenter and doctor signatures at the real consent canvas size, just under the bound, still sign over HTTP; the visit stays `in_progress` |
| B-01 / B-02 | `RmeVisitConsentService::sign()` called directly refuses an oversized consenter / doctor signature with the decoder message and writes nothing; B-01 also shows the next valid consent is `…-000001` |
| B-03 | The service bound is inclusive: exactly 1,000,000 characters passes the size check (and is refused only as an invalid image), 1,000,001 is refused for its size before decoding (before 8D it was decoded and refused as "Data gambar tidak valid.") |
| B-04 | Near-bound signatures still sign through the service; the doctor comes from the visit |
| B-05 | Malformed, blank and missing signatures under the bound are still refused with their existing messages |
| C-01 | An account without `manage_rme_consents` still gets 403, whatever the payload size |
| C-02 | An operator pinned to another working branch is still refused |
| C-03 | A visit no longer in progress still cannot be consented |

The existing prescription signature suites (`RmePrescriptionIntegrityTest` I3-01…R-05) are unchanged and re-run (§5).

## 5. Verification

Environment: Windows 11 + WSL2 Ubuntu 24.04, PHP 8.3.6, Laravel 12, PostgreSQL 16.15. Tests ran with a
process-scoped disposable APP_KEY and no environment file (Pest prints every test as a warning without one; JUnit is
the count of record). SQLite is the in-memory test database. PostgreSQL ran in a dedicated, disposable schema
`mission_8d_test` inside the workstation's test database, never the development database; the schema was dropped at
the end of the mission and the test database's own `public` schema was not touched. One test process at a time per
worktree (CG-20).

**Targeted regression** — one filter covering consent (FormRequest, service, HTTP, gate, exam/pre-consent), canvas
decoding and prescription integrity, clinical write authority, clinical-document read scope (+ allowlist), clinical
evidence, doctor-patient and doctor-room scope, medical record, odontogram, document-number sequence locks, workflow
integrity and concurrent first writes:

| Database | Tests | Assertions | Failed | Errors | Skipped |
|---|---|---|---|---|---|
| SQLite | 1,223 | 3,617 | 2 | 15 | 28 |
| PostgreSQL 16 | 1,223 | 3,688 | 2 | 15 | 1 |

- The 17 failures and errors are all in four legacy-archive batch suites the `Odontogram` token also selects
  (`LegacyBatchPublishHttpTest` 1, `LegacyBatchPublishOdontogramTest` 8, `LegacyBatchReviewOdontogramTest` 6,
  `LegacyBatchReviewSubmissionContractTest` 2). **Pre-existing**: the same 17 fail identically on the untouched base
  tree (`9a32e51b`, = `4a1930fc`) on SQLite and on PostgreSQL. Likely environmental: the legacy PDF path inspects
  documents with Poppler, which this workstation does not have and CI installs. Not verified further (installing
  Poppler needs administrator rights); 8D touches none of those code paths.
- SQLite's 28 skips are 27 PostgreSQL-only concurrency tests (all ran and passed on PostgreSQL) and one Poppler skip.
  PostgreSQL's 1 skip is `MedicalRecordPrintOdontogramSeparationTest` "rme pdf export does not render the odontogram
  section either" (`poppler-utils (pdftotext) is not installed`): **SKIPPED, not passed**.
- Suites of record, identical on both databases unless noted: `RmeVisitConsentSignatureBoundTest` 13/13,
  `RmeVisitConsentGateTest` 25/25, `RmeVisitConsentHttpTest` 17/17, `RmePrescriptionIntegrityTest` 26/26 (the
  prescription signature tests are unchanged), `RmeClinicalWriteAuthorityTest` 18/18,
  `RmeClinicalDocumentReadScopeTest` 16/16, `RmeClinicalDocumentReadScopeAllowlistTest` 10/10,
  `RmeDocumentNumberSequenceLockTest` 20/20 on PostgreSQL (11 run + 9 PostgreSQL-only skips on SQLite),
  `RmeClinicalEvidenceWriteLockConcurrencyTest` 6/6 on PostgreSQL (all skipped on SQLite).

**Governance and contracts** (rules changed), SQLite: `DevflowAiInstructionSystemTest`,
`ProductionShellCommandGuardTest`, `DeployHardenRunnerEvidenceContractTest`, `Ent15EnterpriseDocumentationRunbookTest`
and all of `tests/Feature/Cicd` (includes `CriticalGateSuiteCoverageTest` and `ProductionHostIdentityGuardTest`):
**501 tests, 5,299 assertions, 0 failed, 1 skipped** (`PdfTempFileLifecycleContractTest`, Poppler, the same
pre-existing skip Sprint 0 recorded).

**DEVFLOW** (8D worktree, base `4a1930fc`):

| Command | Result |
|---|---|
| `devflow:base-ref-check --strict` | GO (remote base `4a1930fc`; the local base ref is stale and not used) |
| `sprint:manifest-check` | GO |
| `sprint:scope-audit --strict` | GO (3 modules: Consent, MedicalRecord, Odontogram; 4 `app/*.php` files) |
| `foundation:devflow-check --strict` | GO (registry 0 errors, 0 warnings) |
| `foundation:shared-service-audit --strict` | GO |
| `foundation:security-compliance-check` | GO 9/9 on a migrated disposable SQLite database with `APP_ENV=local` (the Sprint 0 method). With no environment file Laravel defaults to `production`, and then it reports FAIL 5/9 (ENT-5…ENT-8) — **identically on the untouched Sprint 0 tree**, so environmental, not 8D |
| `foundation:ci-runtime-control-check` | GO 6/6 under the same conditions; FAIL 5/6 (ENT-10) under the defaulted `production` env, identically on the untouched tree |
| `sprint:test-plan` | categories `rme`, `config`; FULL REQUIRED SUITE escalation (6 unmatched paths; `access_control`, `branch_context`, `config` force it). Fail-closed by design (rule 107); the Full Suite is **deferred, not run** |
| `./vendor/bin/pint --test` (changed PHP) | passed |
| `git diff --check` | clean |

No JavaScript, CSS or Blade changed, so no asset build. `sprint:test --focused` was not run: its filters select about
416 of 903 test files, close to the Full Suite the policy defers; the targeted suites above cover every required
category instead.

## 6. Canonical rule consistency (CODE = TEST = RULE = DOCS)

| Item | Before 8D | After 8D |
|---|---|---|
| Rule 228 §10.3 | "the 1,000,000 signature payload limit does NOT yet apply" to consent signatures | describes both layers, the unit, the test and its CI selection |
| Rule 228 §15 | two OPEN items (docblocks; consent signature LOW) | both CLOSED by 8D |
| Rule 228 globs | did not cover `StoreRmeVisitConsentRequest.php` | covers it (§10 now governs it) |
| AIR-RMC-53 | "canvases are bounded" | "canvases and consent signatures are bounded" (the file is at its 30,000-character budget, so the existing line was amended rather than a new invariant added) |
| Rule 228 §14 | listed only the prescription integrity suite | also lists `RmeVisitConsentSignatureBoundTest` |
| Rule 224 (open list) | "consent signature streams send no explicit `Cache-Control: no-store`" | marked CLOSED by 8C (rule 228 §11; the controller sends `private, no-store, max-age=0`). A stale line found by the 8D review, in the clinical-evidence rule this sprint reviewed; no code change |
| Sprint 0 drift #7 (§10) | open, assigned to 8D | resolved |

The other Sprint 0 drift findings are **not** in 8D's scope and stay as recorded there: #1 and #10 were corrected by
Sprint 0; #2 is immutable history; #3 (OD-5-9 consent signature disk), #4 (OD-7-1), #5 (OPS-KTP), #6 (OD-7-3 docblock),
#8 (RA-4) and #9 (OPS-Q, RA-5) wait on owner decisions. No new durable rule; no rule superseded.

## 7. Independent review

A separate read-only reviewer examined the candidate `1bbd6435` against base `4a1930fc`. It received the
owner-approved scope, a 14-point checklist and file pointers, not the author's conclusions, and was asked to prove the
change wrong. Static review only: it ran no tests (a test process held the worktree), and it measured the fixtures
standalone.

**Result: PASS WITH FINDINGS — 0 CRITICAL, 0 HIGH, 0 MEDIUM.** It confirmed: decoder unchanged and the constant
reused; both request fields bounded with matching messages, consenter still required and doctor still optional; both
service decodes bounded and the decoder only tightens; refusal precedes every side effect (lock, number, objects, row);
policies changed in comments only and the new docblocks match `RmeWorkingBranchScope` and rule 228 §5; no route,
permission, role, migration, finance or front-end change; the app's 700×220 canvases cannot reach the bound (an
uncompressed one is 821,802 characters, a noise-filled one about 823K); six tests fail without the fix and seven guard
existing behaviour; helper names unique; CI selection in both variants and the mandatory list; no PII or secrets;
code-only rollback safe.

| Finding | Severity | Resolution |
|---|---|---|
| Rule 228 and the manifest cite this record, which was not yet committed | LOW | Committed with the PR (this file) |
| No service-level test at exactly the limit | LOW | B-03 now pins both sides of the service boundary (1,000,000 passes the size check; 1,000,001 is refused) |
| A-02 / A-03 used a decoder spy returning null, so "nothing written" was not earned by the fix | INFO | Replaced by the real decoder, recording calls; the five mutants were re-run, all killed |
| "Characters" is exact for the request; the decoder's `strlen` counts bytes | INFO | Rule 228 §10.3 now states both (same for base64, stricter otherwise) |
| Rule 228 §14 not updated; §15 heading still "Open" | INFO | §14 lists the suite; §15 heading notes the two closures |
| Rule 224 still lists consent-signature `no-store` as open | INFO (pre-existing doc drift) | Marked CLOSED by 8C (rule 228 §11) |
| B-01's "no number consumed" adds nothing beyond "no row" (numbers are MAX-based) | INFO | Kept as documentation of the behaviour; not claimed as an extra guarantee |
| Finance snapshot covers invoices and payments only | INFO | Accepted: no finance code is reachable from `sign()` |

**Round 2** — the same reviewer, statically, on the review-round delta `1bbd6435..31446765` and this record: **PASS
WITH FINDINGS — 0 CRITICAL, 0 HIGH, 0 MEDIUM.** It confirmed the real-decoder tests and the two-sided B-03 boundary
(a 1,000,000-character payload decodes to 749,983 zero bytes and is refused only as invalid), the rule 228 / rule 224
edits against the code, the §8 audit claims against the code, the record's internal arithmetic, and that every new
module-index link resolves. Its two LOW and three INFO findings were all wording in this record (B-02 credited with
the number check; the unit sentence in §2; "byte-identical"; the header stating the production runtime as fact; the
RED line describing the old spy) and are corrected here. It could not re-run the counts reported in §3 and §5.

**Pre-existing, outside 8D's approved scope — recorded for an owner decision, not fixed here:**

1. **Orphan objects on a late failure in `sign()`.** The signature PNGs are written inside the transaction before the
   consent insert; if the second write, the insert or the commit fails, the row rolls back and the object(s) stay on
   the private disk. Not reachable through a refused signature (8D refuses before the transaction). A compensating
   delete (the rule 224 pattern) would be a separate change.
2. **Signature payloads flashed into the session on any validation failure.** Laravel's default `dontFlash` lists only
   password fields, so a refused consent form flashes its signature data URIs into the session store (the session
   driver defaults to `database`; the production driver is not verified here). Pre-existing for every consent
   validation failure, including the decoder's own refusals before 8D. Candidate fix: add the signature fields to
   `dontFlash`. Privacy-relevant (CG-11 spirit); needs approval.
3. **Validation before authorization on the consent POST.** `StoreRmeVisitConsentRequest::authorize()` returns `true`
   and the policy runs in the controller, so an operator holding `manage_rme_consents` but outside the visit's branch
   gets a validation redirect for a bad payload (403 only for a valid one), while a missing visit id answers 404. The
   reply depends only on the caller's own payload, so nothing new about the record is disclosed by 8D, but it is the
   same ordering class as R8A-2 and sits uneasily with CG-05's "404 where existence must not leak". Unchanged by 8D.

## 8. P8R-S6 — read-only audit (not implemented)

Owner scope: audit only; numbering and financial history are not touched. A separate read-only reviewer traced the
code and the key claims were re-checked in code by the sprint author. **Status: `AUDITED / OWNER_DECISION_PENDING`.**

| Question | Finding (code at `4a1930fc`) |
|---|---|
| Current behaviour | Invoice `RME-YYYYMM-NNNNNN` (`RmeInvoiceNumberGeneratorService`) and payment `RMEPAY-YYYYMM-NNNNNN` (`RmePaymentNumberGeneratorService`) take the month from `now()->format('Ym')`, i.e. UTC (`config/app.php` timezone `UTC`). Consent (`RmeVisitConsentNumberGeneratorService`) takes it from `ClinicalClock::today()` (Asia/Makassar). Each takes `lockNumberSequence($month)` (advisory 92_002 / 92_003 / 92_004, objid YYYYMM) inside the write transaction, then reads MAX of the numbers with that month prefix (invoice and payment include soft-deleted rows; no branch filter: one sequence per month across branches) |
| Reproducible? | Yes, from the code: from 00:00 to 07:59 WITA on the 1st of every month (16:00–23:59 UTC on the last UTC day) an invoice or payment carries the previous month's label. Example: 2026-12-01 03:00 WITA → `RME-202611-…`. No test pins the month: `p8bnumInvoiceMonth()` copies the generator's `now()`, and the factories use the UTC month too |
| Accounting depends on the prefix? | No logic found that parses or filters on it. The only prefix `LIKE` reads are the two MAX lookups. Other uses are display, free-text search, exact match, or frozen content (receipt snapshots with their hash, invoice snapshots, audit payloads). Reports and ageing filter on `created_at` / `paid_at` through ClinicalClock-based scopes |
| Uniqueness | Preserved. One `$month` per call drives the lock objid, the MAX filter and the prefix; different prefixes cannot collide; the number columns are UNIQUE (`trx_rme_invoices.invoice_number`, `trx_rme_payments.payment_number`) as a backstop |
| Switching to ClinicalClock: collisions? | None: same prefix → same lock and same MAX; numbers already issued with the previous month's label simply stay in that month's namespace. Mixed versions during a rolling change only interleave labels |
| Existing documents / renumbering | Not needed and not allowed (rule 227 §4: never renumbered; numbers are embedded in hashed receipt snapshots, printed documents and audit payloads). At most a read-only count of mislabelled rows, for disclosure |
| Isolatable? | Yes: the two generators (inject `ClinicalClock`), plus tests in `RmeDocumentNumberSequenceLockTest` (already selected by its own token in both critical variants and declared mandatory) |
| 2026-12-01 checkpoint | Applicable: the next window is 2026-11-30 16:00:00–23:59:59 UTC (2026-12-01 00:00–07:59 WITA), then every month. It matters only if invoices or payments are issued in those hours |
| Billing active in production? | **Not verified.** This workstation has no production SSH; no claim is made either way. "TESTING" database status is not evidence that no billing occurs |

**Found during the audit (not in the earlier record), out of 8D scope, recorded for the owner:**

1. **Pre-existing test-determinism defect (CG-20).** `RmeDocumentNumberSequenceLockTest` "never makes one sequence
   wait for another, nor for another month" computes the previous month with `createFromFormat('Ym', …)`, which fills
   in today's day. When the current UTC day does not exist in the clinic month it overflows. Reproduced in memory:
   at 2027-01-31 16:30 UTC the consent month is 202702 and the "previous" month also comes out as 202702, so the
   PostgreSQL-only probe would contend with the generator's own lock. Affected windows: the last UTC day of Jan, Mar,
   May, Aug and Oct, 16:00–23:59 UTC; the first is 2027-01-31. 2026-11-30 and 2026-12-31 are not affected. Fix
   (`'!Ym'` or an explicit day 1) is a test-only change needing its own approval.
2. **Three more id-suffixed prefixes use the UTC month:** `RMECOR` and `RMEOVP` (`RmeInvoiceCorrectionService`) and
   `RMEDSP` (`RmeBillingDisputeService`). They end in the row id, so uniqueness is not at stake; label only. `RMEREF`
   and `RMESTL` already use ClinicalClock.

**Proposed future corrective sprint (proposal only; needs an owner decision):** inject `ClinicalClock` into the
invoice and payment generators (optionally the three id-suffixed prefixes); switch `p8bnumInvoiceMonth()` to
ClinicalClock and fix the `'Ym'` overflow; pin with `Carbon::setTestNow` at 2026-11-30 15:59:59 UTC (→ 202611),
16:00:00 and 16:30:00 UTC (→ `RME-202612-000001` / `RMEPAY-202612-000001`, lock on 202612, 202611 free), the year
rollover at 2026-12-31 16:30 UTC (→ 202701), a pre-existing `RME-202611-000005` left unchanged, a soft-deleted top row
in the clinic month, and the 2027-01-31 overflow; run on PostgreSQL 16; update rule 227 §4 and AIR-RMC-49. Risks:
do not deploy inside a 16:00–23:59 UTC last-day window; for eight hours a month the label month differs from the UTC
`created_at` month by design. Operational evidence the owner would need first: whether RME billing is used in
production, the production clinical-timezone setting, whether issuance between 00:00 and 07:59 WITA is plausible,
and whether anyone outside the system reads the prefix.

## 9. Release, production and limits

- **Production deployment: NOT AUTHORISED** by this sprint's approval. No deploy, no runner invocation, no SSH, no
  production read or write. `.github/workflows/deploy-vps.yml` is `workflow_dispatch` only, so neither the PR nor a
  merge can deploy. This workstation has no production SSH, so the production runtime is **NOT VERIFIED** here; the
  last recorded runtime is the Program 8C release `b0c0bd62`.
- **GO tag `dms-rme-revision-program-8d-go`: NOT CREATED.** It follows merge, a separately authorised deploy (run on
  the verified Biznet host: `PRODUCTION_TARGET=VERIFIED`, `DEPLOY OK`, `DEPLOY_HEAD_TARGET_MATCH=YES`,
  `DEPLOY RUNNER OK`) and production verification (CG-15, CG-16).
- **Rollback:** code only (`scripts/rollback-vps.sh <commit>` on the host). 8D writes no data in a new shape; a
  revert only removes the tighter bound.
- **Client impact:** none expected. The consent canvases are 700×220 and the application exports them as PNG; even
  stored uncompressed, a canvas of that size is about 822K characters (test A-04 / B-04), under the bound.
- **Full Suite:** deferred by the global temporary policy (CG-18) — not run, not passed. Final RME readiness is not
  claimed. Sprint 0 stays `PARTIAL_GO`.
- **Status:** `SPRINT_8D_IMPLEMENTATION_GO / PRODUCTION_RELEASE_PENDING` only once the PR's exact-head CI is green;
  see the PR for the CI record.

## 10. PR2 — exact approved release SHA guard (2026-11-14)

The owner asked, as part of the 8D production release, for the production deploy to be bound to an exact approved
commit before 8D is deployed. That work is 8D PR2, recorded in
`docs/sprints/dms-rme-revision-program-8d-approved-sha-guard-1.md` (rule 229). The 8D release SHA is therefore the
merge commit of PR2, not `dde7b9b6`; it is deployed only after the owner's written approval of that exact SHA,
recorded here and in `deploy/release-approvals/dms-rme-revision-program-8d.approval`. §9 above stays true until then.

## 11. Owner release approval (CG-22) — 2026-11-13/14

Recorded from the owner's answers in this session (2026-11-13 22:38–22:40 UTC = 2026-11-14 06:38–06:40 WITA), after
PR #622 was squash-merged with the owner's separate merge approval (pinned to the tested head `ebd289e2`):

| Item | Owner decision |
|---|---|
| Target | Biznet production `DaengtisiaMS` (`139.190.106.38`), `/var/www/asia-dental-lab-v2` |
| Release SHA | `82f1eeba1ec25c81db3cefa1d2b235d022973904` (tree `1105b11115a609e2876b1a85286c12df1fa7ae0c`, identical to the tested PR head's tree; parent `dde7b9b6`) |
| Scope | Sprint 8D: consent signature payload bound (PR1, #621) and the rule 229 exact approved release SHA guard (PR2, #622); the docs commits `15583586` and `4a1930fc` are in the delta from production `b0c0bd62` |
| Window | "setelah SHA dan CI terverifikasi" — after the SHA and its CI are verified. No end was given; the approval record is capped at 2026-11-14 18:25 UTC (02:25 WITA on 11-15), before the next backup window, which is narrower than the approval, never wider |
| Service interruption | Permitted (the deploy restarts `php8.3-fpm` and reloads nginx) |
| Rollback authority | The owner decides first: on a failure after mutation, or a failed verification, the state is reported and a code-only rollback to `b0c0bd62` runs only after the owner's explicit go-ahead |
| Clinical smoke | Read-only only: no production data is created or changed; write-path behaviour is evidenced by CI and the PostgreSQL suites, and reported as such |
| Approval record | `deploy/release-approvals/dms-rme-revision-program-8d.approval` (rule 229), merged with this section; the owner authorized merging it as part of the deploy approval |

The deploy follows `docs/runbooks/production-host-identity.md` §6c (the production checkout runs `b0c0bd62`, whose
runner predates rule 229). It starts only after CI on `82f1eeba` is green on the exact SHA. Deployment, verification
and the GO tag are recorded after the fact; until then the release is `APPROVED FOR DEPLOYMENT`, not deployed.

