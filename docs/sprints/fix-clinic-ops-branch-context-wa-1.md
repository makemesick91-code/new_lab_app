# FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1

Nine integrated fixes to the clinic-operations and cashier surfaces.

| | |
|---|---|
| Branch | `feature/fix-clinic-ops-branch-context-wa-1` |
| Base | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `7f28fdf` |
| GO tag | `fix-clinic-ops-branch-context-wa-1-go` |
| Rule mirror | `.cursor/rules/108-clinic-ops-working-branch-context.mdc` |
| Migration | one additive table, `trx_rme_prescription_whatsapp_deliveries` |

## The shared idea

Three of the nine fixes (03, 04, 09) are the same concern: **an operator works
in one branch at a time, and their workspace should show exactly that branch.**
Rather than adding a branch predicate to dozens of controllers, this sprint adds
one authority — `App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope` —
and wires it into the chokepoints that already existed.

- **Context-bound roles** (Admin Klinik, Perawat, and now Kasir) resolve to
  exactly their selected working branch.
- **Fail closed.** No valid context means an *empty* scope — never the whole
  estate, never a MAIN/first-branch fallback.
- **Governance roles** (Owner, Super Admin, Supervisor RME) and the reporting
  roles keep the full active RME set, so cross-branch analytics are unchanged.
- **Doctor is deliberately excluded.** The doctor clinical branch model
  (practice branches + `DoctorClinicalBranchResolver`) is a separate domain and
  is not regressed.
- **A request `branch_id` may only narrow** an already-authorised scope. It can
  never widen it.

Kasir reaches its working branch through the same branch-only online context
Admin Klinik and Perawat already use, so "the branch I am working in" is an
explicit, audited choice rather than a static column fallback.

## The nine fixes

| # | Fix | Where the boundary lives |
|---|---|---|
| 01 | Branch address on documents | the record's own `branch` relation |
| 02 | Prescription delivery over WhatsApp Business | `WhatsAppGatewayInterface` + policy `sendWhatsApp` |
| 03 | Cashier working branch | `RmePaymentService::assertInvoicePayable` + `RmeInvoicePolicy` |
| 04 | Admin Klinik working branch | `ClinicVisitService::scopeBranchIds` + `ClinicVisitPolicy` |
| 05 | Admin Klinik cannot finish an examination | `complete_rme_examination` capability |
| 06 | Kunjungan defaults to today | `ClinicalClock` + `applyVisitIndexDateDefault` |
| 07 | Admin Klinik visit detail is read-only + print | `ClinicVisitPolicy::operateFromDetail` |
| 08 | SATUSEHAT is Super Admin only | the `satusehat.access` gate |
| 09 | Cashier RME surfaces branch-scoped | `RmeWorkingBranchScope` via the cashier chokepoints |

### FIX-01 — document identity

`mst_branches.address` and `.phone` had existed since the first migration and
were already validated, but the Master Data form had no inputs for them, so they
could never be set. **No migration was needed** — only the form, the list, and
the documents.

The real defect was that the Telkomas street address was hardcoded into the
*shared* odontogram print template, so a Landak, Antang or Sunu odontogram
printed the Telkomas address. Documents now read the owning record's branch, and
a branch with no address prints no address line rather than falling back to
another branch's identity (`'TELKOMAS'` / `'Makassar'`).

**Rule: a document's identity follows the record, not the viewer.** Printing a
Landak record while working in Telkomas still prints Landak.

### FIX-02 — WhatsApp Business prescription delivery

Server-to-server through Meta's official Cloud API. No `wa.me`, no WhatsApp Web,
no browser redirect. Verified against Meta's current documentation:

> Template messages are the only type of message that can be sent to WhatsApp
> users outside of a customer service window.

A prescription hand-off is proactive, so it always sends an **approved utility
template**. That requirement is never bypassed.

The gateway mirrors the SATUSEHAT pattern already in this codebase and is **OFF
by default**: without credentials the disabled gateway is bound and opens no
socket. The Cloud API client validates its host against an allowlist and
requires HTTPS *before* any request, never follows redirects, and never logs,
persists or returns the access token. Sending is an explicit, confirmed operator
action, idempotent per prescription + recipient + template, and fails closed —
a rejection, timeout or missing credential leaves the clinical record untouched.

Configuration keys are documented in the environment example file. See
"Production readiness" below.

### FIX-05 — who may finish an examination

"Selesai Pemeriksaan" moved out of the broad `manage_clinic_visits` into its own
`complete_rme_examination` capability (Doctor, Perawat, Supervisor RME; Super
Admin via `Gate::before`). Admin Klinik keeps registration and room placement but
can never close an examination — enforced at the route *and* again in the
service, so a non-HTTP caller cannot do it either.

The cashier-owned `cashier_pending → completed` transition is untouched: it still
happens only in `RmePaymentService` once the invoice is settled.

### FIX-06 — today, on the clinic's calendar

Daftar Kunjungan opens on the clinical day and offers an explicit date and range
filter for history. "Explicit" means the request actually carries a date key —
clearing the field is itself a deliberate request for the full history.

