# Lab Workflow V2 — Pilot Operator Runbook

Sprint: **LAB-WORKFLOW-V2-PILOT-UAT-1**. Audience: pilot operators (per role). Keep this short and operational.

Canonical flow:

```
Pendaftaran (RME) → Kandidat/Order Lab → DRAFT
  → (SPK + foto model WAJIB) → WAITING_PICKUP
  → Kurir pickup (foto pickup WAJIB) → IN_TRANSIT_TO_LAB
  → Admin Lab RECEIVED_AT_LAB → MODEL_REGISTERED → MODEL_ANALYSIS_PENDING
  → Keputusan analisa (alasan WAJIB):
      • INTERNAL → assign Technician → Step 1→2→3→4 → QC → MODEL_DONE
      • EXTERNAL → dispatch lab luar → returned → result review → MODEL_DONE
  → Delivery (foto handover + TTD kurir WAJIB) → IN_TRANSIT_TO_BRANCH
  → ARRIVED_AT_BRANCH → (nama + TTD penerima + foto lokasi WAJIB) → DELIVERED
```

Aturan tetap: Workflow V2 canonical untuk order baru; alur lama nonaktif untuk order baru (histori tetap terbaca); semua transisi lewat state machine; **notifikasi hanya in-app** (tidak ada auto-WhatsApp); bukti foto/tanda tangan **privat + terkompres**; branch isolation dijaga; Admin Lab hanya modul Lab.

---

## Per role

### Perawat / Admin Klinik (buat permintaan Lab)
- **Login → pilih Cabang RME** (Perawat & Admin Klinik memilih cabang setelah login). Jika belum memilih → tidak bisa membuat permintaan.
- Menu: **Buat Permintaan Lab** (`lab-workflow-requests.create`).
- Pilih pasien / dokter / layanan lab (dropdown searchable, sudah di-scope ke cabang), unggah **SPK** + **foto model** (WAJIB), submit → status **WAITING_PICKUP**.
- Bukti wajib: SPK, foto model. Error umum: "Cabang RME belum dipilih", "pasien/dokter tidak muncul" → lihat troubleshooting.

### Kurir pickup
- Menu: **Tugas Pickup** (`lab-pickup-tasks.index`). Klaim tugas (first-committed-wins).
- Unggah **foto pickup** (WAJIB) → mulai perjalanan → **IN_TRANSIT_TO_LAB**.
- Kurir **tidak** bisa mengonfirmasi diterima Lab — hanya Admin Lab yang `RECEIVED_AT_LAB`.

### Admin Lab
- Menu: **Order Lab V2** (`lab-v2-orders.index`), **Dasbor Operasional Lab** (`lab-workflow-dashboard.index`).
- Terima model → **RECEIVED_AT_LAB** → **register** model → **analisa** (INTERNAL/EXTERNAL, alasan WAJIB).
- INTERNAL: **assign Technician** (hanya user aktif ber-role Technician muncul di dropdown).
- Bukti wajib: sesuai transisi. Admin Lab hanya modul Lab (RME/Inventory 403 by design).

### Technician
- Menu: **Order Lab V2** (hanya order yang di-assign). Kerjakan **Step 1 → 2 → 3 → 4** berurutan (tidak boleh lompat) → **kirim ke QC**.
- Tidak boleh meng-QC hasil sendiri (segregation of duty).

### QC (Quality Control)
- Menu: **Order Lab V2** status QC_PENDING.
- **QC pass** → MODEL_DONE (jalur internal). **QC fail** → alasan + target rework WAJIB (default STEP_2); order kembali ke step target.
- QC tidak boleh dilakukan oleh teknisi yang mengerjakan.

### External Lab coordinator (Admin Lab)
- Setelah analisa EXTERNAL: pilih lab luar aktif → **preparation** → **sent** (metode kirim/resi/estimasi) → **in progress** → **returned** → **result review**.
- **Reject result** = alasan WAJIB + putaran dispatch BARU (histori lama tidak ditimpa). Tidak bisa langsung MODEL_DONE.
- **Accept result** → MODEL_DONE → delivery lanjut.

### Kurir delivery
- Menu: **Tugas Delivery** (`lab-delivery-tasks.index`). Klaim tugas.
- Sebelum transit: **foto serah terima Lab→kurir + TTD kurir** WAJIB (transit ditolak jika belum lengkap).
- Tiba di cabang → **ARRIVED_AT_BRANCH**.

### Penerima cabang
- Konfirmasi penerimaan: **nama penerima + TTD penerima + foto lokasi/bukti** WAJIB → **DELIVERED**.

---

## Troubleshooting

| Gejala | Penyebab & tindakan |
|---|---|
| Tidak bisa membuat permintaan Lab | Cabang RME belum dipilih / user tidak ter-bind cabang RME → pilih cabang online; admin bind `users.branch_id` ke cabang RME aktif. |
| Cabang RME belum dipilih | Pilih cabang di selector setelah login (Perawat/Admin Klinik/Doctor). |
| Pasien/dokter tidak muncul | Katalog di-scope ke cabang aktif; pastikan pasien/dokter aktif & branch-compatible. |
| **Dropdown technician kosong** | Tidak ada technician eligible → jalankan `php artisan lab:technician-account-audit`; jika master belum ter-link user → `php artisan lab:technician-link-user --technician=<id> --user=<id> --dry-run` lalu `--apply` (user harus sudah ber-role Technician). |
| File ditolak saat upload | Bukan gambar valid / melebihi batas ukuran setelah kompresi maksimum → gunakan foto lebih kecil/terang; SPK harus terbaca. |
| QC fail / rework | Isi alasan + target rework; order kembali ke step target, teknisi menyelesaikan, QC pass. |
| Notification 404 | Jalankan `php artisan lab-workflow:repair-notification-destinations --dry-run` lalu `--apply`; destinasi recipient-aware. |
| Transit ditolak | Bukti handover / TTD kurir belum lengkap → lengkapi dulu. |
| Delivered ditolak | Nama + TTD penerima + foto lokasi belum lengkap → lengkapi dulu. |
| Order stuck | `php artisan lab-workflow:pilot-readiness-audit --json` → lihat `stuck_orders`; tindak lanjuti actor yang relevan. |
| Context expired | Login ulang & pilih cabang RME kembali. |
| Permission 403 | Role tidak memiliki izin transisi tsb (by design); eskalasi ke Admin Lab / Super Admin. |
| Queue/notification delay | In-app; kegagalan notifikasi tidak membatalkan transisi. Cek `queue:failed`. |

## Eskalasi
1. Operator → Admin Lab (masalah workflow/assignment). 2. Admin Lab → Super Admin (RBAC/akses). 3. Super Admin → engineering (readiness NO-GO / error runtime).

## Perintah readiness (read-only, aman dijalankan kapan saja)
```
php artisan lab-workflow:pilot-readiness-audit --json      # GO/WATCH/NO-GO operasional
php artisan lab:technician-account-audit --json            # eligibility teknisi
php artisan rbac:admin-lab-lab-only-audit                  # Admin Lab tetap Lab-only
php artisan lab-workflow:repair-notification-destinations --dry-run
```
