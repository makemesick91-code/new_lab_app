'use strict';

const http = require('http');
const { getConfig } = require('../src/config');
const { createServer } = require('../src/server');

/**
 * Start a server with a test config (mock backend, known allowed origin) on an
 * ephemeral port bound to 127.0.0.1. Returns { server, port, close }.
 */
function startTestServer(overrides = {}) {
  const config = getConfig({
    backend: 'mock',
    allowedOrigins: ['http://145.79.13.224', 'http://localhost:8000'],
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
 */
function request(port, { method = 'GET', path = '/', headers = {}, body } = {}) {
  return new Promise((resolve, reject) => {
    const data = body !== undefined ? Buffer.from(body) : null;
    const req = http.request(
      {
        host: '127.0.0.1',
        port,
        method,
        path,
        headers: {
          ...(data ? { 'Content-Type': 'application/json', 'Content-Length': data.length } : {}),
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

module.exports = { startTestServer, request };
