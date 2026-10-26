---
paths:
  - "app/Modules/DoctorDevice/**"
  - "app/Support/Android/**"
  - "android/**"
  - "config/android_release.php"
  - "config/webauthn.php"
  - "config/doctor_webauthn_live_proof.php"
  - "config/doctor_device_enforcement.php"
  - "public/sw.js"
  - "resources/js/pwa.js"
  - "resources/js/doctor-device-webauthn.js"
  - "resources/views/settings/doctor-devices/**"
  - "resources/views/settings/doctor-device-registration/**"
  - "resources/views/doctor-device-authorizations/**"
  - "tests/Feature/DoctorDevice/**"
  - "tests/Feature/DoctorDeviceWebAuthn/**"
  - "tests/Feature/Pwa/**"
---

# Doctor trusted device, Android Clinic App, signing, PWA & WebAuthn — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and
are not repeated here. Authority: code > canonical `.cursor/rules/` > this file > archive. On disagreement follow the
code, then the canonical rule, and report the drift.

Session lease, branch lock, fleet readiness and global activation live in `.claude/rules/doctor-access.md`.

## Invariants

### Device identity and registry

- **AIR-DDA-01** Treat only cryptographic possession as device identity: an Android Keystore key answering a server
  challenge, or a device-bound WebAuthn credential. Never MAC, IMEI, Android ID, serial, User-Agent, IP, cookie,
  installation UUID or standalone/install state, alone or combined. Source: `140` §4, `93` §1, `143` §5 (numbers are
  `.cursor/rules/` files in the table; `143` = the authorization rule, `143-readiness` = the runtime-identity rule).
- **AIR-DDA-02** Private keys never leave the authenticator: Keystore keys are non-exportable, never sent, logged or
  handed to the WebView (`DeviceIdentityManager.signChallenge()` returns only a signature); the server stores no
  private key, reusable device secret or biometric. Source: `140` §3, `93` §4.
- **AIR-DDA-03** Write device rows only through `DoctorDeviceService`. `status`, `identity_state`,
  `public_key_fingerprint`, `uuid` and every lifecycle stamp stay outside `$fillable`; no admin path may set
  `identity_state = cryptographically_verified` — only a server-verified Android proof writes it. Source:
  `139` §2–3, §6.
- **AIR-DDA-04** Device lifecycle is `pending_approval → active ⇄ disabled → revoked`. `REVOKED` is terminal,
  `reactivate` accepts only `disabled`, disable/revoke need a written reason, and there is no delete path (no destroy
  route, no soft delete). Revoke a lost or stolen tablet immediately; reused hardware gets a fresh identity. Source:
  `139` §4–5, `143` §7, `141` §12.
- **AIR-DDA-05** One tablet is one `mst_doctor_devices` row serving many doctors; `mst_doctor_device_authorizations`
  (`UNIQUE(doctor_id, doctor_device_id)`) decides who may use it. A WebAuthn credential belongs to the device (user
  handle = device uuid), never a doctor; the password step identifies the clinician. Source: `143` §3, `93` §12.
- **AIR-DDA-06** A device's branch is a server-resolved property of the row: an active RME-enabled branch
  (`BranchService::rmeEnabledIds()`), re-validated on create and update, canonical codes only (`TLK1`, `SPN4`, never
  `TKM1`/`SUN4`). No device, enrolment or WebAuthn path reads a branch from the request. Source: `139` §7, `93` §13.
- **AIR-DDA-07** Authorization lifecycle: `PENDING → ACTIVE → REVOKED` (terminal), `PENDING → REJECTED`. Only
  `allowReRequest` reopens a rejection, naming the one it forgives in `re_request_allowed_for_rejected_at` (never a
  "newer than" timestamp comparison). Reject and revoke need a reason. Source: `143` §6.
