# UI Language Audit

Date: 2026-06-05
Scope: UI language audit for ADLMS pilot migration planning.
Status: Documentation only. No UI/source translation was applied.

Audit source:

- `resources/views`
- `resources/js`
- `resources/css`
- `app/Modules/*/Views`
- `app/Modules/*/resources/views`
- Blade components under `resources/views/components`
- Read-only scan of controller/service flash and validation messages that are shown to users

No Blade, route, permission, policy, migration, database, test, service, repository, or controller
file was changed for this audit.

---

## Ringkasan

- Total file yang diaudit: 174
  - 132 Blade/view files under `resources/views`
  - 3 frontend files under `resources/js` and `resources/css`
  - 0 module-local view files under `app/Modules/*/Views` or `app/Modules/*/resources/views`
  - 39 controller/service files scanned read-only for flash and validation messages
- Total teks Inggris ditemukan: 945 candidate UI strings across 101 UI files
- Modul terdampak:
  - Inventory: 272 candidate strings
  - Settings/Master Data/User Management: 212 candidate strings
  - Navigation/Dashboard: 98 candidate strings
  - Reporting: 84 candidate strings
  - Order Management: 62 candidate strings
  - Production: 62 candidate strings
  - Invoice/Payment: 60 candidate strings
  - Delivery: 49 candidate strings
  - QC: 42 candidate strings
  - Authentication/Profile: 1 candidate string
- Estimasi kompleksitas migrasi: High

Catatan hitungan:

- Angka 945 adalah hasil ekstraksi kandidat string UI Inggris dari teks Blade, atribut `title`,
  `placeholder`, `description`, `action-label`, `empty-title`, confirmation text, dan pesan
  flash/validation. Angka ini perlu review manual saat migrasi karena sebagian teks mengandung
  identifier internal yang tidak boleh diterjemahkan.
- HR, Attendance, dan Employee belum memiliki view aktif di audit target saat ini.

---

## P0 - Prioritas Tinggi

Area: Sidebar, Navbar, Dashboard, Inventory.

