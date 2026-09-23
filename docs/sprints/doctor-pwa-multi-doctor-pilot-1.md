# DOCTOR-PWA-MULTI-DOCTOR-PILOT-1

**Status: LIVE.** Three doctors, three branches, three active clinic devices, all
enforced through WebAuthn, all proven by physical ceremony on real hardware.

`GLOBAL_ENFORCEMENT_ACTIVE=false` · `GLOBAL_ROLLOUT_AUTHORIZED=NO`

This sprint ran in two parts. The **capability** half built and shipped the
enforcement cohort inert, and is tagged `doctor-pwa-multi-doctor-pilot-1-capability-go`.
The **live** half, recorded here, enrolled two more clinic tablets, provisioned two
more doctors, expanded the cohort and proved every path physically.

---

## 1. Authority

| Fact | Value |
| --- | --- |
| Production HEAD / tree | `4d1b57fa6b1bc196621fe811fd0c3e56592e035e` / `3bfb6302b30a1eb6c6a0e23d0882481baa9ff965` |
| Capability tag | `doctor-pwa-multi-doctor-pilot-1-capability-go`, object `85a1d880`, → `4d1b57fa` |
| Live pilot GO tag | `doctor-pwa-multi-doctor-pilot-1-go` → the same verified runtime `4d1b57fa` |
| Parent GO | `doctor-pwa-webauthn-1-go` → `41f87199` |
| Host | `srv1730088`, `APP_ENV=pilot`, `APP_DEBUG=false` |

No runtime code was deployed during the live half. The cohort is a host value, so
activation was a configuration change, not a release. Both GO tags therefore point
at the same verified runtime, which is the honest target: it is the code that was
actually proven.

## 2. The cohort

```
OLD_COHORT = [18]
NEW_COHORT = [9, 15, 18]
ADDED      = [9, 15]
REMOVED    = []
```

| Doctor | User | Doctor id | Branch | Device | Authz | Credential |
| --- | --- | --- | --- | --- | --- | --- |
| drg Karmila | 18 | 21 | SPN4 Sunu | 3 `PHASE4A_PILOT_TABLET_02` | 2 | 2 |
| drg Nisa | 9 | 17 | LDK2 Landak | 5 `PILOT_TABLET_04_LDK2` | 4 | 3 |
| drg Fiitri | 15 | 20 | ATG3 Antang | 6 `PILOT_TABLET_05_ATG3` | 5 | 5 |

Minimum required 3 doctors, 2 branches, 2 devices. Delivered **3 / 3 / 3**.

### The id trap, demonstrated on real data

The cohort takes `users.id`. On this deployment the two id spaces overlap in ways
that would silently enforce the wrong person:

- `mst_doctors.id` 15 is **drg Irwan**, not drg Fiitri
- `users.id` 17 is **Maghfirah Abdullah**, not a doctor at all
- `users.id` 20 is **drg Nurmilah**, a different doctor
- `users.id` 21 is **drg Aisyah**, while `mst_doctors.id` 21 is drg Karmila

Every id was resolved against both tables before it entered the cohort.

## 3. How activation was performed

One line added to the host environment file:

```
ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_IDS=9,15
```

The pre-existing `ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_ID=18` was left untouched.
The capability unions the two keys, so 18 was preserved rather than migrated, and
the change is a single additive line rather than a rewrite of a live pilot's scope.

Then, as the application user:

```
sudo -u daengtisiams php artisan config:clear
sudo -u daengtisiams php artisan config:cache
```

Env moved `7763c923…` → `e68e9748…`, exactly one line, `root:daengtisiams 640`
preserved, no duplicate keys, no secret-bearing backup written.

### The order-independence fix earned its keep

The scope report prints `DECLARED = 9,15,18` but `COVERED = 15,9,18`, because the
covered list returns in database order. The capability half compares **sorted
copies** rather than raw arrays. Without that fix this pilot would have resolved to
WATCH instead of GO on its first live reading.

## 4. Provisioning, in order

Readiness came before enforcement for every doctor. No id entered the cohort until
its device, authorization and credential all existed.

| Audit | Event | Result |
| --- | --- | --- |
| 629–631 | LDK2 tablet requested, approved, **proof verified** | device 5 active, `cryptographically_verified`, branch LDK2 |
| 632–637 | drg Nisa authorization pending → approved → app login | authz 4 active |
| 638–639 | WebAuthn registration on device 5 | credential 3, UV true, BE false, `device_bound` |
| 640–642 | ATG3 tablet requested, approved, **proof verified** | device 6 active, `cryptographically_verified`, branch ATG3 |
| 643–648 | drg Fiitri authorization pending → approved → app login | authz 5 active |
| 649–650 | WebAuthn registration — **against the wrong device** | credential 4 on device 5, see §5 |
| 651 | credential 4 revoked | uncertain physical origin |
| 652–653 | WebAuthn registration on device 6 | credential 5, UV true, BE false, `device_bound` |

Each enrolment shows the same canonical shape, including one
`DOCTOR_APP_LOGIN_AUTHORIZATION_REJECTED` while the authorization was still
pending. That rejection is the system working, not a fault.

## 5. Two mistakes the process caught

Both were caught by server-side verification, neither by the operator noticing.

**Credential registered against the wrong device.** At 14:32 a credential was
created at `/settings/doctor-devices/5/webauthn` when the intent was device 6. It
landed on the Landak device, which then held two credentials while Antang held
none. The two URLs differ by one digit and the pages are otherwise identical.

The database could not say which tablet produced it. Every credential here reports
AAGUID all-zeros with attestation `none`, normal for Android platform
authenticators, and both counters were 0. Asked which tablet they had been
holding, the operator answered **not sure**. It was therefore revoked. A credential
whose hardware cannot be established has no place in a device-trust pilot, and
`device_bound` means nothing if the row and the hardware might disagree.

