# Doctor real-device readiness campaign — the 12 remaining ceremonies

> ### CAMPAIGN COMPLETE — measured 2026-09-17
>
> **`REAL_DEVICE_READY = 15/15`.** All twelve remaining ceremonies were carried
> out on 2026-09-13/14; the last proof is dated 2026-09-14 07:56:51. Fifteen
> distinct users hold a proof across `DOCTOR_APP_LOGIN_AUTHORIZATION_SUCCESS` and
> `DOCTOR_DEVICE_WEBAUTHN_LOGIN_SUCCESS`.
>
> Two figures below are frozen at the date they were written and are no longer
> current: the estate now holds **4** eligible devices (`[3,5,6,7]`, not three)
> and the authorization target is **60** (not 45). Read the live numbers from
> `doctor:estate-resilience --json`; never quote a remembered one.
>
> The **procedure** below remains canonical for any future ceremony.

`DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1`. Owner-authorized 2026-09-13
(`AUTHORIZE_12_DOCTOR_ROTATIONS=YES`), **held for scheduling** at the time of
writing — no doctor had been enrolled then.

This authorization is for **bounded readiness rotations only**. It does not
authorize global enforcement, permanent cohort expansion, permanent
single-session activation, browser-policy weakening, or skipping rollback.

---

## 0. Why this cannot be done from a terminal

A readiness proof requires a clinician physically logging in on a trusted
tablet. That is the only part that cannot be automated, and it must never be
fabricated.

> ### CORRECTED 2026-09-13 — this section previously stated the opposite of the truth
>
> It read: *"A doctor outside the enforcement cohort produces no proof at all…
> So proof requires putting the doctor inside the cohort — which denies their
> browser login until they are taken out again."*
>
> **That was false, and Waves 1–3 were built on it.** The gate governs the
> BROWSER path only. The Android app posts directly to
> `/device-api/v1/doctor/challenge` and `/device-api/v1/doctor/login`, which
> never consult the enforcement scope — an eligible device plus an active
> `DoctorDeviceAuthorization` is sufficient.
>
> Proven on production: drg Ramadhan (user 12) produced audit 828
> `DOCTOR_APP_LOGIN_AUTHORIZATION_SUCCESS` on tablet 3 at 10:57:08 while the
> cohort was `[9,15,18]` and he was not in it.
>
> **Cost of the error:** five doctors were enrolled across Waves 1–3 for no
> benefit, each carrying a browser lockout window. Their successful proofs stay
> valid — the defect was the protocol, not the evidence.
>
> `TEMPORARY_COHORT_ENROLMENT_REQUIRED_FOR_TABLET_READINESS = NO`

---

## 1. State this campaign starts from

Verified on production `1172ba61`, 2026-09-13.

```
ELIGIBLE_DOCTORS  15      LOCKED 15 / UNSET 0
AUTHORIZATION     45 / 45 active · 0 missing · 0 duplicate
ELIGIBLE_TABLETS  3       id 3 SPN4 · id 5 LDK2 · id 6 ATG3
                          all active + cryptographically_verified,
                          1 usable credential each
REAL_DEVICE_READY 3 / 15  users 9 (Nisa), 15 (Fiitri), 18 (Karmila)
COHORT            [9,15,18]   size 3, hard cap 5
FLAGS  single_active_session=false · branch_lock=true
       trusted_device_enforcement=true · pwa_webauthn_device_login=true
GLOBAL_ENFORCEMENT_ACTIVE=false
```

**The twelve, all passing every machine-checkable precondition** (home branch
set, 3/3 authorizations to eligible tablets, 0 active visits, 0 open leases, 0
online, 0 occupying a clinical room):

