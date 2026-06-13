# Sprint 24 Phase 24.5 — VPS Owner Dashboard Receivable KPI Smoke

## Deployment Context

Sprint 24 Phase 24.5 validates Sprint 24.4 Owner Dashboard RME Receivable KPI Integration on VPS.

## Deployed Head

- Branch: `feature/sprint-24-phase-24-4-owner-dashboard-rme-receivable-kpi`
- Commit: `afbc3e3`
- Tag: `sprint-24-phase-24-4-owner-dashboard-rme-receivable-kpi`

## Deployment Result

| Check | Result |
|---|---|
| VPS branch switched to Sprint 24.4 branch | PASS |
| VPS HEAD is `afbc3e3` | PASS |
| `git pull --ff-only` | PASS |
| `php artisan optimize:clear` | PASS |
| `php artisan config:cache` | PASS |
| `php artisan route:cache` | PASS |
| `php artisan view:clear` | PASS |
| `php artisan view:cache` | PASS |
| Route `rme.cashier.receivables` registered | PASS |
| `php8.3-fpm` active/running | PASS |
| `nginx` active/running | PASS |
| Laravel log cleared before smoke | PASS |

## Smoke Test Result

| Check | Result |
|---|---|
| Dashboard Owner opens | PASS |
| Monitoring Pilot RME & Lab section visible | PASS |
| Sisa Piutang RME label visible | PASS |
| Sisa Piutang RME value formatted as Rupiah | PASS |
| Invoice Cicilan label visible | PASS |
| Invoice Belum Dibayar label visible | PASS |
| Piutang RME shortcut visible for permitted user | PASS |
| Piutang RME shortcut opens receivable page | PASS |
| Owner Dashboard branch filter changes receivable KPI by branch | PASS |
| User without `manage_rme_billing` does not see shortcut | N/A |
| Laravel log after smoke | CLEAN |

## Verified UI Labels

- Sisa Piutang RME
- Invoice Cicilan
- Invoice Belum Dibayar
- Piutang RME

## Verified Route

- `rme.cashier.receivables`

## Notes

- The negative permission check for a user without `manage_rme_billing` was marked `N/A` because it was not manually tested during this smoke.
- The permission-gated shortcut behavior is already covered by targeted Sprint 24.4 feature tests.
- No migration was executed.
- No production logic was changed during deployment.
- No payment logic was changed during deployment.
- Graphify was not regenerated.

## Conclusion

Sprint 24 Phase 24.5 VPS smoke is approved.

Owner Dashboard RME Receivable KPI is safe for pilot use on VPS.
