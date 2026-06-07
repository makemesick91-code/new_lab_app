# Sprint 16.8 — Production Notes (Analytics Summary)

**Status:** Sprint 16.8.7 — production readiness  
**Module:** Inventory Analytics  
**Last updated:** 2026-06-07

---

## 1. Overview

Sprint 16.8 menambahkan **analytics summary tables** (`rpt_*`) untuk mempercepat dashboard dan halaman analitik inventory. Tabel ini adalah **read model / cache / reporting optimization** yang di-refresh dari ledger dan procurement.

**Source of truth tetap:**

- `trx_inventory_movements` — stok, consumption, inbound ledger
- Tabel transaksional procurement (`trx_purchase_orders`, `trx_goods_receipts`, dll.)

Summary **tidak** menggantikan ledger. Jika summary berbeda dengan perhitungan live, **ledger benar**.

---

## 2. New Tables

| Table | Granularity | Purpose |
|---|---|---|
| `rpt_inventory_daily_summaries` | branch × day | Trend konsumsi/inbound harian |
| `rpt_inventory_branch_summaries` | branch × snapshot_date | KPI strip cabang, branch comparison |
| `rpt_inventory_product_summaries` | branch × product × snapshot_date | Movement intel, aging, reorder |
| `rpt_procurement_daily_summaries` | branch × day [× supplier] | PO/GR trends, supplier rollup |

Semua tabel **derived** — tidak pernah di-update langsung oleh workflow transaksi.

---

## 3. New Commands

### Refresh (wajib sebelum aktivasi flag)

```bash
# Full refresh semua cabang aktif, tanggal hari ini
php artisan inventory:analytics-summary:refresh --all

# Refresh tanggal tertentu (PO/GR/PURCHASE movement historis)
php artisan inventory:analytics-summary:refresh --date=2026-06-07 --all

# Refresh satu cabang
php artisan inventory:analytics-summary:refresh --branch=ID --all

# Refresh product summary saja (satu cabang)
php artisan inventory:analytics-summary:refresh --branch=ID --product-summary
```

Opsi selektif: `--daily`, `--branch-summary`, `--procurement`.

### Prune (opsional — retention daily/procurement saja)

```bash
# Lihat jumlah row yang akan dihapus (tidak menghapus)
php artisan inventory:analytics-summary:prune --dry-run

# Hapus row daily/procurement lebih tua dari retention (default 730 hari)
php artisan inventory:analytics-summary:prune

# Override retention (minimum 30 hari)
php artisan inventory:analytics-summary:prune --days=365
```

**Prune tidak menyentuh:**

- `trx_inventory_movements` atau tabel transaksi lain
- `rpt_inventory_branch_summaries`
- `rpt_inventory_product_summaries`

---

## 4. Feature Flag

| Env | Config key | Default | Effect |
|---|---|---|---|
| `INVENTORY_ANALYTICS_SUMMARY_ENABLED` | `inventory.analytics_summary_enabled` | **`false`** | `false` → live `InventoryAnalyticsRepository`; `true` → `InventorySummaryAnalyticsRepository` |

Rollback instan: set flag `false`, clear config cache — **tanpa** truncate `rpt_*`.

Retention config (prune only):

| Env | Config key | Default |
|---|---|---|
| `INVENTORY_ANALYTICS_SUMMARY_RETENTION_DAYS` | `inventory.analytics_summary_retention_days` | **730** |

---

## 5. Activation Checklist

1. Deploy code ke production/staging
2. `php artisan migrate --force`
3. Backfill summary:
   ```bash
   php artisan inventory:analytics-summary:refresh --all
   ```
4. Set `.env`:
   ```env
   INVENTORY_ANALYTICS_SUMMARY_ENABLED=true
   ```
