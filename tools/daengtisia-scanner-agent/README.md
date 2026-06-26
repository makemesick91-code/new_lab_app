# DaengtisiaMS Scanner Agent (Prototype — Sprint 61.2)

A tiny, dependency-free local HTTP service that lets the **DaengtisiaMS browser
UI** trigger a document scan from a USB scanner attached to the **FO/operator
computer**.

> Status: **prototype** (CLI/local service). No installer, no GUI yet.

---

## Why this exists

The DaengtisiaMS Laravel app runs on a VPS, and **browsers cannot talk to
scanner drivers directly**. This agent runs *next to the scanner*, on the
operator's own computer, and exposes a small localhost-only HTTP contract:

```
Browser (operator PC)  ──fetch──▶  http://127.0.0.1:17661  ──▶  scanner driver
        │                                                          (SANE / NAPS2 / WIA)
        └── uploads base64 image to DaengtisiaMS (Laravel on VPS) ─┘
```

The agent **must run on the operator computer**, not on the VPS. The VPS / Laravel
app never connects to the agent — only the operator's browser does, over loopback.

Sprint 61.1 added the DaengtisiaMS web/backend scanner-ready integration, and
Sprint 61.1.1 added the scanner section to the RME new-patient visit flow. This
agent is the **local side** that those features call.

---

## How the scanner connects

1. Plug the USB scanner (or scanner/printer multifunction) into the operator PC.
2. Install the OS scanner driver and a backend the agent can drive:
   - **Linux:** SANE (`sane-utils`, provides `scanimage`).
   - **Windows:** NAPS2 (recommended prototype) or WIA (experimental).
3. Start this agent on the operator PC.
4. Open DaengtisiaMS in the browser **on that same PC** and use the scan UI.

A scanner/printer combo (MFP) appears to the OS as a scanner device; SANE
(`scanimage -L`) or NAPS2 enumerates it the same way as a standalone scanner.

---

## Quick start (mock mode — no hardware)

```bash
cd tools/daengtisia-scanner-agent
npm install          # no dependencies, just sets up the package
cp .env.example .env # optional; mock is the default backend
npm start
```

Then, in another terminal:

```bash
curl http://127.0.0.1:17661/health

curl -X POST http://127.0.0.1:17661/scan \
  -H "Content-Type: application/json" \
  -d '{"document_type":"ktp","mode":"color","dpi":200,"max_width":1600,"quality":82}'
```

`/health` returns backend/device status; `/scan` returns a base64 placeholder
image. Mock mode is enough to exercise the DaengtisiaMS UI end-to-end:
**Cek Scanner → connected**, **Scan KTP → preview appears**, **Upload → Laravel
receives the temp token**.

---

## Using it with DaengtisiaMS

1. Start the agent on the FO computer (`npm start`).
2. Open DaengtisiaMS in the browser on that computer.
3. Go to **RME → Kunjungan Baru → Pasien Baru**.
4. Click **Cek Scanner** (calls `GET /health`).
5. Click **Scan KTP** (calls `POST /scan`), preview appears, then upload.

> **CORS note:** the agent must allow the DaengtisiaMS origin. Set
> `SCANNER_AGENT_ALLOWED_ORIGINS` to your DaengtisiaMS URL(s). Local dev origins
> (`localhost` / `127.0.0.1`, any port) are always allowed.

> **Security note:** the agent is **localhost-only by default** (binds
> `127.0.0.1`). Do not expose it to the public network or bind `0.0.0.0`.

---

## Backends

Select with `SCANNER_AGENT_BACKEND` = `mock` | `sane` | `naps2` | `wia`.

### 1. Mock (default)

Works with no scanner. Returns a synthetic placeholder PNG, or a configured
sample image via `SCANNER_AGENT_MOCK_IMAGE` (use a **synthetic** placeholder
only — never a real KTP). Ideal for UI development and CI.

### 2. Linux — SANE (`scanimage`)

```bash
sudo apt install sane-utils
scanimage -L                                   # detect device
scanimage --format=jpeg --resolution 200 > ktp-test.jpg   # manual test
```

Then:

```bash
SCANNER_AGENT_BACKEND=sane npm start
# optional: SCANNER_AGENT_DEVICE="epson2:libusb:001:002"
```

Helper script: [`scripts/linux/install-sane-deps.sh`](scripts/linux/install-sane-deps.sh).
If `scanimage` is missing, the agent returns a clear error:
`sane backend selected but scanimage is not installed.`

### 3. Windows — NAPS2 CLI (recommended prototype)

