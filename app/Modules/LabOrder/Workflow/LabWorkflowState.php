<?php

namespace App\Modules\LabOrder\Workflow;

/**
 * LAB-WORKFLOW-V2 — canonical V2 state vocabulary + transition matrix.
 *
 * Single source of truth for the new Lab Workflow V2 status strings and the
 * legal transitions between them. Consumed by LabWorkflowStateMachine and the
 * role workspaces built in later phases. Legacy (workflow_version = 1) orders
 * do NOT use these states — they keep the Sprint 3-7 LabOrder::STATUS_* set.
 *
 * The matrix is intentionally strict and mostly linear: each stage advances to
 * the next canonical stage (or CANCELLED). Evidence-gated milestones
 * (pre-delivery photo, courier signature, recipient signature, delivery proof)
 * are modelled as explicit states so a downstream stage cannot be reached
 * without the enabling milestone having been recorded first. The concrete
 * evidence-record existence checks are enforced by the state machine's
 * prerequisite guards in the delivery phase.
 */
final class LabWorkflowState
{
    // --- Branch (Cabang) ---
    public const DRAFT = 'DRAFT';

    public const WAITING_PICKUP = 'WAITING_PICKUP';

    // --- Courier pickup ---
    public const PICKUP_ACCEPTED = 'PICKUP_ACCEPTED';

    public const PICKED_UP = 'PICKED_UP';

    public const IN_TRANSIT_TO_LAB = 'IN_TRANSIT_TO_LAB';

    public const RECEIVED_AT_LAB = 'RECEIVED_AT_LAB';

    // --- Lab input & analysis ---
    public const MODEL_REGISTERED = 'MODEL_REGISTERED';

    public const MODEL_ANALYSIS_PENDING = 'MODEL_ANALYSIS_PENDING';

    public const INTERNAL_APPROVED = 'INTERNAL_APPROVED';

    public const EXTERNAL_LAB_REQUIRED = 'EXTERNAL_LAB_REQUIRED';

    // --- Internal production ---
    public const TECHNICIAN_ASSIGNMENT_PENDING = 'TECHNICIAN_ASSIGNMENT_PENDING';

    public const TECHNICIAN_ASSIGNED = 'TECHNICIAN_ASSIGNED';

    public const STEP_1_BLOCKOUT_DUPLICATE = 'STEP_1_BLOCKOUT_DUPLICATE';

    public const STEP_1_COMPLETED = 'STEP_1_COMPLETED';

    public const STEP_2_TEETH_SETUP = 'STEP_2_TEETH_SETUP';

    public const STEP_2_COMPLETED = 'STEP_2_COMPLETED';

    public const STEP_3_PROCESSING = 'STEP_3_PROCESSING';

    public const STEP_3_COMPLETED = 'STEP_3_COMPLETED';

    public const STEP_4_FITTING_POLISH = 'STEP_4_FITTING_POLISH';

    public const STEP_4_COMPLETED = 'STEP_4_COMPLETED';

    // --- Quality control & rework ---
    public const QC_PENDING = 'QC_PENDING';

    public const QC_PASSED = 'QC_PASSED';

    public const QC_FAILED = 'QC_FAILED';

    public const REWORK_REQUIRED = 'REWORK_REQUIRED';

    // --- External lab ---
    public const EXTERNAL_LAB_PREPARATION = 'EXTERNAL_LAB_PREPARATION';

    public const EXTERNAL_LAB_SENT = 'EXTERNAL_LAB_SENT';

    public const EXTERNAL_LAB_IN_PROGRESS = 'EXTERNAL_LAB_IN_PROGRESS';

    public const EXTERNAL_LAB_RETURNED = 'EXTERNAL_LAB_RETURNED';

    public const EXTERNAL_LAB_RESULT_REVIEW = 'EXTERNAL_LAB_RESULT_REVIEW';

    // --- Model done & delivery ---
    public const MODEL_DONE = 'MODEL_DONE';

    public const DELIVERY_PENDING = 'DELIVERY_PENDING';

    public const COURIER_NOTIFIED = 'COURIER_NOTIFIED';

    public const DELIVERY_ACCEPTED = 'DELIVERY_ACCEPTED';

    public const LAB_HANDOVER_PENDING = 'LAB_HANDOVER_PENDING';

