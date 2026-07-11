<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DEVFLOW-1 — Focused Regression Matrix
|--------------------------------------------------------------------------
|
| Canonical, impact-based map: changed paths -> impacted category -> the
| focused tests, related categories, and CI escalation that MUST run.
|
| sprint:test-plan reads `git diff --name-only` + the sprint manifest,
| resolves every changed file to zero or more categories via `path_globs`,
| unions the categories' `related` closure, and emits a deterministic,
| de-duplicated Pest `--filter`/directory plan plus a CI escalation set.
|
| This complements (never replaces) config/ci_runtime_control.php: that
| file drives the CI gate profile; this file drives the LOCAL focused test
| plan and the human-readable impact explanation. Both are fail-closed:
| an unmatched changed file escalates to `full_required`.
|
| `path_globs` use fnmatch() semantics (`*` and `**` both treated as "any").
|
*/

return [

    'version' => 1,

    // When a changed file matches NO category, treat the change as high-risk.
    'unmatched_escalates_to' => 'full_required',

    // Categories that, when touched, force the full required CI suite regardless
    // of anything else (mirrors CICD-CTRL-1 high-risk escalation).
    'full_suite_escalation_categories' => [
        'shared_foundation',
        'auth',
        'branch_context',
        'security',
        'database_schema',
        'ci_cd',
        'dependencies',
        'config',
        'state_machine',
        'access_control',
    ],

    'categories' => [

        'access_control' => [
            'path_globs' => [
                'app/Support/AccessControl/*',
                'app/Policies/*',
                'database/seeders/RoleSeeder.php',
                'database/seeders/PermissionSeeder.php',
            ],
            'tests' => ['tests/Feature/AccessControl'],
            'filters' => ['AccessControl', 'RolePermissionHardening', 'SidebarPermissionVisibility'],
            'related' => ['auth', 'security', 'branch_context'],
            'ci_jobs' => ['critical_test_gate', 'selective_module_gate'],
            'escalate_full_suite' => true,
        ],

        'auth' => [
            'path_globs' => [
                'app/Http/Controllers/Auth/*',
                'app/Http/Middleware/Authenticate*',
                'app/Providers/RepositoryServiceProvider.php',
            ],
            'tests' => ['tests/Feature/Auth'],
            'filters' => ['Auth', 'Login', 'Session'],
            'related' => ['access_control', 'security'],
            'ci_jobs' => ['critical_test_gate'],
            'escalate_full_suite' => true,
        ],

        'branch_context' => [
            'path_globs' => [
                'app/Support/BranchContext*',
                'app/**/BranchContext*',
                'app/Modules/Branch/*',
                'app/Services/BranchService.php',
                'app/**/BranchService.php',
            ],
            'tests' => ['tests/Feature/Branch'],
            'filters' => ['BranchContext', 'Branch', 'PerawatOnlineContext', 'OnlineContext'],
            'related' => ['access_control', 'rme', 'inventory', 'lab_workflow'],
            'ci_jobs' => ['critical_test_gate'],
            'escalate_full_suite' => true,
        ],

        'rme' => [
            'path_globs' => [
                'app/Modules/ClinicVisit/*',
                'app/Modules/MedicalRecord/*',
                'app/Modules/Odontogram/*',
                'app/Modules/RmeInvoice/*',
                'resources/views/rme/*',
            ],
            'tests' => ['tests/Feature/RME'],
            'filters' => ['ClinicVisit', 'MedicalRecord', 'Odontogram', 'Rme'],
            'related' => ['rme_billing', 'branch_context', 'access_control'],
            'ci_jobs' => ['critical_test_gate', 'selective_module_gate'],
            'escalate_full_suite' => false,
        ],

        'rme_billing' => [
            'path_globs' => [
                'app/Modules/RmeInvoice/*',
                'resources/views/rme/cashier/*',
            ],
            'tests' => ['tests/Feature/RME'],
            'filters' => ['Cashier', 'Payment', 'Receivable', 'RmeInvoice', 'Consent'],
            'related' => ['rme', 'ledger', 'access_control'],
            'ci_jobs' => ['critical_test_gate', 'selective_module_gate'],
            'escalate_full_suite' => false,
        ],

        'lab_workflow' => [
            'path_globs' => [
                'app/Modules/LabOrder/*',
                'app/Modules/Technician/*',
                'resources/views/lab*/*',
                'config/lab_workflow*.php',
            ],
            'tests' => ['tests/Feature/LabWorkflow'],
            'filters' => ['LabWorkflow', 'LabV2', 'LabOrder', 'Technician', 'Pickup', 'Delivery'],
            'related' => ['access_control', 'branch_context', 'notifications', 'file_storage'],
            'ci_jobs' => ['critical_test_gate', 'selective_module_gate'],
            'escalate_full_suite' => false,
        ],

        'inventory' => [
            'path_globs' => [
                'app/Modules/Inventory/*',
                'resources/views/inventory/*',
            ],
            'tests' => ['tests/Feature/Inventory'],
            'filters' => ['Inventory', 'GoodsReceipt', 'PurchaseOrder', 'PurchaseRequest', 'StockTransfer', 'StockOpname'],
            'related' => ['ledger', 'branch_context', 'access_control', 'procurement'],
            'ci_jobs' => ['critical_test_gate', 'selective_module_gate'],
            'escalate_full_suite' => false,
        ],

        'ledger' => [
            'path_globs' => [
                'app/Modules/Inventory/Services/InventoryStockService.php',
                'app/**/InventoryStockService.php',
                'app/**/InventoryMovement*',
            ],
            'tests' => ['tests/Feature/Inventory'],
            'filters' => ['Ledger', 'Stock', 'Movement', 'InventoryStock'],
            'related' => ['inventory', 'branch_context'],
            'ci_jobs' => ['critical_test_gate', 'selective_module_gate'],
            'escalate_full_suite' => false,
        ],

        'procurement' => [
            'path_globs' => [
                'app/Modules/Inventory/Services/PurchaseOrderService.php',
                'app/Modules/Inventory/Services/PurchaseRequestService.php',
                'app/Modules/Inventory/Services/GoodsReceiptService.php',
            ],
            'tests' => ['tests/Feature/Inventory'],
            'filters' => ['PurchaseOrder', 'PurchaseRequest', 'GoodsReceipt', 'Procurement'],
            'related' => ['inventory', 'ledger', 'access_control'],
            'ci_jobs' => ['critical_test_gate', 'selective_module_gate'],
            'escalate_full_suite' => false,
        ],

        'reporting' => [
            'path_globs' => [
                'app/Modules/Reporting/*',
                'app/Modules/RmeInvoice/Services/*ReportService.php',
                'resources/views/*/reports/*',
                'resources/views/rme/reports/*',
            ],
            'tests' => ['tests/Feature/RME', 'tests/Feature/Owner'],
            'filters' => ['Report', 'DoctorPerformance', 'OwnerKpi', 'OwnerDashboard'],
            'related' => ['rme', 'inventory', 'access_control'],
            'ci_jobs' => ['selective_module_gate'],
            'escalate_full_suite' => false,
        ],

        'ui_navigation' => [
            'path_globs' => [
                'resources/views/components/*',
                'resources/views/layouts/*',
                'resources/css/*',
                'resources/js/*',
                'tailwind.config.js',
                'app/Console/Commands/ArchitectureUiGovernanceCheckCommand.php',
            ],
            'tests' => ['tests/Feature/Ui'],
            'filters' => ['Ui', 'Sidebar', 'Navigation'],
            'related' => [],
            'ci_jobs' => ['selective_module_gate'],
            'escalate_full_suite' => false,
        ],

        'notifications' => [
            'path_globs' => [
                'app/*Notification*',
                'app/*/Notifications/*',
            ],
            'tests' => ['tests/Feature/Notifications', 'tests/Feature/LabWorkflow'],
            'filters' => ['Notification', 'LabWorkflowNotification'],
            'related' => ['access_control', 'lab_workflow'],
            'ci_jobs' => ['selective_module_gate'],
            'escalate_full_suite' => false,
        ],

        'file_storage' => [
            'path_globs' => [
                'app/*Evidence*',
                'app/*ImageOptimizer*',
                'config/lab_workflow_uploads.php',
                'config/object_storage.php',
                'config/filesystems.php',
            ],
            'tests' => ['tests/Feature/LabWorkflow'],
            'filters' => ['Evidence', 'Upload', 'Compression', 'Storage'],
            'related' => ['lab_workflow', 'security'],
            'ci_jobs' => ['selective_module_gate'],
            'escalate_full_suite' => false,
        ],

        'state_machine' => [
            'path_globs' => [
                'app/*StateMachine*',
                'app/*WorkflowState*',
            ],
            'tests' => ['tests/Feature/LabWorkflow'],
            'filters' => ['StateMachine', 'WorkflowState', 'Transition'],
            'related' => ['lab_workflow', 'access_control'],
            'ci_jobs' => ['critical_test_gate', 'selective_module_gate'],
            'escalate_full_suite' => true,
        ],

        'deployment' => [
            'path_globs' => [
                'scripts/deploy-vps*.sh',
                'scripts/rollback-vps.sh',
                'scripts/backup-vps.sh',
                'scripts/sprint-release.sh',
                'scripts/release/*',
            ],
            'tests' => ['tests/Feature/Foundation', 'tests/Feature/Architecture'],
            'filters' => ['Deployment', 'DeploymentRollback', 'BackupDr', 'Release'],
            'related' => ['ci_cd', 'foundation'],
            'ci_jobs' => ['critical_test_gate'],
            'escalate_full_suite' => true,
        ],

        'ci_cd' => [
            'path_globs' => [
                '.github/workflows/*',
                'scripts/ci/*',
                'config/ci_runtime_control.php',
                'config/cicd_enterprise_gate.php',
            ],
            'tests' => ['tests/Feature/Cicd', 'tests/Feature/Architecture'],
            'filters' => ['Cicd', 'CiRuntime', 'CicdEnterpriseGate'],
            'related' => ['foundation', 'deployment'],
            'ci_jobs' => ['critical_test_gate'],
            'escalate_full_suite' => true,
        ],

        'database_schema' => [
            'path_globs' => [
                'database/migrations/*',
            ],
            'tests' => ['tests/Feature/Foundation'],
            'filters' => ['Migration', 'Schema', 'ReleaseSafety'],
            'related' => ['foundation', 'deployment'],
            'ci_jobs' => ['critical_test_gate', 'selective_module_gate'],
            'escalate_full_suite' => true,
        ],

        'security' => [
            'path_globs' => [
                'app/Support/Security/*',
                'app/Http/Middleware/*',
                'config/security_compliance.php',
            ],
            'tests' => ['tests/Feature/AccessControl', 'tests/Feature/Architecture'],
            'filters' => ['Security', 'SecurityCompliance', 'Middleware'],
            'related' => ['access_control', 'auth', 'branch_context'],
            'ci_jobs' => ['critical_test_gate'],
            'escalate_full_suite' => true,
        ],

        'config' => [
            'path_globs' => [
                'config/*.php',
                'bootstrap/app.php',
            ],
            'tests' => ['tests/Feature/Foundation', 'tests/Feature/Architecture'],
            'filters' => ['Config', 'Foundation'],
            'related' => ['foundation'],
            'ci_jobs' => ['critical_test_gate'],
            'escalate_full_suite' => true,
        ],

        'dependencies' => [
            'path_globs' => [
                'composer.json',
                'composer.lock',
                'package.json',
                'package-lock.json',
            ],
            'tests' => ['tests/Feature/Foundation'],
            'filters' => ['Foundation'],
            'related' => ['foundation', 'ci_cd'],
            'ci_jobs' => ['critical_test_gate', 'full_suite_gate'],
            'escalate_full_suite' => true,
        ],

        'queue' => [
            'path_globs' => [
                'app/Jobs/*',
                'app/**/Jobs/*',
                'config/queue_governance.php',
                'app/Support/Queue/*',
            ],
            'tests' => ['tests/Feature/Foundation'],
            'filters' => ['Queue', 'QueueRetry', 'Job'],
            'related' => ['foundation'],
            'ci_jobs' => ['critical_test_gate'],
            'escalate_full_suite' => false,
        ],

        'foundation' => [
            'path_globs' => [
                'app/Services/Foundation/*',
                'app/Support/Foundation/*',
                'config/foundation_roadmap.php',
                'config/devflow.php',
                'config/sprint_profiles.php',
                'config/sprint_regression_matrix.php',
                'config/shared_foundations.php',
            ],
            'tests' => ['tests/Feature/Foundation', 'tests/Feature/Architecture'],
            'filters' => ['Foundation', 'Roadmap', 'Devflow', 'Sprint'],
            'related' => ['ci_cd', 'deployment'],
            'ci_jobs' => ['critical_test_gate'],
            'escalate_full_suite' => false,
        ],

        'shared_foundation' => [
            'path_globs' => [
                'app/Support/Devflow/*',
                'app/Console/Commands/Sprint*',
                'config/shared_foundations.php',
            ],
            'tests' => ['tests/Feature/Foundation'],
            'filters' => ['Sprint', 'SharedFoundation', 'Devflow'],
            'related' => ['foundation', 'ci_cd'],
            'ci_jobs' => ['critical_test_gate', 'full_suite_gate'],
            'escalate_full_suite' => true,
        ],
    ],
];