- **AIR-DDA-08** Keep authorities split: `view_`/`manage_doctor_devices` go to no role (Super Admin via
  `Gate::before`); `register_doctor_devices` only files a `pending_approval` tablet; `view_`/`manage_doctor_device_authorizations`
  decide doctor↔device. Never widen `DoctorDevice`'s `create` ability: `ApproveDoctorDeviceEnrollmentRequest` reuses
  it to make an active device. Source: `139` §9, `143` §8, `163` §6, `RoleSeeder` (D11, SUNU-FINAL-RELEASE-CANDIDATE).

### Android challenge protocol and app login

- **AIR-DDA-09** The signed message is `daengtisiams-device-proof|v1|<purpose>|<nonce>|<fingerprint>`, byte-identical
  in `app/Modules/DoctorDevice/Support/DeviceProofMessage.php` and `identity/DeviceProofMessage.kt`. Changing it is a
  protocol version bump on both sides; doctor login signs under its own purpose so proofs never cross-replay. Source:
  `140` §5, archive REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1.
- **AIR-DDA-10** Burn every challenge (Android nonce or WebAuthn challenge) in its own committed transaction BEFORE
  verifying the signature; a burn inside the verification transaction is rolled back by the denial and becomes an
  unlimited retry oracle. Every denial returns one opaque error; the reason goes only to `sys_audit_logs`. Source:
  `140` §6, `143` §4, `93` §6.
- **AIR-DDA-11** Status outranks cryptography: a proof succeeds only for a key-bound `active` device with an
  unexpired, unconsumed challenge issued to it, re-checked under lock; `pending_approval` trusts nothing even when
  `identity_state` is verified. Source: `140` §7, `143` §7.
- **AIR-DDA-12** `POST device-api/v1/doctor/login` order is the security property: sign → burn → verify → `Hash::check`
  without starting a session (never `Auth::attempt`) → linked active Doctor via `mst_doctors.user_id` → resolve or
  provision device → resolve or request authorization → mint a hashed, single-use, 60 s ticket only when enforcement
  is on and everything is `active`. Nothing is written before credentials pass. Source: `143` §4–5.

### Gate, session binding and containment

- **AIR-DDA-13** Only `DoctorAppLoginGate` reads `doctor.trusted_device_enforcement` and decides whether a doctor
  session may exist. Auth surfaces may reference only `DoctorAppLoginGate`, `DoctorDeviceSessionService` and
  `EnsureDoctorDeviceSession` (`authentication_coupled_only_through_the_gate`); refactor, never widen the allow-list.
  Source: `143` §2, `93` §19, `139` §1 (narrowed).
- **AIR-DDA-14** Browser denial comes from the ABSENCE of a server-verified device binding and is re-checked on every
  protected request. An enforced doctor has no password-only fallback except an active, time-bounded `DoctorBreakGlassGrant` for that
  `users.id` (re-checked on every request), and the authenticated session is torn down
  before the pending marker is written. Source: `143` §9, `93` §17–18, `150`.
- **AIR-DDA-15** A session records the proof that authenticated it: `DoctorDeviceSessionService::bind()` takes a
  required `DoctorSessionProof` (`android_keystore`, or `webauthn` + credential row id). Never infer it from what the
  device holds; an unknown or absent `proof_type` is denied; login and per-request share `deviceProofDenyReason()` /
  `deviceUsableForProof()`. Source: `93` §27–37.
- **AIR-DDA-16** No proof substitution on a dual-proof tablet: an Android key never keeps a WebAuthn session alive, a
  WebAuthn credential never redeems an Android ticket, and Android sessions survive the WebAuthn flag and credential
  revocation. Containment tests use a dual-proof fixture and assert the deny CODE. Source: `93` §29, §32, §38, §51.
- **AIR-DDA-17** `doctor.pwa_webauthn_device_login` only admits, and only while `doctor.trusted_device_enforcement` is
  on. Switching it off ends an open WebAuthn session on its NEXT protected request (`webauthn_login_disabled`),
  revokes nothing. Rollback = both flags off + config-cache rebuild as `daengtisiams`, never unwinding the scope.
  Source: `93` §22, §45–54, §70.
