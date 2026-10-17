# FEATURE-RME-BRANCH-TARIFF-PROVISIONING-1 — branch tariff provisioning governance

**Status: SCOPED FOUNDATION.** Provisioning governance is implemented; **no tariff
was imported**, because no owner-approved tariff list exists (D21h). Every branch
stays TRANSITIONAL; strict pricing, price exceptions and invoice correction stay OFF.

Rule: `.cursor/rules/193-rme-branch-tariff-provisioning-governance.mdc` (extends 192).
Decision register: `docs/decisions/rme-billing-correction-owner-decisions.md` (unchanged —
D21h and D21a–i remain PENDING_SUB_DECISION; nothing was self-approved).

## 1. Executive summary

The sprint's goal was to provision approved tariffs for every RME branch. The
source audit found **no owner-approved price list anywhere** — not in the
repository, the production VPS, connected documents/artifacts, or the operator's
local files. Inventing, copying or deriving prices is forbidden, so the import was
**not executed** and the 41 missing combinations stay missing.

The audit also found a real governance defect: **production TLK1's 11 tariffs are
the Sprint 19 `TariffSeeder` development defaults**, and
`rme:tariff-authority-readiness` counted them exactly like approved prices. Two
more tariffs (TRT-FREESC, TRT-SC99) would have made TLK1 `TARIFF_READY` on
placeholder prices, and nothing machine-checked rule 192 §39's precondition
"official list imported". This release makes coverage and approval separate,
recorded facts.

## 2. Baseline (verified, not assumed)

