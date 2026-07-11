<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DEVFLOW-1 — Shared Foundation Registry
|--------------------------------------------------------------------------
|
| Canonical registry of the reusable services/resolvers every sprint should
| REUSE rather than reinvent. `foundation:shared-service-audit` reads this to
| verify each canonical class exists, has a test reference, and that no new
| ad-hoc duplicate has been introduced.
|
| This registry documents existing canonical implementations — DEVFLOW-1
| deliberately does NOT refactor modules. Each entry maps a concern to its
| single canonical class, a forbidden alternative pattern, and a test that
| exercises it. Add to this registry when a new cross-sprint concern gains a
| canonical implementation.
|
| Status:
|   canonical  — the entry has a verified single implementation to reuse
|   advisory   — a recommended pattern, not a single class (documentation)
|
*/

return [

    'version' => 1,

    'registry' => [

        'branch_context' => [
            'concern' => 'Resolving the active branch for the current actor.',
            'canonical_class' => 'App\\Modules\\Branch\\Services\\BranchContext',
            'interface' => null,
            'module_owner' => 'Branch',
            'status' => 'canonical',
            'usage' => 'BranchContext::requireId() / ::id() / ::forUser($user)',
            'forbidden' => [
                'Trusting a request-supplied branch_id',
                'Re-deriving the active branch inline inside a controller/service',
            ],
            'test_reference' => 'tests/Feature/Branch',
        ],

        'permission_resolution' => [
            'concern' => 'Checking EFFECTIVE permissions (role + Gate::before + direct).',
            'canonical_class' => 'Illuminate\\Contracts\\Auth\\Access\\Gate',
            'interface' => null,
            'module_owner' => 'AccessControl',
            'status' => 'advisory',
            'usage' => '$user->can()/canAny() or Gate::allows(); audits use getAllPermissions()/getRoleNames()',
            'forbidden' => [
                'Direct-only whereHas(\'permissions\') audits (misses role perms + Gate::before)',
                'Sidebar visibility used as an authorization boundary',
            ],
            'test_reference' => 'tests/Feature/AccessControl',
        ],

        'notification_destination' => [
            'concern' => 'Computing a per-recipient in-app notification destination URL.',
            'canonical_class' => 'App\\Modules\\LabOrder\\Support\\LabWorkflowNotificationDestinationResolver',
            'interface' => null,
            'module_owner' => 'LabOrder',
            'status' => 'canonical',
            'usage' => 'Resolve destination per recipient; return internal named-route URL or null.',
            'forbidden' => [
                'Ad-hoc route() generation for a mixed-audience notification inside a service',
                'Storing one URL for a mixed-permission audience',
            ],
            'test_reference' => 'tests/Feature/LabWorkflow/LabWorkflowNotificationDestinationRoutingTest.php',
        ],

        'image_compression' => [
            'concern' => 'Server-side image validation + compression for private evidence.',
            'canonical_class' => 'App\\Modules\\LabOrder\\Services\\LabEvidenceImageOptimizer',
            'interface' => null,
            'module_owner' => 'LabOrder',
            'status' => 'canonical',
            'usage' => 'Route uploads through the evidence service -> optimizer (GD, EXIF strip, hard caps).',
            'forbidden' => [
                'Controller-level image resize',
                'Storing the raw uploaded original for large images',
            ],
            'test_reference' => 'tests/Feature/LabWorkflow/LabWorkflowUploadCompressionTest.php',
        ],

        'private_evidence_storage' => [
            'concern' => 'Storing typed, private, checksummed workflow evidence.',
            'canonical_class' => 'App\\Modules\\LabOrder\\Models\\LabWorkflowEvidence',
            'interface' => null,
            'module_owner' => 'LabOrder',
            'status' => 'canonical',
            'usage' => 'Private disk + sha256 + soft-delete-only; serve via policy-guarded route.',
            'forbidden' => [
                'Public disk for audit evidence',
                'Hard-deleting audit evidence',
            ],
            'test_reference' => 'tests/Feature/LabWorkflow',
        ],

        'pdf_generation' => [
            'concern' => 'Server-side table-based PDF generation (dompdf).',
            'canonical_class' => 'Barryvdh\\DomPDF\\Facade\\Pdf',
            'interface' => null,
            'module_owner' => 'Shared',
            'status' => 'advisory',
            'usage' => 'dompdf with table-based Blade templates; never flexbox in PDF views.',
            'forbidden' => [
                'A second PDF library',
                'Flexbox layout inside a dompdf template',
            ],
            'test_reference' => 'tests/Feature/Inventory',
        ],

        'audit_log' => [
            'concern' => 'Append-only structured audit logging.',
            'canonical_class' => 'App\\Modules\\LabOrder\\Services\\AuditLogService',
            'interface' => null,
            'module_owner' => 'Shared',
            'status' => 'advisory',
            'usage' => 'Write scalar-only metadata to sys_audit_logs; never PII/binary.',
            'forbidden' => [
                'Logging KTP/NIK or raw payloads into audit metadata',
            ],
            'test_reference' => 'tests/Feature/DeveloperConsole',
        ],

        'workflow_state_machine' => [
            'concern' => 'Guarded, logged, idempotent workflow transitions.',
            'canonical_class' => 'App\\Modules\\LabOrder\\Services\\LabWorkflowStateMachine',
            'interface' => null,
            'module_owner' => 'LabOrder',
            'status' => 'canonical',
            'usage' => 'transition() with matrix + permission map + lockForUpdate + append-only log.',
            'forbidden' => [
                'Updating a workflow status directly from a request',
                'A transition edge that bypasses the matrix',
            ],
            'test_reference' => 'tests/Feature/LabWorkflow',
        ],

        'assignment_eligibility' => [
            'concern' => 'Deciding whether a target user may be assigned work.',
            'canonical_class' => 'App\\Modules\\Technician\\Services\\TechnicianAssignmentEligibility',
            'interface' => null,
            'module_owner' => 'Technician',
            'status' => 'canonical',
            'usage' => 'Single source of truth for assignable targets; re-assert crafted ids server-side.',
            'forbidden' => [
                'Treating an operator permission as a target qualification',
                'Trusting a request-supplied technician_id without re-validation',
            ],
            'test_reference' => 'tests/Feature/LabWorkflow/LabTechnicianAssignmentEligibilityTest.php',
        ],

        'sensitive_value_masking' => [
            'concern' => 'Masking secrets/PII before display or logging.',
            'canonical_class' => 'App\\Support\\DeveloperConsole\\SensitiveValueMasker',
            'interface' => null,
            'module_owner' => 'DeveloperConsole',
            'status' => 'canonical',
            'usage' => 'Mask 13+ digit runs, credentials/tokens, emails before rendering free text.',
            'forbidden' => [
                'Rendering unmasked KTP/NIK/secret in diagnostics or evidence',
            ],
            'test_reference' => 'tests/Feature/Foundation/FoundationMonitoringSanitizationTest.php',
        ],
    ],

    // Ad-hoc patterns whose appearance in NEW code is a WATCH signal for the
    // shared-service audit. Kept here (config, not app/) so the scanner never
    // carries the literal patterns inline.
    'forbidden_new_patterns' => [
        'controller_image_resize' => 'imagecreatefrom',       // in a Controller = WATCH
        'request_branch_id_trust' => 'request(',              // combined with branch_id = advisory
    ],
];
