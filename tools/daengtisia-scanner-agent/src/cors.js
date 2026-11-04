'use strict';

/**
 * CORS / request-trust policy for the scanner agent.
 *
 * The agent hands a scanned KTP image to whoever asks it, so WHO may ask is the
 * whole security model. Rules (safe-by-default, explicit allowlist ONLY):
 *
 *  - Nothing is trusted implicitly. In particular a loopback origin
 *    (http(s)://localhost, 127.0.0.1 or [::1], any port) is NOT trusted just
 *    because it is local: any program on the PC can serve a page from loopback.
 *    A loopback origin is trusted only when the agent runs in development mode
 *    (SCANNER_AGENT_MODE=development) AND the origin is explicitly listed
 *    (DMS-POSTMIGRATION-PR-INTEGRATION-1).
 *  - Otherwise a configured origin is trusted only when it is exactly
 *    `https://<fully qualified DNS name>[:port]`. A plain-http origin can be
 *    impersonated by anyone on the network path, and an IP-address origin
 *    outlives the server that owned the address: when the frozen Hostinger
 *    server's address is reassigned, its new owner would be trusted to pull
 *    KTP scans. Wildcards, the opaque origin "null" and anything else are
 *    dropped at startup with a warning (DMS-DEV-INFRA-REMEDIATION-2).
 *  - The request's Origin header is compared byte-for-byte with the allowlist.
 *    A browser sends the serialized origin (lower-case scheme and host, no
 *    trailing slash, default port omitted); any other spelling did not come
 *    from a page we trust. We never answer with a "*" wildcard; we only echo
 *    the exact request origin when it is on the allowlist.
 *  - A request that CARRIES an Origin the agent does not trust is refused with
 *    403 before any route runs, so a foreign web page can neither read a scan
 *    nor trigger the scanner (a text/plain POST needs no CORS preflight).
 *  - A request that carries NO Origin is refused with 403 for every route
 *    except the read-only `GET /health` (version and readiness only: no image,
 *    no scan). A browser always sends Origin on a cross-origin fetch and on
 *    every POST, so the legitimate DaengtisiaMS page is never affected; this
 *    closes the "no browser origin" path to the scanner.
 *  - The Host header must name the loopback interface the agent listens on.
 *    This stops DNS rebinding: a page on an attacker's name that resolves to
 *    127.0.0.1 is "same-origin" to the browser, so CORS alone never stops it.
 *
 * The Origin header is a statement made by the BROWSER. It stops other web
 * pages; it is not authentication against a program running on this PC, which
 * can send any header it likes (see README "What this does not protect").
 */

const net = require('net');

const MODE_PRODUCTION = 'production';
const MODE_DEVELOPMENT = 'development';
const MODES = [MODE_PRODUCTION, MODE_DEVELOPMENT];

const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];
const WILDCARD_BIND = ['0.0.0.0', '::', ''];

// A DNS name of at least two labels, each 1–63 characters of [a-z0-9-] that do
// not start or end with a hyphen (URL() has already lower-cased the host and
// converted an internationalised name to its xn-- form). Refuses "*", "_",
// a trailing dot and anything else a browser would never serialize as a host.
const DNS_NAME_RE =
  /^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/;

// The only route a request without an Origin may reach.
const ORIGIN_OPTIONAL_ROUTES = [['GET', '/health']];

/**
 * Resolve SCANNER_AGENT_MODE. Anything other than "development" (including a
 * typo) means production: an unrecognised value must never widen trust.
 *
 * @returns {{ mode: string, warning: string|null }}
 */
function resolveMode(raw) {
  const value = String(raw === undefined || raw === null ? '' : raw).trim().toLowerCase();
  if (value === '' || value === MODE_PRODUCTION) return { mode: MODE_PRODUCTION, warning: null };
  if (value === MODE_DEVELOPMENT) return { mode: MODE_DEVELOPMENT, warning: null };
  return {
    mode: MODE_PRODUCTION,
    warning: `unknown SCANNER_AGENT_MODE "${value.slice(0, 40)}" — running in production mode`,
  };
}

function normalizeConfigured(origin) {
  // Configuration only: tolerate surrounding spaces and a trailing slash in the
  // operator's file. The REQUEST Origin is never normalised (see isOriginAllowed).
  return (origin || '').trim().replace(/\/+$/, '');
}

