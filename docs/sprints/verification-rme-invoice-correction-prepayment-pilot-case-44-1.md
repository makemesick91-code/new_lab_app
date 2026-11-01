# VERIFICATION-RME-INVOICE-CORRECTION-PREPAYMENT-PILOT — case #44 (observed decision, 2026-11-01)

**Program:** DAENGTISIAMS-RME-BILLING-CORRECTION · Sprint 2/7 (pre-payment correction pilot, SPN4).
**Rule:** `.cursor/rules/196-rme-invoice-correction-prepayment-pilot-verification.mdc` §11 (items 53–62).
**Owner decisions:** S2-V1, S2-V2 (2026-11-01) in `docs/decisions/rme-billing-correction-owner-decisions.md`.
**Run type:** re-verification of the SAME pilot (rule 196 §18). No runtime change, no deploy, no tag.

## 1. Outcome

```
PILOT_READY                   = VERIFIED
PILOT_ACTIVE                  = VERIFIED
PILOT_LIVE                    = NOT_REACHED
AUTHENTICATED_VERIFIED        = NO   (checker observed; maker never observed)
FINANCIAL_CORRECTION_VERIFIED = NO   (apply step observed and reconciled PASS; the case is not eligible)
FULL_ACTIVATION_GO            = NO
SPRINT2                       = NO_GO_OPERATIONAL_BLOCKED
CASE_44                       = OBSERVED_DECISION_UNOBSERVED_SUBMIT · NOT_ELIGIBLE_FOR_CLOSURE (rule 196 §8)
```

The full-closure tag `verification-rme-invoice-correction-prepayment-pilot-1-go` is **not** created.

## 2. Authority

| Fact | Value |
|---|---|
| Canonical base (remote, after fetch) | `89906fd5` |
| Production HEAD / tree during the whole case decision | `ba33f9bb` / `2d1b684b` (no deploy between submit and decision) |
| Probe (`rme:invoice-correction-readiness --maker=29 --checker=1`, as the runtime user) | `STATE=PILOT_ACTIVE`, admitted `SPN4`, `PILOT_IDENTITIES valid=yes`, `VALID=yes`, `LIVE_NOW=no`, `ACTIVE_CORRECTIONS SPN4=1` before the decision, Decision `WATCH` at every reading (07:29, 07:37, 07:38, 07:39, 07:41 UTC) |
| Dispute layer (`rme:billing-dispute-status`) | `VERDICT=GO ACTIVE_TOTAL=0` |
| `laravel.log` | 1 443 432 bytes / 164 ERROR before and after the run (delta 0, including the read-only integrity script) |

## 3. The case (server-written times; WITA = UTC+8)

| UTC | WITA | Event |
|---|---|---|
| 01:24:25 | 09:24:25 | Visit 65 created (SPN4, visit type `new`, doctor 27, room 17) |
| 01:57:34 → 01:57:39 | 09:57:34 → 09:57:39 | Medical record 53 created → final (5 s) |
| 01:58:35 | 09:58:35 | Invoice #44 `RME-202611-000001` created by account 29: one line, treatment 7 "Gigi Palsu Full", Rp6.500.000 |
| 01:59:19 | 09:59:19 | Correction `RMECOR-202611-000003` SUBMITTED by account 29, 44 s after the invoice: `CHANGE` line 52, Rp6.500.000 → Rp7.000.000 (UPWARD, +Rp500.000), reason "salah ketik", `owner_approval_reference` NULL |
| 01:59:19 → 07:41:11 | 09:59:19 → 15:41:11 | Invoice held by D10 (active correction) for **5 h 41 min 52 s**; 0 payment attempts refused (`RME_PAYMENT_REFUSED_ACTIVE_CORRECTION` = 0) |
| 07:29:16 | 15:29:16 | This run's first read-only measurement found the active correction |
| 07:31:52 | 15:31:52 | Fresh baseline #1 (sha256 `684350b6…`) |
| 07:38:43 → 07:39:02 | 15:38:43 → 15:39:02 | Account 1 loads `/login`, `POST /login` → 302 → `/dashboard` 200 (its previous activity: 12:45:32 WITA) |
| 07:39:26 | 15:39:26 | Final pre-decision capture (sha256 `a0c7afcd…`); nothing changed since baseline #1 |
| 07:39:34 – 07:40:25 | 15:39:34 – 15:40:25 | Account 1 views the correction three times (`OVERSIGHT_BILLING_CORRECTION_VIEWED` × 3) |
| 07:41:11 | 15:41:11 | `SUBMITTED → APPROVED → APPLIED` by account 1, one transaction (D1a): v0 frozen, v1 issued |
| 07:41:23 | 15:41:23 | Post-decision capture (sha256 `7777b6a0…`) |

