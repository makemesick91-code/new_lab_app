---
name: superpowers-workflow
description: Use Superpowers-style disciplined workflow while keeping Claude context usage small.
---

# Superpowers Workflow Skill

Use Superpowers as workflow discipline, not broad exploration.

Rules:
1. Do not load all Superpowers skills.
2. Use only the relevant skill for the current task.
3. Keep planning short, maximum 5 steps.
4. Do not over-explore.
5. Prefer small iterative steps.
6. Search with rg/grep before reading files.
7. Read only target files.
8. Run targeted tests only.

For DaengtisiaMS:
- Clinic master data pattern: ClinicRoom
- Inventory CRUD pattern: ProductCategory/ProductUnit
- Inventory transaction pattern: PurchaseOrder/GoodsReceipt/StockTransfer
- Permission check: PermissionSeeder, RoleSeeder, Policy, route middleware
- Sidebar check: sidebar.blade.php only related section
- Routes check: grep keyword in routes/web.php

Do not:
- Touch HR unless requested.
- Refactor global architecture.
- Run full test suite unless final quality gate.
- Read full sprint history unless requested.