| home | doctor | user | note |
|---|---|---|---|
| LDK2 | 15 drg Irwan | 28 | |
| LDK2 | 18 drg Ramadhan | 12 | |
| TLK1 | 16 drg Fahira | 14 | **[SUPERSEDED 2026-09-17]** True when written. TLK1 now holds eligible device **7** (`PILOT_TABLET_04_TLK1`), provisioned 2026-09-16 — a TLK1 login is no longer necessarily cross-branch. |
| SPN4 | 22 drg Windi | 19 | |
| SPN4 | 23 drg Nurmilah | 20 | |
| SPN4 | 24 drg Aisyah | 21 | |
| SPN4 | 25 drg Ilmiah | 22 | |
| SPN4 | 26 drg Ega | 23 | |
| SPN4 | 27 drg Syifa | 24 | |
| SPN4 | 28 drg Yudya | 25 | |
| SPN4 | 29 drg Syahrul | 26 | |
| SPN4 | 30 drg Wahyuni | 27 | |

**No credential enrolment is needed.** The credential belongs to the TABLET, not
the doctor — every eligible tablet already holds a usable one, and every doctor
is authorized on all three. What is missing is the login itself.

---

## 2. Wave sizing

- **Wave 1: exactly ONE doctor.**
- If Wave 1 succeeds fully, later waves may add **at most TWO** at a time.
- `TOTAL_ENFORCEMENT_COHORT` must never exceed **5**.
- Baseline `[9,15,18]` stays. One campaign doctor → size 4. Two → size 5, the cap.
- Campaign doctors are removed as soon as their proof is verified. **The cohort
  is never permanently expanded by this sprint.**

**Recommended Wave 1: drg Fahira (doctor 16 / user 14), HOME=TLK1.** There is no
tablet at TLK1, so whichever of the three she uses is necessarily cross-branch —
the single most valuable first ceremony, proving `effective branch == HOME` while
the device sits at a different branch.

---

## 3. Pre-ceremony hard check — run per doctor, immediately before enrolling

Machine side (all twelve passed on 2026-09-13, but **re-run it**: state moves):

```bash
ssh daengtisiams-vps 'cd /var/www/asia-dental-lab-v2 && \
  runuser -u daengtisiams -- php artisan doctor:fleet-readiness --json' \
  | python3 -c "import json,sys; r=json.load(sys.stdin); \
      row=[d for d in r['doctors'] if d['user_id']==USER_ID][0]; print(json.dumps(row, indent=2))"
```

Require, for that doctor: `home_branch_locked = true`,
`unauthorized_eligible_device_ids = []`, `real_device_login_proven = false`.

Human side — **not verifiable from a terminal, and the campaign stops here
without it**:

- the doctor is present and available for the ceremony;
- the doctor is not actively treating a patient;
- the doctor is not occupying a clinical room for active work;
- the operator and the chosen tablet are physically ready;
- the rollback below is prepared **before** the cohort is touched.

**If any condition fails: DO NOT ENROL THAT DOCTOR.**

---

## 4. Cohort change

Capture BEFORE (recorded 2026-09-13 as the campaign baseline):

```
COHORT_BEFORE        [9,15,18]
  env  ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_ID=18
       ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_IDS=9,15
FLAGS_BEFORE
       FEATURE_DOCTOR_TRUSTED_DEVICE_ENFORCEMENT=true
       FEATURE_DOCTOR_PWA_WEBAUTHN_DEVICE_LOGIN=true
       FEATURE_DOCTOR_SINGLE_ACTIVE_SESSION=false
       FEATURE_DOCTOR_BRANCH_LOCK=true
CONFIG_STATE_BEFORE   bootstrap/cache/config.php present (cached)
```

Enrol (example: user 14):

```bash
# on the VPS, in /var/www/asia-dental-lab-v2
#   singular stays 18; the plural carries the union
sed -i 's/^ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_IDS=.*/ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_IDS=9,15,14/' .env
runuser -u daengtisiams -- php artisan config:clear
runuser -u daengtisiams -- php artisan config:cache
```

Verify **effective** runtime immediately:

```bash
runuser -u daengtisiams -- php artisan android:phase4a-pilot-scope
```

Required:

```
DECLARED_PILOT_DOCTOR_USER_IDS = 9,15,18,14   (exact set, size 4)
GLOBAL_ENFORCEMENT_ACTIVE      = false
SCOPE_VERDICT                  = GO
```

and, unchanged: `doctor.single_active_session=false`, `doctor.branch_lock=true`.

