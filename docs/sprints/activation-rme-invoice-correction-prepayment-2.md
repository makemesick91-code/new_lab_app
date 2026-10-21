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

## 8. Mutation testing

The harness reverts each mutant by file COPY, checks the file actually changed
before banking a result, and verifies byte-identical restoration by SHA-256.

**Pass 1 — 20 mutants: 19 KILLED, 1 SURVIVED.** The killed mutants cover:

- the pilot gate removed from submit, approve and recovery apply;
- the allowlist admitting everything, not canonicalizing, or prefix-matching;
- approval no longer applying (D1a removed);
- the maker allowed to approve or to apply;
- provenance always kept or never kept;
- money rounding or truncating again;
- the correction quantity cap reverting to 10 000;
- the create form ignoring the pilot;
- readiness not checking distinct accounts, or ignoring role drift;
- reject gated by the pilot (inverse mutant);
- an apply refusal that does not roll back the approval.

The survivor was **M19 — cashier panel ignores the pilot**. The panel's pilot
gate was untested; a test was added (`46c3ad99`).

**Pass 2 — 7 mutants, all KILLED:**

| Mutant | Description |
|---|---|
| N01 | Alias-coded live branch admitted (L1 reverted) |
| N02 | Treatment change keeps provenance (L2 reverted) |
| N03 | Approve also requires the APPLY permission |
| N04 | Approval audit lacks `application_mode` |
| N05 | Readiness probe commits instead of rolling back |
| N06 | Empty allowlist admits all |
| N07 | M19 re-run against the new test |

**Real survivors: 0.**

## 9. Independent security review

No CRITICAL, HIGH or MEDIUM findings. Four LOW findings, all fixed in `b26777a8`:

| Finding | Fix |
|---|---|
| **L1** — a live branch carrying an alias code (`SUN4`) could ride the SPN4 approval. | Only the declared side is canonicalized now; the live branch must carry the canonical code exactly. |
| **L2** — a treatment change at the same price kept the old price provenance. | A treatment change now re-labels it `CORRECTION_MANUAL_ENTRY`. |
| **L3** — the correction-form preview and the oversight dashboard formatted money in floating point. | Both now compute in integer cents in the browser. |
| **L4** — the readiness probe run as root could leave a root-owned Spatie permission-cache file. | It refuses to run as root. |

One informational edge is documented in rule 195 §17. A pre-D1a APPROVED row
outside the pilot can be neither applied nor rejected; only its maker can cancel
it. Production holds 0 corrections, and the probe reports any such row as
`ACTIVE_CORRECTION_OUTSIDE_PILOT`.

## 10. Production finding during activation — a false FAIL in the probe

First release `ac58f02d` was deployed and activated in this order:

1. Deploy (flag OFF).
2. RoleSeeder grant. Production gained exactly the 6 measured pairs (508 → 514),
   none were removed, and `Tester RME` was untouched.
3. Flag ON through the environment and the config cache, as `daengtisiams`.

The readiness probe then reported `STATE=PILOT_ACTIVE` with every role grant
correct. It still returned **FAIL**, with `PAIR_MAKER_CANNOT_REQUEST_IN_PILOT 29`.

The cause was not authorization. User 29 (Front Office, pinned `29:SPN4`) holds the
request permission, but was last online 2026-10-17. It has no daily branch context
for 2026-10-21 and no session. A context-bound account's live scope is empty off
shift (fail-closed, correct). The probe read that live scope as "not authorized",
so it reported a false FAIL that named a defect where there was only an operator
off shift.

The fix, in a follow-up release, is in `RmeInvoiceCorrectionReadinessService`:

- **Validity comes from eligibility.** Validity is decided by the pilot branches
  the account could act in once on shift. Eligibility is resolved through the
  login-path authorities, never guessed:
  - a pinned account → exactly its pinned branch;
  - an undecidable pin → nothing;
  - an unpinned context-bound account → any active RME pilot branch;
  - a governance account → its live scope.
- **Off shift is reported, not failed.** Being on shift now is reported
  separately, as a WATCH (`PAIR_MAKER_NOT_ON_SHIFT_IN_PILOT`). A GO requires
  `live_now`: both accounts on shift in a shared pilot branch.
- **Tests.** Seven new tests, written first and seen red. The fixture's own
  `userInRole()` call had been counted inside the read-only window; it was
  corrected so the read-only measurement covers only the probe.

Rule 195 §28b records the rule.

The flag was left ON throughout. Production holds 0 positive UNPAID invoices in
the pilot, so nothing is correctable. The capability's own gates (allowlist,
maker ≠ checker, eligibility) were never in question.

_Sections 11+ (second release, production verification, GO decision) are filled in
by the evidence commit._
