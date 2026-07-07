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

## Acceptance — UIX-3 Kunjungan list polish (2026-07-07)
- [x] Kunjungan list (`rme/visits/index.blade.php`) memakai `x-ui.page-header`/`x-ui.filter-bar`/`x-ui.input`/`x-ui.select`/`x-ui.kpi-card`/`x-ui.card`/`x-ui.table`/`x-ui.badge`/`x-ui.button`/`x-ui.empty-state`.
- [x] Status badge memakai `:status` (design-system status→tone map), label Indonesia sebagai slot.
- [x] Semua warna via semantic token (navy/ink/hairline/brand/surface); tidak ada teal legacy; tidak ada hex hardcoded.
- [x] Filter bar (search/tanggal/status/cabang) + quick status tabs presentation-only memakai param `status` yang sudah ada.
- [x] Tidak ada perubahan controller/service/query/route/permission/BranchContext; nama param GET dipertahankan.
- [x] Halaman ini menjadi **reference implementation** untuk seluruh list page berikutnya.
- [x] `architecture:ui-governance-check` diperkuat dengan rule list-page (non-brittle).
- [x] Evidence: `docs/sprints/uix-3-kunjungan-list-polish.md`.

## List-page standard (wajib untuk semua list page berikutnya)
Setiap halaman list/index (RME, Kasir, Inventory, Procurement, Lab, Report) **wajib**: `x-ui.page-header` · `x-ui.filter-bar` · `x-ui.table` · `x-ui.badge` (`:status` untuk status domain) · `x-ui.button` · `x-ui.empty-state` · semantic token. Dilarang membuat table/badge/button dari nol, hardcode warna, atau memakai teal legacy.

## Acceptance — UIX-4 RME + Odontogram polish (2026-07-07)
- [x] RME detail (`rme/visits/show.blade.php`) memakai `x-ui.page-header`/`x-ui.card`/`x-ui.badge`/`x-ui.button`/`x-ui.alert`; status kunjungan memakai `:status`.
- [x] Transisi status (Check-in/Mulai/Selesai Pemeriksaan/Batalkan) memakai `x-ui.button` variant (warning/primary/success/danger) — bukan tombol raw hardcoded.
- [x] Banner room-gate & alert selesai/batal memakai `x-ui.alert`; chip consent memakai `x-ui.badge`.
- [x] Odontogram (`odontogram/show.blade.php`) chrome teal → brand; focus ring, tombol tambah baris, kartu DMF-T total memakai token brand.
- [x] **Warna status klinis Odontogram** (karies=merah/hilang=gelap/tambalan=biru/crown=amber/PSA=sky/normal=hijau) **dipertahankan** demi distinguishability & paritas cetak/PDF.
- [x] Riwayat kunjungan partial: highlight & link teal → brand, status memakai `x-ui.badge :status`.
- [x] Tidak ada teal legacy / hex hardcoded / gold-CTA di view klinis; KTP/NIK tidak dirender.
- [x] Tidak ada perubahan controller/service/query/route/permission/BranchContext/schema/print-logic.
- [x] `architecture:ui-governance-check` diperkuat dengan rule clinical-page (non-brittle).
- [x] Evidence: `docs/sprints/uix-4-rme-odontogram-polish.md`.

## Clinical-page standard (wajib untuk semua halaman klinis berikutnya)
Setiap halaman klinis (RME detail, Odontogram, Rekam Medis, Resep, turunannya) **wajib**: semantic token · `x-ui.button` (aksi) · `x-ui.badge` (`:status` status domain) · `x-ui.card` (bila aman) · **tanpa** render KTP/NIK/scan/catatan sensitif mentah baru · gold **bukan** warning/danger/aksi · warna status klinis Odontogram tetap distinguishable & paritas cetak/PDF · print/PDF dicek ulang · **tanpa** perubahan controller/service/query/permission/BranchContext/route/schema.

## UIX-5 — Kasir / Payment polish (selesai 2026-07-07)
- [x] Kasir list (`cashier/index.blade.php`) → list-page standard: `x-ui.page-header` + `x-ui.filter-bar` + `x-ui.input` + `x-ui.table` + `x-ui.badge` + `x-ui.button` + `x-ui.empty-state`.
- [x] Detail tagihan (`cashier/show.blade.php`) & Buat tagihan (`cashier/create.blade.php`) → `x-ui.page-header`; hint tindakan → `x-ui.alert`.
- [x] Pembayaran (`cashier/payment/create.blade.php`) → `x-ui.page-header`; **consent gate → `x-ui.alert` (calm tapi jelas)** dengan nama field & validasi checkbox consent **tidak diubah**; Alpine x-data/x-ref/x-bind pembayaran & piutang **tidak diubah**.
- [x] Kwitansi (`cashier/receipt/show.blade.php`) → `x-ui.page-header` (screen-only); **CSS/isi cetak dipertahankan** (`@media print`, `background:#fff`, stempel LUNAS).
- [x] Piutang (`cashier/receivables.blade.php`) → `x-ui.page-header` + `x-ui.filter-bar` + `x-ui.input`/`x-ui.select`; status → `x-ui.badge`; nota WA manual → `x-ui.alert`.
- [x] Sinkron dokter–kasir (`cashier/handoff.blade.php`) → `x-ui.page-header` + `x-ui.empty-state`.
- [x] Follow-up piutang (`cashier/follow-ups/create.blade.php`) → `x-ui.page-header`; helper WhatsApp manual (client-only) dipertahankan.
- [x] Palet legacy (teal/emerald/amber/sky/rose/purple/red/blue/green/gray) → semantic token (brand/success/warning/info/danger/navy/ink/hairline) di seluruh view Kasir; partial klinis `partials/clinical-summary.blade.php` sengaja dibiarkan (label gray netral, preseden UIX-4).
- [x] Tidak ada teal legacy / gold-CTA / KTP-NIK di permukaan Kasir.
- [x] **Tanpa** perubahan logic pembayaran/consent/receivable/partial/invoice-status/transition; **tanpa** perubahan controller/service/query/route/permission/BranchContext/schema.
- [x] `architecture:ui-governance-check` diperkuat dengan rule cashier/payment (non-brittle).
- [x] Evidence: `docs/sprints/uix-5-kasir-payment-polish.md`.

