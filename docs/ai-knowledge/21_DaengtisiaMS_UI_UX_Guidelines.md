# DaengtisiaMS — UI/UX Guidelines

## Tujuan
Panduan layout, komponen, sidebar, responsive, tabel, form, print, dan pola interaksi RME.

## Ringkasan
Design system resmi: teal primary, TailAdmin-style components (`x-ui.*`), settings-shell, Figtree font. Legacy indigo deprecated.

## Konteks DaengtisiaMS
UI tidak boleh mengorbankan branch isolation, permission gates, atau kebenaran data ledger.

## File / Area Repo Terkait
- `docs/ui_design_system.md` — **otoritas UI**
- `resources/views/layouts/` — app, sidebar, navigation
- `resources/views/components/ui/` — card, table, badge, button
- `resources/views/components/settings-shell.blade.php`
- Reference view: `resources/views/inventory/products/index.blade.php`
- RME views: `resources/views/rme/`
- `resources/js/app.js` — Alpine components (odontogram, RM swipe)
- `tailwind.config.js`

## Aturan Utama

### Layout
- App shell: sidebar + topbar + content canvas `bg-gray-100`
- Settings pages: `x-settings-shell`
- Dashboard: owner-kpi partial + existing cards

### Sidebar
- Canonical: `resources/views/layouts/sidebar.blade.php`
- Menu gated `@can` / `@canany` — tidak hanya hidden CSS
- Hindari flicker: permission check konsisten

### Warna
- Primary: **teal-700** (bukan indigo untuk view baru)
- Semantic badges: emerald success, amber warning, rose danger, sky info

### Typography
- Font: **Figtree** only
- Page title: `text-xl font-semibold`
- Eyebrow: `text-xs uppercase tracking-wide text-teal-700`

### Tabel & form
- Gunakan `x-ui.table`, `x-ui.card`, `x-ui.badge`, `x-ui.button`
- Filter bar compact; mobile responsive
- Empty states jelas — jangan fake data

### Print pages
- Dedicated print-friendly Blade
- Odontogram: HTML `<table>` — no flexbox (dompdf)
- Visit bundle: `window.print()` + optional PDF route
- Sembunyikan KTP & elemen navigasi saat print

### RME khusus
- **Swipeable sheets** (Sprint 64): sheet nav partial, touch swipe tanpa JS dep baru
- **Scroll restore**: `sessionStorage` keys `rmws:{patientId}:sheet` / `:scroll`
- **Handwriting canvas**: overlay editor → save → preview
- **Room gate banner**: visit detail jika belum ada ruangan

### 3D UI
- **Future direction only** — tidak ada implementasi 3D UI di repo saat ini
- Jangan assume WebGL/Three.js

### Bahasa UI
- Banyak label Indonesia di Blade meski locale `en`
- Lihat `docs/ui_language_migration_summary.md` untuk status migrasi

## Workflow / Alur
**Modernisasi halaman:**
1. Baca `ui_design_system.md`
2. Copy pola dari `inventory/products/index`
3. Gunakan komponen `x-ui.*`
4. Gate actions dengan `@can`
5. `npm run build` jika ubah JS/Alpine

## Struktur Teknis
Komponen utama:
- `x-ui.card`, `x-ui.table`, `x-ui.badge`, `x-ui.button`
- `x-settings-shell`
- Partial RME: `rm-sheet-nav`, `visit-nav-arrows`

## Hal yang Tidak Boleh Diubah Sembarangan
- Jangan introduce React/Vue
- Jangan tampilkan menu tanpa permission check server-side
- Jangan expose branch selector lintas cabang untuk operator
- Jangan render KTP penuh

## Checklist Validasi
- [ ] Mobile layout tidak pecah
- [ ] Print preview bersih
- [ ] Badge status match domain constants
- [ ] `npm run build` sukses jika assets berubah
- [ ] Sidebar item match route permission

## Catatan untuk AI
Load skill `ui-ux-modernizer` / `frontend-design` untuk pekerjaan UI besar — tetap dalam constraint Blade+Tailwind+Alpine.

Legacy pages (indigo) — converge saat disentuh, jangan mass refactor tanpa permintaan.
