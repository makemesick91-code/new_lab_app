# REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1 — Phase 0 (discovery)

Branch `revision/doctor-pwa-webauthn-only-access-1`, cut from `56a02aed`
(= production HEAD, = GO tag `doctor-access-global-device-enforcement-readiness-1-go`).

**PHASE 0 IS READ-ONLY. No source file was changed. No flag was moved. No
deploy ran. This document authorizes nothing.**

Phase 0 answers the prompt's mandatory discovery sections (§1, §3, §7, §9, §10,
§27, §28–33, §64) against the deployed tree, and records the three product
decisions the owner made once the discovery contradicted the prompt's
assumptions.

---

## 1. Verified starting posture (§1)

Read from production, not assumed. Commands are read-only; `tinker` is refused
on this deployment by design and was not used.

```
PRODUCTION_HEAD   = 56a02aede67ff9cc214620e6d21408c43c640905
PRODUCTION_TREE   = 11fa8160e7e2e7addc56cda98cd40ae93cc8fff0
BRANCH            = feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
EXACT_TAG         = doctor-access-global-device-enforcement-readiness-1-go
WORKING TREE      = clean (one untracked runtime dir, `storage/runtime-home/`)
```

`webauthn:readiness` → `VERDICT=ARMED`

```
RELYING_PARTY_ID         = daengtisia.online
ALLOWED_ORIGINS          = https://daengtisia.online
USER_VERIFICATION        = required          §17 SATISFIED
REQUIRE_DEVICE_BOUND     = true              §18 SATISFIED
CHALLENGE_TTL_SECONDS    = 120
DEVICE_ENFORCEMENT_ARMED = true
WEBAUTHN_LOGIN_ARMED     = true
DEVICES_ACTIVE           = 4
CREDENTIALS_USABLE       = 4
CREDENTIALS_DEVICE_BOUND = 4
```

`android:phase4a-pilot-scope` → `SCOPE_VERDICT=GO`, and this is the §13 gap
expressed as a number:

```
DOCTOR_ROLE_ACCOUNT_COUNT    = 15
COVERED_DOCTOR_USER_IDS      = 9, 15, 18     (drg Nisa, drg Fiitri, drg Karmila)
BROWSER_DENIED_DOCTOR_COUNT  = 3
BROWSER_ALLOWED_DOCTOR_COUNT = 12
ENFORCEMENT_SCOPE_MODE       = pilot
GLOBAL_SCOPE_PERMITTED       = false
GLOBAL_ENFORCEMENT_ACTIVE    = false
PILOT_COHORT_MAXIMUM         = 5
```

`doctor:fleet-readiness` → `FLEET_READINESS=PARTIAL`, `AUTHORIZES_ACTIVATION=false`

```
ELIGIBLE_DOCTORS = 15   LOCKED = 15   UNSET = 0
ELIGIBLE_TRUSTED_DEVICES = 4        DEVICE_ESTATE_TOTAL = 6
AUTHORIZATION_TARGET_PAIRS = 60     ACTIVE = 60   MISSING = 0   DUPLICATE = 0
HOME_BRANCH_MATRIX: SPN4=10  LDK2=3  TLK1=2
FINDING trusted_device_without_readiness_proof (device_id=7)
```

Devices per branch: `TLK1=1  LDK2=1  ATG3=1  SPN4=1`. Doctors reachable through
SPN4's single tablet: **13**.

`doctor:half-b-readiness` → machinery `READY`, world `FAIL`, `authorizes_activation: false`

| Prerequisite | Measured | Blocks activation |
|---|---|---|
| real_device_pilot_passed | PASS | no |
| every_enforced_doctor_has_an_active_device | PASS | no |
| spare_device_available_per_branch | **FAIL** | **YES** |
| device_loss_runbook_rehearsed | **UNVERIFIED** | **YES** |
| rollback_to_browser_login_proven | PASS | no |

