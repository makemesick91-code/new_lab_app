'use strict';

/**
 * Small helpers for emitting consistent JSON responses.
 *
 * Every response is JSON, even error/404/405 cases, so the DaengtisiaMS
 * browser client can always parse the body.
 */

function sendJson(res, statusCode, payload, extraHeaders = {}) {
  const body = JSON.stringify(payload);
  res.writeHead(statusCode, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
    // Defensive headers — this is a localhost-only service but cheap to set.
    'X-Content-Type-Options': 'nosniff',
    Vary: 'Origin',
    ...extraHeaders,
  });
  res.end(body);
}

function sendError(res, statusCode, code, message, extraHeaders = {}) {
  sendJson(res, statusCode, { ok: false, error: code, message }, extraHeaders);
}

module.exports = { sendJson, sendError };
