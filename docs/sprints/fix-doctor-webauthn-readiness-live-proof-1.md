# FIX-DOCTOR-WEBAUTHN-READINESS-LIVE-PROOF-1

Child of `REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1`, opened by a truth gap the
verify-device bugfix left behind.

**Retires nothing. Moves no flag. Widens no scope. Starts no Stage 4.**

---

## 1. The defect

`webauthn:readiness` reported `VERDICT=ARMED` continuously from **2026-09-09 to
2026-09-19** while the browser leg produced **no successful assertion at all**.

It was not wrong about anything it measured. It measured the wrong things:

```php
$report['webauthn_login_armed'] && $report['credentials_usable'] === 0 => 'ARMED_WITHOUT_CREDENTIALS',
$report['webauthn_login_armed'] => 'ARMED',
```

A feature flag, and a `COUNT(*)` over `trx_doctor_device_webauthn_credentials`.
Neither input can change when a ceremony stops working. The credential rows sat
there unchanged for ten days while the tablet in the clinic did nothing.

The architecture doc made it worse by documenting the verdict as **"`ARMED` |
live"**. It is not live. That word has been corrected.

**Why nobody noticed:** the Android Keystore path kept working throughout, and
the pilot doctors were using it. Android and WebAuthn share no code path —
`DoctorAppLoginService` holds 0 WebAuthn references, `DoctorDeviceWebAuthnLoginService`
holds 0 Keystore references. "The pilot is live" was true, and irrelevant.

```
CONFIGURED           != LIVE
CREDENTIAL_ROW_EXISTS != LIVE
BINDING_ROW_EXISTS    != LIVE
ANDROID_PROOF         != WEBAUTHN PROOF
```

## 2. The evidence source, and the one that was refused

The engine needs something the server writes **only** after a real assertion.

**Refused: `trx_doctor_device_webauthn_credentials.last_used_at`.** It *is*
written at assertion success, so it looks like the answer. Rules 156/157 already
refuse it for the sibling engine and the same reasons apply here: the column has
**no doctor column by design**, so on a shared tablet one doctor's login marks
another ready, and it cannot distinguish the credential that signed from the
credential that has since been revoked. This sprint did not re-litigate that
rule; it obeyed it.

**Used: `sys_audit_logs`, action `DOCTOR_DEVICE_WEBAUTHN_LOGIN_SUCCESS`.**
`DoctorDeviceWebAuthnLoginService` writes it exactly once — grep confirms **one
writer** — and only after `assertionValidator()->check()` returned, the
user-verification policy was satisfied, `Auth::login()` ran and the session was
regenerated. An assertion that verified and then failed to establish a session
writes nothing. It is append-only and carries the correlation the scalar columns
lack:

| field | meaning |
|---|---|
| `entity_id` | the credential row that signed |
| `new_values.doctor_device_id` | the device it signed on |
| `new_values.doctor_id` | the doctor record |
| `performed_by` | the authenticated user |

`DOCTOR_APP_LOGIN_AUTHORIZATION_SUCCESS` — the Android path — is **absent by
construction**: the repository names one action in one constant. Letting Android
satisfy a WebAuthn liveness question is the precise false green this sprint
removes, so it is prevented structurally rather than by review.

## 3. What counts as live

A proof counts only when all of these hold **right now**. The state at the time
of the assertion is irrelevant, because readiness is a statement about the
present:

- the device is `active` — a revoked tablet proves nothing
- the credential is un-revoked — a withdrawn key proves nothing
- its binding verdict is acceptable **under today's policy** — a credential
  admitted while the policy was loose stops counting when it tightens, the same
  rule the per-request revalidation path already enforces for live sessions
- the assertion was recorded against **that credential on that device**
- the assertion was a **WebAuthn** assertion

Production holds the awkward shape this is written for: credential 1 on device 3
is revoked and still carries two success rows.

## 4. Freshness — a new policy, explicitly decided

There was **no freshness duration for doctor WebAuthn proof anywhere** in the
codebase. What existed was a deliberate stance in `DoctorFleetReadinessService`:
*a proof does not expire with time; what invalidates it is the path underneath
being withdrawn.*

