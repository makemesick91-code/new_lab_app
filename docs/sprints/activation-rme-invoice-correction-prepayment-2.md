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

## 11. CI, merge, deploy

| Release | PR | Candidate | Merge (squash) | Tree | Critical Gate (exact SHA) | Deploy |
|---|---|---|---|---|---|---|
| 1 | #497 | `73c79e04` | `ac58f02d` | `af463acc` = candidate | run 39518170813: 6064 passed / 32104 assertions | `DEPLOY OK 20261021-013007` |
| 2 | #498 | `b72543f6` | `61a29795` | `148f6e1f` = candidate | run 39531262595: 6072 passed / 32137 assertions | `DEPLOY OK 20261021-043434` |

**Gates.** Every required gate passed on both candidates: Classifier, Quality,
Critical, Selective Module (release 1), NSF-9, NSF-10 and the Android gate. Two
gates were skipped:

- the self-hosted Critical variant (exactly one variant runs);
- the **Full Suite**, because the global temporary policy is ACTIVE. That skip is
  not a pass.

The PostgreSQL 16 concurrency suite ran in CI: 10 tests, including the two new races.

**Deploys.** Both ran ON the VPS through `deploy-vps-runner.sh start`, detached,
and both reported:

- `DEPLOY_HEAD_TARGET_MATCH=YES`;
- runtime isolation 70 GO / 0 FAIL;
- no migration;
- backups `pre_auto_deploy_20261021-013007.sql` and `…043434.sql` (0640).

The NSF-9 smoke warning on `http://127.0.0.1/login` (404) is the known co-tenant
nginx shadow; the canonical domain returns 200.

## 12. Activation ceremony (production)

| Step | Evidence |
|---|---|
| Delta measured before seeding | Production pairs were unchanged since the first capture (508). The seed-vs-production diff is exactly +6. The `Tester RME` pairs exist only in production; that role is not seeder-managed and stayed untouched. |
| `PermissionSeeder` → `RoleSeeder` → `permission:cache-reset` as `daengtisiams` | All rc=0. Pairs 508→514. The diff is exactly the 6 measured pairs, with 0 removed. |
| Flag ON | `FEATURE_RME_INVOICE_CORRECTION=true` was appended with `>>`. Inode 796156 and 640 `root:daengtisiams` were preserved. The runtime user can read the file. `config:clear` / `config:cache` ran as `daengtisiams`. Result: `enabled=true via=env captured=yes`. |
| Opcache | No override of the defaults (`validate_timestamps=1`, `revalidate_freq=2`), so FPM picks up the new `config.php` within seconds. |
| Probe (release 2) | `STATE=PILOT_ACTIVE`, `ADMITTED=SPN4`, grants as rule 195. User 29: request/approve yes, scope now `-`, eligible `SPN4`. User 1: SPN4. Pair `VALID=yes`, `LIVE_NOW=no`, `[WATCH] PAIR_MAKER_NOT_ON_SHIFT_IN_PILOT`. Decision **WATCH** (rc 0; `--strict` rc 1). |

## 13. Production verification

**Financial fingerprint, read-only, captured three times:** before the deploys,
after activation, and after release 2. All three captures are identical except
`role_permission_pairs` 508→514:

| Item | Value |
|---|---|
| Invoices | 7, Rp 7.548.000 (PAID 5 / PARTIAL 1 / UNPAID 1 — invoice 36, Rp 0) |
| Invoice items | 8 |
| Payments | 8, Rp 5.548.000 |
| Payment batches | 1 |
| Corrections / correction events / document versions | 0 / 0 / 0 |
| Outstanding | 2, Rp 2.000.000 |
| Audit rows | 1601 (max id 1623), 0 correction audit rows |
| User–role links | 36 |

**HTTP over `https://daengtisia.online`:**

- `/login` and `/health/{live,ready,lb}` return 200.
- `/rme/invoice-corrections`, `/approvals`, `/{correction}` and the create form
  all return 302 to `/login` for a guest.
- A POST to approve without a CSRF token returns 419.
- No 500 anywhere.

**Runtime:**

- `laravel.log` is byte-identical (1 437 201 bytes) across the deploys and the
  ceremony.
- `queue:failed` reports no failed jobs.
- `php8.3-fpm`, nginx and the queue worker are active.
- Environment `pilot`, debug OFF, maintenance OFF.

## 14. GO decision

| Gate | Result |
|---|---|
| Owner decisions D1a/D1b/D1c/D1d | RECORDED |
| D21c / D21g | OWNER_DECISION_PENDING (safe-to-defer, restricted) |
| TDD, mutation, security review | PASS (0 real survivors; 4 LOW fixed) |
| CI on exact candidates | PASS (Full Suite SKIPPED by policy) |
| Merge (tree identity), deploy (exit 0 + DEPLOY OK) | PASS ×2 |
| RoleSeeder grant, flag ON, probe | PASS; probe WATCH (maker off shift) |
| Financial reconciliation (no unintended change) | PASS |
| Authenticated pilot by users 29 and 1 | **NOT_PERFORMED** |
| Real authorized correction + reconciliation | **NOT_PERFORMED** — 0 positive UNPAID invoices in SPN4 |

**Scoped GO:** `activation-rme-invoice-correction-prepayment-2-pilot-readiness-go`
@ `61a29795`, an exact match at the VPS HEAD. The full tag
`activation-rme-invoice-correction-prepayment-2-go` was **not** created, and must
not be created until both NOT_PERFORMED rows above are performed and reconciled.

## 15. To close the full activation (operators, not automation)

1. Admin Sunu (user 29) logs in and selects SPN4. The pin allows nothing else.
2. `runuser -u daengtisiams -- php artisan rme:invoice-correction-readiness --maker=29 --checker=1`
   must show `LIVE_NOW=yes` and Decision GO.
3. When a real SPN4 invoice is UNPAID, positive, unpaid and needs a correction, the
   owner authorizes that specific case.
4. User 29 submits the correction. User 1 (never user 29) approves it, which
   applies it immediately.
5. Reconcile:
   - v0 is frozen and v1 matches the new items and total;
   - one correction, two events and two audit rows (`application_mode=AUTO_ON_APPROVAL`);
   - the payment form now binds to the new `correction_version`;
   - no other invoice changed.
6. Only then create the full tag on the deployed runtime SHA.

Rollback is unchanged (rule 195 §30): reject or cancel active corrections, set the
flag OFF and rebuild the config cache as `daengtisiams`. Applied corrections and
frozen document versions are never deleted.