1. Install [NAPS2](https://www.naps2.com/).
2. Open NAPS2 and create a **scanner profile** (e.g. named `ktp`).
3. Configure the agent:

   ```
   SCANNER_AGENT_BACKEND=naps2
   SCANNER_AGENT_NAPS2_PATH=C:\Program Files\NAPS2\NAPS2.Console.exe
   SCANNER_AGENT_NAPS2_PROFILE=ktp
   ```
4. `npm start`.

The agent scans one page to a temp JPEG with the configured profile, returns it
as base64, and deletes the temp file. If NAPS2 is missing/unconfigured, it
returns a clear error.

#### Windows USB scanner/printer workflow

- Connect the MFP via USB, install the vendor driver, and confirm Windows
  "Scan" app can scan.
- Create the NAPS2 profile against that device once.
- Start the agent; DaengtisiaMS in the browser on the same PC can now scan.

### 4. Windows — WIA via PowerShell (EXPERIMENTAL)

A reference script, [`scripts/windows/wia-scan.ps1`](scripts/windows/wia-scan.ps1),
drives the WIA COM API. **WIA automation is fragile** — some drivers ignore the
DPI hint or pop their own UI. **This is not the default backend**; prefer NAPS2.

```
SCANNER_AGENT_BACKEND=wia
# optional override:
# SCANNER_AGENT_WIA_SCRIPT=C:\path\to\wia-scan.ps1
```

---

## HTTP contract

### `GET /health`

```json
{ "ok": true, "device": "Mock Daengtisia Scanner", "version": "0.1.0", "backend": "mock", "ready": true }
```

### `GET /devices` (optional helper)

```json
{ "ok": true, "backend": "mock", "devices": ["Mock Daengtisia Scanner"] }
```

### `POST /scan`

Request:

```json
{ "document_type": "ktp", "mode": "color", "dpi": 200, "max_width": 1600, "quality": 82 }
```

Response:

```json
{ "ok": true, "mime_type": "image/jpeg", "filename": "ktp-scan.jpg", "base64": "..." }
```

Errors are always JSON, e.g.:

```json
{ "ok": false, "error": "UNSUPPORTED_DOCUMENT_TYPE", "message": "Hanya document_type ktp yang didukung." }
{ "ok": false, "error": "SCANNER_UNAVAILABLE", "message": "Scanner tidak ditemukan atau backend belum siap." }
```

Image handling: the agent requests sane scan settings (color, DPI 200, JPEG
where the backend supports it) and returns the raw bytes base64-encoded.
DaengtisiaMS (Laravel/GD) does the final validation/compression/storage, so the
agent intentionally does **no** image resizing (no heavy deps).

---

## Configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `SCANNER_AGENT_HOST` | `127.0.0.1` | Bind address (keep loopback). |
| `SCANNER_AGENT_PORT` | `17661` | Listen port. |
| `SCANNER_AGENT_BACKEND` | `mock` | `mock` \| `sane` \| `naps2` \| `wia`. |
| `SCANNER_AGENT_ALLOWED_ORIGINS` | _(empty)_ | Comma-separated DaengtisiaMS origins. |
| `SCANNER_AGENT_TIMEOUT_MS` | `30000` | Scanner command timeout. |
| `SCANNER_AGENT_DPI` | `200` | Default resolution. |
| `SCANNER_AGENT_QUALITY` | `82` | Default JPEG quality hint. |
| `SCANNER_AGENT_MAX_WIDTH` | `1600` | Default max width hint. |
| `SCANNER_AGENT_MAX_BODY_BYTES` | `1048576` | Request body cap. |
| `SCANNER_AGENT_DEVICE` | _(none)_ | Backend device/profile name. |
| `SCANNER_AGENT_MOCK_IMAGE` | _(none)_ | Mock sample image path (synthetic only). |
| `SCANNER_AGENT_NAPS2_PATH` | _(none)_ | NAPS2.Console.exe path. |
| `SCANNER_AGENT_NAPS2_PROFILE` | _(none)_ | NAPS2 profile name. |
| `SCANNER_AGENT_WIA_SCRIPT` | bundled | WIA PowerShell script path. |

See [`.env.example`](.env.example).

---

## Security model

- **Loopback only** by default (`127.0.0.1`). The agent warns loudly if bound to
  `0.0.0.0`.
- **CORS:** only configured DaengtisiaMS origins + local dev origins are echoed;
  **no wildcard** when an allow list is set.
- Only `document_type: "ktp"` is accepted; unknown methods/paths return JSON
  404/405.
- **No persistent patient data.** Temp scan files (NAPS2/WIA) are deleted after
  the response; SANE streams stdout with no temp file.
- **Never logs base64** image content.
- Request body is size-capped; scanner commands run with a timeout.
- **No arbitrary command execution** — backends run **fixed** binaries with
  fixed flags; only validated numeric options and env-configured device names
  are passed (never request strings, never `shell:true`).

---

## Testing

```bash
cd tools/daengtisia-scanner-agent
npm test
```

Covers the contract response shapes and security behaviour (loopback bind, body
limit, CORS, no path leak, no base64 in logs). Uses Node's built-in
`node:test` — no test dependencies.

---

## Run / dev scripts

| Script | Action |
| --- | --- |
| `npm start` | Start the agent. |
| `npm run dev` | Start with `--watch` (auto-restart on edit). |
| `npm test` | Run the contract + security test suites. |
