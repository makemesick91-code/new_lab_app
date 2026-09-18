/*
 * DOCTOR-PWA-WEBAUTHN-1 — the browser half of the ceremony.
 *
 * THIS FILE DECIDES NOTHING.
 *
 * Everything here is transport: fetch options the server generated, hand them
 * to the platform authenticator, post the result back. Every security decision
 * — which credentials are allowed, whether the challenge is still live, whether
 * the device is approved, whether this doctor may use it — is made on the
 * server, by code that does not trust anything this file says.
 *
 * That matters because this file runs on the client. If it could decide
 * anything, an attacker could decide it too.
 *
 * WHAT IS DELIBERATELY NOT HERE
 *
 * Nothing here inspects whether the page is running as an installed app, what
 * the browser calls itself, or any locally stored identifier. None of those are
 * evidence of anything, and a security decision that consulted them would be a
 * decision an attacker controls. The regression test in
 * DoctorPwaWebAuthnTest asserts this file names none of them.
 */

/** WebAuthn speaks ArrayBuffer; JSON speaks base64url. */
function base64UrlToBuffer(value) {
    const padded = value.replace(/-/g, '+').replace(/_/g, '/');
    const binary = atob(padded + '='.repeat((4 - (padded.length % 4)) % 4));
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes.buffer;
}

function bufferToBase64Url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';

    for (let i = 0; i < bytes.byteLength; i += 1) {
        binary += String.fromCharCode(bytes[i]);
    }

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function isSupported() {
    return typeof window !== 'undefined'
        && typeof window.PublicKeyCredential !== 'undefined'
        && typeof navigator !== 'undefined'
        && typeof navigator.credentials !== 'undefined';
}

function csrfToken() {
    const tag = document.querySelector('meta[name="csrf-token"]');

    return tag ? tag.getAttribute('content') : '';
}

async function postJson(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(body || {}),
    });

    if (!response.ok) {
        const error = new Error('request_failed');
        error.status = response.status;
        throw error;
    }

    return response.json();
}

/** Decode the parts of the server's options that must be binary. */
function decodeCreationOptions(options) {
    const decoded = { ...options };

    decoded.challenge = base64UrlToBuffer(options.challenge);
    decoded.user = { ...options.user, id: base64UrlToBuffer(options.user.id) };

    if (Array.isArray(options.excludeCredentials)) {
        decoded.excludeCredentials = options.excludeCredentials.map((credential) => ({
            ...credential,
            id: base64UrlToBuffer(credential.id),
        }));
    }

    return decoded;
}

function decodeRequestOptions(options) {
    const decoded = { ...options };

    decoded.challenge = base64UrlToBuffer(options.challenge);

    if (Array.isArray(options.allowCredentials)) {
        decoded.allowCredentials = options.allowCredentials.map((credential) => ({
            ...credential,
            id: base64UrlToBuffer(credential.id),
        }));
    }

    return decoded;
}

function encodeAttestation(credential) {
    return {
        id: credential.id,
        rawId: bufferToBase64Url(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
            attestationObject: bufferToBase64Url(credential.response.attestationObject),
        },
    };
}

function encodeAssertion(credential) {
    return {
        id: credential.id,
        rawId: bufferToBase64Url(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
            authenticatorData: bufferToBase64Url(credential.response.authenticatorData),
            signature: bufferToBase64Url(credential.response.signature),
            userHandle: credential.response.userHandle
                ? bufferToBase64Url(credential.response.userHandle)
                : null,
        },
    };
}

/**
 * Enrol this browser onto a clinic device.
 *
 * Returns the encoded credential for the caller to submit through an ordinary
 * form post, so the result travels with the page's CSRF token and normal
 * validation rather than through a bespoke JSON channel.
 */


