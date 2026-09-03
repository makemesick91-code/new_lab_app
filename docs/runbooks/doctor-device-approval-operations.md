# Runbook — Approval Device Dokter

Who this is for: Super Admin and Supervisor RME.
Sprint: `REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1`.

## Read this first

**Device enforcement is OFF.** Approving or refusing a request changes nothing
about how a doctor logs in today — they keep using the browser exactly as
before, and a request left pending blocks nobody. The queue is being populated
now so that a future Phase 4 pilot has real data to switch on against.

That also means: **do not approve a request you cannot account for.** An
approval that is wrong today is a trusted tablet on the day enforcement is
enabled.

## Where things live

| Screen | Purpose | Who |
|---|---|---|
| Approval → **Approval Device Dokter** | "May this doctor use this device?" | Super Admin, Supervisor RME |
| Master Data → **Device Dokter** | The physical device registry and its security lifecycle | Super Admin |

They are deliberately separate. The first is a daily operational queue; the
second is device security administration.

## How a request appears

The doctor opens the Clinic App, enters their username and password, and the app
signs a server-issued challenge with the key held in the tablet's Android
Keystore. If the signature verifies **and** the credentials are correct **and**
the account is a linked, active doctor, an entry appears here.

Nothing is created for a wrong password, a device that cannot sign, or an account
that is not a doctor. So every row in this queue represents a real doctor on
real hardware that proved itself.

## Approving

1. Open the request. Check the four things a decision actually needs:
   * the **doctor** — is this a doctor who works at this clinic?
   * the **device** — model, and the short key fingerprint;
   * the **branch** — is this tablet where it should be?
   * the **time** — does it match a doctor who told you they were setting up?
2. If the branch is wrong, fix it first in Master Data → Device Dokter
   (Edit → branch), then come back. The branch of a brand-new tablet is
   auto-filled from the doctor's own practising branches, and a doctor who works
   at several will get the first one — that is a placement, not a permission.
3. Press **Setujui**.

Approving does two things in one action: the doctor is authorised, and the
device is admitted to the registry if it was still *Menunggu Persetujuan*.

If the doctor was deactivated, or the device disabled or revoked, since the page
was loaded, the approval is refused with a message. That is deliberate — the
decision is re-checked at the moment you press the button, not at the moment the
page rendered.

## Refusing

**Tolak** requires a written reason. Use it when the device is not clinic
property, when the branch does not make sense, or when you cannot account for
the request.

A refused pair **stays refused**. The doctor tapping login again will not
re-queue themselves — the app tells them to contact you.

## Letting a refused doctor try again

If the refusal was a mistake, or the situation changed, open the request and
press **Izinkan Ajukan Ulang**. This does not approve anything: it makes the
pair requestable again. The doctor logs in from that device once more, a fresh
PENDING request appears, and you decide again.

The original refusal and its reason stay on the record. Nothing is erased.

## Revoking

**Cabut Akses** on an approved pair, with a reason. Use it when a doctor leaves,
moves branch, or a tablet is lost.

Revocation is **terminal**. It cannot be undone by approving again — a new
request from the device is required. When enforcement is eventually on,
revoking also ends the doctor's session on their **next request**, not at their
next login.

## Losing a tablet

Revoking the authorization stops that doctor on that tablet. To stop **every**
doctor on it, revoke the **device** in Master Data → Device Dokter. See
`docs/runbooks/android-device-loss-replacement-and-decommission.md`.

## What you will never see here

Private keys, pairing codes, passwords, full key fingerprints, KTP/NIK, or
anything clinical. An approval decision does not need them.

## Checking the enforcement state

```
php artisan android:release-readiness --strict
```

Look for `enforcement_off` = PASS. It reads the real feature flag, so it cannot
report an off state the application does not have.

To switch enforcement on — **Phase 4 only, after a real-device pilot** — set
`FEATURE_DOCTOR_TRUSTED_DEVICE_ENFORCEMENT=true` and clear the config cache.
Before that: every enforced doctor needs an active approved device, every branch
needs a spare, the device-loss runbook must be rehearsed, and rollback to
browser login must be proven. Turning it on without those is how a clinic loses
a day.
