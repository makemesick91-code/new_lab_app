# UI Localization Regression Report
## Ringkasan
- Jumlah file diperiksa: 401
- Jumlah string Inggris tersisa (tampil ke user pada UI utama): 0
- Jumlah string diterjemahkan (Step 8): 26
- Modul yang diaudit: Dashboard, Inventory, Stock Opname, Order Lab, Produksi, QC, Delivery, Invoice, Payment, Reporting, Settings, User Management, Auth, Profile

---
## String Inggris Yang Masih Ada
### Boleh Tetap Inggris
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

### Harus Diterjemahkan
- resources/views/welcome.blade.php:
  - Dashboard
  - Log in
  - Register
  - Let's get started
  - Documentation
  - Deploy now
  - (dan banyak string template Laravel lainnya)

---
## Audit Status
Status yang sudah memakai label Indonesia (tanpa mengubah nilai internal):
- Lab Order: status internal (DRAFT/RECEIVED/ASSIGNED/IN_PRODUCTION/ON_HOLD/QC_PENDING/QC_PASSED/READY_FOR_DELIVERY/IN_DELIVERY/DELIVERED/COMPLETED/CANCELLED/REMAKE) ditampilkan sebagai label Indonesia di halaman operasional + laporan.
- Stock Opname: DRAFT/COUNTING/COMPLETED/CANCELLED ditampilkan sebagai Draft/Sedang Dihitung/Selesai/Dibatalkan.
- Delivery: READY_FOR_DELIVERY/IN_DELIVERY/DELIVERED/COMPLETED/CANCELLED ditampilkan sebagai Siap Dikirim/Dalam Pengiriman/Terkirim/Selesai/Dibatalkan.
- QC Result (reporting): PASSED/REJECTED/REVISION/IN_REVIEW ditampilkan sebagai Lulus/Ditolak/Perlu Revisi/Dalam Peninjauan.
- Payment Method (reporting): CASH/BANK_TRANSFER/QRIS/CARD/OTHER ditampilkan sebagai Tunai/Transfer Bank/QRIS/Kartu/Lainnya.

---
## Audit Format Lokal
Tanggal
- Menggunakan format_date_id() / format_datetime_id() pada area display laporan dan layar utama yang relevan.

Mata uang
- Menggunakan format_currency_id() untuk nominal Rupiah pada area display.

Angka
- Menggunakan format_number_id() untuk angka agregat (kartu/summary laporan).

Quantity
- Menggunakan format_quantity_id() untuk qty display (stok, item order, selisih, dsb).

Persentase
- Menggunakan format_percent_id() pada area display (jika ada).

Catatan
- Input HTML tanggal/datetime (type="date"/"datetime-local") tetap memakai format ISO (Y-m-d / Y-m-d\TH:i) karena itu format input browser, bukan format display.
- Input numeric (type="number") tetap memakai nilai numeric mentah (tanpa format ribuan) untuk kompatibilitas browser.

---
## Regression Test
Test yang ditambahkan/ditingkatkan untuk mencegah regresi Bahasa Inggris pada UI utama:
- BranchAdminDashboardUiTest: memastikan "Dasbor" muncul dan "Dashboard" tidak muncul, serta sidebar menampilkan label Indonesia (Persediaan/Produksi/Pengiriman/Pengaturan/Laporan) dan tidak menampilkan label Inggris.
- InventoryUiTest: memastikan dashboard persediaan tidak menampilkan "Inventory/Dashboard", serta memastikan halaman Stok Opname menampilkan "Stok Opname".
- ProductionAuthorizationTest: memastikan halaman "Papan Produksi" tidak menampilkan "Production/Production Board".
- DeliveryQueueTest: memastikan antrean pengiriman tidak menampilkan "Delivery".
- DashboardReportTest: memastikan "Dasbor Laporan" tidak menampilkan "Reports/Dashboard".
- UserManagementTest: memastikan "Manajemen Pengguna" tidak menampilkan "Settings".

---
## Risiko
- resources/views/welcome.blade.php masih berisi banyak teks template Laravel berbahasa Inggris. Saat ini route "/" mengarah ke login sehingga halaman welcome tidak menjadi UI utama ADLMS, namun bisa menjadi sumber regresi jika route "/" diubah pada sprint berikutnya.
- Catatan status log lama yang tersimpan sebagai teks bebas (mis. notes historis berbahasa Inggris) berpotensi tampil apa adanya jika tidak dimapping di UI timeline.
- Fallback description internal pada pembuatan item invoice ('Lab Order Item') berada di service layer dan dapat tampil jika data master kosong; tidak diubah pada Step 8 karena termasuk area terlarang.

---
## Kesimpulan
- UI utama ADLMS untuk modul yang tercakup audit sudah konsisten menggunakan Bahasa Indonesia, dengan pengecualian istilah yang memang boleh tetap Inggris (singkatan/format/istilah domain).
