# Runbook — Scanner agent trusted-origin rollout to clinic PCs

**Owner:** Foundation / Ops with each branch's Front Office. **Status:** procedure only — **no clinic PC has been
changed.** Each PC needs its own owner go-ahead and an on-site operator.

## Why

The scanner agent runs on the Front Office PC and hands a scanned KTP image to whichever web page asks it. Before
2026-11-03 the example configuration trusted the old server's bare address over plain HTTP. Production moved to
`https://daengtisia.online` on 2026-11-03, so on a PC still configured that way:

- **Scanning from production does not work.** The browser sends `Origin: https://daengtisia.online`, which the old
  list does not contain, so the page cannot read the agent's answer.
- **It is a KTP-exfiltration risk.** The old address can be reassigned to anyone. A page served from it would be a
  trusted origin able to pull scans from the agent.
- **Any local page was trusted.** The prototype always trusted `localhost` / `127.0.0.1` on any port, configured or
  not, and served a scan to any request that carried no `Origin` at all.

The hardened agent 0.2.0 (DMS-SCANNER-AGENT-TRUSTED-ORIGIN-HARDENING-1, DMS-POSTMIGRATION-PR-INTEGRATION-1)
trusts an explicit, exact allowlist only: it refuses plain-http, IP-address, wildcard and `null` origins by itself,
never trusts loopback implicitly, refuses a request from an untrusted origin and a request without `Origin` (except
the read-only `/health`) before any scan, and refuses a non-loopback `Host` (DNS rebinding). This runbook installs
that version and sets the correct origin on each PC.

## Rules

- One PC at a time, outside clinic hours or with that branch's Front Office present.
- Never scan a real KTP to test. The `/health` checks below prove the origin gate, because the agent applies the same
  gate to `/health` and `/scan` (covered by `test/trusted-origin.test.js` and `test/explicit-allowlist.test.js`). A
  real scan also uploads the image to production's temporary KTP storage. None of V1–V8 below calls `/scan`, so none
  of them can trigger a scan even on a PC still running the old agent.
- Never copy patient data, scan images or the PC's local configuration file off the PC.
- Record results with a non-identifying PC label (for example `LDK2-FO-1`), never a patient name.

## Before you start (per PC)

1. Find the agent folder and how it starts (shortcut, startup folder, scheduled task or a terminal window).
2. Note the current version and origin posture. The agent answers on loopback only:
   ```bash
   curl -s http://127.0.0.1:17661/health
   ```
   Record the `version` field. `0.1.0` is the pre-hardening prototype; `0.2.0` is the hardened agent. If the agent
   does not answer, it is not running; note that and continue.
3. Back up the agent folder by copying it to a sibling folder named `daengtisia-scanner-agent-backup-<YYYYMMDD>`.

## Install

1. Stop the agent (close its window or stop its task).
2. Replace the agent's `src/`, `test/`, `package.json` and `README.md` with the copy from the merged commit. From a
   workstation with the repository:
   ```bash
   git archive <MERGE_SHA> tools/daengtisia-scanner-agent | tar -x -C <transfer-folder>
   ```
   Copy that folder to the PC with the clinic's approved method. Keep the PC's own local configuration file; do not
   overwrite it with the example.
3. In the PC's local agent configuration file, set exactly:
   ```
   SCANNER_AGENT_ALLOWED_ORIGINS=https://daengtisia.online
   ```
   No `www`, no `http://`, no trailing slash, no server address, no `localhost`. Keep
   `SCANNER_AGENT_HOST=127.0.0.1`. `SCANNER_AGENT_MODE` must be absent or `production` — **never** `development` on
   a clinic PC.
4. Start the agent. Its first lines must include:
   ```
   mode: production
   trusted origins: https://daengtisia.online
   ```
   and no `WARNING:` line. `WARNING: refused configured origin` means the file still lists an old or local origin:
   remove it and restart. `WARNING: unknown SCANNER_AGENT_MODE` means a typo: fix it and restart.

## Verify (no patient data)

Run on the PC itself. Windows 10 and later include `curl.exe`.

| # | Command | Expect |
|---|---|---|
| V1 | `curl -s -D - -o NUL -H "Origin: https://daengtisia.online" http://127.0.0.1:17661/health` (Linux: `-o /dev/null`) | `HTTP/1.1 200` and `Access-Control-Allow-Origin: https://daengtisia.online` |
| V2 | `curl -s -H "Origin: http://<frozen address>" http://127.0.0.1:17661/health`, using the address listed under `DMS_FROZEN_LEGACY_ADDRESSES` in `deploy/production-host.conf` | `403` and `"error":"ORIGIN_NOT_ALLOWED"` |
| V3 | `curl -s -H "Origin: https://www.daengtisia.online" http://127.0.0.1:17661/health` | `403` and `"error":"ORIGIN_NOT_ALLOWED"` |
| V4 | `curl -s -H "Host: attacker.example:17661" http://127.0.0.1:17661/health` | `403` and `"error":"HOST_NOT_ALLOWED"` |
| V5 | `curl -s http://127.0.0.1:17661/health` | `200` and `"version":"0.2.0"` (a `0.1.0` answer means the old agent is still running) |
| V6 | In DaengtisiaMS on that PC (`https://daengtisia.online`), open the new-patient KTP section and press **Periksa scanner** — **do not press Scan** | `Scanner terhubung` |
| V7 | `curl -s -H "Origin: http://localhost:8000" http://127.0.0.1:17661/health` | `403` and `"error":"ORIGIN_NOT_ALLOWED"` (a `200` means loopback is still trusted implicitly — the old agent) |
| V8 | `curl -s http://127.0.0.1:17661/devices` (no `Origin`) | `403` and `"error":"ORIGIN_REQUIRED"` (a device list means the old agent, which serves Origin-less requests) |

All eight must pass. Record them per PC with the date and time in WITA.

## Roll back (per PC)

Stop the agent, restore the backup folder from "Before you start" step 3, and start it again. Rolling back restores
the old origin list too, so record the PC as **not remediated**.

## Done when

Every Front Office PC that runs the agent has a V1–V8 record. (The repository example configuration already trusts
only `https://daengtisia.online` — DMS-POSTMIGRATION-PR-INTEGRATION-1, owner-approved.)
