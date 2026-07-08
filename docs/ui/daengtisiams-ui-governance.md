# DaengtisiaMS UI Governance (UIX-1)

Aturan wajib untuk semua pekerjaan UI setelah UIX-1. Dapat diperiksa (ringan) via `php artisan architecture:ui-governance-check`.

## Rules
1. **Token wajib.** Semua UI baru memakai design token (`tailwind.config.js`). Dilarang hardcode `#hex`/warna acak di Blade.
2. **Komponen wajib.** Gunakan `x-ui.*` bila memungkinkan; jangan duplikasi class komponen di halaman.
3. **Gold terbatas.** Gold hanya untuk highlight premium, garis aksen, highlight revenue/KPI, dan status `cashier_pending`. **Gold tidak boleh jadi primary CTA.** Primary CTA = biru.
4. **Evidence.** PR yang mengubah UI wajib menyertakan screenshot/bukti untuk state yang berubah (default/hover/focus/disabled/loading/error bila relevan).
5. **Catalog.** Komponen `x-ui.*` baru wajib ditambahkan ke component catalog (`/dev/ui-catalog` atau view katalog).
6. **Ringan.** Tanpa library UI berat, animasi berat, atau aset besar. Hanya Tailwind + Alpine + Blade.
7. **Density seimbang.** Tabel & form mempertahankan kepadatan yang seimbang (lihat design tokens).
8. **Print/PDF dipertahankan.** View print/PDF (RME/odontogram/struk/receipt) tidak boleh regresi; template PDF tetap dompdf-safe.
9. **Aksesibilitas minimum.** Focus ring terlihat, label input, kontras cukup, `aria-*` pada modal/alert, target sentuh memadai.
10. **Responsive wajib.** Mobile-first; body tidak boleh horizontal-scroll; tabel lebar `overflow-x-auto`.
11. **Tanpa perubahan flow bisnis untuk poles visual.** Jangan ubah route penting, permission, BranchContext, RME finalize, cashier consent, atau inventory ledger demi tampilan.
12. **Adopsi bertahap.** Jangan migrasi semua modul dalam satu sprint; reskin primitif bersama dulu.

## Enforcement
- `architecture:ui-governance-check` (non-brittle): memverifikasi dokumen UI ada, komponen kunci `x-ui.*` ada, dokumen token ada, dan tidak ada penyalahgunaan gold sebagai CTA yang jelas pada komponen UI foundation.
- Gunakan bersama gate governance foundation lain; check ini informational, tidak memblokir `combinedDecision`.

### UIX-2 — Owner Dashboard (ditambahkan 2026-07-07)
`architecture:ui-governance-check` juga memverifikasi (ringan, non-brittle) untuk halaman Dashboard Owner:
- View owner dashboard ada (`resources/views/dashboard.blade.php`, `resources/views/dashboards/owner-kpi.blade.php`).
- Owner KPI block memakai komponen `x-ui.kpi-card`.
- **Tidak ada** class brand legacy `teal-*` yang diperkenalkan kembali di file dashboard owner (UIX-1 memigrasikan brand teal → biru).
- Gold tetap accent-only di owner KPI view (dilarang `variant="gold"` sebagai CTA). Aksen gold owner dashboard **hanya** untuk KPI Total Pendapatan (revenue).
- Dokumen evidence sprint `docs/sprints/uix-2-dashboard-owner-polish.md` ada (soft signal).

### UIX-3 — Kunjungan list = reference list page (ditambahkan 2026-07-07)
`architecture:ui-governance-check` juga memverifikasi (ringan, non-brittle) untuk halaman Kunjungan (`resources/views/rme/visits/index.blade.php`) sebagai **reference implementation seluruh list page**:
- Memakai `x-ui.page-header`, `x-ui.filter-bar`, `x-ui.table`, `x-ui.badge`, `x-ui.button`, `x-ui.empty-state`.
- Status badge memakai `:status` (design-system status→tone map).
- **Tidak ada** class brand legacy `teal-*`.
- **Tidak ada** warna hex hardcoded.
- Dokumen evidence sprint `docs/sprints/uix-3-kunjungan-list-polish.md` ada (soft signal).

**Rule permanen — list-page standard:** setiap halaman list/index baru atau yang di-polish (RME, Kasir, Inventory, Procurement, Lab, Report) **wajib** memakai `x-ui.page-header` + `x-ui.filter-bar` + `x-ui.table` + `x-ui.badge` (`:status`) + `x-ui.button` + `x-ui.empty-state` + semantic token; dilarang table/badge/button dari nol, hardcode warna, atau teal legacy.

