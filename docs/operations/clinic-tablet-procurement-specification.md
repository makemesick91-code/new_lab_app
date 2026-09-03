# DaengtisiaMS clinic tablet — procurement specification

**FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3.5.**
Machine-readable form: `config/android_release.device_specification`.
Related: [ADR 0009](../adr/0009-android-production-signing-distribution-and-device-management.md) ·
[provisioning runbook](../runbooks/android-clinic-device-provisioning.md).

> **Nothing has been ordered.** This is a specification and a dated candidate
> list, produced so procurement can start. Prices are not recorded here on
> purpose — they age badly and this document is meant to stay true.

---

## 1. Why these numbers

Every requirement below traces to something the app actually does. A spec that
cannot answer "why?" turns into a shopping list somebody negotiates down.

| Requirement | Because |
|---|---|
| Hardware-backed Keystore | The device identity is an EC keypair whose private half must be non-exportable. Software-backed key storage makes the identity copyable, which defeats the entire feature. |
| Device Owner capable | Kiosk is Lock Task under Device Owner. Screen pinning is user-dismissible and is not a security control. |
| Android 13+ | `minSdk` is 26, but 13+ is what still receives security updates and what Android Enterprise Recommended currently covers. |
| ≥10" screen, ≥4 GB RAM | The RME workspace and odontogram are dense server-rendered pages in a WebView. A phone-sized screen makes the odontogram unusable. |
| Stylus (recommended) | Handwriting RME is the **primary** doctor-facing clinical input. A finger is a poor pen. |
| Published security-update end date | An OEM that will not commit in writing to an end date has not committed to anything. |

---

## 2. Authority: Android Enterprise Recommended

AER is the only vendor-neutral programme that binds an OEM to a published
security-update cadence and end date. Anchor procurement to it.

Live directory: <https://androidenterprisepartners.withgoogle.com/devices/>

Devices are **archived** from AER when an OEM stops meeting update requirements,
so the directory must be checked **at purchase time**. That is exactly why no
model list is frozen into `config/android_release.php` — a model list in config
becomes a procurement trap the day a device is archived.

AER knowledge-worker minimums, retrieved 2026-09-03:

| | Android 13 | Android 14–16 |
|---|---|---|
| RAM | 3 GB (4 GB recommended) | 6 GB |
| Storage | 32 GB | 32 GB |
| CPU | 1.4 GHz | 1.4 GHz |
| Architecture | 64-bit | 64-bit |
| Encryption | by default | by default |
| Security updates | 90-day cadence, end date published | same |
| OS upgrades | current + 1 major | current + 1 major |

---

## 3. Specification

### 3.1 Minimum — do not buy below this

| Attribute | Requirement |
|---|---|
| Android version | 13 or newer, still in security support |
| RAM | 4 GB |
| Storage | 32 GB |
| CPU | 1.4 GHz, 64-bit |
| Screen | 10.0" |
| Encryption | enabled by default |
| **Hardware-backed Keystore** | **required** |
| **Device Owner capable** | **required** |
| Security updates | 90-day cadence, end date published by the OEM |
| Wi-Fi | 802.11ac (5 GHz) |
| Battery | full clinic session on one charge |

### 3.2 Recommended — the fleet target

| Attribute | Target |
|---|---|
| Android version | 14+ |
| RAM | 6 GB |
| Storage | 128 GB |
| Screen | 11", ≥1920×1200 |
| **Stylus** | **yes** — handwriting RME |
| StrongBox | preferred, **not mandatory** |
| Battery | 8+ hours |
| Case | protective, wipe-clean |
| Support | local Indonesian warranty |

### 3.3 Pilot device — the one to buy first

Recommended target spec, plus:

- listed in the AER directory **on the day of purchase**
- stylus (validates the primary clinical input path)
- local warranty and replaceable in-country
- a model the clinic would be willing to standardise on

### 3.4 StrongBox

Requested **opportunistically** by the app, with fallback to the TEE-backed
Keystore. Preferred, never mandated: many perfectly good clinic tablets have no
StrongBox chip, and a hard requirement would refuse acceptable hardware for a
marginal gain.

Whatever the pilot device reports — StrongBox, TEE, or software — gets **recorded
truthfully** in the Phase 4 acceptance evidence. Never assumed.

---

## 4. Candidate shortlist — retrieved 2026-09-03

**Verify every row against the AER directory and a local distributor before
purchase.** These are families known to appear in AER and to be distributed in
Indonesia; specific SKUs vary by region and are archived over time.

| # | Family | Why it is a candidate | Verify before buying |
|---|---|---|---|
| 1 | **Samsung Galaxy Tab S series** (S9/S10 FE or newer) | Included stylus, strong Knox/AER track record, widest Indonesian service network | Current AER listing; exact SKU sold locally |
| 2 | **Samsung Galaxy Tab A series** (A9+/A11 or newer) | Lowest-cost route that still clears the minimum spec | Meets RAM/screen minimum; stylus usually **absent** |
| 3 | **Lenovo Tab P series** | Frequent AER listings, competitive pricing, stylus on some SKUs | AER listing for the exact SKU; update end date |
| 4 | **Samsung Galaxy Tab Active series** | Rugged, IP-rated — worth it only if the clinic environment justifies it | Cost premium vs. actual need |

Deliberately **not** recommended:

- consumer tablets with no AER listing and no published update end date
- any device that cannot be set as Device Owner (some carrier-locked variants)
- Android 12 or older
- tablets under 10"

### Honest limits of this shortlist

Indonesian retail availability, current pricing and per-SKU AER status could not
be verified from primary sources during Phase 3.5. These are **candidate
families**, not validated purchase decisions. The buyer must confirm, for the
exact SKU, on the day: AER listing, published security-update end date, Device
Owner capability, and stylus support.

---

## 5. Quantity

**Phase 4 pilot: one device.** One is enough to validate production signing,
real Keystore behaviour, hardware-backed storage, Device Owner, Lock Task, boot
behaviour, WebView performance, network reconnect, enrolment, branch and room
scoping, revocation and rollback. Buying five before any of that is proven buys
five of whatever is wrong.

Fleet sizing, once the pilot passes:

```
per branch = concurrent Doctor stations + 1 spare
```

The `+1` is not optional — see the
[device loss runbook](../runbooks/android-device-loss-replacement-and-decommission.md) §3.

At **≥5 devices**, hand-provisioning stops being defensible and the EMM /
Managed Google Play model applies
(`config/android_release.device_management.scale_model_required_at_devices`).

---

## 6. Acceptance on arrival

Before a device enters the pilot:

- [ ] model matches the approved specification
- [ ] AER listing confirmed for this SKU
- [ ] published security-update end date recorded
- [ ] factory sealed, no prior accounts
- [ ] Android version at or above minimum
- [ ] `adb shell dpm set-device-owner` succeeds on a factory-reset device
- [ ] hardware-backed Keystore confirmed — **recorded truthfully**, whatever it says
- [ ] StrongBox availability recorded (either way)
- [ ] stylus present and usable in the RME handwriting canvas
- [ ] serial recorded in the asset register
- [ ] warranty terms recorded

Full acceptance criteria for the pilot itself are in the
[Phase 3.5 sprint doc](../sprints/feature-doctor-trusted-android-device-lock-1-phase-3-5.md).

---

## 7. Asset register

Per device: serial, model, purchase date, warranty end, assigned branch,
registry device id, status, security-update end date, last spare check.

The serial is what a police report needs, and nobody can find it after the
device is gone.
