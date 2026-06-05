# ADLMS UI Language Guideline

Version: 1.0
Last updated: 2026-06-05
Status: Official UI language standard for future ADLMS sprints.

Dokumen ini menjadi standar resmi penggunaan bahasa pada antarmuka pengguna Asia Dental Lab
Management System (ADLMS). Dokumen ini bersifat dokumentasi dan tidak mengubah source code,
database, route, permission, policy, service, repository, migration, atau test.

---

# 1. Tujuan

Menetapkan standar penggunaan Bahasa Indonesia pada seluruh antarmuka pengguna (UI) ADLMS.

Standar ini dipakai agar operator cabang, admin lab, teknisi, kurir, finance, dan owner membaca
istilah yang konsisten di menu, halaman kerja, formulir, tabel, pesan sistem, dan alur konfirmasi.

---

# 2. Prinsip Dasar

- UI menggunakan Bahasa Indonesia.
- Source code tetap Bahasa Inggris.
- Database tetap Bahasa Inggris.
- Route tetap Bahasa Inggris.
- Permission tetap Bahasa Inggris.
- Policy tetap Bahasa Inggris.
- Service tetap Bahasa Inggris.
- Repository tetap Bahasa Inggris.
- Migration tetap Bahasa Inggris.
- Test class tetap Bahasa Inggris.

Prinsip utama:

- Terjemahkan label yang dibaca user.
- Jangan terjemahkan identifier internal aplikasi.
- Jangan mengubah enum, route name, permission name, table name, class name, method name, atau
  nama migration hanya untuk kebutuhan tampilan.
- Jika sebuah status internal perlu ditampilkan ke user, buat mapping label tampilan.

---

# 3. Yang Boleh Diterjemahkan

Elemen UI berikut boleh dan sebaiknya menggunakan Bahasa Indonesia:

- Menu
- Sidebar
- Navbar
- Dashboard
- Heading
- Form label
- Placeholder
- Tombol
- Flash message
- Validation message
- Alert
- Empty state
- Tabel
- Tooltip
- Breadcrumb
- Modal konfirmasi

Contoh:

| Elemen | Internal / sumber | Label UI Bahasa Indonesia |
|---|---|---|
| Menu | `inventory.products.index` | Produk |
| Heading | Inventory Dashboard | Dasbor Persediaan |
| Tombol | Create Product | Tambah Produk |
| Flash message | Product created. | Produk berhasil dibuat. |
| Empty state | No products found. | Belum ada produk. |

---

# 4. Yang Tidak Boleh Diterjemahkan

Identifier internal tidak boleh diterjemahkan karena menjadi kontrak source code, database,
authorization, route, dan test.

## Model

- `StockOpname`
- `InventoryLocation`
- `LabOrder`
- `ProductionTask`

## Permission

- `manage_inventory`
- `view_inventory`

## Route

- `inventory.products.index`
- `inventory.stock-opnames.index`

## Database

- `trx_stock_opnames`
- `inv_inventory_locations`

Aturan tambahan:

- Jangan mengganti nama class, namespace, method, enum value, table, column, permission, policy,
  atau route demi menerjemahkan UI.
- Jangan mengubah assertion authorization hanya karena teks UI berubah.
- Jangan mengganti nama migration atau test class ke Bahasa Indonesia.

---

# 5. Kamus Istilah Resmi ADLMS

Gunakan kamus berikut untuk seluruh UI ADLMS.

| English | Bahasa Indonesia |
|---|---|
| Dashboard | Dasbor |
| Inventory | Persediaan |
| Product | Produk |
| Product Category | Kategori Produk |
| Product Unit | Satuan Produk |
| Supplier | Pemasok |
| Inventory Location | Lokasi Persediaan |
| Stock | Stok |
| Current Stock | Stok Saat Ini |
| Low Stock | Stok Menipis |
| Stock Card | Kartu Stok |
| Stock Movement | Pergerakan Stok |
| Stock Adjustment | Penyesuaian Stok |
| Stock Opname | Stok Opname |
| Variance | Selisih |
| Branch | Cabang |
| User Management | Manajemen Pengguna |
| Production | Produksi |
| Production Board | Papan Produksi |
| Lab Order | Order Lab |
| Delivery | Pengiriman |
| Courier | Kurir |
| Report | Laporan |
| Settings | Pengaturan |

Catatan penggunaan:

- Gunakan "Persediaan" untuk nama modul/menu Inventory.
- Gunakan "Stok" untuk kuantitas barang.
- Gunakan "Stok Opname" sebagai istilah operasional, bukan "Stock Taking".
- Gunakan "Selisih" untuk variance pada layar review.

---

# 6. Istilah Yang Tetap Digunakan

Istilah berikut tetap digunakan seperti aslinya karena merupakan istilah umum, singkatan domain,
nama teknologi, atau format file:

- Login
- Logout
- QC
- POD
- CAD/CAM
- Barcode
- QR Code
- PDF
- Excel
- API

Aturan:

- Jangan memaksakan terjemahan untuk istilah di atas.
- Jika perlu penjelasan, tambahkan deskripsi Bahasa Indonesia di sekitar istilah tersebut.