That stance is correct and is **not changed**. It answers a different question —
coverage ("has this doctor ever demonstrated they can use a trusted path"), and
a demonstration does not become untrue because time passed. This engine answers
liveness ("is the leg working now"), and liveness is the one property that does
decay with silence.

**The owner chose 7 days**, calibrated against the real outage: seven days puts
it into STALE on day eight; fourteen would never have fired. It lives in
`config/doctor_webauthn_live_proof.php` and is documented as not-to-be-widened
to make a red gate green.

A null/zero/negative window means **no opinion is configured**, and freshness is
then `UNVERIFIED` rather than silently passing. Being untold is not being
reassured.

## 5. Scope — the narrowest correct invariant

WebAuthn credential scope is the **device**. `DoctorDeviceAuthorization` scope is
doctor × device. So liveness is a **device** property, and this engine does not
demand fifteen human login ceremonies.

**Population: active devices.** Named out loud, because worst-of over an empty
set is vacuously true and this codebase has already shipped a gate that reported
PASS over zero usable tablets exactly that way. An empty population is
`UNVERIFIED`, never `READY`.

Doctor coverage remains an authorization/path question and stays where it lives,
in `DoctorFleetReadinessService`. This sprint did not build a second one.

## 6. The states

```
WEBAUTHN_CONFIGURED        flag on + relying party usable
CREDENTIALS_USABLE         un-revoked rows exist
LIVE_ASSERTION_PROOF       PASS | STALE | NEVER_PROVEN | UNVERIFIED
PROOF_FRESHNESS            FRESH | STALE | NEVER_PROVEN | UNVERIFIED
EFFECTIVE_READINESS        READY | NOT_READY | UNVERIFIED
```

`verdict` (the old configuration word, `ARMED` included) is **unchanged** —
a test pins `READY_NOT_ARMED` and callers may depend on it. The new dimensions
sit beside it, never folded into it.

`--strict` is **deliberately unchanged**: it still fails only on a relying party
that cannot run a ceremony. Liveness is opt-in through `--require-live-proof`,
so adding this dimension cannot silently start failing a caller that asked the
old question.

## 7. Tests — the false-green suite

Every test sets up a deployment the old engine would have called ARMED and
requires the new one to refuse. The positive path is last and is the only test
permitted to reach `READY`.

| Case | Required |
|---|---|
| rows exist, nothing ever asserted | `NEVER_PROVEN` / NOT_READY |
| proof outside the window | `STALE`, not PASS |
| boundary: exactly 7d vs 7d+1s | PASS, then STALE |
| device revoked after a fresh proof | not READY |
| credential revoked after a fresh proof | not READY |
| binding no longer acceptable | not READY |
| binding `unknown` | not READY |
| **Android success only** | `NEVER_PROVEN` — Android cannot satisfy WebAuthn |
| proof recorded against another device | not borrowed |
| proof query throws | `UNVERIFIED`, never PASS |
| no active devices | `UNVERIFIED`, never READY |
| no freshness policy configured | `UNVERIFIED`, even on a 1-minute-old proof |
| eligible + usable + fresh | **PASS / READY** |
| one fresh tablet among three | NOT_READY — one cannot carry the estate |

## 8. What GO means here

**GO means the readiness engine tells the truth. It does not mean the world is
ready.**

Production is expected to report **NOT_READY** after this ships, and that is the
correct outcome, not a regression:

```
device 3  PHASE4A_PILOT_TABLET_02   2026-09-19 00:53:04   FRESH
device 5  PILOT_TABLET_04_LDK2      2026-09-09 14:49:04   STALE
device 6  PILOT_TABLET_05_ATG3      2026-09-09 14:55:33   STALE
device 7  PILOT_TABLET_04_TLK1      never                 NEVER_PROVEN
```

No code here exists to preserve a green status. If the estate is not proven, the
gate says so.

## 9. What this does NOT change

```
device_loss_runbook_rehearsed      PASS    (Stage 3, unaffected)
spare_device_available_per_branch  FAIL    (blocks activation; owner risk
                                            acceptance sits beside it, never
                                            on top of it)
ACTIVATION_PREREQUISITES           FAIL
authorizes_activation              false
single_active_session              true
branch_lock                        false
Enforcement scope                  pilot [9,15,18], global_permitted false
ANDROID_DOCTOR_AUTH                RETAINED
STAGE_4_AUTHORIZED                 NO
```

