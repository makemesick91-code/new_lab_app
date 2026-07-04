# Canonical Entity & Workflow Inventory

**Sprint:** NSF-4  
**Purpose:** Bridge document before DMO-1 (Data Model, Ontology & Canonical Metrics).  
**Maintained by:** `php artisan architecture:canonical-entity-inventory`

## 1. Purpose

Map canonical entities, ownership, scope, sensitivity, workflows, and reporting dependencies across DaengtisiaMS without changing business logic.

## 2. Scope

- Read-only inventory of models, tables, modules, workflows
- Branch vs global classification
- Source-of-truth vs derived/reporting
- PII/PHI/financial sensitivity
- DMO readiness gaps

**Out of scope:** schema changes, new features, row-level data export.

## 3. Canonical Domain Map

| Domain | Module paths | Route prefix (approx) |
| --- | --- | --- |
| foundation | Branch, Doctor, Treatment, Tariff, PaymentMethod, ClinicRoom, WaReminderTemplate | `settings.*` |
| rme | Patient, ClinicVisit, MedicalRecord, Odontogram | `rme.*` |
| cashier | RmeInvoice, RmePayment, receivables | `rme.*` (billing), `invoices.*` |
| inventory | Inventory/* procurement & stock | `inventory.*` |
| lab | LabOrder, Production, QC, Delivery | `lab-orders.*`, `production.*`, `deliveries.*` |
| owner | Reporting KPIs, exports | `dashboard`, `reports.*` |
| telemetry | Performance evidence, audit | `performance:*`, `architecture:*` |

## 4. Canonical Entity Registry (summary)

Run `php artisan architecture:canonical-entity-inventory --json` for full machine-readable registry.

| Canonical Name | Domain | Primary Table | Scope | Source Type | Sensitivity |
| --- | --- | --- | --- | --- | --- |
| Branch | foundation | mst_branches | global | source_of_truth | internal |
| Patient | rme | mst_patients | branch_scoped | source_of_truth | PII |
| Clinic Visit | rme | trx_clinic_visits | branch_scoped | source_of_truth | PII, PHI |
| Medical Record | rme | trx_medical_records | branch_scoped | source_of_truth | PHI |
| Odontogram | rme | trx_odontograms | branch_scoped | source_of_truth | PHI |
| RME Invoice | cashier | trx_rme_invoices | branch_scoped | source_of_truth | financial, PII |
| RME Payment | cashier | trx_rme_payments | branch_scoped | source_of_truth | financial |
| Receivable | cashier | trx_rme_invoices | branch_scoped | derived | financial, PII |
| Inventory Movement | inventory | trx_inventory_movements | branch_scoped | source_of_truth | internal, financial |
| Inventory Batch | inventory | inv_inventory_batches | branch_scoped | source_of_truth | internal |
| Purchase Order | inventory | trx_purchase_orders | branch_scoped | source_of_truth | internal, financial |
| Lab Order | lab | trx_lab_orders | branch_scoped | source_of_truth | internal |
| Owner Dashboard KPI | owner | — | branch_scoped | derived | financial |

*Full registry: 50+ entities across 7 domains.*

## 5. Workflow Inventory

| Workflow | Domain | Key Status Flow |
| --- | --- | --- |
| RME Visit Lifecycle | rme | registered → waiting → in_progress → cashier_pending → completed / cancelled |
| RME Billing & Payment | cashier | DRAFT → UNPAID → PARTIAL/PAID → VOID |
| Receivable Follow-up | cashier | NEW → CONTACTED → PROMISED → ESCALATED → CLOSED |
| Inventory Procurement | inventory | PR → PO → Goods Receipt → ledger movements |
| Stock Transfer | inventory | draft → in_transit → received |
| Stock Opname | inventory | DRAFT → COUNTING → COMPLETED |
| Batch Disposal | inventory | pending → approved → completed |
| Lab Order Lifecycle | lab | DRAFT → IN_PRODUCTION → QC → DELIVERED → COMPLETED |
| RME → Lab Integration | lab | pending_review → converted_to_lab_order |

## 6. Branch / Global Scope

| Scope | Examples |
| --- | --- |
| **global** | Branch, User, Role, Permission, Treatment, Treatment Category, Payment Method |
| **branch_scoped** | Patient, Clinic Visit, RME Invoice, Inventory Movement, Lab Order |
| **system** | Performance telemetry files |

**Rule:** Active branch via `BranchContext::requireId()` — never trust request `branch_id`.

## 7. Source-of-Truth vs Derived

| Type | Examples |
| --- | --- |
| **source_of_truth** | mst_patients, trx_clinic_visits, trx_inventory_movements, trx_rme_invoices |
| **derived** | Receivable (UNPAID/PARTIAL + remaining), Owner KPI aggregates |
| **reporting** | rpt_inventory_* summary tables |
| **telemetry** | storage/app/performance/*.json |

**Inventory stock:** Always derived from `SUM(quantity_in) - SUM(quantity_out)` on movements — no mutable stock columns.

## 8. Sensitive Data Classification

| Class | Entities / Fields | Handling |
| --- | --- | --- |
| **PII** | Patient (name, KTP, phone, address) | KTP masked in UI/export; never in telemetry |
| **PHI** | Medical Record, Odontogram, handwriting | No raw notes in reports/command output |
| **financial** | RME Invoice, Payment, Receivable, Tariff | Branch-scoped; owner KPI aggregates only |
| **internal** | User, Role, Inventory master | Policy-gated access |

## 9. Dashboard / Report / KPI Dependencies

| Consumer | Source Entities |
| --- | --- |
| Owner Dashboard KPI | trx_clinic_visits, trx_rme_payments, trx_rme_invoices, inventory analytics |
| RME Receivables report | trx_rme_invoices (UNPAID/PARTIAL) |
| Inventory dashboard | trx_inventory_movements, inv_products, inv_inventory_batches |
| Inventory reports | rpt_inventory_*, ledger aggregation |
| Performance observability | pg_stat_statements (NSF-3), no PHI |

## 10. Gaps and Ambiguities

| ID | Gap | Severity |
| --- | --- | --- |
| DMO-001 | Patient Document formal DMO policy | medium |
| DMO-003 | Receivable is derived, not separate table | low |
| DMO-004 | Expiry alert is computed service, not persisted | low |
| DMO-005 | Owner KPI spans multiple services | medium |
| DMO-006 | Treatment/tariff multi-branch pricing boundary | medium |

## 11. DMO-1 Readiness Checklist

- [x] Canonical entity names defined
- [x] Primary tables mapped
- [x] Module ownership documented
- [x] Branch scope classified
- [x] Sensitivity tags applied
- [x] Workflows with status flows documented
- [ ] Unified metric registry (deferred to DMO-1)
- [ ] Patient Document policy (needs_review)

## 12. NSF-5 Handoff Notes

- Extend registry with route/controller/service owners per entity (`--include-routes`)
- Add automated drift detection (registry vs live schema)
- Wire canonical names into slow-query module classification
- Resolve DMO-005 Owner KPI metric unification before ontology work
