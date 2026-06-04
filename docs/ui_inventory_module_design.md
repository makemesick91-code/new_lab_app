# Inventory Module UI Redesign

## Context

Project: Asia Dental Lab Management System (ADLMS)

Sprint 12 Inventory Core is complete. The current Inventory architecture is:

`Branch -> Inventory Location -> Product -> Inventory Movement Ledger`

This redesign document is for the existing Laravel modular monolith, Blade, Tailwind CSS, Alpine.js, and PostgreSQL stack. It follows the design direction in:

- `docs/ui_design_system.md`
- `docs/ui_owner_dashboard_design.md`
- `docs/ui_branch_admin_dashboard_design.md`

Implementation context reviewed:

- Inventory views exist under `resources/views/inventory/**`
- Inventory routes live under `/inventory` with route names under `inventory.*`
- Inventory controllers are thin and use services
- Inventory services use `BranchContext`
- Stock is derived from `trx_inventory_movements`
- Stock operations are product-first and require `inventory_location_id`
- Active locations, products, and suppliers are branch-scoped

This is documentation only. It does not create or modify code.

## Design Goals

1. Make stock visibility instant.
2. Make low-stock items impossible to miss.
3. Make branch/location separation obvious.
4. Make stock investigation easy.
5. Make stock operations safe.
6. Reduce operator mistakes.

## UX Principles

- Branch-first clarity: every Inventory page should show the active branch context and location filter state.
- Ledger-first truth: never imply that product has a mutable final stock column.
- Location-first operations: every stock operation must make the target Inventory Location visually explicit.
- Investigation before action: users should be able to move from low stock, to product detail, to stock card, to movement source in one path.
- Dangerous operations need friction: Adjustment Out should be visually more guarded than Receive Stock.
- Dense but calm: preserve the current operational table style, with better hierarchy and warning states.

## Current Route Map

| Screen | Existing Route |
| --- | --- |
| Inventory Dashboard | `inventory.dashboard` |
| Stock Index | `inventory.stock.index` |
| Product List | `inventory.products.index` |
| Product Detail | `inventory.products.show` |
| Product Create/Edit | `inventory.products.create`, `inventory.products.edit` |
| Product Stock Card | `inventory.products.stock-card` |
| Opening Stock | `inventory.products.opening-stock.create` |
| Receive Stock | `inventory.products.receive-stock.create` |
| Adjustment In | `inventory.products.adjust-in.create` |
| Adjustment Out | `inventory.products.adjust-out.create` |
| Location List | `inventory.locations.index` |
| Location Detail | `inventory.locations.show` |
| Supplier List | `inventory.suppliers.index` |
| Supplier Detail | `inventory.suppliers.show` |

## Information Architecture

Recommended Inventory navigation order:

1. Dashboard
2. Stock
3. Products
4. Locations
5. Suppliers

Primary user journeys:

| User Question | Start Screen | Next Step |
| --- | --- | --- |
| What is low? | Dashboard | LowStockWidget -> Stock Index |
| Where is this material? | Stock Index | Location filter -> Product Stock Card |
| Why did stock change? | Product Detail | Stock Card timeline |
| Can I receive this material safely? | Product Detail | Receive Stock wizard |
| Can I adjust stock out? | Product Detail | Adjustment Out wizard with current location balance |
| Which room/location is at risk? | Dashboard | Stock by Location -> Stock Index |

## Inventory Color Palette

Use the shared UI design system as the base:

| Token | Usage | Tailwind Direction |
| --- | --- | --- |
| Inventory primary | Main inventory actions and active filters | `primary` teal from design system |
| Stock healthy | OK stock, valid receive | `emerald` |
| Stock low | Low stock, due attention | `amber` |
| Stock out or unsafe | Out of stock, insufficient adjustment out | `rose` |
| Inbound movement | Opening, purchase, adjustment in | `emerald` or `sky` depending action |
| Outbound movement | Adjustment out | `amber` for normal, `rose` for insufficient/blocked |
| Location identity | Inventory Location labels/cards | `sky` or neutral slate |
| Neutral structure | Tables, borders, cards | `slate` |

Rules:

