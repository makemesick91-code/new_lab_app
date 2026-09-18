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

/*
 * BUGFIX-DOCTOR-PWA-WEBAUTHN-VERIFY-DEVICE-NO-FEEDBACK-2
 *
 * WHAT THESE PIN, AND WHY THEY ARE SOURCE-LEVEL.
 *
 * On a real clinic tablet the verify control produced NO visible reaction at
 * all, five times across three client configurations, and every fact about the
 * failure had to be recovered afterwards from nginx and Postgres. The cause was
 * that `navigator.credentials.get()` was awaited with no abort, so a ceremony
 * that never settles left the catch unreached, no message rendered and the
 * button disabled.
 *
 * So two properties are pinned. The mapping is pinned by EVALUATING the real
 * function, matching this file's existing approach. The bounded wait is pinned
 * at source level, because an unsettling Promise cannot be demonstrated without
 * either a 60-second test or fake timers reaching into module internals — and
 * the property that actually protects the clinician is simply that the abort
 * exists at all.
 */

const assertionReasonFor = extract('assertionReasonFor');
const ceremonyError = extract('ceremonyError');

/** Every reason code the module can emit. */
const EMITTED_REASONS = [
    'timeout',
    'no_credential_or_denied',
    'ceremony_cancelled',
    'origin_not_trusted',
    'device_state_invalid',
    'unsupported',
    'unexpected',
    'session_expired',
    'options_rejected',
    'network_unavailable',
];

test('a timed-out ceremony is reported as a timeout, whatever the browser threw', () => {
    // The abort we fired produces an AbortError, which is indistinguishable
    // from a user dismissal unless the timeout flag wins. It must win.
    const abort = new Error('aborted');
    abort.name = 'AbortError';

    assert.equal(assertionReasonFor(abort, true), 'timeout');
    assert.equal(assertionReasonFor(abort, false), 'ceremony_cancelled');
});

test('each authenticator failure maps to its own reason, never one generic code', () => {
    const cases = {
        NotAllowedError: 'no_credential_or_denied',
        SecurityError: 'origin_not_trusted',
        InvalidStateError: 'device_state_invalid',
        NotSupportedError: 'unsupported',
        ConstraintError: 'unsupported',
    };

    for (const [name, expected] of Object.entries(cases)) {
        const exception = new Error(name);
        exception.name = name;

        assert.equal(assertionReasonFor(exception, false), expected, `${name} must map to ${expected}`);
    }

    // An unrecognised failure is named as unrecognised rather than guessed at.
    const odd = new Error('something new');
    odd.name = 'TotallyNewError';
    assert.equal(assertionReasonFor(odd, false), 'unexpected');
});

test('NotAllowedError is not claimed to mean "no credential" alone', () => {
    /*
     * The spec returns NotAllowedError both for "no matching credential" and
     * for "user dismissed the prompt", deliberately, so a page cannot probe
     * which credentials a device holds. Reporting either one alone would be
     * inventing a distinction the browser refuses to make.
     */
    const exception = new Error('denied');
    exception.name = 'NotAllowedError';

    const reason = assertionReasonFor(exception, false);

    assert.equal(reason, 'no_credential_or_denied');
    assert.ok(reason.includes('denied'), 'the code must not claim the credential is merely absent');
});

test('a ceremony failure carries a machine-readable reason', () => {
    const error = ceremonyError('timeout');

    assert.equal(error.reason, 'timeout');
    assert.ok(error instanceof Error);
});

test('the assertion wait is bounded — this is the regression that caused a silent button', () => {
    assert.match(source, /AbortController/, 'the ceremony must be abortable');
    assert.match(source, /CEREMONY_TIMEOUT_MS\s*=\s*\d+/, 'the wait must have a declared bound');
    assert.match(
        source,
        /navigator\.credentials\.get\(\{\s*publicKey,\s*signal\s*\}\)/,
        'the abort signal must actually be passed to credentials.get',
    );
    assert.match(
        source,
        /navigator\.credentials\.create\(\{\s*publicKey,\s*signal\s*\}\)/,
        'registration must be abortable too, or it inherits the same defect',
    );

    const bound = Number(/CEREMONY_TIMEOUT_MS\s*=\s*(\d+)/.exec(source)[1]);
    assert.ok(bound > 0 && bound <= 120000, `timeout must be a usable clinical bound, got ${bound}ms`);
});

test('the login page renders human wording for every reason the module emits', () => {
    /*
     * The producer and the consumer live in different files, so a reason added
     * to the module without wording in the view would surface to a clinician as
     * a bare failure — which is the defect this sprint fixed, reintroduced by
     * drift. `unexpected` is excluded deliberately: it is the documented
     * fallback and is covered by the view's default branch.
     */
    const view = readFileSync(
        join(root, 'resources/views/auth/doctor-device-webauthn.blade.php'),
        'utf8',
    );

    for (const reason of EMITTED_REASONS) {
        if (reason === 'unexpected') continue;

        assert.match(view, new RegExp(`\\b${reason}\\s*:`), `the view must word "${reason}"`);
    }

    // And the code is shown, so an operator can quote it without guessing.
    assert.match(view, /kode: /, 'the visible message must carry the reason code');
});

test('every reason the module emits is one the test knows about', () => {
    // Guards the list above from going stale as the module grows.
    const found = new Set();
    for (const match of source.matchAll(/return '([a-z_]+)';/g)) {
        found.add(match[1]);
    }
    for (const match of source.matchAll(/ceremonyError\(\s*'([a-z_]+)'/g)) {
        found.add(match[1]);
    }
    for (const match of source.matchAll(/\?\s*'([a-z_]+)'\s*:\s*'([a-z_]+)'/g)) {
        found.add(match[1]);
        found.add(match[2]);
    }

    for (const reason of found) {
        assert.ok(
            EMITTED_REASONS.includes(reason),
            `module emits "${reason}" which this test does not know about — add wording to the view too`,
        );
    }
});