### UIX-4 — RME + Odontogram = reference clinical pages (ditambahkan 2026-07-07)
`architecture:ui-governance-check` juga memverifikasi (ringan, non-brittle) untuk halaman klinis RME detail (`resources/views/rme/visits/show.blade.php`) dan Odontogram (`resources/views/rme/visits/odontogram/show.blade.php`) sebagai **reference implementation seluruh halaman klinis**:
- RME detail memakai `x-ui.page-header`, `x-ui.card`, `x-ui.badge`, `x-ui.button`, `x-ui.alert`.
- Status kunjungan memakai `:status` (design-system status→tone map).
- **Tidak ada** class brand legacy `teal-*` di kedua view.
- **Tidak ada** warna hex hardcoded di kedua view.
- Gold **tidak** dipakai sebagai CTA klinis (`variant="gold"` dilarang) — gold accent-only, bukan warna aksi/warning/danger.
- **Tidak ada** field KTP/NIK (`->ktp`/`->nik`/`->identity_number`) yang dirender di permukaan detail klinis (privacy).
- Dokumen evidence sprint `docs/sprints/uix-4-rme-odontogram-polish.md` ada (soft signal).

**Rule permanen — clinical-page standard:** setiap halaman klinis (RME detail, Odontogram, Rekam Medis, Resep, dan turunannya) **wajib**:
- memakai semantic token (navy/ink/hairline/brand/surface/status) — dilarang teal legacy & hex hardcoded;
- tombol aksi klinis memakai `x-ui.button`, status klinis memakai `x-ui.badge` (`:status` bila status domain), kartu memakai `x-ui.card` bila aman;
- **tidak** menampilkan KTP/NIK/scan dokumen/catatan sensitif mentah yang sebelumnya tidak tampil;
- gold **dilarang** untuk warning/danger/aksi klinis (accent-only);
- **warna status klinis Odontogram** (karies/hilang/tambalan/crown/PSA/normal) tetap dipertahankan agar tetap distinguishable & paritas dengan cetak/PDF — bukan diganti brand;
- print/PDF **wajib** dicek ulang setelah perubahan UI RME;
- **tanpa** perubahan controller/service/query/permission/BranchContext/route/schema untuk sprint poles presentation-only.

### UIX-5 — Kasir / Payment = reference financial workflow pages (ditambahkan 2026-07-07)
`architecture:ui-governance-check` juga memverifikasi (ringan, non-brittle) untuk halaman Kasir/Pembayaran sebagai **reference implementation seluruh alur finansial**:
- Kasir list (`resources/views/rme/cashier/index.blade.php`) memakai `x-ui.page-header` + `x-ui.filter-bar` + `x-ui.table` + `x-ui.badge` + `x-ui.button` + `x-ui.empty-state` (list-page standard UIX-3).
- Halaman Pembayaran (`resources/views/rme/cashier/payment/create.blade.php`) memakai `x-ui.page-header` + `x-ui.card` + `x-ui.badge` + `x-ui.button` + `x-ui.alert` (consent-gate memakai `x-ui.alert`).
- **Tidak ada** class brand legacy `teal-*`, **tidak ada** `variant="gold"` (gold bukan CTA/status/warning/danger pembayaran), dan **tidak ada** field KTP/NIK (`->ktp`/`->nik`/`->identity_number`) yang dirender di seluruh permukaan Kasir (`index`, `show`, `create`, `payment/create`, `receipt/show`, `receivables`, `handoff`, `follow-ups/create`).
- Hex **tidak** discan pada permukaan Kasir karena kwitansi (`receipt/show.blade.php`) memakai `background: #fff` khusus cetak.
- Dokumen evidence sprint `docs/sprints/uix-5-kasir-payment-polish.md` ada (soft signal).

**Rule permanen — cashier/payment-page standard:** setiap halaman Kasir/Pembayaran/Invoice/Piutang **wajib**:
- memakai semantic token — dilarang teal legacy;
- tombol aksi pembayaran memakai `x-ui.button` (brand blue / status semantik), **tidak pernah** gold;
- status pembayaran/invoice memakai `x-ui.badge`, kartu billing/invoice memakai `x-ui.card` bila aman, tabel mengikuti list/table standard UIX-3;
- consent gate memakai pola `x-ui.alert`/`x-ui.card`/`x-ui.badge` — **tanpa** mengubah field/validasi consent;
- **tidak** menampilkan KTP/NIK/identitas sensitif di permukaan Kasir;
- print/kwitansi dicek ulang setelah perubahan UI Kasir;
- **tanpa** perubahan logic pembayaran/consent/receivable/partial-payment/invoice-status/transition, dan **tanpa** perubahan controller/service/query/permission/BranchContext/route/schema — presentation-only.