- Use color as state, not decoration.
- Low and out-of-stock states must include text labels, not only color.
- Adjustment Out uses warning color by default because it reduces stock.
- Confirmation states should use semantic colors: green for receive, amber for adjustment, red only for blocked or dangerous errors.

## Warning System

Warnings should be consistent across the module:

| Severity | Meaning | Example |
| --- | --- | --- |
| Info | Context the user should notice | "Stock is calculated from movement ledger." |
| Warning | Action can proceed but needs care | "This item is already below minimum stock." |
| Danger | Action blocked or unsafe | "Stock at this location is not enough." |
| Audit | Important traceability reminder | "Adjustment notes are required for review." |

Warning placement:

- Dashboard: alert list and KPI card severity
- Product Detail: product risk banner above actions
- Stock Card: unusual movement markers in timeline
- Operation Forms: banner above form and inline validation below field

## Screen Redesigns

## 1. Inventory Dashboard

Purpose:

Give branch operators instant visibility into stock value, stock risk, location distribution, and recent movement activity.

Top hierarchy:

1. Page header: `Inventory Dashboard`, active branch label, link to Stock Index
2. KPI row:
   - Total Inventory Value
   - Low Stock Count
   - Out Of Stock Count
3. LowStockWidget: highest risk products first
4. Stock by Location
5. Recent Movements
6. Top Consumed Materials placeholder

KPI cards:

| KPI | Tone | Drill-down |
| --- | --- | --- |
| Total Inventory Value | neutral or teal | `inventory.stock.index` |
| Low Stock Count | amber | `inventory.stock.index` filtered to low |
| Out Of Stock Count | rose | `inventory.stock.index` filtered to out |

Stock by Location:

- Show each Inventory Location as an `InventoryLocationCard`
- Include location name, code, type, total quantity, inventory value, and low/out count
- Click opens Stock Index filtered by that location

Recent Movements:

- Show movement date, product, location, movement type, quantity delta, unit cost, created by
- Inbound movements show `+qty`; outbound movements show `-qty`
- Link each product to its stock card

Top Consumed Materials:

- Future-ready placeholder only
- Label as "Coming in Sprint 13+ when production usage is implemented"
- Do not fabricate consumption metrics from adjustment out

Empty states:

- No movements: "No stock movements recorded yet."
- No low stock: "No low-stock items in this branch."
- No locations: "Create an Inventory Location before recording stock."

## 2. Product List

Purpose:

Make product stock status and operational actions visible without opening every product.

Recommended layout:

- Filter bar: search, category, unit, active status, stock status
- KPI mini-summary above table: total products, low stock, out of stock, inactive
- Table columns:
  - Code
  - Product
  - Category
  - Unit
  - Minimum Stock
  - Current Stock across active branch
  - Stock Status
  - Active Status
  - Actions

Action priority:

1. View
2. Stock Card
3. Receive
4. Adjustment
5. Edit

Design improvements:

- Replace multiple colored text links with a compact action menu or grouped action links.
- Use `StockStatusBadge` for OK, LOW, OUT.
- Make current stock numeric cells `tabular-nums` and right aligned.
- Show inactive products muted and do not show stock operation actions for them.

Mistake reduction:

- Clearly distinguish branch total stock from location stock.
- Use label "Current Stock - Branch Total" instead of only "Current".

## 3. Product Detail

Purpose:

Act as the investigation and action hub for one product.

Top section:

- Product code and name
- Active status
- Category and unit
- Minimum stock
- Average cost
- Current branch stock
- Stock status badge

Recommended sections:

1. Product Summary Card
2. Location Stock Breakdown
3. Stock Action Panel
4. Recent Movements
5. Product Metadata

Location Stock Breakdown:

- Show each active location with current stock
- Mark low/out state per location
- Link each location row to stock card filtered by location

Stock Action Panel:

- Opening Stock: only for controlled initialization, use cautiously
- Receive Stock: primary positive operation
- Adjustment In: secondary correction operation
- Adjustment Out: warning operation
- Stock Card: investigation action

Warning banner:

- If product is inactive: "This product is inactive. Stock operations are disabled."
- If current stock is below minimum: "This product is below minimum stock."
- If current stock is zero: "This product is out of stock across the active branch."

## 4. Inventory Location List

Purpose:

