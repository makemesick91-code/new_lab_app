'use strict';

/**
 * DaengtisiaMS local scanner agent — HTTP server.
 *
 * Runs on the FO/operator computer and is reached by the DaengtisiaMS browser
 * at http://127.0.0.1:17661. The Laravel app on the VPS never talks to this
 * service directly; only the operator's browser does, over loopback.
 *
 * Routes:
 *   GET  /health    -> backend status (always valid JSON)
 *   GET  /devices   -> known scanner list
 *   POST /scan      -> { ok, mime_type, filename, base64 }
 */

const http = require('http');
const { getConfig } = require('./config');
const { corsHeaders } = require('./cors');
const { sendJson, sendError } = require('./response');
const scanService = require('./scan-service');

/**
 * Read the request body with a hard size cap. Resolves with the raw string,
 * or rejects with a PAYLOAD_TOO_LARGE marker if the cap is exceeded.
 */
function readBody(req, maxBytes) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    let aborted = false;

    req.on('data', (chunk) => {
      if (aborted) return;
      size += chunk.length;
      if (size > maxBytes) {
        aborted = true;
        const e = new Error('Payload too large');
        e.code = 'PAYLOAD_TOO_LARGE';
        // Stop buffering and drain the rest of the upload so the 413 response
        // can flush cleanly instead of resetting the socket.
        req.resume();
        reject(e);
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => {
      if (!aborted) resolve(Buffer.concat(chunks).toString('utf8'));
    });
    req.on('error', (err) => {
      if (!aborted) reject(err);
    });
  });
}

function logLine(message) {
  // Never log request/response bodies (they may carry image base64).
  process.stdout.write(`[scanner-agent] ${new Date().toISOString()} ${message}\n`);
}

function createServer(config = getConfig()) {
  const server = http.createServer(async (req, res) => {
    const origin = req.headers.origin;
    const cors = corsHeaders(origin, config);

    let url;
    try {
      url = new URL(req.url, `http://${config.host}:${config.port}`);
    } catch (_) {
      sendError(res, 400, 'BAD_REQUEST', 'URL permintaan tidak valid.', cors);
      return;
    }
    const pathname = url.pathname.replace(/\/+$/, '') || '/';
    const method = req.method;

    // CORS preflight for any route.
    if (method === 'OPTIONS') {
      res.writeHead(204, cors);
      res.end();
      return;
    }

    try {
      if (pathname === '/health') {
        if (method !== 'GET') {
          sendError(res, 405, 'METHOD_NOT_ALLOWED', 'Gunakan GET untuk /health.', cors);
          return;
        }
        const health = await scanService.getHealth(config);
        logLine(`GET /health -> ready=${health.ready} backend=${health.backend}`);
        sendJson(res, 200, health, cors);
        return;
      }

      if (pathname === '/devices') {
        if (method !== 'GET') {
          sendError(res, 405, 'METHOD_NOT_ALLOWED', 'Gunakan GET untuk /devices.', cors);
          return;
        }
        const devices = await scanService.listDevices(config);
        sendJson(res, 200, { ok: true, backend: config.backend, devices }, cors);
        return;
      }

      if (pathname === '/scan') {
        if (method !== 'POST') {
          sendError(res, 405, 'METHOD_NOT_ALLOWED', 'Gunakan POST untuk /scan.', cors);
          return;
        }

        let raw;
        try {
          raw = await readBody(req, config.maxBodyBytes);
        } catch (err) {
          if (err.code === 'PAYLOAD_TOO_LARGE') {
            sendError(res, 413, 'PAYLOAD_TOO_LARGE', 'Body permintaan terlalu besar.', cors);
            return;
          }
          sendError(res, 400, 'BAD_REQUEST', 'Gagal membaca body permintaan.', cors);
          return;
        }

        let body;
        try {
          body = raw ? JSON.parse(raw) : {};
        } catch (_) {
          sendError(res, 400, 'INVALID_JSON', 'Body harus berupa JSON yang valid.', cors);
          return;
        }

        try {
          const result = await scanService.performScan(body, config);
          // Log metadata only — never the base64 payload.
          logLine(
            `POST /scan -> ok mime=${result.mime_type} bytes=${result.base64.length}`
          );
          sendJson(res, 200, result, cors);
        } catch (err) {
          if (err.errorCode === 'UNSUPPORTED_DOCUMENT_TYPE') {
            sendError(res, 422, err.errorCode, err.message, cors);
            return;
          }
          if (err.errorCode === 'INVALID_REQUEST') {
            sendError(res, 400, err.errorCode, err.message, cors);
            return;
          }
          const code = err.errorCode || 'SCANNER_UNAVAILABLE';
          const message =
            err.errorCode && err.message
              ? err.message
              : 'Scanner tidak ditemukan atau backend belum siap.';
          logLine(`POST /scan -> error ${code}`);
          sendError(res, 503, code, message, cors);
        }
        return;
      }

      // Unknown path.
      sendError(res, 404, 'NOT_FOUND', 'Endpoint tidak ditemukan.', cors);
    } catch (err) {
      logLine(`unhandled error: ${err && err.message}`);
      sendError(res, 500, 'INTERNAL_ERROR', 'Terjadi kesalahan internal.', cors);
    }
  });

  return server;
}

function start(config = getConfig()) {
  const server = createServer(config);
  server.listen(config.port, config.host, () => {
    logLine(
      `listening on http://${config.host}:${config.port} (backend=${config.backend})`
    );
    if (config.host === '0.0.0.0') {
      logLine('WARNING: bound to 0.0.0.0 — this exposes the agent beyond localhost.');
    }
  });
  return server;
}

module.exports = { createServer, start };

// Run directly: `node src/server.js` or `npm start`.
if (require.main === module) {
  start();
}
