# SECURITY-F4C-CLINIC-CALENDAR-LAB-ORDERS-1

**Status:** implemented and verified locally; not merged, not deployed.
**Type:** `RUNTIME_FIX`. **Modules:** LabOrder, Delivery.
Audit item F-4c. Owner decision **D16 (approved 2026-10-28)**: lab operational business dates use
Asia/Makassar; actual event timestamps, historical workflow order and explicitly assigned due
dates are preserved; no historical backfill. No migration, no route, no permission. No stored
row is rewritten.

## 1. Finding

The application timezone is UTC. Between 00:00 and 08:00 WITA the UTC date is still yesterday,
so during those eight hours:

- a new lab order without an explicit date was dated on the previous clinic day, in all three
  paths: legacy create, Workflow V2 draft, and candidate conversion;
- on 1 January a new order or delivery was numbered in the previous year;
- converting a case candidate accepted a due date of "yesterday";
- the operational dashboard's "delivered today" counted the UTC day. That day runs from 08:00
  to 08:00 WITA, so it missed deliveries made after clinic midnight and still counted the
  previous clinic day's late ones.

## 2. Change

`ClinicalClock` (the canonical clinic-date authority) is used for:

- the order-date default in `LabOrderService`, `LabWorkflowRequestService` and
  `LabCaseCandidateConversionService`;
- the year fallback in `OrderNumberGeneratorService` and `DeliveryNumberGeneratorService`;
- the due-date floor in `ConvertLabCaseCandidateRequest`.

`ClinicalDayWindow` converts the clinic day into a UTC instant range for `delivered_at` in
`LabWorkflowOperationalDashboardService`. The form defaults in three views follow the same
date.

What does not change, by D16:

- an order or due date the user enters is stored exactly as entered;
- event timestamps stay UTC instants;
- no stored date is rewritten.

## 3. Deliberately not changed — needs an explicit owner decision

- **Lab KPI analytics** (`LabOperationalAnalyticsService` / `Repository`, LAB-PROD-2) and
  **technician capacity planning** (`LabTechnicianCapacityPlanningService`, LAB-PROD-3) keep the
  application timezone. FIX-LAB-ANALYTICS-MEDIAN-LATENESS-DAY-BOUNDARY-1 chose that deliberately
  and pins it with a test. Moving them would also recompute historical SLA figures. The audit's D16
  recommendation said "analytics unchanged unless revisited", and D16's wording names
  operational dates, not KPIs. Until the owner revisits it, the analytics overdue count can differ
  from order dates for eight hours a day.
- **Legacy lab invoice overdue reports** (`InvoiceReportService`, `ReportingRepository`) are
  billing rather than lab operations. They need their own decision.

## 4. Verification

`tests/Feature/LabWorkflow/LabClinicCalendarBoundaryTest.php` freezes the clock at 23:59,
00:01 and 07:59 WITA. It checks:

- the order-date default;
- that an explicit order date and `created_at` stay as entered;
- order and delivery numbering across new year;
- the due-date floor, accepted on the clinic date and refused for the previous clinic day;
- "delivered today" by the clinic day.

Against the original code the suite fails 5 of 7. The two controls (the same-date case and the
explicitly entered date) pass on both.
