# Lab Workflow V2 — Pilot Real-Role UAT Checklist

Sprint: **LAB-WORKFLOW-V2-PILOT-UAT-1**. This is the **real-role** operator UAT record. It is executed by real pilot operators on the pilot environment.

> **Integrity rules (do NOT violate):**
> - Do **not** pre-mark PASS. A step is PASS only after a real operator performed it and observed the expected result.
> - Do **not** store credentials here.
> - Record the **actual** result, actor (name/role), timestamp, and an evidence reference (screenshot/file id) for every executed step.
> - Automated feature/security tests (in `tests/Feature/LabWorkflow/`) are **evidence for logic**, not a substitute for real-role UAT. Overall status stays **NOT GO** until the mandatory real-role rows below are executed and PASS.
> - Browser/device UAT (desktop + mobile viewport, file upload from phone, signature canvas) is mandatory; if browser automation is unavailable, use supervised manual UAT with screenshots.

Legend: Result = PASS / FAIL / BLOCKED / N/A.

## A. Pre-flight (read-only, run before UAT)
| # | Command | Expected | Actual | Result | By | Time |
|---|---|---|---|---|---|---|
| A1 | `php artisan lab-workflow:pilot-readiness-audit --strict` | GO (or WATCH with documented reason) | | | | |
| A2 | `php artisan lab:technician-account-audit --strict` | ≥1 eligible technician, no duplicate link | | | | |
| A3 | `php artisan rbac:admin-lab-lab-only-audit --strict` | Admin Lab Lab-only, no Super Admin leak | | | | |
| A4 | `php artisan lab-workflow:repair-notification-destinations --dry-run` | 0 pending (or repaired then 0) | | | | |

## B. Internal Lab — real-role end-to-end
| # | Role | Action | Expected | Actual | Result | Actor | Time | Evidence |
|---|---|---|---|---|---|---|---|---|
| B1 | Perawat/Admin Klinik | Login + pilih Cabang RME | Cabang aktif terpilih | | | | | |
| B2 | Perawat/Admin Klinik | Buat permintaan Lab (pasien/dokter/layanan) | Draft dibuat | | | | | |
| B3 | Perawat/Admin Klinik | Upload SPK + foto model, submit | Status WAITING_PICKUP | | | | | evidence id |
| B4 | Kurir | Klaim pickup + foto pickup + mulai transit | IN_TRANSIT_TO_LAB; kurir tak bisa "receive" | | | | | |
| B5 | Admin Lab | Terima model | RECEIVED_AT_LAB | | | | | |
| B6 | Admin Lab | Register + analisa INTERNAL (alasan) | INTERNAL_APPROVED | | | | | |
| B7 | Admin Lab | Assign Technician | Hanya technician eligible muncul; TECHNICIAN_ASSIGNED | | | | | |
| B8 | Technician | Step 1 → 2 → 3 → 4 (berurutan) | Tidak bisa lompat; STEP_4_COMPLETED | | | | | |
| B9 | Technician | Kirim ke QC | QC_PENDING | | | | | |
| B10 | QC | **QC FAIL** (alasan + target rework) | Kembali ke step target; technician≠QC | | | | | |
| B11 | Technician | Selesaikan rework | Kembali ke QC_PENDING | | | | | |
| B12 | QC | QC PASS | MODEL_DONE | | | | | |
| B13 | (verify) | Timeline lengkap, actor & timestamp benar, tanpa skip/duplikat | Timeline valid | | | | | |

## C. Delivery proof gates
| # | Role | Action | Expected | Actual | Result | Actor | Time | Evidence |
|---|---|---|---|---|---|---|---|---|
| C1 | Admin Lab | Buat tugas delivery | Idempotent, 1 task | | | | | |
| C2 | Kurir | Coba transit **sebelum** foto handover + TTD kurir | DITOLAK | | | | | |
| C3 | Kurir | Foto handover + TTD kurir → mulai transit | IN_TRANSIT_TO_BRANCH | | | | | evidence id |
| C4 | Kurir | Tiba di cabang | ARRIVED_AT_BRANCH | | | | | |
| C5 | Penerima | Coba delivered **tanpa** nama+TTD+foto lokasi | DITOLAK | | | | | |
| C6 | Penerima | Nama + TTD penerima + foto lokasi → delivered | DELIVERED | | | | | evidence id |

