# FINAL-STABILIZATION-RESIDUAL-AUDIT-1

| | |
|---|---|
| Type | `DOCS_ONLY` — governance / test / fixture only |
| Base authority | `dccfbe7039547ab827882a0681717b432259cec2` (`storage-public-clinical-evidence-1-go`) |
| GO tag | `final-stabilization-residual-audit-1-go` |
| Runtime behaviour change | **false** |
| Full Suite | `DEFERRED_BY_GLOBAL_TEMPORARY_POLICY` — `FULL_SUITE_EXECUTION_COUNT=0` |
| Rule mirror | `.cursor/rules/121-final-stabilization-residual-audit.mdc` |

> **One sentence:** every residual the corrective series accumulated was
> reconciled against the *current* production authority, classified with
> evidence, and pinned — proving DaengtisiaMS is ready for its one authoritative
> consolidated Full Suite.

---

## 1. What this sprint is, and what it refused to be

This is an **evidence reconciliation** sprint, not a fix sprint.

The rule it operated under: if the audit proved an unresolved runtime, security,
clinical or data-integrity defect, the answer was **NO-GO plus a named
corrective sprint** — never a fix smuggled in under an audit heading. No such
defect was found, so the audit closed GO.

Three things it deliberately did **not** do, each of which would have been the
easy way to a prettier number:

- It did **not** change `Odontogram::hasRecordedTeeth()`. It measured it.
- It did **not** delete the 20 dangling storage rows. It classified them.
- It did **not** run the Full Suite, and did **not** manufacture restore-drill
  evidence to clear a WATCH.

---

## 2. Authority reconciliation

```
BASE_SHA                   = dccfbe7039547ab827882a0681717b432259cec2
CURRENT_GO_TAG             = storage-public-clinical-evidence-1-go
CURRENT_GO_TAG_PEELED_SHA  = dccfbe7039547ab827882a0681717b432259cec2
VPS_HEAD (pre-audit)       = dccfbe7039547ab827882a0681717b432259cec2   ✅ exact match
VPS working tree           = clean
```

Production was already sitting exactly on the tagged authority, so the audit
measured the real thing rather than a drifted approximation.

---

## 3. The residual ledger

Twenty-one unique residuals. Repeated references to the same item across docs and
rules were folded into one row rather than counted separately.

**Form note.** This is a table, not a chart, on purpose. The data's job here is
per-row lookup and traceability — each row must carry its own evidence and
closing authority. A chart would compress away exactly the part that makes a
ledger auditable.

