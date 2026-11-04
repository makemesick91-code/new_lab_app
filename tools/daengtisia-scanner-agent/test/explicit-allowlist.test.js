'use strict';

/**
 * DMS-POSTMIGRATION-PR-INTEGRATION-1 — the scanner agent trusts an explicit,
 * exact allowlist and nothing else.
 *
 *  - no implicit loopback trust (a loopback origin needs development mode AND
 *    an explicit entry);
 *  - exact Origin matching (no case-folding, trailing slash, default port,
 *    surrounding space or duplicated header);
 *  - no wildcard, no opaque "null", no IP, no plain-http production origin;
 *  - a request without Origin reaches nothing but the read-only GET /health;
 *  - every refused request is refused BEFORE the scanner runs: the mock
 *    backend's scan() is spied on, so "zero physical scans" is measured.
 *
 * Synthetic data only: the mock backend returns a generated placeholder image.
 * No real KTP image is read, written or transmitted.
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const mockBackend = require('../src/backends/mock');
const { getConfig, VERSION } = require('../src/config');
const { classifyOrigin, resolveMode, isOriginAllowed } = require('../src/cors');
const { startTestServer, request, PRODUCTION_ORIGIN } = require('./helpers');

const REPO = path.join(__dirname, '..', '..', '..');
const SCAN_BODY = JSON.stringify({ document_type: 'ktp' });

function authorityValue(key) {
  const raw = fs.readFileSync(path.join(REPO, 'deploy', 'production-host.conf'), 'utf8');
  let value = '';
  for (const line of raw.split(/\r?\n/)) {
    if (line.startsWith(`${key}=`)) value = line.slice(key.length + 1).replace(/^"|"$/g, '');
  }
  return value;
}
const FROZEN_ADDRESS = authorityValue('DMS_FROZEN_LEGACY_ADDRESSES').split(/\s+/).filter(Boolean)[0];

/**
 * Count calls to the backend's scan() — the only function that drives the
 * scanner hardware (or, here, generates the synthetic image).
 */
async function withScanSpy(fn) {
  const original = mockBackend.scan;
  let calls = 0;
  mockBackend.scan = async (...args) => {
    calls += 1;
    return original(...args);
  };
  try {
    await fn(() => calls);
  } finally {
    mockBackend.scan = original;
  }
}

/** A refused response carries no image and no CORS grant. */
function assertRefusedWithoutData(res, code, label) {
  assert.strictEqual(res.status, 403, `${label}: expected 403, got ${res.status}`);
  assert.ok(res.json, `${label}: refusal must still be JSON`);
  assert.strictEqual(res.json.ok, false, label);
  assert.strictEqual(res.json.error, code, `${label}: expected ${code}, got ${res.json.error}`);
  assert.strictEqual(res.json.base64, undefined, `${label}: no image may be returned`);
  assert.ok(!res.raw.includes('base64'), `${label}: no image field may be returned`);
  assert.ok(res.raw.length < 300, `${label}: a refusal is a short error, not a payload`);
  assert.strictEqual(res.headers['access-control-allow-origin'], undefined, `${label}: no CORS grant`);
}

// ── 1. the approved production origin works ────────────────────────────────

test('the approved production origin can check health and scan, with its exact origin echoed', async () => {
  await withScanSpy(async (scans) => {
    const s = await startTestServer();
    try {
      const health = await request(s.port, { path: '/health' });
      assert.strictEqual(health.status, 200);
      assert.strictEqual(health.headers['access-control-allow-origin'], PRODUCTION_ORIGIN);

      const scan = await request(s.port, { method: 'POST', path: '/scan', body: SCAN_BODY });
      assert.strictEqual(scan.status, 200);
      assert.strictEqual(scan.headers['access-control-allow-origin'], PRODUCTION_ORIGIN);
      assert.ok(scan.json.base64.length > 100);
      assert.strictEqual(scans(), 1, 'the legitimate request must reach the scanner exactly once');
    } finally {
      await s.close();
    }
  });
});

// ── 2–6, 8–10. everything else is refused before the scanner runs ──────────