## 10. Evidence, as measured

**Tests.** 16 in the new suite (57 assertions). The whole
`tests/Feature/DoctorDeviceWebAuthn/` directory: **98 passed / 427 assertions**.
Adjacent engines — `tests/Feature/DoctorAccess/` + `tests/Feature/DoctorDevice/` —
**1040 passed / 8768 assertions**, 0 failures.

**PostgreSQL.** The affected directory was run against a throwaway
**PostgreSQL 16.14** container, the same major version production runs (the
local default is 18, which is the outlier). 149 tables migrated into it, so the
run is genuine and not a silent SQLite fallback: **98 passed / 427 assertions**.
The repository decodes `new_values` in PHP precisely because `->>` and
`json_extract` are spelled differently across the two engines.

**Mutation: 16 applied, 16 killed, 0 survivors, 0 phantoms.**

| # | Mutation | Result |
|---|---|---|
| M1 | revoked-credential filter removed | KILLED |
| M2 | binding acceptability ignored | KILLED |
| M3 | wrong-device correlation removed | KILLED |
| M4 | stale treated as fresh | KILLED |
| M5 | Android action accepted as WebAuthn proof | KILLED |
| M6 | NEVER_PROVEN rolled up as pass | KILLED |
| M7 | query failure swallowed as an empty result | KILLED |
| M8 | empty population treated as measurable | KILLED |
| M9 | missing freshness policy treated as fresh | KILLED |
| M10 | STALE rolled up as pass | KILLED |
| M11 | UNVERIFIED rolled up as ready | KILLED |
| M12 | empty roll-up arm removed (answers READY to nobody) | KILLED |
| M13 | unusable display timezone throws out of the report | KILLED |
| M14 | future-dated proof accepted as fresh | KILLED |
| M15 | blank timestamp read as "now" | KILLED |
| M16 | repository folds a device-less row into a neighbour | KILLED |

Two things are worth recording about how that number was reached, because both
are ways a mutation run can lie.

**The first run reported 10 of 11 surviving, and every one of those was a
phantom.** The harness used an inline shell heredoc whose escaping mangled the
replacement strings, so the mutations never applied and the suite passed for the
obvious reason. The harness now asserts the anchor exists AND that the file's
bytes actually changed before it believes any result; a mutation that did not
apply is reported as NOT-APPLIED and is not counted as a survivor.

**M11 was a real survivor on the verified run, and it found a real gap.**
Flipping the roll-up's `UNVERIFIED => READINESS_UNVERIFIED` arm to
`READINESS_READY` survived the entire suite, because every other UNVERIFIED test
reaches the early-return helper rather than the `match`. Two assertions were
added: the roll-up is now pinned in the no-freshness-policy case, and a stub
repository exercises the ordering directly — an UNVERIFIED device must outrank a
fresh sibling. That ordering was the thing nobody had asserted.

**A regression the existing suite caught, and which was honoured rather than
relaxed.** The first draft reported `device_name` in the per-device rows.
`DoctorPwaWebAuthnTest` scans the encoded report and requires that no credential
id, public key, device name or doctor name appears in it — the estate is not
something a console report enumerates. The engine now reports device **ids**,
which an operator resolves through the permission-gated device registry, and a
new test pins that property for the new dimension too.

**One thing the schema made unreachable, stated rather than papered over.**
`sys_audit_logs.performed_at` is `NOT NULL`, so the trail cannot hold an
undateable row and the service's `proof_timestamp_unparseable` branch is
defensive rather than reachable through the repository. It is exercised through
a stub, and the test says so instead of implying the database can produce a row
it cannot.

## 11. Adversarial review — four real defects, all fixed before merge

Two independent reviewers were asked to **refute** the sprint's claims rather
than confirm them. They refuted three, and raised a fourth as a latent hole.
Every one was a genuine fail-open, and the first draft of this engine shipped
none of the protections below.