- **AIR-DDA-18** A WebAuthn session belongs to one credential row, looked up by id (`firstWhere('id', …)`, never
  `first()`). Revoking it denies that session next request (`webauthn_credential_not_usable`) and touches neither
  device nor authorization. Revocation is terminal; recovery is a NEW conforming credential; revoked rows stay out of
  `excludeCredentials`. Source: `93` §55–65.

### WebAuthn ceremonies, readiness and the PWA-only policy

- **AIR-DDA-19** Refuse syncable passkeys: with `require_device_bound` (`WEBAUTHN_REQUIRE_DEVICE_BOUND`, default true)
  a `backup_eligible` or unknown credential is rejected; `platform` attachment is not device-bound and BS is never the
  gate. `WebAuthnDeviceBinding::isAcceptable()` is re-checked per request. The requirement fails CLOSED: one parser
  (`resolveRequirementFromEnv`, relaxes only on a boolean false or the exact string `0`; a quoted `'false'` stays
  REQUIRED) and one reader (`isRequired()`, relaxes only on a genuine boolean false); nothing else reads the config
  key. On both assertion paths (doctor, Front Office) the SIGNED BE bit must equal the registered one and satisfy the
  policy: `assertionDenialReason()` after the signature verifies, before any write (the library only dispatches an
  event for a BE change). `webauthn:readiness` reports `require_device_bound_source` (a code, never the raw value);
  `--strict` fails on a blank or unparseable value. Source: `93` §2–3, §62, §67; `208`.
- **AIR-DDA-20** Ceremonies: one-time expiring challenge, user verification `required`, exact origin list, crypto only
  from `web-auth/webauthn-lib`, bound to a session-data token (never `session()->getId()`). Test with a real software
  authenticator; device-binding claims need the real tablet. Source: `93` §5–10, §24–26.
- **AIR-DDA-21** Every browser ceremony is bounded by an `AbortController` timeout and ends in success or a visible
  message; each reason code in `resources/js/doctor-device-webauthn.js` has view wording, and `NotAllowedError` is
  never claimed to mean "no credential". Layouts rendering an auth page render `@stack('scripts')`; test the delivered
  response, not the template. Source: `93` §40–43, §74–76.
- **AIR-DDA-22** `webauthn:readiness` VERDICT=ARMED means configured, never live. Liveness comes only from
  `DOCTOR_DEVICE_WEBAUTHN_LOGIN_SUCCESS` in `sys_audit_logs` within `freshness_window_days` (owner-decided; never
  widen it). Android success never counts, empty or erroring inputs are `UNVERIFIED`, and the command fails closed
  unless `--report-only`. Source: `93` §80–98.
- **AIR-DDA-23** Android Keystore and WebAuthn are independent proofs sharing only the gate and binding; Android
  working is never evidence the PWA works. Keep the `/device-api/v1/*` path and APK intact: Android retirement stays
  blocked until a real-hardware WebAuthn assertion and the resulting PWA session are proven. Source: `93` §21, §77–79.
- **AIR-DDA-24** Ask generic device eligibility through `DoctorDeviceIdentityProofPolicy`
  (`PROOF_ANDROID_KEYSTORE` | `PROOF_WEBAUTHN_DEVICE_BOUND` | `PROOF_NONE`), never by reading `cryptographically_verified`
  (only Android enrolment writes it); credential usability lives only in `DoctorDeviceCredentialUsabilityPolicy`.
  Source: archive REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1, `163` §11–12.
- **AIR-DDA-25** "PWA-only" is a product access policy, never an auth signal: a crafted password-login request for an
  enforced doctor is denied by the server. The server cannot tell Chrome from the installed PWA (identical user
  agent), so record that split as operator attestation, never as a server fact. Source: archive
  REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1, `93` §44, `151` MD-R12.