| File | Teks Saat Ini | Terjemahan Disarankan |
| ---- | ------------- | --------------------- |
| `resources/views/layouts/sidebar.blade.php` | Dashboard | Dasbor |
| `resources/views/layouts/sidebar.blade.php` | Inventory | Persediaan |
| `resources/views/layouts/sidebar.blade.php` | Products | Produk |
| `resources/views/layouts/sidebar.blade.php` | Locations | Lokasi Persediaan |
| `resources/views/layouts/sidebar.blade.php` | Suppliers | Pemasok |
| `resources/views/layouts/sidebar.blade.php` | Stock | Stok |
| `resources/views/layouts/sidebar.blade.php` | Stock Opname | Stok Opname |
| `resources/views/layouts/sidebar.blade.php` | Lab Orders | Order Lab |
| `resources/views/layouts/sidebar.blade.php` | Production | Produksi |
| `resources/views/layouts/sidebar.blade.php` | Delivery | Pengiriman |
| `resources/views/layouts/sidebar.blade.php` | Invoices | Invoice |
| `resources/views/layouts/sidebar.blade.php` | Reports | Laporan |
| `resources/views/layouts/navigation.blade.php` | Profile | Profil |
| `resources/views/layouts/navigation.blade.php` | Log Out | Logout |
| `resources/views/dashboard.blade.php` | Owner Overview | Ringkasan Owner |
| `resources/views/dashboard.blade.php` | Business health at a glance | Kondisi bisnis secara ringkas |
| `resources/views/dashboard.blade.php` | Executive KPI Cards | Kartu KPI Eksekutif |
| `resources/views/dashboard.blade.php` | Alert Center | Pusat Peringatan |
| `resources/views/dashboard.blade.php` | Branch Performance | Performa Cabang |
| `resources/views/dashboard.blade.php` | Recent Activity Timeline | Timeline Aktivitas Terbaru |
| `resources/views/dashboard.blade.php` | No urgent alerts | Tidak ada peringatan mendesak |
| `resources/views/dashboard.blade.php` | No branch performance data | Belum ada data performa cabang |
| `resources/views/dashboard.blade.php` | Available Drill-downs | Akses Detail Tersedia |
| `resources/views/dashboards/branch-admin.blade.php` | Daily branch command center | Pusat kendali harian cabang |
| `resources/views/dashboards/branch-admin.blade.php` | Daily Summary | Ringkasan Harian |
| `resources/views/dashboards/branch-admin.blade.php` | Work Queue Board | Papan Antrean Kerja |
| `resources/views/dashboards/branch-admin.blade.php` | Production Queue | Antrean Produksi |
| `resources/views/dashboards/branch-admin.blade.php` | Delivery Queue | Antrean Pengiriman |
| `resources/views/dashboards/branch-admin.blade.php` | Inventory Alerts | Peringatan Persediaan |
| `resources/views/dashboards/branch-admin.blade.php` | Finance Alerts | Peringatan Keuangan |
| `resources/views/inventory/dashboard.blade.php` | Inventory Dashboard | Dasbor Persediaan |
| `resources/views/inventory/dashboard.blade.php` | Inventory KPI Cards | Kartu KPI Persediaan |
| `resources/views/inventory/dashboard.blade.php` | Stock by Location | Stok per Lokasi |
| `resources/views/inventory/dashboard.blade.php` | Top Consumed Materials | Material Paling Banyak Dipakai |
| `resources/views/components/inventory/stock-value-card.blade.php` | Inventory Value Summary | Ringkasan Nilai Persediaan |
| `resources/views/components/inventory/movement-timeline.blade.php` | Recent Movements | Pergerakan Terbaru |
| `resources/views/components/inventory/low-stock-widget.blade.php` | Low Stock Products | Produk Stok Menipis |
| `resources/views/inventory/products/index.blade.php` | Inventory Products | Produk Persediaan |
| `resources/views/inventory/products/index.blade.php` | Branch Total Stock | Total Stok Cabang |
| `resources/views/inventory/products/index.blade.php` | Current Stock - Branch Total | Stok Saat Ini - Total Cabang |
| `resources/views/inventory/products/index.blade.php` | Stock Status | Status Stok |
| `resources/views/inventory/products/index.blade.php` | Create Product | Tambah Produk |
| `resources/views/inventory/products/index.blade.php` | All products | Semua produk |
| `resources/views/inventory/products/index.blade.php` | Code, name, or category | Kode, nama, atau kategori |
| `resources/views/inventory/products/index.blade.php` | No products found. | Belum ada produk. |
| `resources/views/inventory/products/show.blade.php` | Product Detail | Detail Produk |
| `resources/views/inventory/products/show.blade.php` | Product Summary Card | Kartu Ringkasan Produk |
| `resources/views/inventory/products/show.blade.php` | Branch / Location Stock Clarity | Kejelasan Stok Cabang / Lokasi |
| `resources/views/inventory/products/show.blade.php` | Inventory Value | Nilai Persediaan |
| `resources/views/inventory/products/show.blade.php` | Back to Products | Kembali ke Produk |
| `resources/views/inventory/locations/index.blade.php` | Inventory Locations | Lokasi Persediaan |
| `resources/views/inventory/locations/index.blade.php` | Create Location | Tambah Lokasi |
| `resources/views/inventory/locations/index.blade.php` | Search location | Cari lokasi |
| `resources/views/inventory/suppliers/index.blade.php` | Inventory Suppliers | Pemasok Persediaan |
| `resources/views/inventory/suppliers/index.blade.php` | Create Supplier | Tambah Pemasok |
| `resources/views/inventory/suppliers/index.blade.php` | Search supplier | Cari pemasok |
| `resources/views/inventory/stock/index.blade.php` | Inventory Stock | Stok Persediaan |
| `resources/views/inventory/stock/index.blade.php` | Current Stock | Stok Saat Ini |
| `resources/views/inventory/stock/index.blade.php` | Low Stock | Stok Menipis |
| `resources/views/inventory/stock/card.blade.php` | Stock Card | Kartu Stok |
| `resources/views/inventory/stock/card.blade.php` | Ledger-derived Stock Card | Kartu Stok Berbasis Ledger |
| `resources/views/inventory/stock/card.blade.php` | Movement Timeline | Timeline Pergerakan |
| `resources/views/inventory/stock/card.blade.php` | Running Balance | Saldo Berjalan |
| `resources/views/inventory/stock/opening.blade.php` | Opening Stock | Stok Awal |
| `resources/views/inventory/stock/receive.blade.php` | Receive Stock | Terima Stok |
| `resources/views/inventory/stock/adjust-in.blade.php` | Adjustment In | Penyesuaian Masuk |
| `resources/views/inventory/stock/adjust-out.blade.php` | Adjustment Out | Penyesuaian Keluar |
| `resources/views/inventory/stock/_operation-form.blade.php` | Inventory Location | Lokasi Persediaan |
| `resources/views/inventory/stock/_operation-form.blade.php` | Ledger-derived stock | Stok berbasis ledger |
| `resources/views/inventory/stock/_operation-form.blade.php` | Notes / Reason | Catatan / Alasan |
| `resources/views/inventory/stock-opnames/index.blade.php` | Stock Opnames | Stok Opname |
| `resources/views/inventory/stock-opnames/index.blade.php` | Inventory Stock Opnames | Stok Opname Persediaan |
| `resources/views/inventory/stock-opnames/index.blade.php` | Create Stock Opname | Buat Stok Opname |
| `resources/views/inventory/stock-opnames/index.blade.php` | All statuses | Semua status |
| `resources/views/inventory/stock-opnames/index.blade.php` | Number or location | Nomor atau lokasi |
| `resources/views/inventory/stock-opnames/index.blade.php` | No stock opnames found. | Belum ada stok opname. |
| `resources/views/inventory/stock-opnames/create.blade.php` | Create Stock Opname Session | Buat Sesi Stok Opname |
| `resources/views/inventory/stock-opnames/show.blade.php` | Add Product | Tambah Produk |
| `resources/views/inventory/stock-opnames/show.blade.php` | Counted Quantity | Jumlah Terhitung |
| `resources/views/inventory/stock-opnames/show.blade.php` | Reason for cancellation | Alasan pembatalan |
| `resources/views/inventory/stock-opnames/review.blade.php` | Review Stock Opname | Review Stok Opname |
| `resources/views/inventory/stock-opnames/review.blade.php` | Total Products | Total Produk |
| `resources/views/inventory/stock-opnames/review.blade.php` | Total Variances | Total Selisih |
| `resources/views/inventory/stock-opnames/review.blade.php` | Overages | Selisih Lebih |
| `resources/views/inventory/stock-opnames/review.blade.php` | Shortages | Selisih Kurang |
| `resources/views/inventory/stock-opnames/review.blade.php` | Finalize Opname | Finalisasi Opname |