The capture files hold invoice and audit identifiers only and are kept outside the repository; their hashes above
identify them.

## 4. Financial reconciliation (rule 196 §11) — PASS for the decision step

From the fresh pre-decision baseline (07:39:26 UTC) to the post-decision capture (07:41:23 UTC):

| Measure | Before | After | Delta | Expected |
|---|---|---|---|---|
| Correction #3 status | SUBMITTED | APPLIED (decided and applied by 1, `applied_invoice_version` 1) | — | APPLIED, maker 29 ≠ checker 1 |
| Invoice #44 grand total / version | 6 500 000 / 0 | 7 000 000 / 1 | +500 000 / +1 | = proposed / +1 |
| Invoice #44 status / paid | UNPAID / 0 | UNPAID / 0 | — | unchanged (pre-payment) |
| Invoice #44 items fingerprint | `e41157a6…` | `4e395482…` | line 52 unit price 7 000 000 | = proposed line |
| Invoices (count / sum) | 10 / 31 048 000 | 10 / 31 548 000 | 0 / +500 000 | only #44 |
| Items (count / sum) | 11 / 31 048 000 | 11 / 31 548 000 | 0 / +500 000 | only #44 |
| Payments (count / sum) | 10 / 22 548 000 | 10 / 22 548 000 | 0 | 0 |
| Corrections / lines | 3 / 3 | 3 / 3 | 0 | 0 (submit already counted) |
| Correction events | 7 | 9 | +2 | APPROVED, APPLIED |
| Document versions | 4 | 6 | +2 | v0 `ORIGINAL` (id 5), v1 `REVISION` (id 6, source correction 3) |
| Audit rows | 1 640 (max 1 662) | 1 647 (max 1 669) | +7 | 4 correction rows (APPROVED, 2 × DOCUMENT_VERSION_ISSUED, APPLIED) + 3 read rows (`OVERSIGHT_BILLING_CORRECTION_VIEWED`) |
| Correction #3 own audit rows (whole life) | 1 | 5 | — | 5 (rule 202 §4) |
| Role–permission pairs | 524 | 524 | 0 | 0 |
| Active corrections | 1 | 0 | −1 | 0 |

- Other invoices, items and payments with `updated_at` / `created_at` after 07:39 UTC: **0 / 0 / 0**.
- `RmeInvoiceDocumentVersion::integrityHolds()`, run read-only (transaction `READ ONLY`, rolled back) as the
  runtime user: **v0 HOLDS** (`grand_total_cents` 650 000 000), **v1 HOLDS** (700 000 000); log delta 0.
- D10 by state (rule 196 §12): the correction was `SUBMITTED` with the invoice `UNPAID` at every reading before
  the decision — the payment was blocked by state. The payment page itself was not observed by IT.

## 5. Why case #44 cannot close the pilot

Each item stands on its own; none is a finding about any person.

1. **The payment was held to keep the case alive (rule 196 §8, §21).** The Owner stated in session that a case
   approval existed before the submit and that IT had been instructed to approve, but that the approval was held
   "to be used to close Sprint 2". D10 blocked payment of the patient's invoice for 5 h 41 min 52 s. Delaying a
   patient's payment to keep an invoice in the pilot window is a forbidden way of producing a case, and Owner
   directive item 16 says the same. It disqualifies the case regardless of how well its decision is observed.