**If the effective cohort differs from the requested exact set — ROLL BACK
IMMEDIATELY** (§7).

---

## 5. The ceremony

1. The doctor uses one of the three eligible tablets.
2. They perform the **canonical** production flow: password, then the device
   assertion the gate redirects them to.
3. **Never** create a credential by SQL or an administrative shortcut, and never
   insert a success row.
4. The proof must be tied to a **currently eligible** device. A success row
   naming a revoked, inactive, unverified or non-eligible tablet **does not
   count** — the readiness engine enforces this, and will keep reporting the
   doctor unproven.

Capture per doctor:

```
DOCTOR_ID= USER_ID= DOCTOR_NAME=
HOME_BRANCH=
DEVICE_ID= DEVICE_NAME= DEVICE_PHYSICAL_BRANCH=
DEVICE_ELIGIBLE_AT_PROOF_TIME=YES
AUTHORIZATION_ID= AUTHORIZATION_ACTIVE=YES
LOGIN_RESULT=PASS
PROOF_ROW_ID= PROOF_TIMESTAMP=
EFFECTIVE_BRANCH=
```

**CORRECTED 2026-09-13 BY WAVE 1 — `EFFECTIVE_BRANCH == HOME_BRANCH` IS NOT A
VALID LIVE GATE IN THIS SPRINT, AND THE ORIGINAL REQUIREMENT WAS CONTRADICTORY.**

`DoctorEffectiveBranchResolver::enabled()` returns true only when
`doctor.branch_lock` AND `doctor.single_active_session` are BOTH armed and the
session probe is observable. The two arm and disarm together on purpose — an
expired cover would otherwise keep granting authority with nothing left to
invalidate the session. Production runs `single_active_session=false`, which this
sprint is required to preserve, so **the branch-lock capability is disarmed at
runtime and nothing narrows a doctor's working branch.**

Wave 1 proved it on production rather than on paper: drg Fahira, home-locked to
TLK1, logged in on the SPN4 tablet and her working context became **SPN4**. Her
lock row was untouched (TLK1, `transfer_count` 0). Nothing failed — there was
simply no enforcement to observe.

So a campaign ceremony asserts, and records:

```
DEVICE_PHYSICAL_BRANCH   captured  (expected != HOME — cross-branch is the normal case)
HOME_LOCKED_BRANCH       captured  and must be UNCHANGED by the ceremony
EFFECTIVE_BRANCH         captured as OBSERVED, never asserted equal to HOME
```

**Cross-branch is expected, not a fault.** Never require
`DEVICE_PHYSICAL_BRANCH == HOME_BRANCH`; device branch is not doctor branch
authority. What the ceremony DOES assert is that the login did not rewrite the
home lock.

The live assertion `DEVICE_BRANCH != HOME AND EFFECTIVE_BRANCH == HOME` is
**deferred to `DOCTOR-ACCESS-GLOBAL-ACTIVATION-1`**, where
`single_active_session` is actually armed under an activation window — and that
sprint must handle the lease re-arm invariant first, including Karmila's
surviving lease 8 if it still exists.

Verify the proof landed and qualifies:

```bash
runuser -u daengtisiams -- php artisan doctor:fleet-readiness --json
# that doctor's row must show:
#   real_device_login_proven : true
#   qualifying_device_ids    : [<the tablet used>]
```

`qualifying_device_ids` is the field that matters. `real_device_login_count`
alone is not proof — it counts rows, including ones on hardware no longer
trusted.

---

## 6. Success path

1. Mark the doctor `REAL_DEVICE_READY=YES`.
2. Remove **only** that campaign doctor from the cohort; refresh config
   canonically; verify the effective set is back to baseline plus any other
   still-running campaign doctor.
3. Confirm the doctor returns to ordinary posture (browser login works again).
4. **Do not** delete the audit proof. **Do not** revoke their device
   authorization or the tablet credential merely because the ceremony ended.

---

## 7. Failure path — immediate rollback

If a ceremony fails for any campaign doctor:

- **STOP. Do not continue to the next doctor.**
- Remove that doctor from the cohort at once:

