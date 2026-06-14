# Pilot Daily Operations Checklist — DaengtisiaMS / ADLMS

## Purpose

This checklist defines the daily operational routine for the DaengtisiaMS VPS
pilot. It ensures the application, VPS services, logs, and backups are verified
every operating day so that issues are caught early and a clear daily GO /
WATCH / NO-GO decision can be recorded.

This document is operational only. It does not change application behavior and
must not be used to perform schema changes, payment changes, or VPS
redeployment.

## Daily Morning Checklist

Run at the start of each pilot operating day.

| # | Check | Expected Result |
|---|---|---|
| 1 | Dashboard access (`/dashboard`) loads | Page loads, no 500 |
| 2 | Owner / Admin login works | Login succeeds, correct role landing |
| 3 | Owner Dashboard KPI renders | KPI cards/figures display |
| 4 | RME page loads (visits / medical records) | Page loads, list visible |
| 5 | Kasir RME / Piutang RME page loads | Receivables list visible |
| 6 | Branch filter works | Switching branch updates data |
| 7 | Inventory / Lab pilot module access | Module pages load for permitted roles |
| 8 | Storage / file access (`public/storage` link) | Files/handwriting/odontogram load |
| 9 | Laravel log scan | No new ERROR/exception entries |
| 10 | Service health check | `php8.3-fpm`, `nginx`, `postgresql` active |

## Daily Closing Checklist

Run at the end of each pilot operating day.

| # | Check | Expected Result |
|---|---|---|
| 1 | Data input saved | Today's visits/records persisted |
| 2 | Payment / RME safe | No stuck/partial payment anomaly (full-payment-only rule) |
| 3 | Follow-up mutation safe | Receivable follow-up states consistent |
| 4 | Backup checkpoint | DB + runtime backup taken or confirmed scheduled |
| 5 | Final error scan | `laravel.log` clean for the day |
| 6 | Owner / user feedback captured | Feedback logged to `docs/pilot_feedback_backlog.md` |

## VPS Quick Commands

Run from the VPS app path `/var/www/asia-dental-lab-v2`.
Read-only inspection commands only.

```bash
# Current date/time
date

# Git baseline
git branch --show-current
git log --oneline -5

# Service status
sudo systemctl status php8.3-fpm --no-pager
sudo systemctl status nginx --no-pager
sudo systemctl status postgresql --no-pager

# Nginx config validation
sudo nginx -t

# Laravel environment
php artisan about

# Laravel log tail
tail -100 storage/logs/laravel.log

# Error scan
grep -iE "error|exception|critical|stack trace" storage/logs/laravel.log | tail -50
```

## Daily Report Template

Copy and fill one block per operating day.

```
Tanggal              :
PIC                  :
VPS Status           :
Application Status    :
Laravel Log          :
Backup Status        :
Issue Found          :
Action Taken         :
Owner/User Feedback  :
Next Action          :
Decision             : GO / WATCH / NO-GO
```

## Status Meanings

| Status | Meaning |
|---|---|
| **GO** | All checks pass. Pilot continues normally. No blocking issue. |
| **WATCH** | Minor/non-blocking issue observed. Pilot continues but the issue is tracked and re-checked next day. |
| **NO-GO** | Critical/blocking issue (app down, data integrity risk, payment/RME anomaly). Pilot use paused; escalate per the support runbook. |