| ID | Domain | Residual | Current evidence | Classification | Blocking | Closing authority |
|---|---|---|---|---|:--:|---|
| R-01 | Storage | Clinical evidence served from the public disk | writers **0**, public-URL readers **0**, public clinical objects **0**, private **55**, `source_objects_remaining=0`, nginx `/storage/` → **403**, governance test present and mutation-proven | **CLOSED** | No | `storage-public-clinical-evidence-1-go` @ `dccfbe7` |
| R-02 | Storage | 20 dangling DB → object references | all 20 are `sys_attachments` ids 1–20; `trx_lab_deliveries` POD_SIGNATURE ×9, POD_RECEIVER_PHOTO ×9, `trx_lab_orders` QC_PHOTO ×2; created 2026-06-04/05; 0 soft-deleted; read path `abort_unless($disk->exists($path), 404)` **after** authorisation | **ACCEPTED_RISK** | No | this sprint (§5) |
| R-03 | Odontogram | `hasRecordedTeeth()` may pass on a chart with no clinical content | production: 32 rows, 31 predicate-true, **31 of 31 carry a meaningful tooth status**, ambiguous **0**, eligibility delta **0**, history-visibility delta **0** | **CLOSED** (verified current contract) | No | this sprint (§6) |
| R-04 | Storage / Test | Fixture wrote clinical canvases to the public disk; writer scan missed `database/`; print assertion vacuous | reproduced: `on_public=true, on_clinical=false, rxUri_null=true`; fixed, scan extended, both guards mutation-proven | **CLOSED** | No | this sprint (§7) |
| R-05 | Governance | `.cursor/rules` number collisions — `92` ×8, `97` ×2, `100` ×3 (96 rules total) | filename prefixes only; rule *content* is uniquely named and loaded by filename, so no rule shadows another | **ACCEPTED_RISK** | No | this sprint (§8) |
| R-06 | Monitoring | `laravel_log = WATCH` on production | 7 errors in the 200-line window, **0 application errors**: `--skip-db` guessed-flag ×2 (2026-08-23 02:45) + one `psysh` write attempt (2026-08-23 18:04) — prior-session tooling debris | **ACCEPTED_RISK** | No | this sprint (§9) |
| R-07 | Monitoring | `queue_worker = UNKNOWN` | by design (MON-1: never fake green from an unreliable in-app source); `systemctl` reports **active + enabled**, `queue:failed` empty | **ACCEPTED_RISK** | No | `mon-1-…-go` |
| R-08 | Restore Drill | No restore-drill evidence on production | `read_state=absent`, `unsafe=false`, `decision=WATCH`, runbook present; clearing it needs a disposable staging DB, which this audit is not authorised to create | **DEFERRED** | No | ROLL-5-1A contract |
| R-09 | SATUSEHAT | SATUSEHAT-2 external submission unverified | GO tag `satusehat-2-…-go` verified **ABSENT**; `enabled`/`send_enabled`/`production_enabled`/`sandbox_verified` all default **false** | **BLOCKED_EXTERNAL** | No | — (awaits sandbox credentials) |
| R-10 | WhatsApp | Prescription delivery via Meta not activated | `WHATSAPP_ENABLED` default **false**, `WHATSAPP_DRIVER` default `disabled` | **BLOCKED_EXTERNAL** | No | — (awaits Meta credentials + template) |
| R-11 | Storage | Object-storage production cutover | STORAGE-1 scaffold OFF; production authority is the private **local** disk, which is safe and policy-gated | **DEFERRED** | No | separate authorisation |
| R-12 | Tests | 36 `markTestSkipped` calls | every one environment-conditional: pgsql-only ×10, Poppler-absent ×7, running-as-root/filesystem-mode ×6, absent production-smoke fixture ×6, misc environment ×7 | **ACCEPTED_RISK** | No | this sprint (§10) |
| R-13 | CI | Repository-wide Full Suite deferred | `GLOBAL TEMPORARY FULL-SUITE POLICY` **ACTIVE** since 2026-08-19; expires only per its §10 | **DEFERRED** (by policy) | No | rule 107 |
| R-14 | Tests | Prescription tests write to the *real* local clinical disk, so artifacts survive between local runs | reproduced — a stale `prescriptions/1/1/*.png` made a mutation run pass falsely until the directory was cleared | **ACCEPTED_RISK** | No | this sprint (§7) |
| R-15 | Governance | `FIX-LEGACY-RME-ROUTINE-OPS-1` has no GO tag | code **is** merged and deployed (PRs #315/#316); the tag was withheld because the Full Suite was deferred by owner decision | **ACCEPTED_RISK** | No | this sprint (§11) |
| R-16 | Governance | `FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1` has no GO tag | code **is** merged and deployed (PRs #321/#322); FIX-02 blocked on Meta credentials — carried as `inherits_hold` | **BLOCKED_EXTERNAL** | No | this sprint (§11) |
| R-17 | Source | `TODO` / `FIXME` / `HACK` / `XXX` in shipped source | scan of `app/`, `config/`, `routes/`, `database/`, `resources/` returns **0** | **NOT_APPLICABLE** | No | — (none exist) |
| R-18 | Storage | Empty `handwritings/`, `prescriptions/`, `lab-orders/` directory shells remain under the public disk | 0 files inside; `public_real_files=0`; nginx denies the prefix regardless | **ACCEPTED_RISK** | No | this sprint |
| R-19 | Governance | Commit `17d5ccf` subject reads "WATCH: Wave-1 not executed" | superseded by `2ffe00c` ("record the executed Wave-1 migration"), which is what the GO tag points at; immutable git history, correctly **not** rewritten | **SUPERSEDED** | No | `legacy-rme-pdf-roll-4-wave-1-…-go` |
| R-20 | CI | `RmePrescriptionTest` — and `run_rme_tests` generally — is selected by no required CI gate | verified empirically with `pest --list-tests` against the gate's own 36-token filter: `ClinicalEvidencePrivacyTest` → **22 selected**, `RmePrescriptionTest` → **0**; both runner variants' filters are byte-identical, so this is not drift | **ACCEPTED_RISK** | No | this sprint (§8a) |
| R-21 | Governance / CI | **4 tests failing on the GO-tagged authority** — sibling exact-list pins never updated when `STORAGE-R006` shipped | `STORAGE-R006` exists in `StorageGovernanceService` **at the base commit**, but only 1 of 5 pinning tests was updated; `Lb1`/`Stateless1`/`Replica1`/`CacheRedis` GovernanceIntegrationTest still pinned `R001–R005`. Reproduced in isolation; repinned; all 5 suites now **30 passed / 0 failed** | **CLOSED** | No | this sprint (§7a) |

### Totals

```
TOTAL_UNIQUE_RESIDUALS = 21

CLOSED           = 4    (R-01, R-03, R-04, R-21)
ACCEPTED_RISK    = 9    (R-02, R-05, R-06, R-07, R-12, R-14, R-15, R-18, R-20)
BLOCKED_EXTERNAL = 3    (R-09, R-10, R-16)
DEFERRED         = 3    (R-08, R-11, R-13)
REAL_DEFECT      = 0
SUPERSEDED       = 1    (R-19)
NOT_APPLICABLE   = 1    (R-17)
```

No item is left `UNKNOWN`, `TBD`, `MAYBE` or `REVIEW LATER`.

### Blocker table

| Blocker type | Count |
|---|---:|
| Real defects | **0** |
| Critical security | **0** |
| High security | **0** |
| Required CI | **0** |
| Deployment | **0** |
| Privacy | **0** |
| Clinical correctness | **0** |
| Data loss | **0** |

---

## 4. Storage incident — proven closed, four independent ways

The incident is not "closed because the sprint said so." It is closed because
four independent lines of evidence agree, and each was measured on the current
authority.

**Source.** Zero writers and zero public-URL readers, searched by every binding
form — `disk('public')`, `store(…,'public')`, `storeAs(…,'public')`,
`storePublicly*`, `public_path()`, `asset('storage/…')`, `Storage::url()` and a
bare `/storage/` literal. The single `/storage/` hit in `app/` is the word
"storage" inside a docblock in `HealthCheckService`.

**Production filesystem.** `public_real_files=0` — the public disk holds only
`.gitignore`. `clinical-evidence-private` holds **55** objects: 46 handwriting,
8 prescription/signature, 1 lab attachment. That is exactly the 55 patient-linked
artifacts the incident enumerated, so the migration neither lost nor invented one.

**Verifier.** The command's read-only dry run (its default — it changes nothing
without `--apply`) reports:

```
objects_seen                          0
db_references_checked                63
db_references_resolved               43
db_references_unresolved             20
db_references_dangling_before_migration  20
db_references_broken_by_migration        0
source_objects_remaining              0
decision                             OK
```

**Defence in depth.** `location ^~ /storage/ { deny all; }` is present in the
live vhost and **effective**: two probes over the canonical domain — a
non-existent file and the bare directory, both non-PII — return **403**.

> A note on how nearly this was mis-reported. The first nginx check ran
> `grep -r` over `/etc/nginx/sites-enabled/`, which returned nothing and looked
> like a missing control. `grep -r` does not follow symlinks encountered during
> directory traversal, and every entry in `sites-enabled` is a symlink. The
> deny had been there the whole time. A blocker was almost filed against a
> control that was working; the lesson is that "the check found nothing" and
> "the thing is not there" are different claims.

No real patient object was ever retrieved to prove any of this.

---

## 5. The 20 dangling references — classified, not cleaned

```
DANGLING_DB_REFERENCE_COUNT       = 20   (re-measured, not carried over)
SOURCE_OBJECT_NEVER_EXISTED       = yes — absent from the source disk too
CURRENT_UI_REACHABLE              = yes (a download link may exist)
CURRENT_500_RISK                  = none
CURRENT_DATA_LEAK_RISK            = none
CURRENT_WORKFLOW_BLOCK_RISK       = none
CLASSIFICATION                    = ACCEPTED_RISK
```

**What they are.** All 20 are `sys_attachments` rows, ids 1–20, created
2026-06-04 and 2026-06-05 — the earliest days of the lab pilot. Nine are
`POD_SIGNATURE`, nine `POD_RECEIVER_PHOTO`, two `QC_PHOTO`. None is
soft-deleted. The 21st attachment (2026-07-12) resolves normally, and every
handwriting and prescription reference — 42 of them — resolves.

**Why they are not a migration failure.** Their objects were absent from the
*source* disk before the migration ran. The migration did not break them and
cannot repair them. The command counts them separately for exactly this reason:
folding them into failures would leave the decision permanently red, which is
how a gate stops being read.

**Why access fails safely.** `AttachmentDownloadController::show()` authorises
first, then does `abort_if($path === '', 404)` and
`abort_unless($disk->exists($path), 404)`. The clinical disk is configured
`'throw' => true`, so an *unguarded* read would have surfaced as a 500 — which
is why every read path was checked, not assumed. They are all guarded:
`ClinicalEvidenceStorage::dataUri()` returns `null` on an absent object so a
template renders an honest "not available", `::exists()` guards likewise, and
the only two raw `disk()->` call sites in `app/` are both `put()`.

**Why they were not deleted.** They are historical Lab-V1 records. Lab Workflow
V2 stores its evidence in `trx_lab_workflow_evidence`, a different table, so no
current workflow depends on them. Deleting audit rows to improve a count is data
destruction dressed as remediation.

**Trigger to reopen:** if the count rises above 20, or if any dangling reference
appears in a table other than `sys_attachments`, that is new breakage and must be
investigated as a defect rather than inherited as this accepted risk.

---

## 6. `hasRecordedTeeth()` — audited read-only, deliberately unchanged

```
HAS_RECORDED_TEETH_AUDIT

MEANINGFUL_ROWS                      = 31
EMPTY_PLACEHOLDERS                   = 1   (null payload)
AMBIGUOUS_ROWS                       = 0
ELIGIBILITY_DELTA_IF_CHANGED         = 0 rows
HISTORY_VISIBILITY_DELTA_IF_CHANGED  = 0 rows
REAL_DEFECT_REPRODUCED               = false
CLASSIFICATION                       = CLOSED / VERIFIED_CURRENT_CONTRACT
```

The predicate returns true when `payload['teeth']` is a non-empty array. In
theory a chart of entirely `normal` teeth would pass it while carrying no
finding. The question is whether that happens in production.

It does not. Of 32 odontograms, 31 pass the predicate and **all 31** contain at
least one tooth whose status is outside `{'', normal, untouched, unknown}`.
Zero rows are ambiguous. Tightening the predicate to require a meaningful status
would reclassify **no row**, change **no** legacy-cutoff boundary and hide **no**
history entry.

> The first measurement of this was wrong and was caught before it was reported.
> The initial query tested `jsonb_typeof(teeth) = 'array'` and returned
> `predicate_true = 0`, which would have implied the predicate never matches
> production. Production stores `teeth` as a JSON **object**, and PHP's
> `is_array()` is true for a decoded JSON object as well as an array — so the
> SQL was not equivalent to the PHP it claimed to model. Re-run with an
> object-or-array predicate, the true figure is 31. The lesson is that a
> surprising zero deserves a second look at the instrument before it becomes a
> finding.

The predicate's own docblock states it errs toward keeping the archive boundary,
and treating a sparse chart as content only ever keeps a boundary. That contract
holds and is left exactly as it is. **Do not "fix" this predicate on theory
alone** — any future change must first re-run the measurement above and show a
non-zero delta.

---

## 7. What the audit changed, and why that is still an audit

Three files. `RUNTIME_BEHAVIOR_CHANGE=false` — nothing under `app/`, `config/`,
`routes/` or `database/migrations/` was touched.

**The finding.** `RmePrescriptionFactory` wrote prescription and
doctor-signature canvases to the `'public'` disk while
`RmePrescriptionService::storeCanvas()` writes them through
`ClinicalEvidenceStorage`. This was never an exposure — nothing in production
invokes a factory, no seeder references it — but it reproduced, inside the test
suite, the exact storage layout the incident removed.

**The consequence, which is the part that matters.** Because the fixture wrote
to a disk the read path does not consult, `prescriptionCanvasDataUri()` returned
`null`. Proven, not inferred:

```
on_public = true   on_clinical = false   rxUri_null = true   sigUri_null = true
```

The test `it('can print prescription with fields and canvas images')` then did
`assertSee($rxUrl, false)` with `$rxUrl === null`. `assertSee` casts `null` to
`''`, and every response contains `''`. **The test could not fail when there was
no canvas image.** A test named for the thing it does not verify is a false
green, and false greens are what this whole corrective series exists to remove.

**The fix.** The fixture now writes through `ClinicalEvidenceStorage`; the test
asserts both data URIs actually start with `data:image/` before asserting the
response contains them; and the anti-regression writer scan in
`ClinicalEvidencePrivacyTest` now covers `database/` as well as `app/`. `tests/`
stays excluded on purpose — that file writes to the public disk deliberately, to
prove the migration moves objects off it.

**Mutation-proven.** With the factory reverted to the public disk, the scan fails
and the canvas assertion fails. Both guards bite.

> The first mutation run **passed**, which would have meant the new assertion was
> useless. It passed because an earlier run had left `prescriptions/1/1/*.png` on
> the real clinical disk and the fixture reuses ids 1/1, so the mutated run read
> a stale file. The directory was cleared and the mutation re-run, at which point
> it failed correctly. That pollution is recorded as **R-14**: these tests write
> to the real local disk rather than a faked one, so local reruns can mask
> failures. CI runs on a fresh checkout, so it is not a CI-correctness issue —
> but it is a real trap for anyone mutation-testing this area, which is precisely
> why it is in the ledger instead of being quietly forgotten.

---

## 7a. Four tests were already failing on the GO-tagged authority

This is the finding that justifies running an audit before the Full Suite rather
than trusting that the series was clean.

`STORAGE-PUBLIC-CLINICAL-EVIDENCE-1` added a sixth rule, `STORAGE-R006`, to
`StorageGovernanceService`. Five Architecture tests pin the storage rule list
**exactly**. The sprint updated one of them — `Storage1GovernanceIntegrationTest`
— and left four still asserting `R001–R005`:

```
Lb1GovernanceIntegrationTest.php:32
Stateless1GovernanceIntegrationTest.php:30
Replica1GovernanceIntegrationTest.php:31
CacheRedisGovernanceIntegrationTest.php:36
```

**These were failing on `dccfbe7` — the current GO tag, the commit running in
production.** Verified pre-existing rather than assumed: `STORAGE-R006` is present
in the service at the base commit (`git show dccfbe7:…` confirms), the stale
five-element pin is present in the test at the base commit, and this sprint's diff
touches neither file.

**Why nothing caught it.** The Critical gate's 36-token filter does not select
these suites, and the repository-wide Full Suite has been deferred since
2026-08-19. A test can fail for days when the only gate that runs it is switched
off — which is precisely the condition this audit existed to check before that
gate is switched back on.

**Fixed as audit-owned work**: the four pins now include `STORAGE-R006`, and the
service's own docblock — which still said "Publishes the STORAGE-R001..R005
rules" while publishing six — was corrected. That docblock is the only change
this sprint makes under `app/`, and it is a comment: no executable statement
changed, `RUNTIME_BEHAVIOR_CHANGE` stays **false**. The runtime was always
correct; only the assertions and the comment describing it were stale.

Verified: the five governance-integration suites now run **30 passed / 91
assertions / 0 failed**.

**Swept for siblings rather than fixing only the one that failed.** A script
compared every governance service's published rule ids against every exact-list
`toBe([...])` pin in `tests/`. It flagged the OBS family as stale too — a **false
positive**: OBS-1 and OBS-2 are deliberately separate services publishing
`OBS-R001..R012` and `OBS-R013..R024`, and each test correctly pins its own half.
Re-run keyed by service file rather than by id prefix, every pinned set matches
exactly one service. The four STORAGE pins were the only real staleness.

**Durable trigger:** adding a rule to any governance service means updating
*every* exact-list pin for that family, not just the one named after the sprint.

---

## 8. Rule-file number collisions

96 rule files, with prefix `92` used 8 times, `100` 3 times and `97` twice. Rules
are loaded by filename and each file's *content* is uniquely named, so no rule
shadows or overrides another — the collision is an index-hygiene problem, not a
behavioural one. Renumbering 13 files would break every existing cross-reference
to them in docs, sprint records and memory for no behavioural gain, so the
numbers are left alone and the collision is recorded here instead.

**Trigger to act:** if a future tool ever resolves rules by numeric prefix rather
than filename, this becomes a correctness issue and must be renumbered in one
deliberate pass that also rewrites the references.

---

## 8a. What actually runs in required CI

The audit's own guards were checked against the gate rather than assumed into it.

`tests/Feature/Storage/ClinicalEvidencePrivacyTest.php` is a registered member of
`critical_gate_mandatory_suites` — the registry that exists precisely because "a
control that exists but is never selected by the gate is not a control."
Empirically, the Critical gate's 36-token filter selects **22** of its tests,
including the writer scan this sprint extended to `database/`. Both runner
variants carry byte-identical filters, so the protection is real on whichever
runner the classifier picks.

`RmePrescriptionTest` is selected by **0** gates: it is not in the mandatory
registry, and while the classifier does emit `run_rme_tests=true`, no job
consumes that output. That is pre-existing and deliberate — the registry's own
doctrine is that "adding a suite is a governance decision, not a reflex" — so it
is recorded as **R-20** rather than reflexively appended to the filter. The
hardened canvas assertion is therefore covered by the consolidated Full Suite
that runs next, not by a required gate today.

The classifier resolves this diff to **`unknown_high_risk`**, because
`database/factories/` is not a recognised path and uncertainty always resolves to
the stronger gate. Every module suite and the build run as a result.

**Trigger to reconsider:** if a future change makes the prescription canvas path
security- or privacy-relevant, promote the suite into
`critical_gate_mandatory_suites` deliberately — and verify the promotion in
**both** runner variants, because a token added to one is protection in neither.

---

## 9. `laravel_log = WATCH` is correct, and the monitor should stay noisy

The WATCH is not a monitoring defect. It is the monitor doing its job.

The 7 errors in the window are **0 application errors** and 3 distinct pieces of
tooling debris from earlier sessions:

- `The "--skip-db" option does not exist` ×2 — a guessed Artisan flag.
- `Writing to directory /nonexistent/.config/psysh is not allowed` — a `tinker`
  attempt as a user with no home directory.

Both are the exact hazards the project already has rules about: do not guess
Artisan flags on production, and do not run `tinker` on production. Neither
indicates anything wrong with the application.

The log was **not** truncated or edited — deleting evidence to clear a WATCH
would be the worst possible response, and the entries age out of the window on
their own.

**This audit added zero.** Baseline before any audit command: `laravel.log`
size `786268`, 124 `ERROR` lines. After the complete audit — psql aggregates,
the migration dry run, seven strict governance commands, health probes:
size `786268`, 124 `ERROR` lines. Byte-identical.

```
NEW_APPLICATION_ERRORS = 0
NEW_TOOLING_ERRORS     = 0
```

Achieved by reading command signatures from source before invoking anything, and
by using the migration command's read-only dry-run default instead of `tinker`.

---

## 10. Test skips are environment guards, not hidden debt

All 36 `markTestSkipped` calls are conditional on the environment, never on a
failing assertion: PostgreSQL-only assertions (×10), Poppler not installed (×7),
running as root or a filesystem that will not enforce a mode (×6), a production
smoke fixture that is legitimately absent (×6), and other environment guards
(×7). None disables a test to hide a failure.

---

## 11. Two merged sprints without a GO tag

`FIX-LEGACY-RME-ROUTINE-OPS-1` (PRs #315/#316) and
`FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1` (PRs #321/#322) are both **merged into this
authority and deployed**. Their tags are absent because the Full Suite was
deferred by owner decision, and for the latter because FIX-02 waits on Meta
credentials.

The missing tag is a governance record, not missing code. Both are carried
forward explicitly in the manifest as `inherits_closure` / `inherits_hold` so
the consolidated Full Suite inherits the correct posture. `SATUSEHAT-2`'s tag is
likewise verified **absent**, which is the correct state — it was never created.

---

## 12. Readiness verdict

```
FINAL_AUDIT_DECISION = GO

READY_FOR_AUTHORITATIVE_CONSOLIDATED_FULL_SUITE = true
FULL_SUITE_EXECUTION_COUNT = 0
FULL_SUITE_STATUS = DEFERRED_BY_GLOBAL_TEMPORARY_POLICY
```

This certifies readiness. It does not run the Full Suite and must not. The next
stage — `AUTHORITATIVE-CONSOLIDATED-FULL-SUITE-AND-CLOSURE-1` — requires
explicit user authorisation.

---

## 13. Durable rules this sprint pins

1. **Clinical evidence never reaches a publicly served disk** — and the scan that
   enforces it covers `app/` **and** `database/`. A fixture is not exempt.
2. **A test that computes a value and asserts the response contains it must
   first assert the value is real.** `assertSee(null)` passes against anything.
3. **The global `/storage/` deny is retained defence in depth.** A future
   genuinely-public asset needs an explicit delivery decision; the deny is not
   removed because a new asset failed to render.
4. **A `SECURITY_FIX` is never downgraded to a lower audit type to satisfy a
   scope-size threshold.** A justified exception is documented with its causal
   blast radius, as STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 did.
5. **The 20 dangling `sys_attachments` references are accepted, not normalised.**
   Broken references are not acceptable in general; these specific historical
   rows are, because access fails closed at 404 after authorisation.
6. **Object-storage cutover and external activations (Meta, SATUSEHAT) are
   separate authorisations**, not unresolved internal engineering defects.
7. **`Odontogram::hasRecordedTeeth()` is not changed on theory.** Any proposal
   must first re-measure production and show a non-zero reclassification delta.
8. **Never `tinker` on production and never guess an Artisan flag** — both write
   real `ERROR` lines and pin the log monitor to WATCH.
9. **Verify a negative before reporting it.** `grep -r` does not follow symlinks;
   a surprising zero is a reason to check the instrument first.
