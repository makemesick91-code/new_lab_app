<?php

use App\Modules\LabOrder\Workflow\LabWorkflowState as S;

/**
 * LAB-WORKFLOW-V2 — workflow governance config.
 *
 * The active-workflow decision for NEW orders is NOT read directly from here;
 * it is resolved by App\Modules\LabOrder\Services\LabWorkflowResolver via the
 * `lab.workflow_v2` feature flag (config/feature_flags.php), so behavior is
 * only ever switched through an explicit service branch (NSF-9 rule).
 *
 * This file holds the durable version discriminator values and the
 * transition -> required-permission map consumed by the state machine.
 */
return [

    // Workflow version discriminator stored on trx_lab_orders.workflow_version.
    'version' => [
        'legacy' => 1,
        'v2' => 2,
    ],

    // The feature flag that activates V2 for new orders (default OFF).
    'v2_feature_flag' => 'lab.workflow_v2',

    // Server-side permission required to move an order INTO the given target
    // state. Phase 1 maps to existing Lab permissions so the state machine is
    // enforceable today; later phases refine these per dedicated role. Any
    // target not listed falls back to `default_permission`.
    'default_permission' => 'manage_lab_orders',

    // Targets whose transition must be performed from the order's own branch
    // (branch-actor actions). Courier / lab-side transitions are inherently
    // cross-branch (transport + central lab) and are ownership-guarded via the
    // pickup/delivery task assignment instead.
    'branch_scoped_transitions' => [
        S::WAITING_PICKUP,
    ],

    'transition_permissions' => [
        // Branch (Cabang) nurse
        S::WAITING_PICKUP => 'create_lab_branch_requests',

        // Courier pickup leg
        S::PICKUP_ACCEPTED => 'manage_lab_pickups',
        S::PICKED_UP => 'manage_lab_pickups',
        S::IN_TRANSIT_TO_LAB => 'manage_lab_pickups',

        // Lab receive / input / analysis (Admin Lab)
        S::RECEIVED_AT_LAB => 'manage_lab_orders',
        S::MODEL_REGISTERED => 'manage_lab_orders',
        S::MODEL_ANALYSIS_PENDING => 'manage_lab_orders',
        S::INTERNAL_APPROVED => 'manage_lab_orders',
        S::EXTERNAL_LAB_REQUIRED => 'manage_lab_orders',

        // Internal production. A production step start-state can also be
        // entered by the QC actor as the rework target of a QC-fail decision
        // (reject_qc) — route middleware still restricts the direct step
        // endpoints to production actors, and the production service enforces
        // assignment ownership on top.
        S::TECHNICIAN_ASSIGNMENT_PENDING => 'assign_technicians',
        S::TECHNICIAN_ASSIGNED => 'assign_technicians',
        S::STEP_1_BLOCKOUT_DUPLICATE => ['start_production_work', 'reject_qc'],
        S::STEP_1_COMPLETED => 'complete_production_work',
        S::STEP_2_TEETH_SETUP => ['start_production_work', 'reject_qc'],
        S::STEP_2_COMPLETED => 'complete_production_work',
        S::STEP_3_PROCESSING => ['start_production_work', 'reject_qc'],
        S::STEP_3_COMPLETED => 'complete_production_work',
        S::STEP_4_FITTING_POLISH => ['start_production_work', 'reject_qc'],
        S::STEP_4_COMPLETED => 'complete_production_work',

        // QC & rework
        S::QC_PENDING => 'send_to_qc',
        S::QC_PASSED => 'pass_qc',
        S::QC_FAILED => 'reject_qc',
        S::REWORK_REQUIRED => 'reject_qc',

        // External lab (coordinator = Admin Lab in Phase 1)
        S::EXTERNAL_LAB_PREPARATION => 'manage_lab_orders',
        S::EXTERNAL_LAB_SENT => 'manage_lab_orders',
        S::EXTERNAL_LAB_IN_PROGRESS => 'manage_lab_orders',
        S::EXTERNAL_LAB_RETURNED => 'manage_lab_orders',
        S::EXTERNAL_LAB_RESULT_REVIEW => 'manage_lab_orders',

        // Model done -> delivery. MODEL_DONE is reached from a QC pass
        // (pass_qc) or an accepted external result review (manage_lab_orders).
        S::MODEL_DONE => ['pass_qc', 'manage_lab_orders'],
        S::DELIVERY_PENDING => 'create_delivery',
        S::COURIER_NOTIFIED => 'create_delivery',
        S::DELIVERY_ACCEPTED => 'start_delivery',
        S::LAB_HANDOVER_PENDING => 'start_delivery',
        S::PRE_DELIVERY_PHOTO_CAPTURED => 'start_delivery',
        S::COURIER_SIGNATURE_CAPTURED => 'start_delivery',
        S::READY_FOR_TRANSIT_TO_BRANCH => 'start_delivery',
        S::IN_TRANSIT_TO_BRANCH => 'start_delivery',
        S::ARRIVED_AT_BRANCH => 'mark_delivered',
        S::RECIPIENT_SIGNATURE_CAPTURED => 'mark_delivered',
        S::DELIVERY_LOCATION_PHOTO_CAPTURED => 'mark_delivered',
        S::DELIVERED => 'mark_delivered',

        // Terminal cancel (any authorized lab actor with cancel permission)
        S::CANCELLED => 'cancel_lab_orders',
    ],
];
