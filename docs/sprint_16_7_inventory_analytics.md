# Sprint 16.7 — Inventory Analytics & Executive Dashboard

**Status:** COMPLETED  
**Baseline:** Sprint 16.6 Inventory Audit Trail complete (`sprint-16.6-complete`)  
**Tag:** `sprint-16.7-complete`  
**Module:** `app/Modules/Inventory`  
**Prerequisites:** Sprint 12 Inventory Core, Sprint 15.4 Reorder & Alerts, Sprint 15.5 Inventory Analytics, Sprint 16.1–16.3 Procurement (PR/PO/GR), Sprint 16.5 Permission Hardening, Sprint 16.6 Activity Log  
**Phases:** 16.7.1–16.7.10 PASS  
**Completion doc:** `docs/sprint_16_7_inventory_analytics_completion.md`  
**Last updated:** 2026-06-07

---

## Tujuan

Membangun **Analytics Layer** terpadu dan **Executive Dashboard** untuk Inventory yang menjawab pertanyaan bisnis manajemen:

- Berapa nilai persediaan yang terikat?
- Material mana yang cepat/lambat/mati?
- Bagaimana tren pembelian dan konsumsi?
- Supplier mana yang andal?
- Produk mana yang harus segera dipesan ulang?

Target pengguna: **Owner**, **Admin Lab**, **Manager Cabang**.

Semua metrik **read-only**, **ledger-derived**, dan **branch-safe** — tanpa mutable stock column, tanpa Redis/Queue/Data Warehouse.

---

## Problem Statement

ADLMS sudah memiliki:

| Surface | Cakupan | Keterbatasan untuk eksekutif |
|---|---|---|
| `inventory/dashboard` | KPI operasional cabang aktif (nilai, alert stok, batch) | Tidak ada tren, tidak ada procurement intelligence |
| `inventory/analytics` (Sprint 15.5) | Fast/slow/dead, aging, turnover, nilai per kategori/lokasi, tren keluar bulanan | Hanya cabang aktif; tidak ada supplier/purchase/consumption/reorder insight |
| `inventory/alerts` (Sprint 15.4) | Low stock, out of stock, batch expiry | Daftar alert, bukan rekomendasi kuantitas pesan |
| Procurement (Sprint 16.1–16.3) | PR → PO → GR dengan ledger PURCHASE | Data procurement belum diolah menjadi KPI eksekutif |
| Activity Log (Sprint 16.6) | Audit trail workflow | Bukan surface analitik agregat |

**Gap yang diisi Sprint 16.7:**

1. Satu **Executive Dashboard** yang merangkum 10 domain analitik dalam satu layar keputusan.
2. **Analytics Layer** yang menggabungkan ledger (`trx_inventory_movements`), master data (`inv_products`), procurement (`trx_purchase_orders`, `trx_goods_receipts`), dan alert config — tanpa tabel snapshot baru di MVP.
3. **Supplier Performance** dan **Purchase Trend** dari dokumen procurement Sprint 16.
4. **Consumption Trend** dan **Reorder Recommendation** yang actionable untuk purchasing.
5. **Cross-branch rollup** terkontrol untuk Owner/Super Admin (deferred sejak Sprint 15.4/15.5 — diimplementasikan terbatas di sprint ini).

---

## Out of Scope

| Item | Alasan |
|---|---|
| HR / Payroll | Di luar domain Inventory |
| Redis / Queue | Agregasi on-read; tidak ada background pre-compute |
| Data Warehouse / OLAP terpisah | Tetap PostgreSQL + service layer |
| Mutable stock / analytics counters | Melanggar inventory constitution |
| Notifikasi push/email alert analytics | Channel notification tetap deferred (Sprint 15.4) |
| Export CSV/PDF massal | Follow-up; gate `manage_inventory_analytics` disiapkan |
| Historical on-hand value snapshots | Tren nilai stok historis tetap approximate (lihat Risiko) |
| Accounting-grade COGS / FIFO costing | `average_cost` operational only |
| Owner Dashboard global (lab order + finance) | Tetap di `docs/ui_owner_dashboard_design.md`; sprint ini fokus inventory executive slice |

---

## Business Value

| Capability | Manfaat operasional |
|---|---|
| Inventory KPI ringkas | Owner/Admin menilai kesehatan persediaan dalam < 10 detik |
| Stock Valuation | Transparansi modal kerja terikat di gudang |
| Fast / Slow / Dead Stock | Optimasi shelf space dan cash flow |
| Stock Aging | Rotasi FEFO; selaras batch expiry alerts |
| Supplier Performance | Evaluasi vendor resin, zirconia, consumables |
| Purchase Trend | Deteksi lonjakan/penurunan spending procurement |
| Consumption Trend | Forecast kebutuhan material produksi |
| Reorder Recommendation | Kurangi stock-out; percepat PR dari data, bukan intuisi |
| Branch comparison (Owner) | Identifikasi cabang overstock / understock |
| Ledger integrity | Semua angka dapat ditelusuri ke movement rows |

---

## Current Baseline (Jangan Rusak)

### Ledger & stock (Sprint 12+)

```text
current_stock = SUM(quantity_in) - SUM(quantity_out)
WHERE branch_id = B [AND inventory_location_id = L] [AND product_id = P]
```

Movement types produksi: `OPENING`, `PURCHASE`, `ADJUSTMENT_IN`, `ADJUSTMENT_OUT`, `TRANSFER_IN`, `TRANSFER_OUT`.

### Analytics existing (Sprint 15.5 — COMPLETED)

| Method | Route / UI |
|---|---|
| `InventoryAnalyticsService::getFastMovingProducts()` | Tab Produk Cepat Bergerak |
| `getSlowMovingProducts()` | Tab Produk Lambat Bergerak |
| `getDeadStockProducts()` | Tab Stok Mati |
| `getInventoryAging()` | Tab Umur Persediaan |
| `getInventoryTurnover()` | Tab Perputaran |
| `getInventoryValueByCategory/Location()` | Tab Nilai |
| `getMonthlyOutboundValueTrend()` | Tab Tren Nilai Keluar |
| `getAnalyticsSummary()` | KPI strip analytics |

Route: `GET inventory/analytics` → `inventory.analytics.index`  
Permission: `view_inventory_analytics` (+ fallback `view_inventory`, `manage_inventory`)

### Operational dashboard (Sprint 12–15.4)

Route: `GET inventory/dashboard` → `inventory.dashboard`  
KPI: nilai persediaan, stok kritis/habis/rendah, batch kedaluwarsa  
Widgets: alert summary, stock by location, recent movements, quick actions

### Procurement (Sprint 16.1–16.3)

| Tabel | Use in 16.7 |
|---|---|
| `trx_purchase_requests` + items | Demand signal (submitted/approved PR count & value) |
| `trx_purchase_orders` + items | Purchase trend, supplier order volume |
| `trx_goods_receipts` + items | Delivery performance, received value |
| `trx_inventory_movements` (`PURCHASE`) | Actual inbound qty/value by supplier |

### Reorder config (Sprint 15.4)

| Field | Use |
|---|---|
| `inv_products.reorder_point` | Effective reorder threshold |
| `inv_products.minimum_stock` | Critical threshold fallback |
| `inv_products.alert_enabled` | Filter produk aktif alert |

### Activity log (Sprint 16.6)

`inv_inventory_activity_logs` — **bukan** sumber agregat KPI (lihat **Analytics Data Source Rule**). Hanya drill-down audit/traceability; **jangan** hitung KPI dari tabel ini.

---

## Analytics Layer Architecture

### Prinsip

```text
HTTP → Controller → FormRequest → Service → Repository(ies) → Model/DB

Executive path:
  Controller → InventoryExecutiveDashboardService → InventoryAnalyticsService → InventoryAnalyticsRepositoryInterface

Analytics path:
  Controller → InventoryAnalyticsService → InventoryAnalyticsRepositoryInterface
```

- **Tidak ada** Controller → Model langsung untuk agregat.
- **Tidak ada** business logic di Blade.
- Semua agregat di **Service**; query berat di **Repository** via interface.
- Transaksi DB **tidak diperlukan** (read-only).
- **Tidak ada migration wajib** di MVP — optional index follow-up jika `EXPLAIN` menunjukkan bottleneck.

### Service decomposition (final — Pre-Step 1)

**Keputusan arsitektur:** Jangan gunakan satu service besar (`InventoryExecutiveAnalyticsService`). Pisahkan **perhitungan analytics** dari **komposisi dashboard** agar Sprint 16.8 dapat mengganti data source tanpa refactor controller/UI, dan agar halaman analytics lengkap (`inventory/analytics`) dapat reuse KPI tanpa coupling ke executive layout.

| Service | Tanggung jawab |
|---|---|
| `InventoryAnalyticsService` (extend existing) | Menghitung **seluruh KPI dan analytics** — ledger, procurement, reorder, supplier |
| `InventoryExecutiveDashboardService` (new) | Menyusun **dashboard** dari hasil analytics — snapshot, cards, sections, layout payload |
| `InventoryStockService` (existing) | `getInventoryValue()`, `getBranchSummary()` — reuse via analytics, jangan duplikasi |
| `InventoryAlertService` (existing) | Severity classification — reuse untuk low stock / reorder candidate detection |