function isLoopbackHostname(hostname) {
  const host = String(hostname || '').toLowerCase();
  return LOOPBACK_HOSTS.includes(host) || host.endsWith('.localhost');
}

/**
 * Decide whether ONE configured origin may be trusted.
 *
 * @param {string} raw
 * @param {string} [mode] - 'production' (default) or 'development'
 * @returns {{ ok: boolean, value: string, reason?: string }} `value` is the
 *   canonical serialized origin when ok (lower-case host, default port dropped).
 */
function classifyOrigin(raw, mode = MODE_PRODUCTION) {
  const value = normalizeConfigured(raw);
  if (!value) return { ok: false, value, reason: 'empty' };
  if (value.toLowerCase() === 'null') {
    return { ok: false, value, reason: 'the opaque origin "null" (sent by sandboxed frames, files and data: URLs)' };
  }
  if (value.includes('*')) {
    return { ok: false, value, reason: 'a wildcard (only exact origins are trusted)' };
  }

  let url;
  try {
    url = new URL(value);
  } catch (_) {
    return { ok: false, value, reason: 'not a URL' };
  }
  if (url.username || url.password) {
    return { ok: false, value, reason: 'carries credentials' };
  }
  if (url.pathname !== '/' || url.search || url.hash) {
    return { ok: false, value, reason: 'not a bare origin (it has a path, query or fragment)' };
  }

  if (isLoopbackHostname(url.hostname)) {
    if (mode !== MODE_DEVELOPMENT) {
      return {
        ok: false,
        value,
        reason: 'a loopback origin (trusted only in development mode: SCANNER_AGENT_MODE=development)',
      };
    }
    if (url.protocol !== 'https:' && url.protocol !== 'http:') {
      return { ok: false, value, reason: 'not https (a loopback origin must be http or https)' };
    }
    return { ok: true, value: url.origin };
  }

  if (url.protocol !== 'https:') {
    return { ok: false, value, reason: 'not https (a plain-http origin can be impersonated on the network)' };
  }
  const host = url.hostname.replace(/^\[|\]$/g, '');
  if (net.isIP(host)) {
    return { ok: false, value, reason: 'an IP address (addresses are reassigned; trust the DNS name)' };
  }
  if (!DNS_NAME_RE.test(url.hostname)) {
    return { ok: false, value, reason: 'not a fully qualified DNS name' };
  }
  return { ok: true, value: url.origin };
}

/**
 * Split a list of configured origins into trusted and refused entries.
 *
 * @param {string[]} list
 * @param {string} [mode]
 * @returns {{ accepted: string[], rejected: { value: string, reason: string }[] }}
 */
function classifyOrigins(list, mode = MODE_PRODUCTION) {
  const accepted = [];
  const rejected = [];
  for (const raw of list || []) {
    const result = classifyOrigin(raw, mode);
    if (result.ok) {
      if (!accepted.includes(result.value)) accepted.push(result.value);
    } else if (result.value) {
      rejected.push({ value: result.value, reason: result.reason });
    }
  }
  return { accepted, rejected };
}

/**
 * Decide whether a request's Origin header is allowed. Exact match only: the
 * header is not trimmed, case-folded or otherwise normalised.
 */
function isOriginAllowed(origin, config) {
  if (typeof origin !== 'string' || origin === '') return false;
  if (!Array.isArray(config.allowedOrigins) || !config.allowedOrigins.includes(origin)) return false;
  // Defence in depth: even a hand-built config cannot trust an origin the
  // classifier refuses for the mode the agent is actually running in, nor a
  // spelling that is not the canonical serialized origin.
  const verdict = classifyOrigin(origin, config.mode || MODE_PRODUCTION);
  return verdict.ok && verdict.value === origin;
}

/**
 * Whether a request without an Origin header may reach this route.
 */
function originRequired(method, pathname) {
  return !ORIGIN_OPTIONAL_ROUTES.some(([m, p]) => m === method && p === pathname);
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
  if (!isOriginAllowed(origin, config)) return {};
  return {
    'Access-Control-Allow-Origin': origin,
    'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type',
    'Access-Control-Max-Age': '600',
    Vary: 'Origin',
  };
}

module.exports = {
  MODE_PRODUCTION,
  MODE_DEVELOPMENT,
  MODES,
  resolveMode,
  corsHeaders,
  isOriginAllowed,
  isLoopbackHostname,
  classifyOrigin,
  classifyOrigins,
  originRequired,
  isHostAllowed,
};
