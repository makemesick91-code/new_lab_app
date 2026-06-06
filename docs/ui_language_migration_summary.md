# UI Language Migration Summary

## Overview
Migrasi ini memastikan seluruh antarmuka pengguna (UI) ADLMS menggunakan Bahasa Indonesia secara konsisten untuk meningkatkan keterbacaan, mengurangi miskomunikasi operasional, dan menjaga konsistensi istilah lintas modul. Migrasi ini hanya menyentuh teks yang tampil ke user (label, tombol, heading, empty state, flash message, dan label status), tanpa mengubah kontrak internal aplikasi.

## Scope
Modul yang dimigrasikan:
- Dashboard
- Inventory
- Order Lab
- Produksi
- QC
- Delivery & POD
- Invoice
- Payment
- Reporting
- Settings
- User Management
- Authentication
- Profile

## Timeline
Ringkasan Step 1–8:
- Step 1 — UI Language Guideline: menetapkan standar resmi UI Bahasa Indonesia, kamus istilah, aturan status label, serta aturan format lokal.
- Step 2 — UI Language Audit: audit kandidat string UI Inggris dan area prioritas untuk migrasi.
- Step 3 — Navigation & Dashboard Migration: migrasi label navigasi utama dan dasbor ke Bahasa Indonesia.
- Step 4 — Inventory UI Migration: migrasi seluruh UI Inventory (produk, pemasok, lokasi, stok, kartu stok, stok opname) ke Bahasa Indonesia, termasuk penyesuaian test UI yang relevan.
- Step 5 — Operational UI Migration: migrasi UI operasional (Order Lab, Produksi, QC, Delivery, Invoice/Payment) ke Bahasa Indonesia.
- Step 6 — Global UI Message Migration: migrasi pesan UI global yang tampil ke user (empty state, aksi umum, dan pesan lintas modul) ke Bahasa Indonesia.
- Step 7 — Indonesian Formatting: standarisasi format tampilan lokal Indonesia (tanggal/waktu/angka/mata uang/qty/persen) menggunakan helper proyek.
- Step 8 — UI Localization Regression Test & English String Audit: audit regresi menyeluruh pada area UI utama, perbaikan sisa string Inggris yang tampil ke user, serta penambahan regression test agar UI tidak kembali ke Bahasa Inggris.

## Standards Adopted
Standar yang diadopsi dari [ui_language_guideline.md](file:///d:/new_lab_app/docs/ui_language_guideline.md):
- UI menggunakan Bahasa Indonesia.
- Identifier internal tidak diterjemahkan (source code, database, route, permission, policy, service, repository, migration).
- Status internal tetap memakai nilai internal (umumnya Bahasa Inggris); UI menampilkan label Indonesia melalui mapping.
- Istilah domain/teknis tertentu tetap boleh dipakai (mis. QC, POD, PDF, Excel) dan tidak dipaksakan diterjemahkan.
- Jika perubahan teks UI memengaruhi test yang meng-assert teks UI, maka test UI diperbarui tanpa melemahkan assertion authorization/workflow.

## Indonesian Display Standards
Standar tampilan lokal Indonesia menggunakan helper yang tersedia di proyek:
- Tanggal: format_date_id()
- Waktu/Tanggal+Waktu: format_time_id(), format_datetime_id()
- Mata uang: format_currency_id()
- Angka: format_number_id()
- Quantity: format_quantity_id()
- Persentase: format_percent_id()

Catatan:
- Input HTML tanggal/datetime (type="date"/"datetime-local") memakai format ISO untuk kompatibilitas browser dan tidak dianggap sebagai format display UI.
- Input numeric (type="number") memakai nilai numeric mentah (tanpa pemisah ribuan) untuk menjaga stabilitas input.

## Regression Protection
Proteksi regresi Bahasa Inggris ditambahkan lewat regression tests yang memverifikasi teks utama (tanpa bergantung pada seluruh HTML):
- BranchAdminDashboardUiTest: memastikan label “Dasbor” dan label sidebar utama tetap Bahasa Indonesia.
- InventoryUiTest: memastikan modul Persediaan menampilkan label Indonesia (mis. Dasbor Persediaan, Produk, Pemasok, Lokasi Persediaan, Stok Opname) dan tidak menampilkan label Inggris pada area utama.
- ProductionAuthorizationTest: memastikan “Papan Produksi” tetap Bahasa Indonesia.
- DeliveryQueueTest: memastikan “Pengiriman” tetap Bahasa Indonesia.
- DashboardReportTest: memastikan “Dasbor Laporan” tetap Bahasa Indonesia.
- UserManagementTest: memastikan “Manajemen Pengguna” dan konteks “Pengaturan” tetap Bahasa Indonesia.

## Remaining English Terms
Istilah yang sengaja tetap digunakan (sesuai guideline):
- Login
- Logout
- QC
- POD
- PDF
- Excel
- API
- Barcode
- QR Code
- CAD/CAM
- SKU
- QRIS
- MRN
- Role
- Permission
- Invoice

## Known Risks
Risiko yang dicatat dari audit regresi final:
- welcome.blade.php: masih berisi teks template Laravel berbahasa Inggris. Saat ini route “/” mengarah ke login sehingga bukan UI utama ADLMS, namun berisiko bila route root diubah pada sprint berikutnya.
- Historical notes: catatan historis yang tersimpan sebagai teks bebas dapat tampil apa adanya jika tidak dimapping di UI.
- Fallback descriptions: beberapa fallback string berada di layer service dan dapat tampil pada kondisi data tertentu; tidak diubah pada migrasi UI karena termasuk area terlarang.

## Validation Results
Hasil validasi terakhir (sesuai audit regresi final):
- Pint: PASS (.\vendor\bin\pint --test)
- Tests: 436 tests, 1141 assertions
- Route list: 180 routes
- Build: npm.cmd run build PASS

## Conclusion
ADLMS UI telah berhasil dimigrasikan ke Bahasa Indonesia secara konsisten tanpa perubahan pada database, workflow, business logic, permission, policy, repository, model, migration, maupun route.