/*
 * BUGFIX-DOCTOR-PWA-WEBAUTHN-VERIFY-DEVICE-NO-FEEDBACK-2
 *
 * WHY THERE IS A TIMEOUT AT ALL.
 *
 * `navigator.credentials.get()` is not guaranteed to settle. On a real clinic
 * tablet it was observed to neither resolve nor reject: the awaiting caller
 * hung, its catch never ran, no message rendered, and the button stayed
 * disabled. From the clinician's side the control simply did nothing, five
 * times across three client configurations, and every fact about the failure
 * had to be recovered from nginx and Postgres afterwards.
 *
 * A ceremony that can hang forever therefore needs an abort, or the UI has no
 * lower bound on how long it can lie about being busy. The WebAuthn
 * `timeout` member is only a hint to the browser; AbortController is the part
 * that actually ends the wait.
 */
const CEREMONY_TIMEOUT_MS = 60000;

/**
 * A failure carrying a stable `reason` the UI can map to human wording.
 *
 * The reason is a CODE, never a sentence: the caller owns the wording, and a
 * code can be quoted by an operator in an incident report without describing
 * the clinic's device estate to whoever is reading over their shoulder.
 */
function ceremonyError(reason, cause) {
    const error = new Error(reason);
    error.reason = reason;

    if (cause !== undefined) {
        error.cause = cause;
    }

    return error;
}

/**
 * Map a DOMException from the authenticator onto one of our reason codes.
 *
 * `NotAllowedError` DELIBERATELY COVERS TWO CASES and we do not pretend
 * otherwise: the WebAuthn spec returns it both when no matching credential
 * exists and when the user dismissed the prompt, precisely so that a page
 * cannot probe which credentials a device holds. Reporting "no credential
 * registered" on that code would be inventing a distinction the browser
 * refuses to make, so the wording for it has to cover both.
 */
function assertionReasonFor(exception, timedOut) {
    if (timedOut) {
        return 'timeout';
    }

    const name = exception && exception.name ? exception.name : '';

    switch (name) {
        case 'NotAllowedError':
            return 'no_credential_or_denied';
        case 'AbortError':
            return 'ceremony_cancelled';
        case 'SecurityError':
            return 'origin_not_trusted';
        case 'InvalidStateError':
            return 'device_state_invalid';
        case 'NotSupportedError':
        case 'ConstraintError':
            return 'unsupported';
        default:
            return 'unexpected';
    }
}

/** Fetch options, mapping transport and server failures onto reason codes. */
async function ceremonyOptions(optionsUrl, decode) {
    try {
        return decode(await postJson(optionsUrl));
    } catch (exception) {
        if (exception && typeof exception.status === 'number') {
            const error = ceremonyError(
                exception.status === 419 ? 'session_expired' : 'options_rejected',
                exception,
            );
            error.status = exception.status;

            throw error;
        }

        throw ceremonyError('network_unavailable', exception);
    }
}

/**
 * Run one WebAuthn ceremony with a bounded wait.
 *
 * Every exit path produces either a credential or an Error carrying a
 * `reason`. There is no path that resolves to nothing, because "resolved to
 * nothing" is what produced a silent button.
 */
async function runCeremony({ optionsUrl, decode, invoke, encode }) {
    const options = await ceremonyOptions(optionsUrl, decode);

    const controller = new AbortController();
    let timedOut = false;

    const timer = setTimeout(() => {
        timedOut = true;
        controller.abort();
    }, CEREMONY_TIMEOUT_MS);

    let credential;

    try {
        credential = await invoke(options, controller.signal);
    } catch (exception) {
        throw ceremonyError(assertionReasonFor(exception, timedOut), exception);
    } finally {
        clearTimeout(timer);
    }

    if (!credential) {
        throw ceremonyError('ceremony_cancelled');
    }

    return encode(credential);
}

export async function register(optionsUrl) {
    return runCeremony({
        optionsUrl,
        decode: decodeCreationOptions,
        invoke: (publicKey, signal) => navigator.credentials.create({ publicKey, signal }),
        encode: encodeAttestation,
    });
}

/** Prove this browser is running on an approved clinic device. */
export async function assert(optionsUrl) {
    return runCeremony({
        optionsUrl,
        decode: decodeRequestOptions,
        invoke: (publicKey, signal) => navigator.credentials.get({ publicKey, signal }),
        encode: encodeAssertion,
    });
}

export default { isSupported, register, assert };
