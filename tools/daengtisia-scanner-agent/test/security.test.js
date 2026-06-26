'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { getConfig } = require('../src/config');
const { startTestServer, request } = require('./helpers');

test('default host is 127.0.0.1 (loopback only, never 0.0.0.0)', async () => {
  const config = getConfig();
  assert.strictEqual(config.host, '127.0.0.1');
  // And the listening socket actually binds loopback.
  const s = await startTestServer();
  try {
    assert.strictEqual(s.address, '127.0.0.1');
  } finally {
    await s.close();
  }
});

test('oversized request body is rejected with 413', async () => {
  const s = await startTestServer({ maxBodyBytes: 1024 });
  try {
    const huge = JSON.stringify({ document_type: 'ktp', pad: 'x'.repeat(5000) });
    const res = await request(s.port, {
      method: 'POST',
      path: '/scan',
      body: huge,
    });
    assert.strictEqual(res.status, 413);
    assert.strictEqual(res.json.error, 'PAYLOAD_TOO_LARGE');
  } finally {
    await s.close();
  }
});

test('scan response never leaks a filesystem path', async () => {
  const s = await startTestServer();
  try {
    const res = await request(s.port, {
      method: 'POST',
      path: '/scan',
      body: JSON.stringify({ document_type: 'ktp' }),
    });
    assert.strictEqual(res.status, 200);
    const keys = Object.keys(res.json);
    assert.deepStrictEqual(keys.sort(), ['base64', 'filename', 'mime_type', 'ok']);
    // No absolute/temp path strings anywhere in the response except base64.
    const withoutBase64 = { ...res.json, base64: '<omitted>' };
    const serialized = JSON.stringify(withoutBase64);
    assert.ok(!/\/tmp\//.test(serialized));
    assert.ok(!/[A-Za-z]:\\/.test(serialized));
    // filename is a bare name, not a path.
    assert.ok(!res.json.filename.includes('/'));
    assert.ok(!res.json.filename.includes('\\'));
  } finally {
    await s.close();
  }
});

test('scan base64 contains no line breaks', async () => {
  const s = await startTestServer();
  try {
    const res = await request(s.port, {
      method: 'POST',
      path: '/scan',
      body: JSON.stringify({ document_type: 'ktp' }),
    });
    assert.ok(!res.json.base64.includes('\n'));
    assert.ok(!res.json.base64.includes('\r'));
  } finally {
    await s.close();
  }
});

test('disallowed cross-origin gets no Access-Control-Allow-Origin header', async () => {
  const s = await startTestServer({ allowedOrigins: ['http://145.79.13.224'] });
  try {
    const res = await request(s.port, {
      path: '/health',
      headers: { Origin: 'http://evil.example.com' },
    });
    assert.strictEqual(res.headers['access-control-allow-origin'], undefined);
    // Body still served (CORS is enforced by the browser), but no wildcard.
    assert.strictEqual(res.status, 200);
  } finally {
    await s.close();
  }
});

test('scan base64 is never written to stdout logs', async () => {
  const s = await startTestServer();
  const captured = [];
  const originalWrite = process.stdout.write.bind(process.stdout);
  process.stdout.write = (chunk, ...rest) => {
    captured.push(chunk.toString());
    return originalWrite(chunk, ...rest);
  };
  try {
    const res = await request(s.port, {
      method: 'POST',
      path: '/scan',
      body: JSON.stringify({ document_type: 'ktp' }),
    });
    const logs = captured.join('');
    // The full base64 payload must not appear in any log line.
    assert.ok(res.json.base64.length > 100);
    assert.ok(!logs.includes(res.json.base64));
  } finally {
    process.stdout.write = originalWrite;
    await s.close();
  }
});