```bash
sed -i 's/^ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_IDS=.*/ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_IDS=9,15/' .env
runuser -u daengtisiams -- php artisan config:clear
runuser -u daengtisiams -- php artisan config:cache
runuser -u daengtisiams -- php artisan android:phase4a-pilot-scope   # expect 9,15,18
```

- Verify the doctor is no longer locked out.
- Capture `FAILURE_REASON`, `ROLLBACK_STARTED_AT`, `ROLLBACK_COMPLETED_AT`,
  `POST_ROLLBACK_ACCESS_STATE`.
- The doctor stays `REAL_DEVICE_READY=NO`. **Never manufacture a proof.**
  Diagnose the blocker before retrying.

**A failed ceremony must never be left overnight with the doctor trapped in
temporary enforcement scope.**

---

## 8. Wave gate — after every wave

```
EXPECTED_COHORT == EFFECTIVE_COHORT
NEW_READY_DOCTORS =
GLOBAL_ENFORCEMENT_ACTIVE = false
doctor.single_active_session = false
NO_UNEXPECTED_BRANCH_CHANGES = YES
NO_UNEXPECTED_AUTHORIZATION_CHANGES = YES
NO_UNEXPECTED_CREDENTIAL_REVOCATIONS = YES
PRODUCTION_HEALTH = PASS
```

Health is checked over the **canonical domain**, never the bare loopback — a
co-tenant app answers `http://127.0.0.1` on this shared VPS:

```bash
for p in /login /health/live /health/ready; do
  curl -sk -o /dev/null -w "$p -> %{http_code}\n" "https://daengtisia.online$p"; done
```

Only then proceed to the next wave.

---

## 9. Final physical gate

```
ELIGIBLE_DOCTORS 15 · REAL_DEVICE_READY 15 · NOT_READY 0
LOCKED 15 · UNSET 0
AUTHORIZATION 45/45 · MISSING 0 · DUPLICATE 0
ELIGIBLE_TRUSTED_DEVICES 3 · DEVICES_WITH_VALID_PROOF 3
UNRESOLVED_BLOCKERS 0
COHORT back to [9,15,18]
single_active_session=false · branch_lock=true · GLOBAL_ENFORCEMENT_ACTIVE=false
```

Any failure → `FLEET_READINESS = PARTIAL / NO-GO`.

---

## 10. After the twelve — still not a GO

Two closure obligations remain, and the readiness tag is **not** created until
both are satisfied:

1. **Graphify** — done 2026-09-13 against the deployed authority `1172ba61`:
   32909 nodes / 48953 edges / 3246 communities, covering Doctor, branch lock,
   effective-branch resolver, DoctorDevice, DoctorDeviceAuthorization, the
   device-login readiness engine, the governed assignment CLI and the
   rollout-readiness reporting. `GRAPHIFY_FINALIZED=YES`.
2. **Full Suite** — currently `FULL_SUITE_EXECUTED=NO`,
   `FULL_SUITE_RESULT=SKIPPED`, `CLAIMED_PASS=NO`, and that must stay stated
   honestly until it runs. Dispatch only **after** the physical gate is 15/15 and
   no source fix is required, on the final immutable **deployed** runtime tree —
   never on a docs-only commit:

```bash
gh workflow run foundation-evidence-gates.yml \
  --ref <side-ref-at-the-deployed-runtime> \
  -f run_full_suite=true -f full_suite_policy_override=true
```

Canary with `run_full_suite=false` first and confirm `full_suite_authorized=true`
in the classifier log before waiting hours. If any runtime source change is made
to fix a ceremony blocker, the old Full Suite authority is void: deploy the new
runtime and rerun against the new immutable tree.

Then final DEVFLOW, a fresh contradiction scan, and only then:

```
doctor-access-fleet-rollout-readiness-1-go
```

tagging the **verified deployed runtime**, never an evidence-only commit, and
recording `FLEET_READINESS_GO=YES` with `GLOBAL_ACTIVATION_AUTHORIZED=NO`.

**Stop at readiness GO.** `DOCTOR-ACCESS-GLOBAL-ACTIVATION-1` does not start
automatically.

---

