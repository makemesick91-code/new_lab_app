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

The hardened agent (DMS-SCANNER-AGENT-TRUSTED-ORIGIN-HARDENING-1) refuses plain-http and IP-address origins by
itself, refuses a request from an untrusted origin before any scan, and refuses a non-loopback `Host` (DNS
rebinding). This runbook installs that version and sets the correct origin on each PC.

## Rules

- One PC at a time, outside clinic hours or with that branch's Front Office present.
- Never scan a real KTP to test. The `/health` checks below prove the origin gate, because the agent applies the same
  gate to `/health` and `/scan` (covered by `test/trusted-origin.test.js`). A real scan also uploads the image to
  production's temporary KTP storage.
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
   No `www`, no `http://`, no trailing slash, no server address. Keep `SCANNER_AGENT_HOST=127.0.0.1`.
4. Start the agent. Its first lines must include:
   ```
   trusted origins: https://daengtisia.online
   ```
   and no `WARNING: refused configured origin` line. A refused line means the file still lists an old origin: remove
   it and restart.

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

All six must pass. Record them per PC with the date and time in WITA.

## Roll back (per PC)

Stop the agent, restore the backup folder from "Before you start" step 3, and start it again. Rolling back restores
the old origin list too, so record the PC as **not remediated**.

## Done when

Every Front Office PC that runs the agent has a V1–V6 record, and the repository example configuration no longer
names the old address (owner action — the assistant's permission scope excludes environment-style files).