**Alasan desain:**

1. **Single Responsibility** — analytics = domain calculation; dashboard = presentation composition untuk executive view.
2. **Reuse** — `InventoryAnalyticsController` dan `InventoryExecutiveDashboardController` keduanya memanggil `InventoryAnalyticsService` langsung atau via dashboard composer.
3. **Testability** — unit test KPI terpisah dari test layout/section ordering.
4. **Future-proof** — Sprint 16.8 summary tables hanya mengganti repository di belakang `InventoryAnalyticsService`; dashboard service tidak berubah.

#### `InventoryAnalyticsService` — public API (final)

Bertanggung jawab menghitung seluruh KPI dan analytics. Bergantung pada `InventoryAnalyticsRepositoryInterface` (lihat Future Optimization Layer).

| Method | Domain |
|---|---|
| `getInventoryOverview()` | Ringkasan nilai, SKU, alert counts, turnover proxy |
| `getStockValuation()` | Nilai total, by category, by location, at-risk subsets |
| `getFastMovingItems()` | Top movers by outbound qty/value (reuse/alias `getFastMovingProducts()`) |
| `getSlowMovingItems()` | Slow movers (reuse/alias `getSlowMovingProducts()`) |
| `getDeadStockItems()` | Dead stock list + aggregate value (reuse/alias `getDeadStockProducts()`) |
| `getStockAging()` | Aging buckets fresh → very_old (reuse/alias `getInventoryAging()`) |
| `getPurchaseTrend()` | Monthly PO/GR/ledger PURCHASE series |
| `getConsumptionTrend()` | Monthly outbound qty + value (extend `getMonthlyOutboundValueTrend()`) |
| `getSupplierPerformance()` | Supplier ranking, fulfillment, lead time |
| `getReorderRecommendations()` | Actionable reorder list dengan suggested qty |

#### `InventoryExecutiveDashboardService` — public API (final)

Bertanggung jawab menyusun dashboard dari hasil analytics. **Tidak** berisi query DB langsung; hanya orchestrate + map ke DTO/view model.

| Method | Output |
|---|---|
| `getExecutiveSnapshot()` | `InventoryExecutiveSnapshot` DTO — KPI strip atas |
| `getDashboardCards()` | Array card view models (tone, trend, href) |
| `getDashboardSections()` | Ordered sections: trends, movement intel, valuation, supplier, reorder, branch compare |
| `getExecutiveDashboard()` | Full payload untuk controller → view |

```text
InventoryExecutiveDashboardController
  → InventoryExecutiveDashboardService::getExecutiveDashboard()
      → InventoryAnalyticsService (semua domain KPI)
      → InventoryExecutiveSnapshot DTO
      → section/card composers (no DB)
```

**Supporting services (reuse only):** `InventoryStockService`, `InventoryAlertService` — dipanggil **dari dalam** `InventoryAnalyticsService`, bukan dari dashboard service.

### Repository extensions

| Interface | Method baru (usulan) |
|---|---|
| `InventoryMovementRepositoryInterface` | `monthlyInboundByType()`, `monthlyOutboundQtyValue()`, `consumptionByProductInPeriod()` |
| `PurchaseOrderRepositoryInterface` (new atau extend existing) | `monthlyPurchaseOrderStats()`, `supplierOrderAggregates()` |
| `GoodsReceiptRepositoryInterface` (new atau extend existing) | `supplierDeliveryPerformance()`, `monthlyReceivedValue()` |
| `InventoryMovementRepositoryInterface` | `purchaseValueBySupplier()` — PURCHASE movements grouped by `supplier_id` |

Jika repository procurement belum ada interface-nya, ikuti pola `PurchaseOrderService` existing — tambah interface + binding di `RepositoryServiceProvider`.

### Branch resolution

| Persona | Branch scope |
|---|---|
| Manager Cabang | `BranchContext::requireId()` — satu cabang |
| Admin Lab | Default cabang aktif; optional filter cabang jika user punya akses multi-cabang |
| Owner / Super Admin | Cross-branch **hanya** dengan `view_inventory_cross_branch` + explicit opt-in (lihat Cross Branch Rules) |

```php
// Pseudocode — service entry (InventoryAnalyticsService / DashboardService)
public function resolveBranchScope(User $user, ?int $requestedBranchId): BranchScope
{
    if ($requestedBranchId !== null) {
        $this->assertUserCanAccessBranch($user, $requestedBranchId);
        return BranchScope::single($requestedBranchId);
    }

    if ($user->can('view_inventory_cross_branch')) {
        return BranchScope::all(); // explicit opt-in only
    }

    return BranchScope::single($this->branchContext->requireId());
}
```

**Forbidden:** Trust `branch_id` dari request tanpa policy check. Manager Cabang tidak boleh melihat cabang lain meskipun mengirim `branch_id` berbeda.

---

## Architecture Decisions

Ringkasan keputusan arsitektur Sprint 16.7 Pre-Step 1 — authority untuk implementasi Step 1–11.

| # | Keputusan | Alasan |
|---|---|---|
| AD-1 | Pisah `InventoryAnalyticsService` dan `InventoryExecutiveDashboardService` | Hindari god-service; analytics reusable di halaman analytics + executive |
| AD-2 | Analytics service bergantung pada `InventoryAnalyticsRepositoryInterface` | Sprint 16.8 dapat swap ke summary tables tanpa ubah consumer |
| AD-3 | Dashboard service hanya compose, tidak query | Controller thin; KPI testable terpisah |
| AD-4 | `InventoryExecutiveSnapshot` DTO typed | Hindari array tidak terstruktur di view/controller |
| AD-5 | Activity log **bukan** sumber KPI | Log untuk audit/drill-down; KPI dari ledger + procurement + master |
| AD-6 | Cross-branch explicit opt-in permission | Cegah leakage; default = BranchContext only |
| AD-7 | KPI governance table wajib sebelum implement | Satu definisi formula/source/refresh/scope untuk semua step |

---

## Controller Design Rule

Controller **thin** — wajib untuk `InventoryExecutiveDashboardController` dan `InventoryAnalyticsController`.

### Forbidden di controller

- `DB::table()` / `DB::raw()`
- Aggregate query (`SUM`, `COUNT`, `GROUP BY`)
- KPI calculation / business rules
- Branch scope resolution tanpa service/policy

### Allowed di controller

- `$this->authorize(...)`
- FormRequest validation (`validate()` via injected request)
- Memanggil service
- Return response / view

### Contoh canonical

```php
public function index(InventoryExecutiveDashboardFilterRequest $request): View
{
    $this->authorize('viewExecutiveDashboard', InventoryMovement::class);

    $dashboard = $this->dashboardService->getExecutiveDashboard(
        $request->user(),
        $request->validated(),
    );

    return view('inventory.executive-dashboard', [
        'dashboard' => $dashboard,
        'filters' => $request->validated(),
    ]);
}
```

**Rule:** Jika controller perlu angka KPI, angka itu **harus** sudah ada di return value service/DTO — bukan dihitung ulang di controller.

---

## Executive Snapshot DTO

### Object: `InventoryExecutiveSnapshot`

DTO immutable (readonly properties atau dedicated Data object) — output `InventoryExecutiveDashboardService::getExecutiveSnapshot()`.

| Field | Type | Deskripsi |
|---|---|---|
| `inventoryValue` | `float` | Total nilai persediaan (ledger × average_cost) |
| `activeSku` | `int` | COUNT produk aktif dengan derived stock > 0 |
| `deadStockCount` | `int` | COUNT dead stock (definisi KPI Governance) |
| `lowStockCount` | `int` | COUNT produk di/below reorder point atau severity low/critical |
| `openPr` | `int` | COUNT PR status submitted/approved (belum fully converted) |
| `openPo` | `int` | COUNT PO status `approved`, `sent`, `partially_received` |
| `pendingGr` | `int` | COUNT GR status draft/submitted (belum posted) |
| `inTransitTransfer` | `int` | COUNT stock transfer in-transit (issued, belum received) |
| `inventoryAccuracy` | `float|null` | % akurasi dari stock opname COMPLETED vs ledger (`null` jika belum ada opname selesai) |

### Alasan desain

- Menghindari array tidak terstruktur (`['inventory_value' => ..., 'open_po' => ...]`) yang sulit di-refactor dan rawan typo key di Blade.
- Executive KPI row dan API future (export JSON) share satu kontrak typed.
- Unit test dapat assert field-by-field tanpa magic string keys.

### Lokasi file (implementation phase)

`app/Modules/Inventory/DTOs/InventoryExecutiveSnapshot.php` (atau `Data/InventoryExecutiveSnapshot.php` — ikuti konvensi module jika sudah ada folder DTO).

---

## Analytics Data Source Rule

### Authority table