Make branch/location separation obvious and show where stock is stored.

Recommended layout:

- Filter bar: search, type, active status
- Card/table hybrid:
  - Desktop: table
  - Mobile: `InventoryLocationCard` stack

Table columns:

- Code
- Name
- Type
- Current Stock Items Count
- Inventory Value
- Low/Out Count
- Active Status
- Actions

Location types:

- WAREHOUSE
- PRODUCTION_ROOM
- QC_ROOM
- DELIVERY_AREA
- CLINIC_ROOM
- OTHER

Design improvements:

- Use human-readable type labels.
- Add a short type hint for operational meaning.
- Show inactive locations as muted and hide them from operation selectors.

Empty state:

- "No inventory locations found."
- CTA only if user can manage inventory: "Create Location"

## 5. Supplier List

Purpose:

Keep supplier records useful for Receive Stock without turning this into purchasing.

Recommended layout:

- Filter bar: search, active status
- Table columns:
  - Supplier
  - Phone
  - Email
  - Recent Receive Count
  - Last Receive Date
  - Active Status
  - Actions

Design improvements:

- Add optional context columns when data exists: last receive date and total recent received value.
- Keep receive stock tied to product, not supplier-first purchasing.
- Show inactive suppliers muted and prevent selection in Receive Stock form.

Out of scope:

- Supplier payment
- Purchase orders
- Supplier performance scoring

## 6. Stock Index

Purpose:

Answer "what stock exists where?" instantly.

Recommended hierarchy:

1. Location-aware filter bar
2. KPI row for selected scope
3. Stock table grouped by location
4. Low/out quick filter

Filters:

- Inventory Location: all active locations or one location
- Search product
- Category
- Stock status: All, OK, Low, Out

KPI row:

- Inventory Value for selected scope
- Low Stock
- Out Of Stock
- Location selected or "All active locations"

Table columns:

- Product code
- Product name
- Category
- Location
- Current stock
- Minimum stock
- Inventory value
- Stock status
- Actions

Actions:

- Stock Card
- Receive
- Adjust In
- Adjust Out

Design improvements:

- If location filter is active, show a clear location chip beside the page title.
- If no location filter, group rows by location or add sticky location labels.
- Low/out rows should be visually scannable with left accent or badge.

## 7. Stock Card

Purpose:

Make stock investigation easy by showing movement history, running balance, location, reference, cost, and notes.

Required elements:

- Product summary header
- Current stock for selected scope
- Location filter
- Movement type filter
- Date range filter
- Running balance
- Timeline layout
- Reference information
- Cost information
- Notes

Recommended layout:

1. Product Summary Card:
   - Product code, name, category, unit
   - Current stock in selected scope
   - Minimum stock
   - Stock status
2. Filter bar:
   - Location
   - Movement type
   - From
   - To
3. StockMovementTimeline:
   - Date
   - Movement type
   - Location
   - Quantity in/out
   - Running balance
   - Unit cost
   - Reference
   - Created by
   - Notes
4. Optional dense table fallback for export-like scanning

Timeline behavior:

- Inbound movements appear on the left/green side or with a `+` marker.
- Outbound movements appear with amber marker and `-` quantity.
- Running balance is prominent on every row.
- Same-day movements preserve repository ordering by movement date and id.

Reference information:

- Show `reference_type` and `reference_id` when present.
- If empty, show "Manual inventory movement."

Cost information:

- Show unit cost for opening and purchase movements.
- For adjustment movements with zero cost, show "No cost captured" rather than a blank.

Empty state:

- "No stock movements match these filters."

## 8. Opening Stock

Purpose:

Record initial ledger balance for a product at a specific Inventory Location.

Design stance:

Opening Stock is a controlled initialization action, not a routine receive workflow.

Layout:

1. Product Summary Card
2. Warning banner:
   - "Opening Stock creates an initial ledger movement. Use it only for setup or approved correction."
3. StockOperationWizard:
   - Step 1: Select Inventory Location
   - Step 2: Enter quantity and unit cost
   - Step 3: Add notes
   - Step 4: Confirm

Required fields:

- Inventory Location
- Quantity greater than 0
- Unit cost, minimum 0
- Notes recommended

