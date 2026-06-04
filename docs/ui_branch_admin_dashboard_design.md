# Admin Cabang Dashboard UI Design

## Purpose

The Admin Cabang Dashboard is the daily operations command center for one active branch in Asia Dental Lab Management System. It is designed for the branch admin who needs to quickly answer:

- What arrived today?
- What needs assignment?
- What is stuck?
- What needs QC?
- What is ready for delivery?
- What inventory is low?
- Which invoices are unpaid?

The dashboard should be operational, branch-scoped, and action-oriented. It is not an owner-level multi-branch analytics dashboard. Owner and Super Admin users can still access broader reports, but the Admin Cabang view should default to the active branch resolved by `app/Modules/Branch/Services/BranchContext.php`.

## Existing Context Reviewed

The design aligns with the current ADLMS modular monolith architecture:

- Laravel modules under `app/Modules`
- Blade views using `x-settings-shell`
- Tailwind CSS utility classes
- Compact tables, KPI cards, filters, badges, and text action links
- Permission-aware sidebar sections
- Branch foundation through `BranchContext`
- Existing workflows for lab orders, production, quality control, delivery, invoice/payment, reporting, and inventory

Existing route families that should feed this dashboard:

| Area | Existing Route Examples | Dashboard Usage |
| --- | --- | --- |
| Lab orders | `lab-orders.index`, `lab-orders.create`, `lab-orders.show` | New arrivals, assignment queue, overdue orders |
| Production | `production.board`, `production.show` | Production workload, stuck orders, technician assignment |
| Quality Control | `quality-control.queue`, `quality-control.show` | QC pending, QC failed/remake visibility |
| Delivery | `deliveries.index`, `deliveries.show` | Ready for delivery, active deliveries, POD follow-up |
| Inventory | `inventory.dashboard`, `inventory.stock.index`, `inventory.products.show` | Low stock, out of stock, stock by location |
| Finance | `invoices.index`, `invoices.show`, reports outstanding/revenue routes | Unpaid and overdue invoices |
| Reports | `reports.dashboard` and report detail routes | Drill-down analytics when permitted |

Existing permission convention should be reused. Do not introduce a new permission system. If a dedicated dashboard permission is added later, it should be seeded using the existing permission seeder pattern and mapped to existing branch admin style roles. Until then, visibility can be composed from existing permissions such as `view_lab_orders`, `manage_lab_orders`, `view_production`, `manage_production`, `view_quality_control`, `view_delivery`, `view_inventory`, and invoice/report permissions.

## Design Positioning

Admin Cabang is equivalent to a branch operations controller. The dashboard should prioritize today's operational risk over historical analytics.

Primary behavior:

- Show only active branch data by default.
- Surface urgent exceptions before normal lists.
- Provide one-click movement into existing operational pages.
- Avoid duplicate business logic in Blade.
- Keep filters small and predictable.
- Prefer dense tables and queue cards over large marketing-style panels.

Recommended future route:

| Item | Recommendation |
| --- | --- |
| Route | `GET /branch/dashboard` |
| Name | `branch.dashboard` |
| Controller | `BranchAdminDashboardController` |
| Request | `BranchAdminDashboardRequest` |
| Service | `BranchAdminDashboardService` |
| Repository | `BranchAdminDashboardRepository` or composed existing repositories |
| View | `resources/views/dashboards/branch-admin.blade.php` |
| Branch scope | `BranchContext::requireId()` |

## Dashboard Hierarchy

### 1. Header Band

Purpose: orient the admin to today's branch operations.

Content:

- Active branch name
- Current date
- Optional period selector: Today, This week, This month
- Compact search field for order number, patient, clinic, or invoice number
- Primary action: Create Lab Order, only when user can `create_lab_orders` or `manage_lab_orders`

Design notes:

- Keep the header unframed inside `x-settings-shell`.
- Show the branch name prominently enough to prevent cross-branch confusion.
- If branch cannot be resolved, the controller/service should fail clearly before rendering.

### 2. Daily Summary Cards

Purpose: answer the most important daily questions in one scan.