test('every untrusted request is refused before any scan, and returns no data', async () => {
  await withScanSpy(async (scans) => {
    // The installed 0.1.0 configuration listed the frozen address and loopback;
    // in production mode those entries are dropped, not trusted.
    const s = await startTestServer({
      allowedOrigins: [PRODUCTION_ORIGIN, `http://${FROZEN_ADDRESS}`, 'http://localhost:8000', 'http://127.0.0.1:8000'],
    });
    const port = s.port;
    const scan = (extra) => ({ method: 'POST', path: '/scan', body: SCAN_BODY, ...extra });
    try {
      const cases = [
        // 2. the frozen Hostinger origin, over http and https
        ['frozen origin (http)', scan({ origin: `http://${FROZEN_ADDRESS}` }), 'ORIGIN_NOT_ALLOWED'],
        ['frozen origin (https)', scan({ origin: `https://${FROZEN_ADDRESS}` }), 'ORIGIN_NOT_ALLOWED'],
        // 3. unapproved origins, including near-misses of the real one
        ['foreign origin', scan({ origin: 'https://evil.example' }), 'ORIGIN_NOT_ALLOWED'],
        ['www form', scan({ origin: 'https://www.daengtisia.online' }), 'ORIGIN_NOT_ALLOWED'],
        ['plain-http form', scan({ origin: 'http://daengtisia.online' }), 'ORIGIN_NOT_ALLOWED'],
        ['suffix confusion', scan({ origin: 'https://daengtisia.online.evil.example' }), 'ORIGIN_NOT_ALLOWED'],
        // exact matching: no normalisation of the request header
        ['upper-case host', scan({ origin: 'https://DAENGTISIA.online' }), 'ORIGIN_NOT_ALLOWED'],
        ['trailing slash', scan({ origin: `${PRODUCTION_ORIGIN}/` }), 'ORIGIN_NOT_ALLOWED'],
        ['explicit default port', scan({ origin: `${PRODUCTION_ORIGIN}:443` }), 'ORIGIN_NOT_ALLOWED'],
        // (Leading/trailing spaces are not tested: in HTTP they are optional
        // whitespace, not part of the field value (RFC 9110 §5.5), so the
        // parser hands the agent the exact origin — that is not a near-miss.)
        ['duplicated Origin header', scan({ origin: null, headers: { Origin: [PRODUCTION_ORIGIN, 'https://evil.example'] } }), 'ORIGIN_NOT_ALLOWED'],
        // malformed and opaque origins
        ['opaque origin null', scan({ origin: 'null' }), 'ORIGIN_NOT_ALLOWED'],
        ['empty origin', scan({ origin: '' }), 'ORIGIN_NOT_ALLOWED'],
        ['not a URL', scan({ origin: 'not a url' }), 'ORIGIN_NOT_ALLOWED'],
        ['wildcard', scan({ origin: '*' }), 'ORIGIN_NOT_ALLOWED'],
        // 6. implicit loopback, even though the old config listed it
        ['localhost (listed in production mode)', scan({ origin: 'http://localhost:8000' }), 'ORIGIN_NOT_ALLOWED'],
        ['127.0.0.1 (listed in production mode)', scan({ origin: 'http://127.0.0.1:8000' }), 'ORIGIN_NOT_ALLOWED'],
        ['localhost (unlisted port)', scan({ origin: 'http://localhost:5173' }), 'ORIGIN_NOT_ALLOWED'],
        ['IPv6 loopback', scan({ origin: 'http://[::1]:8000' }), 'ORIGIN_NOT_ALLOWED'],
        ['*.localhost', scan({ origin: 'http://app.localhost:8000' }), 'ORIGIN_NOT_ALLOWED'],
        // 4. arbitrary Host header
        ['arbitrary Host', scan({ headers: { Host: `attacker.example:${port}` } }), 'HOST_NOT_ALLOWED'],
        ['production name as Host', scan({ headers: { Host: `daengtisia.online:${port}` } }), 'HOST_NOT_ALLOWED'],
        ['loopback on another port', scan({ headers: { Host: `127.0.0.1:${port + 1}` } }), 'HOST_NOT_ALLOWED'],
        // 5. DNS rebinding: the attacker's name now resolves to 127.0.0.1, so the
        //    browser sends the attacker's name as both Host and Origin.
        [
          'DNS rebinding',
          scan({ origin: `http://rebind.attacker.example:${port}`, headers: { Host: `rebind.attacker.example:${port}` } }),
          'HOST_NOT_ALLOWED',
        ],
        [
          'DNS rebinding carrying the trusted Origin',
          scan({ headers: { Host: `rebind.attacker.example:${port}` } }),
          'HOST_NOT_ALLOWED',
        ],
        // 8. no browser origin at all cannot bypass the policy
        ['no Origin: POST /scan', scan({ origin: null }), 'ORIGIN_REQUIRED'],
        ['no Origin: text/plain POST /scan', scan({ origin: null, headers: { 'Content-Type': 'text/plain' } }), 'ORIGIN_REQUIRED'],
        ['no Origin: GET /devices', { method: 'GET', path: '/devices', origin: null }, 'ORIGIN_REQUIRED'],
        ['no Origin: OPTIONS /scan', { method: 'OPTIONS', path: '/scan', origin: null }, 'ORIGIN_REQUIRED'],
        ['no Origin: unknown route', { method: 'GET', path: '/scan/../scan', origin: null }, 'ORIGIN_REQUIRED'],
        // a "simple" cross-origin POST a foreign page can send without preflight
        ['foreign text/plain POST', scan({ origin: 'https://evil.example', headers: { 'Content-Type': 'text/plain' } }), 'ORIGIN_NOT_ALLOWED'],
      ];

      for (const [label, req, code] of cases) {
        const res = await request(port, req);
        assertRefusedWithoutData(res, code, label);
      }
      // 9. zero physical scans across every refused request
      assert.strictEqual(scans(), 0, 'a refused request must never reach the scanner');
    } finally {
      await s.close();
    }
  });
});