Confirmation summary:

- Product
- Location
- Quantity in
- Unit cost
- Inventory value impact
- Notes

## 9. Receive Stock

Purpose:

Record inbound stock into a branch location from an optional supplier.

Layout:

1. Product Summary Card
2. Location selector
3. Quantity and unit cost
4. Supplier selector
5. Notes
6. Confirmation

Strong location selector:

- Show location cards or a select with type/code/name.
- Include label: "Stock will be added to this location."
- Do not default silently when more than one location exists.

Supplier behavior:

- Supplier is optional in current implementation.
- If selected, show supplier contact hint.
- Inactive suppliers must not be selectable.

Confirmation summary:

- Movement type: PURCHASE
- Product
- Location
- Supplier or "No supplier"
- Quantity in
- Unit cost
- Total value

## 10. Adjustment In

Purpose:

Record positive stock correction without supplier.

Design stance:

Adjustment In is a correction workflow. It should be slightly more guarded than Receive Stock.

Warning banner:

- "Adjustment In is for stock correction. Use Receive Stock for supplier deliveries."

Fields:

- Inventory Location
- Quantity greater than 0
- Notes

Validation guidance:

- Notes should explain the reason for adjustment.
- Quantity cannot be zero or negative.

Confirmation summary:

- Movement type: ADJUSTMENT_IN
- Product
- Location
- Quantity in
- Notes

## 11. Adjustment Out

Purpose:

Record negative stock correction at a specific location.

Design stance:

Adjustment Out is the highest-risk current Inventory operation because it reduces stock and can block production if used incorrectly.

Warning banner:

- "This will reduce stock only at the selected location."
- "The system will reject this action if location stock is insufficient."

Required UI safeguards:

- Strong location selector
- Show current stock at selected location before submit
- Show resulting balance preview
- Require notes
- Confirmation step before final submit

Fields:

- Inventory Location
- Quantity greater than 0
- Notes

Blocked state:

- If quantity exceeds current stock at selected location, show:
  - "Insufficient stock at this location."
  - Current stock
  - Requested quantity
  - Maximum allowed quantity

Confirmation summary:

- Movement type: ADJUSTMENT_OUT
- Product
- Location
- Current stock
- Quantity out
- Resulting balance
- Notes

## Stock Operation Form Pattern

Every stock operation form should include:

1. Product Summary Card
2. Strong location selector
3. Operation-specific warning banner
4. Quantity field with validation guidance
5. Optional cost/supplier fields when applicable
6. Notes with reason guidance
7. Confirmation flow
8. Cancel link back to Product Detail

Recommended form labels:

| Field | Label |
| --- | --- |
| `inventory_location_id` | Inventory Location |
| `quantity` | Quantity |
| `unit_cost` | Unit Cost |
| `supplier_id` | Supplier |
| `notes` | Notes / Reason |

Validation guidance:

- Quantity: "Must be greater than 0."
- Unit cost: "Use 0 only when no cost is captured."
- Location: "Choose where this stock physically exists."
- Notes: "Explain why this movement is being recorded."

## Reusable Components

The following component contracts follow the Skill-Creator principle: clear purpose, concise data contract, predictable states, and enough flexibility for Blade/Tailwind implementation.

### InventoryKpiCard

Purpose:

Render one inventory metric with severity and drill-down action.

Suggested path:

`resources/views/components/inventory/kpi-card.blade.php`

Props:

| Prop | Type | Notes |
| --- | --- | --- |
| `label` | string | Metric name |
| `value` | string/int/float | Preformatted value |
| `hint` | string nullable | Secondary context |
| `tone` | string | `neutral`, `success`, `warning`, `danger`, `info` |
| `href` | string nullable | Optional drill-down |
| `format` | string | `number`, `currency`, `quantity`, `text` |

States:

- Normal
- Warning
- Danger
- Empty
- Restricted hidden state

### StockStatusBadge

Purpose:

Show stock health using text and semantic color.

Suggested path:

`resources/views/components/inventory/stock-status-badge.blade.php`

Inputs:

| Input | Type | Notes |
| --- | --- | --- |
| `current` | numeric | Current ledger-derived stock |
| `minimum` | numeric | Product minimum stock |
| `label` | string nullable | Optional override |