5. Reload config:
   ```bash
   php artisan config:clear
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
6. Frontend assets (jika UI berubah):
   ```bash
   npm run build
   ```
7. Verifikasi analytics dashboard (`/inventory/analytics`) — mode hint menampilkan **Analytics summary mode aktif**
8. Verifikasi executive dashboard load tanpa error
9. Verifikasi permission cross-branch (`view_inventory_cross_branch_analytics`) untuk Admin Lab / Super Admin
10. Pastikan cron Laravel scheduler aktif (lihat §7)

---

## 6. Rollback Checklist

1. Set `.env`:
   ```env
   INVENTORY_ANALYTICS_SUMMARY_ENABLED=false
   ```
2. Reload config:
   ```bash
   php artisan config:clear
   php artisan config:cache
   ```
3. Verifikasi analytics dashboard — mode hint **Live ledger mode**
4. **Jangan** truncate `rpt_*` kecuali ada instruksi eksplisit — data summary aman dibiarkan

Binding kembali ke live ledger repository; transaksi tidak terpengaruh.

---

## 7. Scheduler Setup

Pastikan cron Laravel aktif di server:

```cron
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

### Scheduled jobs (Sprint 16.8.7)

| Job | Schedule | Command | Notes |
|---|---|---|---|
| Summary refresh | Daily **01:30** server time | `inventory:analytics-summary:refresh --all` | `withoutOverlapping()`; aman walaupun flag `false` |
| Summary prune | Monthly **1st at 02:30** | `inventory:analytics-summary:prune` | Hanya daily/procurement rows; `withoutOverlapping()` |

Verifikasi manual:

```bash
php artisan schedule:list
```

Scheduler **tidak** mengaktifkan feature flag — hanya mempersiapkan/menyegarkan data `rpt_*`.

---

## 8. Troubleshooting

| Symptom | Likely cause | Action |
|---|---|---|
| Dashboard KPI 0/null | Summary belum di-refresh | `php artisan inventory:analytics-summary:refresh --all` |
| Mode hint "Summary belum di-refresh" | Flag true tapi snapshot kosong | Jalankan refresh; atau set flag false sementara |
| Branch comparison kosong | Permission atau summary missing | Cek `view_inventory_cross_branch_analytics`; refresh branch summaries |
| Cross-branch tab tidak muncul | User tidak punya permission | Assign permission ke role yang sesuai |
| Perubahan .env tidak efek | Config cache stale | `php artisan config:clear && php artisan config:cache` |
| Purchase trend tidak match | Procurement daily belum di-refresh untuk tanggal PO/GR/PURCHASE | `php artisan inventory:analytics-summary:refresh --date=YYYY-MM-DD --all` |
| Supplier tab lambat | Expected — `getSupplierPerformance` masih live fallback | Normal; bukan bug summary |
| Prune tidak jalan | Scheduler/cron tidak aktif | Cek cron; atau jalankan prune manual `--dry-run` dulu |

---

## 9. Data Correctness

1. **Ledger wins** — jika summary ≠ live, perbaiki refresh service, bukan ledger.
2. Re-run refresh idempotent — aman dijalankan ulang.
3. Feature flag `false` = instant fallback ke live queries.
4. Reconciliation tests (`InventoryAnalyticsSummaryReconciliationTest`) memvalidasi parity KPI setelah refresh.

---

## 10. Known Limitations

| Limitation | Detail |
|---|---|
| `getSupplierPerformance` | Masih live fallback di summary repo (avg lead time, coverage %, cancelled PO rate) |
| Custom days fast/slow | Hanya 7/30/90 dari summary; selain itu fallback live |
| Dead stock custom days | Flag `is_dead_stock` hanya default 90 hari |
| Stock aging | Fallback live jika product snapshot belum ada |
| Location-level analytics | Product summary branch-level only (Sprint 16.7 parity) |
| Historical trends | Perlu refresh per tanggal relevan untuk trend historis |
| Executive dashboard | Belum deferred tabs (analytics index only — Sprint 16.8.6) |
| `migrate:fresh --seed` | **Jangan** di production — hanya dev/test |

---

## References

- `docs/sprint_16_8_analytics_optimization_design.md` — design authority
- `docs/database_schema.md` — Section 20 (rpt_* tables)
- `config/inventory.php` — feature flag & retention
- `routes/console.php` — scheduler definitions

---

*Sprint 16.8.7 deliverable — production deployment guide before Sprint 16.8.8 final completion.*