**D1 — a device could borrow a fresher assertion performed on another device.**
The repository returned one `last_at` per credential (the max across every row)
beside a flat `device_ids` union, and the service checked *membership*. A
credential whose history spans two devices therefore passed the membership test
for **both**, and then used whichever timestamp was newest. Device 3 could
report `PASS` off an assertion performed on device 7 while its own newest
assertion was months old.

It is reachable only when a credential's `doctor_device_id` changed over its
life — a device swap, a data fix, or exactly the *"migration that quietly
re-pointed a row"* the correlation check was written to catch. **The check built
for the abnormal case failed open precisely on it**, and this document's own
interface contract said that shape must be distrusted "rather than averaged
away". The code averaged it away.

Fixed structurally rather than with another check: the repository now keys
timestamps **by device** (`last_at_by_device`), so device 3 can only ever read
device 3's own latest assertion. The borrow is unrepresentable, not merely
detected. The membership test is gone.

**D2 — `rollUp([])` answered READY.** With an empty status list every `in_array`
is false and the match fell to `default => PASS`. `report()` guards against
reaching it empty, but **a guard in a different method is how a false green
survives a refactor** — and this is the empty-population defect the programme
already shipped once, rebuilt one level down. There is now an explicit
`$statuses === []` arm returning UNVERIFIED, and a test that calls `rollUp`
directly so the arm holds on its own rather than because the caller happened not
to exercise it.

**D3 — the "every failure resolves to UNVERIFIED" promise was false.** Only the
two queries were guarded; the per-device description and roll-up were not, and
`local()` throws on an unknown timezone read from the environment
(`Asia/Makasar`, one `s`, is a plausible typo). That threw straight out of
`report()`. The command's own catch masked it, so any *other* caller would have
taken the exception. `local()` now degrades to a labelled UTC string, and the
evaluation half is inside a guard resolving to `proof_evaluation_failed`.

**D4 — the binding guarantee was env-conditional, and the tests could not see
it.** With `WEBAUTHN_REQUIRE_DEVICE_BOUND=false`, `isAcceptable()` returns true
for `backup_eligible`, `unknown` and an empty verdict alike, so a syncable
credential's assertion counts as live proof. Both tests asserting otherwise pin
the config to `true` in `beforeEach` — they assert the **policy**, not the
engine. This is the single-proof-fixture shape: two tests named for a property
passing while the property is conditional.

The delegation itself is kept and is deliberate — if the deployment would let
that credential log a doctor in, then its successful assertion *is* evidence the
browser leg works, and reporting NOT_READY while logins succeed would be lying
in the other direction. What was wrong was the **claim**: the docblock said an
unacceptable verdict could never stay green, which held only in the tightening
direction. The wording now states both directions, and a test exercises the
relaxed policy so it is a recorded decision rather than a surprise. Production
runs the policy at `true`.

**Two further hardenings raised as caveats rather than defects.**
`CarbonImmutable::parse('')` returns **now** rather than throwing, so a
contract-violating repository handing back a blank value would have marked every
device freshly proven — the service depends on the *interface*, and a
fail-closed guarantee resting on a collaborator's good behaviour is not one. And
the freshness comparison was one-sided, so an assertion dated in the future
(clock skew, or a backfilled row) read FRESH forever and could never age out.
Both are now closed, with a five-minute skew tolerance that absorbs ordinary
drift without admitting a genuinely future-dated row.

**What the reviewers could not break:** the single-writer guarantee on
`DOCTOR_DEVICE_WEBAUTHN_LOGIN_SUCCESS` (the deny path writes a different
action); the exclusion of `last_used_at` (no executable read anywhere); revoked
credentials (excluded twice over — the id never even reaches the query);
inactive devices (closed status domain, no soft deletes, no global scopes);
Android proof (two independent clauses, and the Android writer uses a different
`entity_type`); one tablet dominating the estate; the unchanged `verdict` and
`--strict` semantics; and the absence of any scope mutation — the diff writes
nothing and enables nothing.

## 12. Deployed, measured — and one defect the deploy itself found

