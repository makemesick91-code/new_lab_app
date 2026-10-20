# ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2

Pre-payment RME invoice correction — controlled pilot activation.
Rule: `.cursor/rules/195-rme-invoice-correction-prepayment-pilot-activation.mdc`.
Decisions: `docs/decisions/rme-billing-correction-owner-decisions.md`.

## 1. Baseline (verified, not assumed)

| Fact | Value |
|---|---|
| Canonical base (remote) | `6660f9927281b6003df69a6d24f68e8cea517e6f` (docs evidence on `576e8aae`) |
| Production at start | `576e8aaeafa29186251da18f47ab2ce84c751c53`, tree `b385b3fc`, tag `revision-rme-manual-pricing-authority-1-go` |
| Flag `rme.invoice_correction` (prod) | `enabled=false`, `via=default` (environment key absent) |
| Correction permissions (prod) | held only by Super Admin |
| Prod roles | Kasir **0** users, Admin Klinik **0** users, Front Office **8** users (7, 8, 16, 17, 29, 30, 31, 32), Super Admin user 1 |
| Prod corrections / document versions | 0 / 0 |
| Prod invoices (all SPN4) | PAID 5 (Rp 3.548.000), PARTIAL 1 (Rp 4.000.000), UNPAID 1 (id 36, **Rp 0**, 1 item, 0 payments) |
| Prod payments | 8, Rp 5.548.000 |
| Prod invoice items | 8, all with NULL `pricing_mode`/`price_source` (pre-manual-pricing legacy), max qty 1 |
| Prod non-whole amounts | 0 items, 0 invoices, 0 payments |

The only UNPAID invoice in production is a legacy Rp0 invoice. There is **no**
positive UNPAID invoice that a pre-payment correction could legitimately target.

## 2. Owner decisions (2026-10-21)

Asked as four explicit questions; answers recorded verbatim in the register and
confirmed by the owner in a follow-up message:

| Code | Decision |
|---|---|
| D1a | The CHECKER applies, automatically at approval (one transaction). |
| D1b | Front Office holds request AND approve (literal D1 on the merged role). |
| D1c | Pilot scope: SPN4 (Cabang Sunu) only, server-side allowlist, fail-closed. |
| D1d | Pilot accounts: maker user 29 (Admin Sunu), checker user 1 (IT Support, Super Admin). |

D21c (discount policy) and D21g (Rp0) were NOT decided. Classified
`SAFE_TO_DEFER_WITH_EXPLICIT_RESTRICTION` (register §D21c/D21g): no cap invented;
the existing bounds plus a second account's fingerprint approval apply; every new
Rp0 line/total is refused.

The D1b question existed only because of a production measurement: the D1 roles
(Kasir, Admin Klinik) have no users, and Front Office is by construction their
exact union (`FrontOfficeRoleTest`), so a literal D1 grant puts both halves on
every front-desk account. Maker ≠ checker is enforced per ACCOUNT, so this never
lets one person approve their own request.

## 3. What was reused and what changed

| Component | Classification |
|---|---|
| Correction request / proposal builder / snapshot / fingerprint / STALE / one-active index | REUSE_AS_IS |
| D10 payment block, `invoice_versions` binding | REUSE_AS_IS |
| Frozen v0/vN document versions | REUSE_AS_IS |
| `approve()` | HARDEN_REQUIRED → approve-and-apply atomically (D1a) |
| `apply()` | HARDEN_REQUIRED → recovery path only; refuses the maker |
| Feature flag reach | HARDEN_REQUIRED → `RmeInvoiceCorrectionPilotScope` + `config/rme_invoice_correction.php` |
| RoleSeeder | grant per D1/D1b |
| Money display | HARDEN_REQUIRED → never round/truncate stored cents |
| Correction qty bound | HARDEN_REQUIRED → equals the invoice bound |
| Price provenance on CHANGE | HARDEN_REQUIRED → only a changed PRICE is re-labelled |
| Post-payment correction, refund, reversal, credit, Rp0 adjustment | OUT_OF_SCOPE |
| Discount authority (D21c), free-treatment catalog (D21g) | POLICY_BLOCKED |

## 4. Correction lifecycle after D1a

```
(none) ─submit─▶ SUBMITTED ──approve (checker ≠ maker)──▶ [APPROVED → APPLIED in ONE transaction]
                   │  │  └─reject─▶ REJECTED
                   │  └─cancel (maker)─▶ CANCELLED
                   └─invoice changed (payment, content, version)─▶ STALE (at approval)

Legacy only: APPROVED (pre-D1a row) ──apply (≠ maker, APPLY permission)──▶ APPLIED
```

Payment: blocked while SUBMITTED/APPROVED (D10); after APPLIED it is payable only
against the new `correction_version` (rule 191).

## 5. Permission matrix (rule 195, `RoleSeeder`)

| Role | request | approve (= apply, D1a) | apply (recovery) |
|---|---|---|---|
| Kasir | ✔ | — | — |
| Admin Klinik | — | ✔ | — |
| Front Office | ✔ | ✔ | — |
| Super Admin | ✔ (wildcard) | ✔ | ✔ |
| Supervisor RME, Owner, Doctor, Perawat, Finance Klinik, Data Analyst, Finance, Admin Lab, … | — | — | — |

Plus, in the service, per account: requester ≠ approver, requester ≠ applier,
working-branch scope (`RmeWorkingBranchScope`), pilot allowlist.

## 6. Pilot allowlist

`config/rme_invoice_correction.php` → `pilot.branch_codes = ['SPN4']`, no `env()`.
Gated: submit, approve, recovery apply (`BRANCH_NOT_IN_PILOT`, audited after
rollback). Not gated: reject, cancel. HTTP create/store 404 outside the pilot.

## 7. Tests (local)

| Suite | Result |
|---|---|
| `tests/Feature/RmeInvoiceCorrection` (SQLite) | 182 passed, 8 skipped (PostgreSQL-only) |
| `RmeInvoiceCorrectionConcurrencyTest` on **PostgreSQL 16.15** | 10 passed (66 assertions) |
| RmeManualPricing + RmeTariffAuthority + Front Office role/migration/legacy + new suite | 243 passed, 2 skipped |
| RmeManualPricing + RmeTariffAuthority + ClinicOversight + AccessControl + Supervisor pin + invoice redesign + new suite (before pin revisions) | 465 passed, 10 failed — all contract pins of the old posture, revised |

Tests REVISED (never deleted), each with a `REVISED by rule 195` note: 18 in the
correction directory (APPROVED-unapplied state now built as the pre-D1a legacy
shape through `ricLegacyApproved()`; the D1 grant posture inverted), 4 Front Office
permission-count pins (Admin Klinik 24→25, Kasir 6→7, union 25→27), 5 governance
/ manual-pricing pins (D1a decided; grants per rule 195).

New: `RmeInvoiceCorrectionPilotActivationTest` (declared in
`config/ci_runner.php` `critical_gate_mandatory_suites`) and two PostgreSQL races
(simultaneous approvals serialize to ONE application; atomic commit).

_Sections 8+ (mutation, security review, CI, deploy, production verification,
activation, GO decision) are filled in by the evidence commit._
