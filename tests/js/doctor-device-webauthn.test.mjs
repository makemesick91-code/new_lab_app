/*
 * DOCTOR-PWA-WEBAUTHN-1 — the browser transport module.
 *
 * WHAT IS WORTH TESTING HERE, AND WHAT IS NOT
 *
 * The module makes no security decision, so there is no security logic to
 * test. What CAN break silently is the encoding: WebAuthn speaks ArrayBuffer
 * and JSON speaks base64url, and a wrong conversion produces a credential the
 * server rejects with a signature error — which reads like a broken key rather
 * than a broken `atob`. So the round-trip is pinned.
 *
 * The second test pins an absence. It is easy, later, to "improve" the login
 * page by checking whether the app is installed before offering the ceremony.
 * That would make an installation state part of an authentication path, which
 * is the mistake this whole sprint exists to avoid.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const source = readFileSync(join(root, 'resources/js/doctor-device-webauthn.js'), 'utf8');

/*
 * The module targets a browser (atob/btoa, fetch, navigator.credentials), so it
 * is exercised by evaluating the two pure encoders rather than by importing a
 * module that would reach for globals node does not have. Node 18+ has atob and
 * btoa natively, which is what makes this honest rather than a re-implementation.
 */
function extract(name) {
    const start = source.indexOf(`function ${name}(`);
    assert.notEqual(start, -1, `${name} not found — the module was renamed`);

    let depth = 0;
    let i = source.indexOf('{', start);
    const open = i;

    for (; i < source.length; i += 1) {
        if (source[i] === '{') depth += 1;
        if (source[i] === '}') {
            depth -= 1;
            if (depth === 0) break;
        }
    }

    return new Function(`${source.slice(start, i + 1)}; return ${name};`)();
}

const base64UrlToBuffer = extract('base64UrlToBuffer');
const bufferToBase64Url = extract('bufferToBase64Url');

test('base64url survives a round trip through an ArrayBuffer', () => {
    const bytes = new Uint8Array(64);
    for (let i = 0; i < bytes.length; i += 1) {
        bytes[i] = (i * 7 + 3) % 256;
    }

    const encoded = bufferToBase64Url(bytes.buffer);

    // base64url: no padding, and none of the two characters that would be
    // mangled in a URL or a form field.
    assert.ok(!encoded.includes('='), 'padding must be stripped');
    assert.ok(!encoded.includes('+'), 'must not contain +');
    assert.ok(!encoded.includes('/'), 'must not contain /');

    assert.deepEqual(new Uint8Array(base64UrlToBuffer(encoded)), bytes);
});

test('decoding tolerates every unpadded length the server can emit', () => {
    // A 32-byte challenge encodes to 43 characters, which needs one padding
    // character restored. Lengths 1..8 cover all three remainder cases.
    for (let length = 1; length <= 8; length += 1) {
        const bytes = new Uint8Array(length).fill(length);
        const encoded = bufferToBase64Url(bytes.buffer);

        assert.deepEqual(
            new Uint8Array(base64UrlToBuffer(encoded)),
            bytes,
            `round trip failed for ${length} bytes`,
        );
    }
});

test('the module never consults installation state or a stored identifier', () => {
    for (const forbidden of ['display-mode', 'beforeinstallprompt', 'localStorage', 'sessionStorage', 'userAgent']) {
        assert.ok(
            !source.includes(forbidden),
            `${forbidden} must not appear: an installation or client-asserted value is not evidence of a trusted device`,
        );
    }
});
