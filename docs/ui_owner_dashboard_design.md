# Owner Dashboard UI Design

## Context

Application: Asia Dental Lab Management System (ADLMS)

Current state:
- Laravel modular monolith
- Blade, Tailwind CSS, Alpine.js
- PostgreSQL
- Modules exist for Lab Order, Production, QC, Delivery, Invoice/Payment, Reporting, Branch, and Inventory
- Pilot/live testing phase

Existing patterns reviewed:
- Sidebar navigation uses permission-aware sections.
- Reporting dashboard already provides read-only operational summaries.
- Inventory dashboard already provides inventory value, low stock, out-of-stock, stock by location, and recent movements.
- Branch foundation exists through `mst_branches`, `branch_id`, and `BranchContext`, but global multi-branch owner analytics should not depend only on active branch context.

## Design Objective

The Owner Dashboard is an executive-first dashboard for business owners and senior operators. It should answer the most important business questions in one screen:

1. Revenue this month
2. Active orders
3. Overdue orders
4. Outstanding invoices
5. Inventory value
6. Low stock alerts
7. Branch performance
8. QC failure rate
9. Delivery performance
10. Production bottlenecks

The dashboard is not a replacement for detailed reports. It is a decision surface: show what needs attention, what is improving or worsening, and where the owner should click next.

## Design Principles

- Executive-first: top section prioritizes revenue, cash risk, workload, and blocking alerts.
- Decision-oriented: every metric should include status, trend, and next action.
- Minimal clicks: owner can move from KPI to filtered report/work queue in one click.
- Mobile friendly: KPI stack, alert center, and branch cards remain readable on small screens.
- Operational visibility: show workflow health across order, production, QC, delivery, finance, and inventory.
- Multi-branch visibility: default to all branches for owner roles, with branch filter and per-branch comparisons.

## User and Permission Assumptions

Primary users:
- Super Admin
- Owner
- Admin Lab or management user with reporting access

Recommended permission model:
- Initial implementation can reuse existing `manage_report` for access.
- If a separate owner surface is desired later, add `view_owner_dashboard`.
- Do not add a separate permission system. Continue using Spatie permissions and policy/gate conventions already used in Reporting and Inventory.

Branch behavior:
- Owner dashboard should support `All branches` by default.
- Branch filter options: `All branches`, individual branch.
- Per-branch comparisons should query by `branch_id` directly, not rely only on `BranchContext::requireId()`.
- Existing active branch can still be used for non-owner users if the dashboard is later shared with branch managers.

## Data Source Map

| Metric | Primary source | Notes |
| --- | --- | --- |
| Revenue this month | `trx_invoices`, `trx_payments` | Display invoice revenue and optionally collected cash as a secondary value. Exclude VOID invoices. |
| Active orders | `trx_lab_orders` | Count non-final statuses: RECEIVED, ASSIGNED, IN_PRODUCTION, QC_PENDING, QC_PASSED, READY_FOR_DELIVERY, IN_DELIVERY, ON_HOLD, REMAKE. |
| Overdue orders | `trx_lab_orders` | Due date before today and status not COMPLETED, DELIVERED, CANCELLED. |
| Outstanding invoices | `trx_invoices` | Sum `outstanding_amount` where invoice is not VOID and outstanding > 0. |
| Inventory value | Inventory ledger plus `inv_products.average_cost` | Existing `InventoryStockService::getInventoryValue()` covers active branch; owner view needs branch-aware/global repository query. |
| Low stock alerts | `trx_inventory_movements`, `inv_products.minimum_stock` | Existing low stock logic derives stock from ledger. |
| Branch performance | Branch plus orders, invoices, delivery, QC | Requires branch grouped aggregates. |
| QC failure rate | `trx_lab_quality_controls` | FAIL or REJECTED results divided by completed QC reviews. Confirm exact result enum during implementation. |
| Delivery performance | `trx_lab_deliveries` | Completed/delivered count, POD completion, aging in delivery. |
| Production bottlenecks | `trx_lab_orders`, assignments, steps, work logs | Use orders stuck in IN_PRODUCTION, ON_HOLD, QC_PENDING, or assignment aging. |

