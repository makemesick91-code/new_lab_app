<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 — the dispute itself.
|--------------------------------------------------------------------------
|
| A billing dispute is a REPORT, not a correction: it records that an invoice
| is known or suspected to be wrong, holds its collection (proven in
| RmeBillingDisputePaymentHoldTest) and routes the case to the branch authority.
| It never changes the invoice. These tests pin the lifecycle — reporting, the
| one-active-per-invoice rule (service AND database), the explicit state
| machine, the evidence each resolution outcome requires, the immutable record
| and timeline, the PII-free audit trail, and in-app escalation that can fail
| without unwinding the dispute or its hold.
*/

use App\Modules\RmeInvoice\Models\RmeBillingDispute;
use App\Modules\RmeInvoice\Models\RmeBillingDisputeEvent;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Notifications\RmeBillingDisputeNotification;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeEscalationService;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeAuditEvent;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeDenied;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeOutcome;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeReason;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
use App\Support\AccessControl\ActiveAccountResolver;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => rbdSetup());

function rbdExpectCode(callable $work, string $code): void
{
    try {
        $work();
    } catch (ValidationException $e) {
        expect(implode(' ', array_merge(...array_values($e->errors()))))->toContain('['.$code.']');

        return;
    }

    test()->fail("Expected [{$code}] to be refused.");
}

function rbdService(): RmeBillingDisputeService
{
    return app(RmeBillingDisputeService::class);
}

// ─── Reporting ──────────────────────────────────────────────────────────────

it('reports a dispute as OPEN with the invoice snapshot, a number, and a REPORTED event', function () {
    $invoice = ricInvoice();

    $dispute = rbdReport($invoice);

    expect($dispute->status)->toBe(RmeBillingDisputeStatus::OPEN)
        ->and($dispute->dispute_number)->toMatch('/^RMEDSP-\d{6}-\d{6}$/')
        ->and((int) $dispute->branch_id)->toBe((int) $invoice->branch_id)
        ->and($dispute->invoice_status_at_report)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and((float) $dispute->invoice_grand_total_at_report)->toBe((float) $invoice->grand_total)
        ->and((float) $dispute->invoice_paid_total_at_report)->toBe(0.0)
        ->and((int) $dispute->reported_by)->toBe((int) test()->cashier->id);

    $events = RmeBillingDisputeEvent::query()->where('rme_billing_dispute_id', $dispute->id)->get();
    expect($events)->toHaveCount(1)
        ->and($events->first()->event_type)->toBe(RmeBillingDisputeEvent::TYPE_REPORTED)
        ->and($events->first()->from_status)->toBeNull()
        ->and($events->first()->to_status)->toBe(RmeBillingDisputeStatus::OPEN);
});

