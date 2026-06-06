# ADLMS Branch Memory

ADLMS is a multi-branch system.

## Branch Rules

- Operational data must be scoped by branch.
- Cross-branch data leaks are critical bugs.
- BranchContext should be used where applicable.
- Related records in a transaction must belong to the same branch.
- Users should only access records allowed by their role/branch assignment.

## Testing

New workflows should include branch isolation tests.