---

## P1 - Prioritas Menengah

Area: Order, Production, QC, Delivery.

| File | Teks Saat Ini | Terjemahan Disarankan |
| ---- | ------------- | --------------------- |
| `resources/views/lab-orders/index.blade.php` | Lab Orders | Order Lab |
| `resources/views/lab-orders/index.blade.php` | Create Lab Order | Buat Order Lab |
| `resources/views/lab-orders/index.blade.php` | All clinics | Semua klinik |
| `resources/views/lab-orders/index.blade.php` | All status | Semua status |
| `resources/views/lab-orders/index.blade.php` | Order #, RM, clinic, doctor, patient | No. order, RM, klinik, dokter, pasien |
| `resources/views/lab-orders/show.blade.php` | Lab Order Detail | Detail Order Lab |
| `resources/views/lab-orders/show.blade.php` | Cancel Order | Batalkan Order |
| `resources/views/lab-orders/show.blade.php` | Created By | Dibuat Oleh |
| `resources/views/lab-orders/show.blade.php` | Delete this attachment? | Hapus lampiran ini? |
| `resources/views/production/board.blade.php` | Production Board | Papan Produksi |
| `resources/views/production/board.blade.php` | All technicians | Semua teknisi |
| `resources/views/production/board.blade.php` | No production orders found. | Belum ada order produksi. |
| `resources/views/production/show.blade.php` | Production Detail | Detail Produksi |
| `resources/views/production/show.blade.php` | Assign Technician | Tugaskan Teknisi |
| `resources/views/production/show.blade.php` | Reassign Technician | Ganti Teknisi |
| `resources/views/production/show.blade.php` | Start Work | Mulai Pekerjaan |
| `resources/views/production/show.blade.php` | Pause Work | Jeda Pekerjaan |
| `resources/views/production/show.blade.php` | Resume Work | Lanjutkan Pekerjaan |
| `resources/views/production/show.blade.php` | Complete Work | Selesaikan Pekerjaan |
| `resources/views/production/show.blade.php` | Send to QC | Kirim ke QC |
| `resources/views/production/show.blade.php` | Assignment History | Riwayat Penugasan |
| `resources/views/production/work-logs.blade.php` | Work Logs | Log Pekerjaan |
| `resources/views/quality-control/queue.blade.php` | QC Queue | Antrean QC |
| `resources/views/quality-control/queue.blade.php` | QC Status | Status QC |
| `resources/views/quality-control/queue.blade.php` | QC queue is empty. | Antrean QC kosong. |
| `resources/views/quality-control/show.blade.php` | QC Detail | Detail QC |
| `resources/views/quality-control/show.blade.php` | Active QC Inspector | Pemeriksa QC Aktif |
| `resources/views/quality-control/show.blade.php` | QC Actions | Aksi QC |
| `resources/views/quality-control/show.blade.php` | Start QC | Mulai QC |
| `resources/views/quality-control/show.blade.php` | Pass QC | Lulus QC |
| `resources/views/quality-control/show.blade.php` | Reject QC | Tolak QC |
| `resources/views/quality-control/show.blade.php` | QC Checklist | Checklist QC |
| `resources/views/quality-control/show.blade.php` | QC Evidence | Bukti QC |
| `resources/views/quality-control/show.blade.php` | QC History | Riwayat QC |
| `resources/views/quality-control/show.blade.php` | No QC history. | Belum ada riwayat QC. |
| `resources/views/deliveries/index.blade.php` | Delivery Queue | Antrean Pengiriman |
| `resources/views/deliveries/index.blade.php` | All delivery status | Semua status pengiriman |
| `resources/views/deliveries/index.blade.php` | Ready to Prepare | Siap Diproses |
| `resources/views/deliveries/index.blade.php` | Active Deliveries | Pengiriman Aktif |
| `resources/views/deliveries/index.blade.php` | Create Delivery | Buat Pengiriman |
| `resources/views/deliveries/index.blade.php` | No delivery records. | Belum ada data pengiriman. |
| `resources/views/deliveries/show.blade.php` | Delivery Detail | Detail Pengiriman |
| `resources/views/deliveries/show.blade.php` | Courier Assignment | Penugasan Kurir |
| `resources/views/deliveries/show.blade.php` | Assign Courier | Tugaskan Kurir |
| `resources/views/deliveries/show.blade.php` | Reassign Courier | Ganti Kurir |
| `resources/views/deliveries/show.blade.php` | Start Delivery | Mulai Pengiriman |
| `resources/views/deliveries/show.blade.php` | Complete Delivery | Selesaikan Pengiriman |
| `resources/views/deliveries/show.blade.php` | POD Panel | Panel POD |
| `resources/views/deliveries/show.blade.php` | Receiver Photo | Foto Penerima |
| `resources/views/deliveries/show.blade.php` | Mark Delivered | Tandai Terkirim |
| `resources/views/deliveries/show.blade.php` | Evidence Panel | Panel Bukti |
| `resources/views/deliveries/show.blade.php` | Audit History | Riwayat Audit |

