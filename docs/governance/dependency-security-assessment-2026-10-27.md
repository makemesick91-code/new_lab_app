# Dependency security assessment — 2026-10-27

**Scope:** the deployed production authority `f9134aac`
(`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`), its `composer.lock`
and `package-lock.json`. **Remediation:** SECURITY-DEPENDENCY-UPDATES-1 (same-major updates only).

## 1. Method

- `composer audit --locked` (with and without `--no-dev`) and `npm audit` (with and without
  `--omit=dev`) against the live advisory feeds.
- `composer outdated --locked` and the npm registry for the first fixed release **within the major
  version already in use**. Major upgrades (Laravel 13, Guzzle 8) are out of scope.
- For every advisory, the code path in this application that reaches the vulnerable function was
  traced from the source, not inferred from the package name.

## 2. Findings and exposure in this application

### PHP (production dependencies unless stated)

| Package | Locked | Advisory (highest first) | Fixed in | Path in this application | Exposure |
|---|---|---|---|---|---|
| `symfony/http-foundation` | 7.4.13 | CVE-2026-108082 ReDoS in `HeaderUtils::split()` through the `Cache-Control` **request** header (unrated) | 7.4.19 | Every HTTP request is parsed by HttpFoundation; the header is attacker-controlled and needs no session. | **Highest real exposure**: unauthenticated CPU exhaustion of PHP-FPM workers. |
| `laravel/framework` | 12.61.0 | critical CVE-2026-114509 TokenGuard bypass via non-string credentials | 12.68.0 | `config/auth.php` defines only the `web` guard (`session` driver); no `token`/`api` guard, no Sanctum. | Not reachable. |
| | | medium CVE-2026-114508 relationship inclusion can trigger unauthorized data destruction (≥12.45) | 12.68.0 | No path established in this code; fixed regardless. | Low / unconfirmed. |
| | | medium PKSA-m5cs temporary signed URL path confusion | 12.61.1 | One signed route: `verification.verify` (`signed` middleware). | Low. |
| | | low CVE-2026-102279 XSS in the debug page | 12.69.0 | `APP_DEBUG` is off in production. | Not reachable in production. |
| `guzzlehttp/guzzle` (+ `psr7`, `promises`) | 7.13.1 | high CVE-2026-69246 non-canonical host bypasses host checks; medium cookie scope, Referer fragment, Proxy-Authorization leak, unbounded cookies | 7.15.2 | Laravel `Http` client: monitoring/smoke probes to fixed hosts; SATUSEHAT gateway (disabled); WhatsApp gateway (disabled). No user-supplied URL. | Low. |
| `league/commonmark` | 2.8.2 | high: DoS family, `on*` filter bypass (XSS) in AttributesExtension; medium raw-HTML and unsafe-link filter bypasses | 2.10.2 | No `Str::markdown`, no Markdown rendering of user input, no Markdown mail in application code. | Not reachable through user input. |
| `dompdf/dompdf` | 3.1.5 | medium: SVG data-URI local file read, file/dir existence via SVG, BMP/bitmap resource exhaustion; low: chroot bypass, font-face file oracle | 3.1.6 | Eight `Pdf::loadView` callers (cashier receipt, RME visit and legacy record exports, oversight exports, inventory PDFs). Templates escape text; embedded images are server-generated PNG data URIs. | Low, but the renderer handles clinical data. |
| `league/flysystem` (+ `-local`) | 3.35.1 | low CVE-2026-102601 control-character path check bypassed by malformed UTF-8 | 3.35.3 | Private clinical-evidence and legacy disks; paths are server-generated. | Low. |
| `symfony/yaml` (dev) | 7.4.13 | CVE-2026-109413 unbounded recursion in the inline lexer | 7.4.18 | Development and CI tooling only. | Not in production. |

### JavaScript (all `devDependencies`; `npm audit --omit=dev` reports 0)

`--omit=dev` is not the end of the question for a Vite application: `devDependencies` imported by
`resources/js` are compiled into `public/build` and shipped to every browser.

| Package | Locked | Advisory | Fixed in | Path | Exposure |
|---|---|---|---|---|---|
| `axios` (+ `follow-redirects`, `form-data`) | 1.16.1 | high: prototype-pollution gadgets alter auth/request config | 1.20.0 | **Shipped**: `resources/js/bootstrap.js` imports axios onto `window.axios`; only a default header is set. | Low; shipped code, so updated. |
| `vite` | 6.4.3 | high: launch-editor argument handling | 6.4.4 | Development server only. | Developer machines. |
| `postcss`, `postcss-selector-parser`, `source-map-js`, `nanoid`, `browserslist`, `baseline-browser-mapping` | — | DoS / path traversal in build tooling | see test | Build time only. | CI and developer machines. |
| `concurrently` → `shell-quote` | 1.8.3 | critical: newline quoting, quadratic parse | 1.8.5 | `npm run dev` script runner. | Developer machines. |

## 3. Remediation

- **Composer:** `composer update` of exactly the affected packages with `--with-dependencies
  --minimal-changes`: 10 packages updated, 0 installed, 0 removed, every one within its major
  (`laravel/framework` 12.61.0 → 12.70.1, `guzzlehttp/guzzle` 7.13.1 → 7.16.1, `psr7` 2.12.3 →
  2.13.2, `promises` 2.5.0 → 2.6.0, `league/commonmark` 2.8.2 → 2.10.4, `dompdf/dompdf` 3.1.5 →
  3.1.6, `league/flysystem` 3.35.1 → 3.36.0, `league/flysystem-local` 3.31.0 → 3.35.3,
  `symfony/http-foundation` and `symfony/yaml` 7.4.13 → 7.4.21). `composer.json` unchanged; the
  PHP 8.3.6 platform pin is kept.
- **npm:** `npm audit fix` without `--force`: 16 packages, all within their existing ranges,
  no major change; `package.json` unchanged.
- **After:** `composer audit` 0 advisories (production and dev); `npm audit` 0 vulnerabilities.
- **Regression guard:** `tests/Feature/Security/DependencySecurityFloorTest.php` fails if any
  patched package falls below its first fixed release or leaves its major. It fails 18 of 19 cases
  on the old lock files. Mandatory critical-gate suite (`DependencySecurityFloor` token).

## 4. Not done here

- Laravel 13, Guzzle 8 and other major upgrades: separate decisions.
- A scheduled `composer audit` / `npm audit` job: the audits need a live feed, so the floor test
  is the offline guard; a periodic audit is a CI/CD item (Priority 4).