Recommended cards:

| Card | Metric | Primary Drill-down |
| --- | --- | --- |
| Arrived Today | Count of lab orders created today in active branch | `lab-orders.index` filtered to today |
| Needs Assignment | Orders without active production assignment | `production.board` filtered to unassigned |
| Stuck / Overdue | Orders past due date or idle beyond threshold | `production.board` or `lab-orders.index` |
| Needs QC | Orders in QC pending/review state | `quality-control.queue` |
| Ready Delivery | QC passed orders not yet delivered | `deliveries.index` ready section |
| Low Stock | Low or out-of-stock inventory items | `inventory.stock.index` |
| Unpaid Invoices | Outstanding invoice amount and count | `invoices.index` filtered unpaid |

Card behavior:

- Each card should show a large number, short label, and small trend/context line.
- Use color only for operational severity: red for overdue/out of stock, amber for attention, green for healthy/ready.
- Cards should be permission-aware. Hide finance card if user cannot view invoices.

### 3. Alert Center

Purpose: place urgent exceptions above queue details.

Alert categories:

| Alert | Trigger | Action |
| --- | --- | --- |
| Overdue orders | Due date before today and not completed/delivered | Open order or production board |
| Idle production | No status/work log movement beyond threshold | Open production detail |
| QC waiting | QC pending longer than branch target | Open QC detail |
| Delivery waiting | QC passed but delivery not created | Open delivery queue |
| POD incomplete | Delivered status without completed POD | Open delivery detail |
| Low inventory | Current stock at or below minimum | Open inventory stock |
| Unpaid overdue invoice | Invoice due date passed with outstanding amount | Open invoice detail |

Display:

- Use a compact vertical list of high priority alerts.
- Each alert row should include severity, title, object identifier, age, and one action link.
- Cap visible alerts to 5 to 7 items with a "View all" link to the relevant module.

### 4. Work Queue Board

Purpose: provide a board-style overview of the branch's daily workflow.

Columns:

| Column | Contents | Suggested Sort |
| --- | --- | --- |
| Arrived Today | New orders created today | Newest first |
| Needs Assignment | Orders awaiting production assignment | Oldest first |
| In Production | Active production orders | Due date, priority |
| Stuck | Overdue, paused, or idle orders | Highest risk first |
| Needs QC | QC pending or in review | Oldest first |
| Ready Delivery | QC passed, delivery pending | Oldest first |
| Finance Follow-up | Unpaid or overdue invoices | Oldest due first |

Queue card fields:

- Order number or invoice number
- Clinic and doctor
- Patient name when available
- Priority badge
- Due date
- Current status
- Age in current state
- Assigned technician/courier when available
- Next action link

The board should be horizontally scrollable on desktop if needed and converted into tabs or stacked sections on mobile.

## Production Queue

Purpose: help the admin see workload and bottlenecks without opening every order.

Sections:

- Technician workload summary
- Unassigned orders
- Due today/tomorrow
- Paused or idle orders
- Remake-related production work

Recommended metrics:

| Metric | Meaning |
| --- | --- |
| Assigned | Count of active assignments per technician |
| In progress | Assignments actively being worked |
| Paused | Assignments paused or waiting |
| Due soon | Orders due today or tomorrow |
| Overdue | Orders past due date |

Actions:

- Assign or reassign technician through existing production detail flow.
- Open `production.board` with filters applied.
- Open `production.show` for order-level management.

Design notes:

- Do not duplicate the full production board.
- Show bottleneck indicators and lead the user to the existing production pages for full action handling.

## QC Queue

Purpose: make QC handoff visible and reduce missed reviews.

Sections:

- QC pending
- QC in review
- Rejected/remake follow-up
- Oldest QC waiting items

Recommended fields:

- Order number
- Clinic
- Patient
- Technician
- Submitted to QC time/date
- Priority
- Current lab order status
- Action: Review

Actions should route to:

- `quality-control.queue`
- `quality-control.show`

Business rules:

- Respect existing QC permissions.
- Show only orders from active branch.
- Failed QC should be visible as an operational risk, not hidden in history.