## 11. WAVE 1 — drg Fahira, 2026-09-13. Executed, rolled back, protocol corrected

**`WAVE_1_ORIGINAL_PROTOCOL_RESULT = FAIL / ROLLED BACK`**
Reason: `CROSS_BRANCH_LIVE_ASSERTION_UNREACHABLE_WITH_SINGLE_SESSION_FALSE`.

This is recorded as a failure of the **protocol**, not of the doctor, the tablet
or the login — and it is not rewritten to look like a pass.

**The device-readiness evidence is valid and is preserved:**

```
FAHIRA_DEVICE_LOGIN_PROOF = PASS
  audit 819  DOCTOR_APP_LOGIN_AUTHORIZATION_SUCCESS
             performed_by 14 · doctor_id 16 · doctor_device_id 3
             2026-09-13 10:07:05 UTC = 18:07:05 WITA
  audit 818  DOCTOR_DEVICE_LOGIN_REQUESTED — the attempt, correctly NOT counted
  device 3   PHASE4A_PILOT_TABLET_02 (SPN4) — active, cryptographically_verified,
             not revoked: ELIGIBLE AT PROOF TIME
  authz 10   ACTIVE; last_authorized_login_at written 10:07

FAHIRA_REAL_DEVICE_READY = YES
REAL_DEVICE_READY = 4 / 15        (was 3)
```

**What was observed instead of the asserted invariant:** her working context
became **SPN4** at 10:08, one minute after the proof, while her HOME lock stayed
**TLK1** with `transfer_count` 0. The lock was not rewritten; it was simply inert.

**Cohort discipline held throughout:**

```
COHORT  [9,15,18] -> [9,14,15,18] -> [9,15,18]     BEFORE == AFTER
BROWSER_DENIED   3 -> 4 -> 3        BROWSER_ALLOWED 12 -> 11 -> 12
enrolled ~10:06 UTC · rolled back 10:09:04 UTC — about three minutes,
never left trapped, rollback run BEFORE any diagnosis
```

**Post-ceremony, by canonical logout only — no SQL, no forced state:**

```
FAHIRA_ONLINE = false · FAHIRA_ROOM = none (SPN-A released) · offline_at 10:13 UTC
live sessions 0 · open leases 0 · active visits 0
TEMPORARY_COHORT_MEMBERSHIP = false · COHORT = [9,15,18]
```

**Reconciliation — zero unexplained mutation:**

```
locks 15 -> 15 · Fahira home TLK1 -> TLK1 · authz 47/45 -> 47/45
devices 5/3 -> 5/3 · credentials 5/3 -> 5/3 · open leases 1 -> 1 (Karmila's, untouched)
audit 817 -> 819: exactly two rows, both Fahira's, both expected
log 1406217 bytes / 163 ERROR — byte-identical · health 200/200/200
UNEXPECTED branch / authorization / credential / device / flag changes = 0
```

---

## 12. The corrected readiness gate (authoritative for Wave 2+)

Real-device readiness for `DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1` means **all**
of:

- the doctor has a `HOME_LOCKED_BRANCH`;
- the doctor has an ACTIVE authorization to the selected tablet;
- the selected tablet is currently eligible;
- the doctor enters the temporary trusted-device enforcement cohort;
- a real physical tablet login succeeds;
- a server-side success proof exists;
- the proof is tied to that currently eligible tablet;
- the doctor is removed from the temporary cohort afterwards;
- the final cohort returns to `[9,15,18]`;
- no unexplained production mutation or error.

It does **NOT** require live effective-branch narrowing while
`single_active_session=false`.

`ARM_SINGLE_ACTIVE_SESSION_FOR_READINESS = NO`. The lease engine is not
temporarily activated for any wave, and Karmila's lease 8 is **not** released
merely to make a ceremony possible.

### Branch authority — classified separately, and honestly

```
BRANCH_LOCK_CAPABILITY_PROVEN            = YES
  · the PR-B production ceremony
  · current automated regression
  · 15/15 HOME locks stored
  · source verification: the resolver is DELIBERATELY coupled to single-session arming

FLEET_LIVE_BRANCH_LOCK_ACTIVATION_PROVEN = NO
  · global/fleet activation has not happened
```