The revoke reason stored on the row reads `iuohjg7rtfyedhfryf`. The audit trail is
append-only so that text is permanent. **The real reason is uncertain physical
origin**, recorded here because the row itself does not say so.

**Ceremony performed by the wrong doctor.** At 14:49 a login intended for drg
Fiitri on the Antang tablet was in fact drg Nisa's third login on the Landak
tablet, identified by user id, credential id and an identical user-agent hash. It
was not counted. drg Fiitri's real ceremony followed at 14:52.

## 6. The ceremonies

Watermarked: every claim below is an event strictly newer than the watermark taken
immediately before that ceremony.

| Doctor | Client | Audit | Credential | Counter | Protected request |
| --- | --- | --- | --- | --- | --- |
| drg Nisa | Chrome | 655 | 3 | 0 → 1 | activity 14:41:38 → 14:44:12, no rejection |
| drg Nisa | installed PWA | 657 | 3 | 1 → 2 | activity → 14:46:59, no rejection |
| drg Nisa | (third login) | 659 | 3 | 2 → 3 | — |
| drg Fiitri | Chrome | 661 | 5 | 0 → 1 | activity → 14:52:05, no rejection |
| drg Fiitri | installed PWA | 663 | 5 | 1 → 2 | activity → 14:56:01, no rejection |

Every login is preceded by `DOCTOR_SESSION_DEVICE_INVALIDATED`. That pairing is the
whole property: the password session is **torn down** because the doctor is
covered, and only WebAuthn readmits them. Enforcement is observable, not merely
configured.

The signature counters are the strongest evidence in this document. They are
produced by the tablets' authenticators, not by the application, and cannot
advance without that physical device signing a fresh challenge.

### What the server can and cannot distinguish

The Chrome and installed-PWA user agents for drg Nisa are **byte-identical**, MD5
`c6a91b89…`, both `Chrome/152.0.0.0`. So:

- **Server-verified**: two independent assertions on the same credential, minutes
  apart, each with its own invalidation and its own counter increment.
- **Operator-attested only**: that one was Chrome and the other the installed PWA.

That distinction is stated rather than blurred. The server has no field that
separates an Android PWA in standalone mode from the browser.

The server **can** distinguish the two tablets. Every drg Nisa assertion carries
MD5 `c6a91b89…`; every drg Fiitri assertion carries `980b68e0…`. Different
hardware, corroborated independently of anyone's testimony.

## 7. Verification

| Check | Result |
| --- | --- |
| Scope | covered `[9,15,18]`, size 3, ceiling 5, `SCOPE_VERDICT=GO` |
| Global, three ways | source `false`, runtime-config key absent, env absent |
| Non-pilot | denied **3** = cohort size, allowed **12** = 15 − 3 |
| Branch isolation | Nisa LDK2=LDK2, Fiitri ATG3=ATG3, Karmila SPN4=SPN4, **0 mismatches** |
| drg Karmila control | device 3, authz 2, credential 2 all unchanged; counter still 5 |
| Credentials per active device | exactly **1** usable on each of devices 3, 5, 6 |
| Health | `/login`, `/health/live`, `/health/ready` all 200 |
| Log errors | **162**, identical to the pre-ceremony baseline |
| Failed jobs / migrations | 0 / 0, queue worker active |

### Identity accounting

| | Before | After | New |
| --- | --- | --- | --- |
| Devices | 3 | 5 | 5 LDK2, 6 ATG3 |
| Authorizations | 3 | 5 | 4 Nisa, 5 Fiitri |
| Credentials | 2 | 5 | 3 Nisa, 4 revoked, 5 Fiitri |

**Unexpected new identities: 0.** Credential 1 remains revoked and credential 2
remains active, both untouched. No device was revoked, no authorization withdrawn.

### Two honest limits

**The "12 allowed" figure is not a login test.** It proves the gate returns allow
for those doctors, computed from the user id and the absence of a device session,
without credentials. It does not prove all twelve can complete a login, and no
automated test exercises a real non-cohort login while a three-member cohort is
armed. Proving more would mean handling other clinicians' passwords, which is not
worth the certainty.

**`android:phase4a-pilot-readiness` reports FAIL, 22/23.** The single failing check
is `enforcement_inactive`, which is `($armed || !$configuredOff) ? 'FAIL' : 'PASS'`
— a pure function of the enforcement flag. That command audits the **preparation**
sprint, whose contract was "ship with enforcement off". Enforcement has been armed
since the parent programme activated the pilot, so this check has been failing
since long before this sprint, deterministically, and is unfixable while any pilot
is live. The sprint-appropriate gate is `android:phase4a-pilot-scope`, which reads
GO. A later governance sprint should teach that check the difference between
"enforcement leaked on" and "a declared pilot is running".

## 8. Rollback

Unchanged, and it does not touch identities.

**Remove one doctor**: delete their id from
`ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_IDS`, then `config:clear` and `config:cache`
as `daengtisiams`. Their device, authorization and credential all survive.

**Whole pilot**: set both feature flags false and rebuild config. Expect denied 0
and allowed 15.

Device revoke, authorization revoke and credential revoke stay terminal and are
reserved for real compromise, never for rollback.

## 9. What this GO does not authorise

Not fleet-wide enforcement, not a fourth doctor, not a new device, not global
rollout. `GLOBAL_ENFORCEMENT_ACTIVE` is false and `global_permitted` remains a
source-controlled false that no host variable can reach. The cohort ceiling is 5,
and raising it costs a reviewed source change.

Next programme: `DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1`. PR #392 remains carried to
it, unapproved by this GO.
