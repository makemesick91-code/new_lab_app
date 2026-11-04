# DMS-SCANNER-AGENT-TRUSTED-ORIGIN-HARDENING-1 — the scanner agent trusts only the production DNS name

**Date:** 2026-11-04 (WITA). **Missions:** DMS-DEV-INFRA-REMEDIATION-2 Task B; DMS-POSTMIGRATION-PR-INTEGRATION-1
(§5). **Type:** SECURITY_FIX. **Status:** PR #589, merged only after required CI on its final head. **No clinic PC
was changed.**

## 1. Finding

`tools/daengtisia-scanner-agent/` runs on each Front Office PC and returns a scanned KTP image to the web page that
asks. Read from `src/cors.js`, `src/config.js` and `src/server.js` before this change:

| # | Finding | Effect |
|---|---|---|
| F1 | The example configuration trusts the old server's bare address over plain HTTP (residual from the 2026-11-03 move; the file is outside the assistant's permission scope, so it is an owner action) | an agent installed from it trusts an address that can be reassigned to anyone, whose pages could then read KTP scans; and production scanning fails, because `https://daengtisia.online` is not in that list |
| F2 | The agent trusted any configured origin, including `http://` and IP addresses | nothing stopped the stale origin even after the move |
| F3 | A request from an untrusted origin was still executed; CORS only hid the answer | a foreign page could trigger the scanner with a `text/plain` POST, which needs no preflight |
| F4 | The `Host` header was never checked | DNS rebinding: a page on an attacker's name resolving to `127.0.0.1` is same-origin to the browser, so CORS never applied |

Origin matching itself is an exact, case-sensitive string compare after trimming a trailing `/`; `www` and `http`
forms are different origins (the production `www` and `http` URLs answer `301`).

## 2. Change

- `src/cors.js`: `classifyOrigin()` trusts only `https://<fully qualified DNS name>[:port]` (plus loopback, as
  before) and returns the canonical origin; `isHostAllowed()` accepts only `127.0.0.1`, `localhost` or `[::1]` on the
  port the connection arrived on; `isOriginAllowed()` re-checks the classifier (defence in depth).
- `src/config.js`: every configured origin, from the environment or an override, goes through the classifier;
  refused ones are kept in `rejectedOrigins`.
- `src/server.js`: `403 HOST_NOT_ALLOWED` and `403 ORIGIN_NOT_ALLOWED` before any route runs, with no CORS headers;
  the startup log lists the trusted origins and warns once per refused origin.
- Requests without an `Origin` (curl, a native client on the PC) are unchanged.
- `package.json` `test:js` also runs the agent's tests, so CI enforces them.
- Docs: agent README; `docs/runbooks/scanner-agent-trusted-origin-rollout.md`; rule AIR-PAT-32.

## 3. Tests

21 agent tests pass (14 existing, one of them updated; 7 new in `test/trusted-origin.test.js`). **They were never
run by CI before:** `npm run test:js` globbed only `tests/js/`; it now also runs
`tools/daengtisia-scanner-agent/test/*.test.js`, so the NSF-R012 quality gate enforces them (247 tests, 246 pass,
1 pre-existing skip that needs a built stylesheet). The
updated test used to assert that an untrusted origin still got `200` ("CORS is enforced by the browser"); it now
asserts `403 ORIGIN_NOT_ALLOWED`. The new tests read the frozen address from `deploy/production-host.conf`
(`DMS_FROZEN_LEGACY_ADDRESSES`), never a literal, and prove: the frozen origin (http and https) is refused even when
configured, and every route including a `text/plain` scan POST returns `403` with no image; the production origin
is served and echoed exactly; `www` and `http` forms are refused; rebinding `Host` values are refused without a
scan. Mutation: 8 of 8 killed (origin gate, host gate, http trusted, IP trusted, any port, any host name, overrides
bypassing the classifier, path origins trusted). No real KTP image is used; the mock backend returns a synthetic
image.

## 4. Residual and owner actions