| Item | Value |
|---|---|
| Canonical base (remote) | `origin/feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `017540e8` (evidence-only, undeployed) |
| Production runtime | `90a39579` = `feature-rme-branch-tariff-authority-1-foundation-go` (exact match), tracked tree clean |
| Deploy lock | free (advisory `flock` probe) |
| Production DB | PostgreSQL 16.15, all checks under `default_transaction_read_only=on` |

## 3. Branch and treatment registry (production, read-only)

Branches: TLK1 (1), LDK2 (2), ATG3 (3), SPN4 (5) — active + RME-enabled. MAIN (4)
active but not RME-enabled (never billed). SYN4A (6) inactive, soft-deleted.

Treatments (global master, all active, none deleted): TRT-CONS, TRT-SCAL, TRT-FILL,
TRT-EXTR, TRT-RCT, TRT-PDEN, TRT-FDEN, TRT-CRWN, TRT-BRDG, TRT-DENT, TRT-RETN,
TRT-SC99 (Scalling 99K), TRT-FREESC (Scalling Voucher Gratis) — **13**.
The schema has **no per-branch applicability**: every active treatment is billable
in every branch (rule 192 §9), so NOT_APPLICABLE cannot be derived from data.

## 4. Tariff coverage before (52 combinations)

| Branch | Mode | Readiness | Provisioned | Approved source | Missing |
|---|---|---|---|---|---|
| TLK1 | TRANSITIONAL | TARIFF_INCOMPLETE | 11/13 | **0/11** (seeded defaults) | TRT-FREESC, TRT-SC99 |
| LDK2 | TRANSITIONAL | NO_TARIFFS | 0/13 | — | all 13 |
| ATG3 | TRANSITIONAL | NO_TARIFFS | 0/13 | — | all 13 |
| SPN4 | TRANSITIONAL | NO_TARIFFS | 0/13 | — | all 13 |

Existing 11 / missing 41. Duplicate active tariffs: 0. Conflicts: 0. Zero-price
tariffs: 0. Tariff audit rows (`RME_TARIFF_*`): **0**. SPN4 billed lines: 8, all
`NOT_RECORDED` (pre-authority legacy, never backfilled).

**TLK1 provenance evidence.** Rows id 1–11: prices equal `TariffSeeder::PRICES`
exactly (TRT-CONS 50.000 … TRT-BRDG 3.500.000), all `effective_date 2026-01-01`
(= `TariffSeeder::EFFECTIVE_DATE`), all `created_at = updated_at = 2026-06-10
14:54:02`, no notes, no audit row. Classification: **EXISTING_UNVERIFIED_PROVENANCE**
— preserved untouched, never treated as approved.

## 5. Official tariff source register

| Source searched | Result |
|---|---|
| Repository (docs, CSV/XLSX, seeders) | none — only `TariffSeeder` (development defaults, not a source) |
| Production VPS filesystem (`*tarif*`, `*tariff*`, `*harga*`, `*pricelist*`) | none |
| Connected Claude artifacts/documents (29 listed) | none |
| Operator local files | none relevant (unrelated marketing material) |

**OFFICIAL_TARIFF_SOURCE = MISSING.** No public/competitor price, historical invoice
amount or treatment name was used. `TRT-SC99` ("Scalling 99K") is not evidence of
a Rp 99.000 tariff; `TRT-FREESC` is a D21g (Rp0) decision.

## 6. Provisioning classification

| Class | Count | Pairs |
|---|---|---|
| EXISTING_VALID (approved) | 0 | — |
| EXISTING_UNVERIFIED_PROVENANCE | 11 | TLK1 × 11 seeded |
| MISSING_APPROVED_SOURCE_AVAILABLE | 0 | — |
| MISSING_SOURCE_UNAVAILABLE | 33 | LDK2/ATG3/SPN4 × 11 non-free codes |
| PENDING_OWNER_DECISION | 8 | TRT-FREESC (D21g) × 4, TRT-SC99 (D21h, name ≠ price) × 4 |
| EXISTING_CONFLICT / DUPLICATE / INACTIVE | 0 | — |
| NOT_APPLICABLE | 0 derivable | needs owner decision + schema |

## 7. What was built (deployable)

1. **Migration** `2026_10_17_300001_add_approval_provenance_to_mst_tariffs_table`:
   nullable `approval_reference` (≤120), `approval_recorded_by` (FK users,
   nullOnDelete), `approval_recorded_at`. Additive, no backfill.
2. **`TariffProvenance`** — VERIFIED/UNVERIFIED/NONE + the one reference rule
   (6–120 chars, `[A-Za-z0-9][A-Za-z0-9._:/-]*`).
3. **Model/repository** — columns not fillable; `recordApprovalProvenance()` /
   `clearApprovalProvenance()` are the only writers; `approvalReferencesFor()`
   (one query); `lockForAttestation()` (`lockForUpdate`).
4. **Import** — `--approval-reference` required on apply and bound into the
   digest; `ATTEST` action (identical price, no provenance → provenance only,
   under a row lock with re-check); `UNCHANGED` never overwrites a reference;
   CONFLICT/create-only unchanged; audits `RME_TARIFF_IMPORTED` /
   `RME_TARIFF_PROVENANCE_ATTESTED`.
5. **Laundering guard** — a `Tariff` model `updating` hook withdraws approval in
   the same UPDATE whenever price/date/treatment/branch changes, on every Eloquent
   save (master data, `updateOrCreate`, repairs); `TariffService::update()` keeps
   its own check as a second layer.
6. **Readiness** — `provenance`, `approved_tariffs`, `unverified_tariff_codes`,
   `strict_eligible`; WATCH `TARIFF_PROVENANCE_UNVERIFIED`; FAIL
   `STRICT_BRANCH_TARIFF_PROVENANCE_UNVERIFIED`.
7. **Template** — `current_tariff_provenance` column.

Not changed: pricing modes, the resolver, invoice pricing, D10, exceptions, the
correction flag, permissions, roles, D21i date basis.

## 8. Tests

| Suite | Result |
|---|---|
| `RmeTariffProvisioningProvenanceTest` (new, 31) | red-first: 23 failed / 4 passed before implementation (first 27); green after; +4 after the security review |
| `RmeTariffProvisioningConcurrencyTest` (new, 2, PG only) | PASS on PostgreSQL 16.15 |
| `tests/Feature/RmeTariffAuthority` (SQLite) | 108 passed, 2 skipped (PG-only) / 401 assertions |
| Tariff + correction + master data + stress seed (SQLite) | 273 passed, 10 skipped (PG-only) |
| Cashier / payment / receivable / receipt / refund / oversight / Finance Klinik / Data Analyst / permission (SQLite) | 293 passed, 3 skipped (PG-only) |
| Tariff + correction + master data on **PostgreSQL 16.15** (incl. D10 races) | **274 passed, 0 skipped** / 1262 assertions |
| Migration up → down → up on PG16 | clean |

Pre-existing commands test updated (not relaxed): apply now carries the reference,
template header gained the provenance column.

**Mutation (focused, copy-based revert, every mutant verified to apply, file
restored byte-identical):** 15 total / **14 killed** / 0 real survivors /
**1 equivalent** — reference gate, digest binding, overwrite of an existing
reference, ATTEST row lock (PG), ATTEST price re-check (PG), readiness
provenance, strict FAIL, create-only CONFLICT, mass assignment, provenance on
CREATE, malformed reference, model laundering hook, NIK-shaped reference,
inactive actor. The equivalent is M6 (service-level clear removed): the model
hook already cleared the approval in the same UPDATE; the compound mutant
(hook + service clear removed) is KILLED (4 failed), so the layer is deliberate
redundancy, not dead coverage.

## 8a. Security review (independent, adversarial)

**No CRITICAL, no HIGH.** Clean: forged approval (not fillable, verified by probe),
UI laundering, digest binding, ATTEST race, create-only, cross-branch, CSV/formula/
log injection, readiness false green, historical financial data, migration,
authorization.

| Finding | Severity | Resolution |
|---|---|---|
| Laundering guard only in `TariffService`; `updateOrCreate` kept an approval on a new price (probe-verified) | MEDIUM | **Fixed**: model `updating` hook + test + mutant M13 |
| Reference accepted 16-digit NIK-shaped tokens | LOW | **Fixed**: 13+ digit runs refused + test + M14 |
| Service `apply()` did not check the actor is active | LOW | **Fixed** + test + M15 |
| Concurrent-create unique violation surfaced as a stack trace | LOW | **Fixed**: command reports `PLAN_CHANGED` (nothing written) |
| Approval is self-attested (same permission edits and imports; `--actor` is attribution) | LOW | **Accepted, documented** (rule 193 §5a) |
| Reference not bound to a branch | LOW | **Accepted, documented** |
| Provenance is report-only; strict billing does not read it | LOW | **Accepted, documented** — runtime pricing deliberately unchanged |

## 9. Pending owner decisions (BLOCKED — nothing invented)

- **D21h** official tariff list for TLK1, LDK2, ATG3, SPN4 — the blocker for every import.
- **D21g** Rp0 / intrinsically free treatments (`TRT-FREESC`, Rp0 consultation/control).
- **TLK1 seeded prices** — approve as-is (one ATTEST import) or replace.
- **TRT-SC99** price per branch (the name is not a price).
- D21a–f (transition duration, exception approver, caps, thresholds), D21i (date
  basis; current default unchanged), D1a (correction applier).
- Per-branch NOT_APPLICABLE treatments (needs a decision and a schema change).

## 10. Next activation prerequisites

1. Owner supplies a signed price list per branch with a reference id.
2. `rme:tariff-template --branch=X` → fill `price` from the list only → dry run
   with `--approval-reference` → review CREATE/ATTEST/digest with the owner →
   `--apply` on the VPS as the runtime user → `rme:tariff-authority-readiness`.
3. A branch reaches STRICT_READY only with `strict_eligible = true`.
4. Strict mode per branch is a separate approved config change in a supervised
   window. Correction activation additionally needs rule 191 §20.
