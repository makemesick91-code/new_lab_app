# Owner risk acceptance — Doctor trusted-device estate (pre-live)

Recorded under `REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1`, Phase 0 decision **D2**.
Measured against production HEAD `56a02aede67ff9cc214620e6d21408c43c640905`
(GO tag `doctor-access-global-device-enforcement-readiness-1-go`).

**THIS DOCUMENT CHANGES NO MEASUREMENT AND AUTHORIZES NO ACTIVATION.**

---

## 1. Owner declaration (verbatim)

> OWNER RISK ACCEPTANCE — PRE-LIVE DEVICE ESTATE
>
> I accept the currently measured lack of spare trusted Doctor devices
> for the PRE-LIVE / DEVELOPMENT phase of DaengtisiaMS.
>
> I understand that:
>
> - spare_device_available_per_branch remains MEASURED=FAIL;
> - Level 3 / one-device-loss resilience remains FAIL;
> - this acceptance does NOT convert the measurement to PASS;
> - this acceptance does NOT mean the prerequisite is technically satisfied;
> - a failure of the sole eligible tablet at a staffed branch may temporarily
>   prevent Doctor access at that branch;
> - this risk acceptance applies only while DaengtisiaMS remains pre-live;
> - spare-device capacity must be re-evaluated as part of go-live readiness.

```
OWNER_RISK_ACCEPTANCE    = YES
SCOPE                    = PRE_LIVE_DEVELOPMENT
MEASURED_STATUS          = FAIL
ATTESTED_RISK_ACCEPTANCE = YES
CONTRADICTION            = NO
```

## 2. The measurement this accepts (unchanged)

From `doctor:half-b-readiness` and `doctor:fleet-readiness` on production:

```
spare_device_available_per_branch = FAIL      (blocks activation: YES)
  "Measured over branches that home doctors: a staffed branch holds no spare."

ELIGIBLE_TRUSTED_DEVICES = 4        DEVICE_ESTATE_TOTAL = 6
Devices per branch:  TLK1=1   LDK2=1   ATG3=1   SPN4=1
HOME_BRANCH_MATRIX:  SPN4=10  LDK2=3   TLK1=2
Doctors reachable only through SPN4's single tablet: 13
FLEET_READINESS = PARTIAL           AUTHORIZES_ACTIVATION = false
```

Worst case accepted: loss of the single eligible tablet at SPN4 removes the
device path for **10 home-branch doctors** (13 by hardware reachability).

## 3. Why `CONTRADICTION=NO` is correct

The acceptance and the measurement address **different propositions**:

| Proposition | Status |
|---|---|
| "A spare trusted device is available at each staffed branch." | **FALSE** — measured FAIL, unchanged |
| "The owner accepts operating without one while pre-live." | **TRUE** — attested here |

No signature is recorded against the measurement, so nothing contradicts it.

## 4. WHERE THIS IS NOT WRITTEN, AND WHY

**This acceptance MUST NOT be written into
`config/doctor_global_enforcement_readiness.php` →
`global_prerequisites_attested['spare_device_available_per_branch']`.**

That field answers *"did somebody sign that a spare exists?"*. The contradiction
engine is:

```php
// App\Modules\DoctorAccess\Support\DoctorGlobalEnforcementPrerequisite
public static function contradicts(bool $attested, string $measured): bool
{
    return $attested && $measured !== self::PASS;
}
```

So `attested=true` with `measured=FAIL` yields `contradiction = true` and raises
`FINDING_CONTRADICTION`. Recording the acceptance there would therefore produce
`CONTRADICTION=YES`, which is the opposite of what the owner declared.

It is also the precise defect `DoctorGlobalEnforcementReadinessService` was
built to close. Its own docblock:

> `attested true + measured false  ===>  PASS` — *"the one artifact meant to
> stop a premature fleet-wide lockout can be satisfied by typing `true` five
> times."*

Writing this acceptance into that array would be that act, on that prerequisite.
**It was not done.** No config file was modified.

## 5. Machine-readable acceptance — deferred to Stage 1

Nothing in `app/` or `config/` currently models a risk acceptance distinct from
an attestation. Every prior `ACCEPTED_RISK` in this repository is prose in
`docs/sprints/*.md`, so this document follows that precedent and is **not**
read by any gate today.

If a machine-readable form is wanted, Stage 1 must add it as a **separate
field with separate semantics**, subject to all of:

1. it is **never** an input to `Prerequisite::contradicts()`;
2. it **never** changes a `Measured` value;
3. `spare_device_available_per_branch` keeps reporting `FAIL` and keeps
   `blocks_activation = YES`;
4. it is surfaced as its own column (e.g. `risk_accepted`) so a reader can see
   FAIL and the acceptance at the same time, never one instead of the other;
5. it carries `SCOPE=PRE_LIVE_DEVELOPMENT` and is **ignored** outside that scope;
6. it authorizes no flag, no phase move and no cohort change on its own.

Until that exists, the estate gate is reported as **FAIL with a recorded
acceptance beside it** — never PASS, GO, satisfied, or `NOT_APPLICABLE`.

## 6. Scope limits

- Applies **only** while DaengtisiaMS is pre-live. It expires at go-live and
  must be re-evaluated as a go-live readiness item.
- Covers **only** `spare_device_available_per_branch` / Level 3 one-device-loss
  resilience. It does **not** cover `device_loss_runbook_rehearsed`, which is
  separately **UNVERIFIED** (no evidence at
  `readiness/device-loss-drills/latest.json`) and separately blocks activation.
- Does **not** authorize widening enforcement scope. Stage 4 additionally
  requires Stage 1 (device identity predicate), Stage 2 (break-glass access,
  decision D1) and Stage 3 (controlled acceptance on real tablets).
- `GLOBAL_SCOPE_PERMITTED=false` and `PILOT_COHORT_MAXIMUM=5` remain hard
  config bounds. This acceptance does not move them.

## 7. Operator note

`APP_ENV=pilot` on production, and the deployment carries real clinical data.
The accepted failure mode — "may temporarily prevent Doctor access at that
branch" — is therefore not hypothetical: under PWA-only with no password
fallback, a tablet failure at SPN4 blocks real clinicians from real patients
until hardware is restored or break-glass access (D1) is used.

This is recorded so the risk is visible to whoever operates the branch, not to
reopen a decision the owner has made.

## 8. Status

```
D2                        = ACCEPTED (owner, pre-live scope)
MEASURED_STATUS           = FAIL (unchanged)
CONFIG_MODIFIED           = NONE
ATTESTATION_ARRAY_TOUCHED = NO
GATE_FLIPPED              = NO
BLOCKS_STAGE_4            = still YES via device_loss_runbook_rehearsed + Stages 1-3
```