---

## P2 - Prioritas Rendah

Area: Empty State, Tooltip, Placeholder, Helper Text.

| File | Teks Saat Ini | Terjemahan Disarankan |
| ---- | ------------- | --------------------- |
| `resources/views/settings/users/index.blade.php` | Search name or email | Cari nama atau email |
| `resources/views/settings/users/index.blade.php` | No users found. | Belum ada pengguna. |
| `resources/views/settings/users/_form.blade.php` | leave blank to keep current | kosongkan untuk mempertahankan password saat ini |
| `resources/views/settings/roles/_form.blade.php` | Select the permissions granted to this role. | Pilih permission yang diberikan ke role ini. |
| `resources/views/settings/roles/index.blade.php` | No roles found. | Belum ada role. |
| `resources/views/settings/permissions/index.blade.php` | Assign permissions to roles from the Role edit screen. | Atur permission role dari layar edit Role. |
| `resources/views/settings/permissions/index.blade.php` | No permissions found. | Belum ada permission. |
| `resources/views/settings/clinics/index.blade.php` | Search name, code, city | Cari nama, kode, kota |
| `resources/views/settings/clinics/index.blade.php` | No clinics found. | Belum ada klinik. |
| `resources/views/settings/doctors/index.blade.php` | Search name or code | Cari nama atau kode |
| `resources/views/settings/doctors/index.blade.php` | No doctors found. | Belum ada dokter. |
| `resources/views/settings/patients/index.blade.php` | Search name or MRN | Cari nama atau MRN |
| `resources/views/settings/patients/index.blade.php` | No patients found. | Belum ada pasien. |
| `resources/views/settings/technicians/index.blade.php` | Search name, code, specialization | Cari nama, kode, spesialisasi |
| `resources/views/settings/technicians/index.blade.php` | Linked User | User Tertaut |
| `resources/views/settings/technicians/index.blade.php` | No technicians found. | Belum ada teknisi. |
| `resources/views/settings/lab-services/index.blade.php` | Search name, code, category | Cari nama, kode, kategori |
| `resources/views/settings/lab-services/index.blade.php` | No lab services found. | Belum ada layanan lab. |
| `resources/views/invoices/index.blade.php` | Invoice # or clinic | No. invoice atau klinik |
| `resources/views/invoices/create.blade.php` | Order #, clinic, doctor, patient | No. order, klinik, dokter, pasien |
| `resources/views/invoices/create.blade.php` | No completed Lab Orders available for invoicing. | Belum ada Order Lab selesai yang dapat dibuat invoice. |
| `resources/views/invoices/show.blade.php` | Issue notes | Catatan penerbitan |
| `resources/views/invoices/show.blade.php` | Void reason | Alasan void |
| `resources/views/invoices/show.blade.php` | Reference number | Nomor referensi |
| `resources/views/invoices/show.blade.php` | Payment notes | Catatan pembayaran |
| `resources/views/reports/orders.blade.php` | No orders found. | Belum ada order. |
| `resources/views/reports/production.blade.php` | No production records found. | Belum ada data produksi. |
| `resources/views/reports/qc.blade.php` | No QC records found. | Belum ada data QC. |
| `resources/views/reports/delivery.blade.php` | No delivery records found. | Belum ada data pengiriman. |
| `resources/views/reports/invoices.blade.php` | No invoices found. | Belum ada invoice. |
| `resources/views/reports/payments.blade.php` | No payments found. | Belum ada pembayaran. |
| `resources/views/reports/outstanding.blade.php` | No outstanding invoices found. | Tidak ada invoice tertunggak. |
| `resources/views/reports/revenue.blade.php` | No revenue data found. | Belum ada data pendapatan. |
| `resources/views/lab-orders/show.blade.php` | Coming in a future sprint | Akan tersedia pada sprint berikutnya |
| `resources/views/production/show.blade.php` | Reason (min 5 chars) | Alasan (minimal 5 karakter) |
| `resources/views/quality-control/show.blade.php` | Notes (required) | Catatan (wajib) |
| `resources/views/deliveries/show.blade.php` | Reassignment notes required | Catatan pergantian wajib diisi |
| `resources/views/deliveries/show.blade.php` | Assignment notes | Catatan penugasan |
| `resources/views/inventory/stock/card.blade.php` | No stock movements match these filters. | Tidak ada pergerakan stok yang cocok dengan filter ini. |
| `resources/views/inventory/stock-opnames/show.blade.php` | Notes | Catatan |