### UIX-6 — Inventory = reference warehouse/operator pages (ditambahkan 2026-07-07)
`architecture:ui-governance-check` juga memverifikasi (ringan, non-brittle) untuk halaman Inventory sebagai **reference implementation seluruh permukaan gudang/persediaan**:
- Daftar produk (`resources/views/inventory/products/index.blade.php`) sebagai **reference inventory list** memakai `x-ui.page-header` + `x-ui.filter-bar` + `x-ui.table` + `x-ui.badge` + `x-ui.button` + `x-ui.empty-state` (list-page standard UIX-3).
- Kartu stok (`resources/views/inventory/stock/card.blade.php`) sebagai **reference detail berbasis ledger** memakai `x-ui.page-header` + `x-ui.card` + `x-ui.badge` + `x-ui.table`.
- **Tidak ada** class brand legacy `teal-*`, **tidak ada** `variant="gold"` (gold bukan CTA/status/warning/danger persediaan), dan **tidak ada** penulisan atribut stok mutable (`->current_stock =`, `->derived_stock =`, `->stock_quantity =`, dst.) di 11 view Inventory yang dipoles (stok tetap ledger-derived).
- Dokumen evidence sprint `docs/sprints/uix-6-inventory-polish.md` ada (soft signal).

**Rule permanen — inventory-page standard:** setiap halaman Inventory (dashboard, daftar item, current stock, kartu stok, low stock, movement/mutasi, procurement PR/PO/GR, transfer, stok opname, batch/lot/expiry) **wajib**:
- memakai semantic token (navy/ink/hairline/brand/surface/status) — dilarang teal legacy & hex hardcoded;
- daftar/list mengikuti list-page standard UIX-3 (`x-ui.page-header` + `x-ui.filter-bar` + `x-ui.table` + `x-ui.badge` + `x-ui.button` + `x-ui.empty-state`);
- status stok/batch/procurement/transfer/opname memakai `x-ui.badge` (low_stock/expired_soon → **warning**, out_of_stock/expired/void/rejected → **danger**, received/posted/approved/completed → **success**, submitted/in_transit/neutral → **info/brand**);
- tombol aksi memakai `x-ui.button` (brand blue / status semantik), gold **dilarang** untuk aksi/status/warning/danger persediaan (accent-only);
- KPI/summary memakai `x-ui.card`/`x-ui.kpi-card` bila aman; tabel besar `overflow-x-auto` + `x-ui.table`;
- **dilarang** memperkenalkan kolom/atribut stok mutable — stok tetap SUM movements (ledger-only);
- print/export Inventory dicek ulang setelah perubahan komponen bersama;
- **tanpa** perubahan ledger/stock-calc/valuation/procurement/transfer/opname/batch logic, dan **tanpa** perubahan controller/service/query/permission/BranchContext/route/schema — presentation-only.

### UIX-7 — Lab pipeline = reference lab workflow pages (ditambahkan 2026-07-07)
`architecture:ui-governance-check` juga memverifikasi (ringan, non-brittle) untuk permukaan Lab pipeline sebagai **reference implementation seluruh alur lab**:
- Komponen bersama baru `x-lab.status-badge` (`resources/views/components/lab/status-badge.blade.php`) memetakan kode status lab uppercase (lifecycle/priority/QC/delivery) ke tone semantik UIX-1 + label Indonesia, dirender lewat `x-ui.badge`. Ini komponen `x-lab.*` pertama.
- Daftar order lab (`resources/views/lab-orders/index.blade.php`) sebagai **reference lab list** memakai `x-ui.page-header` + `x-ui.filter-bar` + `x-ui.table` + `x-lab.status-badge` + `x-ui.button` + `x-ui.empty-state` (list-page standard UIX-3).
- Detail order lab (`resources/views/lab-orders/show.blade.php`) sebagai **reference lab detail** memakai `x-ui.page-header` + `x-ui.button` + `x-lab.status-badge`.
- **Tidak ada** class brand legacy `teal-*`, **tidak ada** `variant="gold"` (gold bukan CTA lab), dan **tidak ada** render `->ktp/nik/identity_number` di 14 view lab yang dipoles. Hex **tidak** dipindai (signature pad delivery menyimpan warna tinta kanvas di JS — presedennya sama dengan UIX-5 yang melewati hex untuk struk).
- Dokumen evidence sprint `docs/sprints/uix-7-lab-pipeline-polish.md` ada (soft signal).

