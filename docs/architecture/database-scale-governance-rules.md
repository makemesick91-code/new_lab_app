# Database Scale Governance Rules

REPLICA-1 adds application governance for future database scale-out. These rules are published in `architecture:foundation-governance-summary` as `REPLICA-R001..REPLICA-R012`.

## Rules

- `REPLICA-R001`: Default runtime must remain single-primary safe until a replica is explicitly configured and approved.
- `REPLICA-R002`: All write operations must use the primary database connection; replica connections are for read-only workloads only.
- `REPLICA-R003`: Heavy report and analytics reads must use explicit service/repository paths before any future read routing change.
- `REPLICA-R004`: The DB replica readiness command must be read-only and must not run insert, update, delete, DDL, or workflow mutations.
- `REPLICA-R005`: Database secret values must not appear in command output, documentation, logs, tests, or governance summaries.
- `REPLICA-R006`: Replica lag must be auditable before any read traffic is directed to a replica.
- `REPLICA-R007`: When a replica is expected, missing read host, database, username, or password must be NO-GO in strict mode.
- `REPLICA-R008`: Primary/read hosts may be identical for pilot smoke, but must produce a warning when a replica is expected.
- `REPLICA-R009`: Database scale deploys must keep a rollback path back to primary-only reads without destructive migrations.
- `REPLICA-R010`: Foundation governance summary must surface DB replica readiness without weakening STORAGE, STATELESS, LB, NSF, or DMO governance.
- `REPLICA-R011`: Cross-branch read analytics must continue to respect permissions, policies, and branch isolation.
- `REPLICA-R012`: Stale replica reads must not be used for payment, stock movement, visit completion, or finalization workflows.

## Operating Position

Single-primary remains the production-safe default. A read replica can be configured and audited, but runtime read routing stays off unless a later sprint explicitly implements and tests that path.

## Rollback Position

The safe rollback is to disable `DB_REPLICA_ENABLED`, rebuild config cache, and keep `DB_CONNECTION` on the primary. REPLICA-1 adds no destructive migration and no persistent data move.