---

## Validation & Notifications

| File | Pesan Saat Ini | Terjemahan Disarankan |
| ---- | -------------- | --------------------- |
| `app/Modules/User/Controllers/UserController.php` | User created successfully. | Pengguna berhasil dibuat. |
| `app/Modules/User/Controllers/UserController.php` | User updated successfully. | Pengguna berhasil diperbarui. |
| `app/Modules/User/Controllers/UserController.php` | User deleted successfully. | Pengguna berhasil dihapus. |
| `app/Modules/Technician/Controllers/TechnicianController.php` | Technician created successfully. | Teknisi berhasil dibuat. |
| `app/Modules/Clinic/Controllers/ClinicController.php` | Clinic created successfully. | Klinik berhasil dibuat. |
| `app/Modules/Doctor/Controllers/DoctorController.php` | Doctor created successfully. | Dokter berhasil dibuat. |
| `app/Modules/Patient/Controllers/PatientController.php` | Patient created successfully. | Pasien berhasil dibuat. |
| `app/Modules/LabService/Controllers/LabServiceController.php` | Lab service created successfully. | Layanan lab berhasil dibuat. |
| `app/Modules/LabOrder/Controllers/LabOrderController.php` | Lab order created successfully. | Order lab berhasil dibuat. |
| `app/Modules/LabOrder/Controllers/LabOrderController.php` | Lab order updated successfully. | Order lab berhasil diperbarui. |
| `app/Modules/LabOrder/Controllers/LabOrderController.php` | Lab order cancelled. | Order lab dibatalkan. |
| `app/Modules/LabOrder/Controllers/AttachmentController.php` | Attachment uploaded successfully. | Lampiran berhasil diunggah. |
| `app/Modules/Production/Controllers/AssignmentController.php` | Technician assigned. | Teknisi berhasil ditugaskan. |
| `app/Modules/Production/Controllers/ProductionStepController.php` | Production step updated. | Tahap produksi berhasil diperbarui. |
| `app/Modules/Production/Controllers/ProductionWorkflowController.php` | Order sent to QC. | Order dikirim ke QC. |
| `app/Modules/QualityControl/Controllers/QualityControlController.php` | QC review started. | Review QC dimulai. |
| `app/Modules/QualityControl/Controllers/QualityControlController.php` | QC passed. Order is QC_PASSED. | QC lulus. Order berstatus QC_PASSED. |
| `app/Modules/QualityControl/Controllers/QualityControlController.php` | QC rejected. Order moved to REMAKE. | QC ditolak. Order dipindahkan ke REMAKE. |
| `app/Modules/QualityControl/Controllers/QualityControlController.php` | QC evidence uploaded. | Bukti QC berhasil diunggah. |
| `app/Modules/QualityControl/Controllers/RemakeController.php` | Remake request created. | Permintaan remake berhasil dibuat. |
| `app/Modules/Delivery/Controllers/DeliveryController.php` | Delivery created. | Pengiriman berhasil dibuat. |
| `app/Modules/Delivery/Controllers/DeliveryController.php` | POD uploaded. | POD berhasil diunggah. |
| `app/Modules/Invoice/Controllers/InvoiceController.php` | Invoice created. | Invoice berhasil dibuat. |
| `app/Modules/Invoice/Controllers/InvoiceController.php` | Invoice issued. | Invoice berhasil diterbitkan. |
| `app/Modules/Invoice/Controllers/InvoiceController.php` | Invoice voided. | Invoice berhasil divoid. |
| `app/Modules/Invoice/Controllers/PaymentController.php` | Payment recorded. | Pembayaran berhasil dicatat. |
| `app/Modules/Inventory/Controllers/ProductController.php` | Product created. | Produk berhasil dibuat. |
| `app/Modules/Inventory/Controllers/SupplierController.php` | Supplier updated. | Pemasok berhasil diperbarui. |
| `app/Modules/Inventory/Controllers/InventoryLocationController.php` | Inventory location deactivated. | Lokasi persediaan dinonaktifkan. |
| `app/Modules/Inventory/Controllers/InventoryStockController.php` | Opening stock created. | Stok awal berhasil dibuat. |
| `app/Modules/Inventory/Controllers/InventoryStockController.php` | Stock received. | Stok berhasil diterima. |
| `app/Modules/Inventory/Controllers/InventoryStockController.php` | Stock adjustment in created. | Penyesuaian stok masuk berhasil dibuat. |
| `app/Modules/Inventory/Controllers/InventoryStockController.php` | Stock adjustment out created. | Penyesuaian stok keluar berhasil dibuat. |
| `app/Modules/Inventory/Controllers/StockOpnameController.php` | Stock opname created successfully. | Stok opname berhasil dibuat. |
| `app/Modules/Inventory/Controllers/StockOpnameController.php` | Counted quantity updated successfully. | Jumlah terhitung berhasil diperbarui. |
| `app/Modules/Inventory/Controllers/StockOpnameController.php` | Stock opname reviewed successfully. | Stok opname berhasil direview. |
| `app/Modules/Inventory/Controllers/StockOpnameController.php` | Stock opname finalized successfully. | Stok opname berhasil difinalisasi. |
| `app/Modules/Inventory/Controllers/StockOpnameController.php` | Stock opname cancelled successfully. | Stok opname berhasil dibatalkan. |
| `app/Modules/Invoice/Services/InvoiceWorkflowService.php` | Due date wajib diisi sebelum invoice diterbitkan. | Tanggal jatuh tempo wajib diisi sebelum invoice diterbitkan. |
| `app/Modules/Invoice/Services/PaymentService.php` | Amount harus lebih dari 0. | Jumlah pembayaran harus lebih dari 0. |
| `app/Modules/Invoice/Services/PaymentService.php` | Payment tidak boleh melebihi outstanding amount. | Pembayaran tidak boleh melebihi sisa tagihan. |