## Information Architecture

Recommended route:
- `GET /owner/dashboard`
- Route name: `owner.dashboard`
- Access: `manage_report` initially, or `view_owner_dashboard` if added later

Navigation placement:
- Sidebar section: `Management`
- Item: `Owner Dashboard`
- Visible to Super Admin and management/reporting roles only

Page-level filters:
- Date range: default current month
- Branch: default all branches
- Comparison: previous period, default previous month
- Optional: clinic filter for owners who manage clinic-heavy operations

Filter behavior:
- Filters sit in a compact sticky-ish top band on desktop.
- On mobile, filters collapse into a single `Filters` disclosure.
- Applied filters must be reflected in every KPI, chart, alert, and branch card.

## Dashboard Hierarchy

### 1. Executive KPI Row

Purpose: answer financial and operational health in under five seconds.

Cards:
- Revenue this month
- Active orders
- Overdue orders
- Outstanding invoices
- Inventory value
- Low stock alerts

Each card should include:
- Label
- Primary value
- Secondary context
- Trend vs previous period
- Severity state
- Click target

Example:
- Revenue this month: `Rp 128.4M`
- Secondary: `+12.5% vs last month`
- Action: click to filtered revenue report

### 2. Alert Center

Purpose: put the owner where action is needed.

Alert groups:
- Overdue orders
- High outstanding invoices
- Low stock and out-of-stock materials
- QC failure spike
- Delivery delays
- Production bottlenecks

Alert severity:
- Critical: blocks delivery, revenue, or production
- Warning: needs monitoring or near-threshold
- Info: unusual but not urgent

Each alert row:
- Title
- Affected branch
- Count or amount
- Age or trend
- One action link

### 3. Branch Comparison

Purpose: show which branch is healthy, underperforming, or overloaded.

Branch card metrics:
- Revenue
- Active orders
- Overdue orders
- Outstanding invoices
- QC failure rate
- Delivery completion rate
- Low stock count

Default sort:
- Highest risk score first, not alphabetical.

Risk score recommendation:
- Overdue orders weight: high
- Outstanding invoices weight: high
- QC failure rate weight: medium
- Delivery delay weight: medium
- Low stock count weight: medium
- Active order load weight: low unless above threshold

### 4. Operational Pipeline

Purpose: reveal where orders are stuck.

Pipeline stages:
- Received
- Assigned
- In Production
- QC Pending
- QC Passed
- Ready for Delivery
- In Delivery
- Delivered/Completed
- Remake

Visualization:
- Horizontal segmented pipeline on desktop
- Vertical stacked stage cards on mobile

Each stage:
- Count
- Oldest item age
- Percentage of total active orders
- Bottleneck indicator when count or age exceeds threshold

### 5. Trend and Chart Section

Purpose: support quick pattern recognition without turning the dashboard into a reporting page.

Recommended charts:
- Revenue trend by day/week for current month
- Orders by status
- Branch revenue vs overdue orders
- QC pass/fail trend
- Delivery status distribution
- Inventory value by branch/location

Charts should stay compact and readable. Use tables when a chart would be decorative rather than actionable.

### 6. Activity Timeline

Purpose: give the owner a live operational pulse.

Timeline events:
- New order received
- Order moved to production
- QC failed/remake requested
- Delivery completed
- Payment received
- Stock went below minimum

Each event:
- Time
- Entity
- Branch
- Status/action
- Link to detail

## KPI Card Design

### Revenue this month

Primary value:
- Total non-VOID invoice amount for current month.

Secondary values:
- Payments collected this month
- Trend vs previous month

State rules:
- Positive trend: green accent
- Flat: gray
- Negative trend: amber or red depending threshold

Click target:
- `reports.revenue` with date range and branch filter