## Delivery Queue

Purpose: make outgoing work and POD completion visible.

Sections:

- Ready to prepare
- Active deliveries
- Delivered today
- POD incomplete
- Delivery delayed or overdue

Recommended fields:

- Delivery number when created
- Order number
- Clinic
- Patient
- Courier
- Delivery status
- Due date
- POD status
- Action: Create delivery or view detail

Actions should route to:

- `deliveries.index`
- `deliveries.show`

Design notes:

- The dashboard should not create a new delivery workflow.
- The "Ready Delivery" card should point users to the existing delivery queue where creation and courier assignment already exist.

## Inventory Alerts

Purpose: show operational stock risk by active branch and inventory location.

Sources:

- Inventory ledger-derived current stock
- Product minimum stock
- Inventory locations

Required alerts:

| Alert | Meaning |
| --- | --- |
| Low stock | Current stock is above zero but at or below minimum stock |
| Out of stock | Current stock equals zero or below |
| Location-specific risk | Low stock in a specific inventory location |
| Recent adjustment out | Recent manual out movement that may need review |

Fields:

- Product code
- Product name
- Location
- Current stock
- Minimum stock
- Unit
- Severity
- Action: View stock card

Actions:

- Open `inventory.stock.index`
- Open product stock card route
- Open receive stock form only when user can manage inventory

Branch/location rules:

- Show only locations in active branch.
- Do not aggregate another branch into Admin Cabang dashboard.
- Current stock must be derived from `trx_inventory_movements`.

## Finance Alerts

Purpose: highlight branch cash collection work without replacing invoice screens.

Sections:

- Outstanding invoice count and amount
- Overdue invoice count and amount
- Partially paid invoices
- High-value unpaid invoices

Fields:

- Invoice number
- Clinic
- Invoice date
- Due date
- Total amount
- Paid amount
- Outstanding amount
- Status
- Action: View invoice

Actions:

- Open `invoices.index` with status filters
- Open `invoices.show`
- Open outstanding report if user has report permission

Design notes:

- Finance visibility should respect existing invoice/payment/report permissions.
- If the branch admin lacks finance permissions, show no finance data and avoid empty restricted panels.

## Quick Actions

Purpose: reduce daily clicks for common branch admin operations.

Recommended actions:

| Action | Route | Permission Gate |
| --- | --- | --- |
| Create Lab Order | `lab-orders.create` | `create_lab_orders` or `manage_lab_orders` |
| View Lab Orders | `lab-orders.index` | `view_lab_orders` or `manage_lab_orders` |
| Open Production Board | `production.board` | `view_production` or `manage_production` |
| Open QC Queue | `quality-control.queue` | `view_quality_control` or `manage_quality_control` |
| Open Delivery Queue | `deliveries.index` | `view_delivery` or `manage_delivery` |
| View Inventory Stock | `inventory.stock.index` | `view_inventory` or `manage_inventory` |
| Receive Stock | existing receive stock flow from product detail | `manage_inventory` |
| View Invoices | `invoices.index` | `view_invoice` or `manage_invoice` |
| View Reports | `reports.dashboard` | existing report permissions |

Design notes:

- Use icon plus short text where the current UI icon system supports it.
- On mobile, use a compact two-column action grid or bottom quick-action panel.
- Do not expose actions the user cannot perform.

## Recommended Charts

Charts should remain compact and operational. Use simple visualizations that can be implemented with Blade/Tailwind first, then enhanced later if a chart library is adopted.

| Chart | Purpose | Recommended Form |
| --- | --- | --- |
| Daily workflow pipeline | Show counts by stage | Horizontal segmented bar |
| Production workload | Show technician capacity | Small table with progress bars |
| QC outcome | Show pass/reject/remake mix | Compact stacked bar or table |
| Delivery performance | Show ready, in delivery, delivered, POD incomplete | Status table |
| Inventory risk | Show low stock by location | Table grouped by location |
| Invoice exposure | Show unpaid and overdue amount | KPI plus ranked table |

Avoid decorative charts. Every chart should answer a branch admin decision question.