### Authorization denominator, recomputed (§75)

Not carried over from a previous sprint. `15 doctors x 4 eligible devices = 60`
target pairs, 60 active, 0 missing, 0 duplicate.

---

## 2. What the prompt asks for that is ALREADY BUILT

This is the largest Phase 0 result: four requirements need no new code.

| § | Requirement | Finding |
|---|---|---|
| §13 | Deny doctor password browser login | **Already implemented and already armed.** `doctor.trusted_device_enforcement` is documented in the flag registry as *"Turning it on DENIES browser login for every account holding the Doctor role."* It is a **scope** change (3 → 15 doctors), not an implementation. |
| §5 | Never treat PWA install as authentication authority | **Already honoured.** The registry states *"A PWA being installed grants nothing; the credential is the proof."* |
| §17 | User verification required | **Already enforced** server-side: `USER_VERIFICATION=required`. |
| §18 | Acceptable device binding / BE-BS policy | **Already enforced**: `REQUIRE_DEVICE_BOUND=true`, and 4 of 4 usable credentials are device-bound, so a synced (`BE=1`) passkey is refused. |
| §28–32 | Sidebar on every authenticated device-workflow page | **Already satisfied** — see §4 below. |

---

## 3. THE CRITICAL PATH — `cryptographically_verified` (§9, §10)

