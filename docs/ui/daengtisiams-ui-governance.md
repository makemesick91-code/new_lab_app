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

## Ownership & review
- Perubahan token = review lintas modul (berdampak global).
- Komponen `x-ui.*` = owner design system; PR wajib update katalog + docs.