it('changes nothing financial on report, review, escalation, note or resolution', function () {
    $invoice = ricInvoice();
    $before = rbdLedger();

    $dispute = rbdReport($invoice);
    expect(rbdLedger())->toBe($before);

    test()->actingAs(test()->reviewer);
    rbdService()->startReview($dispute, test()->reviewer, 'Mulai dicek oleh penanggung jawab.');
    expect(rbdLedger())->toBe($before);

    rbdService()->addNote($dispute->fresh(), test()->reviewer, 'Pasien sudah pulang, akan dihubungi.');
    expect(rbdLedger())->toBe($before);

    rbdService()->escalate($dispute->fresh(), test()->reviewer, 'Butuh keputusan Owner soal tarif.');
    expect(rbdLedger())->toBe($before);

    rbdConfirmOriginal($dispute->fresh());
    expect(rbdLedger())->toBe($before)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('accepts PARTIAL and PAID invoices, never reverses a payment and never refunds', function () {
    $partial = ricInvoice();
    rbdPay($partial, 100000);
    $paid = ricInvoice();
    rbdPay($paid, (float) $paid->grand_total);
    $before = rbdLedger();

    $d1 = rbdReport($partial);
    $d2 = rbdReport($paid);

    expect($d1->invoice_status_at_report)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and((float) $d1->invoice_paid_total_at_report)->toBe(100000.0)
        ->and($d2->invoice_status_at_report)->toBe(RmeInvoice::STATUS_PAID)
        ->and($paid->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and(rbdLedger())->toBe($before);
});

it('refuses a DRAFT or VOID invoice', function (string $status) {
    $invoice = ricInvoice();
    DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['status' => $status]);

    rbdExpectCode(fn () => rbdReport($invoice), 'BILLING_DISPUTE_INVOICE_STATUS_UNSUPPORTED');
    expect(RmeBillingDispute::query()->count())->toBe(0);
})->with([RmeInvoice::STATUS_DRAFT, RmeInvoice::STATUS_VOID]);

it('validates the reason and the description, and refuses an identity number in free text', function () {
    $invoice = ricInvoice();

    expect(fn () => rbdReport($invoice, null, ['reason_category' => 'WHATEVER']))->toThrow(ValidationException::class)
        ->and(fn () => rbdReport($invoice, null, ['description' => 'pendek']))->toThrow(ValidationException::class)
        ->and(fn () => rbdReport($invoice, null, ['description' => 'NIK pasien 7371234567890123 salah tagih.']))->toThrow(ValidationException::class)
        ->and(RmeBillingDispute::query()->count())->toBe(0);
});

it('keeps at most one active dispute per invoice, and allows a new one once it is resolved', function () {
    $invoice = ricInvoice();
    $first = rbdReport($invoice);

    rbdExpectCode(fn () => rbdReport($invoice), 'BILLING_DISPUTE_DUPLICATE');

    rbdConfirmOriginal($first);
    $second = rbdReport($invoice, null, ['reason_category' => RmeBillingDisputeReason::ITEM_INCORRECT]);

    expect($second->id)->not->toBe($first->id)
        ->and(RmeBillingDispute::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(2);
});

it('enforces one active dispute per invoice in the database itself', function () {
    $invoice = ricInvoice();
    $first = rbdReport($invoice);

    $row = DB::table('trx_rme_billing_disputes')->where('id', $first->id)->first();
    $copy = (array) $row;
    unset($copy['id']);
    $copy['dispute_number'] = null;
    $copy['status'] = RmeBillingDisputeStatus::ESCALATED;

    // A savepoint, so PostgreSQL's aborted-transaction state stays contained.
    expect(fn () => DB::transaction(fn () => DB::table('trx_rme_billing_disputes')->insert($copy)))
        ->toThrow(QueryException::class);

    $copy['status'] = RmeBillingDisputeStatus::RESOLVED;
    $copy['resolution_outcome'] = RmeBillingDisputeOutcome::ORIGINAL_CONFIRMED_CORRECT;
    $copy['resolution_reason'] = 'Riwayat lama yang sudah selesai.';
    $copy['resolved_by'] = test()->reviewer->id;
    $copy['resolved_at'] = now();
    DB::table('trx_rme_billing_disputes')->insert($copy);

    expect(DB::table('trx_rme_billing_disputes')->where('rme_invoice_id', $invoice->id)->count())->toBe(2);
});

// ─── State machine ──────────────────────────────────────────────────────────

it('follows the explicit state machine and refuses every illegal transition', function () {
    $dispute = rbdReport(ricInvoice());
    test()->actingAs(test()->reviewer);

    $dispute = rbdService()->startReview($dispute, test()->reviewer);
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::UNDER_REVIEW);
    rbdExpectCode(fn () => rbdService()->startReview($dispute->fresh(), test()->reviewer), 'BILLING_DISPUTE_INVALID_TRANSITION');

    rbdService()->escalate($dispute->fresh(), test()->reviewer, 'Perlu keputusan Owner.');
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::ESCALATED);
    rbdExpectCode(fn () => rbdService()->startReview($dispute->fresh(), test()->reviewer), 'BILLING_DISPUTE_INVALID_TRANSITION');
    rbdExpectCode(fn () => rbdService()->escalate($dispute->fresh(), test()->reviewer, 'Eskalasi ulang tidak sah.'), 'BILLING_DISPUTE_INVALID_TRANSITION');

    rbdConfirmOriginal($dispute->fresh());
    $resolved = $dispute->fresh();
    expect($resolved->status)->toBe(RmeBillingDisputeStatus::RESOLVED)
        ->and($resolved->isActive())->toBeFalse();

    rbdExpectCode(fn () => rbdConfirmOriginal($resolved), 'BILLING_DISPUTE_INVALID_TRANSITION');
    rbdExpectCode(fn () => rbdService()->addNote($resolved, test()->reviewer, 'Catatan setelah selesai.'), 'BILLING_DISPUTE_INVALID_TRANSITION');
    rbdExpectCode(fn () => rbdService()->escalate($resolved, test()->reviewer, 'Tidak boleh lagi.'), 'BILLING_DISPUTE_INVALID_TRANSITION');

    expect(RmeBillingDisputeEvent::query()->where('rme_billing_dispute_id', $dispute->id)->pluck('event_type')->all())->toBe([
        RmeBillingDisputeEvent::TYPE_REPORTED,
        RmeBillingDisputeEvent::TYPE_REVIEW_STARTED,
        RmeBillingDisputeEvent::TYPE_ESCALATED,
        RmeBillingDisputeEvent::TYPE_RESOLVED,
    ]);
});

it('lets an OPEN dispute be escalated or resolved directly, and requires an escalation reason', function () {
    $dispute = rbdReport(ricInvoice());
    test()->actingAs(test()->reviewer);

    expect(fn () => rbdService()->escalate($dispute, test()->reviewer, ''))->toThrow(ValidationException::class)
        ->and($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);

    rbdService()->escalate($dispute, test()->reviewer, 'Perlu keputusan Owner segera.');
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::ESCALATED);

    $other = rbdReport(ricInvoice());
    rbdConfirmOriginal($other);
    expect($other->fresh()->status)->toBe(RmeBillingDisputeStatus::RESOLVED);
});

