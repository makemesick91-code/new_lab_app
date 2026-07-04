# DQ-1 ACID, Constraint & Data Quality Audit

## 1. Objective

Establish read-only database correctness governance for DaengtisiaMS:

- ACID transaction coverage for critical multi-write flows
- Schema constraint coverage (FK, unique, monetary, ledger direction)
- Production-safe data anomaly detection
- Integration with foundation governance and deploy gates

## 2. Scope

| Area | In scope | Out of scope |
| --- | --- | --- |
| ACID | Static scan of critical services for `DB::transaction` | Rewriting domain workflows |
| Constraints | Schema introspection + aggregate data checks | Destructive data cleanup |
| Data quality | Aggregate/chunked SQL anomaly counts | Full row exports |
| Migrations | Additive constraints only when data is clean | `migrate:fresh` / `db:wipe` |

## 3. ACID rules

Future sprints **must not** introduce multi-write database operations outside `DB::transaction`.

Critical flows audited:

- RME visit lifecycle (`ClinicVisitService`)
- Medical record / odontogram (`MedicalRecordService`, `OdontogramService`)
- RME invoice/payment (`RmeInvoiceService`, `RmePaymentService`)
- RME → Lab candidate (`RmeLabIntegrationService` — documented post-commit exception)
- Lab order conversion (`LabCaseCandidateConversionService`, `LabOrderService`)
- Inventory movements (`InventoryStockService`, `GoodsReceiptService`, `StockTransferService`, `StockOpnameService`, procurement)
- Legacy patient import commit (`LegacyPatientImportService`)

## 4. Constraint rules

Future migrations **must prefer additive, named constraints** and **must not use destructive operations** on production.

| Check ID | Rule |
| --- | --- |
| DQ1-CONSTRAINT-001 | Critical FK constraints on RME, MR, inventory ledger |
| DQ1-CONSTRAINT-002 | Unique `mst_patients.medical_record_number` |
| DQ1-CONSTRAINT-003 | Nullable unique `mst_patients.ktp_number` |
| DQ1-CONSTRAINT-004 | Non-negative monetary fields on RME invoices/payments |
| DQ1-CONSTRAINT-005 | Inventory `quantity_in` / `quantity_out` direction |
| DQ1-CONSTRAINT-006 | Unique `trx_medical_records.clinic_visit_id` (one RM sheet per visit) |

## 5. Data quality rules

| Check ID | Rule |
| --- | --- |
| DQ1-DATA-001 | No duplicate RM numbers |
| DQ1-DATA-002 | No duplicate non-null KTP (masked samples only) |
| DQ1-DATA-003 | No orphan RME invoices/payments/items |
| DQ1-DATA-004 | Invoice status/remaining consistency |
| DQ1-DATA-005 | Medical records linked to visits |
| DQ1-DATA-006 | Valid inventory movement direction / batch tracking — extended by DQ-2 backfill (`inventory:backfill-missing-batches`) |
| DQ1-DATA-007 | Branch-owned rows have `branch_id` |
| DQ1-DATA-008 | Lab `trx_payments` separate from RME `trx_rme_payments` |
| DQ1-DATA-009 | Receivable follow-ups reference valid invoices |
| DQ1-DATA-010 | Received stock transfers have movement references |

## 6. Command usage

```bash
php artisan data-quality:dq1-audit
php artisan data-quality:dq1-audit --json
php artisan data-quality:dq1-audit --fail-on=error
php artisan data-quality:dq1-audit --output=dq1-audit.json
```

| Option | Description |
| --- | --- |
| `--json` | Machine-readable report |
| `--fail-on=error` | Exit non-zero on FAIL severity checks |
| `--fail-on=warning` | Exit non-zero on WARN or FAIL |
| `--output=` | Write JSON under `storage/app/architecture/` only |

Integrated in:

```bash
php artisan architecture:foundation-governance-summary
```

## 7. Production safety

- Read-only by default — no mutations
- Aggregate SQL / `EXISTS` checks — no full table loads
- KTP/NIK never printed in full — masked via `PatientDataCompletenessService::maskKtp()`
- Safe on VPS pilot/production PostgreSQL datasets

## 8. Pre-deploy gate

Database/foundation sprints must run before GO tag:

```bash
php artisan data-quality:dq1-audit --fail-on=error
php artisan architecture:foundation-governance-summary
```

## 9. Known warnings / deferred items

- DB-level CHECK constraints for inventory quantity direction deferred (report-first; app enforces ledger rules)
- `RmeLabIntegrationService` is intentionally post-commit idempotent — not a multi-write transaction boundary
- Soft-deleted duplicate RM/KTP may require manual review if historical imports bypassed constraints

## 10. Registry

Source: `config/dq1.php`  
Service: `App\Services\DataQuality\Dq1AuditService`  
Command: `App\Console\Commands\DataQualityDq1AuditCommand`