## Cashier/payment-page standard (wajib untuk semua halaman finansial berikutnya)
Setiap halaman Kasir/Pembayaran/Invoice/Piutang **wajib**: semantic token · `x-ui.button` (aksi pembayaran, **tidak pernah** gold) · `x-ui.badge` (status pembayaran/invoice) · `x-ui.card` (billing/invoice, bila aman) · list/table standard UIX-3 · consent gate pola `x-ui.alert`/`x-ui.card`/`x-ui.badge` **tanpa** ubah field/validasi · **tanpa** render KTP/NIK · print/kwitansi dicek ulang · **tanpa** perubahan logic pembayaran/consent/receivable/partial/invoice-status/transition/controller/service/query/permission/BranchContext/route/schema.

## UIX-6 — Inventory polish (selesai 2026-07-07)
- [x] Dashboard (`inventory/dashboard.blade.php`) → eyebrow/select/CTA token + `x-ui.button` (UIX-2 dashboard standard); `x-inventory.kpi-card` tone dipetakan ke token status yang benar (50/100/700).
- [x] Daftar produk (`inventory/products/index.blade.php`) → **reference inventory list**: `x-ui.page-header` + `x-ui.filter-bar` + `x-ui.input`/`x-ui.select` + `x-ui.table` + `x-ui.badge` + `x-ui.button` + `x-ui.empty-state`; param GET (`search`,`is_active`) & rute tidak berubah.
- [x] Current stock (`inventory/stock/index.blade.php`) → page-header + filter-bar + `x-ui.kpi-card` + tabel token.
- [x] Kartu stok (`inventory/stock/card.blade.php`) → **reference detail ledger**: page-header + card + table; **urutan movement, tanda `quantity_in`/`quantity_out`, `running_balance` dipertahankan** (tanpa perubahan kalkulasi).
- [x] Low stock/expiry (`inventory/alerts/index.blade.php`) → page-header + filter-bar + card + table + empty-state; `orange`→`warning` token; badge severity via `_stock-severity-badge`.
- [x] Batch/lot (`inventory/batches/index.blade.php`) → page-header + `x-ui.alert` (nota) + filter-bar + card + table + empty-state; `searchable-product-select` dipertahankan.
- [x] PR/PO/GR/Transfer/Opname index → header `x-ui.page-header` + `x-ui.button`; chrome gray → token; status badge (PR/GR) via `x-ui.badge`.
- [x] Komponen bersama `x-inventory.*` + partial badge (teal/emerald/amber/rose/sky) → semantic token.
- [x] Tidak ada teal legacy / gold-CTA / atribut stok mutable di 11 view Inventory yang dipoles.
- [x] **Tanpa** perubahan ledger/stock-calc/valuation/procurement/transfer/opname/batch logic; **tanpa** perubahan controller/service/query/route/permission/BranchContext/schema/migration.
- [x] `architecture:ui-governance-check` diperkuat dengan rule inventory (non-brittle).
- [x] Evidence: `docs/sprints/uix-6-inventory-polish.md`; test `tests/Feature/Ui/InventoryUixTest.php`.

## Inventory-page standard (wajib untuk semua halaman persediaan berikutnya)
Setiap halaman Inventory **wajib**: semantic token · list mengikuti standard UIX-3 · status via `x-ui.badge` (low_stock/expired_soon=warning, out_of_stock/expired/void=danger, received/posted/approved=success, submitted/in_transit=info/brand) · aksi via `x-ui.button` (**tidak pernah** gold) · KPI via `x-ui.card`/`x-ui.kpi-card` · tabel besar `overflow-x-auto` + `x-ui.table` · **dilarang** kolom/atribut stok mutable (ledger-only) · print/export dicek ulang · **tanpa** perubahan ledger/stock/procurement/transfer/opname logic atau controller/service/query/permission/BranchContext/route/schema.

## Migration order
1. [x] UIX-2 Dashboard Owner polish
2. [x] UIX-3 Kunjungan list polish
3. [x] UIX-4 RME + Odontogram polish
4. [x] UIX-5 Kasir/payment polish
5. [x] UIX-6 Inventory table/dashboard polish
6. UIX-7 Lab pipeline polish
7. UIX-8 Reports/print/PDF polish
