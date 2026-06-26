'use strict';

/**
 * CORS policy for the scanner agent.
 *
 * Rules (safe-by-default):
 *  - Local development origins (http(s)://localhost[:port] and
 *    http(s)://127.0.0.1[:port]) are always allowed — the operator's browser
 *    talks to the agent over loopback.
 *  - Explicitly configured DaengtisiaMS origins are allowed.
 *  - When configured origins exist we NEVER fall back to a "*" wildcard; we
 *    only echo the exact request origin if it is on the allow list.
 *  - Requests with no Origin header (curl, native clients) are permitted but
 *    receive no CORS headers (they don't need them).
 */

const LOCAL_ORIGIN_RE = /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/i;

function normalize(origin) {
  return (origin || '').trim().replace(/\/+$/, '');
}

function isLocalOrigin(origin) {
  return LOCAL_ORIGIN_RE.test(origin);
}

/**
 * Decide whether an origin is allowed.
 */
function isOriginAllowed(origin, config) {
  const o = normalize(origin);
  if (!o) return false;
  if (isLocalOrigin(o)) return true;
  return config.allowedOrigins.includes(o);
}

/**
 * Build the CORS headers for a given request origin.
 * Returns an object that may be empty (no Origin / not allowed).
 */
function corsHeaders(origin, config) {
  const o = normalize(origin);
  if (!o) return {};
  if (!isOriginAllowed(o, config)) return {};
  return {
    'Access-Control-Allow-Origin': o,
    'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type',
    'Access-Control-Max-Age': '600',
    Vary: 'Origin',
  };
}

module.exports = { corsHeaders, isOriginAllowed, isLocalOrigin };
