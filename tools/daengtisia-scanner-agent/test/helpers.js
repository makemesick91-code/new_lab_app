'use strict';

const http = require('http');
const { getConfig } = require('../src/config');
const { createServer } = require('../src/server');

// The one origin the production DaengtisiaMS page runs on. The test client
// sends it by default because that is what the legitimate browser does; a test
// that needs another origin, or none at all, says so explicitly.
const PRODUCTION_ORIGIN = 'https://daengtisia.online';

/**
 * Start a server with a test config (mock backend, production mode, only the
 * production origin trusted) on an ephemeral port bound to 127.0.0.1.
 * Returns { server, port, close }.
 */
function startTestServer(overrides = {}) {
  const config = getConfig({
    backend: 'mock',
    mode: 'production',
    allowedOrigins: [PRODUCTION_ORIGIN],
    ...overrides,
  });
  const server = createServer(config);
  return new Promise((resolve) => {
    server.listen(0, config.host, () => {
      const { port, address } = server.address();
      resolve({
        server,
        port,
        address,
        config,
        close: () => new Promise((r) => server.close(r)),
      });
    });
  });
}

/**
 * Minimal HTTP request helper. Returns { status, headers, json, raw }.
 *
 * `origin` defaults to the production origin (the legitimate browser). Pass
 * `origin: null` to send NO Origin header (a native client or curl). An Origin
 * given in `headers` always wins.
 */
function request(port, { method = 'GET', path = '/', headers = {}, body, origin = PRODUCTION_ORIGIN } = {}) {
  return new Promise((resolve, reject) => {
    const data = body !== undefined ? Buffer.from(body) : null;
    const hasOrigin = Object.keys(headers).some((h) => h.toLowerCase() === 'origin');
    const originHeader = !hasOrigin && origin !== null ? { Origin: origin } : {};
    const req = http.request(
      {
        host: '127.0.0.1',
        port,
        method,
        path,
        headers: {
          ...(data ? { 'Content-Type': 'application/json', 'Content-Length': data.length } : {}),
          ...originHeader,
          ...headers,
        },
      },
      (res) => {
        const chunks = [];
        res.on('data', (c) => chunks.push(c));
        res.on('end', () => {
          const raw = Buffer.concat(chunks).toString('utf8');
          let json = null;
          try {
            json = JSON.parse(raw);
          } catch (_) {
            /* not all responses are JSON-parseable in tests */
          }
          resolve({ status: res.statusCode, headers: res.headers, raw, json });
        });
      }
    );
    req.on('error', reject);
    if (data) req.write(data);
    req.end();
  });
}

module.exports = { startTestServer, request, PRODUCTION_ORIGIN };