**Rule permanen — lab-page standard:** setiap halaman Lab (order list/detail, kandidat RME, produksi, QC, pengiriman/POD) **wajib**:
- memakai semantic token (navy/ink/hairline/brand/surface/status) — dilarang teal legacy;
- daftar/list mengikuti list-page standard UIX-3;
- status lifecycle/priority/QC/delivery memakai `x-lab.status-badge` (bukan badge dari nol) → tone semantik (QC_PASSED/DELIVERED/COMPLETED → **success**, ON_HOLD/QC_PENDING/URGENT/REVISION → **warning**, CANCELLED/REJECTED/FAIL/SUPER_URGENT → **danger**, RECEIVED/ASSIGNED/IN_PRODUCTION/IN_DELIVERY → **info**, DRAFT/NORMAL → **neutral**);
- tombol aksi memakai `x-ui.button` dengan hirarki jelas (primary/secondary/success/warning/danger); gold **dilarang** untuk aksi lab (accent-only);
- **tanpa** perubahan LabOrder lifecycle / RME→Lab candidate generation / invoice / payment, dan **tanpa** perubahan controller/service/query/permission/policy/BranchContext/route/schema — presentation-only; **tanpa** render KTP/NIK penuh.

### UIX-8 — Reports / print / PDF = reference report pages (ditambahkan 2026-07-07)
`architecture:ui-governance-check` juga memverifikasi (ringan, non-brittle) untuk permukaan laporan/cetak/PDF:
- Laporan RME (`resources/views/rme/reports/patients.blade.php` + `payments.blade.php`) sebagai **reference report list** memakai `x-ui.page-header` + `x-ui.filter-bar` + `x-ui.table` + `x-ui.badge` + `x-ui.button` + `x-ui.empty-state` (list-page standard UIX-3); total nominal/revenue memakai `x-ui.kpi-card accent` (gold accent-only, khusus revenue).
- Hub laporan inventory (`resources/views/inventory/reports/index.blade.php`) + index batch memakai `x-ui.page-header`; warna **status stok semantik** (empty/low/overstock/normal, masuk/keluar) dipertahankan (bukan brand) sesuai preseden UIX-6.
- Template cetak/PDF (browser `window.print()` & dompdf) di-retint teal→brand blue, tetap **berbasis `<table>`** untuk grid data (bukan flexbox), zebra rows untuk keterbacaan.
- **Tidak ada** class brand legacy `teal-*`, **tidak ada** `variant="gold"` CTA, dan **tidak ada** render `->ktp/nik/identity_number` di permukaan laporan/cetak yang dipoles. Hex **tidak** dipindai (template cetak menyimpan brand hex inline — preseden sama dengan struk UIX-5).
- Dokumen evidence sprint `docs/sprints/uix-8-reports-print-pdf-polish.md` ada (soft signal).

**Rule permanen — report/print-page standard:** setiap halaman laporan **wajib** memakai list-page standard UIX-3 (page-header/filter-bar/table/badge/button/empty-state) + semantic token; total ringkasan boleh `x-ui.kpi-card` (gold accent hanya untuk revenue); template cetak/PDF **wajib** table-based (dompdf-safe, hindari flexbox untuk grid data) dan memakai brand hex; **tanpa** perubahan kalkulasi laporan / receivable / payment / stock valuation / KPI, **tanpa** perubahan kolom export, dan **tanpa** render KTP/NIK penuh.

### UIX-15 — Global component foundation hardening (ditambahkan 2026-07-08)
`architecture:ui-governance-check` juga memverifikasi (ringan, non-brittle) fondasi komponen global:
- `x-ui.badge` resolusi `status` **case-insensitive** (nilai di-lowercase sebelum lookup) dan **wajib** memetakan status finansial kanonik (`unpaid`/`partial`/`void`/`paid`); status tak dikenal jatuh ke `neutral` (default aman). Tidak ada pemetaan lama yang berubah tone; penambahan bersifat additive + tested (`unpaid`/`partial`/`overstock` → warning, `registered`/`submitted` → info, `posted`/`received` → success, `void` → danger).
- `x-ui.table` **tanpa** class gray legacy dan memakai token `divide-hairline` (+ caption `text-navy`).
- Komponen domain badge (`x-lab.status-badge`, `x-rme.invoice-summary`) **wajib** render melalui `x-ui.badge` — batas domain terdokumentasi, **bukan** design system kedua.
- Dokumen `docs/ui_design_system.md` mendokumentasikan fondasi UIX-15 + evidence sprint `docs/sprints/uix-15-global-component-foundation-hardening.md` ada (soft signal).