## Mobile Layout

Mobile priority order:

1. Header with branch name and date
2. Critical alerts
3. Daily summary cards in two columns
4. Work queue tabs
5. Quick actions
6. Inventory alerts
7. Finance alerts when permitted
8. Compact activity timeline

Mobile behavior:

- Convert the queue board into tabs: Today, Assign, Production, QC, Delivery, Inventory, Finance.
- Keep cards short with one primary action.
- Use sticky quick actions only if it does not cover table actions.
- Avoid wide tables on mobile; use stacked rows or card rows.

## Empty States

Empty states should be calm, specific, and operational.

| Section | Empty State |
| --- | --- |
| Arrived Today | "No new orders today." |
| Needs Assignment | "All new orders are assigned." |
| Stuck | "No stuck or overdue work." |
| QC Queue | "QC queue is clear." |
| Delivery Queue | "No orders waiting for delivery." |
| Inventory Alerts | "No low stock items in this branch." |
| Finance Alerts | "No unpaid invoices needing follow-up." |
| Quick Actions | Hide actions the user cannot access instead of showing disabled buttons. |

Use a subtle text-only empty state inside the existing white panel style.

## Component Architecture

The following reusable components are specified using the Skill-Creator principle: concise purpose, clear data contract, predictable states, and limited degrees of freedom. These are design contracts only, not code generation.

### DailySummaryCard

Purpose: show one operational KPI and route to a filtered work list.

Suggested Blade component:

`resources/views/components/branch-dashboard/daily-summary-card.blade.php`

Data contract:

| Prop | Type | Notes |
| --- | --- | --- |
| `label` | string | Short KPI label |
| `value` | int/string | Main number or formatted amount |
| `context` | string nullable | Example: "3 due today" |
| `severity` | string | `neutral`, `success`, `warning`, `danger` |
| `href` | string nullable | Drill-down route |
| `permission` | string/array nullable | Optional render gate |

States:

- Normal
- Warning
- Danger
- Empty/zero
- Restricted hidden state

### QueueCard

Purpose: represent one order, delivery, or invoice inside a queue column.

Suggested Blade component:

`resources/views/components/branch-dashboard/queue-card.blade.php`

Data contract:

| Prop | Type | Notes |
| --- | --- | --- |
| `identifier` | string | Order, delivery, or invoice number |
| `title` | string | Clinic, patient, or product context |
| `subtitle` | string nullable | Doctor, technician, courier, or location |
| `status` | string | Current workflow status |
| `priority` | string nullable | Order priority |
| `dueDate` | date/string nullable | Due date display |
| `ageLabel` | string nullable | Time in current state |
| `severity` | string | `neutral`, `warning`, `danger` |
| `href` | string | Detail route |
| `actionLabel` | string | Example: Manage, Review, View |

States:

- Normal
- Due soon
- Overdue
- Waiting assignment
- Restricted hidden state

### WorkloadWidget

Purpose: summarize team workload and bottlenecks.

Suggested Blade component:

`resources/views/components/branch-dashboard/workload-widget.blade.php`

Data contract:

| Prop | Type | Notes |
| --- | --- | --- |
| `title` | string | Example: Technician Workload |
| `rows` | array | Each row contains name, assigned, in_progress, paused, overdue |
| `href` | string nullable | Link to production board |
| `emptyMessage` | string | Text when no workload exists |

States:

- Balanced workload
- Overloaded technician
- No assignments
- Permission hidden

### QuickActionPanel

Purpose: collect permission-aware shortcuts for daily branch tasks.

Suggested Blade component:

`resources/views/components/branch-dashboard/quick-action-panel.blade.php`

Data contract:

| Prop | Type | Notes |
| --- | --- | --- |
| `actions` | array | label, href, permission, severity/style |
| `layout` | string | `grid`, `compact`, `mobile` |

States:

- Full access
- Partial access
- No visible actions: hide the panel

### InventoryAlertWidget

Purpose: show low/out stock by product and inventory location.

Suggested Blade component:

`resources/views/components/branch-dashboard/inventory-alert-widget.blade.php`