Deployed to production **2026-09-19**, `PRODUCTION_HEAD=088427fa` /
`PRODUCTION_TREE=3bcce9da`, exact-matching the merge. Health over the canonical
domain: `/login` `/health/live` `/health/ready` all **200**; migrations pending
**0**; `APP_ENV=pilot`, `APP_DEBUG=false`.

*(The NSF-9 smoke logged one warning — `/login` 404 on `http://127.0.0.1`. That
is the plain-IP probe landing on the co-tenant vhost that shares this VPS; with
a `Host:` header it is a 301 to HTTPS and 200 over TLS. A probe artifact, not a
fault.)*

**The engine's first production measurement matched the prediction locked
before the merge, device for device:**

```
device 3   2026-09-19 00:53:04 UTC / 08:53:04 WITA   0.18 d   PASS
device 5   2026-09-09 14:49:04 UTC / 22:49:04 WITA   9.60 d   STALE
device 6   2026-09-09 14:55:33 UTC / 22:55:33 WITA   9.60 d   STALE
device 7   never                                       —      NEVER_PROVEN

verdict              ARMED          (configuration — unchanged)
live_assertion_proof NEVER_PROVEN   (liveness)
scope_coverage       1/4 active devices with a fresh WebAuthn assertion
effective_readiness  NOT_READY
```

The prediction was derived by hand from production SQL **before** the engine
existed to confirm it, so the reconciliation is a genuine cross-check rather
than the scanner agreeing with itself. `READINESS_SCANNER` and
`INDEPENDENT_RECONCILIATION` **MATCH**.

### The defect the first measurement exposed

That same output carried `unverified_reason: live_proof_report_failed` beside
four correctly measured devices. Nothing had failed.

```php
$report['unverified_reason'] = $proof['unverified_reason'] ?? 'live_proof_report_failed';
```

The service returns `null` there on the **success** path, and `??` treats null
as absent — so every healthy run claimed its own failure. In a report whose
entire purpose is to stop asserting what it cannot support, that is the defect
class this sprint exists to remove, shipped by the sprint itself.

**Why the suite missed it.** Every test exercised the *service*. Nothing
asserted the shape the *command* emits — which is the thing an operator
actually reads. A defect living purely in the key mapping was invisible to a
green suite, to 16 killed mutants, and to two adversarial reviewers who were
pointed at the engine.

Fixed by making the null-vs-absent question unaskable: the payload is read only
when `$proof !== null`, in one branch, so no key can silently substitute a
fallback for a legitimate null. The sibling keys had survived only by luck —
they happen to map null to null. Three command-level tests now pin the emitted
shape (success ⇒ `unverified_reason` null; an engine-reported reason passed
through verbatim rather than overwritten; `--require-live-proof` exiting
non-zero while the estate is unproven), and the fix is mutation-checked:
restoring `??` fails the suite.

**The lesson worth keeping:** testing the engine is not testing the report. The
operator reads the command.

## 13. Durable rules

1. **WEBAUTHN-READINESS-LIVE-PROOF-INVARIANT.** Doctor WebAuthn readiness must
   not be reported READY solely because configuration is armed or
   credential/device rows exist. Readiness must incorporate current server-side
   evidence of a successfully verified WebAuthn assertion for the required
   current device scope.
2. **Android keystore login success is independent and can never satisfy the
   WebAuthn live-proof requirement.** Enforced structurally by naming one action.
3. **A historical assertion outside the configured freshness window is STALE,
   not READY** — and STALE is never FAIL either.
4. **A proof is invalidated by the path underneath it.** Revoked device, revoked
   credential, or a binding that no longer satisfies today's policy each void it,
   regardless of how recent it was.
5. **A proof belongs to the credential that signed it and the device it signed
   on.** Never borrowed sideways.
6. **`last_used_at` is not a readiness input** — no doctor attribution, and blind
   to revocation. This restates rules 156/157 rather than reopening them.
7. **A failed measurement is UNVERIFIED, never PASS**, at every level: query,
   timestamp, population, policy.
8. **An aggregate gate names its population**, and an empty population is
   UNVERIFIED rather than vacuously true.
9. **Configuration and liveness stay separate keys.** Never fold them into one
   word; that word was `ARMED` and it cost ten days.
10. **Never widen the freshness window to make a red gate green.** That inverts
    the gate.