### Enforcement scope and the pilot

- **AIR-DDA-26** `AndroidDoctorEnforcementScope` only narrows. Coverage is an explicit list of `users.id`
  (`ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_IDS`, unioned with the singular key) — no wildcard, role, branch or "all";
  one unreadable entry voids the whole cohort; a non-Doctor id covers nobody; `unscoped` without `global_permitted`
  covers nobody. Source: `150`, `151` MD-R1, MD-R5, MD-R8.
- **AIR-DDA-27** `enforcement.scope.global_permitted`, `pilot_cohort_maximum`, `governance_phase` and
  `expected_posture` live in `config/android_release.php`, which never reads the environment — do not even write the
  environment-read call spelling there. Keep the ceiling below the count of Doctor accounts so listing the fleet
  covers nobody. Source: `150`, `151` MD-R3, `93` §69, code comment on `pilot_cohort_maximum`.
- **AIR-DDA-28** Readiness before enforcement: active device, active authorization and a usable, user-verified,
  device-bound credential before an id joins the cohort. Order: set scope → `config:clear && config:cache` as
  `daengtisiams` → verify `android:phase4a-pilot-scope` → arm. Membership creates and revokes nothing; narrowing the
  cohort, never device revoke, removes a doctor. Source: `150`, `151` MD-R4, MD-R6, MD-R10, MD-R14.
- **AIR-DDA-29** Watermark each physical ceremony on the maximum `sys_audit_logs.id` taken just before it. Revoke any
  credential whose physical origin is uncertain (AAGUID is all-zeros). Source: `151` MD-R11, MD-R13.
- **AIR-DDA-30** Phase 4A is non-destructive: never factory-reset, remove accounts from, or set Device Owner on an
  in-service tablet. The six Device-Owner/lock-task checks are deferred (proven against
  `scanner.dedicated_device_kiosk_checks`), never deleted; screen pinning is never a security control. Source: `150`,
  `141` §8, §16, `140` §9.

### Signing and release

- **AIR-DDA-31** Exactly one permanent production signing key exists. Never generate a second — not to rotate,
  replace a suspect key or green a gate: a new signer forces an uninstall that erases every tablet's Keystore identity
  and enrolment. If anything looks wrong, STOP and report. Source: `147` §1, `149` §1.
- **AIR-DDA-32** Keystores, `.p12`/`.jks`, passwords and passphrases never enter git (checked against the index), CI,
  the VPS, logs, docs or chat. Signing is a human-only ceremony in the custodian's closed-at-rest LUKS2 vault, swap
  off; never ask for or script around passphrases; CI never signs. Source: `141` §3, §5, `145`, `146`, `147` §5,
  `149` §4.
- **AIR-DDA-33** RECORDED ≠ PINNED: `signing.production_certificate_sha256_recorded` (fact) and
  `signing.production_certificate_sha256` (trust anchor) stay two fields, compared only through
  `AndroidCertificateFingerprint` (never merge `isPresent()` into `isValid()`; colon form rejected). The verifier
  never falls back to the recorded value or the manifest; changing the pin is a signer rotation. Source: `147` §3,
  `148` §2–4, `142` §5a.
- **AIR-DDA-34** Distribute a signed APK (never `.aab`) installed by authorised Admin/IT; Google Play, Managed Google
  Play and Play App Signing are not used (a new ADR is required to change that). Run `android:verify-release <apk>
  <manifest>` before every install (non-zero = do not install); evidence extracts the signer from the signed APK
  (`ApksignerFingerprintResolver`). Source: `142` §1, §4–5, `149` §2.
- **AIR-DDA-35** `versionCode` strictly increases (our rule; the platform only needs `>=`). There is no production
  downgrade: rollback is stop distribution, then forward-fix under a higher code. Never uninstall or clear app data to
  cure a signer mismatch — stop and escalate. Source: `142` §6–9, `141` §6.
