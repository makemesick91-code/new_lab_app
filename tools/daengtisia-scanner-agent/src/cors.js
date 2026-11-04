'use strict';

/**
 * CORS / request-trust policy for the scanner agent.
 *
 * The agent hands a scanned KTP image to whoever asks it, so WHO may ask is the
 * whole security model. Rules (safe-by-default):
 *
 *  - Local development origins (http(s)://localhost[:port] and
 *    http(s)://127.0.0.1[:port]) are always allowed — the operator's browser
 *    talks to the agent over loopback.
 *  - A configured DaengtisiaMS origin is trusted only when it is exactly
 *    `https://<fully qualified DNS name>[:port]`. A plain-http origin can be
 *    impersonated by anyone on the network path, and an IP-address origin
 *    outlives the server that owned the address: when the frozen Hostinger
 *    server's address is reassigned, its new owner would be trusted to pull
 *    KTP scans. Anything else is dropped at startup with a warning
 *    (DMS-DEV-INFRA-REMEDIATION-2).
 *  - When configured origins exist we NEVER fall back to a "*" wildcard; we
 *    only echo the exact request origin if it is on the allow list.
 *  - A request that CARRIES an Origin the agent does not trust is refused with
 *    403 before any route runs, so a foreign web page can neither read a scan
 *    nor trigger the scanner (a text/plain POST needs no CORS preflight).
 *  - The Host header must name the loopback interface the agent listens on.
 *    This stops DNS rebinding: a page on an attacker's name that resolves to
 *    127.0.0.1 is "same-origin" to the browser, so CORS alone never stops it.
 *  - Requests with no Origin header (curl, native clients on this PC) are
 *    permitted but receive no CORS headers (they don't need them).
 */

const net = require('net');

const LOCAL_ORIGIN_RE = /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/i;
const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];
const WILDCARD_BIND = ['0.0.0.0', '::', ''];

function normalize(origin) {
  return (origin || '').trim().replace(/\/+$/, '');
}

function isLocalOrigin(origin) {
  return LOCAL_ORIGIN_RE.test(origin);
}

/**
 * Decide whether ONE configured origin may be trusted.
 *
 * @returns {{ ok: boolean, value: string, reason?: string }} `value` is the
 *   canonical serialized origin when ok (lower-case host, default port dropped).
 */
function classifyOrigin(raw) {
  const value = normalize(raw);
  if (!value) return { ok: false, value, reason: 'empty' };
  if (isLocalOrigin(value)) return { ok: true, value: value.toLowerCase() };

  let url;
  try {
    url = new URL(value);
  } catch (_) {
    return { ok: false, value, reason: 'not a URL' };
  }
  if (url.protocol !== 'https:') {
    return { ok: false, value, reason: 'not https (a plain-http origin can be impersonated on the network)' };
  }
  if (url.username || url.password) {
    return { ok: false, value, reason: 'carries credentials' };
  }
  if (url.pathname !== '/' || url.search || url.hash) {
    return { ok: false, value, reason: 'not a bare origin (it has a path, query or fragment)' };
  }
  const host = url.hostname.replace(/^\[|\]$/g, '');
  if (net.isIP(host)) {
    return { ok: false, value, reason: 'an IP address (addresses are reassigned; trust the DNS name)' };
  }
  if (!host.includes('.')) {
    return { ok: false, value, reason: 'not a fully qualified DNS name' };
  }
  return { ok: true, value: url.origin };
}

/**
 * Split a list of configured origins into trusted and refused entries.
 *
 * @param {string[]} list
 * @returns {{ accepted: string[], rejected: { value: string, reason: string }[] }}
 */
function classifyOrigins(list) {
  const accepted = [];
  const rejected = [];
  for (const raw of list || []) {
    const result = classifyOrigin(raw);
    if (result.ok) {
      if (!accepted.includes(result.value)) accepted.push(result.value);
    } else if (result.value) {
      rejected.push({ value: result.value, reason: result.reason });
    }
  }
  return { accepted, rejected };
}

/**
 * Decide whether an origin is allowed.
 */
function isOriginAllowed(origin, config) {
  const o = normalize(origin);
  if (!o) return false;
  if (isLocalOrigin(o)) return true;
  // Defence in depth: even a hand-built config cannot trust an origin the
  // classifier refuses.
  return config.allowedOrigins.includes(o) && classifyOrigin(o).ok;
}

/**
 * Decide whether the Host header names the loopback interface this request
 * actually arrived on (DNS-rebinding defence).
 *
 * @param {string|undefined} hostHeader - the request's Host header
 * @param {number} localPort - the port the connection was accepted on
 * @param {object} config
 */
function isHostAllowed(hostHeader, localPort, config) {
  if (typeof hostHeader !== 'string' || hostHeader.trim() === '') return false;
  let url;
  try {
    url = new URL(`http://${hostHeader.trim()}`);
  } catch (_) {
    return false;
  }
  if (url.username || url.password || url.pathname !== '/' || url.search || url.hash) return false;

  const names = LOOPBACK_HOSTS.slice();
  const bound = (config.host || '').toLowerCase();
  if (!WILDCARD_BIND.includes(bound)) {
    names.push(bound.includes(':') && !bound.startsWith('[') ? `[${bound}]` : bound);
  }
  if (!names.includes(url.hostname.toLowerCase())) return false;

  const port = url.port === '' ? 80 : Number(url.port);
  return port === Number(localPort);
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

module.exports = {
  corsHeaders,
  isOriginAllowed,
  isLocalOrigin,
  classifyOrigin,
  classifyOrigins,
  isHostAllowed,
};
