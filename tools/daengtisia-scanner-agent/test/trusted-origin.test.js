'use strict';

/**
 * DMS-DEV-INFRA-REMEDIATION-2 — the scanner agent trusts only the production
 * DNS name, never the frozen Hostinger address, and refuses an untrusted
 * request before any scan is taken.
 *
 * The frozen address is read from the repository's production host authority
 * (deploy/production-host.conf, DMS_FROZEN_LEGACY_ADDRESSES) rather than typed
 * here, so this test follows the authority and the repository keeps exactly one
 * place that names it. No real KTP image is involved: the mock backend returns
 * a synthetic image.
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { getConfig } = require('../src/config');
const { classifyOrigin, isHostAllowed } = require('../src/cors');
const { startTestServer, request } = require('./helpers');

const PRODUCTION_ORIGIN = 'https://daengtisia.online';
const AUTHORITY = path.join(__dirname, '..', '..', '..', 'deploy', 'production-host.conf');

function authorityValue(key) {
  const raw = fs.readFileSync(AUTHORITY, 'utf8');
  let value = '';
  for (const line of raw.split(/\r?\n/)) {
    if (line.startsWith(`${key}=`)) value = line.slice(key.length + 1).replace(/^"|"$/g, '');
  }
  return value;
}

const FROZEN_ADDRESSES = authorityValue('DMS_FROZEN_LEGACY_ADDRESSES').split(/\s+/).filter(Boolean);
const PRODUCTION_DOMAIN = authorityValue('DMS_PRODUCTION_DOMAIN');

test('the authority declares the frozen address and the production domain this test relies on', () => {
  assert.ok(FROZEN_ADDRESSES.length >= 1, 'deploy/production-host.conf names no frozen legacy address');
  assert.strictEqual(`https://${PRODUCTION_DOMAIN}`, PRODUCTION_ORIGIN);
});

test('only an https origin on a DNS name is trusted', () => {
  const accepted = [
    ['https://daengtisia.online', 'https://daengtisia.online'],
    ['https://daengtisia.online/', 'https://daengtisia.online'],
    ['  https://DAENGTISIA.online  ', 'https://daengtisia.online'],
    ['https://daengtisia.online:443', 'https://daengtisia.online'],
    ['http://localhost:8000', 'http://localhost:8000'],
  ];
  for (const [raw, canonical] of accepted) {
    const r = classifyOrigin(raw);
    assert.strictEqual(r.ok, true, `${raw} should be trusted`);
    assert.strictEqual(r.value, canonical);
  }

  const refused = {
    'http://daengtisia.online': 'not https',
    'https://203.0.113.9': 'an IP address',
    'https://[2001:db8::1]': 'an IP address',
    'https://daengtisia.online/app': 'not a bare origin',
    'https://daengtisia.online?x=1': 'not a bare origin',
    'https://user:pw@daengtisia.online': 'carries credentials',
    'https://intranet': 'not a fully qualified DNS name',
    'not-a-url': 'not a URL',
  };
  for (const [raw, reason] of Object.entries(refused)) {
    const r = classifyOrigin(raw);
    assert.strictEqual(r.ok, false, `${raw} must not be trusted`);
    assert.ok(r.reason.startsWith(reason), `${raw}: expected '${reason}', got '${r.reason}'`);
  }
});

test('the frozen Hostinger address is never trusted, even when configured', () => {
  for (const address of FROZEN_ADDRESSES) {
    const config = getConfig({
      backend: 'mock',
      allowedOrigins: [`http://${address}`, `https://${address}`, PRODUCTION_ORIGIN],
    });
    assert.deepStrictEqual(config.allowedOrigins, [PRODUCTION_ORIGIN]);
    assert.deepStrictEqual(
      config.rejectedOrigins.map((r) => r.value),
      [`http://${address}`, `https://${address}`]
    );
  }
});

test('a request from the frozen origin is refused before any scan, with no CORS headers', async () => {
  for (const address of FROZEN_ADDRESSES) {
    // The old installed configuration: the frozen address next to loopback.
    const s = await startTestServer({
      allowedOrigins: [`http://${address}`, 'http://localhost:8000', 'http://127.0.0.1:8000'],
    });
    try {
      for (const req of [
        { method: 'GET', path: '/health' },
        { method: 'GET', path: '/devices' },
        { method: 'OPTIONS', path: '/scan' },
        { method: 'POST', path: '/scan', body: JSON.stringify({ document_type: 'ktp' }) },
        // A "simple" request a foreign page can send without a preflight.
        { method: 'POST', path: '/scan', body: JSON.stringify({ document_type: 'ktp' }), headers: { 'Content-Type': 'text/plain' } },
      ]) {
        const res = await request(s.port, { ...req, headers: { ...(req.headers || {}), Origin: `http://${address}` } });
        assert.strictEqual(res.status, 403, `${req.method} ${req.path} must be refused`);
        assert.strictEqual(res.headers['access-control-allow-origin'], undefined);
        if (res.json) {
          assert.strictEqual(res.json.error, 'ORIGIN_NOT_ALLOWED');
          assert.strictEqual(res.json.base64, undefined, 'no scan may be returned');
        }
      }
    } finally {
      await s.close();
    }
  }
});

test('the production origin is served, with its exact origin echoed', async () => {
  const s = await startTestServer({ allowedOrigins: [PRODUCTION_ORIGIN] });
  try {
    const health = await request(s.port, { path: '/health', headers: { Origin: PRODUCTION_ORIGIN } });
    assert.strictEqual(health.status, 200);
    assert.strictEqual(health.headers['access-control-allow-origin'], PRODUCTION_ORIGIN);

    const preflight = await request(s.port, {
      method: 'OPTIONS',
      path: '/scan',
      headers: { Origin: PRODUCTION_ORIGIN, 'Access-Control-Request-Method': 'POST' },
    });
    assert.strictEqual(preflight.status, 204);
    assert.strictEqual(preflight.headers['access-control-allow-origin'], PRODUCTION_ORIGIN);

    const scan = await request(s.port, {
      method: 'POST',
      path: '/scan',
      headers: { Origin: PRODUCTION_ORIGIN },
      body: JSON.stringify({ document_type: 'ktp' }),
    });
    assert.strictEqual(scan.status, 200);
    assert.ok(scan.json.base64.length > 100);

    // The www and plain-http forms are different origins and are not trusted.
    for (const other of ['https://www.daengtisia.online', 'http://daengtisia.online']) {
      const res = await request(s.port, { path: '/health', headers: { Origin: other } });
      assert.strictEqual(res.status, 403, `${other} must be refused`);
    }
  } finally {
    await s.close();
  }
});

test('a rebinding Host is refused before any scan', async () => {
  const s = await startTestServer({ allowedOrigins: [PRODUCTION_ORIGIN] });
  try {
    for (const host of ['attacker.example:' + s.port, `127.0.0.1:${s.port + 1}`, `daengtisia.online:${s.port}`]) {
      const res = await request(s.port, {
        method: 'POST',
        path: '/scan',
        headers: { Host: host },
        body: JSON.stringify({ document_type: 'ktp' }),
      });
      assert.strictEqual(res.status, 403, `Host ${host} must be refused`);
      assert.strictEqual(res.json.error, 'HOST_NOT_ALLOWED');
      assert.strictEqual(res.json.base64, undefined);
    }
    // A native client on this PC (no Origin) using the loopback name still works.
    const ok = await request(s.port, { method: 'GET', path: '/health', headers: { Host: `localhost:${s.port}` } });
    assert.strictEqual(ok.status, 200);
  } finally {
    await s.close();
  }
});

test('the Host check accepts only the loopback names on the accepting port', () => {
  const config = { host: '127.0.0.1' };
  assert.strictEqual(isHostAllowed('127.0.0.1:17661', 17661, config), true);
  assert.strictEqual(isHostAllowed('LOCALHOST:17661', 17661, config), true);
  assert.strictEqual(isHostAllowed('[::1]:17661', 17661, config), true);
  for (const bad of [undefined, '', 'evil.example:17661', '127.0.0.1:17662', '127.0.0.1:17661/x', 'u@127.0.0.1:17661']) {
    assert.strictEqual(isHostAllowed(bad, 17661, config), false, `Host ${bad} must be refused`);
  }
  // Binding to every interface never widens the accepted names.
  assert.strictEqual(isHostAllowed('192.0.2.10:17661', 17661, { host: '0.0.0.0' }), false);
});
