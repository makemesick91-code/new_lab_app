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
export async function register(optionsUrl) {
    const options = decodeCreationOptions(await postJson(optionsUrl));
    const credential = await navigator.credentials.create({ publicKey: options });

    if (!credential) {
        throw new Error('ceremony_cancelled');
    }

    return encodeAttestation(credential);
}

/** Prove this browser is running on an approved clinic device. */
export async function assert(optionsUrl) {
    const options = decodeRequestOptions(await postJson(optionsUrl));
    const credential = await navigator.credentials.get({ publicKey: options });

    if (!credential) {
        throw new Error('ceremony_cancelled');
    }

    return encodeAssertion(credential);
}

export default { isSupported, register, assert };