Status rules:

- `OUT`: current <= 0
- `LOW`: current > 0 and current <= minimum
- `OK`: current > minimum

States:

- OK
- Low
- Out
- Unknown, when product/minimum data is missing

### InventoryLocationCard

Purpose:

Show one branch inventory location and its stock risk summary.

Suggested path:

`resources/views/components/inventory/location-card.blade.php`

Props:

| Prop | Type | Notes |
| --- | --- | --- |
| `name` | string | Location name |
| `code` | string nullable | Location code |
| `type` | string | Location type |
| `isActive` | bool | Active status |
| `totalStock` | numeric nullable | Optional total quantity |
| `inventoryValue` | numeric nullable | Optional value |
| `lowCount` | int nullable | Low-stock item count |
| `outCount` | int nullable | Out-of-stock item count |
| `href` | string nullable | Stock Index filtered to location |

States:

- Active
- Inactive
- Healthy
- Low stock exists
- Out of stock exists

### StockMovementTimeline

Purpose:

Render ledger movements as an investigation timeline with running balance.

Suggested path:

`resources/views/components/inventory/stock-movement-timeline.blade.php`

Movement item fields:

| Field | Notes |
| --- | --- |
| `movement_date` | Display date |
| `movement_type` | OPENING, PURCHASE, ADJUSTMENT_IN, ADJUSTMENT_OUT |
| `location_name` | Inventory Location |
| `quantity_in` | Inbound quantity |
| `quantity_out` | Outbound quantity |
| `running_balance` | Calculated balance |
| `unit_cost` | Cost captured on movement |
| `reference_label` | Reference type/id or manual |
| `created_by` | User name if available |
| `notes` | Movement notes |

States:

- Inbound
- Outbound
- Manual/no reference
- Empty

### StockValueCard

Purpose:

Show inventory value for a branch, location, product, or filtered stock scope.

Suggested path:

`resources/views/components/inventory/stock-value-card.blade.php`

Props:

| Prop | Type | Notes |
| --- | --- | --- |
| `title` | string | Scope label |
| `value` | numeric/string | Currency formatted |
| `quantity` | numeric nullable | Optional total quantity |
| `scopeLabel` | string nullable | Branch/location/product context |
| `href` | string nullable | Drill-down |

States:

- Normal
- Zero value
- Filtered scope

### StockOperationWizard

Purpose:

Guide opening, receive, adjustment in, and adjustment out workflows safely.

Suggested path:

`resources/views/components/inventory/stock-operation-wizard.blade.php`

Props:

| Prop | Type | Notes |
| --- | --- | --- |
| `operationType` | string | `opening`, `receive`, `adjust_in`, `adjust_out` |
| `product` | DTO/model | Product summary |
| `locations` | collection | Active branch locations |
| `suppliers` | collection nullable | Receive only |
| `action` | string | Form action |
| `method` | string | POST |
| `currentLocationStock` | numeric nullable | Needed for adjustment out preview |

States:

- Location not selected
- Valid input
- Insufficient stock
- Confirmation ready
- Submitting
- Validation error

### LowStockWidget

Purpose:

Make low/out stock impossible to miss on dashboard and branch admin surfaces.

Suggested path:

`resources/views/components/inventory/low-stock-widget.blade.php`

Props:

| Prop | Type | Notes |
| --- | --- | --- |
| `items` | collection/array | Low/out stock rows |
| `title` | string | Widget heading |
| `limit` | int | Visible item cap |
| `showLocation` | bool | Show location when available |
| `href` | string nullable | View all link |

Item fields:

- Product code
- Product name
- Location name if location-specific
- Current stock
- Minimum stock
- Unit symbol
- Status
- Stock card link

States:

- No low stock
- Low stock
- Out of stock
- Mixed low/out

## Error States

| Scenario | UI Response |
| --- | --- |
| No active branch resolved | Show blocking page-level error before inventory data renders |
| Product from another branch | Policy blocks access; do not show partial page |
| Location from another branch | Validation/service error: "Inventory location tidak valid untuk branch aktif." |
| Inactive location selected | Remove from selectors; if submitted, show validation error |
| Inactive product operation | Disable stock actions and show inactive warning |
| Inactive supplier selected | Remove from selector; if submitted, show validation error |
| Quantity <= 0 | Inline field error under quantity |
| Adjustment out exceeds location stock | Blocking danger banner plus inline quantity error |
| Date range invalid | Inline error on `date_to` |
| No movement rows | Empty state inside timeline/table |

