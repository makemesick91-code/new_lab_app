<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

/**
 * DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1 — how each row of an owner-approved
 * home-branch matrix is classified.
 *
 * EVERY CLASSIFICATION IS EXPLICIT AND NONE OF THEM IS A DEFAULT. A row that
 * cannot be classified is REFUSED, never assumed assignable: this tool's whole
 * purpose is writing a clinician's permanent branch, and the failure mode worth
 * engineering against is a silent widening — an unreadable id becoming "all
 * doctors", or an unrecognised state becoming "go ahead".
 */
final class DoctorBranchLockBulkAssignmentOutcome
{
    /** UNSET today, owner-approved destination, ready to assign. */
    public const PENDING_ASSIGNMENT = 'PENDING_ASSIGNMENT';

    /**
     * Already locked to exactly the branch the matrix asks for.
     *
     * An idempotent no-op and NOT a failure — it is what a second run of a
     * successful first run must produce for every row.
     */
    public const ALREADY_SATISFIED = 'ALREADY_SATISFIED';

    /**
     * Locked to a DIFFERENT branch than the matrix asks for.
     *
     * Stopped here, deliberately, rather than assigned. Initial assignment and
     * permanent transfer are separate workflows with different approvals and
     * different consequences, and quietly performing the second while the
     * operator asked for the first is how a clinician's branch moves without
     * anybody deciding it should.
     */
    public const TRANSFER_REQUIRED = 'TRANSFER_REQUIRED';

    /** The doctor is online or in an active clinical context right now. */
    public const ONLINE_NEEDS_ACKNOWLEDGEMENT = 'ONLINE_NEEDS_ACKNOWLEDGEMENT';

    /** The row cannot be executed at all. `refusal_reason` says why. */
    public const REFUSED = 'REFUSED';

    /** Written successfully during --apply. */
    public const ASSIGNED = 'ASSIGNED';

    /** Refused at write time, after passing the dry run. Real drift. */
    public const FAILED = 'FAILED';

    public const REASON_DOCTOR_NOT_FOUND = 'doctor_not_found';

    public const REASON_DOCTOR_INACTIVE = 'doctor_record_inactive';

    public const REASON_DOCTOR_NOT_LINKED = 'doctor_record_not_linked';

    public const REASON_BRANCH_NOT_FOUND = 'branch_code_not_found';

    public const REASON_BRANCH_INACTIVE = 'branch_inactive';

    public const REASON_BRANCH_NOT_RME = 'branch_not_rme_enabled';

    public const REASON_BRANCH_NOT_IN_PRACTICE_SET = 'branch_outside_doctor_practice_set';

    public const REASON_MALFORMED_ENTRY = 'malformed_matrix_entry';

    public const REASON_DUPLICATE_DOCTOR = 'doctor_named_more_than_once';

    /** Nothing in this list may be written without an explicit apply. */
    public const WRITABLE = [
        self::PENDING_ASSIGNMENT,
    ];
}