| Layer | Role |
|---|---|
| **Primary analytics sources** | `trx_inventory_movements`, `trx_purchase_requests`, `trx_purchase_orders`, `trx_goods_receipts`, `trx_stock_opnames`, `inv_products`, `inv_suppliers`, `inv_inventory_locations` |
| **Supporting (reuse services)** | Derived stock via movement ledger; batch tables untuk aging FEFO |
| **Activity log** | `inv_inventory_activity_logs` — **BUKAN** source utama analytics |

### Aturan `inv_inventory_activity_logs`

Activity log **hanya** digunakan untuk:

- **Drill-down** — link dari baris executive/analytics ke log filter by `correlation_id`, subject, action
- **Audit** — investigasi who/when/what pada workflow event
- **Traceability** — chain PR → PO → GR via `correlation_id`

### Warning eksplisit

> **Jangan menghitung KPI dari activity log.**
>
> Activity log adalah append-only audit trail (Sprint 16.6). Volume tinggi, schema event-oriented, bukan ledger quantity. Menghitung `SUM`, `COUNT`, atau trend dari log table melanggar desain Sprint 16.6 dan akan produce angka tidak konsisten dengan ledger.

Analytics utama **harus** menggunakan movement ledger dan procurement transactional tables — angka dapat direconcile ke baris `trx_inventory_movements`.

---

## Future Optimization Layer

### `InventoryAnalyticsRepositoryInterface`

Analytics service **wajib** bergantung pada interface — bukan query konkret di service.

```text
InventoryAnalyticsService
  → InventoryAnalyticsRepositoryInterface
      → InventoryAnalyticsRepository (Sprint 16.7 — current)
      → InventorySummaryAnalyticsRepository (Sprint 16.8 — future)
```

### Current implementation (Sprint 16.7)

| Source | Use |
|---|---|
| `trx_inventory_movements` | Stock derivation, consumption, purchase ledger, aging anchors |
| `trx_purchase_requests` + items | Open PR, demand signal |
| `trx_purchase_orders` + items | Open PO, purchase trend |
| `trx_goods_receipts` + items | Pending GR, supplier delivery |
| `trx_stock_opnames` | Inventory accuracy KPI |
| `inv_products`, `inv_suppliers`, `inv_inventory_locations` | Master filters, cost, reorder config |

### Future implementation (Sprint 16.8+)

| Source | Use |
|---|---|
| `inventory_daily_summary` | Pre-aggregated daily KPI (optional migration) |
| `inventory_monthly_summary` | Monthly trend without full ledger scan |
| Materialized reporting tables | Supplier performance, branch rollup cache |

### Tujuan

Sprint 16.8 dapat mengganti source analytics **tanpa mengubah**:

- Controller (`InventoryExecutiveDashboardController`, `InventoryAnalyticsController`)
- Dashboard service consumer (`InventoryExecutiveDashboardService`)
- Public method signatures `InventoryAnalyticsService`
- View contracts (DTO field names stabil)

Hanya binding `InventoryAnalyticsRepositoryInterface` → implementasi baru.

---

## Cross Branch Analytics

### Default analytics scope

Semua user **default**: `BranchContext::requireId()` — satu cabang aktif.

### Cross-branch analytics

Hanya jika user memiliki permission **`view_inventory_cross_branch`**.

| Persona | Cross-branch |
|---|---|
| Owner | ✅ dengan `view_inventory_cross_branch` |
| Super Admin | ✅ dengan `view_inventory_cross_branch` |
| Admin Lab | ❌ cabang aktif saja |
| Manager Cabang | ❌ cabang assigned only |
| Technician | ❌ |
| QC | ❌ |

### Warning eksplisit

> **Cross-branch query harus explicit opt-in.**
>
> Jangan fallback ke `WHERE 1=1` atau aggregate global hanya karena user pungsi `view_inventory_executive_dashboard`. Permission executive dashboard ≠ permission cross-branch. Query multi-cabang wajib: (1) cek `view_inventory_cross_branch`, (2) `GROUP BY branch_id` dengan label cabang, (3) feature test branch pair isolation.

### Enforcement checklist

- Filter `branch_id` dari request → policy `assertUserCanAccessBranch()` sebelum query
- Manager Cabang kirim `branch_id` cabang lain → **403** (fail closed)
- Branch comparison section (Section 7 dashboard) → gate `@can('viewCrossBranchInventoryAnalytics')` + service scope

---

## Design Lock — Pre-Step 2 (Analytics Governance)

Authority section untuk keputusan audit Sprint 16.7 Step 1. **Semua item di bawah ini LOCKED** — implementasi Step 2+ wajib mengikuti tanpa deviasi.

---

### Inventory Valuation Governance

**Keputusan final:**

```text
Inventory Value = Current Stock × average_cost
```

| Gunakan | Jangan gunakan |
|---|---|
| `inv_products.average_cost` | Weighted average costing |
| Derived stock `S(P)` dari ledger | FIFO |
| | LIFO |

> **⚠️ WARNING — Operational Inventory Value**
>
> Nilai persediaan di Sprint 16.7 adalah **Operational Inventory Value**, **bukan Accounting Valuation**.

**Alasan (audit finding):**

Audit menemukan `average_cost` **tidak otomatis dihitung ulang** saat Goods Receipt diposting. Nilai persediaan bergantung pada field `average_cost` yang di-maintain manual/operasional — bukan costing akuntansi real-time.

**Digunakan untuk:**

- Dashboard operasional
- Executive reporting
- Reorder prioritization

**Bukan untuk:**

- Laporan keuangan
- Accounting valuation
- COGS / P&L resmi

---

### Open Purchase Order Definition

**Keputusan final — status Open PO:**

| Termasuk (Open) | Tidak termasuk |
|---|---|
| `approved` | `draft` |
| `sent` | `submitted` |
| `partially_received` | `fully_received` |
| | `cancelled` |

**Contoh status flow:**

```text
draft → submitted → approved → sent → partially_received → fully_received
                         ↘ cancelled (terminal)
```

PO dengan status `sent` = sudah dikirim ke supplier tetapi belum diterima penuh → **tetap open commitment**.

**Alasan:**

PO yang sudah dikirim ke supplier tetapi belum diterima merupakan komitmen pembelian aktif. Menghitung hanya `approved` + `partially_received` akan **undercount** open commitment operasional.

---

### Consumption Definition

**Keputusan final:**

```text
Consumption = SUM(quantity_out)
```

**Termasuk movement types outbound:**

- `ADJUSTMENT_OUT`
- `TRANSFER_OUT`
- Semua `quantity_out` lain pada `trx_inventory_movements` dalam scope cabang/lokasi/periode

> **⚠️ WARNING**
>
> Consumption **bukan** pemakaian produksi murni. Consumption = **seluruh outbound inventory movement** dalam periode.

**Tujuan:**

Konsisten dengan definisi Sprint 15.5 analytics (`getMonthlyOutboundValueTrend`, fast/slow/dead berbasis `quantity_out`).

---

### Inventory Accuracy Governance

**Keputusan final — jika belum ada stock opname COMPLETED:**

```text
Inventory Accuracy = null
```

| Bukan | UI wajib menampilkan |
|---|---|
| `0%` | *Belum ada stock opname selesai.* |
| `100%` | (bukan angka palsu) |

**Formula (hanya jika ≥ 1 opname COMPLETED):**

```text
accuracy = 1 - (
  SUM(ABS(variance_quantity))
  /
  SUM(system_quantity)
)
```

- **Clamp:** `0%` – `100%`
- **Sumber:** `trx_stock_opnames` status COMPLETED + `trx_stock_opname_items` (`variance_quantity`, `system_quantity`)
- **Bukan:** opname draft/in-progress

---

### Supplier Performance Governance

**KPI tambahan — `coverage_percentage`:**

```text
coverage_percentage = (
  COUNT(PO dengan expected_delivery_date)
  /
  COUNT(Total PO non-draft, non-cancelled)
) × 100
```

**Aturan On-Time Delivery:**

| Kondisi PO | Perilaku |
|---|---|
| Memiliki `expected_delivery_date` | Masuk denominator on-time; dihitung `receipt_date <= expected_delivery_date` |
| Tanpa `expected_delivery_date` | Tidak masuk denominator on-time |
| Tanpa `expected_delivery_date` | **Tidak** dianggap terlambat |
| Tanpa `expected_delivery_date` | **Tidak** dianggap tepat waktu |

**UI wajib menampilkan (per supplier / agregat):**

- **On-Time Delivery %** — hanya dari PO dengan `expected_delivery_date`
- **Coverage %** — proporsi PO yang memiliki `expected_delivery_date`

---

### Activity Log Analytics Rule

**Keputusan final:**

`inv_inventory_activity_logs` **TIDAK BOLEH** digunakan sebagai sumber KPI.

**Hanya boleh digunakan untuk:**

- Audit
- Drill-down
- Traceability
- Detail investigasi

> **⛔ FORBIDDEN**
>
> - `COUNT(action)` dari activity log
> - `SUM(...)` dari activity log
> - Analytics / trend / KPI agregat dari activity log
>
> Analytics **wajib** menggunakan:
>
> - **Ledger** (`trx_inventory_movements`)
> - **Procurement** (`trx_purchase_requests`, `trx_purchase_orders`, `trx_goods_receipts`)
> - **Opname** (`trx_stock_opnames`)

