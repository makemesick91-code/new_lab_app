# Sprint 68.13 — Performance Closure Owner/Stakeholder Summary

## Executive Summary

- DaengtisiaMS telah diuji dengan data stress sintetis lokal dalam skala besar — **bukan** data pasien nyata dan **bukan** lingkungan pilot/VPS production.
- Skala uji mencapai **250.000 pasien**, **556.000 kunjungan RME**, dan **500.400 pembayaran RME** (~2 GB database stress lokal).
- Halaman utama tetap responsif: **Owner Dashboard** rata-rata sekitar **60 ms**; halaman RME sekitar **53–63 ms**; p95 maksimum sekitar **72 ms**.
- Agregat SQL backend tertentu (trend harian) masuk kategori **WATCH** (~118 ms) — artinya pantau, bukan perbaiki mendesak.
- **Tidak perlu deploy** performa ke VPS/pilot saat ini.
- **Tidak perlu** penambahan index database, rewrite query, cache, atau tabel ringkasan materialized saat ini.
- Optimisasi di masa depan hanya jika ambang yang disepakati terlampaui (misalnya dashboard >300–500 ms atau pembayaran >1 juta baris dengan keluhan pengguna).
- **Monitoring pilot tetap direkomendasikan** — bukti stress lokal tidak sama dengan hardware VPS, traffic nyata, atau banyak pengguna bersamaan.

## Plain-Language Conclusion

Berdasarkan bukti stress lokal, DaengtisiaMS saat ini **tetap responsif pada skala yang diuji**. Sistem **tidak memerlukan** deployment terkait performa maupun optimisasi database pada waktu ini.

Penting dipahami: ini adalah hasil pengujian stress **lokal** dengan data **sintetis**. Performa di VPS/pilot dengan banyak pengguna bersamaan adalah evaluasi terpisah dan tetap perlu dipantau selama operasional pilot berjalan.

## What Was Tested

| Area | What Was Tested | Result |
|---|---|---|
| Patient volume | 250,000 synthetic patients | Passed |
| RME visit volume | 556,000 synthetic visits | Passed |
| RME payment volume | 500,400 synthetic payments | Passed |
| Owner Dashboard | KPI/dashboard page response | Passed |
| RME pages | Queue, visits, reports, receivables, cashier-related pages | Passed |
| SQL aggregate | Owner payment aggregate and daily trend | Passed / Watch |

Semua pengujian menggunakan data sintetis pada database stress lokal (`daengtisia_stress`). Tidak ada data pasien nyata, KTP/NIK, atau informasi pribadi yang diekspos.

## Tested Scale

| Metric | Tested Volume |
|---|---:|
| Patients | 250,000 |
| RME visits | 556,000 |
| RME payments | 500,400 |
| Stress DB size | ~2 GB |
| Payment table size | ~223 MB |

## Performance Summary for Owner

| Page/Area | Result | Status |
|---|---:|---|
| Owner Dashboard average | ~60 ms | OK |
| RME pages average | ~53–63 ms | OK |
| HTTP p95 max | ~71.80 ms | OK |
| Payment report SQL | ~0.10 ms | OK |
| Owner aggregate SQL | ~80 ms | OK / Watch |
| Daily trend SQL | ~118 ms | Watch |

**OK** = masih nyaman untuk pengguna. **Watch** = pantau perkembangan; belum perlu tindakan optimisasi.

## What This Means

- Halaman yang paling sering dipakai (antrian, kunjungan, laporan, piutang, kasir, Owner Dashboard) **masih cepat** pada skala setengah juta pembayaran.
- Owner Dashboard tetap cepat (~60 ms rata-rata) meskipun volume pembayaran sudah besar — ini indikasi baik untuk kebutuhan manajemen saat ini.
- Beberapa perhitungan agregat di belakang layar (misalnya trend harian pembayaran) sudah mendekati area **WATCH** (~118 ms) tetapi **belum** memperlambat halaman yang dilihat pengguna.
- Percobaan menambahkan index paksa pada agregat owner justru **lebih lambat** — bukti saat ini **tidak mendukung** penambahan index atau perubahan query mendadak.
- Tidak ada dasar teknis untuk sprint optimisasi database atau deploy performa **sekarang**.

## Deploy Decision