Catatan:

- Banyak validation message service sudah campuran Bahasa Indonesia dan istilah internal Bahasa
  Inggris. Migrasi harus memisahkan label UI dari status internal seperti `DRAFT`, `ISSUED`,
  `QC_PENDING`, `REMAKE`, dan `COMPLETED`.
- Jangan mengganti enum/status internal untuk membuat pesan terlihat Indonesia.

---

## Istilah Yang Tidak Boleh Diterjemahkan

Istilah berikut dicatat sesuai `docs/ui_language_guideline.md`.

| Istilah | Ditemukan Saat Audit | Catatan |
|---|---|---|
| Login | Ya | Auth UI/Breeze. Tetap gunakan `Login`. |
| Logout | Ya | Navigation/profile. Tetap gunakan `Logout`. |
| QC | Ya | Banyak di dashboard, production, QC, reports. Tetap gunakan `QC`. |
| POD | Ya | Delivery/POD panel dan service messages. Tetap gunakan `POD`. |
| CAD/CAM | Tidak ditemukan | Tetap tidak diterjemahkan jika muncul pada sprint berikutnya. |
| Barcode | Tidak ditemukan | Tetap tidak diterjemahkan jika muncul pada sprint berikutnya. |
| QR Code | Tidak ditemukan | Tetap tidak diterjemahkan jika muncul pada sprint berikutnya. |
| PDF | Tidak ditemukan pada UI target | Tetap tidak diterjemahkan jika muncul pada export/generate UI. |
| Excel | Tidak ditemukan pada UI target | Tetap tidak diterjemahkan jika muncul pada export UI. |
| API | Tidak ditemukan pada UI target | Tetap tidak diterjemahkan jika muncul pada teknis/admin UI. |