---

### Cross Branch Analytics Governance

**Default:** `BranchContext::requireId()` — analytics hanya untuk cabang aktif.

**Cross Branch:** hanya jika user memiliki permission `view_inventory_cross_branch`.

| Role | Cross Branch |
|---|---|
| Owner | Yes |
| Super Admin | Yes |
| Admin Lab | No |
| Manager Cabang | No |
| Technician | No |
| QC | No |

> **⚠️ WARNING**
>
> Cross branch harus **explicit opt-in** via permission `view_inventory_cross_branch`. Executive dashboard permission (`view_inventory_executive_dashboard`) **≠** cross-branch permission.

---

### KPI Lock Matrix

Tabel authority final — semua KPI **LOCKED** untuk implementasi.

| KPI | Formula | Source | Fallback | Cross Branch Allowed | Status |
|---|---|---|---|---|---|
| **Inventory Value** | `SUM(S(P) × inv_products.average_cost)` | `trx_inventory_movements` + `inv_products` | `0` jika tidak ada stok | Yes (opt-in) | LOCKED |
| **Active SKU** | `COUNT(P)` where `S(P) > 0` AND `is_active` | Movements + `inv_products` | `0` | Yes (opt-in) | LOCKED |
| **Low Stock** | `COUNT(P)` where `S(P) ≤ effective_reorder_point(P)` | Movements + `inv_products` + `InventoryAlertService` | `0` | No (default branch) | LOCKED |
| **Dead Stock** | `COUNT(P)` where `S(P) > 0` AND no outbound ≥ N days | Movements + `inv_products` | `0` | No | LOCKED |
| **Open PR** | `COUNT(PR)` status ∈ `{submitted, approved}` not fully converted | `trx_purchase_requests` | `0` | No | LOCKED |
| **Open PO** | `COUNT(PO)` status ∈ `{approved, sent, partially_received}` | `trx_purchase_orders` | `0` | No | LOCKED |
| **Pending GR** | `COUNT(GR)` status ∈ `{draft, submitted}` not posted | `trx_goods_receipts` | `0` | No | LOCKED |
| **In Transit Transfer** | `COUNT(transfer)` status in-transit (shipped, belum received) | `trx_stock_transfers` | `0` | No | LOCKED |
| **Inventory Accuracy** | `1 - SUM(ABS(variance_qty)) / SUM(system_qty)`; clamp 0–100% | `trx_stock_opnames` COMPLETED + items | `null` + UI *Belum ada stock opname selesai.* | No | LOCKED |
| **Fast Moving** | Top N by `SUM(quantity_out)` in period DESC | `trx_inventory_movements` | Empty list | No | LOCKED |
| **Slow Moving** | `S(P) > 0` AND `OUT(P) ≤ threshold` | Movements + `inv_products` | Empty list | No | LOCKED |
| **Stock Aging** | Bucket by days since batch `received_date` or `last_in_date` | Movements + `inv_inventory_batches` | Empty buckets | No | LOCKED |
| **Purchase Trend** | Monthly PO/GR/ledger PURCHASE aggregates | `trx_purchase_orders`, `trx_goods_receipts`, movements | Empty series | Yes (opt-in) | LOCKED |
| **Consumption Trend** | Monthly `SUM(quantity_out)` + value | `trx_inventory_movements` | Empty series | No | LOCKED |
| **Supplier Performance** | Fulfillment, on-time (dated PO only), coverage %, lead time | PO + GR + PURCHASE movements | *Belum ada data supplier* | No | LOCKED |
| **Reorder Recommendation** | `S(P) ≤ effective_reorder_point(P)` + suggested qty | Movements + `inv_products` + alert config | Empty list | No | LOCKED |

**Refresh Strategy (semua KPI MVP):** On-read — tidak ada background job, Redis, atau mutable counter.

**Cross Branch Allowed:** `Yes (opt-in)` = hanya dengan `view_inventory_cross_branch` + explicit filter/rollup; default selalu single branch.

---

### Implementation Sequence

Urutan implementasi Sprint 16.7 **LOCKED**:

| Phase | Deliverable |
|---|---|
| **16.7.1** | `InventoryAnalyticsRepositoryInterface` |
| **16.7.2** | Analytics Repository Methods |
| **16.7.3** | `InventoryAnalyticsService` |
| **16.7.4** | `InventoryExecutiveSnapshot` DTO |
| **16.7.5** | `InventoryExecutiveDashboardService` |
| **16.7.6** | Analytics Tests |
| **16.7.7** | Executive Dashboard UI |
| **16.7.8** | Permission & Sidebar |
| **16.7.9** | Performance Audit |

**Catatan:** Phase 16.7.1–16.7.6 = backend-only (no UI). UI dan permission setelah KPI + tests stabil.

---

## KPI Governance (Legacy Quick Reference)

Detail narrative per domain tetap di section **KPI Definitions** di bawah. **Authority:** gunakan **KPI Lock Matrix** (Pre-Step 2) untuk implementasi — tabel ini disederhanakan untuk backward reference.

---

## KPI Definitions

Semua KPI dihitung **on-read**. Simbol bersama:

| Simbol | Arti |
|---|---|
| `B` | Branch ID (atau semua cabang jika rollup) |
| `L` | Optional `inventory_location_id` |
| `period` | `[date_from, date_to]` inklusif |
| `S(P)` | Derived stock produk P |
| `OUT(P)` | `SUM(quantity_out)` periode untuk P |
| `IN_PURCHASE(P)` | `SUM(quantity_in)` movement `PURCHASE` |

---

### 1. Inventory KPI (KPI Persediaan)

Ringkasan eksekutif — **bukan** duplikasi penuh operational dashboard.