### Active orders

Primary value:
- Count of active non-final lab orders.

Secondary values:
- Oldest active order age
- Urgent/SUPER_URGENT count

Click target:
- `lab-orders.index` filtered to active statuses

### Overdue orders

Primary value:
- Count of orders past due date and not final.

Secondary values:
- Oldest overdue age
- Highest risk branch

State rules:
- 0: healthy
- 1-5: warning
- 6+: critical, tune after pilot data

Click target:
- `lab-orders.index` filtered by overdue

### Outstanding invoices

Primary value:
- Sum of outstanding invoice amount.

Secondary values:
- Overdue invoice count
- Largest outstanding branch or clinic

Click target:
- `reports.outstanding`

### Inventory value

Primary value:
- Ledger-derived stock value.

Secondary values:
- Top branch/location by inventory value
- Change vs previous month if historical snapshot exists later

Click target:
- `inventory.stock.index` or `inventory.dashboard`

### Low stock alerts

Primary value:
- Number of products at or below minimum stock.

Secondary values:
- Out-of-stock count
- Highest-risk branch/location

Click target:
- `inventory.dashboard` or filtered stock view

## Alert Center Design

Layout:
- Desktop: right column beside pipeline/branch performance, 30-35% width
- Mobile: appears immediately after KPI cards

Alert order:
1. Critical revenue or delivery blockers
2. Overdue orders
3. Low/out-of-stock inventory
4. QC failure spikes
5. Production aging

Alert copy examples:
- `8 overdue orders need review`
- `Rp 24.5M outstanding invoices past due`
- `Zirconia Block is out of stock at Gudang Utama`
- `QC failure rate rose to 18% this week`
- `12 orders have been in production for more than 3 days`

Empty state:
- Title: `No urgent alerts`
- Body: `Operations are within configured thresholds for the selected period.`
- Avoid celebratory language; keep it calm and operational.

## Branch Comparison Section

BranchPerformanceCard should show:
- Branch name
- Overall health status
- Revenue
- Active orders
- Overdue orders
- Outstanding invoices
- QC failure rate
- Delivery completion rate
- Low stock count

Visual hierarchy:
- Branch name and health badge first
- Two primary metrics: revenue and overdue orders
- Remaining metrics in compact two-column grid

Branch health states:
- Healthy: no critical issues, stable revenue
- Watch: 1-2 warnings
- Critical: overdue/order/delivery/QC/inventory issue above threshold

Interaction:
- Click card to apply branch filter to whole dashboard.
- Secondary links open detail reports.

## Pipeline Visualization

PipelineCard should support:
- Stage list
- Count per stage
- Aging per stage
- Bottleneck state
- Click-through route per stage

Recommended desktop layout:
- One horizontal card with 8 compact stage blocks.
- Blocks use neutral background, amber for warning, red for critical.

Recommended mobile layout:
- Vertical list of stage rows with count right-aligned.

Bottleneck rules:
- Stage count above configured threshold
- Oldest order age above SLA
- Stage is increasing vs previous period

SLA examples for pilot:
- RECEIVED: more than 1 day
- ASSIGNED: more than 1 day
- IN_PRODUCTION: more than 3 days
- QC_PENDING: more than 1 day
- READY_FOR_DELIVERY: more than 1 day
- IN_DELIVERY: more than 1 day
- REMAKE: any count should be highlighted

## Recommended Charts

Use charts sparingly. The owner dashboard should use compact charts and tables, not a dense analytics wall.

Recommended:
- Revenue trend line: daily revenue for current month
- Branch performance table/card grid: better than a decorative bar chart for branch comparisons
- Pipeline stage distribution: segmented bar
- QC trend: pass/fail stacked mini bars
- Delivery performance: status distribution or delivery completion percentage
- Inventory risk: low stock by branch/location table

Avoid:
- Pie charts for more than three statuses
- Decorative charts without click-through action
- Large hero charts that push alerts below the fold

