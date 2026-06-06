# ADLMS Snippets

Reusable prompt snippets for ADLMS AI-assisted development.

## Available Snippet

- `adlms_master_workflow.md` - the ADLMS MASTER WORKFLOW prompt starter for Codex, Cursor, or other AI coding agents.

## How To Use

1. Open `.cursor/snippets/adlms_master_workflow.md`.
2. Copy the full contents.
3. Paste it at the beginning of a new implementation prompt.
4. Add the specific sprint/task request after it.
5. Make sure the agent reports files reviewed, existing patterns found, risks, and planned implementation before coding.

## Example Sprint Prompt

```text
<paste ADLMS MASTER WORKFLOW here>

Task:
Implement Sprint 16.1 - Purchase Request foundation.

Scope:
- Inventory/Purchasing only.
- Preserve ledger-only stock.
- Purchase requests must not create stock movements.
- Add service tests and route authorization tests.

After implementation:
- Run php artisan test.
- Run .\vendor\bin\pint.
```

## Reminder

Paste ADLMS MASTER WORKFLOW before each implementation prompt. It forces the agent to read the project rules, inventory rules, sprint history, graph report, and Cursor memory before touching files.