- **AIR-DDA-36** A release build never references debug signing (`release_not_debug_signed`) and every
  `proguardFiles` it names must exist (`release_proguard_files_present`). The manifest's `release_source_sha` /
  `release_source_tree` name the tree that built the APK; the later evidence commit must never pose as the build
  source. Source: `141` §4, `149` §3, §5.
- **AIR-DDA-37** Claim "hardware-backed" only from `KeyInfo.getSecurityLevel()` on the generated key:
  `TRUSTED_ENVIRONMENT` or `STRONGBOX` with `privateKeyExportable=false`; reject `SOFTWARE`/`UNKNOWN_SECURE`.
  Capability flags and emulators prove nothing; StrongBox is preferred, never mandatory; commit a logical label, never
  a serial/IMEI/MEID/ICCID/Android ID. Source: `144`.
- **AIR-DDA-38** `android:release-readiness` must reach GO as `daengtisiams`; answer git's ownership refusal only with
  an invocation-scoped `-c safe.directory=<repo>` (never `*`, never persistent, never chown); other git failures FAIL
  with reason `index_access_error`. Its GO is repository governance only, never an installed or enforced build;
  forbidden literals live in config. Source: `143-readiness`, `141` §14–15, `149` §7.

### PWA layer and guided registration

- **AIR-DDA-39** `public/sw.js` is deny-by-default: cache only the `PWA-CACHE-ALLOWLIST` (`/build/`, `/pwa/`,
  `/assets/brand/`, `/offline.html`, `/manifest.webmanifest`, `/favicon.ico`), `cache.put()` only inside
  `putIfAllowed()`, navigations network-first and never cached, no denylist, no `skipWaiting()`; bump `CACHE_VERSION`
  and purge only `daengtisiams-` keys. Clinical data stays online-only: never in Cache Storage, IndexedDB or
  `localStorage`, never a queued write. Source: `92`, `93` §20.
- **AIR-DDA-40** `/settings/doctor-device-registration` is a guide: steps post to the routes that own the mutation
  (`settings.doctor-devices.*`); its one new mutation can only file a PENDING authorization; return-to-workflow is the
  boolean `registration_workflow`, never a URL. No stored `wizard_status`; every gate is evaluated, `UNVERIFIED` is
  never green, and a disabled WebAuthn switch reads `UNVERIFIED` (`webauthn_login_disabled`), not a device defect,
  while the verdict stays `NOT_READY`. Source: `163`.
- **AIR-DDA-41** Break-glass is created only by `DoctorBreakGlassService::grant()`, which re-asserts
  `grant_doctor_break_glass_access`. Each grant names one account, needs a written reason and is bounded by
  `config/doctor_access.php` `break_glass` (maximum hours, minimum minutes). No shared password, no secret in a URL, and
  a grant never switches enforcement off: for a lost tablet grant break-glass to that doctor, never disarm the flags for
  the fleet. Revoking a grant ends its open session on the next request. Source: archive
  REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1 (Stages 2–3); `DoctorBreakGlassService`, `DoctorAppLoginGate`.

## Current posture (snapshot 2026-10-25 — verify before relying on it)

- Last recorded production state: `doctor.trusted_device_enforcement` and `doctor.pwa_webauthn_device_login` both ON,
  scope mode `pilot` (assertion proven 2026-09-19). Verify as `daengtisiams` with `android:phase4a-pilot-scope`,
  `webauthn:readiness --report-only` and `foundation:feature-flags`.