| Item | Decision |
|---|---|
| Deploy needed now | No |
| VPS action | No |
| Database migration/index | No |
| Query rewrite | No |
| Cache/materialized summary | No |
| Reason | Performance remains within acceptable threshold |

## Optimization Trigger Rules

Optimisasi hanya dilakukan jika kondisi nyata melampaui ambang berikut — bukan berdasarkan asumsi.

| Condition | Action |
|---|---|
| Owner Dashboard avg under 300 ms | No action |
| Owner Dashboard avg 300–500 ms | Monitor closely |
| Owner Dashboard avg above 500 ms | Investigate optimization |
| Owner Dashboard avg or p95 above 1 second | Optimization sprint required |
| SQL aggregate above 500 ms consistently | Review query/index/summary option |
| Payment rows exceed 1M and users feel slowdown | Consider aggregate optimization |

Dalam pengujian Sprint 68.8–68.12, **tidak ada** kondisi di atas yang terpenuhi.

## What Should Not Be Done Now

- Do not deploy performance changes now.
- Do not add new database index now.
- Do not add materialized summary now.
- Do not rewrite Owner Dashboard queries now.
- Do not run 1M benchmark without explicit approval.
- Do not benchmark on VPS/pilot without a controlled plan.

## Caveats / Limitations

- Pengujian ini dilakukan **lokal** dengan data sintetis — bukan traffic production nyata.
- Performa VPS/pilot dapat berbeda karena hardware, jaringan, dan beban server lain.
- **Concurrency multi-user** (banyak staf login bersamaan) **belum** menjadi fokus utama pengujian ini.
- Pola operasional nyata (jam sibuk, distribusi tanggal pembayaran, dll.) dapat berbeda dari data seed stress.
- **Monitoring pilot tetap diperlukan** untuk memastikan performa nyata selaras dengan bukti stress lokal.

## Recommended Next Steps

**Rekomendasi utama:**

- Lanjutkan operasional pilot dengan pemantauan performa berkala (tanpa deploy optimisasi sekarang).

**Sprint berikutnya yang disarankan:**

- **Sprint 68.14 — Pilot Performance Monitoring Plan** — rencana pemantauan performa di lingkungan pilot/VPS secara terkontrol.

**Opsi alternatif (hanya jika disetujui dan dibutuhkan):**

- Controlled 1M Payment Benchmark — hanya dengan persetujuan eksplisit pemilik proyek.
- Owner Dashboard Aggregate Optimization — hanya jika ambang trigger terlampaui.
- Multi-user concurrency test — jika keluhan lambat muncul saat banyak pengguna bersamaan.

## Stakeholder-Ready Summary

DaengtisiaMS has been stress-tested locally using synthetic data up to 250,000 patients, 556,000 RME visits, and 500,000 payments. The main RME pages and Owner Dashboard remained fast, with Owner Dashboard averaging around 60 ms. Based on this evidence, no performance-related deployment, database index, query rewrite, or caching change is needed at this time. The system should continue to be monitored during pilot usage, especially if payment records grow beyond 1 million or dashboard response time exceeds agreed thresholds.

## Safety Confirmation

- No deploy.
- No VPS SSH.
- No migration.
- No destructive DB command.
- No business logic changed.
- No `.env`/backup/SSH key/DB dump committed.
- No real PII/KTP/NIK exposed.

## Commands Run

```bash
cd ~/Projects/new_lab_app
git fetch origin
git switch feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git pull --ff-only origin feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git switch -c feature/sprint-68-13-performance-closure-owner-stakeholder-summary

graphify update .

pwd
git status --short
git status --short --ignored | grep -E '(^!! .env$|.env|storage/app/backups|graphify-out|ssh|daengtisiams_vps|dump|\.sql|benchmark|screenshots)' || true
git branch --show-current
git log --oneline -5
php artisan about

ls docs/sprints | grep -E "68-(8|9|10|11|12)"
rg -n "Sprint 68\\.(8|9|10|11|12)|Owner Dashboard|500k|payment|threshold|NO DEPLOY|Performance Closure|Q5|Q6|HTTP|SQL|WATCH|OK" docs/sprints -g'*.md'

git diff --check
git status --short
git diff --stat
```

## Final Status

DONE / COMMITTED / PUSHED / PR MERGED / GO-TAGGED / NO DEPLOY