    public const PRE_DELIVERY_PHOTO_CAPTURED = 'PRE_DELIVERY_PHOTO_CAPTURED';

    public const COURIER_SIGNATURE_CAPTURED = 'COURIER_SIGNATURE_CAPTURED';

    public const READY_FOR_TRANSIT_TO_BRANCH = 'READY_FOR_TRANSIT_TO_BRANCH';

    public const IN_TRANSIT_TO_BRANCH = 'IN_TRANSIT_TO_BRANCH';

    public const ARRIVED_AT_BRANCH = 'ARRIVED_AT_BRANCH';

    public const RECIPIENT_SIGNATURE_CAPTURED = 'RECIPIENT_SIGNATURE_CAPTURED';

    public const DELIVERY_LOCATION_PHOTO_CAPTURED = 'DELIVERY_LOCATION_PHOTO_CAPTURED';

    public const DELIVERED = 'DELIVERED';

    // --- Terminal (shared) ---
    public const CANCELLED = 'CANCELLED';

    /** Initial state for a new V2 order. */
    public const INITIAL = self::DRAFT;

    /** States from which no further transition is allowed. */
    public const TERMINAL = [
        self::DELIVERED,
        self::CANCELLED,
    ];

    /**
     * The default rework target when QC fails, per the owner's diagram.
     * The QC actor may choose a different explicit target (see MATRIX rework row).
     */
    public const DEFAULT_REWORK_TARGET = self::STEP_2_TEETH_SETUP;

    /**
     * Internal production steps in canonical order: start-state => completed-state.
     * Also used as the V2 step template rows in trx_lab_production_steps
     * (step_name = the start-state string).
     *
     * @var array<string, string>
     */
    public const V2_PRODUCTION_STEPS = [
        self::STEP_1_BLOCKOUT_DUPLICATE => self::STEP_1_COMPLETED,
        self::STEP_2_TEETH_SETUP => self::STEP_2_COMPLETED,
        self::STEP_3_PROCESSING => self::STEP_3_COMPLETED,
        self::STEP_4_FITTING_POLISH => self::STEP_4_COMPLETED,
    ];

    /** Valid QC rework targets (production step start-states). */
    public const REWORK_TARGETS = [
        self::STEP_1_BLOCKOUT_DUPLICATE,
        self::STEP_2_TEETH_SETUP,
        self::STEP_3_PROCESSING,
        self::STEP_4_FITTING_POLISH,
    ];