1. ~~**The example configuration file still names the old address.**~~ Resolved in §5. The assistant may not edit environment-style
   files. The exact patch, and the matching one-line change to the identity guard's exact residual set (both must
   land in one commit), is prepared outside the repository; apply with `git apply --unidiff-zero`. With this PR the
   agent already refuses that origin at startup, so the residual is hygiene, not exposure.
2. Roll the hardened agent out to each Front Office PC with the runbook, one PC at a time, owner go-ahead per PC.
3. GO tag `dms-scanner-agent-trusted-origin-hardening-1-go` only after merge (CG-16); the agent is not part of the
   server deploy.

## 5. DMS-POSTMIGRATION-PR-INTEGRATION-1 — explicit allowlist only (owner decision: implicit localhost trust prohibited)

Measured by running the previous `src/cors.js` (`7b563341`), not by reading it:

| # | Finding | Effect |
|---|---|---|
| F5 | Any loopback request origin (`http(s)://localhost` / `127.0.0.1`, any port) was trusted even when not configured | any program on the PC that serves a page on loopback could pull a KTP scan |
| F6 | A request with no `Origin` was served on every route, `/scan` included | the policy had a "no browser origin" bypass to the scanner |
| F7 | A configured `https://*.daengtisia.online` or trailing-dot name (`https://localhost.`) was accepted; the request `Origin` was normalised (a trailing `/` matched) | wildcard-looking and loopback names passed as "trusted"; matching was not exact |
| F8 | The CI step running `npm run test:js \| tee` was safe only because it happened to declare `shell: bash`; it was not a registered strict pipeline step | deleting that line would have turned the agent's security tests into a non-gating step with no check noticing |

Change:

- `src/cors.js`: no implicit trust. A loopback origin is trusted only in `SCANNER_AGENT_MODE=development` AND when
  listed; an unknown mode resolves to production. Configured hosts must be valid DNS names (no `*`, `_`, trailing
  dot); `*` and `null` are refused with their own reason. The request `Origin` is compared byte-for-byte (a
  duplicated header, a trailing `/`, an upper-case host or `:443` are refused). `originRequired()` — only
  `GET /health` may arrive without `Origin`.
- `src/server.js`: `403 ORIGIN_REQUIRED` before any route for an Origin-less request to anything but `GET /health`;
  the startup log shows the mode and warns on development mode or an unknown mode.
- `src/config.js`: `mode` / `modeWarning`; origins are classified under the resolved mode.
- `.env.example` (owner patch 02, applied with the owner's approval): `SCANNER_AGENT_ALLOWED_ORIGINS=https://daengtisia.online`;
  the comment that promised implicit loopback trust is corrected and `SCANNER_AGENT_MODE` is documented.
  `ProductionHostIdentityGuardTest` residual set shrunk to `[]` in the same commit (owner patch 01).
- `config/ci_runner.php`: `JavaScript unit tests` is a strict pipeline step; `ScannerAgentRequiredCiCoverageTest` is a
  mandatory critical suite (pins the `test:js` glob, the unconditional step and its pipefail).
- Docs: README (incl. "What this does not protect"), rollout runbook (mode check, V7 implicit loopback refused, V8
  Origin-less device access refused — none of V1–V8 calls `/scan`), rule AIR-PAT-32.

Tests: agent suite 31 pass (`test/explicit-allowlist.test.js` new: production origin served; 30 refused variants —
frozen, foreign, near-miss, non-exact, `null`, empty, wildcard, implicit loopback, arbitrary Host, DNS rebinding,
Origin-less — each `403` with no image and no CORS grant; a spy on the backend's `scan()` proves **zero** scans for
all of them and exactly one for the legitimate request; development loopback works only in development mode and
only when listed; the reported version, `package.json` and the runbook's V5 all say `0.2.0`). Two old tests that
asserted implicit loopback trust were inverted, not deleted. Mutation: agent policy 14/14 killed (baseline 31/31),
CI guard 4/4 killed. One invalid first mutation run (`node --test test/` loaded the directory as one module, so
every run "failed") was discarded and repeated with a proven-green baseline.

Not protected, recorded: `Origin` is a browser statement, not authentication. A program already running on the PC
can send any header (and can reach the scanner hardware directly); closing that needs a per-PC pairing secret. A
bind address other than loopback is still only warned about at startup.