`cryptographically_verified` is written **only** by the Android keystore
challenge-response (`DoctorDeviceEnrollmentService`: *"sets
`cryptographically_verified` after a valid signature"*). WebAuthn registration
never sets it.

It is **not** read in the PWA login path. `DoctorDeviceWebAuthnLoginService::canAssert()`
is flag → enforcement → doctor resolves → usable credential, and nothing else.
So §10 is already true *for login*.

**But it is a hard gate in 8 live call sites that govern PROVISIONING and
ELIGIBILITY**, none of which are the login path:

| Call site | What it gates | Consequence if Android is retired first |
|---|---|---|
| `DoctorDeviceAuthorizationService:204` | creating a `DoctorDeviceAuthorization` | §42's mandatory authorization **cannot be created** for a PWA-only device |
| `DoctorDeviceBulkAuthorizationService:486,547` | `doctor:device-bulk-authorize` | bulk authorize **refuses** the device |
| `DoctorGlobalRolloutReadinessService:252,453` | "complete trusted path" | device reported **incomplete** forever |
| `DoctorEstateResilienceService:1670` | what counts as an **eligible trusted device** | a PWA-only spare **can never satisfy** `spare_device_available_per_branch` |
| `DoctorFleetReadinessService:327,466` | eligible-device filter | device invisible to fleet readiness |
| `DoctorAppLoginGate:349`, `DoctorAppLoginService:409`, `DoctorDeviceProofService:200` | the Android path itself | expected; retired with it |

### Why this is the critical path, and an ordering trap

Retiring Android before re-deriving this predicate makes it **permanently
impossible to provision a new PWA-only device**. The estate would freeze at
today's 4 Android-enrolled tablets. Buying twenty tablets would not help: none
would count as eligible, so Blocker 2 (spares) becomes unsatisfiable *by
construction*.

The prompt's §21 puts Android retirement at Stage 4, after PWA acceptance at
Stage 2 — but §38/§39 require new-device registration to work without Android
from Stage 1. Those cannot both hold until this predicate is re-derived.
**§10 is therefore not polish; it is the first thing that must change.**

### Intended shape (§11 — one canonical policy, no duplicates)

A device is identity-verified if it carries **either** Android keystore proof
**or** an acceptable device-bound WebAuthn credential. One predicate, read by
all 8 sites. This preserves existing Android evidence as historical fact (§10
forbids deleting it) while admitting PWA-only hardware.

Not yet designed in detail. **No code written in Phase 0.**

---

## 4. Device workflow UI matrix (§27, §64) and the sidebar invariant (§28–33)

Layout capability, measured:

| Layout | `@stack('scripts')` | sidebar |
|---|---|---|
| `layouts/app.blade.php` | yes (line 47) | yes — `@include('layouts.sidebar')` line 33 |
| `layouts/guest.blade.php` | yes (line 40) | no (correct) |

Chain: `<x-settings-shell>` → `x-app-layout` → `layouts/app.blade.php`.

| View | Root | Authenticated | Sidebar | Script stack | Verdict |
|---|---|---|---|---|---|
| `settings/doctor-devices/index` | `x-settings-shell` | yes | YES | reachable | PASS |
| `settings/doctor-devices/show` | `x-settings-shell` | yes | YES | reachable | PASS |
| `settings/doctor-devices/create` | `x-settings-shell` | yes | YES | reachable | PASS |
| `settings/doctor-devices/webauthn` | `x-settings-shell` | yes | YES | `@push('scripts')` reachable | PASS |
| `doctor-device-authorizations/index` | `x-settings-shell` | yes | YES | reachable | PASS |
| `doctor-device-authorizations/show` | `x-settings-shell` | yes | YES | reachable | PASS |
| `auth/doctor-device-webauthn` | `x-guest-layout` | **NO** | none | `@push('scripts')` reachable | PASS — §31 exception |

`auth/doctor-device-webauthn` is the pre-authentication assertion page. The
session holds only `doctor_device_webauthn.pending_user_id`, documented as
*"Hold no privilege on their own"*, so `AUTHENTICATED_CONTEXT=NO` and the guest
shell is correct under §31 rather than excused by history.

**§28–32 SIDEBAR_PRESENT=YES for every authenticated row. No remediation
needed.** The historical verify-button failure (a `@push` with no matching
`@stack`) is already fixed: every pushing view resolves to a layout that
exposes the stack.

### §33 correction

**No layout in this application exposes `@stack('styles')`** — measured zero on
all four layouts. Styling is delivered through Vite, not a Blade style stack.
§33's literal check resolves to "no such slot exists, by design", not to a
defect. Any future WebAuthn styling must go through Vite.

### Method note

An earlier, narrower grep reported `LAYOUT: NONE` for the six `x-settings-shell`
views because it matched only `@extends` and `<x-app|guest|settings-layout`.
That would have been a fabricated defect. The table above is from a corrected
root-element pass. Recorded because the same narrow pattern will mislead the
next reader.

---

## 4a. DOCTOR_AUTH_FLOW_MATRIX (§7) — COMPLETE

Every path traced from the route file through to the model on `56a02aed`.
`FLAG` names are registry keys read only through `FeatureFlagService`.

| # | Path | Route | Middleware | Controller | FormRequest | Service | Flag | Security authority | Current | Target |
|---|---|---|---|---|---|---|---|---|---|---|
| A | Doctor password/browser login | `POST /login` (`routes/auth.php:23`) | `guest` | `AuthenticatedSessionController@store` | `LoginRequest` | `DoctorAppLoginGate` + `PostAuthenticationRedirectService` | `doctor.trusted_device_enforcement` | gate: `enforcementEnabled()` AND `appliesTo()` AND per-doctor scope | **ALLOWED for 12 of 15 doctors** | **DENIED for all eligible doctors** (password-only; §99) |
| B | PWA WebAuthn login | `GET /doctor-device-webauthn`, `POST …/options`, `POST …` (`web.php:1814-1835`) | `web` + `throttle:30,1` | `DoctorDeviceWebAuthnLoginController` | `DoctorDeviceWebAuthnAssertionRequest` | `DoctorDeviceWebAuthnLoginService` | `doctor.pwa_webauthn_device_login` (depends on enforcement) | credential + binding + UV + authorization, asserted in service | ARMED, pilot-reachable | **canonical sole channel** |
| C | Android app login | `POST device-api/v1/doctor/login` | `throttle:doctor-app-login` | `DoctorDeviceApiController@doctorLogin` | `DoctorAppLoginRequest` | `DoctorAppLoginService` | `doctor.trusted_device_enforcement` | keystore proof + ticket redemption | ACTIVE | **RETIRED (Stage 5)** |
| D | Android login challenge | `POST device-api/v1/doctor/challenge`; `GET doctor/authorization/{uuid}/status` | `throttle:doctor-app-login-challenge` | `DoctorDeviceApiController@loginChallenge` | `DoctorAppLoginChallengeRequest` | `DoctorAppLoginService` | as C | keystore challenge-response | ACTIVE | **RETIRED (Stage 5)** |
| D′ | Android device enrolment / proof | `POST enrollment/request` (`throttle:10,1`); `GET enrollment/{uuid}/status`, `POST challenge`, `POST proof` (`throttle:60,1`) | throttle only | `DoctorDeviceApiController` | `DeviceEnrollmentRequest`, `DeviceChallengeRequest`, `DeviceProofRequest` | `DoctorDeviceEnrollmentService`, `DoctorDeviceProofService` | as C | keystore signature | **SOLE WRITER of `cryptographically_verified`** | **RETIRED (Stage 5)** — writer disappears, so §102 must land first |
| E | WebAuthn registration | `GET/POST doctor-devices/{doctorDevice}/webauthn[/options]` (`web.php:493-498`) | `auth` + `permission:view_doctor_devices\|manage_doctor_devices` | `DoctorDeviceWebAuthnController` | `DoctorDeviceWebAuthnRegistrationRequest` | `DoctorDeviceWebAuthnRegistrationService` | — | permission + policy; **never sets `cryptographically_verified`** | ACTIVE | unchanged + becomes the identity-proof branch (§102) |
| F | WebAuthn authentication | see B | see B | see B | see B | see B | see B | see B | ACTIVE | canonical |
| G | Trusted-device approval | `POST doctor-device-enrollments/{enrollment}/approve\|reject` (`web.php:475-478`) | `auth` + `permission:view_doctor_devices\|manage_doctor_devices` | `DoctorDeviceController@approveEnrollment/rejectEnrollment` | `ApproveDoctorDeviceEnrollmentRequest` | `DoctorDeviceEnrollmentService` | — | human approval, audited (§40) | ACTIVE | retained; must accept PWA-only devices |
| H | DoctorDeviceAuthorization | `GET doctor-device-authorizations[/{authorization}]` (`web.php:1773-1776`); writes under `manage_doctor_device_authorizations` (`:1779`) | `auth` + those permissions | `DoctorDeviceAuthorizationController` | `DoctorDeviceAuthorizationReasonRequest` | `DoctorDeviceAuthorizationService` | — | **hard Android gate at `:204`** | **BLOCKS PWA-only devices** | migrated by §102/§104 |
| I | Credential/device binding | within B and E | — | — | — | `WebAuthnDeviceBinding`, `WebAuthnRelyingParty` | — | `REQUIRE_DEVICE_BOUND=true`, `UV=required` | ENFORCED | unchanged |
| J | Device revoke / disable / reactivate | `POST doctor-devices/{doctorDevice}/revoke\|disable\|reactivate` (`web.php:466-471`) | `auth` + device permissions | `DoctorDeviceController` | `DoctorDeviceReasonRequest` | `DoctorDeviceService` | — | permission + policy + reason | ACTIVE | unchanged |
| K | Credential revoke | `POST doctor-devices/{doctorDevice}/webauthn/{credential}/revoke` (`web.php:499`) | `auth` + device permissions | `DoctorDeviceWebAuthnController@revoke` | `DoctorDeviceWebAuthnRevokeRequest` | `DoctorDeviceWebAuthnRegistrationService` | — | permission + policy | ACTIVE | unchanged |
| L | Session lease | global middleware (`bootstrap/app.php:82`) + `Event::listen(Login::class, ClaimDoctorSessionLease::class)` (`AppServiceProvider.php:149`) | global | — | — | `DoctorSessionLeaseService` → `DoctorSessionLeaseRepository` → `DoctorSessionLease` | `doctor.single_active_session` | claimed on framework `Login` event, revalidated per request | **LIVE (true)** | **stays true (§19)** |
| L′ | Device session re-verification | global middleware (`bootstrap/app.php:101`), alias `doctor.device.session` (`:115`) | global | — | — | `DoctorDeviceSessionService` | `doctor.trusted_device_enforcement` | re-verifies the recorded proof per request | ACTIVE | unchanged |
| M | Logout | `POST /logout` (`routes/auth.php:57`) | `auth` | `AuthenticatedSessionController@destroy` | — | `DoctorSessionReleaseService` | — | session teardown + lease release | ACTIVE | unchanged |
| N | Recovery / replacement | **NO DEDICATED ROUTE** | — | — | — | composed from J + G + E | — | — | **GAP** | Stage 2 (break-glass, §107-115) |
| O | Non-Doctor browser login | `POST /login` (same as A) | `guest` | `AuthenticatedSessionController@store` | `LoginRequest` | `PostAuthenticationRedirectService` | — | `DoctorAppLoginGate::appliesTo()` returns **false** | **UNCHANGED** | **UNCHANGED (§15)** |

### Three findings from the trace

1. **`appliesTo()` is role-based, deliberately.** `return $user->hasRole('Doctor');`
   — and the source comments that a Super Admin who *also* holds the Doctor role
   is included on purpose, because "the hardware is trusted, and a powerful
   account is not a reason to skip that". Non-Doctor accounts fall out here,
   which is exactly why §15/§O is safe.

2. **The PWA login routes carry `web` only, not `auth`** — correct, since the
   actor is mid-login. Denial is therefore *not* route middleware; it is asserted
   inside `DoctorDeviceWebAuthnLoginService` and `DoctorAppLoginGate`. That
   satisfies §14 (server-side, not UI) and means row A's denial cannot be
   bypassed by crafting a request.

3. **Row D′ is the sole writer of `cryptographically_verified`, and row H is a
   hard reader of it.** Retiring D′ (Stage 5) while H still gates on it is the
   §100 trap: the writer vanishes and the gate can never again be satisfied.
   §102 must land in Stage 1.

---

## 5. Half-B supersession decision (§3, §122)

`DOCTOR-ACCESS-GLOBAL-DEVICE-ENFORCEMENT` (GO-tagged 2026-09-18) is marked:

**`SUPERSEDED_BY_PWA_ONLY_PRODUCT_DECISION` — in its ACTIVATION DESIGN ONLY.**

Its five prerequisites are **not** obsolete, and the programme is marked neither
PASS nor FAIL:

| Prerequisite | Status under PWA-only |
|---|---|
| real_device_pilot_passed | still relevant, still PASS |
| every_enforced_doctor_has_an_active_device | still relevant, **tightens** — it becomes the only way in |
| spare_device_available_per_branch | **tightens sharply** — no password fallback exists behind it |
| device_loss_runbook_rehearsed | **tightens sharply** — same reason |
| rollback_to_browser_login_proven | **INVALIDATED AS A STRATEGY** — §13 deletes its target |

Marking the whole programme superseded would discard the exact gates PWA-only
makes *more* necessary. That is the failure §3 warns against, so the
supersession is scoped to the activation design and the prerequisites are
inherited.

---

## 6. Owner decisions taken in Phase 0

The discovery contradicted three of the prompt's assumptions. Decisions:

### D1 — Rollback (§80, §81)

PWA-only removes browser login, which is today's *proven* rollback
(`rollback_to_browser_login_proven = PASS`). A PASS prerequisite becomes
structurally impossible, so the inherited rollback cannot be reused.

**Decision: design a break-glass path.** An audited, time-boxed,
permission-gated emergency access route for a doctor whose device is
unavailable — supervisor-authorized, written reason, expiring, fully audited.
This becomes the new rollback target and a **Stage-3 prerequisite**: no
password-login denial ships before it exists and is proven.

### D2 — Estate capacity (§72, §73)

`spare_device_available_per_branch = FAIL` — *"a staffed branch holds no
spare."* Ten doctors are homed at SPN4 behind one tablet; thirteen are
reachable only through it. Today that is an inconvenience (browser fallback);
under PWA-only it is ten clinicians unable to see patients with no fallback.

**Decision: owner accepts the single-tablet-per-branch risk, in writing.**

**SIGNED.** Recorded verbatim at
`docs/operations/doctor-device-estate-owner-risk-acceptance-pre-live.md`
with `OWNER_RISK_ACCEPTANCE=YES`, `SCOPE=PRE_LIVE_DEVELOPMENT`,
`MEASURED_STATUS=FAIL`, `CONTRADICTION=NO`.

### Correction to this decision's original plan

The plan above said the acceptance would be recorded "as an **attestation**".
**That was wrong, and it was not done.** `Prerequisite::contradicts()` is:

```php
return $attested && $measured !== self::PASS;
```

so writing the acceptance into
`global_prerequisites_attested['spare_device_available_per_branch'] = true`
against `measured = FAIL` would have set `contradiction = true`, raised
`FINDING_CONTRADICTION`, and directly contradicted the owner's declared
`CONTRADICTION=NO`. It is also the exact defect
`DoctorGlobalEnforcementReadinessService` exists to close — its docblock calls
out `attested true + measured false ===> PASS` as *"satisfied by typing `true`
five times"* on the artifact meant to prevent a premature fleet-wide lockout.

The `Attested` column answers *"did somebody sign that a spare exists?"* — a
different proposition from *"the owner accepts operating without one"*. Only the
second is true, so no signature was recorded and **no config file was touched**.

Nothing in `app/` or `config/` models risk acceptance separately from
attestation today; every prior `ACCEPTED_RISK` in this repo is prose in
`docs/sprints/*.md`. The acceptance therefore follows that precedent and **is
read by no gate**. A machine-readable `risk_accepted` field is specified as a
Stage-1 item under six constraints (never an input to `contradicts()`, never
alters `Measured`, gate keeps `FAIL` + `blocks_activation=YES`, shown as its own
column, scope-limited to pre-live, authorizes nothing) — see §5 of the
acceptance record.

Net effect: the estate gate still reports **FAIL** and still blocks activation.
The acceptance removes the *decision* blocker, not the measurement.

### D3 — §13 reading

The PWA path is password **then** WebAuthn, not credential-only.
`beginPending()` is *"Park a password-verified login while the device proves
itself"*, and `doctor:fleet-readiness` states: *"The clinician is identified by
the password step; the device assertion proves hardware presence and screen-lock
passage, never which human was standing there."*

The credential names the **tablet**, not the clinician. Removing the password
step on a shared clinic tablet would let any doctor assume any other doctor's
identity.

**Decision: §13 means deny password-ONLY login** — password without a
successful device assertion. The password step is retained as the clinician
identification. This is exactly what `doctor.trusted_device_enforcement` already
does, so §13 reduces to widening enforcement scope from 3 doctors to 15.

---

## 7. Authoritative stage order (§101)

§21's original order cannot hold: §38/§39 need PWA-only registration working
before Android is retired, while the eligibility predicate still requires
Android proof. §101 supersedes it, and this is the order in force:

| Stage | Content | Must not do |
|---|---|---|
| **1** | Canonical trusted-device proof / eligibility abstraction (§102) | **not** retire Android (§141) |
| **2** | Migrate provisioning / readiness / estate consumers (§104) **and** implement break-glass (§107–115) | preserve pilot scope (§142) |
| **3** | Full controlled PWA pre-live acceptance (§131), incl. device-loss / break-glass rehearsal (§120) | no global widening unless PASS (§143) |
| **4** | Governed widening from pilot to the PWA-only target scope (§124–128) | needs its own fresh checkpoint (§144) |
| **5** | Retire Android doctor authentication; prove old-APK denial (§145) | only after Stage 4 succeeds |
| **6** | Post-retirement regression, rules sync, contradiction scan, Graphify, dataviz, Superpower, DEVFLOW, Full Suite, GO (§146) | — |

Android retirement must never precede Stage 1–3 proof (§101).

`GLOBAL_SCOPE_PERMITTED=false` and `PILOT_COHORT_MAXIMUM=5` are real hard
bounds (§124). Stage 4 crosses them as a governed change through tests, DEVFLOW,
PR, CI, merge, VPS deploy and production verification (§126) — never a config
edit. The target subject set is derived from the eligible doctor population at
that time; **15 is today's measurement, not permanent policy** (§127).

---

## 8. Explicitly UNVERIFIED (never PASS)

Per §0: no timeout, missing output or unreadable result is recorded as a pass.

| Item | Status | Why |
|---|---|---|
| `DOCTOR_AUTH_FLOW_MATRIX` (§7) | **COMPLETE** | see §4a — all 15 paths A–O traced route → middleware → controller → FormRequest → service → model |
| Recovery / replacement route (row N) | **GAP, not unverified** | no dedicated route exists; composed from J+G+E. Closed by Stage 2 break-glass |
| `TRUSTED_DEVICES_WITH_READINESS_PROOF=3` + `WITHOUT=7` = 10, vs `DEVICE_ESTATE_TOTAL=6` | **UNVERIFIED** | the metric's population is not the device estate; not derived, and no defect is asserted |
| `BREAK_GLASS_APPROVER_PERMISSION` (§110) | **NOT YET DERIVED** | Stage 2; must be re-derived from source, never invented |
| `device_loss_runbook_rehearsed` | **UNVERIFIED** (production) | no evidence at `readiness/device-loss-drills/latest.json`; absence of a rehearsal is not a failed one |
| Level 2 / Level 3 estate capacity (§72) | **DEFERRED_GO_LIVE_READINESS** | not PASS; see D2 |
| PWA lifecycle / service-worker update behaviour (§35, §36) | **NOT YET EXAMINED** | Stage 3 |
| Graphify semantic call edges (§8) | **NOT AVAILABLE** | see §8a |
| Dataviz architecture diagram (§89, §138) | **COMPLETE** — see §8b | |

### 8a. Graphify precheck (§8) — done, with a stated limitation

`graphify update .` was run inside this worktree and re-extracted 3771 files,
producing a graph built from `56a02aed` itself (`graphify-out/graph.json`,
32.8 MB).

Recorded honestly per §137:

```
GRAPHIFY_PRECHECK_COMPLETE  = YES   (structure)
GRAPHIFY_TOPOLOGY           = AVAILABLE
GRAPHIFY_CALL_EDGE_AUTHORITY= UNAVAILABLE
```

The graph is **AST-only**. Without a semantic-extraction API key it emits
containment and method edges, not cross-file call edges — `explain` on
`DoctorDeviceAuthorizationService` and `DoctorDeviceWebAuthnLoginService`
returns their own methods, not their callers. It therefore **cannot** answer
the §8/§9 reader/writer question on its own.

The authoritative enumeration in §3 above is the exhaustive grep, which is
complete for this question because the predicate is reachable only through one
method name (`isCryptographicallyVerified`) and one column name
(`cryptographically_verified`), both searched across `app/`, `config/`,
`database/` and `routes/`. Graphify corroborates the topology; it does not
supersede that enumeration, and no claim in this document rests on a graph edge.

Note for the next reader: a `graphify update .` issued through a backgrounded
shell silently ran against the wrong checkout and produced nothing while still
exiting 0. Run it in the foreground from the intended tree and confirm
`graphify-out/graph.json` exists afterwards.
| Prompt sections 95 → end | **NOT RECEIVED** | source prompt truncated mid-§95; GO semantics and closure criteria unknown, so no GO claim is possible |

---

## 8b. Dataviz (§89, §138) — COMPLETE

`DATAVIZ_FINALIZED=YES`

Published: **https://claude.ai/code/artifact/75f0d2dc-6da6-4b50-be4d-c25e4f166e6d**
(*PWA-Only Doctor Access* — private to the owner unless shared).

Three hand-authored SVG figures, one claim each, per §138:

1. **Legacy** — the three parallel paths into a doctor session, with the
   password-only path marked denied and the Android path marked retiring. Shows
   that all three already terminate in the same single-session lease, so the
   lease is not what this revision changes.
2. **Migration** — the §102 OR-predicate between two proof sources and the five
   eligibility consumers, with today's direct Android coupling drawn as a dashed
   edge labelled *"removed in Stage 1"*. This is the §100 trap made visible.
3. **Target** — the §99/§135 chain (clinician identity → WebAuthn trusted device
   → authorization → single-session → application), with password-only blocked
   before the chain and break-glass entering at the device leg only.

Also carried, per §138: `branch_lock=OFF`, Android `RETIRED` after migration,
password-only `DENIED`, non-Doctor auth `UNCHANGED`, break-glass as a bounded
per-doctor path, `Level3=FAIL` with pre-live risk accepted, and the device-loss
rehearsal shown as gating scope expansion rather than following it.

Design notes: the page reuses the project's own UIX-1 tokens (brand blue
`#1D4ED8`, no gold — gold stays revenue-only per the UIX-2 rule). Status colours
are **semantic, not categorical series**, so the dataviz CVD validator does not
apply; instead every coloured element also carries a text label, so no state is
conveyed by colour alone.