Contoh:

```text
Upload bukti POD
Export Excel
Generate PDF
Scan QR Code
```

---

# 7. Status Workflow

Jika status internal masih menggunakan enum/string Bahasa Inggris, jangan mengubah nilai internal.

Contoh status internal Stock Opname:

```text
DRAFT
COUNTING
COMPLETED
CANCELLED
```

Buat label tampilan:

| Status Internal | Label Tampilan |
|---|---|
| `DRAFT` | Draft |
| `COUNTING` | Sedang Dihitung |
| `COMPLETED` | Selesai |
| `CANCELLED` | Dibatalkan |

Aturan:

- Jangan mengubah nilai internal.
- Jangan menambah status baru hanya untuk kebutuhan label.
- Mapping label boleh berada di view model, helper tampilan, component badge, atau struktur
  presentasi yang sudah sesuai pola project.
- Service tetap memvalidasi status internal Bahasa Inggris.
- Test workflow tetap menguji status internal yang benar.

---

# 8. Format Lokal Indonesia

Gunakan format lokal Indonesia untuk tampilan user.

## Tanggal

Format:

```text
05 Juni 2026
```

Aturan:

- Gunakan nama bulan Bahasa Indonesia pada tampilan.
- Jangan mengubah format penyimpanan database.
- Input tanggal HTML boleh tetap memakai format browser/native jika itu pola form yang sedang
  dipakai.

## Mata Uang

Format:

```text
Rp 1.500.000
```

Aturan:

- Gunakan prefix `Rp`.
- Gunakan titik sebagai pemisah ribuan.
- Jangan menampilkan desimal jika nominal rupiah tidak membutuhkan pecahan.

## Angka

Format:

```text
1.234,56
```

Aturan:

- Gunakan titik sebagai pemisah ribuan.
- Gunakan koma sebagai pemisah desimal.
- Untuk kuantitas stok, ikuti presisi yang sudah digunakan di modul terkait.

---

# 9. Aturan Pengujian

Jika teks UI berubah:

- Update UI test yang relevan.
- Jangan menghapus test.
- Jangan melemahkan assertion authorization.
- Jangan mengubah business logic.

Aturan tambahan:

- Perubahan label harus tetap menjaga route, policy, permission, dan service logic.
- Assertion yang menguji teks tampilan boleh diperbarui ke Bahasa Indonesia.
- Assertion yang menguji authorization, branch isolation, validation, ledger-derived stock, atau
  workflow status internal tidak boleh dilemahkan.
- Jika perubahan teks memengaruhi empty state, flash message, atau validation message, update test
  yang memang membaca teks tersebut.

---

# 10. Checklist Implementasi

Checklist ini dipakai pada sprint berikutnya untuk migrasi UI Bahasa Indonesia.

## Persiapan

- [ ] Baca `docs/ui_language_guideline.md`.
- [ ] Baca `docs/ui_design_system.md`.
- [ ] Identifikasi view/component yang akan disentuh.
- [ ] Identifikasi test UI/feature yang membaca teks tampilan.
- [ ] Pastikan scope hanya perubahan bahasa UI.

## Saat Mengubah UI

- [ ] Terjemahkan menu, heading, label, placeholder, tombol, pesan, alert, empty state, tabel,
      tooltip, breadcrumb, dan modal konfirmasi.
- [ ] Gunakan kamus istilah resmi ADLMS.
- [ ] Pertahankan class name, method name, route name, permission name, policy name, table name,
      column name, enum value, migration name, dan test class dalam Bahasa Inggris.
- [ ] Untuk status internal Bahasa Inggris, buat label tampilan tanpa mengubah nilai internal.
- [ ] Gunakan format tanggal, mata uang, dan angka lokal Indonesia untuk display.
- [ ] Pastikan action tetap permission-aware.
- [ ] Pastikan branch isolation dan ledger-derived stock tidak berubah.

## Setelah Mengubah UI

- [ ] Update UI test yang relevan.
- [ ] Jangan menghapus test.
- [ ] Jangan melemahkan assertion authorization.
- [ ] Jangan mengubah business logic.
- [ ] Jalankan quality gate yang diminta pada sprint tersebut.
- [ ] Review `git diff` untuk memastikan tidak ada perubahan source code/database/route/permission
      jika scope hanya bahasa UI.

## Larangan

- [ ] Jangan mengubah source code domain hanya untuk menerjemahkan identifier.
- [ ] Jangan mengubah database.
- [ ] Jangan mengubah migration.
- [ ] Jangan mengubah route.
- [ ] Jangan mengubah permission.
- [ ] Jangan mengubah policy.
- [ ] Jangan mengubah service.
- [ ] Jangan mengubah repository.
- [ ] Jangan menambah business logic di Blade.

---

*Dokumen ini adalah standar bahasa UI. Implementasi migrasi bahasa pada sprint berikutnya harus
tetap mengikuti arsitektur ADLMS: Controller -> Request -> Service -> Repository -> Model,
authorization berbasis policy/permission, branch isolation, dan inventory ledger-derived stock.*