This exposed a genuine latent bug. The filter resolves the clinical day
(Asia/Makassar, the canonical authority) while registration stamped `visit_date`
from a UTC `today()`. Those differ for eight hours of every day, so a visit
registered between 00:00 and 08:00 WITA was recorded with *yesterday's* date —
and, since the same value drives the queue number and visit number, yesterday's
sequence too. Both sides now use `ClinicalClock`.

## Known residual

`StoreClinicVisitRequest` still falls back to a UTC `today()` when composing a
new patient's RM number and no `registered_at` is supplied. It is the same class
of inconsistency as FIX-06 and matters only in the same eight-hour window, but
it touches RM-number composition, so it was left alone deliberately rather than
changed as a side effect of this sprint. It is reported, not fixed.

## Production readiness — FIX-02

The code is complete and hermetically tested, but WhatsApp delivery cannot be
*operational* until the owner supplies, from a Meta Business account:

1. a WhatsApp Business Platform app with a permanent access token,
2. the business phone number ID, and
3. an **approved utility template** for the prescription hand-off.

Production had none of these at implementation time. Until they exist the
feature ships enabled-in-code but switched off, the disabled gateway is bound,
and the UI says so plainly. This is stated as a blocker rather than presented as
a working integration.

## Tests

46 new tests:

| File | Covers |
|---|---|
| `tests/Feature/RME/FixClinicOpsWorkingBranchContextTest.php` | FIX-03/04/06/09 — scope authority, crafted filters, direct URLs, exports, context switching, clinical-day stamping |
| `tests/Feature/RME/FixClinicOpsVisitActionsTest.php` | FIX-05/07/08 |
| `tests/Feature/RME/FixClinicOpsBranchDocumentIdentityTest.php` | FIX-01 |
| `tests/Feature/RME/PrescriptionWhatsAppDeliveryTest.php` | FIX-02 |

Existing tests were updated for the new rules, not around them: Kasir fixtures
select a working branch exactly as Perawat's did when Perawat gained a context,
and actors that finish an examination now hold the capability it requires. No
test was skipped, deleted or weakened to pass.

## Closure — consolidated Full Suite and final status (2026-08-20)

Status: **WATCH — no GO tag.** Eight of the nine fixes are production-complete
and machine-verified; FIX-02 remains blocked on an external dependency, so the
programme deliberately does not close.

### Consolidated Full Suite

The GLOBAL TEMPORARY FULL-SUITE POLICY stayed ACTIVE throughout; both runs were
explicit, individually authorised `workflow_dispatch` invocations.

| Run | Source SHA | Result | Assertions | Duration |
|---|---|---|---|---|
| `32319351675` | `99c5643` | **1 failed** | 30283 | 14172.78s |
| `32344343470` | `82f1122` | **0 failed** | 30284 | 14309.25s |

The first run's single failure was `Sprint41WhatsAppManualReminderOperationalization
FollowUpWorkflowTest` line 138 — `Failed asserting that true is false` on
`str_contains($name, 'whatsapp.send')`. Sprint 41 had asserted that **no**
WhatsApp send route exists anywhere; FIX-02 deliberately introduced exactly one.

PR #322 (`82f1122`, **test-only**) narrowed that contract rather than deleting
it. The receivable/follow-up surface is still asserted to have no send route of
its own, and the app-wide permitted set is now pinned **exactly** to
`['rme.prescriptions.whatsapp.send']` — stricter than the original substring
ban, because a second send route can no longer appear unnoticed. Verified
against production runtime: 566 routes, exactly one WhatsApp send route.

The second run's classifier resolved `full_suite_authorized=true`,
`gate_profile=unknown_high_risk`, `run_critical_tests=true`, and the
`Run full Pest suite` step completed as **executed**, not skipped. Every other
gate was green: Quality, Classifier, Critical, Selective Module, NSF-9 Release
Safety & Smoke, NSF-10 Release Evidence.

### Authority

`82f1122` is test-only, so the deployed runtime authority remains `99c5643`
(VPS HEAD exact match, working tree clean). The corrective SHA is **not**
deployed, following the established evidence-commit pattern.

### FIX-02 — still blocked

Production carries **none** of the required Meta values (access token, phone
number ID, approved utility template name), so the container binds
`DisabledWhatsAppGateway` and opens no socket. Re-verified against Meta's
current documentation on 2026-08-20: template messages remain the only type
deliverable outside an open 24-hour customer service window, and Graph API
v23.0 is supported until 8 October 2027 (latest v26.0). Because the graph
version and every credential are read from configuration, **activation is
config-only** — no source change is required, which is why this Full Suite
result stays valid once credentials arrive.

### Defect found during closure — not fixed here

`asia_dental_lab`, a **2.9 MB SQLite database, was committed to the repository
root** by this programme's merge and is still at the base-branch tip. It is
schema-only: of 128 tables only `migrations` and `sqlite_sequence` hold rows,
and every patient, user, clinical, invoice, payment and audit table is empty —
**zero PII**, so this is repo hygiene, not a security incident. It originates
from `DB_DATABASE=asia_dental_lab` combined with a SQLite connection, and there
is no ignore rule covering it, so it can recur. It was left in place
deliberately: a new commit would have moved the source tip out from under the
one authorised Full Suite. **Follow-up:** remove it from tracking and add ignore
rules for it and for `*.sqlite` / `*.sqlite3`.
