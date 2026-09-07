# BUGFIX-ANDROID-LOGIN-NULL-PARSING-ERROR-MAPPING-1

**Status:** NOT STARTED — booked, not begun. Do not start during
`PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1`.

**Why it exists:** the activation sprint shipped a server-side compatibility fix
so the already-installed v0.3.0-phase3 client behaves. That fix is correct on its
own terms, but the client defects it works around are still in the APK on the
tablet. This sprint fixes them at the source.

---

## The two defects, both found on the real pilot tablet

### 1. JSON null is parsed as the string `"null"`

`org.json.JSONObject.optString(name)` returns the literal four-character string
`"null"` for a JSON null; it returns the `""` fallback only when the key is
**absent**. So `optString(k).ifBlank { null }` never produces null for a
nullable field the server sends as JSON null.

`"login_ticket": null` — which is the normal response whenever device
enforcement is off — became the 4-character ticket `"null"`.
`DoctorLoginStateMachine` then took the *approved AND enforcement on* branch and
navigated to `/device-login/null`, a 404, from a login that had entirely
succeeded (authorization `active`, device `cryptographically_verified`).

This defeats a fail-closed property: a client holding **no** ticket was routed
down the branch reserved for holding one. Nothing was granted, because the
server mints no session without a real ticket — but the check was not checking
what it believed.

### 2. Every non-2xx and every exception is reported as "offline"

`EnrollmentApi.readJson` maps any non-2xx to `null`, and `post`/`get` wrap
everything in `runCatching{}.getOrNull()`. `DoctorLoginStateMachine.resolve(null)`
returns `OFFLINE`, which renders *"Tidak dapat menghubungi server."*

On the pilot tablet that single message stood for, on three separate attempts:

| Attempt | Server said | Tablet said |
| --- | --- | --- |
| 00:27:00 | `422` FormRequest validation failure | cannot contact server |
| 00:27:41 | `422` FormRequest validation failure | cannot contact server |
| 00:47:27 | `422` `invalid_credentials` | cannot contact server |

None of those was a network failure. Every request reached the server and was
answered. Diagnosis took an access-log correlation that an operator in a clinic
cannot do.

---

## Required output

| Field | Value |
| --- | --- |
| applicationId | `com.daengtisia.clinic` (unchanged, permanent) |
| versionCode | `2` (strictly higher) |
| versionName | `0.3.1` (recommended) |
| signer | `79db269b7cd38e920b80efbcf2f59142721f1e57924d3048d07a862f34fea2d9` |

Same applicationId, same permanent production signer, strictly higher
versionCode — so it is an **update in place**. Uninstall and clear-data are not
upgrade steps: they destroy the Android Keystore device identity and the
enrolment with it.

## Scope

1. Introduce a nullable-safe JSON helper that consults `isNull()`/`has()` before
   `optString`.
2. Audit **every** nullable field `EnrollmentApi` reads, not only
   `login_ticket`.
3. Distinguish, explicitly and in tests: absent · JSON null · `""` · the literal
   string `"null"`.
4. Remove every security-sensitive ambiguity that survey finds.
5. Fix the response and error mapping.

### Required user-visible mapping

| Condition | Screen |
| --- | --- |
| 2xx success | the actual success state |
| FormRequest validation failure | validation / input message |
| `invalid_credentials` | login failure message |
| pending authorization | awaiting approval |
| rejected / revoked / disabled | the matching access-denied state |
| network exception, timeout, DNS, TLS | "Tidak dapat menghubungi server" |
| HTTP 5xx | server / service error |

**Do not map all non-2xx to OFFLINE.**

### Required regression vectors

JSON null · field absent · `""` · literal `"null"` · malformed JSON ·
401 · 403 · 422 · 429 · 5xx · transport exception · timeout · challenge signing
failure.

## Relationship to the server-side shim

The activation sprint made `DoctorDeviceApiController::doctorLogin` **omit**
`login_ticket` and `login_ticket_expires_at` when no ticket is minted, instead of
sending JSON null.

That is not a hack to be reverted: absence is what is true, and a corrected
client should read it the same way. It is covered by
`DoctorAppLoginTicketAbsenceCompatibilityTest`, which models the shipped client's
parsing so the property is provable in CI rather than only on a tablet.

What this sprint must NOT do is leave the client depending on that shape for its
correctness. After it lands, the client must read absence, JSON null, `""` and
`"null"` all as "no ticket", so the two sides are independently correct.
