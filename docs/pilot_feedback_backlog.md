Foundation-first sprint lock is active. Non-foundation feedback is POST-FOUNDATION
BACKLOG and must not execute before FOUNDATION GO. This item is intentionally
deferred by Foundation-First Sprint Lock unless its row is explicitly classified
as FOUNDATION, HOTFIX, SECURITY, DEPLOYMENT, OPERATIONS, or FOUNDATION-DOCS.

ID | Date | Source | Role | Branch | Module | Page/URL | Feedback | Type | Priority | Status | Target Phase | Notes
| S25-FB-006 | 2026-06-14 | Kasir | Kasir | Cabang Landak | Piutang RME | `/rme/cashier/receivables` | Filter status PARTIAL kadang tidak menampilkan data sesuai cabang | DATA | P2 | TRIAGED | Sprint 25.3 | VPS data shows the only PARTIAL invoice is in Cabang Antang branch_id=3, while Cabang Landak is branch_id=2; no code fix required |
| S25-FB-005 | TBD | Owner | Owner | All | Reporting | `/dashboard` | Confirm dashboard KPIs needed for business review | REPORTING | P2 | POST-FOUNDATION BACKLOG | Not scheduled before Foundation GO | Owner review questions and dashboard enhancement candidates documented; waiting for owner approval before implementation. This item is intentionally deferred by Foundation-First Sprint Lock. |
| ODE-001 | 2026-06-14 | Owner | Owner | All | Reporting | `/dashboard` | Owner needs a per-branch RME receivable summary table (remaining balance, PARTIAL/UNPAID counts, follow-up status) | REPORTING | P2 | IMPLEMENTED | Sprint 25.5 | Implemented read-only "Ringkasan Piutang per Cabang" table on Owner Dashboard via `OwnerDashboardRmeLabKpiService::branchReceivableSummary()`; respects owner branch filter; "Lihat Piutang" branch-filtered action gated by `manage_rme_billing`. See `docs/sprint_25_phase_25_5_owner_dashboard_branch_receivable_summary.md` |
