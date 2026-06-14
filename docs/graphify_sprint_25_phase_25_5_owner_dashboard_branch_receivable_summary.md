# Graphify Companion — Sprint 25 Phase 25.5 Owner Dashboard Branch Receivable Summary

**Graph refreshed:** 2026-06-14 via `graphify update .`
**Graph stats:** 12036 nodes, 17054 edges, 1724 communities (AST-only, no LLM).

## Feature node map

```
HomeDashboardController::index()
        │  resolves selected branch id, active branches, metrics
        ├──> OwnerDashboardRmeLabKpiService::resolveSelectedBranchId()
        ├──> OwnerDashboardRmeLabKpiService::activeBranches()
        ├──> OwnerDashboardRmeLabKpiService::metrics()                 (existing)
        ├──> OwnerDashboardRmeLabKpiService::branchReceivableSummary() (NEW 25.5)
        └──> OwnerDashboardRmeLabDrilldownService::linksFor()          (gates rme_receivables)
                                │
                                ▼
        resources/views/dashboard.blade.php
          └── section: "Ringkasan Piutang per Cabang" (NEW 25.5)
                ├── columns: Cabang | Sisa Piutang | Invoice Cicilan |
                │            Invoice Belum Dibayar | Tindak Lanjut | Aksi
                └── "Lihat Piutang" → route('rme.cashier.receivables', branch_id)
                      gated by $ownerRmeLabDrilldowns['rme_receivables'] (manage_rme_billing)
```

## `branchReceivableSummary()` internal reuse

- `resolveBranchIds()` — selected-branch-or-all-active resolution (shared with `metrics()` / `branchSummary()`).
- `rmeReceivableQuery()` — UNPAID + PARTIAL scope helper (shared with receivable KPIs).
- `RmeInvoice::payments()` via `withSum('payments','amount')` — N+1-safe remaining balance.
- `RmeInvoice::latestFollowUp()` / `followUps()` — follow-up posture counts.

## Key relationships

- `OwnerDashboardRmeLabKpiService` ──reads──> `Branch`, `RmeInvoice`, `RmePayment`, `RmeReceivableFollowUp`.
- `HomeDashboardController` ──depends on──> `OwnerDashboardRmeLabKpiService`, `OwnerDashboardRmeLabDrilldownService`.
- View action ──route──> `rme.cashier.receivables` (`RmeInvoiceController::receivables`, accepts `branch_id`).

## Suggested graphify queries

- `graphify query "owner dashboard branch receivable summary"`
- `graphify explain "branchReceivableSummary"`
- `graphify path "HomeDashboardController" "RmeInvoice"`

## Constraints reflected in graph

- No new edges into payment / invoice mutation services — read-only aggregate path only.
- No new migration / model nodes added.
