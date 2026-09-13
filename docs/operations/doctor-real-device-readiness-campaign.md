# Doctor real-device readiness campaign — the 12 remaining ceremonies

`DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1`. Owner-authorized 2026-09-13
(`AUTHORIZE_12_DOCTOR_ROTATIONS=YES`), **held for scheduling** — no doctor has
been enrolled.

This authorization is for **bounded readiness rotations only**. It does not
authorize global enforcement, permanent cohort expansion, permanent
single-session activation, browser-policy weakening, or skipping rollback.

---

## 0. Why this cannot be done from a terminal

A doctor **outside** the enforcement cohort produces no proof at all:
`DoctorAppLoginGate::denyBrowserSessionReason()` returns null for them, the
browser login simply succeeds, and no device ceremony ever happens. So proof
requires putting the doctor **inside** the cohort — which denies their browser
login until they are taken out again.

That is the whole risk of this campaign. **Enrolment is a lockout** for as long
as it lasts. Never enrol a doctor who is not already standing at a tablet.

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
| TLK1 | 16 drg Fahira | 14 | **no tablet exists at TLK1 — any tablet is cross-branch** |
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

Required: `EFFECTIVE_BRANCH == HOME_BRANCH`, unless a legitimate active temporary
cover exists.

**Cross-branch is expected, not a fault.** Do not require
`DEVICE_PHYSICAL_BRANCH == HOME_BRANCH`; device branch is not doctor branch
authority. A TLK1 doctor on an SPN4/LDK2/ATG3 tablet is exactly the evidence
worth keeping.

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
