# LEGACY-RME-PDF-1D — VOID and Clinical Read / Print Completion

**Branch:** `feature/legacy-rme-pdf-1d-void-clinical-read-print`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
(ROLL-1 merge `9e90c7bb2bf59fc4ddd966df6dfcc8b52b9c8e4b`, GO tag
`legacy-rme-pdf-roll-1-feature-flag-runtime-readiness-go`)
**GO tag:** `legacy-rme-pdf-1d-void-clinical-read-print-go`

> **Production stays OFF.** `rme.legacy_pdf_archive` remains `false`; every 1D
> route answers 404 server-side while it is. ROLL-1 made that switch
> trustworthy — 1D does not throw it.

---

## 1. Scope

1C ended with a published, immutable archive that the Super Admin operator could
read. 1D completes the clinical lifecycle around it:

| Area | Outcome |
|---|---|
| Retraction | `POST rme/legacy-records/{record}/void` (`void_legacy_rme_imports`) |
| Clinical read | `view_legacy_rme_archive` — a **new**, read-only permission granted to Doctor |
| Print | `GET rme/legacy-records/{record}/print` — browser `window.print()` |
| Export | `GET rme/legacy-records/{record}/export` — dompdf download |

**No migration.** `voided_by`, `voided_at` and `void_reason` have existed since
1A, `LegacyRmeRecordStatus` already declared `PUBLISHED → VOID` as terminal,
`LegacyRmeRecordPolicy::void()` already existed, and `void_legacy_rme_imports`
was already seeded. 1D supplies the runtime those pieces were waiting for.

## 2. VOID semantics

**Retract, never erase.** The row, its pages and its files on the private disk
all survive. `markVoided()` writes only `status` / `voided_by` / `voided_at` /
`void_reason`; patient, date, file, checksums and pages are untouched, so a
"correction" cannot be smuggled in as a void.

**Terminal.** `PUBLISHED → VOID`, and nothing out of VOID. No un-void, no
republish, no delete route. Correcting a mis-filed archive is a VOID plus a
**fresh import**, because rewriting a published record in place would destroy
the evidence trail the archive exists to provide.

**Reasoned.** A reason of at least 10 characters is required — enforced in the
FormRequest *and* again in the service, since a FormRequest only guards the HTTP
door. The canonical trigger is a document attached to the WRONG patient, and a
later reader must be able to tell a mis-file from a duplicate.

**Atomic and idempotent.** One transaction opening with a row lock that re-reads
the status under it. A second void is a no-op preserving the **first** reason and
actor, and writes no second audit row.

**Audited, without leaking.** `LEGACY_RME_VOIDED` carries `status` and
`void_reason_length` only. The reason itself is operator free text that may name
a patient, and the audit allow-list is structure-only — its permanent home is the
record's `void_reason` column.

**A VOIDed archive stops serving bytes.** Source, pages, print and export all
refuse it (1C already established this for `viewFile`). The row stays readable so
the metadata and reason remain auditable.

### The repository exception

`LegacyRmeRecordRepositoryInterface` deliberately exposed no update and no
delete. 1D adds exactly two narrow, named methods — `lockForUpdate()` and
`markVoided()` — rather than a generic `update()`, which would quietly restore
in-place mutation of clinical evidence. The column list is hard-coded inside the
repository so no caller can widen a void into an edit.

## 3. The clinical reader

`view_legacy_rme_imports` is the **intake** operator (upload, review, publish,
retract) and is held by Super Admin. Granting it to a doctor just so the archive
is readable would hand out archive management with it.

1D therefore adds `view_legacy_rme_archive`: read, and nothing else.

- Either permission satisfies `LegacyRmeRecordPolicy::READ_PERMISSIONS`; neither
  implies the other, and neither implies review/publish/void.
- It is **deliberately not** in `LegacyRmeWorkspaceScope::GOVERNANCE_PERMISSIONS`,
  so its holder stays pinned to their own `BranchContext` branch instead of
  widening to every RME branch. Rows with no branch provenance stay
  governance-only.
- Out of scope is **404, never 403** — a reader must not be able to probe which
  archive ids exist in a branch they cannot see.

**Honest note on "fail closed":** `BranchContext` has a documented fallback chain
(online context → `users.branch_id` → relation → MAIN/first active), so an
unpinned doctor still resolves *some* branch rather than none. The security
property that actually holds — and that the tests assert — is **non-widening**: a
clinical reader's scope is never more than one branch and never the governance
set.

## 4. Print and export

Both sit behind auth, the permission, the policy and the feature flag, and both
refuse a VOIDed record.

- **Print** *references* page images through the existing policy-gated page
  route, so it never becomes a second, weaker door to the private disk: the
  browser re-requests each page with the caller's own session and every request
  goes through the same policy.
- **Export** embeds pages as data URIs read back *through the storage
  abstraction*, because dompdf carries no session and cannot fetch the gated
  route. The absolute filesystem path stays inside the storage service.

Both outputs are visibly **ARSIP RME LAMA — DOKUMEN HISTORIS (HANYA BACA)** and
state they are not a visit, bill, payment or lab order, so they can never be
mistaken for native RME output. The dompdf template is table-based (no flexbox,
no external asset). The download filename is generic — no patient name, no
medical-record number. KTP/NIK appears nowhere, asserted both at runtime and by a
static scan of the templates.

The export is bounded at 30 embedded pages; past the cap the document still
renders, says plainly that it is truncated, and points at the complete source
PDF. An unbounded archive would otherwise inline dozens of full-resolution PNGs
into one dompdf run and exhaust memory.

**GD dependency.** dompdf decodes the embedded PNGs through GD. CI
(`extensions: … gd, exif`) and the production host both have it; a bare local CLI
often does not. The two tests that genuinely render a PDF are GD-guarded, and
were verified in a GD-enabled container (`serversideup/php:8.3-cli` +
`install-php-extensions gd`) — **28/28 passed with GD**, so the export path is
proven rather than merely skipped. Every authorization, branch-scope, void and
feature-flag assertion around export runs everywhere, because those refuse before
dompdf is reached.

## 5. No downstream side effects

Voiding writes to one table. A test snapshots visits, invoices, medical records,
payments, odontograms, lab candidates, lab orders and SATUSEHAT candidates before
and after and asserts they are unchanged.

## 6. Superseded assertion

1C asserted the published-archive surface had **no write route**. 1D supersedes
that by exactly one route. The repin preserves the intent rather than relaxing
it: VOID is the only write, it is a `POST` (never `PUT`/`PATCH`/`DELETE`, which
would imply an edit or an erasure), every other route stays GET/HEAD, and the
test still asserts no `update`, `destroy` or `republish` route exists.

## 7. Tests

- `tests/Feature/LegacyRme/LegacyRmeVoidTest.php` (18) — retraction, audit
  redaction, non-erasure, non-editing, history exclusion, byte refusal,
  idempotency, terminality, races, reason floor (form *and* service),
  authorization, 404-not-403, guest, flag off, side-effect snapshot.
- `tests/Feature/LegacyRme/LegacyRmeClinicalReadPrintTest.php` (28) — permission
  separation, non-widening, provenance-less rows, doctor read/print, write
  refusal, print/export content and privacy, static template scan, VOID refusal,
  audit, flag off, guests, unauthorized.

## 8. Carry-forward

- **INFRA-POPPLER** — Poppler still needs declarative server provisioning.
- **CICD-FIX-1** — pre-existing `file_get_contents` warning noise.
- **Deploy queue restart contract.**
- **Controlled production enablement of `rme.legacy_pdf_archive`** — still a
  separate, explicit rollout decision. 1D completes the capability; it does not
  turn it on.