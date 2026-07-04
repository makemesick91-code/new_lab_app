# Canonical Data Dictionary

**Sprint:** DMO-1  
**Source:** NSF-4 `CanonicalEntityRegistry`  
**Maintained by:** `php artisan architecture:dmo-foundation` / `architecture:canonical-entity-inventory`

## Registry Summary

| Count | Value |
| --- | --- |
| Entities | 56 |
| Domains | 7 (foundation, rme, cashier, inventory, lab, owner, telemetry) |
| Workflows | 9 |
| DMO ready | 54 |
| needs_review | 2 |
| Gaps | 6 |

## Entity Schema

Each entry in the machine-readable registry includes:

```json
{
  "canonical_name": "Patient",
  "domain": "rme",
  "primary_table": "mst_patients",
  "model": "App\\Modules\\Patient\\Models\\Patient",
  "scope": "branch_scoped",
  "source_type": "source_of_truth",
  "lifecycle_status_fields": [],
  "relationships": ["branch_id", "doctor_id", "clinic_id"],
  "sensitivity": ["PII"],
  "privacy_notes": "KTP masked in UI/export",
  "downstream_consumers": [],
  "dmo_readiness": "ready"
}
```

## Domain Registry (summary)

### Foundation / Identity / Access

| Entity | Table | Scope | Source Type | Sensitivity |
| --- | --- | --- | --- | --- |
| Branch | mst_branches | global | source_of_truth | internal |
| User | users | global | source_of_truth | internal, PII |
| Role / Permission | roles, permissions | global | source_of_truth | internal |
| Doctor | mst_doctors | branch_scoped | source_of_truth | internal |
| Treatment / Tariff | mst_treatments, mst_tariffs | global/branch | source_of_truth | internal/financial |
| Clinic Room | mst_clinic_rooms | branch_scoped | source_of_truth | internal |

### RME / Clinical

| Entity | Table | Scope | Sensitivity | DMO |
| --- | --- | --- | --- | --- |
| Patient | mst_patients | branch_scoped | PII | ready |
| Clinic Visit | trx_clinic_visits | branch_scoped | PII, PHI | ready |
| Medical Record | trx_medical_records | branch_scoped | PHI | ready |
| Odontogram | trx_odontograms | branch_scoped | PHI | ready |
| Patient Document | mst_patient_documents | branch_scoped | PII, PHI | **needs_review** |
| RME Prescription | trx_rme_prescriptions | branch_scoped | PHI | **needs_review** |

### Cashier / Receivable

| Entity | Table | Source Type | Notes |
| --- | --- | --- | --- |
| RME Invoice | trx_rme_invoices | source_of_truth | DRAFT/UNPAID/PARTIAL/PAID/VOID |
| RME Payment | trx_rme_payments | source_of_truth | payment_batch_uuid for carry-over |
| Receivable | trx_rme_invoices | **derived** | UNPAID/PARTIAL remaining>0 — no separate table |
| Receivable Follow-up | trx_rme_receivable_follow_ups | source_of_truth | NEW→CLOSED workflow |

### Inventory / Procurement

| Entity | Table | Source Type | Notes |
| --- | --- | --- | --- |
| Inventory Product | inv_products | source_of_truth | |
| Inventory Movement | trx_inventory_movements | source_of_truth | **Ledger truth** |
| Inventory Batch | inv_inventory_batches | source_of_truth | Lot/expiry |
| Purchase Request → PO → GR | trx_* | source_of_truth | Procurement chain |
| Stock Transfer / Opname | trx_stock_* | source_of_truth | Creates ledger movements |

### Lab / Production / QC / Delivery

| Entity | Table | Notes |
| --- | --- | --- |
| Lab Order | trx_lab_orders | Status pipeline |
| Lab Case Candidate | trx_lab_case_candidates | RME → Lab staging |
| Quality Control | trx_lab_quality_controls | |
| Delivery | trx_lab_deliveries | |

### Owner / Reporting / Telemetry

| Entity | Source Type | Notes |
| --- | --- | --- |
| Owner Dashboard KPI | derived | OwnerDashboardKpiService |
| Inventory Analytics Summary | reporting | rpt_inventory_* snapshots |
| Performance Runtime Evidence | telemetry | storage/app/performance/*.json |

## Source Type Definitions

| Type | Meaning |
| --- | --- |
| source_of_truth | Authoritative transactional table |
| derived | Computed from other entities (Receivable, Current Stock) |
| reporting | Snapshot/summary tables |
| telemetry | Performance/audit evidence files |
| configuration | Master data settings |

## Scope Definitions

| Scope | Rule |
| --- | --- |
| global | Shared across branches (Treatment catalog) |
| branch_scoped | Filtered by BranchContext |
| system | Non-business telemetry |

## Entity Gaps (DMO-001 – DMO-006)

See `dmo-foundation.md` §12. Key items: Patient Document policy, Receivable derived documentation, expiry alert computed-only, Owner KPI multi-service sources, treatment/tariff multi-branch boundary.

## Full Machine-Readable Export

```bash
php artisan architecture:canonical-entity-inventory --json
php artisan architecture:dmo-foundation --json --output=storage/app/architecture/dmo1-foundation.json
```