---

## 9. Phase 0 verdict

### §139 Phase-0 commit gate

| # | Gate item | State |
|---|---|---|
| 1 | Phase-0 findings doc complete | **PASS** (this document) |
| 2 | D2 owner-risk document present | **PASS** |
| 3 | D2 classified as risk acceptance, not attestation | **PASS** (§6, and §4 of the acceptance record) |
| 4 | §89 dataviz complete | **PASS** (§8b) |
| 5 | Auth flow matrix complete | **PASS** (§4a, paths A–O) |
| 6 | Device UI matrix complete | **PASS** (§4, 7 rows) |
| 7 | Android crypto reader/writer map complete | **PASS** (§3, 8 call sites) |
| 8 | Supersession decision complete | **PASS** (§5) |
| 9 | Graphify limitation recorded | **PASS** (§8a) |
| 10 | `SOURCE_MUTATIONS=0` | **PASS** — `git diff --stat` empty, `config/` unmodified |

§139 also directs that no deployment be made merely to manufacture a runtime
touch at this checkpoint. **Nothing was deployed.**

```
PHASE_0                   = COMPLETE
PHASE0_COMMIT_GATE        = 10/10 PASS
SOURCE_MUTATIONS          = 0
FLAGS_MOVED               = 0
CONFIG_MODIFIED           = NONE
DEPLOYED                  = NO
GO_TAG                    = NONE (not claimable until §153)
DATAVIZ_FINALIZED         = YES
GRAPHIFY_TOPOLOGY         = AVAILABLE
GRAPHIFY_CALL_EDGE_AUTHORITY = UNAVAILABLE
CRITICAL_PATH             = the device identity predicate (§3, §102)
STAGE_1_APPROVED          = YES (§140 — Stage 1 only)
REAL_WORLD_OPERATIONAL_PROOF = NOT_CLAIMED_PRE_LIVE (§97)
```