## Mobile Layout

Mobile order:
1. Filter button/disclosure
2. Revenue this month
3. Alert center
4. Active orders and overdue orders
5. Outstanding invoices
6. Inventory value and low stock
7. Branch performance cards
8. Pipeline stages
9. Charts
10. Activity timeline

Mobile rules:
- KPI cards stack one per row for the top two metrics, then two-column where possible.
- Alert center must appear before charts.
- Branch cards become full-width.
- Pipeline becomes a vertical list.
- Tables should collapse to summary rows with a detail link.

## Empty States

Dashboard empty state:
- Title: `No activity for this period`
- Body: `Try a wider date range or switch branch filter.`
- Action: `Reset filters`

KPI empty state:
- Show `0` or `Rp 0.00`, not a blank card.
- Secondary text: `No records in selected period.`

Branch comparison empty state:
- Title: `No branch data`
- Body: `No operational records match the selected filters.`

Alert center empty state:
- Title: `No urgent alerts`
- Body: `No thresholds are currently exceeded.`

Chart empty state:
- Use a compact row inside the card rather than a large placeholder.

## Component Architecture

The following components should be generated as reusable Blade components or component-like partials. Naming follows the requested component names, but implementation should use the existing Blade/Tailwind pattern.

### OwnerKpiCard

Purpose:
- Render one executive metric with value, trend, severity, and action.

Props:
- `label`
- `value`
- `format`: `number`, `currency`, `percent`, `text`
- `secondary`
- `trendValue`
- `trendDirection`: `up`, `down`, `flat`
- `severity`: `neutral`, `success`, `warning`, `critical`
- `href`
- `icon` optional

States:
- Normal
- Warning
- Critical
- Empty
- Loading, optional if later using async refresh

Usage:
- Revenue this month
- Active orders
- Overdue orders
- Outstanding invoices
- Inventory value
- Low stock alerts

### AlertPanel

Purpose:
- Render prioritized operational alerts.

Props:
- `alerts`: collection of alert DTOs
- `title`
- `emptyTitle`
- `emptyBody`

Alert item fields:
- `severity`
- `title`
- `description`
- `branchName`
- `metric`
- `ageLabel`
- `href`

States:
- Empty
- Has critical alerts
- Has warnings only

Behavior:
- Sort critical first.
- Keep each alert to two lines on desktop.
- On mobile, show full-width stacked items.

### BranchPerformanceCard

Purpose:
- Show branch health and business performance in one compact card.

Props:
- `branchName`
- `health`
- `revenue`
- `activeOrders`
- `overdueOrders`
- `outstandingInvoices`
- `qcFailureRate`
- `deliveryCompletionRate`
- `lowStockCount`
- `href`

States:
- Healthy
- Watch
- Critical
- No data

Behavior:
- Click applies branch filter or opens branch-specific dashboard.
- Use critical badge only when action is needed.

### PipelineCard

Purpose:
- Visualize order count and aging by workflow stage.

Props:
- `stages`: ordered stage DTOs
- `title`
- `periodLabel`

Stage fields:
- `key`
- `label`
- `count`
- `percent`
- `oldestAge`
- `severity`
- `href`

States:
- Normal
- Bottleneck warning
- Bottleneck critical
- Empty

Behavior:
- Desktop horizontal segmented layout.
- Mobile vertical stage list.

### ActivityTimeline

Purpose:
- Show latest important business events.

Props:
- `events`
- `title`
- `emptyTitle`

Event fields:
- `occurredAt`
- `type`
- `title`
- `branchName`
- `actorName`
- `href`
- `severity` optional

States:
- Empty
- Normal
- Highlight critical event types

Recommended event types:
- `order_created`
- `production_started`
- `qc_failed`
- `remake_requested`
- `delivery_completed`
- `payment_received`
- `low_stock`

### DashboardSection

Purpose:
- Standardize dashboard section header, action link, and card surface.