Never collapse those two lines into one. A stored lock is a capability; a
narrowed queue is an activation, and only one of them is true today.

### Wave 2+ — device readiness only

Authorized, but **never started automatically**: clinicians and tablets must be
physically ready first. At most **two** campaign doctors per wave, cohort size
**≤ 5**. Per doctor, in order: precheck → human presence → tablet confirmation →
rollback readiness → temporary cohort add → config refresh → verify cohort →
physical tablet login → server proof → remove → config refresh → verify baseline
cohort → health/audit reconciliation. **A failed login rolls back first and is
diagnosed second.**

---

## 13. THE CANONICAL CEREMONY (authoritative for Wave 4+)

**The cohort does not move.** `COHORT_BEFORE == COHORT_DURING == COHORT_AFTER ==
[9,15,18]`. A cohort change during a normal readiness ceremony is an
**unexpected mutation**.

```
physical doctor present
  -> eligible tablet ready
  -> machine precheck
  -> DIRECT Android tablet login          (no enrolment, no config change)
  -> server-side success proof
  -> verify proof tied to a currently eligible device
  -> verify active DoctorDeviceAuthorization
  -> verify HOME lock unchanged
  -> canonical logout
  -> health / audit reconciliation
```

### Machine precheck per doctor

Doctor present · operator and tablet ready · `active_visit=0` · `room=none` ·
`online=false` · `HOME_LOCKED_BRANCH` set · selected tablet currently eligible ·
active `DoctorDeviceAuthorization` to that tablet.

**No open-lease condition** — `single_active_session` is false, so the lease
engine is inert and nothing in the device-login path consults it. Do not invent
coupling that the source does not have.

### Capture per doctor

`DOCTOR_ID` · `USER_ID` · `HOME_BRANCH` · `DEVICE_ID` · `DEVICE_BRANCH` ·
`DEVICE_ELIGIBLE_AT_PROOF_TIME` · `AUTHORIZATION_ID` · `AUTHORIZATION_ACTIVE` ·
`LOGIN_RESULT` · success audit id + timestamp · readiness proof id · observed
effective branch · HOME lock before/after.

The observed effective branch is **informational** while the resolver is
disarmed. Do **not** require `EFFECTIVE_BRANCH == HOME`. **Do** require
`HOME_LOCKED_BRANCH_AFTER == HOME_LOCKED_BRANCH_BEFORE`.

### PASS

Real physical tablet login success · server-side success evidence · device
currently eligible · authorization active · HOME lock unchanged · canonical
logout completed · health pass · no unexplained mutation or error.
`REAL_DEVICE_READY` increments **only** on this.

### FAIL — no rollback needed any more

Because nobody is enrolled, a failed login needs no cohort rollback:

1. confirm the cohort is still `[9,15,18]`;
2. confirm health;
3. capture the server-side rejection reason;
4. leave the doctor outside enforcement scope;
5. diagnose afterwards.

Do not mutate the cohort, do not revoke the authorization, do not touch the
tablet unless evidence actually points at the tablet.

### `invalid_credentials` does not mean the password is broken

It means **that request** did not authenticate — a typo, a wrong email, a stale
remembered credential, or an outdated stored password are indistinguishable.
Record `SUBMITTED_CREDENTIALS_REJECTED=YES`, never `STORED_PASSWORD_BROKEN=YES`
without independent proof.

drg Ramadhan is the production proof: rejected at 10:41, **succeeded at 10:57**,
`users.updated_at` still 2026-06-29 — same account, no reset, nothing changed.

So do **not** reset a password after one rejection. Allow a fresh deliberate
entry first, respect the authentication rate limits, and use
`settings/users/<id>/edit` only when an authorized human has determined that
remediation is genuinely required.

### Browser credential pre-check

`BROWSER_CREDENTIAL_PRECHECK_REQUIRED = NO`. It proves less than the tablet
login, adds an authentication event, and guarded a lockout that no longer
exists. Optional diagnostic after repeated failures only — never a gate.