## Empty States

| Screen | Empty State |
| --- | --- |
| Dashboard | "No inventory movements recorded yet." |
| Product List | "No products found." |
| Product Detail location stock | "No stock has been recorded for this product." |
| Location List | "No inventory locations found." |
| Supplier List | "No suppliers found." |
| Stock Index | "No stock movements match this scope." |
| Stock Card | "No stock card movements match these filters." |
| Opening Stock | "Create an active location before recording opening stock." |
| Receive Stock | "Create an active location before receiving stock." |
| Adjustment In/Out | "No active location is available for adjustment." |

Empty state CTAs should appear only when the user has the matching manage permission.

## Mobile Behavior

Global mobile rules:

- KPI cards: two columns where possible, one column for currency-heavy cards.
- Filter bars: collapse into stacked full-width controls.
- Tables: use horizontal scroll only for dense stock investigation; use card rows for operational lists.
- Stock operation forms: single-column, location selector first.
- Confirmation step: show sticky bottom action row only when it does not cover validation errors.

Screen-specific mobile behavior:

| Screen | Mobile Pattern |
| --- | --- |
| Dashboard | KPI cards, then LowStockWidget, then location cards |
| Product List | Product cards with stock badge and action menu |
| Product Detail | Summary first, action panel second, location stock stack |
| Location List | `InventoryLocationCard` stack |
| Supplier List | Supplier cards with contact details |
| Stock Index | Filter disclosure plus grouped rows by location |
| Stock Card | Timeline first, dense table optional behind "Table view" |
| Operation Forms | Wizard steps stacked; confirmation at bottom |

## Table Patterns

Inventory tables should follow the shared design system:

- White surface with light slate border
- `text-sm`
- Right-aligned numeric cells
- `tabular-nums` for quantities and currency
- Product names as primary text
- Codes, categories, units, and locations as secondary text
- Badges for status
- Actions at far right

Recommended table enhancements:

- Use sticky first column only if tables become hard to scan.
- Keep quantity columns consistently named:
  - Current Stock
  - Minimum Stock
  - Quantity In
  - Quantity Out
  - Running Balance
- Use plus/minus signs in movement rows:
  - `+10.00`
  - `-2.00`
- Avoid mixing branch total and location stock without explicit labels.

## Branch and Location Rules

Inventory UI must reinforce the business rules already implemented in services:

- Every branch-owned query uses active branch context.
- Every stock movement belongs to branch, inventory location, and product.
- Product branch and location branch must match.
- Supplier branch must match when supplier is selected.
- Inactive products cannot be used for stock operations.
- Inactive locations and suppliers are not selectable.
- Stock must be calculated from ledger movements.
- No UI should imply `stock`, `current_stock`, or `qty_on_hand` is stored on products as source of truth.

## Recommended Implementation Boundary

If implemented later, keep the current architecture:

- Controller: route and authorization only
- Request: validation
- Service: branch/location business rules and stock operations
- Repository: branch-scoped queries and ledger aggregation
- Blade: render precomputed data

Do not implement in this redesign:

- Purchase Order
- Stock Opname
- Production Usage
- Bill of Materials
- Inter-location transfer
- Inter-branch transfer
- Supplier payment
- Forecasting

## Acceptance Criteria

The Inventory UI redesign is ready for implementation when:

- Low-stock and out-of-stock items are visible on Dashboard and Stock Index.
- Every stock operation requires an explicit Inventory Location.
- Product detail provides both branch-total stock and location-level stock breakdown.
- Stock Card shows running balance, timeline, location filter, reference, cost, and notes.
- Adjustment Out clearly previews current stock and resulting balance.
- Inactive products, locations, and suppliers are visually and functionally guarded.
- Mobile users can perform receive and adjustment workflows without horizontal table dependence.
- All screens preserve branch isolation and current permission conventions.
- The design does not introduce out-of-scope future inventory workflows.