test('a request without Origin may read GET /health only, with no image and no CORS grant', async () => {
  await withScanSpy(async (scans) => {
    const s = await startTestServer();
    try {
      const res = await request(s.port, { path: '/health', origin: null });
      assert.strictEqual(res.status, 200);
      assert.strictEqual(res.json.version, VERSION);
      assert.strictEqual(res.json.base64, undefined);
      assert.strictEqual(res.headers['access-control-allow-origin'], undefined);
      assert.strictEqual(scans(), 0, '/health must never scan');
    } finally {
      await s.close();
    }
  });
});

// ── 7. explicit development loopback works only in development mode ────────

test('a listed loopback origin is trusted in development mode only, and only exactly as listed', async () => {
  await withScanSpy(async (scans) => {
    const listed = 'http://localhost:8000';

    const dev = await startTestServer({ mode: 'development', allowedOrigins: [PRODUCTION_ORIGIN, listed] });
    try {
      assert.deepStrictEqual(dev.config.allowedOrigins, [PRODUCTION_ORIGIN, listed]);
      const ok = await request(dev.port, { method: 'POST', path: '/scan', body: SCAN_BODY, origin: listed });
      assert.strictEqual(ok.status, 200);
      assert.strictEqual(ok.headers['access-control-allow-origin'], listed);
      assert.strictEqual(scans(), 1);

      // development mode never trusts what is not listed
      for (const unlisted of ['http://localhost:5173', 'http://127.0.0.1:8000', 'http://localhost']) {
        const res = await request(dev.port, { method: 'POST', path: '/scan', body: SCAN_BODY, origin: unlisted });
        assertRefusedWithoutData(res, 'ORIGIN_NOT_ALLOWED', `dev, unlisted ${unlisted}`);
      }
      assert.strictEqual(scans(), 1);
    } finally {
      await dev.close();
    }

    const prod = await startTestServer({ mode: 'production', allowedOrigins: [PRODUCTION_ORIGIN, listed] });
    try {
      assert.deepStrictEqual(prod.config.allowedOrigins, [PRODUCTION_ORIGIN]);
      assert.deepStrictEqual(prod.config.rejectedOrigins.map((r) => r.value), [listed]);
      assert.match(prod.config.rejectedOrigins[0].reason, /^a loopback origin/);
      const res = await request(prod.port, { method: 'POST', path: '/scan', body: SCAN_BODY, origin: listed });
      assertRefusedWithoutData(res, 'ORIGIN_NOT_ALLOWED', 'production, listed loopback');
      assert.strictEqual(scans(), 1, 'production mode must not scan for a loopback origin');
    } finally {
      await prod.close();
    }
  });
});

