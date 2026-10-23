<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Modules\RmeInvoice\Models\RmeBillingDispute;

/**
 * Rule 203 — the audit vocabulary of the billing dispute lifecycle.
 *
 * Lifecycle rows are written INSIDE the transaction that writes the fact, so a
 * rolled-back transition leaves no row. A REFUSED attempt (unusable actor,
 * missing permission, out-of-scope branch, self-resolution) is written AFTER the
 * rollback, because it records an attempt. A failed escalation notification is
 * written after commit: the dispute and its payment hold stand regardless.
 *
 * PRIVACY: ids, numbers, amounts, statuses, codes and counts only. Free text
 * (description, note, resolution reason) is recorded by LENGTH — an operator
 * may have typed a patient's name into it. Never a patient name, NIK, medical
 * record number or clinical content.
 */
final class RmeBillingDisputeAuditEvent
{
    public const ENTITY_DISPUTE = 'trx_rme_billing_disputes';

    public const ACTION_REPORTED = 'RME_BILLING_DISPUTE_REPORTED';

    public const ACTION_REVIEW_STARTED = 'RME_BILLING_DISPUTE_REVIEW_STARTED';

    public const ACTION_ESCALATED = 'RME_BILLING_DISPUTE_ESCALATED';

    public const ACTION_NOTE_ADDED = 'RME_BILLING_DISPUTE_NOTE_ADDED';

    public const ACTION_RESOLVED = 'RME_BILLING_DISPUTE_RESOLVED';

    public const ACTION_REFUSED = 'RME_BILLING_DISPUTE_REFUSED';

    /** No reviewer could be resolved for the branch when the dispute was reported (hold stands). */
    public const ACTION_ESCALATION_UNROUTED = 'RME_BILLING_DISPUTE_ESCALATION_UNROUTED';

    /** The in-app escalation notification could not be delivered (hold stands). */
    public const ACTION_NOTIFICATION_FAILED = 'RME_BILLING_DISPUTE_NOTIFICATION_FAILED';

    /** event_type => audit action. */
    public const EVENT_ACTIONS = [
        'REPORTED' => self::ACTION_REPORTED,
        'REVIEW_STARTED' => self::ACTION_REVIEW_STARTED,
        'ESCALATED' => self::ACTION_ESCALATED,
        'NOTE_ADDED' => self::ACTION_NOTE_ADDED,
        'RESOLVED' => self::ACTION_RESOLVED,
    ];

    /**
     * @param  array<string, int|float|string|bool|null>  $extra
     * @return array<string, mixed>
     */
    public static function payload(RmeBillingDispute $dispute, ?string $from, array $extra = []): array
    {
        return array_merge([
            'dispute_id' => (int) $dispute->id,
            'dispute_number' => $dispute->dispute_number !== null ? (string) $dispute->dispute_number : null,
            'rme_invoice_id' => (int) $dispute->rme_invoice_id,
            'branch_id' => (int) $dispute->branch_id,
            'from_status' => $from,
            'to_status' => (string) $dispute->status,
            'reason_category' => (string) $dispute->reason_category,
            'invoice_status_at_report' => (string) $dispute->invoice_status_at_report,
            'invoice_correction_version_at_report' => (int) $dispute->invoice_correction_version_at_report,
            'resolution_outcome' => $dispute->resolution_outcome !== null ? (string) $dispute->resolution_outcome : null,
            'resolution_correction_id' => $dispute->resolution_correction_id !== null ? (int) $dispute->resolution_correction_id : null,
        ], $extra);
    }
}
