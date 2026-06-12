<?php

/*
|--------------------------------------------------------------------------
| Module Branch Scope (Sprint 23 Phase 23.5)
|--------------------------------------------------------------------------
|
| Owner decision after Phase 23.4 smoke: only RME and Inventory are
| multi-branch modules. Laboratory is a single / global laboratory and must
| NOT be filtered or enforced by branch context.
|
| Values:
|   - 'multi_branch'  : branch-aware. Uses Master Data Cabang, branch filters
|                       are allowed / required where appropriate.
|   - 'single_branch' : global. No branch filter, no branch enforcement,
|                       no KPI grouped by branch. Legacy branch_id columns are
|                       kept for backward compatibility but do NOT drive behavior.
|
| Read this config through App\Modules\Branch\Support\ModuleBranchScope so the
| rule stays centralized.
|
*/

return [

    'rme' => 'multi_branch',

    'inventory' => 'multi_branch',

    'lab' => 'single_branch',

];
