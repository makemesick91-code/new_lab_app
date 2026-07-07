# DaengtisiaMS UI Implementation Checklist (UIX-1)

Referensi: [design canvas](./daengtisiams-design-canvas.html) · [developer guide](./daengtisiams-developer-guide.html) · [tokens](./daengtisiams-design-tokens.md) · [governance](./daengtisiams-ui-governance.md).

## Acceptance — UIX-1 foundation
- [x] ENT foundation complete sebelum mulai (closure GO, tag `enterprise-foundation-go`).
- [x] Design references tersimpan di `docs/ui/`.
- [x] Design tokens ditambahkan (`tailwind.config.js`, `resources/css/app.css`, docs token).
- [x] Reusable Blade components `x-ui.*` ada (extend existing + baru).
- [x] Layout shell aman (sidebar/topbar reskin token, tanpa ubah struktur/route).
- [x] UI governance doc ada + optional check command.
- [x] Component catalog / preview tersedia.
- [x] Tidak ada rewrite business logic.
- [x] Tidak ada regresi route/permission/BranchContext.
- [x] Tidak ada regresi RME/cashier/inventory logic.
- [x] `npm run build` pass · `php artisan test` pass.
- [x] Gold usage terbatas (bukan primary CTA).
- [x] Responsive & aksesibilitas minimum didokumentasikan.
- [x] Strategi migrasi & future UIX path didokumentasikan.

## Per-component checklist (untuk sprint UIX berikutnya)
Setiap komponen `x-ui.*` harus mendukung (bila relevan): default · hover · focus · disabled · loading · variant error/warning/success/info · atribut aksesibilitas · slot/prop API jelas · Alpine minimal.

## Acceptance — UIX-2 Owner Dashboard polish (2026-07-07)
- [x] Owner KPI block (`dashboards/owner-kpi.blade.php`) memakai `x-ui.card`/`x-ui.kpi-card`/`x-ui.table`/`x-ui.badge`/`x-ui.button`/`x-ui.input`/`x-ui.select`/`x-ui.empty-state`.
- [x] Owner section `dashboard.blade.php` + komponen `x-owner-dashboard.*` retheme token (teal → brand biru; amber/emerald/sky/rose → warning/success/info/danger).
- [x] Gold accent **hanya** untuk KPI Total Pendapatan (revenue) via `:accent`; tidak ada gold sebagai CTA.
- [x] Warning tetap orange, danger merah, success hijau, info biru.
- [x] Tidak ada perubahan logic KPI / controller / service / route / permission / BranchContext.
- [x] String kunci test dipertahankan (`Dashboard KPI Owner`, `Low Stock`).
- [x] `architecture:ui-governance-check` diperkuat dengan rule owner-dashboard (non-brittle).
- [x] Evidence: `docs/sprints/uix-2-dashboard-owner-polish.md`.

## Migration order
1. [x] UIX-2 Dashboard Owner polish
2. UIX-3 Kunjungan list polish
3. UIX-4 RME + Odontogram polish
4. UIX-5 Kasir/payment polish
5. UIX-6 Inventory table/dashboard polish
6. UIX-7 Lab pipeline polish
7. UIX-8 Reports/print/PDF polish