---

## Risiko Migrasi

### Hardcoded String

- Banyak teks UI masih hardcoded langsung di Blade, terutama pada:
  - `resources/views/inventory/**`
  - `resources/views/settings/**`
  - `resources/views/deliveries/**`
  - `resources/views/production/**`
  - `resources/views/quality-control/**`
  - `resources/views/invoices/**`
- Placeholder, empty state, helper text, dan confirmation text tersebar di banyak file.
- Beberapa string berada di atribut component seperti `title`, `description`, `empty-title`, dan
  `action-label`, sehingga migration harus mencakup props component, bukan hanya teks antar tag.

### Assertion Test Yang Akan Terdampak

Test berikut memiliki assertion terhadap teks Inggris dan perlu diperbarui jika UI dimigrasikan:

- `tests/Feature/Dashboard/OwnerDashboardUiTest.php`
- `tests/Feature/Dashboard/BranchAdminDashboardUiTest.php`
- `tests/Feature/Inventory/InventoryUiTest.php`
- `tests/Feature/Inventory/StockOpnameServiceTest.php`
- `tests/Feature/Delivery/DeliveryAdditionalCoverageTest.php`
- `tests/Feature/Delivery/DeliveryQueueTest.php`
- `tests/Feature/Invoice/InvoicePaymentTest.php`
- `tests/Feature/Reporting/DashboardReportTest.php`

Aturan:

- Update assertion teks UI yang relevan.
- Jangan menghapus test.
- Jangan melemahkan assertion authorization, branch isolation, ledger-derived stock, atau workflow.

