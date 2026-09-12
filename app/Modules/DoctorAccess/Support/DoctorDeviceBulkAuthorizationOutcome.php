<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

use App\Modules\DoctorDevice\Services\DoctorDeviceAuthorizationService;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 PR-C — the vocabulary of a bulk
 * device-authorization run.
 *
 * A final class of `const` strings rather than a native `enum`, matching
 * {@see DoctorBranchCoverStatus} and {@see DoctorBranchLockRequestStatus}: there
 * is no PHP enum anywhere in `app/`, and a first one introduced by a console
 * tool would be a convention fork nobody asked for.
 *
 * TWO VOCABULARIES, KEPT APART ON PURPOSE.
 *
 *   PLAN buckets say what the estate LOOKS LIKE. They are computed from reads
 *   alone and are what a dry run prints.
 *
 *   APPLY outcomes say what ACTUALLY HAPPENED to each pair. They exist because
 *   the estate can drift between the preview and the write, and a run whose
 *   plan and result disagree must be able to say so precisely rather than
 *   reporting the plan a second time and calling it a result.
 *
 * Collapsing the two would make "proposed" and "done" the same word, which is
 * exactly the ambiguity that lets a partially-applied run read as a clean one.
 */
final class DoctorDeviceBulkAuthorizationOutcome
{
    /*
    |--------------------------------------------------------------------------
    | Plan buckets — what a dry run classifies every matrix cell into
    |--------------------------------------------------------------------------
    |
    | Every (eligible doctor x eligible device) cell lands in exactly one of
    | these. F and G are not cells at all: they are the reasons a doctor or a
    | device never entered the matrix, carried here so one vocabulary covers the
    | whole report.
    */

    /** A: the pair already has its single ACTIVE authorization. Nothing to do. */
    public const BUCKET_ALREADY_ACTIVE = 'already_active';

    /** B: no row exists for the pair. Create PENDING, then approve. */
    public const BUCKET_CREATE = 'create';

    /** C: a PENDING row exists — typically a doctor's own login request. Approve only. */
    public const BUCKET_ADOPT_PENDING = 'adopt_pending';

    /**
     * D: a REJECTED row exists. PROPOSE NOTHING.
     *
     * Re-opening one is a privileged human act with its own route and its own
     * audit event. A synchroniser that quietly undid a refusal would make the
     * refusal meaningless.
     */
    public const BUCKET_BLOCKED_REJECTED = 'blocked_rejected';

    /**
     * E: a REVOKED row exists. PROPOSE NOTHING.
     *
     * Terminal by design — {@see DoctorDeviceAuthorizationService::approve()}
     * refuses it outright. Withdrawn trust is never restored by a bulk tool.
     */
    public const BUCKET_BLOCKED_REVOKED = 'blocked_revoked';

    /** F: the doctor failed an eligibility predicate, so none of their pairs exist. */
    public const BUCKET_INELIGIBLE_DOCTOR = 'ineligible_doctor';

    /** G: the device failed an eligibility predicate, so none of its pairs exist. */
    public const BUCKET_INELIGIBLE_DEVICE = 'ineligible_device';

    /*
    |--------------------------------------------------------------------------
    | Apply outcomes — what actually happened, per pair
    |--------------------------------------------------------------------------
    */

    /** Created PENDING then approved to ACTIVE. The bucket-B happy path. */
    public const APPLIED_CREATED = 'applied_created';

    /** An existing PENDING row was approved. No row was created. */
    public const APPLIED_APPROVED_EXISTING = 'applied_approved_existing';

    /** Already ACTIVE when the write ran. The idempotent no-op. */
    public const SKIPPED_ALREADY_ACTIVE = 'skipped_already_active';

    /**
     * Refused under the lock because the device is no longer ACTIVE.
     *
     * THIS IS THE GUARD THAT KEEPS PR-C OUT OF THE DEVICE TABLE. `approve()`
     * promotes a `pending_approval` device to `active`; asserting ACTIVE under
     * the same row lock `approve()` will re-acquire makes that branch provably
     * unreachable instead of merely unlikely.
     */
    public const REFUSED_DEVICE_NOT_ACTIVE = 'refused_device_not_active';

    /** Refused under the lock because the device lost its cryptographic identity. */
    public const REFUSED_DEVICE_IDENTITY_UNVERIFIED = 'refused_device_identity_unverified';

    /** Refused under the lock because the doctor was deactivated after the plan was built. */
    public const REFUSED_DOCTOR_INACTIVE = 'refused_doctor_inactive';

    /**
     * Refused because the row stopped being PENDING between create and approve.
     *
     * A concurrent operator rejecting or revoking the same pair wins; this run
     * reports the loss rather than overriding a human decision.
     */
    public const REFUSED_RACED_TO_NON_PENDING = 'refused_raced_to_non_pending';

    /*
    |--------------------------------------------------------------------------
    | Exclusion reason codes
    |--------------------------------------------------------------------------
    |
    | The three that already exist are REUSED from the readiness engine rather
    | than restated, so the two surfaces cannot drift into describing the same
    | estate with different words.
    */

    /**
     * The account is switched off even though its doctor record is not.
     *
     * PR-C's OWN CODE, with no readiness equivalent, and that is the point.
     * `doctorAccounts()` does not filter `users.is_active` and the readiness
     * engine only inspects `mst_doctors.is_active`, so a deactivated user with
     * an active doctor record reports READY today. Provisioning a tablet for an
     * account that cannot authenticate is noise; naming the exclusion closes a
     * documented blind spot instead of inheriting it.
     */
    public const REASON_USER_ACCOUNT_INACTIVE = 'user_account_inactive';

    /** The device is registered but no human has admitted the hardware yet. */
    public const REASON_DEVICE_PENDING_APPROVAL = 'device_pending_approval';

    /** The device is temporarily withdrawn. Reactivate it first, deliberately. */
    public const REASON_DEVICE_DISABLED = 'device_disabled';

    /** The device is permanently withdrawn. Terminal. */
    public const REASON_DEVICE_REVOKED = 'device_revoked';
}
