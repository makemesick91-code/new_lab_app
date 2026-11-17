# DMS-RME-COMPREHENSIVE-CLOSURE-1 — comprehensive RME audit, remediation and release

**Date:** 2026-11-16 (WITA = UTC+8). **Type:** SECURITY_FIX (Foundation-First Sprint Lock: allowed category security
fix / clinical correctness). **Base:** `a7b0bc99` (remote base tip, PR #645). **Rule:**
`.cursor/rules/237-rme-comprehensive-closure.mdc`. **Previous RME record:** [[dms-rme-revision-program-8d]] (GO,
`82f1eeba`), register source [[dms-rme-revision-sprint-0-evidence-recovery]].

Full Suite deferred under the global temporary policy (CG-18): **not run, not passed**.

## 0. Status

```text
PROGRAM_STATUS            = DEPLOYED / PRODUCTION VERIFIED / PROGRAM NO-GO — PENDING CLOSURE (owner, 2026-11-16, §2b)
RELEASE 1                 = DEPLOYED + VERIFIED: cab3a4c1 (PR1 #646 + PR2 #647), deploy 20261116-141906 (§9.3)
CLOSURE WAVE              = IN PROGRESS: PR #648, #649, #650, #651, #652, PR6, PR7b (§5.3, §9.4)
GO tag                    = NOT CREATED (dms-rme-comprehensive-closure-1-go): withheld by the owner until every confirmed
                            in-scope pending item is resolved and verified in production
FULL_SUITE_STATUS         = SKIPPED_BY_GLOBAL_TEMPORARY_POLICY (execution count 0)
BK-2                      = NOT RUN (manual-only directive, rule 232)
GLOBAL DOCTOR ENFORCEMENT = UNCHANGED (not touched; no flag, cohort or scope change)
SATUSEHAT                 = DISABLED (unchanged; Rule 88 §2 / AIR-SSH-04 kept until official activation)
```

## 1. Scope, authority, environment

The owner's brief (2026-11-16) orders a full audit of the RME module, an inventory of every RME pending item, the
remediation of every confirmed item that existing requirements and authorizations allow, and the full release
chain up to a GO tag, with explicit owner approval for new clinical product decisions, release signing and
rollback. It does not authorize global doctor enforcement, a pilot expansion, BK-2 or any production data change.

| Item | Measured |
|---|---|
| Base | `a7b0bc99c5450c4bfc6fb9d302e3ccef0fa9c4ec` (`devflow:base-ref-check --strict`: remote tracking ref; the local base ref is stale and not used) |
| RME application code since the 8D release | unchanged: `git diff 82f1eeba a7b0bc99 -- app resources routes config database` touches deploy/rollback code only |
| Worktrees | PR1 `fix/dms-rme-comprehensive-closure-1`, PR2 `fix/dms-rme-comprehensive-closure-1-pr2`, both cut from the remote base with `--no-track` (CG-02) |
| Test databases | PostgreSQL 16.15 (local socket), mission-owned schemas `rmecc1_pr1` / `rmecc1_pr2` in the test database; SQLite in memory for governance suites |
| Graphify | 0.9.99, baseline `graphify update .` on the base tree: 58,933 nodes, 143,245 edges, 2,421 communities (2 m 50 s); `graphify-out/` is git-ignored |
| Audit method | the Sprint 0 register re-checked against the code at `a7b0bc99`, plus three read-only audit passes (patient identity / branch context; device pilot, SATUSEHAT, queue, KTP timer; consent / completion / odontogram / print / prescription / cashier / handwriting / N+1). Every finding used below was re-verified in code by the author before it was classified |

## 2. Owner decisions (CG-22) — 2026-11-16, this program's session

Asked through the session's structured question tool; answers verbatim:

| # | Item | Question (short) | Owner answer |
|---|---|---|---|
| 1 | P8R-S6 | Invoice/payment (and RMECOR/RMEOVP/RMEDSP) month from UTC; fix now? | **Fix now** |
| 2 | OD-5-6 | New RM sheet on any consented visit, or only on the current encounter? | **Current encounter only** |
| 3 | Unsigned prescription print | Keep print before the named doctor signs, or refuse? | **Keep print allowed** |
| 4 | OD-7-1 | Kontrol without carry-over completes on a PARTIAL invoice; code or rule wins? | **Code wins, fix rule text** |
| 5 | Prescription edited after signing | Lock after signing / edit clears signature / keep | **Lock after signing** |
| 6 | OD-5-9 consent signature storage | Separate storage sprint or include now? | **Separate storage sprint** |
| 7 | Patient MRN override and patient-form branch | Measure first or restrict now? | **Measure first** |
| 8 | OPS-KTP timer and OPS-Q queue timing | Fix timing in code and give root commands, or leave? | **Fix timing in code + give commands** |

The brief itself orders the remediation of every confirmed RME pending item that existing requirements allow
("KERJAKAN SELURUH PENDING YANG TERKONFIRMASI DALAM CAKUPAN PROGRAM RME"). Sprint 0 had recommended FIX, with
acceptance criteria, for OD-7-6, OD-5-4 and OD-5-10(a), tightening-only changes that follow existing canonical rules
(the separate archive scopes of FIX-04b, rule 200's deactivation semantics, billing's "a cancelled parent is a label,
not a follow-up"). They were implemented under that brief, and the owner then confirmed them explicitly (§2a).

### 2a. Owner confirmations and merge approvals

| When (2026-11-16) | Question | Owner answer |
|---|---|---|
| after PR #646 CI | Squash-merge #646 pinned to the tested head `37828b4f`? | **Yes, squash-merge #646** |
| same question set | Confirm OD-7-6, OD-5-4 and OD-5-10(a), implemented under the brief | **Confirm all three** |
| after PR #647 CI | Squash-merge #647 pinned to the tested head `bf48e9d5`? | **Yes, squash-merge #647** |
| after post-merge CI on `cab3a4c1` | Written approval of the release SHA `cab3a4c1082923d92b665238eb72034b4af13e34` | **Approve this release** |
| after production verification | GO and the tag on `cab3a4c1` with the residual register? | **NO-GO for now** |

Closure-wave merges (2026-11-17), each asked separately and pinned with `--match-head-commit` to the head whose
critical gate is recorded in §9.4:

| PR | Tested head | Owner answer | Squash commit on the base |
|---|---|---|---|
| #651 | `9697d00a` | **Yes, squash-merge #651** | `5f4e7b75` |
| #652 | `daf88277` | **Yes, squash-merge #652** | `5f55e776` |
| #648 | `803db2e4` | **Yes, squash-merge #648** | `f97b906e` |
| #649 | `71c1375c` | **Yes, squash-merge #649** | `9f32bc83` |
| #650 | `0d701e35` | **Yes, squash-merge #650** | `01426e72` |
| #657 | `85527d84` | **Yes, squash-merge #657** | `0d265631` |
| #653 | `f44453a5` | **Yes, squash-merge #653** | `22d9c872` |
| #654 | `0aa2b771` | **Yes, squash-merge #654** | `07ebe300` |
| #655 | `af653175` | **Yes, squash-merge #655** | `d62408fd` |
| #656 | `e11074fe` | **Yes, squash-merge #656** | `82cb6e13` |

The base tree after #649, after #654 and after #656 equals the sequential merge simulation exactly; the final tree
`eb3035ad` is the tree of the locally integrated run (§8b).

Release 2 owner decisions (2026-11-17, structured question tool and written decisions "RELEASE 2 OWNER DECISIONS",
"CONSENT STORAGE FALLBACK DECISION"):

| Question | Owner answer |
|---|---|
| Release 2 at `c705f048` | **APPROVE**, conditional: required CI green on the exact SHA; approval signed by the owner (Contract 3); both migrations tested and compatible with production data; pre-deploy database backup succeeded; the runner on the Biznet VPS; production verification passes. Release 2 is an intermediate release; **no final GO tag** while the memo wave and every mandatory pending item are open |
| Consent signature copy | **Show me the dry run first**: read-only dry run reporting files, conflicts, verification failures, missing sources and checksums; no `--apply` before the owner approves the dry run; do not remove or disable the old read fallback before the needed copies are migrated and verified |
| Conflict found: `c705f048` had no read fallback (rule 121 §5, #648) | **Add temp fallback first**: a read-only fallback for historical signatures (new disk first; the old location only while the object is not on the new disk; nothing changed, copied, deleted or overwritten; authorization and BranchContext kept; regression tests for missing / invalid / unauthorized / fallback); required CI and a security review; then a NEW release SHA with post-merge CI and a NEW Contract 3 approval — the approval of `c705f048` is not reused; deploy on the Biznet VPS; pre-deploy backup; BK-2 SKIPPED; after the deploy: dry run, report, wait for approval; the fallback is removed only after every copy is verified and a separate approval |
| Docs PR #659 | **APPROVE — merge after release 2 production verification**; the record states release 2 VERIFIED, memo wave PENDING, the program OPEN, the final GO tag not allowed, and every pending item with an owner, acceptance criteria and evidence requirements |

The fallback is PR #661 (rule 237 §14.5a).

| When | Question | Owner answer |
|---|---|---|
| After #661 merged (post-merge push run 44023053296 on `0f3c3bb3`: critical gate 10,664 passed / 0 failed, PostgreSQL) | Written approval of the NEW release-2 SHA `0f3c3bb32a3a6b0e8a90f8ab9e43ca9fd7cc3ab3` | **Approve 0f3c3bb3** |

### 2b. Owner decision NO-GO (2026-11-16) and the closure wave

The owner recorded the program as **DEPLOYED / PRODUCTION VERIFIED / PROGRAM NO-GO — PENDING CLOSURE** and ordered
the remaining pending work completed through the full DEVFLOW: "Jangan menganggap DEFERRED sebagai RESOLVED. Jangan
mengubah scope secara diam-diam agar seluruh gate terlihat PASS." The GO tag waits until every confirmed in-scope
pending item is resolved and production verification succeeds. The order named eight work items: D5-9 consent storage
(OD-5-9), patient MRN audit and measurement, SATUSEHAT latent items without activation, the D4 ceremony with an
authorized operator, D5 branch confirmation by valid human confirmation, the blank odontogram print, per-row
authorization queries and the governance scanner. The constraints of the brief stay in force (no BK-2, runner on the
VPS only, no global enforcement, no key or passphrase access, no automatic rollback, no fabricated evidence).

Owner answers in the closure wave (structured question tool, 2026-11-16; verbatim labels, the owner's follow-up text
summarised):

| Item | Owner answer | Owner conditions (follow-up message) |
|---|---|---|
| R8A-2 (+ 8D finding 3) | **Fix** | authorization before validation, with negative tests |
| OD-6-1 | **Fix with page revision** | optimistic concurrency per page, a reload message, two-device tests |
| OD-5-8 | **Duplicate guard + supervised void** | a dedicated permission, a written reason, an audit trail, maker ≠ checker as the rules require, historical consents never deleted, re-sign only after a valid void, race and authorization tests |
| OD-5-5 | **Refuse while in_progress** | an atomic check under a lock, a message naming the active visit, concurrency and regression tests |
| OD-7-5 | **Backfill** | "Jangan menjalankan --apply pada production tanpa approval saya" |
| OD-7-7 | free text | audit and fix without silently changing rule 153 |
| R7-1 | free text | check the evidence; add or verify tests |
| RA-1 | free text | accepted if the replacement CI on the same SHA is green |
| OD-5-10(b) | free text | accepted by design if canonical |
| OD-7-3 | **Keep allowed** | do not widen legacy odontogram access or cross-branch access |
| D4 | **Not available yet** | never treat pilot devices or real doctor accounts as test-only; audit readiness; BLOCKED_EXTERNAL with the procedure if unavailable; no global enforcement |
| OD-5-9 | NO-GO order, item 1 (supersedes answer 6 of §2) | "Audit dan implementasikan D5-9 Consent Storage sesuai aturan RME" |
| CC1-ODO | **Keep empty state** | accepted by design, with references to the existing tests |
| CC1-SAT-1a | **Keep canonical design** | Rule 88 §2 / AIR-SSH-04 kept; disabled until official activation; negative tests must prove nothing is sent while disabled |
| CI workflow scope | **I will grant workflow scope** | least privilege; "Jangan meminta atau membaca token saya"; change no workflow beyond what is needed and reviewed; register the SATUSEHAT tests on the critical gate; Full Suite DEFERRED is never PASSED |
| R7-1-FS | **Fix first-sheet race** (2026-11-17) | with focused, authorization, concurrency and regression tests |

### 2c. Owner decisions on the internal memo of 2026-10-17 (2026-11-17)

Asked through the structured question tool, then confirmed in three written owner decisions ("OWNER REQUIREMENTS
DECISION", "OWNER DECISIONS M3/M4", "OWNER DECISIONS M4/M5/M6"). They revise earlier canonical rules on purpose;
each revision is made explicitly in the rules after an impact analysis, never silently. Traceability:
[[dms-rme-comprehensive-closure-1-memo-traceability]].

| Memo item | Owner answer | Owner conditions (written decision) |
|---|---|---|
| M4.1 RM number | **Auto, read-only** | generated automatically, FO cannot edit it, DaengtisiaMS format kept, locking + database uniqueness, existing numbers never changed, Legacy RME compatibility kept, CC1-PAT-1 measured before implementation, safe overflow handling, the Sprint 23.8 manual-number rule revised explicitly |
| M1.1–M1.3 PTM | **Both signatures gate writing** | patient signs through FO, doctor through the doctor dashboard; both required before RME and odontogram writing; "Mulai Pemeriksaan" before consent kept; permitted history reading stays; no clinical edit before both signatures are valid; consent audit trail and supervised void kept; affected canonical rules updated |
| M2.1–M2.2 Doctor access | **Own encounter, history inside it** | a doctor handles only assigned encounters; relevant clinical history read-only during a legitimate service; never edit another doctor's encounter; after the service, access follows the encounters the doctor handled; a reassignment revokes the old access through valid state transitions; impact on Legacy RME, BranchContext, room assignment and session locking audited; regression and negative authorization tests |
| Immediate | **All four**: hide payment totals, treatment on worklist, transfer reference required, fix first-sheet race | focused, authorization, concurrency and regression tests |
| M3.1 Doctor fields | **Memo list + RM number** | name, RM number, place and date of birth, age, occupation, planned treatment; phone, WhatsApp, address, email and NIK hidden from doctor screens; NIK stays on the consent document where the rules require it |
| M4.2 Required fields | **Core fields, NIK optional** | core administrative fields mandatory for new patients; NIK and KTP scan optional; existing patients never blocked by incomplete history; a controlled exception for patients without a phone number |
| M2.4 Doctor downloads | **Deny archive + PTM print** | doctors read permitted archives but never print or download Legacy RME or the PTM; native RME / odontogram print stays denied; prescription print stays; an owner revision of rule 93 §12; FO / Admin Klinik keep a legitimate print path |
| M3.3 After finalization | **Lock on finalize + amendment** | finalizing locks the RME sheet or odontogram at once; corrections only through a supervised amendment with reason, approval, version history and audit trail; payment changes stay with FO/Kasir maker-checker (reverses Sprint 59 editing of finalized records) |
| M4.4 Clinical photos | **Build + split consent clause 8** | before / after / progress photos as clinical documentation; clinical documentation consent separated from publication / promotion; refusing publication never blocks care; private storage, authorization, BranchContext, audit trail, limited access; no automatic FO/Kasir access; the new consent version tested and historical consents never changed |
| M5.1/M5.2 Payments | **Wait for the discussion** | current payment workflow kept; no supplementary invoice and no change to immutable invoices without a management decision; the approved transfer-reference validation done; additional lab fees, post-payment correction and roll-out expansion recorded as REQUIRES OWNER DECISION |
| M6.2 Storage | **Usage report + owner measurement** | a read-only capacity report (database, private files, RME, PDF, clinical photos, free space), no patient information; growth projection and cost estimate from verified rates; no BK-2; the running production backup unchanged |
| M6.1, C4 Organizational | **Record, do not block technical GO** | migration responsibility (IT and clinical side) and management UAT before all-branch roll-out are organizational gates; technical GO when every mandatory technical gate passes; all-branch roll-out stays HOLD until the organizational approvals exist; BUSINESS ROLLOUT APPROVED is never claimed without evidence |

## 3. Historical items in the brief, verified

| Brief statement | Verified state | Disposition |
|---|---|---|
| DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1: PR-B verification incomplete, ceremony pending, PR-C not started | Tag `doctor-access-single-session-branch-lock-1-go` → `d7d645cb` (2026-09-13); PR-B runtime `1010d9fb` is an ancestor of the base; PR-C (#406) merged and deployed (`docs/sprints/doctor-access-pr-c-production-evidence.md`); Half A activation tag `doctor-access-global-activation-half-a-1-go` → `4fec066c` (2026-09-18) | **ALREADY RESOLVED** (the brief's status is stale) |
| Consent signature 1 MB bound, pre-8C policy docblocks | closed by 8D (`dde7b9b6`, `82f1eeba`, tag `dms-rme-revision-program-8d-go`) | **ALREADY RESOLVED** |
| Legacy RME program | `LEGACY-RME-PROGRAM-CLOSURE-1` CLOSED / STEADY-STATE, inherited by the manifest | not reopened; no defect found that requires it |
| Global doctor enforcement, Android pilot | `global_permitted` literal false, cohort ceiling enforced, flags default OFF (audit pass 2) | **UNCHANGED** — not in scope without an owner APPLY decision |
| BK-2 | manual-only directive (rule 232) | not run, not a gate |

## 4. Canonical pending register

Severity: H high, M medium, L low, I info. Disposition codes: RESOLVED (this program), ALREADY RESOLVED, OWNER
DECISION (open), HUMAN OPERATION, OWNER DEFINITION (id defined only in the owner's private matrix), DEFERRED (a
documented, owner-visible follow-up with its trigger), BY DESIGN.

### 4.1 Resolved by this program

| Id | Module | Finding (root cause) | Sev | Fix | PR |
|---|---|---|---|---|---|
| P8R-S6 | RmeInvoice | invoice/payment month from UTC `now()` (CG-12); also RMECOR, RMEOVP, RMEDSP | M | `ClinicalClock::today()`; nothing renumbered | PR1 |
| CC1-NOFLASH (8D finding 2) | Consent, Prescription, MedicalRecord | a refused consent, prescription or RM page flashed signature/canvas data URIs into the session store (framework default flashes all non-password input) | M | five fields in `dontFlash` | PR1 |
| CC1-NOSTORE | RME prints | rendered clinical and patient-data documents (visit print/PDF, odontogram, prescription, consent, legacy archive, oversight, cashier receipt, patient/payment/receivable/patient-audit reports) had the framework default `no-cache, private` | M | `cache.headers:private;no_store;max_age=0` on 21 routes | PR1 |
| OPS-Q | Queue | `retry_after` 90 s and `TimeoutStopSec` 150 s below the 240 s legacy PDF render | M | `retry_after` 300 s, `TimeoutStopSec` 270 s (repo; host unit install is a root step) | PR1 |
| OD-7-6 | LegacyImport mass upload | odontogram batches scoped by the RME archive scope (list and per-batch) | M | scope chosen by `import_type` in one policy method | PR1 |
| OD-5-4 | User / online context | deactivation dropped a doctor's presence only on that doctor's next request | L | `markOffline()` in deactivate / inactive update / delete | PR1 |
| OD-7-1 | RmeInvoice (rule text) | AIR-RMB-04 said every PARTIAL Kontrol stays `cashier_pending`; the no-carry-over path completes it | L | owner: code wins; text narrowed; dangling "rule 207 §C7" reference removed | PR1 |
| Unsigned print | Prescription (rule text) | rule 228 §15 left the question open | I | owner: keep print allowed; recorded | PR1 |
| CC1-SEQTEST (8D audit finding 1) | tests | `createFromFormat('Ym')` keeps today's day and overflows on the 29th–31st (CG-20) | L | `!Ym`; boundary tests pinned | PR1 |
| OD-5-6 | MedicalRecord | a new RM sheet was accepted on any visit of the patient named in the URL once some consented encounter existed | M | owner: current encounter only; service refuses, navigation never offers it | PR2 |
| OD-5-10(a) | ClinicVisit | a cancelled visit was accepted (and offered) as a Kontrol prior visit | L | guard refuses (read FOR SHARE), picker excludes | PR2 |
| CC1-HWBOUND | MedicalRecord handwriting | the RM page canvas had no payload, declared-size or inflate bound (8C/8D bounded only prescription and consent canvases) | M | `HandwritingPayloadGuard`: request `max`, declared-size caps, one IHDR, bounded incremental inflate; all above the real 900x1273 canvas worst case | PR1 |
| CC1-RXSIGNED | Prescription | another clinician could change a prescription after the named doctor signed; the signature stayed on content the doctor did not see | M | owner: lock after signing; policy (after the scope check) + service (visit lock, fresh row) + rejection audit + UI | PR2 |
| CC1-RXSIGNRACE | Prescription | the reverse race: content changed after the doctor's page loaded, then the doctor signs without re-sending the canvas (independent review F6) | L | content version carried by the form; a signature over newer content is refused | PR2 |
| CC1-KONTROLBTN | ClinicVisit | "Buat Kontrol" offered from a cancelled visit, silently registering a NEW visit (independent review F7) | L | controller flag hides it | PR2 |
| CC1-DOC1 | rules | `rme-clinical.md` posture said a mistaken consent "is superseded by re-signing" (Sprint 0 OD-5-8: re-sign is unreachable) | I | corrected | PR1 |

### 4.2 Not resolved here — disposition and trigger

| Id | Finding | Sev | Disposition | Next step / trigger |
|---|---|---|---|---|
| OD-5-9 (+ P8R-S4b) | consent signatures on the generic `local` private disk, not `ClinicalEvidenceStorage` (rule 121) | M | OWNER DECISION 2026-11-16: separate storage sprint | new write path + key-preserving copy command (dry run, then apply on the host with owner approval); fold in CC1-ORPHAN |
| CC1-ORPHAN (8D finding 1) | a late failure inside `RmeVisitConsentService::sign()` (second object write, insert or commit) leaves the signature objects on the private disk | L | DEFERRED into the OD-5-9 storage sprint (same write path; no data lost, no exposure) | that sprint applies the rule 224 compensate-on-failure pattern |
| CC1-PAT-1 | the patient form's explicit Nomor RM override is stored as typed (bypasses AIR-PAT-11 composition; can disagree with the patient's branch) | M | OWNER DECISION 2026-11-16: measure first | owner runs the read-only count in §10.2; then a patient-identity sprint |
| CC1-PAT-2 | the patient form takes a request `branch_id` with no working-branch pinning for Front Office / Perawat / Admin Klinik | M | same decision | same sprint |
| CC1-PAT-3 | `BranchSeeder` creates MAIN without `is_rme_enabled=false` (column default true) on a fresh seed; many tests rely on it | L | DEFERRED to the same sprint | production value to be measured with §10.2 |
| CC1-PAT-4 | small patient validations: `registered_at` unbounded year, NIK not `digits:16` at registration, no `$hidden` for `ktp_number`, unique violation answers 500 | L | DEFERRED to the same sprint (needs the production measurement first so legacy rows are not locked out) | same sprint |
| CC1-SAT-1 | SATUSEHAT: branch pilot approval is not checked in prepare / queue / process; excluding a queued record does not stop it | L (latent: `satusehat.enabled` defaults false) | DEFERRED with a hard trigger, like OPS-SAT | must be fixed before `satusehat.enabled=true` anywhere |
| OPS-SAT | no SATUSEHAT retry sweep scheduled; worker does not read the `satusehat` queue | L (latent) | OWNER DEFINITION (id) + same trigger | before enabling SATUSEHAT |
| OPS-KTP | KTP temporary-scan prune timer not installed in production | M | HUMAN OPERATION (root): commands in §10.1 | owner runs them; verify with `systemctl list-timers` |
| CC1-ODO | print / cashier summary show an empty odontogram row as a blank chart instead of using `hasRecordedTeeth()` | L | DEFERRED (UX) | next RME UX sprint |
| CC1-PERF | per-row `@can('update', $visit)` on the visit and queue lists repeats the doctor lookup for doctor accounts (3–4 queries a row) | L | DEFERRED (performance; lists are already doctor-filtered) | measure on production read path before changing |
| CC1-CG08 | `AndroidReleaseGovernanceScanner` reads a flag's `default` straight from `config('feature_flags…')` (metadata, not the runtime value) | L | DEFERRED (governance tooling) | next doctor-access governance change |
| OD-5-5, OD-7-5, OD-7-7, R7-1, R8A-2 (+ 8D finding 3), RA-1, OD-6-1, OD-5-10(b) | Sprint 0 ACCEPT-AS-RESIDUAL proposals | L/I | OWNER DECISION (open; proposals unchanged, Sprint 0 §3) | owner disposition |
| OD-5-8 | consent correction flow (void/re-sign route; double submit creates two consents) | L | OWNER DECISION (design) | owner design, then one unit |
| OD-7-3 | doctors may print/export a published legacy archive | I | OWNER DECISION (allowed by rule 93 today); the over-broad `DoctorRoomScopeService` docblock was corrected | owner, only if "deny" is wanted |
| R7-F, P8-FIX1, P8-SEED, R8A-3, RA-4 | ids defined only in the owner's private matrix | ? | OWNER DEFINITION | one neutral line each |
| D3-NEW-3 / D3-NEW-5 | deferred with conditions (owner) | ? | OWNER DECISION | triggers as recorded in Sprint 0 §6 |
| RA-3, RA-5 | untracked runtime directory on the host; restore-drill evidence path | I | HUMAN OPERATION / owner register | — |
| D4 | dedicated real-device ceremony | — | BLOCKED_EXTERNAL / HUMAN OPERATION | owner provides a test device and a test doctor |
| D5 | legacy WAVE-4 branch confirmations (SPN4, TLK1, LDK2, ATG3) | — | HUMAN OPERATION | four branch confirmations |
| CC1-RXPRINT-WRITE | printing a prescription sets `printed_at` on a GET | — | BY DESIGN (rule 226 §1.7a) | — |
| CC1-LAB-FLASH | lab delivery POD signatures are flashed the same way | — | OUT OF SCOPE (lab module) — recorded for the lab owner | — |
| CC1-LAB-UTC | lab `InvoiceNumberGeneratorService` / `PaymentNumberGeneratorService` still use the UTC month | — | OUT OF SCOPE (lab module) — same class as P8R-S6, recorded for the lab owner | — |

### 4.3 Closure wave (after the NO-GO, §2b) — supersedes the matching §4.2 rows

"Implemented" means code and tests are on a PR; an item counts as RESOLVED only after merge, post-merge CI, release
and production verification (§9.4). Nothing here is DEFERRED-as-resolved.

| Id | Owner basis | Change | PR | State |
|---|---|---|---|---|
| OD-5-9 (+ P8R-S4b) | NO-GO order, item 1 | consent signatures written through `ClinicalEvidenceStorage` (rule 121); read through `ClinicalEvidenceStorage::dataUri`, no fallback | #648 | implemented |
| OD-5-9 copy phase | same | `rme:consent-signatures-migrate` (dry run default; `--apply` copies key for key, SHA-256 verified, never overwrites a CONFLICT, never deletes; counts only; one audit row) | #649 | implemented; production `--apply` needs the owner's approval (§10.5) |
| CC1-ORPHAN | folded into OD-5-9 | a failure after the object writes removes the uncommitted objects (rule 224 compensation) | #648 | implemented |
| OD-5-8 | duplicate guard + supervised void | one active consent per visit under the visit lock; void needs `void_rme_consents` (Supervisor RME), a 10–1000 character reason, voider ≠ signer inside the transaction, an `in_progress` visit; audit `RME_CONSENT_VOIDED`; the row is kept; re-sign only after the void | #648 | implemented |
| R8A-2 (+ 8D finding 3) | fix | consent and prescription FormRequests authorize through `Gate::inspect` before validation; the HTTP signed-lock audit moved into the request | #648, #650 | implemented |
| OD-6-1 | page revision | each handwriting page carries an opaque revision; a stale save is refused under the record lock before any object is written, with a reload message | #650 | implemented |
| OD-5-5 | refuse while in_progress | a new visit is refused while the patient has an examination in progress, decided under the lock, naming the active visit | #650 | implemented |
| OD-7-5 | backfill | `rme:lab-candidates-backfill` (dry run default with counts per paid month; `--apply` needs an explicit `--paid-from` clinic date, optional `--paid-to`; per invoice under its row lock, idempotent, one audit row per invoice filled, counts only) | #657 (split from #650 to keep #650 within the scope gate) | implemented; production `--apply` needs the owner's approval (§10.6) |
| CC1-PERF | NO-GO order, item 7 | `DoctorIdentityResolver` memoised per routed request and cleared by `DoctorAccountLinkService` | #651 | implemented |
| CC1-CG08 | NO-GO order, item 8 | `AndroidReleaseGovernanceScanner` reads flags through `FeatureFlagService` (CG-08) | #651 | implemented |
| OD-7-7 | fix without silently changing rule 153 | lease in-flight grace (`doctor_access.lease.in_flight_grace_seconds`, 30 s); rule 153 and AIR-DAC-08 amended openly | #652 | implemented |
| CC1-SAT-1b, OPS-SAT | NO-GO order, item 3 | excluding a candidate cancels its not-yet-sent items; the claim re-checks exclusion; `satusehat:retry-sweep` (a no-op while disabled) scheduled only while the gateway is enabled; negative tests prove nothing is sent and nothing is queued while disabled | PR6 | implemented; waits for the CI token (the workflow change needs the `workflow` scope the owner is granting) |
| CC1-SAT-1a | keep canonical design | branch pilot approval stays as designed (Rule 88 §2, AIR-SSH-04) | — | BY DESIGN (owner) |
| R7-1 | check evidence, add or verify tests | both sub-cases reproduced (RED 6 of 7 on the base); one handwriting book per patient after the book's visit is cancelled or a merge puts an earlier visit in front of it (also a never-stamped pre-64.0 book); the canonical visit is decided on its locked row | #653 | implemented |
| R7-1-FS | found while fixing R7-1; not reproduced | the "Buat Rekam Medis" first-sheet path picks the canonical visit without the new lock: two concurrent first-sheet clicks with a cancel of the earliest visit between them could leave two sheets (both visible, nothing lost) | — | OPEN — candidate, owner decision (fix now or record) |
| RA-1 | accepted if the replacement CI on the same SHA is green | run `42309524995` attempt 2 on `c0e12382`: critical gate **9,615 passed, 47,546 assertions, 0 failed** (PostgreSQL); Full Suite skipped (deferred) | — | **ACCEPTED** (condition met) |
| OD-5-10(b) | accepted by design if canonical | the oversight widgets scope through `scopedBranchIdsFor` (the canonical branch authority) | — | **ACCEPTED BY DESIGN** (condition met) |
| OD-7-3 | keep allowed | unchanged; no widening of legacy odontogram or cross-branch access | — | BY DESIGN (owner) |
| CC1-ODO | keep empty state | unchanged; covered by `StructuredOdontogramPrintTemplateTest` ("empty odontogram renders the safe empty state"), `OdontogramSavedResultTablePreviewTest` ("safe empty state"), `MedicalRecordPrintOdontogramSeparationTest` | — | **ACCEPTED BY DESIGN** (owner) |
| CC1-PAT-1..4 | measure first | measured read-only by the owner on 2026-11-17 (§10.2: 1,522 patients, all canonical, 0 duplicates, unique index present); the automatic number is memo RM PR-2 (rule 244) | — | MEASURED; implementation in the memo wave |
| CC1-ADJ-1 | found 2026-11-17 by the doctor-scope A1b re-review (pre-existing, live in production with every flag OFF) | the previous/next visit arrows on the odontogram and prescription pages (`ClinicVisitRepository::adjacentVisitsForPatient`) were scoped only to all RME-enabled branches: Front Office / Admin Klinik / Kasir at branch A saw another branch's visit number and date (CG-05, rule 228 §5). Fix PR from the base, filtered per viewer through the clinical-document read authority; ships in the next release | fix PR in progress | OPEN — FIX IN PROGRESS |
| CC1-CI-1 | found 2026-11-17 (CG-17) | a push run diffs only against `github.event.before`: when a Markdown-only commit lands on a base whose code push run was cancelled (`cancel-in-progress`), the green push run on the new tip ran no critical regression test; the release guard accepts it. Remedy used: a `workflow_dispatch` run on the exact SHA (classifier fails closed). A workflow change needs the `workflow` scope and is not made here | — | OPEN — RECORDED; owner decision on a workflow fix |
| D4 | not available yet | readiness re-audit and ceremony procedure (§10.7) | — | **BLOCKED_EXTERNAL** |
| D5 | valid human confirmation | confirmation template (§10.8) | — | OPEN — HUMAN OPERATION |
| OPS-KTP | HUMAN OPERATION | §10.1 | — | OPEN — HUMAN OPERATION |
| R7-F, P8-FIX1, P8-SEED, R8A-3, RA-4, D3-NEW-3, D3-NEW-5, RA-3, RA-5 | owner register | unchanged | — | OPEN — OWNER DEFINITION / DECISION |

## 5. Implementation

### 5.1 PR1 — `fix/dms-rme-comprehensive-closure-1` (10 `app/*.php` files, 5 modules)

- `RmeInvoiceNumberGeneratorService`, `RmePaymentNumberGeneratorService`: inject `ClinicalClock`; one clinic `$month`
  drives the lock key, the MAX read and the prefix.
- `RmeInvoiceCorrectionService` (RMECOR, RMEOVP), `RmeBillingDisputeService` (RMEDSP): `ClinicalClock::today()`.
- `bootstrap/app.php`: `dontFlash` for `consenter_signature`, `doctor_signature`, `prescription_canvas_data`,
  `doctor_signature_canvas_data`, `handwriting_data`.
- `routes/web.php`: the 21 clinical and patient-data document routes carry `cache.headers:private;no_store;max_age=0`.
- `HandwritingPayloadGuard` (new service) called by `MedicalRecordHandwritingController`: payload, declared-size,
  single-IHDR and bounded-inflate checks; the blank check's own inflate is bounded too. Moved from PR2 to PR1 after
  the independent review (thin controller, CG-04; the suite's CI token lives in PR1).
- `config/queue.php` (`retry_after` 300), `deploy/systemd/daengtisiams-queue-worker.service` (`TimeoutStopSec` 270),
  queue runbook paragraph.
- `LegacyMassUploadBatchPolicy::workspaceScopeFor()`; `LegacyMassUploadController::index` uses it.
- `UserService`: `markOffline()` on deactivate, inactive update and delete.
- `DoctorRoomScopeService`: docblock names rule 138 §6's three routes (comment only).
- Rules: new rule 237; rule 227 §4.21 (P8R-S6 closed); rule 228 §15 (unsigned print decision); AIR-RMB-04 narrowed;
  `rme-clinical.md` index line and posture correction (both domain files stay under the 30,000-character budget).
- CI: the three new suites are named so EXISTING critical-gate tokens select them in both variants
  (`RmeClinicalWriteAuthorityNoFlashTest`, `RmeClinicalWriteAuthorityHandwritingBoundTest` →
  `RmeClinicalWriteAuthority`; `RmeClinicalDocumentReadScopeNoStoreTest` → `RmeClinicalDocumentReadScope`) and are
  declared in `critical_gate_mandatory_suites` (CG-19). A new token was first added to the workflow, but this
  workstation's GitHub credential lacks the `workflow` scope, so the workflow file is unchanged.

### 5.2 PR2 — `fix/dms-rme-comprehensive-closure-1-pr2` (10 `app/*.php` files; rebased on PR1)

- `ClinicVisitRegistrationGuard` (cancelled prior visit refused), `ClinicVisitService::patientVisitOptions` (not offered).
- `MedicalRecordService::createDraftOn` (OD-5-6 refusal, `MESSAGE_SHEET_ONLY_ON_ENCOUNTER`), `MedicalRecordController`
  (offer "Tambah Lembar RM" only for the current encounter), `ClinicVisitController` (comment).
- `PrescriptionAuthoringAuthority::signedContentLocked()` (the doctor the edit path derives: the prescription's own,
  else the visit's), `RmePrescriptionPolicy::update` (scope first, then the lock), `RmePrescriptionService` (decided on
  the fresh row under the visit lock; `RME_PRESCRIPTION_SIGNED_EDIT_REJECTED` written after rollback;
  `contentVersion()` refuses a signature over content changed since the page loaded), `UpdateRmePrescriptionRequest`
  (`content_version`), `RmePrescriptionController` (no edit offer; HTTP refusal audited only when the policy's reason is
  the lock), view (notice, hidden version).
- `ClinicVisitController`: no "Buat Kontrol" from a cancelled visit.
- Factory state `RmePrescriptionFactory::unsigned()`. Tests that exercise what ANOTHER clinician may author now start
  from an unsigned prescription (their intent unchanged; OD-5-1 kept); I5-04 split into the unsigned and signed cases.

## 6. Tests (measured, local)

RED first: every new behavioural test was run against the untouched code and failed for its own reason, then passed
after the change. Keep-working controls pass both ways.

| Area | RED on the base code | GREEN (PostgreSQL 16) |
|---|---|---|
| Sequence lock + clinic month (`RmeDocumentNumberSequenceLockTest`) | 6 failed (the 00:00–07:59 WITA cases, the old-label case, the PostgreSQL lock-key cases) | 30 tests, 95 assertions, 0 failed |
| RMECOR / RMEOVP / RMEDSP | 3 failed | 127 tests (three lifecycle suites), 460 assertions, 0 failed |
| No flash (NF-01, NF-02, consent D-01) | 3 failed | 3 tests, 31 assertions |
| No-store prints (NS-01..04) | 2 failed (NS-01, NS-03) | 4 tests, 50 assertions |
| OPS-Q order | 1 failed | queue + ENT-5 + runtime-hardening suites: 54 tests, 377 assertions |
| OD-5-4 | 3 failed | `ActiveAccountSessionTest` 42 tests, 153 assertions |
| OD-7-6 | 4 failed | `LegacyMassUpload*` 120 tests, 828 assertions |
| OD-5-10(a) | 2 failed | registration suites 84 tests, 279 assertions |
| OD-5-6 | 3 failed | 5 tests; the ten medical-record / workspace / consent suites 257 tests, 677 assertions |
| Handwriting bounds (HW-01..08) | 6 failed (PR2 controller version, and again after the move to `HandwritingPayloadGuard` in PR1) | 8 tests; the 15 suites that post handwriting + the no-store suite (21 routes) 549 tests, 2,248 assertions (PR1) |
| Prescription lock (I7-01..07, I5-04b) | 6 failed | prescription + write-authority + read-scope suites 172 tests, 648 assertions |
| Review fixes (I7-08 version, I7-11 scope order, I7-09/10, Buat Kontrol, `DoctorPatientScopeTest`) | I7-08, I7-11 failed with each fix removed | 49 tests, 152 assertions (PR2) |
| Governance (SQLite): `tests/Feature/Cicd`, `DevflowAiInstructionSystemTest`, `ProductionShellCommandGuardTest`, queue, ENT-5 | — | 467 tests, 5,164 assertions; 1 skipped (Poppler, pre-existing); the one failure (domain rule size budget) fixed and re-run; after the review round: 463 tests (Cicd, DevflowAi, shell guard, queue, storage) 0 failed, 1 skipped |
| PR1 broad regression, PostgreSQL 16: every critical-gate token touching RME, legacy, doctor, patient, queue, oversight, odontogram, consent, prescription, cashier, payment, receivable | — | 6,994 tests, 30,461 assertions, 128 failed + 18 errors, 24 skipped (45 min). **The same 146 tests fail on the untouched base `a7b0bc99`** (16 suites re-run in a detached baseline worktree: 193 tests, 146 failed; set difference 0 in both directions): multi-connection concurrency suites whose competing child process cannot see the parent's data under this workstation's schema-isolated database, and Poppler-dependent legacy batch suites. Pre-existing and environmental (CG-21); CI runs them with its own database and Poppler |
| PR1 report / receipt / cashier suites after the no-store extension | — | 504 tests, 1,960 assertions; 11 failed, all in the same concurrency suites and all failing identically on the base |
| PR2 stacked on PR1, PostgreSQL 16: prescription, doctor-patient scope, write authority, read scope, registration, medical record, workspace, consent suites | — | 733 tests, 2,311 assertions, 0 failed |

## 7. DEVFLOW gates (PR1, local)

`devflow:base-ref-check --strict` GO; `sprint:manifest-check` GO; `sprint:scope-audit --strict` GO (5 modules, 10
`app/*.php` files after the handwriting move); `foundation:devflow-check --strict` GO; Pint clean; `git diff --check`
clean. `sprint:test-plan`
escalates to the full required suite (unmatched rule/doc paths and the access-control / config categories): under
CG-18 the targeted suites in §6 run instead and the Full Suite is reported as deferred.

## 8. Independent review

A separate read-only `general-purpose` session reviewed both uncommitted change sets against `a7b0bc99`, given the
rules and the code, not the author's conclusions. It ran no tests. Result: 1 HIGH, 3 MEDIUM, 5 LOW, 2 INFO; it
confirmed every intended behaviour A1–A6 and B1–B4 in code.

| # | Sev | Finding | Resolution |
|---|---|---|---|
| F1 | HIGH | `DoctorPatientScopeTest` (critical-gate token `DoctorPatientScope`) edited a SIGNED prescription as another doctor; the lock made it 403 | fixture unsigned (the test is about scope) + an explicit assertion that the signed case is refused; suite green |
| F2 | MEDIUM | the lock was checked before the scope check: an out-of-scope doctor got the signed-lock message and an audit row | policy evaluates `view()` first; I7-11 |
| F3 | MEDIUM | rules did not describe PR2; comments cited a wrong rule-237 section | rule 237 §9–§11 and rule 228 §12.2 amended in PR2; references point at §9 |
| F4 | MEDIUM | the handwriting bound suite was not declared mandatory and relied on PR1's token | moved into PR1, declared, selected by an existing token |
| F5 | LOW | the lock used the raw `doctor_id`; the edit path falls back to the visit doctor | same derivation; I7-10 |
| F6 | LOW | reverse race: a signature over content changed after the doctor's page loaded | `contentVersion`; I7-08, I7-09 |
| F7 | LOW | "Buat Kontrol" still offered from a cancelled visit | hidden (controller flag); test added |
| F8 | LOW | PNG parsing in the controller (CG-04); misplaced docblock | `HandwritingPayloadGuard` service |
| F9 | LOW | OD-7-6, OD-5-4, OD-5-10(a) rely on the brief, not a recorded answer | §2 states the basis; owner confirmation requested with the merge approval (§9) |
| F10 | INFO | PR2 depends on PR1 (manifest, dontFlash, token) | PR2 rebased on the merged PR1 |
| F11 | INFO | receipt PDF and patient/payment reports lacked no-store; lab numbers use UTC; I7-05 tests the refresh | no-store extended to 7 more routes; lab item recorded out of scope (§4.2); I7-05 kept as the refresh test |

### 8b. Closure wave — independent review rounds (2026-11-17)

Each PR was reviewed by a separate read-only session given the code and the rules, not the author's conclusions.
Every finding was fixed RED-first (or recorded with a reason) in a follow-up commit, and every follow-up commit was
re-reviewed by another separate session. Force-push was refused by the session's safety policy, so each fix landed as
a fast-forward commit carrying exactly the tested tree.

| PR | Round 1 (HIGH / MEDIUM / LOW+INFO) | Notable | Re-review | Final head |
|---|---|---|---|---|
| #648 consent integrity | 0 / 0 / 9 | scope re-check inside `sign()`/`void()`; Super Admin void form; two CI nondeterminism failures (an unordered event read; a random queue number colliding with queue 1, reproduced on `cab3a4c1`) | all resolved | `803db2e4` |
| #649 signature copy | 0 / 3 / 1 | failed write leaked an object key; unreadable object empty-copied; root refusal; a PHP fatal found by the integrated critical run | resolved; fail-closed root check added | `71c1375c` |
| #650 page revision, OD-5-5 | 0 / 1 / 4 | two examinations could still be open at once via "Mulai Pemeriksaan" — per-patient advisory lock 92_005 in both paths, no lock cycle; backfill split to #657 | resolved; namespace inventory pinned | `0d701e35` |
| #651 identity memo, scanner | 0 / 0 / 2 | flag with no declared default passed | resolved | `9697d00a` |
| #652 lease in-flight grace | 0 / 1 / 0 | same-browser double submit locked the doctor out — hashed CSRF claim nonce + double-submit guard | resolved; two timings remain a recorded residual (rule 153, rule 237 §17) | `daf88277` |
| #653 one RM book | 0 / 3 / 1 | later sheet taken as the book; stamping un-anchored a legacy book; vacuous concurrency test; first-sheet race (R7-1-FS, owner-approved) | resolved; service re-asserts `create` on the locked visit | `f44453a5` |
| #654 RM-number foundation | 0 / 1 / 3 | an oversized existing number would block a branch-year; dry run printed a reconstructable RM number | resolved | `0aa2b771` |
| #655 memo quick wins | 0 / 0 / 5 | rule 238 index not loaded for payment code; soft-deleted transfer method passed | resolved | `af653175` |
| #656 storage report | 0 / 0 / 4 | a stat failure aborted the run and printed a host path; top-level symlinks followed | resolved | `e11074fe` |
| #657 lab backfill | (round 1 under #650) | `--apply` needs an explicit payment window | resolved | `85527d84` |

A sequential merge simulation of all ten heads on the base is textually clean; combined rule-file sizes stay under the
30,000-character budget (`rme-clinical.md` 29,973 — the next memo PR must condense it first).

Local integrated run on the simulated merge tree `6882f001` (workstation, not a CI gate; reported as measured):

| Run | Result |
|---|---|
| Governance suites (SQLite) | 0 failed |
| Full critical filter (PostgreSQL 16, isolated schema) | 10,638 tests; 147 failed, all in the known local-environment classes: multi-process concurrency suites (payment submission, billing dispute ×2, performed treatment, refund settlement, refund request, zero-value closure, invoice correction ×3, owner reference registry, tariff provisioning — "competing transaction did not take its locks"), Poppler legacy batch suites (odontogram publish/review ×4), and one Observability diagnostics page |
| Observability diagnostics page | the same failure reproduced on the untouched base `cab3a4c1` (`SQLSTATE[25P02]`) — local environment, not introduced by the wave |

CI is the authority for these classes. Each PR's NSF-R011 Critical Test Gate ran on PostgreSQL on its final head with
0 failures (§9.4).

## 9. Release and production

### 9.1 PR1 — #646

| Item | Evidence |
|---|---|
| Tested head | `37828b4f67bb39256eb4a38977f551ec46f2bc83` |
| PR CI | run `43850156576` (pull_request): all required checks success; NSF-R011 Critical Test Gate on PostgreSQL **10,455 passed, 51,892 assertions, 0 failed** (the three new suites and every touched suite in the log); Full Suite Gate SKIPPED (CG-18, not passed) |
| Merge | squash, `--match-head-commit 37828b4f`, owner approval §2a → `daf2ac15b0a0809b604126a69b7ea1de4797509d`; merge tree `e6553990` = tested head tree |

Release flow: PR → exact-head CI → owner merge approval → post-merge push CI on the exact merge SHA → owner's
written approval of the full release SHA → owner signs locally (`scripts/release-approval-sign.sh`) →
`submit-approval` → `check-approval` GO → `start` on the Biznet host through the deploy operator → `follow` →
`verify <deploy-id>` (`DEPLOY RUNNER OK`) → read-only production verification → GO tag. Not inside 02:25–02:40 WITA.

### 9.2 PR2 — #647

| Item | Evidence |
|---|---|
| Tested head | `bf48e9d5` |
| PR CI | run `43861427094`: required checks success; NSF-R011 Critical Test Gate on PostgreSQL **10,478 passed, 51,975 assertions, 0 failed**; Full Suite Gate SKIPPED (CG-18, not passed) |
| Merge | squash, `--match-head-commit bf48e9d5`, owner approval §2a → `cab3a4c1082923d92b665238eb72034b4af13e34`; merge tree = tested head tree |
| Post-merge push CI | run `43871132383` on `cab3a4c1`: every required job success; critical gate **10,478 passed, 51,975 assertions, 0 failed**; Full Suite Gate SKIPPED (deferred, not passed) |

### 9.3 Release 1 — `dms-rme-comprehensive-closure-1` at `cab3a4c1` (P8R-S6 live before the 2026-12-01 boundary)

| Item | Evidence |
|---|---|
| Pre-release posture (deploy operator, read-only) | `SUDO_POLICY=LEAST_PRIVILEGE`, `PRIVSEP_DRIFT=NONE`, operator entrypoint CURRENT |
| Owner approval | written approval of the full SHA (§2a), then signed in the owner's own terminal (Contract 3, rule 236): approval id `2d1425769ef560eef5a23d64b2a052bb`, bundle SHA-256 `2aa6b428d28f24b7efa7baf18e80050b30c38ad71c4fb5f9c9ec0824f2e50bf5`, record SHA-256 `091033d67c463f1967ed1d968ee0cc195225f5c909b0ba30688f89eb449008ef`, valid until 2026-11-18T14:17:59Z. The key and passphrase never left the owner's terminal |
| Approval check | `submit-approval`, then `check-approval` = GO |
| Deploy | `start` on the Biznet host through the deploy operator (runner on the VPS, never local): deploy id **`20261116-141906`**, `final exit=0`, `DEPLOY_APPROVED_TARGET_MATCH=YES`, `DEPLOY OK`; `verify 20261116-141906` → **`DEPLOY RUNNER OK`** |
| Migrations | none in this release (release 2 will carry additive migrations: the lease claim-nonce column of #652 and the RM-number sequence table of #654) |
| Production verification (read-only) | HEAD = `cab3a4c1`; health / smoke 200 / 403 / 302 as expected; `LARAVEL_LOG_ERROR_LINES=166` = the baseline recorded at the last three GO closures (no new application errors); `PRIVSEP_DRIFT=NONE`; queue worker and timers unchanged |
| Pre-deploy database dump | taken by the runner inside the deploy; the line itself sits in the root-only deploy log and is read by the owner (§10.3) |
| Release id | spent; the next release uses a new id |
| GO | **NO-GO (owner, §2b)** — no tag created |

### 9.4 Closure wave — CI on the final heads (before merge)

NSF-R011 Critical Test Gate, read from the job log (PostgreSQL, `DB_CONNECTION: pgsql`; passes only, 0 failed):

| PR | Final head | Workflow run | Critical gate |
|---|---|---|---|
| #648 | `803db2e4` | 43914546878 | 10,497 passed |
| #649 | `71c1375c` | 43926359393 | 10,490 passed |
| #650 | `0d701e35` | 43926373031 | 10,501 passed |
| #651 | `9697d00a` | 43926386917 | 10,491 passed |
| #652 | `daf88277` | 43926397404 | 10,485 passed |
| #653 | `f44453a5` | 43926409786 | 10,494 passed |
| #654 | `0aa2b771` | 43920506608 | 10,508 passed |
| #655 | `af653175` | 43924260896 | 10,497 passed |
| #656 | `e11074fe` | 43924269075 | 10,491 passed |
| #657 | `85527d84` | 43920501415 | 10,486 passed |

### 9.5 Release 2 — candidate (closure wave + rules headroom)

| Item | Content |
|---|---|
| Candidate SHA (superseded) | `c705f048377f558e872bba81b0f4f6e49d81efed` = the ten closure-wave squashes (§2a) + #658 (rules only). Approved by the owner with conditions, then **superseded**: it had no read fallback for pre-release consent signatures, which conflicted with the owner's later directive (§2a, release 2 decisions); that approval is not reused |
| Candidate SHA (current) | `0f3c3bb32a3a6b0e8a90f8ab9e43ca9fd7cc3ab3` = `c705f048` + #661 (temporary read-only fallback, rule 237 §14.5a; security review + re-review CLEAN; PR CI critical gate 10,664 passed / 0 failed on PostgreSQL); merge tree = tested head tree. Needs a NEW written owner approval and a NEW Contract 3 signature |
| Migrations (additive) | `2026_11_17_100001_add_claim_nonce_hash_to_trx_doctor_session_leases_table` (#652), `2026_11_17_100001_create_mst_patient_rm_sequences_table` (#654, dormant) |
| Permissions | `void_rme_consents` → Supervisor RME (#648); after deploy §10.4 |
| Post-deploy, same window | §10.5 consent signature copy (dry run, then `--apply` with owner approval) — rule 121 has no read fallback |
| Optional, owner approval | §10.6 lab backfill; `patient:rm-sequence-seed` is NOT needed until the automatic-number PR (memo RM PR-2) ships |
| Excluded on purpose | memo M3.3 lock PRs (the lock ships only with its amendment path), PTM PR1–3 (one release), PR6 (needs the `workflow` scope) |
| Post-merge CI (superseded candidate) | push run 43967765523 on `c705f048`: success, but the classifier diffed against the previous commit only (#658, Markdown) → `gate_profile=docs_only`, critical regression tests **SKIPPED** (CG-17: skipped is not passed). The push run on `82cb6e13` that would have run them was cancelled by the #658 push. Remedy: `workflow_dispatch` run 43968947617 on `c705f048` (no base → the classifier fails closed to the full critical gate): **NSF-R011 Critical Test Gate 10,638 passed, 52,938 assertions, 0 failed, PostgreSQL**; release safety and release evidence gates success; Full Suite SKIPPED (CG-18, deferred, not passed) |

### 9.6 Release 2 — `dms-rme-comprehensive-closure-1-r2` at `0f3c3bb3` (intermediate release; the program stays OPEN)

| Item | Evidence |
|---|---|
| Owner approval | written approval of the full SHA `0f3c3bb32a3a6b0e8a90f8ab9e43ca9fd7cc3ab3` (§2a release 2 decisions; the `c705f048` approval was not reused), then signed in the owner's own WSL terminal (Contract 3, rule 236): approval id `2dddde3cd9fe5bba398688d418b95164`, bundle SHA-256 `56429db42764d00102cf9935c4315b4c1853d0fd4b4c455efc955ac009a0cc93` (re-computed locally before submission), record SHA-256 `00afa456655857c29a050ad447ee1a752c6e8fbccafe721105fa4b33dcd32d3c`, signature SHA-256 `f72a1fe20600adc2a24860305bcaa155869a0ab354761ee179aaf41f370a381d`, signer key `SHA256:0WGGTlMDzLPGAhQaRG+Ag7BqoypSLinSNOHalZKtgt4`, valid until 2026-11-19T08:16:48Z. The key and passphrase never left the owner's terminal |
| Target and posture (read-only) | `production-target-check --operator --remote` → `PRODUCTION_TARGET=VERIFIED`; `sudo-policy-check` → `SUDO_POLICY=LEAST_PRIVILEGE`, entrypoint CURRENT; `trust-check` → `APPROVAL_CONTRACTS=2 3`, `APPROVAL_STORE=OK`, `RELEASE GUARD TRUST: READY` |
| Approval check | `submit-approval` stored the record; `check-approval` = **GO** (signature against the host anchor, SHA = base tip, release and approval unspent, required CI push run 44023053296 success with "Run critical regression tests" ran) |
| Deploy | `start` through the deploy operator (runner on the VPS, never local) at 16:18 WITA: deploy id **`20261117-081816`**, finished 08:19:54Z, `final exit=0`, `DEPLOY_APPROVED_TARGET_MATCH=YES`; `verify 20261117-081816` → **`DEPLOY RUNNER OK`** (runner contract 4, checkout HEAD matches) |
| Migrations | the two additive migrations (`2026_11_17_100001_add_claim_nonce_hash_to_trx_doctor_session_leases_table`, `2026_11_17_100001_create_mst_patient_rm_sequences_table`) ran inside the runner's `migrate --force` after its pre-deploy dump; the runner aborts on a failed dump or migration, so `DEPLOY OK` implies both; the log lines sit in the root-only deploy log (owner read, §10.3b) |
| Production verification (read-only) | `posture`: `HEAD=0f3c3bb3`, `LARAVEL_LOG_ERROR_LINES=166` (the baseline — no new application error), queue worker and timers unchanged; `drift-check`: `PRIVSEP_DRIFT=NONE`; HTTP on `https://daengtisia.online`: `/up` 200, `/login` 200, `/rme/visits`, `/settings/patients`, the consent signature stream → 302 to login (unauthenticated) |
| BK-2 | SKIPPED (owner directive) |
| Release id | spent |
| GO | **none** — release 2 is intermediate; the final GO tag waits for the memo wave and every mandatory item |

Pending owner steps after release 2: §10.3b (log read: backup + migrations), §10.4 (PermissionSeeder → RoleSeeder → permission:cache-reset), §10.5 (consent-signature copy **dry run only**, counts reported; `--apply` needs a separate approval; the fallback stays), and a visual check of one pre-release consent's signatures (rule 237 §14.5a).

### 9.7 Program status after release 2 — OPEN (no GO tag)

Release 2 is **DEPLOYED and PRODUCTION VERIFIED (read-only)**. The memo wave is **PENDING**. The program
DMS-RME-COMPREHENSIVE-CLOSURE-1 stays **OPEN**; the final GO tag `dms-rme-comprehensive-closure-1-go` is **not
allowed** until every mandatory item below is done, released and production verified (owner directive, §2a).

| Pending item | Owner | Acceptance criteria | Evidence required |
|---|---|---|---|
| Release 2 follow-ups (§9.6) | Owner (root / runtime-user commands) | deploy log shows the pre-deploy backup line and both `2026_11_17_100001_*` migrations DONE; `PermissionSeeder` → `RoleSeeder` → `permission:cache-reset` succeed; `rme:consent-signatures-migrate` dry run reports `CONFLICT=0`, `VERIFY_FAILED=0`; one pre-release consent shows its signatures (fallback) | pasted command outputs and the owner's visual confirmation, recorded in §9.6 |
| Consent signature copy `--apply` | Owner (separate approval after the dry run) | `CONSENT_SIGNATURE_MIGRATION=APPLIED`, `CONFLICT=0`, `VERIFY_FAILED=0`, nothing deleted | command output; re-run dry run shows `ALREADY_COPIED` = total |
| Removing the read fallback (rule 237 §14.5a) | Owner (separate approval after the copy is verified) | all needed copies verified; fallback removed by a reviewed PR | PR + CI + release evidence |
| Memo M3.3 (rule 240) PR1–PR7 | Engineering; owner approves each merge and the release | every PR independently reviewed (fix rounds closed), CI green on the exact heads, merged back-to-back, released together, production verified (lock, seal, amendment request/approve, version chain, handwriting amendment page, labels) | review records, PR CI runs, deploy id + verification |
| Memo PTM (rules 241/242) PR1–PR3 — **merged**: #663 `b204b2d6`→`fd2bf03e`, #664 `69872fb9`→`ae07ccb5`, #665 `b15f4fa9`→`c1b17367` (each on its exact re-tested head; #664/#665 updated after the previous squash with a strategy-`ours` merge, tree byte-identical to the tested head; critical gates 10,862 / 10,862 / 10,891 passed) — release 3 pending (+ PR4/PR5 clinical photos, wave 2) | Engineering; Owner supplies the clause 8/9 wording | PR1–PR3 ship together; v2026.2 stays **inactive** in production until the owner approves the final wording; photos flag `rme.clinical_photos` stays OFF until an owner decision | review records, CI, deploy; owner's written wording approval before any activation |
| Memo RM number (rule 244) PR-2, PR-3, PR-3b, PR-4 (+ PR-5 OCR suggestion, PR-6 records) | Engineering; owner approves merges/release | auto number, claim cap per batch, paper-card reason (restricted), core fields + no-phone; existing numbers never changed | review records, CI, deploy, production smoke of registration |
| Memo doctor scope (rule 243) B (#660, merged `41fecc06`; post-merge base run critical gate 10,759 passed), A1, A1b, A1c, A2, A3, A4, C1, C2 | Engineering; Owner decides turning the flag ON | B effective on release; encounter scope flag `rme.doctor_encounter_scope` stays OFF until A2–A4 (incl. the A3 list surfaces), C1–C2, M3.3 released, a dry run, doctor UAT and an owner decision | review records, CI, deploy; dry-run + UAT records; owner decision |
| CC1-ADJ-1 adjacent-visit arrows (#662, merged `b5772008` after every required gate passed on `ec274cf5`, critical gate 10,684 passed; release pending) | Engineering; owner approves merge/release | arrows offer only visits the viewer may open | review, CI, deploy, production smoke |
| PR6 SATUSEHAT submission guard | Owner grants the `workflow` gh scope | the local PR6 pushed, reviewed, CI green | PR + CI |
| CC1-CI-1 docs-only push run after a cancelled code run | Owner decision on a workflow fix (needs the `workflow` scope) | either a workflow fix or a recorded operating rule (dispatch run on release SHAs) | decision record |
| D4 readiness ceremony | BLOCKED_EXTERNAL | §10.7 | ceremony record |
| D5 branch confirmations; OPS-KTP timer | Owner (human operation) | §10.8, §10.1 | signed confirmations; timer status |
| R7-F, P8-FIX1, P8-SEED, R8A-3, RA-4, D3-NEW-3, D3-NEW-5, RA-3, RA-5 | Owner (definition / decision) | owner definition recorded | decision record |
| M5.1/M5.2 lab fees, post-payment correction, rollout | Owner (discussion) | owner decision | decision record |
| M6.2 storage cost | Owner (verified rate) | report + rate | `storage:usage-report` output + rate source |
| M6.1 / C4 organizational approvals | Owner / management | approvals exist before any all-branch rollout (no BUSINESS ROLLOUT APPROVED claim without evidence) | approval records |

## 10. Owner actions prepared by this program

### 10.1 OPS-KTP — install the KTP temporary-scan prune timer (root, owner's admin password)

Run from the owner's own terminal on the production host, after this release is deployed (the units ship in
`deploy/systemd/`, rule 182 §13):

```bash
cd /var/www/asia-dental-lab-v2
sudo install -m 0644 -o root -g root deploy/systemd/daengtisiams-ktp-temp-prune.service /etc/systemd/system/
sudo install -m 0644 -o root -g root deploy/systemd/daengtisiams-ktp-temp-prune.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now daengtisiams-ktp-temp-prune.timer
systemctl list-timers daengtisiams-ktp-temp-prune.timer --no-pager
```

Evidence to keep: the `list-timers` line (next run time) and, after the first run,
`journalctl -u daengtisiams-ktp-temp-prune.service --since today --no-pager | tail -5` (counts only, no file names).

### 10.2 CC1-PAT — read-only measurement before the patient-identity sprint

Counts only; no identifier leaves the database. Run in a read-only transaction on the production database:

```sql
BEGIN READ ONLY;
SELECT
  count(*)                                                                      AS patients,
  count(*) FILTER (WHERE medical_record_number IS NULL)                          AS mrn_missing,
  count(*) FILTER (WHERE medical_record_number !~ '^DG-[A-Z0-9]{3,5}-[0-9]{4}-[0-9]+$') AS mrn_not_canonical,
  count(*) FILTER (WHERE p.medical_record_number ~ '^DG-'
                     AND split_part(p.medical_record_number, '-', 2) NOT IN (SELECT code FROM mst_branches)
                     AND split_part(p.medical_record_number, '-', 2) NOT IN ('TKM1', 'SUN4')) AS mrn_unknown_branch_code,
  count(*) FILTER (WHERE b.code IS NOT NULL AND p.medical_record_number ~ '^DG-'
                     AND split_part(p.medical_record_number, '-', 2) <> b.code
                     AND NOT (b.code = 'TLK1' AND split_part(p.medical_record_number, '-', 2) = 'TKM1')
                     AND NOT (b.code = 'SPN4' AND split_part(p.medical_record_number, '-', 2) = 'SUN4')) AS mrn_branch_mismatch,
  count(*) FILTER (WHERE ktp_number IS NOT NULL AND ktp_number !~ '^[0-9]{16}$')   AS nik_not_16_digits,
  count(*) FILTER (WHERE registered_at > CURRENT_DATE)                            AS registered_in_future
FROM mst_patients p LEFT JOIN mst_branches b ON b.id = p.branch_id
WHERE p.deleted_at IS NULL;
SELECT code, is_active, is_rme_enabled FROM mst_branches ORDER BY code;
ROLLBACK;
```

**Result (owner, 2026-11-17, production, read-only `BEGIN READ ONLY … ROLLBACK` as the runtime user through
`php artisan db`; counts and aggregates only — the extended query of the memo implementation plan, M4.1 section):**

| Measure | Value |
|---|---|
| Live patients | 1,522 (all registered in the last 90 days) |
| RM number missing / not canonical / unknown branch code / branch mismatch / registered in the future | 0 / 0 / 0 / 0 / 0 |
| Generator-format (`DG-YYYYMM-NNNNNN`) / other free text / override-like (CC1-PAT-1) | 0 / 0 / **0** |
| Number part differs from `manual_rm_number` / non-numeric / shorter than 4 digits / 4 digits / longer | 0 / 0 / 0 / 1,522 / 0 |
| Number year ≠ registration year / future year | 0 / 0 |
| Per branch-year | one group: SPN4, 2026 — 1,522 numbers, max 4 digits, none above 9999, no numerically equal duplicates, gaps present |
| NIK present but not 16 digits (CC1-PAT-4) | 84 |
| Core-field gaps among live patients (M4.2) | date of birth 1, phone and WhatsApp 5, address 2, occupation 103, gender 0 |
| RM number unique index | `mst_patients_medical_record_number_unique`, unique |
| Branch flags | ATG3, LDK2, SPN4, TLK1 active and RME-enabled; MAIN active, **RME disabled**; SYN4A inactive, RME disabled |

Consequences: the free override was never used (CC1-PAT-1 = 0), so retiring it needs no reconciliation; every
existing number is canonical, 4 digits and in one branch-year, so the sequence seed is a single counter (SPN4 2026 =
the highest existing number, shown only by the host-side dry run; gaps are never reused); MAIN is not RME-enabled in
production (CC1-PAT-3 is a fresh-seed concern only); the 84 non-16-digit NIK values and the core-field gaps are legacy
data that the M4.2 rules never force (new patients only).

### 10.3 Release 1 — the pre-deploy dump line (root-only log, owner's admin password)

The deploy operator replays only the summary of a finished deploy, and the full log is root-only. Read the dump and
outcome lines from the owner's own terminal:

```bash
ssh -t daengtisiams-biznet-prod 'sudo grep -E "Pre-deploy backup|DEPLOY OK|DEPLOY_SNAPSHOT_CLEANED" /var/lib/daengtisiams-deploy/runner/deploy-20261116-141906.log'
```

### 10.4 Release 2 — permissions after deploy (runtime user, CG-15)

PR #648 adds the permission `void_rme_consents` and grants it to Supervisor RME. After the release-2 deploy, as the
runtime user in the app directory: `PermissionSeeder`, then `RoleSeeder`, then `permission:cache-reset`.

### 10.5 Release 2 — consent signature copy (owner approval for `--apply`)

Rule 121 has no read fallback: after release 2, a consent signed before it renders its signatures only once the copy
has run. The copy belongs in the release window, right after verification. Dry run first; `--apply` only with the
owner's approval, which is requested together with the release approval:

```bash
php artisan rme:consent-signatures-migrate            # dry run: counts only
php artisan rme:consent-signatures-migrate --apply    # owner-approved: copies, verifies, never deletes
```

Expected: `CONFLICT=0`, `VERIFY_FAILED=0`, `CONSENT_SIGNATURE_MIGRATION=APPLIED`; a `MISSING` count is a WATCH
(objects already unreadable before the run). Purging the old disk is a separate phase and a separate owner decision.

### 10.6 Release 2 — lab candidate backfill (owner approval for `--apply`)

Since #657 (rule 237 §18) `--apply` needs an explicit payment window in clinic dates (WITA); the dry run shows the
counts per month to choose it from:

```bash
php artisan rme:lab-candidates-backfill                                              # dry run: counts per month
php artisan rme:lab-candidates-backfill --apply --paid-from=YYYY-MM-DD [--paid-to=YYYY-MM-DD]   # owner approval only
```

### 10.7 D4 — readiness re-audit and ceremony (BLOCKED_EXTERNAL)

The authoritative ceremony is `docs/operations/doctor-real-device-readiness-campaign.md` §13, with the D4 conditions
of `dms-rme-revision-program-8-owner-operational-closure.md` (a dedicated device that is not an operational clinic
tablet, enrolled through the canonical approval path with the Android Keystore proof and a device-bound passkey; a
dedicated test doctor account linked one-to-one, never a real clinician's account). The owner's 2026-11-16 answer
adds: pilot devices and real doctor accounts are never treated as test-only, and global enforcement stays off.

Readiness re-audit, read-only, as the runtime user (counts and states only; keep doctor and device identifiers out of
any shared evidence):

```bash
php artisan webauthn:readiness
php artisan doctor:rollout-readiness | grep -E 'ENFORCEMENT_FLAG_ARMED|GLOBAL_ENFORCEMENT_ACTIVE'
php artisan doctor:fleet-readiness
php artisan doctor:estate-resilience
```

D4 stays BLOCKED_EXTERNAL until the owner names the dedicated device and the dedicated test doctor. `webauthn:readiness`
ARMED means "configured", not "proven live".

### 10.8 D5 — branch confirmation template (one per branch: SPN4, TLK1, LDK2, ATG3)

A confirmation counts only when a person responsible at the branch gives it; it is recorded here by role, date and
channel, never by personal name or patient detail (public repository):

| Field | Value |
|---|---|
| Branch code | |
| Confirmer's role at the branch | |
| Date (WITA) and channel | |
| Paper RME archives remaining at the branch | YES / NO |
| If YES: approximate count and whether intake should continue | |
| Recorded by | |

After all four are recorded, the canonical closure path of the Program 8 owner checkpoint record (D5 section) runs
step by step, dry run first, as the runtime user.

## 11. Graphify, Dataviz, Obsidian

- Graphify baseline recorded in §1; the post-change refresh is recorded at closure.
- Dataviz: the register (§4) and the test table (§6) are the data; the report is published at closure with counts
  only (no patient data).
- Obsidian: `docs/` is the knowledge base; this record and the module index are updated in place. The vault worktree
  is locked and is not edited (CG-02).