### Component Yang Digunakan Ulang Di Banyak Modul

- `resources/views/components/settings-shell.blade.php`
- `resources/views/components/owner-dashboard/*.blade.php`
- `resources/views/components/branch-dashboard/*.blade.php`
- `resources/views/components/inventory/*.blade.php`
- `resources/views/components/input-label.blade.php`
- `resources/views/components/input-error.blade.php`
- `resources/views/inventory/_status-badge.blade.php`
- `resources/views/inventory/_low-stock-badge.blade.php`

Risiko:

- Mengubah label di component reusable dapat mengubah banyak halaman dan banyak test sekaligus.
- Perlu migrasi bertahap dan snapshot test/feature test yang jelas.

### Status Workflow Yang Masih Tampil Dalam Bahasa Inggris

Status internal berikut masih tampil langsung di beberapa UI:

- Lab Order: `DRAFT`, `RECEIVED`, `ASSIGNED`, `IN_PRODUCTION`, `QC_PENDING`, `QC_PASSED`,
  `READY_FOR_DELIVERY`, `IN_DELIVERY`, `DELIVERED`, `COMPLETED`, `CANCELLED`, `REMAKE`
- Production assignment: `ASSIGNED`, `IN_PROGRESS`, `DONE`, `CANCELLED`, `REASSIGNED`
- QC result/checklist: `PASSED`, `REJECTED`, `REVISION`, `PASS`, `FAIL`, `N/A`
- Delivery: `READY_FOR_DELIVERY`, `IN_DELIVERY`, `DELIVERED`, `COMPLETED`
- Invoice/payment: `DRAFT`, `ISSUED`, `PARTIALLY_PAID`, `PAID`, `OVERDUE`, `VOID`
- Inventory movement: `OPENING`, `PURCHASE`, `ADJUSTMENT_IN`, `ADJUSTMENT_OUT`
- Stock Opname: `DRAFT`, `COUNTING`, `COMPLETED`, `CANCELLED`

Aturan migrasi:

- Jangan mengubah nilai internal.
- Buat mapping label tampilan per domain/status.
- Service dan test workflow tetap memakai nilai internal.

---

## Estimasi Pekerjaan

### Quick Win Migration

- Jumlah file: 35-45 file
- Jumlah string: 250-350 string
- Scope:
  - Sidebar/navigation
  - Dashboard labels
  - Inventory index/create/show headings
  - Stock Opname visible labels
  - Flash messages paling sering muncul
- Tingkat risiko: Medium
- Risiko utama:
  - UI tests dashboard dan inventory akan perlu update.
  - Component dashboard/inventory reusable punya dampak lintas halaman.
  - Status internal harus tetap menggunakan mapping label, bukan rename enum.

### Full Migration

- Jumlah file: 100-115 UI files plus 35-45 controller/service message files
- Jumlah string: 900-1,050 candidate strings
- Scope:
  - Semua Blade views
  - Component props dan empty states
  - Placeholder/helper text
  - Confirmation modal/native confirm text
  - Flash message
  - Validation message yang tampil ke user
  - Report export labels where user-facing
- Tingkat risiko: High
- Risiko utama:
  - Banyak feature/UI tests assert teks Inggris.
  - Banyak status internal tampil langsung dari enum/string.
  - Beberapa pesan service mencampur Bahasa Indonesia dan identifier internal.
  - Migrasi harus bertahap agar authorization, branch isolation, dan ledger-derived stock tetap
    terbukti bersih.

---

## Rekomendasi Urutan Migrasi

1. Mulai dari shared language map/status label map untuk tampilan saja.
2. Migrasi Sidebar, Navbar, Dashboard, dan Inventory list/detail terlebih dahulu.
3. Update UI tests yang assert teks tampilan tanpa melemahkan behavior assertions.
4. Migrasi Stock Opname setelah status label map siap.
5. Migrasi Order, Production, QC, Delivery.
6. Migrasi Reporting, Settings/Master Data, Invoice/Payment.
7. Migrasi flash/validation messages terakhir, karena beberapa berasal dari service workflow dan
   perlu review domain/status internal.

---

*Dokumen ini adalah audit bahasa UI. Tidak ada perubahan source code atau penerapan terjemahan
dilakukan di audit ini.*