- Cohort: canonical rules record a three-doctor pilot (SPN4, LDK2, ATG3). On 2026-09-20 the source ceiling
  `pilot_cohort_maximum` was raised 5 → 12 (D10 of DAENGTISIAMS-SUNU-FINAL-RELEASE-CANDIDATE-1, "PWA-only, Cabang Sunu
  first"); rule 151 still says 5. Whether the host cohort was widened is not recorded — read it, never assume it.
- Source-controlled and verified in config: `global_permitted=false`, `governance_phase=phase_4a`,
  `expected_posture=bounded_pilot`. Stage 4 (scope widening) is unauthorised while `spare_device_available_per_branch`
  measures FAIL; Android doctor auth is RETAINED and its retirement blocked.
- `owner_signoff.pilot_activated=false` and the `phase_4a.activation_boundary` literals in `config/android_release.php`
  record the preparation sprint, not live state; read the measured `GLOBAL_ENFORCEMENT_ACTIVE_LIVE`. That command
  (`android:phase4a-pilot-readiness`) FAILs on `enforcement_inactive` while a pilot is live — expected; the running
  pilot's gate is `android:phase4a-pilot-scope`.
- Signing: one production key exists, custody at `recovery_verified`, certificate pinned; the first production APK
  `0.3.0-phase3` (versionCode 1) is signed and verified. Open custody caveat: one party can reach every copy; a second
  independent human custodian is owed before `operational`.
- No record of Phase 4B (Device Owner / kiosk) on any tablet; it needs a dedicated, factory-fresh device.
- Related posture owned by `doctor-access.md`: `single_active_session=true`, `branch_lock=false`.

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/92-pwa-foundation.mdc` | Service-worker cache allowlist |
| `.cursor/rules/93-doctor-pwa-webauthn.mdc` | WebAuthn, proof binding, kill switch, revocation, live proof |
| `.cursor/rules/138-doctor-room-scoped-access-and-print-deny.mdc` | Phase 1 room scope, print denial |
| `.cursor/rules/139-doctor-device-registry.mdc` | Device registry lifecycle |
| `.cursor/rules/140-android-clinic-device-identity.mdc` | Keystore identity, challenge protocol, WebView |
| `.cursor/rules/141-android-production-release-governance.mdc` | Release governance, 4A/4B split, enforcement ladder |
| `.cursor/rules/142-android-direct-apk-signing-distribution.mdc` | Direct APK, no Google Play, versionCode |
| `.cursor/rules/143-android-release-readiness-runtime-identity.mdc` | Readiness gate as runtime user |
| `.cursor/rules/143-doctor-device-authorization-and-app-only-login.mdc` | Authorization, app-only gate, login ticket |
| `.cursor/rules/144-android-real-device-keyinfo-preflight.mdc` | KeyInfo hardware proof |
| `.cursor/rules/145-android-production-signing-custody-readiness.mdc` | Custody destinations |
| `.cursor/rules/146-android-signing-custodian1-encrypted-vault.mdc` | LUKS2 signing vault |
| `.cursor/rules/147-android-production-signing-key-provisioned.mdc` | One permanent key; recorded vs pinned |
| `.cursor/rules/148-android-production-certificate-pinned.mdc` | Pinned certificate anchor |
| `.cursor/rules/149-production-android-release-apk-evidence.mdc` | Release source vs evidence commit |
| `.cursor/rules/150-phase4a-doctor-android-non-destructive-pilot.mdc` | Non-destructive pilot, scope before arming |
| `.cursor/rules/151-doctor-pilot-enforcement-cohort.mdc` | Ceilinged user-id cohort |
| `.cursor/rules/163-doctor-device-guided-registration-workflow.mdc` | Guided device registration |
| `.cursor/rules/208-doctor-webauthn-device-bound-fail-closed.mdc` | Device-bound requirement fails closed; signed BE check |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/07-doctor-access-device.part1.md`,
`docs/sprints/claude-md-archive/07-doctor-access-device.part2.md`,
`docs/sprints/claude-md-archive/07-doctor-access-device.part3.md`. Grep by sprint id; never read a whole file.
Contracts: `docs/architecture/doctor-pwa-webauthn.md`, `docs/architecture/pwa-foundation.md`, ADR 0010.
