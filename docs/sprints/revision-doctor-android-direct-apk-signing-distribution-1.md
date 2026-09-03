# REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1

## Self-managed signing + direct admin-managed APK distribution

Supersedes the Google Play signing and distribution decision made in
[Phase 3.5](feature-doctor-trusted-android-device-lock-1-phase-3-5.md).
Decision record: [ADR 0010](../adr/0010-android-direct-apk-signing-and-distribution.md).

---

## 1. Status

| | |
|---|---|
| Revision | **Complete** — decision superseded and enforced |
| Signing authority | **Self-managed DaengtisiaMS production key** |
| Distribution | **Direct admin-managed signed APK** |
| Google Play / Managed Google Play / Play App Signing | **NOT used, not required** |
| Production signing key | **NOT PROVISIONED** — deliberately |
| Production certificate pin | **NOT PINNED** — verifier fails closed until it is |
| Real-device validation | **NOT PERFORMED** — no clinic tablet exists |
| `DEVICE_ENFORCEMENT_ACTIVE` | **false** |
| `DOCTOR_BROWSER_LOGIN_DENIED` | **false** |
| Full feature | **NO-GO** |

`feature-doctor-trusted-android-device-lock-1-go` is **not** created.

No migration. No route. No permission. No schema change. Phase 1, 2 and 3
foundations untouched.

---

## 2. What changed, and what deliberately did not

| Phase 3.5 | This revision |
|---|---|
| Play App Signing (Google KMS holds the key) | **Self-managed DaengtisiaMS key** |
| Clinic holds a *resettable upload key* | Clinic holds the **app signing key itself** |
| Managed Google Play private app | **Direct admin-managed APK install** |
| Artifact = `.aab` | Artifact = **`.apk`** |
| Pilot exception (side-load Play-signed artifact) | **Deleted** — pilot and fleet use one path |
| EMM **required** at 5 devices | EMM **advisory** past ~10; never required |
| "Halt the rollout, then forward-fix" | **Stop distribution**, then forward-fix |

**Unchanged and explicitly preserved:** release governance, versionCode
discipline, Device Owner + Lock Task, secure WebView, cryptographic enrolment,
challenge-response and replay protection, procurement requirements, pilot
acceptance gates, the device-loss runbook, and the server/client compatibility
policy. Only the Google-Play-specific assumptions were replaced.

---

## 3. Findings worth keeping

**The risk did not disappear — it moved onto the clinic, and it got worse.**
ADR 0009's argument was that an upload key is resettable and an app signing key
is not. This revision does **not** refute that; it accepts it as a cost. And the
blast radius here is larger than for a typical app, following the platform chain
all the way through: Android requires an update to be signed by the same
certificate → a mismatched APK can only be installed after an **uninstall** →
uninstall **erases app data** → app data holds the **Keystore device identity**
→ that identity *is* the DoctorDevice enrolment. So losing this key does not
merely end updates; it forces a reinstall that re-enrols **every tablet by
hand**. The governance compensates deliberately: three custodian copies instead
of two, a 90-day restore drill instead of 180.

**Play was silently enforcing half of our versionCode rule.** The platform
requires an update's `versionCode` to be **`>=`** the installed one. Play
additionally refused anything not **strictly `>`**. Phase 3.5's wording —
"Play refuses a versionCode that is not greater" — was true *of Play*, and
removing Play removed that enforcement. Two different builds could now share a
`versionCode` and Android would install one over the other without complaint,
leaving "which build is on that tablet?" unanswerable. The config now separates
`platform_version_code_rule` from `governance_version_code_rule` so nobody
mistakes ours for the platform's.

**Deleting the pilot exception was better than generalising it.** Phase 3.5
needed an exception because a self-owned Device Owner tablet has no Google
account and so could not install from Managed Google Play. With direct
installation canonical there is no mismatch to except — pilot and fleet use the
identical path. A revision that removes a special case is worth more than one
that widens it.

**The EMM-at-five rule was a distribution dependency wearing a device costume.**
It read like a kiosk-management threshold. It was not: it came entirely from
Managed Google Play being the channel. Once the channel changed it had no
justification left, so it became advisory rather than being carried forward out
of momentum.

**A term scan cannot tell "requires Play" from "Play is not used".** The prose
explaining why Play was dropped necessarily names Play — the third appearance of
the trap that bit the Phase 3 TLS guard and the Phase 3.5 read guard. The
load-bearing assertion is therefore the machine-readable negatives
(`google_play_required => false` and siblings), which prose cannot satisfy. The
term scan only requires that an active file naming a Play term also carries a
negation or supersession marker — which is exactly what a quiet reintroduction
would lack.

