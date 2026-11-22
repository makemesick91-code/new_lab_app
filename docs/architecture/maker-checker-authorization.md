---
kb_status: current
kb_verified_against: 3a231c28
kb_updated: 2026-11-20
---

# Spesifikasi otorisasi maker-checker — RME-LAB-FEE-APPROVAL-1

Kembali ke [00-INDEX](../00-INDEX.md). Keputusan:
[Owner Design Lock](../decisions/rme-lab-fee-approval-1-owner-design-lock.md). Rule kanonik:
`.cursor/rules/247-maker-checker-independence.mdc`.

**Status:** keputusan bisnis APPROVED (DL-LAB, T9, A-02, A-07, A-08, A-25). Guard, skema, dan test di bawah ini
**PROPOSED / NOT IMPLEMENTED**. Gate 1: NO-GO.

## 1. Kondisi sekarang (diverifikasi di `3a231c28`)

- Koreksi, refund, dan dispute sudah menolak keputusan oleh maker sendiri, di bawah row lock.
- Refund dan settlement juga dijaga CHECK di database; koreksi tidak.
- Role Front Office memegang izin request **dan** approve koreksi.
- Supervisor RME memegang `manage_rme_billing` dan dikecualikan dari konteks cabang, sehingga cakupannya seluruh cabang
  RME.
- `Gate::before` memberi Super Admin semua ability. Karena itu pemisahan tugas wajib ditegakkan di service.

## 2. Prinsip

1. Independensi diukur per **identitas akun**, ditegakkan di service, di dalam transaksi, setelah row lock, untuk semua
   role termasuk Super Admin (CG-06, CG-07).
2. Pemisahan manusia tidak pernah diklaim dari pemisahan akun.

## 3. Aturan per kasus (approval biaya LAB sebelum terbit, reversal tagihan)

Checker harus:

- berbeda dari maker permintaan;
- berbeda dari kasir penerbit invoice (A-08);
- lolos `RmeWorkingBranchScope::allows()` untuk cabang invoice;
- memegang penunjukan aktif per (akun, cabang) yang dibuat oleh akun lain, tidak pernah oleh dirinya sendiri (pola
  reviewer billing dispute).

Role atau permission saja tidak pernah cukup.

## 4. Approval Owner

Berlaku untuk pengecualian penerbitan LAB (A-02) dan untuk setiap reversal tagihan sampai Owner menetapkan ambang
risiko (A-07).

- Diputuskan in-system oleh identitas pada allowlist Owner yang source-controlled (tanpa `env()`; daftar kosong berarti
  tidak ada siapa pun), **bukan** lewat `can()`. Super Admin yang tidak ada di allowlist tidak bisa menyetujui.
- Owner ≠ pemohon.
- Setiap approval mengutip referensi Owner sekali pakai (registry rule 214) dan mencatat `owner_approved_at`.
- Pengecualian kedaluwarsa paling lama 7 hari setelah disetujui, dengan kode alasan dari daftar tertutup Owner (A-25;
  isi daftar belum ditetapkan).

## 5. Lapisan database

- CHECK `decided_by IS NULL OR decided_by <> requested_by` (untuk pengecualian: `owner_approver_id <> requested_by`).
- Partial unique index: satu permintaan aktif per objek.
- Idempotency key unik.
- Tabel event append-only (rule 248).
- **Retrofit koreksi (MC-03):** CHECK yang sama untuk `decided_by` dan `applied_by` terhadap `requested_by`, divalidasi
  setelah audit data.

## 6. Penolakan

Kode: `SELF_APPROVAL_FORBIDDEN`, `CHECKER_NOT_DESIGNATED`, `CHECKER_BRANCH_OUT_OF_SCOPE`, `CHECKER_IS_ISSUER`,
`APPROVER_NOT_OWNER`. Semua diaudit setelah rollback, dengan payload berisi id dan kode saja.

## 7. Kriteria penerimaan

- Self-approval ditolak, termasuk untuk Super Admin.
- Checker tanpa penunjukan ditolak; checker di luar cabang → 404; checker yang menerbitkan invoice ditolak.
- Super Admin di luar allowlist Owner tidak bisa menyetujui keputusan tingkat Owner.
- SQL langsung dengan maker = checker → SQLSTATE 23514.
- Dua checker bersamaan: satu menang, satu `ALREADY_DECIDED`. Replay tidak berefek.