    /**
     * Canonical transition matrix: from-state => [allowed to-states].
     * Absence of a key (or an empty array) means terminal / no outgoing edge.
     *
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        return [
            self::DRAFT => [self::WAITING_PICKUP, self::CANCELLED],
            self::WAITING_PICKUP => [self::PICKUP_ACCEPTED, self::CANCELLED],

            self::PICKUP_ACCEPTED => [self::PICKED_UP, self::CANCELLED],
            self::PICKED_UP => [self::IN_TRANSIT_TO_LAB, self::CANCELLED],
            self::IN_TRANSIT_TO_LAB => [self::RECEIVED_AT_LAB, self::CANCELLED],
            self::RECEIVED_AT_LAB => [self::MODEL_REGISTERED, self::CANCELLED],

            self::MODEL_REGISTERED => [self::MODEL_ANALYSIS_PENDING, self::CANCELLED],
            self::MODEL_ANALYSIS_PENDING => [self::INTERNAL_APPROVED, self::EXTERNAL_LAB_REQUIRED, self::CANCELLED],

            // Internal production
            self::INTERNAL_APPROVED => [self::TECHNICIAN_ASSIGNMENT_PENDING, self::CANCELLED],
            self::TECHNICIAN_ASSIGNMENT_PENDING => [self::TECHNICIAN_ASSIGNED, self::CANCELLED],
            self::TECHNICIAN_ASSIGNED => [self::STEP_1_BLOCKOUT_DUPLICATE, self::CANCELLED],
            self::STEP_1_BLOCKOUT_DUPLICATE => [self::STEP_1_COMPLETED, self::CANCELLED],
            self::STEP_1_COMPLETED => [self::STEP_2_TEETH_SETUP, self::CANCELLED],
            self::STEP_2_TEETH_SETUP => [self::STEP_2_COMPLETED, self::CANCELLED],
            self::STEP_2_COMPLETED => [self::STEP_3_PROCESSING, self::CANCELLED],
            self::STEP_3_PROCESSING => [self::STEP_3_COMPLETED, self::CANCELLED],
            self::STEP_3_COMPLETED => [self::STEP_4_FITTING_POLISH, self::CANCELLED],
            self::STEP_4_FITTING_POLISH => [self::STEP_4_COMPLETED, self::CANCELLED],
            self::STEP_4_COMPLETED => [self::QC_PENDING, self::CANCELLED],

            // QC & rework
            self::QC_PENDING => [self::QC_PASSED, self::QC_FAILED, self::CANCELLED],
            self::QC_PASSED => [self::MODEL_DONE, self::CANCELLED],
            self::QC_FAILED => [self::REWORK_REQUIRED, self::CANCELLED],
            // Rework returns to an explicit production step (default STEP_2), then re-enters QC.
            self::REWORK_REQUIRED => [
                self::STEP_1_BLOCKOUT_DUPLICATE,
                self::STEP_2_TEETH_SETUP,
                self::STEP_3_PROCESSING,
                self::STEP_4_FITTING_POLISH,
                self::QC_PENDING,
                self::CANCELLED,
            ],

            // External lab
            self::EXTERNAL_LAB_REQUIRED => [self::EXTERNAL_LAB_PREPARATION, self::CANCELLED],
            self::EXTERNAL_LAB_PREPARATION => [self::EXTERNAL_LAB_SENT, self::CANCELLED],
            self::EXTERNAL_LAB_SENT => [self::EXTERNAL_LAB_IN_PROGRESS, self::CANCELLED],
            self::EXTERNAL_LAB_IN_PROGRESS => [self::EXTERNAL_LAB_RETURNED, self::CANCELLED],
            self::EXTERNAL_LAB_RETURNED => [self::EXTERNAL_LAB_RESULT_REVIEW, self::CANCELLED],
            // Result review: accept -> MODEL_DONE, or reject -> resend / internal rework.
            self::EXTERNAL_LAB_RESULT_REVIEW => [
                self::MODEL_DONE,
                self::EXTERNAL_LAB_PREPARATION,
                self::REWORK_REQUIRED,
                self::CANCELLED,
            ],

            // Model done -> delivery
            self::MODEL_DONE => [self::DELIVERY_PENDING, self::CANCELLED],
            self::DELIVERY_PENDING => [self::COURIER_NOTIFIED, self::CANCELLED],
            self::COURIER_NOTIFIED => [self::DELIVERY_ACCEPTED, self::CANCELLED],
            self::DELIVERY_ACCEPTED => [self::LAB_HANDOVER_PENDING, self::CANCELLED],
            self::LAB_HANDOVER_PENDING => [self::PRE_DELIVERY_PHOTO_CAPTURED, self::CANCELLED],
            // Mandatory pre-transit evidence gates (owner requirement):
            self::PRE_DELIVERY_PHOTO_CAPTURED => [self::COURIER_SIGNATURE_CAPTURED, self::CANCELLED],
            self::COURIER_SIGNATURE_CAPTURED => [self::READY_FOR_TRANSIT_TO_BRANCH, self::CANCELLED],
            self::READY_FOR_TRANSIT_TO_BRANCH => [self::IN_TRANSIT_TO_BRANCH, self::CANCELLED],
            // In-transit / arrival: no cancel mid-transit.
            self::IN_TRANSIT_TO_BRANCH => [self::ARRIVED_AT_BRANCH],
            self::ARRIVED_AT_BRANCH => [self::RECIPIENT_SIGNATURE_CAPTURED],
            // Mandatory recipient handover evidence gates:
            self::RECIPIENT_SIGNATURE_CAPTURED => [self::DELIVERY_LOCATION_PHOTO_CAPTURED],
            self::DELIVERY_LOCATION_PHOTO_CAPTURED => [self::DELIVERED],

            // Terminal
            self::DELIVERED => [],
            self::CANCELLED => [],
        ];
    }

    /**
     * All valid V2 states (matrix keys are exhaustive of the vocabulary).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::matrix());
    }

    public static function isValid(string $state): bool
    {
        return array_key_exists($state, self::matrix());
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }

    /**
     * @return list<string>
     */
    public static function allowedFrom(string $state): array
    {
        return self::matrix()[$state] ?? [];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::allowedFrom($from), true);
    }
}
