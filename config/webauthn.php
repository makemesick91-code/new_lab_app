<?php

/*
|--------------------------------------------------------------------------
| DOCTOR-PWA-WEBAUTHN-1 — relying party configuration
|--------------------------------------------------------------------------
|
| WHAT A RELYING PARTY ID ACTUALLY BUYS
|
| The RP ID is the only thing standing between "a doctor tapped their tablet"
| and "something on another origin asked their tablet to sign". An authenticator
| will not release an assertion to an origin whose registrable domain does not
| match the RP ID the credential was created under, so getting this value wrong
| does not fail loudly at the RP — it fails at the browser, on the tablet, in a
| clinic, at the moment a doctor needs their patients.
|
| It is therefore DERIVED from APP_URL by default rather than typed twice. A
| hand-typed RP ID that drifts from the origin the PWA is actually served from
| is the single most common way this feature breaks after a domain change.
|
| WHY ORIGINS ARE A LIST AND THE RP ID IS NOT
|
| One deployment answers on exactly one registrable domain, but may legitimately
| be reached on more than one origin during a transition. The RP ID stays
| singular because a credential is bound to one; the allowed-origin list is
| explicit because "any origin under this domain" is not a security statement.
|
| NOTHING IN THIS FILE IS A SECRET.
|
| There is no key, no token and no credential here — WebAuthn's whole point is
| that the private key never leaves the authenticator, so the relying party has
| nothing worth hiding. What it does have is values that must be RIGHT, which is
| why `WebAuthnRelyingParty` validates them at runtime and refuses to serve a
| ceremony from a configuration that could not possibly work.
|
*/

return [

    /*
    | The relying party. `id` must be the registrable domain (or a parent) of
    | every allowed origin. Null means "derive from APP_URL", which is the
    | intended production posture.
    */
    'relying_party' => [
        'id' => env('WEBAUTHN_RP_ID'),

        'name' => env('WEBAUTHN_RP_NAME', 'DaengtisiaMS'),

        // Comma-separated. Empty means "just the APP_URL origin".
        'allowed_origins' => env('WEBAUTHN_ALLOWED_ORIGINS', ''),

        /*
        | Localhost is the one origin the WebAuthn spec treats as a secure
        | context without TLS, which makes it the only way to exercise a real
        | ceremony in development. It is refused outright when the application
        | environment is production-like, so this cannot become a production
        | bypass by being left on.
        */
        'allow_insecure_localhost' => (bool) env('WEBAUTHN_ALLOW_INSECURE_LOCALHOST', false),
    ],

    'ceremony' => [
        /*
        | User verification. `required` means the authenticator must have
        | verified a HUMAN — biometric or device PIN — not merely that a device
        | was present. A doctor tablet is shared and often unattended between
        | patients, so presence alone is not the property we want.
        |
        | Deliberately not configurable down to 'discouraged' by environment:
        | see WebAuthnRelyingParty::userVerification(), which refuses any value
        | outside the permitted set rather than silently downgrading.
        */
        'user_verification' => env('WEBAUTHN_USER_VERIFICATION', 'required'),

        /*
        | Platform attachment: the credential must live in the authenticator
        | built into the device in front of the doctor, not on a roaming key
        | that could be carried to any browser. This is a REQUEST, not a proof
        | — see the device-binding policy below for the part that is checked.
        */
        'authenticator_attachment' => env('WEBAUTHN_AUTHENTICATOR_ATTACHMENT', 'platform'),

        /*
        | Seconds a challenge stays usable. Short enough that a captured
        | challenge is worthless before it can be replayed against a doctor,
        | long enough for a biometric prompt on a slow tablet.
        */
        'challenge_ttl_seconds' => (int) env('WEBAUTHN_CHALLENGE_TTL_SECONDS', 120),

        // Milliseconds handed to the browser as the ceremony timeout.
        'timeout_milliseconds' => (int) env('WEBAUTHN_TIMEOUT_MILLISECONDS', 90000),

        // Bytes of CSPRNG entropy per challenge. 32 is the spec's floor for
        // "cryptographically random"; there is no reason to go below it.
        'challenge_bytes' => 32,
    ],

    /*
    |----------------------------------------------------------------------
    | Device binding — the part that decides whether this is a TRUSTED DEVICE
    |----------------------------------------------------------------------
    |
    | This is the whole reason the sprint exists, so it is worth being blunt:
    |
    |   authenticatorAttachment = "platform"  DOES NOT MEAN  device-bound.
    |
    | A modern platform authenticator is very often a SYNCED PASSKEY. The
    | private key is generated on the tablet, is non-exportable from that
    | tablet's hardware, AND is also present in the doctor's Google account, on
    | their phone, and on any device they sign into. Attachment describes where
    | the ceremony happened; it says nothing about how many devices hold the
    | key.
    |
    | The property that does say something is the pair of flags in the
    | authenticator data:
    |
    |   BE (Backup Eligible) — the credential MAY be synced. Set at creation
    |                          and immutable for the life of the credential.
    |   BS (Backup State)    — the credential IS currently backed up.
    |
    | BE=0 is the only signal that a credential is confined to one device. It
    | is a claim by the authenticator rather than a proof, but it is the
    | strongest claim the platform exposes, and it is the one the spec defines
    | for exactly this question.
    |
    | So when `require_device_bound` is true, a BE=1 credential is REFUSED at
    | registration. A clinic tablet whose Google account is signed in on a
    | doctor's personal phone would otherwise hand that phone a working clinic
    | credential, and the entire "approved physical device" property would be a
    | polite fiction.
    |
    | This defaults to true and should stay true. It is configurable only so
    | that a deployment which has formally accepted synced credentials can say
    | so out loud, in a reviewed change, rather than by weakening the check.
    */
    'device_binding' => [
        'require_device_bound' => (bool) env('WEBAUTHN_REQUIRE_DEVICE_BOUND', true),

        // Verdicts recorded against a credential. `unknown` exists because an
        // authenticator that reports no backup flags has not told us it is
        // device-bound — and silence is not a PASS.
        'verdict_pass' => 'device_bound',
        'verdict_fail' => 'backup_eligible',
        'verdict_unknown' => 'unknown',
    ],

    /*
    | COSE algorithms offered at registration, most preferred first.
    | -7  = ES256 (ECDSA P-256), universally supported by platform authenticators
    | -257 = RS256, for the handful of authenticators that only do RSA
    */
    'algorithms' => [-7, -257],

];