it('keeps the report immutable, the resolution terminal, the row undeletable and the timeline append-only', function () {
    $dispute = rbdReport(ricInvoice());

    expect(fn () => $dispute->fresh()->forceFill(['description' => 'Ditulis ulang setelah dilaporkan.'])->save())->toThrow(LogicException::class)
        ->and(fn () => $dispute->fresh()->forceFill(['invoice_grand_total_at_report' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $dispute->fresh()->delete())->toThrow(LogicException::class);

    $resolved = rbdConfirmOriginal($dispute)->fresh();
    expect(fn () => $resolved->forceFill(['status' => RmeBillingDisputeStatus::OPEN])->save())->toThrow(LogicException::class);

    $event = RmeBillingDisputeEvent::query()->where('rme_billing_dispute_id', $dispute->id)->first();
    expect(fn () => $event->forceFill(['note' => 'diubah'])->save())->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class)
        ->and(RmeBillingDispute::query()->find($dispute->id))->not->toBeNull();
});

// ─── Resolution evidence ────────────────────────────────────────────────────

it('requires a reference to confirm the original invoice, and never lets the reporter self-certify it', function () {
    $selfReporter = userWith(['manage_rme_billing', 'review_rme_billing_disputes', 'view_clinic_visits']);
    $dispute = rbdReport(ricInvoice(), $selfReporter);
    test()->actingAs(test()->reviewer);

    expect(fn () => rbdService()->resolve($dispute, test()->reviewer, [
        'outcome' => RmeBillingDisputeOutcome::ORIGINAL_CONFIRMED_CORRECT,
        'resolution_reason' => 'Harga sudah dicek dan benar.',
    ]))->toThrow(ValidationException::class);

    test()->actingAs($selfReporter);
    expect(fn () => rbdConfirmOriginal($dispute, $selfReporter))->toThrow(RmeBillingDisputeDenied::class)
        ->and($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);

    $refused = rbdAudit(RmeBillingDisputeAuditEvent::ACTION_REFUSED)->last();
    expect($refused->new_values['code'])->toBe(RmeBillingDisputeDenied::SELF_RESOLUTION)
        ->and($refused->new_values['attempted_action'])->toBe('resolve');

    $resolved = rbdConfirmOriginal($dispute);
    expect($resolved->fresh()->resolution_reference)->toBe('OWNER-OK/2026-10-24/001')
        ->and((int) $resolved->fresh()->resolved_by)->toBe((int) test()->reviewer->id);
});

it('refuses to confirm the original once the invoice has changed since the report', function () {
    $invoice = ricInvoice();
    $dispute = rbdReport($invoice);
    ricApprove(ricSubmit($invoice)); // applied: the invoice is now version 1

    rbdExpectCode(fn () => rbdConfirmOriginal($dispute), 'BILLING_DISPUTE_INVOICE_CHANGED');
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

it('resolves CORRECTION_APPLIED only from a correction applied after the report, filling the reference itself', function () {
    $invoice = ricInvoice();
    $dispute = rbdReport($invoice);
    test()->actingAs(test()->reviewer);

    rbdExpectCode(fn () => rbdService()->resolve($dispute, test()->reviewer, [
        'outcome' => RmeBillingDisputeOutcome::CORRECTION_APPLIED,
        'resolution_reason' => 'Sudah dikoreksi (padahal belum).',
    ]), 'BILLING_DISPUTE_RESOLUTION_EVIDENCE_MISSING');

    $correction = ricApprove(ricSubmit($invoice));

    test()->actingAs(test()->reviewer);
    $resolved = rbdService()->resolve($dispute->fresh(), test()->reviewer, [
        'outcome' => RmeBillingDisputeOutcome::CORRECTION_APPLIED,
        'resolution_reason' => 'Koreksi harga tambal sudah diterapkan.',
        'resolution_reference' => 'DIABAIKAN-OLEH-SERVER',
    ])->fresh();

    expect($resolved->status)->toBe(RmeBillingDisputeStatus::RESOLVED)
        ->and((int) $resolved->resolution_correction_id)->toBe((int) $correction->id)
        ->and($resolved->resolution_reference)->toBe((string) $correction->fresh()->correction_number);
});

it('does not accept a correction applied BEFORE the report as evidence for it', function () {
    $invoice = ricInvoice();
    ricApprove(ricSubmit($invoice));
    $dispute = rbdReport($invoice->fresh());
    test()->actingAs(test()->reviewer);

    rbdExpectCode(fn () => rbdService()->resolve($dispute, test()->reviewer, [
        'outcome' => RmeBillingDisputeOutcome::CORRECTION_APPLIED,
        'resolution_reason' => 'Koreksi lama dianggap cukup.',
    ]), 'BILLING_DISPUTE_RESOLUTION_EVIDENCE_MISSING');
});

it('refuses every resolution while a correction is still in flight', function () {
    $invoice = ricInvoice();
    $dispute = rbdReport($invoice);
    ricSubmit($invoice);

    rbdExpectCode(fn () => rbdConfirmOriginal($dispute), 'BILLING_DISPUTE_CORRECTION_ACTIVE');
    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

// ─── Audit trail ────────────────────────────────────────────────────────────

it('audits every step with ids and codes only — never the free text or the patient', function () {
    $invoice = ricInvoice();
    $dispute = rbdReport($invoice, null, ['description' => 'Rahasia: harga tambal salah ketik kasir.']);
    test()->actingAs(test()->reviewer);
    rbdService()->escalate($dispute, test()->reviewer, 'Catatan rahasia untuk Owner.');
    rbdConfirmOriginal($dispute->fresh());

    $rows = DB::table('sys_audit_logs')->where('entity_type', RmeBillingDisputeAuditEvent::ENTITY_DISPUTE)->get();
    expect($rows->pluck('action')->all())->toContain(
        RmeBillingDisputeAuditEvent::ACTION_REPORTED,
        RmeBillingDisputeAuditEvent::ACTION_ESCALATED,
        RmeBillingDisputeAuditEvent::ACTION_RESOLVED,
    );

    $all = $rows->map(fn ($r) => (string) $r->new_values.(string) $r->old_values)->implode(' ');
    expect($all)->not->toContain('Rahasia')
        ->and($all)->not->toContain('Catatan rahasia')
        ->and($all)->not->toContain((string) test()->patient->name)
        ->and($all)->not->toContain('OWNER-OK');
});

// ─── Escalation to the branch authority ─────────────────────────────────────

it('notifies the reachable, active branch reviewers in-app and never the reporter', function () {
    $inactive = userWith(['review_rme_billing_disputes', 'view_clinic_visits']);
    $inactive->forceFill(['is_active' => false])->save();

    rbdReport(ricInvoice());

    expect(test()->reviewer->fresh()->notifications()->count())->toBe(1)
        ->and(test()->cashier->fresh()->notifications()->count())->toBe(0)
        ->and($inactive->fresh()->notifications()->count())->toBe(0)
        ->and(test()->viewer->fresh()->notifications()->count())->toBe(0);

    $note = test()->reviewer->fresh()->notifications()->first();
    expect($note->type)->toBe(RmeBillingDisputeNotification::class)
        ->and($note->data['url'])->toStartWith('/')
        ->and($note->data['message'])->not->toContain((string) test()->patient->name);
});

it('notifies the owner-style viewer as well once escalated', function () {
    $dispute = rbdReport(ricInvoice());
    test()->actingAs(test()->reviewer);
    rbdService()->escalate($dispute, test()->reviewer, 'Perlu keputusan Owner.');

    expect(test()->viewer->fresh()->notifications()->count())->toBe(1);
});

it('records an UNROUTED escalation and keeps the dispute when no reviewer can be reached', function () {
    test()->reviewer->forceFill(['is_active' => false])->save();
    // Super Admin is the only other review holder in production; none exists here.

    $dispute = rbdReport(ricInvoice());

    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN)
        ->and(rbdAudit(RmeBillingDisputeAuditEvent::ACTION_ESCALATION_UNROUTED))->toHaveCount(1)
        ->and(app(RmeBillingDisputeEscalationService::class)->reviewersFor((int) $dispute->branch_id))->toHaveCount(0);
});

it('keeps the dispute and its hold when notification delivery fails', function () {
    Event::listen(NotificationSending::class, function () {
        throw new RuntimeException('mail relay down');
    });

    $invoice = ricInvoice();
    $dispute = rbdReport($invoice);

    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN)
        ->and(rbdAudit(RmeBillingDisputeAuditEvent::ACTION_NOTIFICATION_FAILED))->toHaveCount(1);

    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 100000));
});

it('does not stall the patient visit: reporting leaves the visit status untouched', function () {
    $invoice = ricInvoice();
    $visitStatus = DB::table('trx_clinic_visits')->where('id', $invoice->clinic_visit_id)->value('status');

    rbdReport($invoice);

    expect(DB::table('trx_clinic_visits')->where('id', $invoice->clinic_visit_id)->value('status'))->toBe($visitStatus);
});

it('reports what the cashier page needs: the active dispute, the history, and whether a new report is possible', function () {
    $invoice = ricInvoice();
    $panel = rbdService()->panelFor($invoice, test()->cashier);
    expect($panel['active'])->toBeNull()->and($panel['can_report'])->toBeTrue();

    $dispute = rbdReport($invoice);
    $panel = rbdService()->panelFor($invoice->fresh(), test()->cashier);
    expect($panel['active']?->id)->toBe($dispute->id)
        ->and($panel['can_report'])->toBeFalse();

    expect(rbdService()->panelFor($invoice->fresh(), test()->viewer)['can_report'])->toBeFalse();
});

it('keeps the dispute and answers normally when building the notification itself fails (security review A)', function () {
    // The invoice number is looked up to build the message. A failure there must
    // be contained like a delivery failure — never a 500 after the dispute committed.
    DB::listen(function ($query) {
        if (preg_match('/^select\s+"invoice_number"\s+from\s+"trx_rme_invoices"/i', $query->sql) === 1) {
            throw new RuntimeException('database blip while building the notification');
        }
    });

    $invoice = ricInvoice();
    $dispute = rbdReport($invoice);

    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN)
        ->and(rbdAudit(RmeBillingDisputeAuditEvent::ACTION_NOTIFICATION_FAILED))->toHaveCount(1)
        ->and(rbdAudit(RmeBillingDisputeAuditEvent::ACTION_NOTIFICATION_FAILED)->first()->new_values['code'])->toBe('ESCALATION_FAILED');

    test()->actingAs(test()->reviewer);
    $escalated = rbdService()->escalate($dispute->fresh(), test()->reviewer, 'Perlu keputusan Owner soal tarif.');
    expect($escalated->fresh()->status)->toBe(RmeBillingDisputeStatus::ESCALATED);

    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 100000));
});

it('keeps the dispute when resolving the recipients fails', function () {
    $resolver = Mockery::mock(ActiveAccountResolver::class.'[isUsable]', [])->makePartial();
    $resolver->shouldReceive('isUsable')->andThrow(new RuntimeException('account lookup failed'));
    app()->instance(ActiveAccountResolver::class, $resolver);

    $dispute = rbdReport(ricInvoice());

    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN)
        ->and(rbdAudit(RmeBillingDisputeAuditEvent::ACTION_NOTIFICATION_FAILED)->first()?->new_values['code'])->toBe('ESCALATION_FAILED');
});

it('never notifies the reviewer of their own escalation (mutation M13)', function () {
    $dispute = rbdReport(ricInvoice());
    expect(test()->reviewer->fresh()->notifications()->count())->toBe(1); // the report

    test()->actingAs(test()->reviewer);
    rbdService()->escalate($dispute, test()->reviewer, 'Perlu keputusan Owner soal tarif.');

    expect(test()->reviewer->fresh()->notifications()->count())->toBe(1)
        ->and(test()->viewer->fresh()->notifications()->count())->toBe(1);
});
