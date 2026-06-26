'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { startTestServer, request } = require('./helpers');

test('GET /health returns valid JSON with contract fields', async () => {
  const s = await startTestServer();
  try {
    const res = await request(s.port, { path: '/health' });
    assert.strictEqual(res.status, 200);
    assert.ok(res.json, 'response is JSON');
    assert.strictEqual(res.json.ok, true);
    assert.strictEqual(res.json.backend, 'mock');
    assert.strictEqual(res.json.ready, true);
    assert.strictEqual(res.json.version, '0.1.0');
    assert.ok(typeof res.json.device === 'string' && res.json.device.length > 0);
  } finally {
    await s.close();
  }
});

test('POST /scan (mock) returns ok, mime_type, filename and base64', async () => {
  const s = await startTestServer();
  try {
    const res = await request(s.port, {
      method: 'POST',
      path: '/scan',
      body: JSON.stringify({
        document_type: 'ktp',
        mode: 'color',
        dpi: 200,
        max_width: 1600,
        quality: 82,
      }),
    });
    assert.strictEqual(res.status, 200);
    assert.strictEqual(res.json.ok, true);
    assert.ok(['image/jpeg', 'image/png'].includes(res.json.mime_type));
    assert.match(res.json.filename, /^ktp-scan\.(jpg|png)$/);
    assert.ok(res.json.base64 && res.json.base64.length > 0);
    // base64 must be valid and decode to a non-empty image buffer.
    const buf = Buffer.from(res.json.base64, 'base64');
    assert.ok(buf.length > 50);
  } finally {
    await s.close();
  }
});

test('GET /devices (mock) returns the mock scanner', async () => {
  const s = await startTestServer();
  try {
    const res = await request(s.port, { path: '/devices' });
    assert.strictEqual(res.status, 200);
    assert.strictEqual(res.json.ok, true);
    assert.ok(Array.isArray(res.json.devices));
    assert.ok(res.json.devices.length >= 1);
  } finally {
    await s.close();
  }
});

test('POST /scan rejects unsupported document_type', async () => {
  const s = await startTestServer();
  try {
    const res = await request(s.port, {
      method: 'POST',
      path: '/scan',
      body: JSON.stringify({ document_type: 'passport' }),
    });
    assert.strictEqual(res.status, 422);
    assert.strictEqual(res.json.ok, false);
    assert.strictEqual(res.json.error, 'UNSUPPORTED_DOCUMENT_TYPE');
  } finally {
    await s.close();
  }
});

test('unknown route returns 404 JSON', async () => {
  const s = await startTestServer();
  try {
    const res = await request(s.port, { path: '/nope' });
    assert.strictEqual(res.status, 404);
    assert.ok(res.json, 'body is JSON');
    assert.strictEqual(res.json.ok, false);
    assert.strictEqual(res.json.error, 'NOT_FOUND');
  } finally {
    await s.close();
  }
});

test('unsupported method on /scan returns 405 JSON', async () => {
  const s = await startTestServer();
  try {
    const res = await request(s.port, { method: 'GET', path: '/scan' });
    assert.strictEqual(res.status, 405);
    assert.strictEqual(res.json.ok, false);
    assert.strictEqual(res.json.error, 'METHOD_NOT_ALLOWED');
  } finally {
    await s.close();
  }
});

test('CORS preflight (OPTIONS) succeeds for an allowed origin', async () => {
  const s = await startTestServer();
  try {
    const res = await request(s.port, {
      method: 'OPTIONS',
      path: '/scan',
      headers: {
        Origin: 'http://145.79.13.224',
        'Access-Control-Request-Method': 'POST',
      },
    });
    assert.strictEqual(res.status, 204);
    assert.strictEqual(
      res.headers['access-control-allow-origin'],
      'http://145.79.13.224'
    );
    assert.match(res.headers['access-control-allow-methods'] || '', /POST/);
  } finally {
    await s.close();
  }
});

test('CORS allows local dev origin even when not explicitly listed', async () => {
  const s = await startTestServer({ allowedOrigins: ['http://145.79.13.224'] });
  try {
    const res = await request(s.port, {
      path: '/health',
      headers: { Origin: 'http://127.0.0.1:5173' },
    });
    assert.strictEqual(
      res.headers['access-control-allow-origin'],
      'http://127.0.0.1:5173'
    );
  } finally {
    await s.close();
  }
});
