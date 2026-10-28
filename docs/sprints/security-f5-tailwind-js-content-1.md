# SECURITY-F5-TAILWIND-JS-CONTENT-1

**Status:** IMPLEMENTED AND VERIFIED LOCALLY. Not pushed, not merged, not deployed. The visual
check on a real clinic tablet is **not** performed and is not claimed.

**Type:** `UI_POLISH`. **Base:** `19a2ed1c`
(`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`). Audit item F-5
(`docs/audit/full-system-audit-2026-10-27/POST-AUDIT-EXECUTION-ROADMAP.md`, PR-08).

No PHP change, no migration, no route, no permission.

## 1. Finding

`tailwind.config.js` `content` listed Blade views and compiled views only. Tailwind keeps a class
only if it finds it in a scanned file, so classes applied from JavaScript were purged. The KTP
scan region handles in `resources/js/ktp-roi-ui.js` depend on four of them —
`-translate-x-1/2`, `-translate-y-1/2`, `cursor-grab`, `max-w-[220px]` — and none was in the
production stylesheet: the handles rendered off-centre, without a grab cursor or width cap.

## 2. Change

- `tailwind.config.js`: `./resources/js/**/*.js` added to `content`.
- `tests/js/tailwind-content.test.mjs` (3, run by the quality gate's `npm run test:js` after the
  asset build): the glob is present; the four classes are still used by `ktp-roi-ui.js`; the
  built stylesheet contains them.

## 3. Verification

| Build | Four classes in `app-*.css` | Stylesheet size |
|---|---|---|
| base config | none | 88,132 bytes |
| this change | all four | 90,125 bytes |

The new test fails twice on the base config and passes on this one; `npm run test:js`
226 passed.

## 4. Production check after deploy

The deployed `public/build/assets/app-*.css` contains the four classes; the KTP scan page on a
real clinic tablet shows the region handles centred on their corners.

## 5. Rollback

`git revert` + redeploy (asset rebuild).