**The replacement for Google Play was, at first, a checksum wearing a
signature's clothes.** Security review found the CRITICAL defect of this
revision: the verifier read the expected certificate fingerprint **out of the
release manifest that ships beside the APK**. That proves self-consistency, not
authenticity. Whoever can replace the artifact replaces the manifest with it —
digest set to their build, fingerprint set to their own key — and every
remaining check (package, variant, approval, channel) is a public constant
readable in this repository. The command exited **0**, and the runbook then told
the installer to `adb install`.

The failure is worth naming precisely because it is so easy to miss: each
individual check was correct, and the *set* of checks looked thorough. What was
missing was a **trust anchor** — something the attacker cannot also rewrite. The
expected fingerprint now lives in `config/android_release.signing.
production_certificate_sha256`; the manifest's copy became a cross-check for
paperwork drift.

It is `null` today, and while it is null the verifier **fails closed** rather
than authenticating against a document that cannot vouch for itself. That is the
correct state, not a gap: there is no authority to verify against until the
production key exists. Pinning it is now an explicit Phase-4 entry step.

**A second, quieter version of the same mistake was in the runbook.** It told
the installer the command validated `versionName`, `versionCode` and
`package_name`. It does not: only the SHA-256 and the signer fingerprint are
APK-derived; the rest are manifest-declared. A false assurance given to someone
standing at a tablet with a cable in their hand is worse than a missing check,
so §2 now states exactly which properties come from the binary and which from
the paperwork.

**The superseded runbook predicted this change and set the bar for it.** Its
key-recovery section said: *"If Play App Signing were ever abandoned in favour of
self-managed signing, loss becomes unrecoverable… that change requires a new
ADR, not a decision made in a hurry."* That condition was met. The quote is kept
in the runbook because it remains the accurate description of the risk now being
carried.

---

## 4. What was built

**Governance as code**

- `config/android_release.php` — signing (self-managed, loss declared
  unrecoverable), distribution (direct APK + explicit Play negatives), device
  management (EMM advisory), versioning (platform vs governance rule split),
  new `installation` and `update_contract` sections, Play-remnant scan contract.
- `AndroidReleaseGovernanceScanner` — 4 new checks:
  `apk_is_canonical_release_artifact`, `google_play_not_required`,
  `signing_authority_is_self_managed`,
  `active_authority_acknowledges_supersession` /
  `historical_authority_marked_superseded`.
- `AndroidReleaseArtifactVerifier` + `SignerFingerprintResolver` (seam) +
  `ApksignerFingerprintResolver` — verify an APK against its manifest.
- `php artisan android:verify-release <apk> <manifest>` — non-zero exit means
  DO NOT INSTALL.

**Documents**

| Document | Answers |
|---|---|
| [ADR 0010](../adr/0010-android-direct-apk-signing-and-distribution.md) | why direct APK, what was traded, what was rejected |
| [signing governance](../governance/android-production-signing-governance.md) | who signs, kept where, what breaks |
| [direct APK installation](../runbooks/android-direct-apk-installation.md) | how Admin/IT verifies and installs |
| [release & recovery](../runbooks/android-release-distribution-and-rollback.md) | how to ship and how to recover |
| [key backup & recovery](../runbooks/android-signing-key-backup-and-recovery.md) | the unrecoverable key |
| [provisioning](../runbooks/android-clinic-device-provisioning.md) | tablet → trusted device |
| [procurement](../operations/clinic-tablet-procurement-specification.md) | what to buy |

**Rule**: `.cursor/rules/142-android-direct-apk-signing-distribution.mdc`.
Rule 141's Play clauses carry a supersession note.

---

## 5. Phase 4 entry checklist

**No Google Play setup appears anywhere in this list.**

1. Obtain one approved pilot Android tablet.
2. Verify the exact SKU against the procurement specification.
3. Designate three signing custodians in the operations register.
4. Provision the DaengtisiaMS production signing key under approved custody.
5. Create three encrypted backups (one offsite, one sealed cold).
6. Run the restore drill without exposing key material.
7. **Pin the certificate SHA-256 fingerprint** in
   `config/android_release.signing.production_certificate_sha256`. Until this
   is done `android:verify-release` fails closed, by design — there is no
   authority to verify against, and a manifest cannot vouch for itself.
8. Build the production pilot APK.
9. Sign it with the production key.
10. Generate the release manifest.
11. Verify APK SHA-256.
12. Verify the signer certificate fingerprint.
13. Prepare the authorised Admin/IT installation workstation.
14. Factory-reset the tablet.
15. Directly install the approved signed APK.
16. Establish Device Owner.
17. Enable Lock Task / kiosk.
18. **Disable USB debugging** after provisioning.
19. Run cryptographic device enrolment.
20. Approve in Master Data → Device Dokter.
21. Bind the device to the pilot branch.
22. Select a controlled pilot Doctor/UAT identity.
23. Schedule the pilot window.
24. Name the rollback/incident operator.
25. Run Phase-4 DEVFLOW.

Items 1 and 4 are the ones with real lead time. Everything else is written down
and gated.
