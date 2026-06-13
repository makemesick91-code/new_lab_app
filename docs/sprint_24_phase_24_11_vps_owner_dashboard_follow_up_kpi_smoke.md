# Sprint 24 Phase 24.11 — VPS Owner Dashboard Follow-up KPI Smoke

## Deployment Context

Sprint 24 Phase 24.11 validates Sprint 24.10 Owner Dashboard Receivable Follow-up KPI Integration on VPS.

## Deployed Head

- Branch: `feature/sprint-24-phase-24-10-owner-dashboard-receivable-follow-up-kpi`
- Commit: `ea17ce4`
- Tag: `sprint-24-phase-24-10-owner-dashboard-receivable-follow-up-kpi`

## Deployment Result

| Check | Result |
|---|---|
| VPS branch switched to Sprint 24.10 branch | PASS |
| VPS HEAD is `ea17ce4` | PASS |
| `git pull --ff-only` | PASS |
| `php artisan optimize:clear` | PASS |
| `php artisan config:cache` | PASS |
| `php artisan route:cache` | PASS |
| `php artisan view:clear` | PASS |
| `php artisan view:cache` | PASS |
| Route `dashboard` registered | PASS |
| Route `rme.cashier.receivables` registered | PASS |
| Route `rme.cashier.receivables.export` registered | PASS |
| Route `rme.cashier.receivables.follow-ups.create` registered | PASS |
| Route `rme.cashier.receivables.follow-ups.store` registered | PASS |
| `php8.3-fpm` active/running | PASS |
| `nginx` active/running | PASS |
| Laravel log cleared before smoke | PASS |

## Smoke Test Result

| Check | Result |
|---|---|
| Owner Dashboard opens | PASS |
| Existing RME receivable KPI still visible | PASS |
| Follow-up Jatuh Tempo KPI visible | PASS |
| Follow-up Hari Ini KPI visible | PASS |
| Belum Pernah Follow-up KPI visible | PASS |
| Follow-up Terjadwal KPI visible | PASS |
| Bonus Escalated KPI visible or safely omitted | N/A |
| Branch filter affects follow-up KPI counts | PASS |
| Dashboard does not show billing shortcut for Owner without `manage_rme_billing` | PASS |
| Kasir/billing user shortcut to Piutang RME works | PASS |
| Piutang RME `follow_up_filter=overdue` works | PASS |
| Piutang RME `follow_up_filter=today` works | PASS |
| Piutang RME `follow_up_filter=never` works | PASS |
| Piutang RME `follow_up_filter=scheduled` works | PASS |
| Existing Piutang RME search/branch/status/aging filters still work | PASS |
| CSV export still opens/downloads after `follow_up_filter` | PASS |
| Laravel log after smoke | CLEAN |

## Notes

- `follow_up_filter` works even when the selected filter has no data.
- CSV export opens/downloads after `follow_up_filter`.
- No payment was posted.
- No invoice status transition was changed.
- No WhatsApp message was sent.
- No scheduler or external reminder service was used.
- Laravel log after smoke was clean.
- The unrelated `sprint-16-complete` tag fetch warning does not affect Sprint 24.11.

## Conclusion

Sprint 24 Phase 24.11 VPS smoke is approved.

Owner Dashboard Receivable Follow-up KPI Integration is safe for pilot use on VPS.
