# Sprint 24 Phase 24.7 — VPS RME Receivable Aging/Export Smoke

## Deployment Context

Sprint 24 Phase 24.7 validates Sprint 24.6 RME Receivable Aging + CSV Export Foundation on VPS.

## Deployed Head

- Branch: `feature/sprint-24-phase-24-6-rme-receivable-aging-export-foundation`
- Commit: `28c9361`
- Tag: `sprint-24-phase-24-6-rme-receivable-aging-export-foundation`

## Deployment Result

| Check | Result |
|---|---|
| VPS branch switched to Sprint 24.6 branch | PASS |
| VPS HEAD is `28c9361` | PASS |
| `git pull --ff-only` | PASS |
| `php artisan optimize:clear` | PASS |
| `php artisan config:cache` | PASS |
| `php artisan route:cache` | PASS |
| `php artisan view:clear` | PASS |
| `php artisan view:cache` | PASS |
| Route `rme.cashier.receivables` registered | PASS |
| Route `rme.cashier.receivables.export` registered | PASS |
| `php8.3-fpm` active/running | PASS |
| `nginx` active/running | PASS |
| Laravel log cleared before smoke | PASS |

## Smoke Test Result

| Check | Result |
|---|---|
| Piutang RME page opens | PASS |
| Aging summary cards `0–7`, `8–14`, `15–30`, `>30` visible | PASS |
| Aging summary invoice count and remaining total visible | PASS |
| Aging filter works | PASS |
| Existing search, branch, and status filters still work | PASS |
| Export CSV button visible | PASS |
| Export CSV downloads or opens CSV response | PASS |
| CSV contains headers `No Invoice`, `Bucket Aging`, `Sisa Piutang` | PASS |
| Export respects selected filters | PASS |
| Laravel log after smoke | CLEAN |

## Verified Routes

- `rme.cashier.receivables`
- `rme.cashier.receivables.export`

## Verified UI Labels

- 0–7 Hari
- 8–14 Hari
- 15–30 Hari
- >30 Hari
- Export CSV
- Umur
- Bucket

## Verified CSV Headers

- No Invoice
- Pasien
- No Kunjungan
- Cabang
- Status
- Tanggal Invoice
- Umur Hari
- Bucket Aging
- Grand Total
- Sudah Dibayar
- Sisa Piutang

## Notes

- Export CSV uses Laravel response streaming.
- No Excel package was added.
- No migration was executed.
- No production logic was changed during deployment.
- No payment logic was changed during deployment.
- Laravel log after smoke was clean.

## Conclusion

Sprint 24 Phase 24.7 VPS smoke is approved.

RME Receivable Aging + CSV Export is safe for pilot use on VPS.