| KPI | Formula / sumber | Label UI |
|---|---|---|
| Total Nilai Persediaan | `SUM(S(P) × average_cost)` semua produk aktif | Nilai Persediaan |
| Jumlah SKU Aktif Berstok | `COUNT(P)` where `S(P) > 0` | SKU Berstok |
| Stok Kritis + Habis | Count dari `InventoryAlertService` severity `critical` + `out_of_stock` | Produk Perlu Perhatian |
| Dead Stock Count | Count dead stock (definisi #5) | SKU Stok Mati |
| Fast Mover Count (period) | Count produk dengan `OUT(P) > 0` ranked top N | Produk Cepat Bergerak |
| Inventory Turnover Ratio | `OUT_value_period / avg_on_hand_value_period` (existing 15.5) | Rasio Perputaran |
| Purchase Spend (period) | `SUM(PO line qty × unit_price)` approved+ PO dalam period | Belanja Pembelian |
| Consumption Value (period) | `SUM(OUT × average_cost)` | Nilai Konsumsi |
| Open PO Count | `COUNT(PO)` status `approved`, `sent`, `partially_received` | PO Terbuka |
| Pending GR Count | `COUNT(GR)` status `draft`, `submitted` | Penerimaan Draft |

**Severity states (KPI cards):**

| State | Kondisi contoh |
|---|---|
| `healthy` | Dead stock < 5% SKU berstok; turnover dalam band normal |
| `warning` | Dead stock 5–15%; low stock > 10 SKU |
| `critical` | Out of stock > 5 SKU kritis; batch expired > 0 |

**Trend:** bandingkan `period` vs `previous_period` sama panjang (mis. 30 hari vs 30 hari sebelumnya). Tampilkan `+12%` / `-8%` pada card.

---

### 2. Stock Valuation (Valuasi Persediaan)

| Metrik | Formula | Catatan |
|---|---|---|
| Total Inventory Value | `InventoryStockService::getInventoryValue()` | Snapshot saat ini |
| Value by Category | Existing `getInventoryValueByCategory()` | Breakdown pie/bar |
| Value by Location | Existing `getInventoryValueByLocation()` | Breakdown bar |
| Value at Risk (dead stock) | `SUM(dead_stock_qty × average_cost)` | Subset dead stock report |
| Value at Risk (expiring batch) | Batch dengan `expiry_date` ≤ 30 hari × derived batch stock × cost | Selaras alert batch |
| Inbound Value (period) | `SUM(quantity_in × unit_cost)` movement IN dalam period | Termasuk PURCHASE, OPENING, ADJ_IN, TRANSFER_IN |
| Outbound Value (period) | `SUM(quantity_out × average_cost)` | Consumption proxy |

**Disclaimer UI wajib:** *Operational Inventory Value — dihitung dari stok ledger × `inv_products.average_cost`. Bukan accounting valuation. `average_cost` tidak otomatis dihitung ulang saat GR diposting.*

---

### 3. Fast Moving Items (Produk Pergerakan Cepat)

**Reuse Sprint 15.5** — definisi tidak berubah:

```text
fast_moving_score(P) = OUT(P) dalam period
ORDER BY OUT(P) DESC
```

Executive dashboard: tampilkan **Top 5** (bukan 25) dengan link ke `inventory/analytics#section-fast`.

| Kolom | Keterangan |
|---|---|
| Produk / SKU | Nama + kode |
| Jumlah Keluar | `OUT(P)` |
| Nilai Keluar | `OUT(P) × average_cost` |
| Stok Saat Ini | `S(P)` |
| % dari Total Konsumsi | `OUT(P) / SUM(OUT_all) × 100` |

---

### 4. Slow Moving Items (Produk Pergerakan Lambat)

**Reuse Sprint 15.5:**

```text
slow_moving(P) = S(P) > 0 AND OUT(P) <= slow_moving_threshold
```

Executive dashboard: Top 5 slow movers by `S(P) DESC` setelah filter threshold.

Tambahan kolom eksekutif: **Hari Tanpa Keluar** (sama definisi dead stock partial) untuk konteks.

---

### 5. Dead Stock (Stok Mati)

**Reuse Sprint 15.5:**

```text
dead_stock(P) = S(P) > 0 AND (last_out_date IS NULL OR last_out_date < today - dead_stock_days)
```

Default `dead_stock_days = 90`.

Executive KPI: **Nilai Stok Mati** = `SUM(S(P) × average_cost)` untuk semua dead stock.

---

### 6. Stock Aging (Penuaan Persediaan)

**Reuse Sprint 15.5** aging buckets:

| Bucket | Hari sejak anchor |
|---|---|
| `fresh` | 0–30 |
| `aging` | 31–60 |
| `stale` | 61–90 |
| `old` | 91–180 |
| `very_old` | > 180 |

Anchor: `received_date` batch jika batch stock > 0; else `last_in_date` produk (proxy).

Executive dashboard: **stacked bar** jumlah SKU atau nilai per bucket; drill-down ke analytics aging tab.

---

### 7. Supplier Performance (Kinerja Supplier)

**Baru — sumber procurement + ledger.**

Per supplier `S` dalam cabang `B` dan `period`:

| Metrik | Formula | Label UI |
|---|---|---|
| Order Count | `COUNT(DISTINCT PO.id)` where `supplier_id = S`, status ∉ `draft,cancelled` | Jumlah PO |
| Order Value | `SUM(PO_item.quantity × unit_price)` | Nilai PO |
| Received Value | `SUM(GR posted line received_qty × unit_cost)` atau PURCHASE movement value | Nilai Diterima |
| Fulfillment Rate | `received_qty / ordered_qty` per PO line aggregate | Tingkat Pemenuhan |
| On-Time Delivery Rate | `COUNT(on-time GR) / COUNT(GR where PO has expected_delivery_date)` — PO tanpa tanggal di-exclude | Ketepatan Waktu |
| Coverage % | `COUNT(PO with expected_delivery_date) / COUNT(PO non-draft, non-cancelled) × 100` | Cakupan Data Pengiriman |
| Avg Lead Time (days) | `AVG(receipt_date - order_date)` untuk GR posted | Rata-rata Lead Time |
| Purchase Ledger Share | `SUM(PURCHASE movement value where supplier_id = S) / SUM(all PURCHASE)` | Kontribusi Pembelian |
| Cancelled PO Rate | `COUNT(PO cancelled) / COUNT(PO non-draft)` | Tingkat Pembatalan |

**Ranking default:** by Received Value DESC.

**Inclusion rules:**

- Supplier `is_active = true`, `branch_id = B`
- PO/GR harus `branch_id = B` (tidak cross-branch)
- GR posted = status yang menulis ledger (`posted` / completed per Sprint 16.3 enum)

**Empty state:** *Belum ada data supplier untuk periode ini.*

---

### 8. Purchase Trend (Tren Pembelian)

Agregat bulanan dalam `period`:

| Series | Sumber | Metrik |
|---|---|---|
| PO Created | `trx_purchase_orders.order_date` | Count + total line value |
| PO Approved | `approved_at` date | Count + value |
| GR Posted | `trx_goods_receipts.posted_at` | Count + received value |
| Ledger PURCHASE | `trx_inventory_movements` type PURCHASE | Inbound qty + value |

```text
purchase_trend(month) = {
  po_count, po_value,
  gr_count, gr_received_value,
  ledger_purchase_value
}
GROUP BY DATE_TRUNC('month', date_field)
```

**Chart:** multi-line atau grouped bar (Bulan × Nilai).

**Filter:** `date_from`, `date_to` (default 6 bulan).

---

### 9. Consumption Trend (Tren Konsumsi)

Agregat bulanan outbound:

```text
consumption_trend(month) = {
  outbound_qty: SUM(quantity_out),
  outbound_value: SUM(quantity_out × average_cost_at_report_time)
}
WHERE movement_date in month AND branch_id = B
```

**Reuse/extend** `getMonthlyOutboundValueTrend()` — tambah `outbound_qty` series.

**Optional breakdown:** per `product_category_id` (top 5 kategori by value).

**Catatan (LOCKED):** Consumption = `SUM(quantity_out)` — termasuk `TRANSFER_OUT`, `ADJUSTMENT_OUT`, dan semua outbound. Bukan pemakaian produksi murni. Konsisten Sprint 15.5.

---

### 10. Reorder Recommendation (Rekomendasi Pesanan Ulang)

Produk kandidat reorder — actionable list:

```text
reorder_candidate(P) =
  is_active = true
  AND alert_enabled = true
  AND S(P) <= effective_reorder_point(P)
```

| Kolom | Formula |
|---|---|
| Stok Saat Ini | `S(P)` |
| Reorder Point | `effective_reorder_point(P)` dari `InventoryAlertService` |
| Minimum Stock | `minimum_stock` |
| Safety Stock Gap | `reorder_point - S(P)` (min 0) |
| Avg Daily Consumption | `OUT(P, last_30_days) / 30` |
| Suggested Order Qty | `MAX(minimum_order_qty, avg_daily × lead_time_days + safety_gap)` |
| Est. Days Until Stockout | `S(P) / avg_daily` jika avg_daily > 0 |
| Preferred Supplier | Last `supplier_id` dari PURCHASE movement terbaru untuk P |
| Suggested Action | Link buat PR (`purchase-requests.create` dengan query prefill) |

**Parameter service (config constants, bukan DB):**

```php
public const REORDER_LOOKBACK_DAYS = 30;
public const DEFAULT_LEAD_TIME_DAYS = 14;
public const DEFAULT_MIN_ORDER_QTY = 1;
```

**Severity:**

| Level | Kondisi |
|---|---|
| `critical` | `S(P) <= minimum_stock` atau stockout |
| `low` | `S(P) <= reorder_point` |
| `watch` | Within 10% above reorder point |

**Out of scope MVP:** auto-create PR; hanya prefill link seperti Sprint 16.1 alerts shortcut.

---

## Executive Dashboard Layout

### Route & navigation

| Method | URI | Name | Deskripsi |
|---|---|---|---|
| GET | `inventory/executive-dashboard` | `inventory.executive-dashboard` | Executive Dashboard (baru) |
| GET | `inventory/analytics` | `inventory.analytics.index` | Deep-dive analytics (enhanced) |
| GET | `inventory/dashboard` | `inventory.dashboard` | Operational dashboard (unchanged) |

**Sidebar** — grup **Persediaan**:

```text
Dasbor                    → inventory.dashboard
Dasbor Eksekutif          → inventory.executive-dashboard   [NEW — permission gated]
Analitik Persediaan       → inventory.analytics.index
```

Urutan: Operational → Executive → Analytics (semakin dalam).

### Page structure — `inventory/executive-dashboard`

```text
<x-settings-shell title="Dasbor Eksekutif Persediaan">

  ┌─ Header ─────────────────────────────────────────────────────────┐
  │ Judul, deskripsi, badge "Dihitung dari ledger"                   │
  │ Filter: Periode | Cabang (Owner only) | Lokasi | Kategori        │
  │ Actions: Buka Analitik Lengkap | Buka Peringatan | Buat PR       │
  └──────────────────────────────────────────────────────────────────┘

  ┌─ Section 1: Executive KPI Row (grid 2×3 mobile, 3×4 desktop) ────┐
  │ Nilai Persediaan | Konsumsi (periode) | Belanja (periode)         │
  │ SKU Berstok | Stok Mati (nilai) | Rasio Perputaran               │
  │ PO Terbuka | Rekomendasi Pesan | Batch Risiko                    │
  └──────────────────────────────────────────────────────────────────┘

  ┌─ Section 2: Trend Charts (2-col desktop, stack mobile) ──────────┐
  │ [Tren Konsumsi]          [Tren Pembelian]                        │
  │ line/bar 6 bulan         multi-series PO/GR/Ledger                 │
  └──────────────────────────────────────────────────────────────────┘

  ┌─ Section 3: Movement Intelligence (3-col cards) ───────────────────┐
  │ Top 5 Cepat Bergerak | Top 5 Lambat Bergerak | Top 5 Stok Mati   │
  └──────────────────────────────────────────────────────────────────┘

  ┌─ Section 4: Valuation & Aging ─────────────────────────────────────┐
  │ Nilai per Kategori (bar) | Penuaan Persediaan (stacked bucket)    │
  └──────────────────────────────────────────────────────────────────┘

  ┌─ Section 5: Supplier Performance Table ────────────────────────────┐
  │ Desktop table + mobile cards; sort by Nilai Diterima              │
  └──────────────────────────────────────────────────────────────────┘

  ┌─ Section 6: Reorder Recommendation ────────────────────────────────┐
  │ Priority-sorted table; CTA "Buat PR" per row                      │
  └──────────────────────────────────────────────────────────────────┘

  ┌─ Section 7: Branch Comparison (Owner cross-branch only) ───────────┐
  │ Cards per cabang: nilai, konsumsi, dead stock %, alert count       │
  └──────────────────────────────────────────────────────────────────┘

</x-settings-shell>
```

### Visual design

Ikuti `docs/ui_design_system.md` dan komponen `inventory.*`:

- Teal accent; `tabular-nums`; `format_currency_id` / `format_number_id`
- KPI cards: reuse `<x-inventory.kpi-card>` dengan `tone`, `hint`, `href`
- Charts: CSS/SVG minimal atau Alpine — **tanpa** library JS baru
- Empty states per section dengan hint ubah filter
- Mobile: KPI stack → charts stack → tables jadi cards

### Enhanced analytics page (existing)

Tambah tab/section di `inventory/analytics/index`:

| Section baru | ID anchor |
|---|---|
| Kinerja Supplier | `#section-supplier` |
| Tren Pembelian | `#section-purchase-trend` |
| Tren Konsumsi | `#section-consumption-trend` |
| Rekomendasi Pesan Ulang | `#section-reorder` |

Tab navigation existing diperluas; filter bar shared tetap sama.

---

## Permission Mapping

### Permission baru (usulan)

| Permission | Deskripsi | Grup Role Management |
|---|---|---|
| `view_inventory_executive_dashboard` | Lihat Dasbor Eksekutif Persediaan; termasuk cross-branch rollup untuk Owner | Inventory / Persediaan |
| `view_inventory_cross_branch` | Izin eksplisit agregasi multi-cabang (subset dari executive) | Inventory / Persediaan |

**Existing (tetap dipakai):**

| Permission | Use |
|---|---|
| `view_inventory_analytics` | Halaman analitik lengkap |
| `manage_inventory_analytics` | Export (future), konfigurasi threshold analytics (future) |
| `view_inventory` | Fallback operasional dashboard |
| `manage_inventory` | Full inventory admin |
| `manage_report` | Fallback Owner access (sementara, backward compatible) |

### Role → permission matrix

| Role / Persona | Executive Dashboard | Cross-Branch Rollup | Analytics Lengkap | Operational Dashboard | Reorder CTA (buat PR) |
|---|---|---|---|---|---|
| **Owner** (Super Admin) | ✅ `view_inventory_executive_dashboard` | ✅ `view_inventory_cross_branch` | ✅ `view_inventory_analytics` | ✅ | ✅ jika `manage_purchase_request` |
| **Admin Lab** | ✅ | ❌ default cabang aktif saja | ✅ | ✅ | ✅ |
| **Manager Cabang** | ✅ | ❌ cabang assigned only | ✅ `view_inventory_analytics` | ✅ | ✅ jika `manage_purchase_request` |
| Technician | ❌ | ❌ | ❌ | ✅ `view_inventory` | ❌ |
| Finance | ❌ | ❌ | ❌ | ❌ | ❌ |
| QC / Delivery / Courier | ❌ | ❌ | ❌ | partial `view_inventory` | ❌ |

### Policy

```php
// InventoryExecutiveDashboardPolicy atau extend InventoryMovementPolicy
public function viewExecutiveDashboard(User $user): bool
{
    return $user->canAny([
        'view_inventory_executive_dashboard',
        'manage_inventory',
        'manage master data',
    ]);
}

public function viewCrossBranchInventoryAnalytics(User $user): bool
{
    return $user->canAny([
        'view_inventory_cross_branch',
        'manage master data', // Super Admin wildcard
    ]);
}
```

Controller:

```php
$this->authorize('viewExecutiveDashboard', InventoryMovement::class);
```

**Sidebar gates:**

```blade
@can('viewExecutiveDashboard', \App\Modules\Inventory\Models\InventoryMovement::class)
    <a href="{{ route('inventory.executive-dashboard') }}">Dasbor Eksekutif</a>
@endcan
```

### RoleSeeder update (implementation phase)

| Role | Tambahan permission |
|---|---|
| Super Admin | `*` (otomatis) |
| Admin Lab | `view_inventory_executive_dashboard`, `view_inventory_analytics`, `manage_inventory_analytics` |
| (Future) Owner / Manager Cabang | Definisikan role resmi di PROJECT_RULES jika belum ada; sementara map ke Admin Lab + branch assignment |

---

## Filter Plan

**Form request:** `InventoryExecutiveDashboardFilterRequest`

| Parameter | Type | Default | Validation |
|---|---|---|---|
| `date_from` | date | `today - 30 days` | nullable, before_or_equal:date_to |
| `date_to` | date | `today` | nullable |
| `branch_id` | int | null (all jika allowed) | nullable, exists, policy cross-branch |
| `location_id` | int | null | nullable, exists in resolved branch |
| `category_id` | int | null | nullable, exists in resolved branch |
| `dead_stock_days` | int | 90 | 30–365 |
| `slow_moving_threshold` | numeric | 1 | 0–1000 |
| `supplier_limit` | int | 10 | 5–50 |
| `reorder_limit` | int | 20 | 5–100 |

Query string preserved; reset link ke default.

---

## Implementation Phases

**Authority:** urutan **LOCKED** di section **Implementation Sequence** (Pre-Step 2). Ringkasan deliverable per phase:

| Phase | Scope | UI? |
|---|---|---|
| **16.7.1** | `InventoryAnalyticsRepositoryInterface` + provider binding | No |
| **16.7.2** | Analytics repository methods (ledger, procurement, opname, transfer) | No |
| **16.7.3** | `InventoryAnalyticsService` — 10 KPI methods per KPI Lock Matrix | No |
| **16.7.4** | `InventoryExecutiveSnapshot` DTO | No |
| **16.7.5** | `InventoryExecutiveDashboardService` — compose only, no DB | No |
| **16.7.6** | Analytics tests — setiap KPI Lock Matrix row + branch isolation | No |
| **16.7.7** | Executive Dashboard UI + enhanced analytics tabs | Yes |
| **16.7.8** | Permission seeder, policy, sidebar, routes | Yes |
| **16.7.9** | Performance audit (`EXPLAIN`), optional index follow-up | No |

**Phase 16.7.7 mencakup:** `InventoryExecutiveDashboardController`, views, link ke `inventory/analytics` anchor sections.

**Phase 16.7.9 mencakup:** regression Sprint 15.5/16.6, `php artisan test`, `vendor/bin/pint`, `php artisan route:list`, `npm run build`.

---

## File Manifest (implementation)

| Layer | File | Action |
|---|---|---|
| DTO | `DTOs/InventoryExecutiveSnapshot.php` | Create |
| Service | `Services/InventoryExecutiveDashboardService.php` | Create |
| Service | `Services/InventoryAnalyticsService.php` | Extend (10 KPI methods) |
| Interface | `Interfaces/InventoryAnalyticsRepositoryInterface.php` | Create |
| Repository | `Repositories/InventoryAnalyticsRepository.php` | Create |
| Interface | `Interfaces/InventoryMovementRepositoryInterface.php` | Extend |
| Repository | `Repositories/InventoryMovementRepository.php` | Extend |
| Interface | `Interfaces/PurchaseOrderRepositoryInterface.php` | Create/extend |
| Repository | `Repositories/PurchaseOrderRepository.php` | Create/extend |
| Interface | `Interfaces/GoodsReceiptRepositoryInterface.php` | Create/extend |
| Repository | `Repositories/GoodsReceiptRepository.php` | Create/extend |
| Request | `Requests/InventoryExecutiveDashboardFilterRequest.php` | Create |
| Request | `Requests/InventoryAnalyticsFilterRequest.php` | Extend tabs |
| Controller | `Controllers/InventoryExecutiveDashboardController.php` | Create (thin) |
| Controller | `Controllers/InventoryAnalyticsController.php` | Extend (thin) |
| Policy | `Policies/InventoryMovementPolicy.php` or dedicated | Extend |
| Views | `resources/views/inventory/executive-dashboard.blade.php` | Create |
| Views | `resources/views/inventory/analytics/index.blade.php` | Extend |
| Components | `resources/views/components/inventory/executive-*.blade.php` | Create |
| Routes | `routes/web.php` | Add |
| Sidebar | `resources/views/layouts/sidebar.blade.php` | Add link |
| Seeder | `PermissionSeeder.php`, `RoleSeeder.php` | Add permissions |
| Grouping | `PermissionGroupingService.php` | Add labels |
| Provider | `RepositoryServiceProvider.php` | Bind analytics repository interface |
| Tests | `tests/Feature/Inventory/InventoryExecutiveDashboardTest.php` | Create |
| Tests | `tests/Feature/Inventory/InventoryAnalyticsServiceTest.php` | Extend |
| Tests | `tests/Feature/Inventory/InventoryExecutiveDashboardServiceTest.php` | Create |
| Tests | `tests/Feature/Inventory/InventoryAnalyticsControllerTest.php` | Update |

**No migration required** untuk MVP.

**Pre-Step 2 note:** Belum ada file di atas yang dibuat — hanya desain dokumen. Authority implementasi: **KPI Lock Matrix** + **Implementation Sequence**.

---

## Branch Isolation Rules

| Rule | Enforcement |
|---|---|
| Default scope | `BranchContext::requireId()` untuk non-cross-branch users |
| Cross-branch | Hanya via `view_inventory_cross_branch`; query `whereIn(branch_id, $allowedBranchIds)` |
| Owner rollup | `GROUP BY branch_id` — tidak pernah merge movement antar cabang tanpa label cabang |
| Location/category filter | Validasi `findInBranch()` |
| Supplier scope | `inv_suppliers.branch_id = B` |
| PO/GR scope | `trx_purchase_orders.branch_id = B` |
| Request tampering | `branch_id` invalid → 403 atau ignore + fallback active branch (fail closed untuk Manager) |
| Tests wajib | User cabang A tidak melihat nilai cabang B di executive dashboard |

---

## Acceptance Criteria

1. Executive dashboard dapat diakses via `/inventory/executive-dashboard` dengan permission gate.
2. Semua 10 domain analitik tampil pada executive dashboard atau link drill-down jelas ke analytics.
3. KPI, valuation, fast/slow/dead, aging konsisten dengan definisi dokumen ini dan Sprint 15.5.
4. Supplier performance dan purchase trend menggunakan data procurement Sprint 16 — bukan estimasi.
5. Consumption trend menampilkan minimal 6 bulan outbound qty + value.
6. Reorder recommendation menampilkan suggested qty dan link prefill PR.
7. Owner/Super Admin dapat melihat branch comparison; Manager Cabang hanya cabang sendiri.
8. Tidak ada mutable stock column ditambahkan.
9. Tests: happy path, auth denial, branch isolation, cross-branch allowed/denied.
10. Quality gates: `php artisan test`, `vendor/bin/pint`, `php artisan route:list`, `npm run build`.

---

## Quality Gates

```bash
php artisan test --filter=InventoryExecutive
php artisan test --filter=InventoryAnalytics
php artisan test
vendor/bin/pint
php artisan route:list
npm run build
```

---

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Query berat pada ledger besar | Optional index `(branch_id, movement_date, movement_type)`; limit period default; pagination pada tabel |
| Historical inventory value trend tidak akurat | Label jelas: tren konsumsi ≠ tren nilai on-hand; snapshot table deferred |
| Supplier on-time metric bergantung `expected_delivery_date` nullable | **LOCKED:** exclude dari on-time denominator; tampilkan Coverage % terpisah |
| Inventory value bukan accounting-grade | **LOCKED:** disclaimer Operational Inventory Value; tidak pakai FIFO/LIFO/WAC |
| Open PO undercount tanpa status `sent` | **LOCKED:** include `approved`, `sent`, `partially_received` |
| Inventory accuracy palsu tanpa opname | **LOCKED:** return `null` + UI *Belum ada stock opname selesai.* |
| KPI dari activity log | **LOCKED:** forbidden — ledger + procurement + opname only |
| Cross-branch leakage via executive permission | **LOCKED:** explicit `view_inventory_cross_branch` + role matrix |
| Product aging tanpa batch adalah approximate | Reuse disclaimer Sprint 15.5 |
| Role Owner/Manager Cabang belum resmi di RoleSeeder | Map ke Admin Lab + dokumentasi; role resmi = follow-up PROJECT_RULES |
| Cross-branch leakage | Dedicated permission + feature tests per branch pair |
| Activity log volume | Executive dashboard tidak query log table untuk agregat — hanya drill-down optional |

---

## Sprint Consistency Check

| Sprint | Check | Status |
|---|---|---|
| Sprint 10 | Branch via BranchContext | ✅ Rencana comply |
| Sprint 11 | Branch-scoped queries | ✅ `where('branch_id', $branchId)` semua agregat |
| Sprint 12 | Ledger-derived stock | ✅ Tidak ada mutable stock |
| Sprint 15.5 | Existing analytics preserved | ✅ Extend, bukan replace |
| Sprint 16.3 | PURCHASE movements untuk received value | ✅ Digunakan supplier metrics |
| Sprint 16.5 | Permission hardening | ✅ Policy + permission baru |
| Sprint 16.6 | Activity log tidak diubah | ✅ Tidak menyentuh logging |

---

## Documentation Updates (post-implementation)

- `docs/sprint_history.md` — tambah Sprint 16.7 entry
- `docs/sprint_16_7_inventory_analytics_completion.md` — setelah Step 6
- `.cursor/memory/sprint-history.md` — ringkasan keputusan

---

## References

- `docs/sprint_15_5_inventory_analytics_design.md` — definisi fast/slow/dead/aging/turnover
- `docs/sprint_16_6_inventory_audit_trail_completion.md` — baseline audit trail
- `docs/ui_owner_dashboard_design.md` — pola executive KPI (inventory slice only di sprint ini)
- `docs/inventory_rules.md` — ledger constitution
- `docs/ui_design_system.md` — visual standard
- `app/Modules/Inventory/Services/InventoryAnalyticsService.php` — implementasi existing

---

## Pre-Step 1 Completion Checklist

| Item | Status |
|---|---|
| Architecture Decisions documented | ✅ |
| Controller Design Rule documented | ✅ |
| Executive Snapshot DTO documented | ✅ |
| Analytics Data Source Rule documented | ✅ |
| Cross Branch Rules documented | ✅ |
| Future Optimization Layer documented | ✅ |
| KPI Governance table documented | ✅ |
| Source code (migration/service/controller/repository/UI/test) | ❌ Not created — design only |
| Ready for Step 1 Audit Data Source | ✅ |

---

## Pre-Step 2 Completion Checklist (Analytics Design Lock)

| Item | Status |
|---|---|
| Inventory Valuation Governance | ✅ LOCKED |
| Open Purchase Order Definition | ✅ LOCKED |
| Consumption Definition | ✅ LOCKED |
| Inventory Accuracy Governance | ✅ LOCKED |
| Supplier Performance Governance (+ coverage %) | ✅ LOCKED |
| Activity Log Analytics Rule | ✅ LOCKED |
| Cross Branch Analytics Governance (+ role matrix) | ✅ LOCKED |
| KPI Lock Matrix (16 KPI, semua LOCKED) | ✅ LOCKED |
| Implementation Sequence (16.7.1–16.7.9) | ✅ LOCKED |
| Source code (migration/service/controller/repository/UI/test) | ❌ Not created — design only |
| Ready for Step 2 Implementation | ✅ |

---

## Pre-Step 2 Completion Report

### 1. Section yang ditambahkan

| Section | Lokasi dokumen |
|---|---|
| Design Lock — Pre-Step 2 (Analytics Governance) | Section utama baru |
| Inventory Valuation Governance | Sub-section Pre-Step 2 |
| Open Purchase Order Definition | Sub-section Pre-Step 2 |
| Consumption Definition | Sub-section Pre-Step 2 |
| Inventory Accuracy Governance | Sub-section Pre-Step 2 |
| Supplier Performance Governance | Sub-section Pre-Step 2 |
| Activity Log Analytics Rule | Sub-section Pre-Step 2 |
| Cross Branch Analytics Governance | Sub-section Pre-Step 2 |
| KPI Lock Matrix | Sub-section Pre-Step 2 |
| Implementation Sequence | Sub-section Pre-Step 2 |
| Pre-Step 2 Completion Checklist | Akhir dokumen |
| Pre-Step 2 Completion Report | Akhir dokumen |

### 2. KPI yang dikunci (16 KPI — semua LOCKED)

Inventory Value · Active SKU · Low Stock · Dead Stock · Open PR · Open PO · Pending GR · In Transit Transfer · Inventory Accuracy · Fast Moving · Slow Moving · Stock Aging · Purchase Trend · Consumption Trend · Supplier Performance · Reorder Recommendation

### 3. Formula final (ringkas)

| Domain | Formula |
|---|---|
| Inventory Value | `SUM(S(P) × inv_products.average_cost)` |
| Consumption | `SUM(quantity_out)` — semua outbound termasuk TRANSFER_OUT, ADJUSTMENT_OUT |
| Open PO | status ∈ `{approved, sent, partially_received}` |
| Inventory Accuracy | `1 - SUM(ABS(variance_qty)) / SUM(system_qty)`; `null` jika tidak ada opname COMPLETED |
| Supplier On-Time | hanya PO dengan `expected_delivery_date`; exclude PO tanpa tanggal |
| Supplier Coverage | `PO dengan expected_delivery_date / Total PO × 100` |

### 4. Cross branch rules

- Default: `BranchContext` — cabang aktif saja
- Cross branch: explicit opt-in `view_inventory_cross_branch`
- Owner + Super Admin: Yes; Admin Lab, Manager Cabang, Technician, QC: No

### 5. Activity log rules

- `inv_inventory_activity_logs` **forbidden** sebagai sumber KPI
- Hanya: audit, drill-down, traceability, investigasi
- Analytics wajib: ledger + procurement + opname

### 6. Implementation order

16.7.1 Interface → 16.7.2 Repository Methods → 16.7.3 Analytics Service → 16.7.4 DTO → 16.7.5 Dashboard Service → 16.7.6 Tests → 16.7.7 UI → 16.7.8 Permission & Sidebar → 16.7.9 Performance Audit

### 7. Risiko yang berhasil dieliminasi (via design lock)

| Risiko | Mitigasi terkunci |
|---|---|
| Accounting valuation salah interpretasi | Operational Inventory Value disclaimer; no FIFO/LIFO/WAC |
| Open PO undercount | Status `sent` termasuk open |
| Consumption definisi ambigu | Seluruh `quantity_out` — konsisten Sprint 15.5 |
| Inventory accuracy palsu | `null` + UI message jika belum ada opname COMPLETED |
| On-time metric misleading | Coverage % terpisah; PO tanpa tanggal di-exclude |
| KPI dari activity log | Explicitly forbidden |
| Cross-branch leakage | Role matrix + explicit permission opt-in |
| Implementation order chaos | 9-phase sequence locked |

### 8. Konfirmasi source code

**Tidak ada** migration, repository, service, controller, UI, atau test yang dibuat pada Pre-Step 2. Hanya `docs/sprint_16_7_inventory_analytics.md` yang diupdate.

### 9. Konfirmasi kesiapan Step 2

✅ **Siap masuk Sprint 16.7 Step 2 (Implementation Phase 16.7.1)** — semua keputusan audit dikunci; KPI Lock Matrix dan Implementation Sequence menjadi authority implementasi.

---

*Step 0 deliverable: design authority untuk Sprint 16.7.*  
*Pre-Step 1 deliverable: architecture hardening — Step 1 Audit PASS.*  
*Pre-Step 2 deliverable: analytics design lock — Step 2 Implementation COMPLETE.*  
*Phase 16.7.10 deliverable: release, documentation, tagging — Sprint 16.7 COMPLETE.*

---

## Implementation Summary

Sprint 16.7 mengimplementasikan seluruh KPI Lock Matrix (16 KPI) melalui analytics repository
interface, extended analytics service, executive snapshot DTO, dashboard compose service, executive
dashboard UI, permission hardening, dan performance audit — tanpa migration, tanpa mutable stock
column, tanpa background job.

**Arsitektur final:**

```text
InventoryExecutiveDashboardController (thin)
  → InventoryExecutiveDashboardService (compose only)
    → InventoryAnalyticsService (KPI calculation)
      → InventoryAnalyticsRepositoryInterface (17 methods)
    → InventoryExecutiveSnapshot DTO
```

Semua agregat on-read dari ledger (`trx_inventory_movements`), procurement
(`trx_purchase_requests`, `trx_purchase_orders`, `trx_goods_receipts`), opname
(`trx_stock_opnames`), dan master data (`inv_products`, `inv_suppliers`).

---

## Delivered Components

| Layer | File | Status |
|---|---|---|
| Interface | `Interfaces/InventoryAnalyticsRepositoryInterface.php` | ✅ |
| Repository | `Repositories/InventoryAnalyticsRepository.php` | ✅ |
| Service | `Services/InventoryAnalyticsService.php` (extended) | ✅ |
| Service | `Services/InventoryExecutiveDashboardService.php` | ✅ |
| DTO | `DTOs/InventoryExecutiveSnapshot.php` | ✅ |
| Controller | `Controllers/InventoryExecutiveDashboardController.php` | ✅ |
| Policy | `Policies/InventoryMovementPolicy.php` + `ChecksInventoryAccess` | ✅ |
| View | `resources/views/inventory/executive-dashboard.blade.php` | ✅ |
| Sidebar | `resources/views/layouts/sidebar.blade.php` | ✅ |
| Routes | `routes/web.php` — `inventory.executive-dashboard` | ✅ |
| Seeder | `PermissionSeeder`, `RoleSeeder` — `view_inventory_executive_dashboard` | ✅ |
| Grouping | `PermissionGroupingService` | ✅ |
| Provider | `RepositoryServiceProvider` — interface binding | ✅ |
| Tests | 6 new/updated inventory executive + analytics test files | ✅ |
| Docs | `sprint_16_7_performance_audit.md`, completion doc | ✅ |

---

## Deferred Components

| Component | Alasan | Target |
|---|---|---|
| `view_inventory_cross_branch` permission + seeder | Cross-branch rollup deferred | Sprint 16.8 |
| Branch comparison section (Section 7) | Requires cross-branch permission | Sprint 16.8 |
| Enhanced analytics tabs (supplier, purchase, consumption, reorder) | Executive dashboard MVP first | Sprint 16.8 |
| `InventoryExecutiveDashboardFilterRequest` | Default period/filter via service constants | Sprint 16.8 |
| `inventory_daily_summary` / `inventory_monthly_summary` tables | On-read acceptable at pilot scale | Sprint 16.8 |
| Composite indexes (see performance audit §6) | No migration in 16.7 | Sprint 16.8 |
| CSV/PDF export | Gate `manage_inventory_analytics` disiapkan | Sprint 16.8+ |
| Owner/Manager Cabang role resmi | Map ke Admin Lab sementara | PROJECT_RULES follow-up |
| Redis read-through cache for executive snapshot | Not needed at pilot scale | Sprint 16.9+ |

---

## Performance Notes

**Audit verdict (16.7.9):** Safe for release at current data volumes.

| Metric | Value |
|---|---|
| Branch isolation | PASS |
| Dashboard SQL count (10 suppliers) | ~100–120 statements |
| Target SLA | <3s per branch at pilot scale |
| Monitor threshold | >500K movement rows per branch |
| 16.8 readiness score | 82/100 |

**Optimizations applied in 16.7.9:**

- Batched supplier on-time GR lookup (N+1 fix)

**Known repetition (deferred to 16.8):**

- `stockSubquery` rebuilt 4× in KPI strip
- `productsWithDerivedStock` called 5× across dashboard sections
- `getSupplierPerformance` O(suppliers × queries) — dominant cost driver

Detail: `docs/sprint_16_7_performance_audit.md`

---

## Future Constraints

Future sprints **must preserve**:

1. **Ledger-derived analytics** — no mutable stock columns, no analytics cache tables (until 16.8 swap)
2. **Interface contract** — `InventoryAnalyticsRepositoryInterface` return shapes stable for views/DTO
3. **Service decomposition** — analytics calculation vs dashboard composition remain separate
4. **KPI Lock Matrix formulas** — governance definitions are binding
5. **Activity log forbidden as KPI source** — `inv_inventory_activity_logs` for audit/drill-down only
6. **BranchContext default** — single branch unless explicit cross-branch permission (16.8)
7. **Operational Inventory Value disclaimer** — not accounting valuation
8. **Compose-only dashboard service** — no direct DB queries in `InventoryExecutiveDashboardService`

**Sprint 16.8 swap path:**

```text
RepositoryServiceProvider:
  InventoryAnalyticsRepositoryInterface
    → InventorySummaryAnalyticsRepository (new)
      reads: inventory_daily_summary, inventory_monthly_summary
```

Controller, DTO, dashboard service, and Blade contracts remain unchanged.

---

## Release Checklist

| Item | Status |
|---|---|
| Analytics Repository | **PASS** |
| Analytics Service | **PASS** |
| DTO (`InventoryExecutiveSnapshot`) | **PASS** |
| Dashboard Service | **PASS** |
| Executive Dashboard UI | **PASS** |
| Permission (`view_inventory_executive_dashboard`) | **PASS** |
| Sidebar (Dasbor Eksekutif link) | **PASS** |
| Branch Isolation | **PASS** |
| Performance Audit | **PASS** |
| Tests | **PASS** — 1189 tests, 4124 assertions |
| Build | **PASS** — `npm run build` |

---

## Production Deployment

### Deploy commands

```bash
git fetch
git pull

composer install --no-dev --optimize-autoloader

php artisan optimize:clear
php artisan migrate --force

php artisan db:seed --class=PermissionSeeder --force

php artisan config:cache
php artisan route:cache
php artisan view:cache

npm install
npm run build
```

### Post-deploy verification

- [ ] Executive Dashboard accessible at `/inventory/executive-dashboard`
- [ ] KPI cards tampil (nilai persediaan, SKU, open PR/PO, dll.)
- [ ] Sidebar menu **Dasbor Eksekutif** tampil untuk user dengan `view_inventory_executive_dashboard`
- [ ] Permission bekerja — user tanpa permission mendapat 403
- [ ] Branch isolation tetap aman — tidak ada data cabang lain yang bocor