test('development mode relaxes nothing but listed loopback origins', () => {
  for (const [raw, reason] of [
    ['http://daengtisia.online', 'not https'],
    [`http://${FROZEN_ADDRESS}`, 'not https'],
    [`https://${FROZEN_ADDRESS}`, 'an IP address'],
    ['*', 'a wildcard'],
    ['null', 'the opaque origin'],
  ]) {
    const r = classifyOrigin(raw, 'development');
    assert.strictEqual(r.ok, false, `${raw} must stay refused in development mode`);
    assert.ok(r.reason.startsWith(reason), `${raw}: expected '${reason}', got '${r.reason}'`);
  }
});

test('the mode defaults to production, and an unknown mode never widens trust', () => {
  assert.deepStrictEqual(resolveMode(undefined), { mode: 'production', warning: null });
  assert.deepStrictEqual(resolveMode(''), { mode: 'production', warning: null });
  assert.deepStrictEqual(resolveMode('DEVELOPMENT'), { mode: 'development', warning: null });
  for (const typo of ['dev', 'developement', 'debug', 'true', '1']) {
    const r = resolveMode(typo);
    assert.strictEqual(r.mode, 'production', `${typo} must fall back to production`);
    assert.ok(r.warning, `${typo} must be reported`);
  }
  const config = getConfig({ backend: 'mock', mode: 'dev', allowedOrigins: ['http://localhost:8000'] });
  assert.strictEqual(config.mode, 'production');
  assert.deepStrictEqual(config.allowedOrigins, []);
});

// ── configured-origin hygiene: no wildcard, no null, no malformed host ──────

test('wildcards, the opaque origin and malformed hosts are refused at startup', () => {
  const refused = {
    '*': 'a wildcard',
    'https://*.daengtisia.online': 'a wildcard',
    'https://daengtisia.*': 'a wildcard',
    null: 'the opaque origin',
    NULL: 'the opaque origin',
    'https://localhost.': 'not a fully qualified DNS name',
    'https://daengtisia.online.': 'not a fully qualified DNS name',
    'https://under_score.example': 'not a fully qualified DNS name',
    'https://-bad.example': 'not a fully qualified DNS name',
    'ftp://daengtisia.online': 'not https',
  };
  for (const [raw, reason] of Object.entries(refused)) {
    const r = classifyOrigin(raw);
    assert.strictEqual(r.ok, false, `${raw} must not be trusted`);
    assert.ok(r.reason.startsWith(reason), `${raw}: expected '${reason}', got '${r.reason}'`);
  }
});

test('a hand-built config cannot smuggle in an origin the classifier refuses', () => {
  const config = { mode: 'production', allowedOrigins: ['*', 'null', 'http://localhost:8000', `http://${FROZEN_ADDRESS}`] };
  for (const origin of config.allowedOrigins) {
    assert.strictEqual(isOriginAllowed(origin, config), false, `${origin} must stay refused`);
  }
  // a non-canonical spelling is refused even if someone listed it verbatim
  assert.strictEqual(
    isOriginAllowed('https://DAENGTISIA.online', { mode: 'production', allowedOrigins: ['https://DAENGTISIA.online'] }),
    false
  );
});

// ── 11. the hardened version is distinguishable from the prototype ─────────

test('the agent reports 0.2.0 everywhere the rollout reads it, never the prototype 0.1.0', async () => {
  assert.strictEqual(VERSION, '0.2.0');
  const pkg = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'package.json'), 'utf8'));
  assert.strictEqual(pkg.version, VERSION, 'package.json and the reported version must agree');

  const s = await startTestServer();
  try {
    const res = await request(s.port, { path: '/health', origin: null });
    assert.strictEqual(res.json.version, VERSION);
  } finally {
    await s.close();
  }

  // The rollout runbook's version check must expect exactly what the agent
  // reports, and must name 0.1.0 as the legacy prototype — otherwise an old PC
  // could pass the check.
  const runbook = fs.readFileSync(path.join(REPO, 'docs', 'runbooks', 'scanner-agent-trusted-origin-rollout.md'), 'utf8');
  const v5 = runbook.split(/\r?\n/).find((line) => line.startsWith('| V5 '));
  assert.ok(v5, 'the rollout runbook has a V5 version check');
  assert.ok(v5.includes(`"version":"${VERSION}"`), 'V5 must expect the version the agent reports');
  assert.ok(v5.includes('0.1.0'), 'V5 must say a 0.1.0 answer means the old agent');
});
