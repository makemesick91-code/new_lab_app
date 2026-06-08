# Sprint 18 — Daengtisia Management System Rebranding Plan

## Status
IN PROGRESS

## Tujuan
Mengubah branding aplikasi dari Asia Dental Lab Management System / ADLMS menjadi Daengtisia Management System tanpa merusak workflow inventory, procurement, reporting, delivery, QC, invoice, dan modul lama yang sudah stabil.

## Nama Baru
- Nama resmi: Daengtisia Management System
- Nama pendek: DaengtisiaMS
- Singkatan teknis: DMS

## Scope Sprint 18
- Ubah APP_NAME menjadi Daengtisia Management System
- Ubah title browser
- Ubah login page
- Ubah sidebar branding
- Ubah footer
- Ubah label aplikasi yang tampil ke user
- Ubah teks Asia Dental Lab / ADLMS menjadi Daengtisia Management System jika hanya user-facing
- Jangan rename database
- Jangan rename namespace besar
- Jangan ubah migration lama
- Jangan ubah route lama yang sudah stabil
- Jangan menghapus modul lab/inventory lama

## Yang Tidak Dikerjakan di Sprint Ini
- Belum membuat modul RME
- Belum membuat modul antrian
- Belum membuat billing pasien klinik
- Belum membuat cicilan
- Belum membuat integrasi lab gigi palsu baru
- Belum rename database
- Belum rename folder project
- Belum refactor namespace besar

## Risiko
Rebranding harus dilakukan secara aman. Perubahan hanya pada teks, branding, layout, dan konfigurasi yang tampil ke user. Jangan menyentuh business logic inventory/procurement/reporting.

## Quality Gate
Sebelum commit:
- php artisan optimize:clear
- php artisan route:list
- php artisan test
- npm run build
- git status

## Output
Aplikasi tampil sebagai Daengtisia Management System, tetapi seluruh workflow lama tetap berjalan.
