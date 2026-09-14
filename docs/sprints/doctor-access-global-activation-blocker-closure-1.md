# DOCTOR-ACCESS-GLOBAL-ACTIVATION-BLOCKER-CLOSURE-1

**Parent:** DOCTOR-ACCESS-GLOBAL-ACTIVATION-1 (Phase 0 complete, activation DEFERRED)
**Type:** SECURITY_FIX · **Module:** DoctorAccess
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`

**This sprint performed no activation.** `doctor.single_active_session` stays false,
`global_permitted` stays a source-controlled false, the browser enforcement cohort stays
as it was, and no lease, authorization, credential, home lock or cover was touched.

---

## Why the diagnosis came first

Three of the six blockers were stated wrongly in the brief, and one of them — B5 — asked
for an observability layer against a defect that does not exist. Every blocker was
therefore re-derived from deployed source before any code was written.

| Blocker | Stated | Established | Outcome |
|---|---|---|---|
| B1 | rollback evidence unproven | true; `global_prerequisites` read by **no** code | new suite that performs each rollback |
| B2 | 3 contradicting sites | **7 sites**; `postureCheck()` was not one of the real ones | phase declaration + `NOT_APPLICABLE` |
| B3 | checklist demands an impossible `authorizes_activation=true` | it demands no such thing — it reads the **recorded** line, not the **measured** one | one checklist step |
| B4 | §1.3/§2.2 unexecutable at UNSET=0 | true, plus a third sentence the brief missed | three runbook edits |
| B5 | readiness blind to WebAuthn | **already closed**; zero readers of the column blamed | regression pins, no runtime code |
| B6 | residual `GLOBAL_READY` | true — 2 leftovers, 2 rename-records to preserve | two prose lines |

---

## B2 — the gate that reddened on its own success

Seven sites assert fleet-wide enforcement is neither permitted, declared nor live. All are
correct for Phase 4A; all would FAIL on the state a Phase 5 exists to reach.

`android_release.enforcement.governance_phase` now declares which phase is being audited —
in the file that reads no environment, because a declaration a host could edit would not
audit the host values, it would just be a second copy of them agreeing with itself. Inside
`phase_4a` behaviour is byte-identical; the 106 existing scanner tests never moved.

Three properties the implementation holds:

1. **A skipped check is never a PASS.** `NOT_APPLICABLE` is a fourth status, counted
   separately, excluded from `passed`, still visible in the report.
2. **The halves that never stop mattering keep evaluating.** Armed-over-nobody FAILs in
   every phase; `global_enforcement_not_active` inverts at `global_activated` so a declared
   activation that failed to take is surfaced; the posture strength ceiling is untouched;
   a preflight's own containment is permanent.
3. **The prohibition is replaced by a precondition.** `global_prerequisites` — five strings
   no code read since Phase 3.5 — now gates on recorded attestations that **ship all false**.

An unrecognised phase resolves to `phase_4a`: a typo tightens the audit.

### Why the attestations ship false

An attestation is a signature, not a measurement. At least two are knowably untrue today:
no branch holds a spare tablet, and one RME branch holds none at all. Recording them now
would be recording something false — which is the failure mode the whole gate exists to
prevent.

---

## B5 — closed by design, pinned rather than rebuilt

`DoctorFleetReadinessRepository` reads the audit trail over both success actions. The
column the diagnosis blamed, `last_authorized_login_at`, has exactly one writer (Android
ticket redemption) and **zero readers**. Production reconciles exactly: the three doctors
with browser logins show combined Android+WebAuthn counts matching what the command printed.

The original diagnosis read a docblock that *rejects* that column as though it endorsed it.

What was genuinely missing was the pinning. Now: the whitelist is exactly two actions; a
doctor who has only ever used the browser reaches ready; the two paths stay distinguishable
rather than collapsing to a boolean; and the column can neither manufacture nor destroy a
proof. `DOCTOR_APP_LOGIN_DEVICE_PROOF_REJECTED` joins the exclusion dataset.

---

## B1 — rollback performed, not described

Rollback lived in two runbooks and no assertion. The new suite performs it: browser login
restored when the flag is disarmed; every device, authorization, credential, lease, lock,
cover and audit row intact across an arm/disarm cycle; branch lock dropping to ineffective
the moment single-session goes; and the config-cache roundtrip in both directions.

That last one matters most operationally: **production runs cached configuration and Laravel
skips the environment file entirely when cached**, so an activation *or* rollback that edits
the environment and stops there changes nothing.

The config-cache harness was **moved** to `tests/Pest.php`, not copied. Two harnesses can
disagree, and the one that disagrees quietly is the one a rollback would be trusted to.

---

## B3, B4, B6

- **B3** — the checklist step now reads the measured `GLOBAL_ENFORCEMENT_ACTIVE_LIVE` and
  says why not to read the recorded line beside it. `authorizes_activation` stays hardcoded
  false: the readiness engine does not authorise activation, and owner authorisation is
  external governance.
- **B4** — the branch-lock runbook now separates provisioning from verification. Its old
  precondition ("confirm every doctor is UNSET") is unexecutable at 0 unset and is replaced
  by a provenance check that works at any count. Its most dangerous sentence — "arming is
  inert and therefore NOT the risky step" — is now false twice over and is corrected:
  `branch_lock` narrows every locked doctor once the fleet is provisioned, and
  `single_active_session` was never lock-scoped at all.
- **B6** — two leftover `GLOBAL_READY` strings renamed; the two lines that *record* the
  rename preserved, along with all quoted historical production output.

---

## Parent programme state after this sprint

```
ACTIVATION_BLOCKER_CLOSURE_GO = YES
ESTATE_RESILIENCE_GO          = NO  (pending DOCTOR-ACCESS-TRUSTED-DEVICE-ESTATE-RESILIENCE-1)
GLOBAL_ACTIVATION_APPLY_AUTHORIZED = NO
SINGLE_SESSION_ACTIVATED      = NO
GLOBAL_ENFORCEMENT_ACTIVATED  = NO
```

Closing B1–B6 authorises nothing. The owner's decision stands:
**PROVISION_ESTATE_SPARE_CAPACITY_FIRST.**

Durable rules: `.cursor/rules/157-doctor-access-governance-phase.mdc`.