## D. External Lab — real-role
| # | Role | Action | Expected | Actual | Result | Actor | Time |
|---|---|---|---|---|---|---|---|
| D1 | Admin Lab | Analisa EXTERNAL (lab luar aktif) | EXTERNAL_LAB_REQUIRED | | | | |
| D2 | Admin Lab | Preparation → Sent (metode/resi/estimasi) | EXTERNAL_LAB_SENT | | | | |
| D3 | Admin Lab | In progress → Returned | EXTERNAL_LAB_RETURNED | | | | |
| D4 | Admin Lab | **Reject** result (alasan) | Dispatch cycle BARU; histori lama utuh; MODEL_DONE langsung DITOLAK | | | | |
| D5 | Admin Lab | Accept result | Result review pass → MODEL_DONE | | | | |
> If a physical external lab is not available for the pilot, use a clearly-labelled UAT external lab master and record: **functional UAT, not third-party integration UAT.**

## E. Notification matrix (spot-check per recipient)
| # | Event | Recipient | Link opens (no 404/403) | Result | Time |
|---|---|---|---|---|---|
| E1 | Pickup task | Kurir | | | |
| E2 | Received at Lab | Admin Lab | | | |
| E3 | Technician assigned | Technician | | | |
| E4 | QC pending | QC | | | |
| E5 | Delivery task | Kurir | | | |
| E6 | **Delivered to branch** | Admin Lab (opens V2 order, NOT legacy branch route) | | | |
| E7 | Delivered to branch | Perawat/Admin Klinik (own branch page) | | | |

## F. File / compression UAT (representative field files)
| # | Evidence type | Original bytes | Compressed bytes | Ratio | Format | Decode OK | Readable | Result |
|---|---|---|---|---|---|---|---|---|
| F1 | SPK (teks kecil) | | | | JPEG | | terbaca? | |
| F2 | Foto model | | | | JPEG | | | |
| F3 | Foto pickup | | | | JPEG | | | |
| F4 | Foto handover | | | | JPEG | | | |
| F5 | TTD kurir | | | | PNG (alpha) | | tajam? | |
| F6 | TTD penerima | | | | PNG (alpha) | | | |
| F7 | Foto lokasi | | | | JPEG | | | |
| F8 | Portrait EXIF rotation | | | | JPEG | | orientasi benar, EXIF/GPS hilang | |
| F9 | Already-small image | | | | (kept) | | tidak diperbesar | |
| F10 | Invalid/polyglot file | — | — | — | — | — | DITOLAK (fail closed) | |
> Also confirm: evidence privat (unauthorized access ditolak via `lab-workflow-evidence.show`).

## G. Dashboard / SLA
| # | Check | Expected | Result | Time |
|---|---|---|---|---|
| G1 | `/lab/operational-dashboard` loads | Status buckets + counts + last updated | | |
| G2 | Branch operator vs lab staff scope | Operator hanya cabangnya; lab staff semua | | |
| G3 | SLA baseline section | Durasi per stage + catatan "baseline pilot" | | |

## Sign-off
- [ ] All B/C rows PASS (internal happy-path + QC fail/rework/pass + delivery gates).
- [ ] All D rows PASS (external incl. reject cycle).
- [ ] Notification matrix (E) PASS.
- [ ] Compression + readability (F) PASS.
- [ ] Dashboard/SLA (G) PASS.
- [ ] Readiness audit (A1) GO (or WATCH documented).
- [ ] No unresolved P0/P1 findings.

UAT lead: ________________  Date: __________  Overall: ☐ GO ☐ WATCH ☐ NO-GO