Data contract:

| Prop | Type | Notes |
| --- | --- | --- |
| `items` | array | product code/name, location, current, minimum, unit, severity, href |
| `summary` | array | low count, out count, inventory value |
| `canManageInventory` | bool | Controls receive/adjustment action visibility |

States:

- Healthy inventory
- Low stock
- Out of stock
- Location-specific warning
- Permission hidden

### FinanceAlertWidget

Purpose: show unpaid and overdue invoice exposure for the active branch.

Suggested Blade component:

`resources/views/components/branch-dashboard/finance-alert-widget.blade.php`

Data contract:

| Prop | Type | Notes |
| --- | --- | --- |
| `invoices` | array | invoice number, clinic, due date, outstanding amount, status, href |
| `summary` | array | outstanding count, outstanding amount, overdue count, overdue amount |
| `canViewFinance` | bool | Controls render visibility |

States:

- No outstanding invoices
- Outstanding invoices
- Overdue invoices
- Permission hidden

## Data Composition

Recommended service response shape:

| Key | Description |
| --- | --- |
| `branch` | Active branch metadata |
| `period` | Today/week/month filters |
| `summaryCards` | KPI cards |
| `alerts` | Ordered urgent alerts |
| `queues` | Work queue columns |
| `productionWorkload` | Technician workload rows |
| `qcQueue` | QC summary and items |
| `deliveryQueue` | Delivery summary and items |
| `inventoryAlerts` | Low/out stock rows |
| `financeAlerts` | Outstanding invoice rows |
| `quickActions` | Permission-aware action config |

Data should be prepared in the service layer. Blade should only render the supplied data.

## Branch and Permission Rules

Branch rules:

- Resolve active branch through `BranchContext::requireId()`.
- All operational counts must be filtered to active branch.
- Lab orders, deliveries, invoices, payments, inventory, and branch-owned records must not leak other branch data.
- Production and QC data should inherit branch scope through lab order branch ownership.

Permission rules:

- Use existing Laravel authorization and current seeded permissions.
- Do not invent `admin_cabang` permissions inside the UI design.
- Hide restricted panels rather than showing inaccessible data.
- If a future `view_branch_dashboard` permission is created, seed it using the current permission pattern and assign it to the branch admin equivalent role.

## Recommended Implementation Boundary

This document does not implement code. If implemented later, keep the existing ADLMS architecture:

- Controller remains thin.
- Request validates filters.
- Service composes operational data.
- Repositories perform branch-scoped queries.
- Policies/permissions gate access.
- Blade renders componentized UI.

Recommended files for a future implementation:

| Type | Candidate File |
| --- | --- |
| Controller | `app/Modules/Dashboard/Controllers/BranchAdminDashboardController.php` or existing dashboard module equivalent |
| Request | `app/Modules/Dashboard/Requests/BranchAdminDashboardRequest.php` |
| Service | `app/Modules/Dashboard/Services/BranchAdminDashboardService.php` |
| Repository | `app/Modules/Dashboard/Repositories/BranchAdminDashboardRepository.php` |
| View | `resources/views/dashboards/branch-admin.blade.php` |
| Components | `resources/views/components/branch-dashboard/*.blade.php` |
| Tests | `tests/Feature/Dashboard/BranchAdminDashboardTest.php` |

## Acceptance Criteria

- Admin Cabang can answer all seven daily questions from the first screen.
- Dashboard only shows active branch data.
- Critical alerts are visible before normal queues.
- Every section links to existing operational routes.
- Finance and inventory sections respect existing permissions.
- Mobile layout remains usable without horizontal table dependence.
- Empty states are clear and do not imply missing permissions.
- No future-sprint workflows are introduced.
- No new permission system is required.
- Component contracts are reusable for future dashboard implementation.

## Out of Scope

- Owner multi-branch dashboard
- New branch switching UI
- New permission system
- Purchase orders
- Stock opname
- Production usage automation
- Bill of materials
- Inter-location transfer
- Inter-branch transfer
- Supplier payment
- Forecasting