Props:
- `title`
- `description`
- `actionLabel`
- `actionHref`
- `density`: `compact`, `normal`

Slots:
- Default content
- Optional header action

Usage:
- Alert center wrapper
- Branch performance section
- Pipeline section
- Chart section
- Activity timeline wrapper

## Suggested Page Composition

Desktop layout:

1. Header and filters
2. KPI card grid: 6 cards, 3 columns on medium, 6 on wide
3. Two-column decision row:
   - Left: PipelineCard
   - Right: AlertPanel
4. Branch performance grid
5. Charts: revenue trend, QC trend, delivery performance
6. ActivityTimeline

Mobile layout:

1. Header
2. Filter disclosure
3. Revenue OwnerKpiCard
4. AlertPanel
5. Remaining KPI cards
6. BranchPerformanceCard stack
7. PipelineCard vertical
8. Compact chart cards
9. ActivityTimeline

## Recommended Data DTOs

Controller should stay thin:
- Request validates filters.
- Service composes owner dashboard metrics.
- Repository performs grouped read-only queries.
- Blade renders DTOs.

Suggested classes:
- `OwnerDashboardController`
- `OwnerDashboardRequest`
- `OwnerDashboardService`
- `OwnerDashboardRepository`
- `OwnerDashboardPolicy` or named gate

Suggested DTO shape:

```php
[
    'filters' => [...],
    'kpis' => [...],
    'alerts' => [...],
    'branches' => [...],
    'pipeline' => [...],
    'charts' => [...],
    'activity' => [...],
]
```

Do not compute business rules inside Blade.

## Dashboard Actions and Click Targets

| UI item | Action |
| --- | --- |
| Revenue this month | Open revenue report filtered by selected period and branch |
| Active orders | Open lab orders filtered to active statuses |
| Overdue orders | Open lab orders filtered to overdue |
| Outstanding invoices | Open outstanding report |
| Inventory value | Open inventory dashboard or stock index |
| Low stock alerts | Open inventory dashboard or stock index filtered to low stock |
| QC failure rate | Open QC report filtered to failed/rejected |
| Delivery performance | Open delivery report |
| Production bottleneck stage | Open production board or filtered production report |
| Branch card | Apply branch filter to dashboard |

## Implementation Notes

Do:
- Keep route/controller/service/repository pattern.
- Add branch filter support to reporting queries.
- Use existing Tailwind visual language: white surfaces, light borders, compact tables, small badges.
- Prefer compact decision cards over large decorative charts.
- Use `manage_report` first unless product explicitly needs a new permission.

Avoid:
- Building this as a marketing-style page.
- Placing dashboard sections in nested decorative cards.
- Adding a new CSS framework.
- Creating business logic in Blade.
- Making owner metrics depend only on `BranchContext::requireId()` when all-branch view is selected.

## Risks and Dependencies

Risks:
- Current reporting repository does not consistently group by branch across all modules.
- BranchContext currently resolves an active branch and falls back to MAIN; owner all-branch view needs explicit branch-scoped reporting queries.
- QC result enum should be verified before final failure-rate formula.
- Production bottleneck logic needs SLA thresholds agreed during pilot.
- Inventory value uses product average cost, not weighted moving average.

Dependencies:
- Confirm owner role/permission naming.
- Confirm whether owner dashboard should be the default `/dashboard` for Super Admin/Owner.
- Confirm branch switching UX and whether branch managers can see only their active branch.
- Confirm KPI threshold values after pilot data review.

## Acceptance Criteria

Owner Dashboard design is ready when:
- Owner can understand revenue, workload, cash risk, inventory risk, QC health, delivery health, and bottlenecks above the fold on desktop.
- Mobile view shows alert center before charts.
- Every critical metric has a click-through action.
- Multi-branch view supports branch comparison and branch filtering.
- Components are reusable across future dashboards.
- Implementation can follow Controller -> Request -> Service -> Repository -> View without business logic in Blade.
