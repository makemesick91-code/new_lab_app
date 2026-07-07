# DaengtisiaMS UI Foundation (UIX-1)

**Sprint:** UIX-1 — Luxury Healthcare Design System Foundation
**Status:** Foundation only (design system, shell, tokens, reusable components, governance, docs). **Not** a full-page rewrite.

## Design direction (locked)
- Luxury healthcare SaaS: bersih, mewah, profesional, ringan, mudah dibaca.
- **Putih / off-white** sebagai background utama.
- **Biru** sebagai warna aksi, focus, active state, link, dan **primary CTA**.
- **Gold/kuning** hanya aksen premium terbatas — **tidak boleh** jadi primary CTA.
- Dioptimalkan untuk klinik sibuk: cepat dibaca, cepat dipakai, tidak melelahkan operator.

## Source of truth (design references)
- [`daengtisiams-design-canvas.html`](./daengtisiams-design-canvas.html) — layar & komponen (Dashboard Owner, RME/Odontogram, Kunjungan, Kasir, sistem komponen, Inventory & Lab rules).
- [`daengtisiams-developer-guide.html`](./daengtisiams-developer-guide.html) — konsep visual, palet/token, tipografi, spacing/radius/shadow, layout, guideline komponen, pola Tailwind/Blade/Alpine, responsive, state/akses, tabel/form, print/PDF, aksesibilitas, performance, aturan gold, strategi migrasi, prioritas, acceptance, governance.

> Kedua HTML di atas diauthor in-repo sebagai refleksi kanonik dari file Claude Design ("DaengtisiaMS - Layar & Komponen" dan "DaengtisiaMS - Panduan Developer"). Jika file upload asli tersedia kemudian, timpa dengan versi asli namun pertahankan token & governance yang sama.

## What UIX-1 delivers
1. **Design tokens** semantik di `tailwind.config.js` + `resources/css/app.css` (lihat [design tokens](./daengtisiams-design-tokens.md)).
2. **Layout shell** aman: sidebar putih + active biru + aksen gold tipis, topbar (cabang/user/search) — reskin token, tanpa mengubah struktur/route.
3. **Reusable Blade components** `x-ui.*` (extend existing badge/button/card/table + komponen baru input/select/textarea/alert/modal/empty-state/skeleton/page-header/filter-bar/kpi-card).
4. **Component catalog** internal untuk validasi developer.
5. **Governance** + optional `php artisan architecture:ui-governance-check`.

## Hard boundaries (tidak diubah di UIX-1)
- Business logic, schema (no migration), route names, permissions, BranchContext isolation.
- RME finalization, cashier consent gate, inventory ledger-only stock, procurement/lab logic.
- View print/PDF (RME, odontogram, struk) dipertahankan.

## Deferred (future UIX sprints)
UIX-2 Dashboard Owner · UIX-3 Kunjungan · UIX-4 RME + Odontogram · UIX-5 Kasir/payment · UIX-6 Inventory · UIX-7 Lab pipeline · UIX-8 Reports/print/PDF.
