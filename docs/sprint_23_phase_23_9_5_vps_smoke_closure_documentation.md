# Sprint 23 Phase 23.9.5 — VPS Smoke Closure Documentation

## 1. Status

Status: GO WITH WATCH

This phase documents the completed VPS deployment and smoke test for Sprint 23 Phase 23.9.4.

This is a docs-only closure phase.

## 2. Deployment Reference

* Source phase: Sprint 23 Phase 23.9.3 — RME Visit List Branch Filter Fix
* Deployed phase: Sprint 23 Phase 23.9.4 — VPS Deploy + Visit List Branch Filter Smoke
* Deployed commit: c9a5ebb
* Deployed tag: sprint-23-phase-23-9-3-rme-visit-list-branch-filter
* VPS path: /var/www/asia-dental-lab-v2
* Environment: pilot
* Maintenance mode after deploy: OFF

## 3. Backup Evidence

* Backup file: storage/app/deploy-backups/sprint-23-phase-23-9-4-vps-visit-list-branch-filter-20260613-015453.sql
* Backup size: 374K
* Backup completed before deploy: YES

## 4. Deployment Evidence

* Checkout to c9a5ebb: PASS
* composer install: PASS
* npm ci: PASS with existing Node warning
* npm run build: PASS
* php artisan migrate --force: PASS, nothing to migrate
* PermissionSeeder: PASS
* RoleSeeder: PASS
* Owner role ensured: PASS
* MAIN hidden from RME/Inventory dropdowns: PASS
* Cache rebuild: PASS
* Storage permissions fixed: PASS
* App live after deploy: PASS

## 5. HTTP Smoke

* /login: 200 OK
* /rme/visits before auth: 302 to /login, not 500

## 6. VPS Data Verification

Current commit:
c9a5ebb

Operational RME branches:

* ATG3 — Cabang Antang
* LDK2 — Cabang Landak
* TKM1 — Cabang Telkomas

MAIN flags:

* active=1
* rme=0
* inventory=0

Visit query:

* VIS-20260613-001
* Megasanti
* branch=ATG3 — Cabang Antang
* status=waiting
* branch_id=3
* clinic_id=null

## 7. Browser Smoke Result

Daftar Kunjungan:

* /rme/visits terbuka setelah login: PASS
* Filter Semua Cabang RME menampilkan Megasanti / VIS-20260613-001: PASS
* Filter ATG3 menampilkan Megasanti / VIS-20260613-001: PASS
* Filter TKM1 tidak menampilkan Megasanti: PASS
* Status waiting terlihat: PASS
* Tidak ada 500 error: PASS

Regression:

* Create visit tetap terbuka: PASS
* Existing patient visit tetap bisa dibuat: PASS
* New patient visit tetap bisa dibuat: PASS
* Master Data Cabang terbuka: PASS

## 8. Resolution

Bug resolved:
Megasanti / VIS-20260613-001 now appears in Daftar Kunjungan.

The ATG3 visit is no longer hidden by BranchContext fallback.

## 9. Watch Items

* Node VPS still v18 while @tailwindcss/oxide requires Node >=20.
* npm audit still reports 5 vulnerabilities.
* Legacy mst_clinics is intentionally preserved for compatibility.
* Backfill old patients to new RM format is not done yet.

## 10. Next Recommended Phase

Sprint 23 Phase 23.10 — RME Pilot Data Entry Hardening

Suggested scope:

* Verify create visit flow from registration to cashier.
* Harden existing patient branch behavior.
* Add safe old patient RM/backfill preview report.
* Confirm treatment/tariff/payment flow after branch-source changes.
* Prepare pilot checklist for clinic users.