**Rule permanen — global component foundation:** `x-ui.*` adalah **satu-satunya** design system (Blade + Tailwind + Alpine, tanpa React/Vue/SPA/library UI berat). Backward compatibility komponen dijaga (tanpa rename/hapus prop publik). **Tanpa** business logic di komponen, **tanpa** keputusan permission/policy di komponen UI generik, **tanpa** mutasi/form/route tersembunyi. Tone semantik danger/success/warning/status berasal dari fondasi; badge status kanonik memakai `:status`; tabel/filter/page-header/card/alert/button/empty-state/form memakai komponen kanonik; print/PDF tetap dompdf table-safe; **tanpa** render KTP/NIK/scan/catatan klinis mentah/secret/env.

## Ownership & review
- Perubahan token = review lintas modul (berdampak global).
- Komponen `x-ui.*` = owner design system; PR wajib update katalog + docs.

## UIX-16 — Responsive, tablet & operator rules

Presentation-only responsive hardening for laptop/tablet operators. No route, policy,
query, permission, schema, financial, RME, Inventory, Lab, dashboard, or master-data
behaviour changes.

- **Table overflow:** every data table renders through `x-ui.table` (`overflow-x-auto`
  scroll container); wide tables scroll inside their container, never the page body.
- **Filter-bar wrapping:** `x-ui.filter-bar` stacks on narrow (`flex-col`), horizontal
  from `md` (`md:flex-row md:flex-wrap`), actions wrap (`flex-wrap`).
- **Page-header action wrapping:** `x-ui.page-header` stacks (`flex-col` → `sm:flex-row`),
  actions wrap (`flex-wrap`).
- **Button group wrapping:** action groups wrap on narrow widths, never a forced fixed row.
- **Card/detail-grid stacking:** detail & summary grids use a `grid-cols-1` base and
  widen from `sm`/`md` up; no fixed `grid-cols-2`/`grid-cols-3` *text-sm* detail grid.
- **Form stacking:** form fields are `w-full` inside stacking containers.
- **No hidden critical data** without a safe responsive alternative (scroll / stack / wrap);
  actions are never removed.
- **No heavy JS/responsive dependency** — Tailwind responsive utilities + Blade + Alpine only.
- **No sensitive data exposure** (KTP/NIK/scans/raw notes/secrets/env) from responsive polish.

Enforced by `architecture:ui-governance-check --strict`.

## UIX-17 — Accessibility, error state & empty state rules

Additive accessibility/semantics hardening for daily operators (keyboard + screen
reader). No route, policy, permission, Gate, BranchContext, query, schema,
validation-rule, financial, RME, Inventory, Lab, dashboard, or master-data behaviour
changes. Server-side validation is never replaced by frontend-only validation.

- **Explicit labels & association:** `x-ui.input`/`select`/`textarea` tie the label to the
  control via `for`/`id`.
- **Validation error visibility:** errors render near the field (danger token) and are
  associated with the control via `aria-describedby="{id}-error"`; invalid state via
  `aria-invalid="true"`; errors resolve automatically from the `$errors` bag by `name`.
- **Required marker:** backend-required fields carry a visible danger asterisk +
  `aria-required="true"` (never invents a requirement the backend does not have).
- **Helper text:** `help` renders in soft-ink and is associated via
  `aria-describedby="{id}-help"` when there is no error; it supplements, never replaces, the label.
- **Disabled/loading clarity:** `x-ui.button` dims + disables, exposes `aria-disabled`, and on
  `loading` shows a spinner + `aria-busy="true"` + an `sr-only` "Memproses…" label.
- **Danger action clarity:** destructive actions use `x-ui.button variant="danger"` / a `danger` alert.
- **Empty/no-data copy:** `x-ui.empty-state` keeps `title` + `description` explaining what
  happened / what to do next; no invented actions.
- **Alert semantics:** `x-ui.alert` uses a semantic variant and keeps `role="alert"`.
- **No fake/noisy ARIA:** add ARIA only when it adds semantics native HTML lacks; never alter
  keyboard/submission behaviour.
- **No sensitive data exposure** (KTP/NIK/scans/raw notes/secrets/env).
- **No formal WCAG claim** unless a real audit is performed.

Enforced by `architecture:ui-governance-check --strict`: form components wire
`aria-describedby` (+`-error`/`-help` id) and expose `aria-invalid`/`aria-required`;
`x-ui.button` exposes `aria-busy` + `sr-only` loading label; `x-ui.alert` keeps
`role="alert"`; `x-ui.empty-state` keeps a `description`.