2. **The submit was not observed.** No verifier, no probe and no fresh baseline existed before 09:59:19 WITA;
   `PILOT_LIVE` and the maker's `AUTHENTICATED_VERIFIED` cannot be produced afterwards (§31, §41). The run began
   5 h 30 min after the submit.
3. **`PILOT_LIVE` was never reached.** Every probe reading shows `LIVE_NOW=no` (account 29 holds no working
   context in SPN4); the decision was taken by the checker alone.
4. **No Owner reference exists in any field.** `owner_approval_reference` is NULL, the reason is "salah ketik", and
   the checker's approval note is the 19-character text "Catatan Persetujuan" — the field's own label, not a
   reference. The Owner's in-session statement is recorded as `OWNER_RETROSPECTIVE_ATTESTATION` (§49) →
   `OWNER_CASE_APPROVAL = OWNER_ATTESTED_PENDING_VERIFICATION` until the original record from the sanctioned
   channel, with a channel time before 09:59:19 WITA, is examined (§50). Even then it closes only the approval gap.
5. **Warning signs (§45), recorded for the Owner, never adjudicated:** invoice → submit in 44 s; medical record
   created → final in 5 s; an UPWARD price change explained as "salah ketik"; the deliberate hold; one distinct
   network address across all sessions of accounts 1 and 29 (count only).

## 6. What WAS observed (and is kept as evidence, not as a rung)

- The checker's own login (server-side `POST /login`, then three views of the correction, then the decision), with
  the Owner stating that they witnessed it (decision S2-V1). Human identity remains `OWNER_ATTESTED`, not
  system-verifiable.
- The verifier protocol worked only after a correction: the first "ready" reply arrived while the server held **no**
  authenticated session (account 1's last activity was 12:45:32 WITA, past the session lifetime). The verifier asked
  again and gave the go-ahead only once the login appeared server-side. Rule 196 §57 makes this mandatory.
- A fresh baseline immediately before the decision, the atomic `SUBMITTED → APPROVED → APPLIED` transition, and a
  reconciliation with no unexplained delta.

## 7. Deploy coordination (decision S2-V2)

Production ran `ba33f9bb` throughout the case. Track A (`HARDENING-RME-RP0-CLINICAL-BILLING-CONSISTENCY-1`, PR #578)
was not deployed while correction #3 was active; with the correction terminal (`active_corrections = 0`) the window
is free. A deploy is recorded with the runtime SHA before and after so a later case is attributed to its runtime
(§35).

## 8. Next real case — what closure needs (unchanged standard, §21 and §48)

1. The Owner authorizes THAT invoice in writing, in a sanctioned channel, **before** the submit, with a reference.
2. The verifier (S2-V1: the Owner witnessing, IT capturing) is engaged **before** the submit.
3. Accounts 29 and 1 log in themselves; the verifier confirms both logins server-side; the probe reads
   `LIVE_NOW=yes`, Decision GO.
4. Fresh baseline → submit with the reference in `owner_approval_reference` → D10 observed by state → the checker
   decides **promptly** with the same reference in the approval note → reconcile.
5. The patient is never held. If the Owner cannot authorize while the patient is present, the case is lost to the
   pilot (§21).

## 9. Open items

- `OWNER_CASE_APPROVAL` for #44: the original pre-submit record (text, with channel time) — Owner to supply if they
  want the attestation verified (§50). It cannot change the outcome above.
- Recommended Owner decision (not taken here): require `owner_approval_reference` at submit for pilot pre-payment
  corrections, so the §32 pre-existence becomes system-verifiable instead of depending on a later attestation.
- Whether account 1 (IT) keeps the `Owner` role remains the Owner's least-privilege decision (§52).
- Invoice #44 is `UNPAID` at Rp7.000.000 and payable by the ordinary cashier flow; its payment is ordinary
  operations, not part of this pilot.
