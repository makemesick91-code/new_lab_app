<?php

/*
| FIX-RME-INVOICE-CORRECTION-ZERO-ORIGINAL-GUARD-1 (rule 201) — a correction
| can never raise an invoice whose AUTHORITATIVE ORIGINAL total is Rp0.
|
| THE GAP (verified in source on b76b0ed2 before this sprint):
|   - prePaymentIneligibility() refused a NEGATIVE invoice total, never a Rp0
|     one;
|   - the proposal builder refuses a NEW Rp0 line and a Rp0 RESULTING total —
|     so a correction could never zero anything — but it lets an unchanged Rp0
|     line be CHANGED to a positive price, so Rp0 → positive passed every check.
|   Production carried exactly that case: SPN4 invoice #36, UNPAID, Rp0. The ban
|   on correcting it was procedural only (rule 196 §23).
|
| THE GUARD is a TEMPORARY FAIL-CLOSED BOUNDARY, not a Rp0 policy: D21g (Rp0
| consultation/control) and D22 (zombie invoices) stay PENDING. It refuses
| submit, approve (= apply, D1a) and the recovery apply; it never refuses the
| two de-escalations, reject and cancel, which release the D10 payment block.
|
| THE AUTHORITY is persisted data read under the invoice row lock — never a
| request field, a rendered amount, or the object a caller passes in:
|   - correction_version 0 → the live invoice row IS v0;
|   - correction_version > 0 → v0 is the frozen ORIGINAL document version;
|   - correction_version > 0 with no frozen v0 → unverifiable → refused;
|   - a Rp0 CURRENT total is refused as well (same outcome: money created out
|     of a Rp0 document).
*/

use App\Models\User;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceCorrectionRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeInvoiceDocumentVersion;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionProposalBuilder;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionDenied;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
use App\Modules\RmeInvoice\Support\RmeInvoiceSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    ricSetup();
});

// ─── Fixtures ──────────────────────────────────────────────────────────────

/** A Rp0 invoice exactly like production #36: one Rp0 line, UNPAID, never corrected. */
function zogZeroInvoice(string $unitPrice = '0', string $discount = '0'): RmeInvoice
{
    return app(RmeInvoiceService::class)->create(ricVisit(), test()->cashier, [
        'items' => [
            ['description' => 'Kontrol pasca tindakan', 'qty' => 1, 'unit_price' => $unitPrice, 'discount' => $discount, 'treatment_id' => test()->treatment->id],
        ],
    ])->refresh();
}

/**
 * The proposal that raises the Rp0 line to a positive price — the bypass.
 *
 * @return list<array<string, mixed>>
 */
function zogRaisingLines(RmeInvoice $invoice, string $price = '150000', string $discount = '0'): array
{
    $item = $invoice->items()->orderBy('id')->firstOrFail();

    return [[
        'original_item_id' => $item->id,
        'treatment_id' => test()->treatment->id,
        'description' => 'Kontrol pasca tindakan',
        'qty' => 1,
        'unit_price' => $price,
        'discount' => $discount,
    ]];
}

/**
 * A correction against a Rp0 invoice filed BEFORE this guard existed — built
 * exactly as the pre-guard service built it (same builder, same snapshot, same
 * fingerprint, same columns), so approve/apply see the shape history can hold.
 */
function zogPreGuardZeroOriginalCorrection(RmeInvoice $invoice, ?User $maker = null): RmeInvoiceCorrection
{
    $maker ??= test()->maker;
    $repo = app(RmeInvoiceCorrectionRepositoryInterface::class);

    $original = RmeInvoiceSnapshot::capture($invoice->fresh());
    $proposal = app(RmeInvoiceCorrectionProposalBuilder::class)->build($original, zogRaisingLines($invoice));

    $correction = $repo->create([
        'uuid' => (string) Str::uuid(),
        'branch_id' => (int) $invoice->branch_id,
        'rme_invoice_id' => (int) $invoice->id,
        'clinic_visit_id' => (int) $invoice->clinic_visit_id,
        'patient_id' => (int) $invoice->patient_id,
        'correction_type' => RmeInvoiceCorrectionType::PRE_PAYMENT,
        'status' => RmeInvoiceCorrectionStatus::SUBMITTED,
        'reason' => 'Koreksi lama terhadap tagihan Rp0 sebelum guard.',
        'snapshot_schema_version' => RmeInvoiceSnapshot::SCHEMA_VERSION,
        'invoice_version_at_request' => (int) ($invoice->correction_version ?? 0),
        'invoice_fingerprint' => RmeInvoiceSnapshot::fingerprint($original),
        'original_snapshot' => $original,
        'proposed_snapshot' => [
            'schema_version' => RmeInvoiceSnapshot::SCHEMA_VERSION,
            'basis' => 'PROPOSAL',
            'invoice' => $original['invoice'],
            'items' => $proposal['items'],
            'totals' => [
                'subtotal_cents' => $proposal['subtotal_cents'],
                'discount_total_cents' => $proposal['discount_total_cents'],
                'grand_total_cents' => $proposal['grand_total_cents'],
                'paid_cents' => 0,
            ],
        ],
        'original_grand_total' => RmeInvoiceSnapshot::centsToDecimal((int) $original['totals']['grand_total_cents']),
        'proposed_grand_total' => RmeInvoiceSnapshot::centsToDecimal($proposal['grand_total_cents']),
        'difference_amount' => RmeInvoiceSnapshot::centsToDecimal($proposal['grand_total_cents'] - (int) $original['totals']['grand_total_cents']),
        'paid_total_at_request' => '0.00',
        'requested_by' => (int) $maker->id,
        'requested_at' => now(),
    ]);
    foreach ($proposal['lines'] as $line) {
        $repo->createLine(array_merge($line, ['rme_invoice_correction_id' => (int) $correction->id]));
    }

    return $correction->fresh();
}

/** Everything a correction could change, so "nothing moved" is one comparison. */
function zogFingerprint(RmeInvoice $invoice): array
{
    $invoice = $invoice->fresh(['items']);

    return [
        'invoice' => RmeInvoiceSnapshot::fingerprint(RmeInvoiceSnapshot::capture($invoice)),
        'grand_total' => (string) $invoice->grand_total,
        'status' => (string) $invoice->status,
        'correction_version' => (int) ($invoice->correction_version ?? 0),
        'items' => $invoice->items->map(fn ($i) => [(int) $i->id, (string) $i->unit_price, (string) $i->discount, (string) $i->subtotal, (string) $i->price_source])->all(),
        'document_versions' => DB::table('trx_rme_invoice_document_versions')->where('rme_invoice_id', $invoice->id)->count(),
        'payments' => DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->count(),
        'payment_total' => (string) DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->sum('amount'),
    ];
}

function zogService(): RmeInvoiceCorrectionService
{
    return app(RmeInvoiceCorrectionService::class);
}

/** The refusal message of a ValidationException, or the failure of the test. */
function zogValidationRefusal(callable $call): string
{
    try {
        $call();
    } catch (ValidationException $e) {
        return json_encode($e->errors(), JSON_UNESCAPED_UNICODE) ?: '';
    }

    test()->fail('Expected a validation refusal; the correction service allowed the action.');
}

function zogDenied(callable $call): string
{
    try {
        $call();
    } catch (RmeInvoiceCorrectionDenied $e) {
        return $e->reasonCode;
    }

    test()->fail('Expected an authorization refusal; the correction service allowed the action.');
}

// ─── Submit ────────────────────────────────────────────────────────────────

it('refuses to submit a correction that raises a Rp0 original invoice to a positive total', function () {
    $invoice = zogZeroInvoice();
    $before = zogFingerprint($invoice);
    $auditBefore = AuditLog::query()->count();

    $refusal = zogValidationRefusal(fn () => ricSubmit($invoice, test()->maker, zogRaisingLines($invoice)));

    expect($refusal)->toContain('CORRECTION_ORIGINAL_TOTAL_ZERO')
        ->and(RmeInvoiceCorrection::query()->count())->toBe(0)
        ->and(DB::table('trx_rme_invoice_correction_lines')->count())->toBe(0)
        ->and(DB::table('trx_rme_invoice_correction_events')->count())->toBe(0)
        ->and(zogFingerprint($invoice))->toBe($before)
        // A policy refusal rolls back and writes no audit row (as every
        // eligibility refusal does) — never a SUBMITTED row for a refused act.
        ->and(AuditLog::query()->count())->toBe($auditBefore)
        ->and(ricAudit(RmeFinancialAuditEvent::CORRECTION_ACTIONS[RmeInvoiceCorrectionStatus::SUBMITTED]))->toHaveCount(0);
});

it('refuses a Rp0 original whatever the proposed total is — zero, negative-by-discount, or positive', function (string $price, string $discount) {
    $invoice = zogZeroInvoice();

    $refusal = zogValidationRefusal(fn () => ricSubmit($invoice, test()->maker, zogRaisingLines($invoice, $price, $discount)));

    // The zero-original guard answers BEFORE the proposal builder is consulted:
    // the refusal is the original-total code, not a line or Rp0-result code.
    expect($refusal)->toContain('CORRECTION_ORIGINAL_TOTAL_ZERO')
        ->and(RmeInvoiceCorrection::query()->count())->toBe(0);
})->with([
    'revised positive' => ['150000', '0'],
    'revised zero' => ['0', '0'],
    'revised zero by full discount' => ['100000', '100000'],
    'revised over-discounted' => ['100000', '200000'],
]);

it('refuses a Rp0 original made of a positive line fully discounted', function () {
    $invoice = zogZeroInvoice('100000', '100000');
    expect((float) $invoice->grand_total)->toEqual(0.0);

    $refusal = zogValidationRefusal(fn () => ricSubmit($invoice, test()->maker, zogRaisingLines($invoice, '100000', '0')));

    expect($refusal)->toContain('CORRECTION_ORIGINAL_TOTAL_ZERO')
        ->and(RmeInvoiceCorrection::query()->count())->toBe(0);
});

it('ignores every client-supplied total — the original comes from the locked invoice row', function () {
    $invoice = zogZeroInvoice();

    $refusal = zogValidationRefusal(fn () => zogService()->submit($invoice, test()->maker, [
        'reason' => 'Harga kontrol salah input oleh kasir.',
        'lines' => zogRaisingLines($invoice),
        // None of these keys is read by the service.
        'original_grand_total' => '500000',
        'grand_total' => '500000',
        'original_total' => '500000',
        'proposed_grand_total' => '150000',
    ]));

    expect($refusal)->toContain('CORRECTION_ORIGINAL_TOTAL_ZERO')
        ->and(RmeInvoiceCorrection::query()->count())->toBe(0);
});

it('judges the persisted invoice, never the object the caller passes in', function () {
    $invoice = zogZeroInvoice();

    // A stale or forged in-memory object claiming 500.000 does not move the guard.
    $forged = $invoice->fresh();
    $forged->grand_total = '500000.00';
    $forged->subtotal = '500000.00';

    $refusal = zogValidationRefusal(fn () => ricSubmit($forged, test()->maker, zogRaisingLines($invoice)));
    expect($refusal)->toContain('CORRECTION_ORIGINAL_TOTAL_ZERO');

    // …and the reverse: an object claiming Rp0 over a positive row is no refusal.
    $positive = ricInvoice();
    $stale = $positive->fresh();
    $stale->grand_total = '0.00';

    $correction = ricSubmit($stale, test()->maker, ricLinesReducing($positive));
    expect($correction->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and((float) $correction->original_grand_total)->toEqual(500000.0);
});

it('refuses the forged-total request over HTTP and writes nothing', function () {
    $invoice = zogZeroInvoice();
    $before = zogFingerprint($invoice);
    ricActingAs(test()->maker);

    $this->post(route('rme.invoice-corrections.store', [$invoice->clinic_visit_id, $invoice->id]), [
        'reason' => 'Harga kontrol salah input oleh kasir.',
        'idempotency_key' => '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d',
        'lines' => zogRaisingLines($invoice),
        'grand_total' => '500000',
        'original_grand_total' => '500000',
    ])->assertSessionHasErrors('rme_invoice_id');

    expect(RmeInvoiceCorrection::query()->count())->toBe(0)
        ->and(zogFingerprint($invoice))->toBe($before);
});

it('offers no request on a Rp0 invoice and says why', function () {
    $invoice = zogZeroInvoice();

    $panel = zogService()->panelFor($invoice, test()->maker);

    expect($panel['eligible'])->toBeFalse()
        ->and($panel['ineligible_reason'])->toContain('CORRECTION_ORIGINAL_TOTAL_ZERO')
        ->and(zogService()->panelFor(ricInvoice(), test()->maker)['eligible'])->toBeTrue();
});

it('keeps the earlier gates in front of the guard for a Rp0 invoice (identity, permission, branch)', function () {
    $invoice = zogZeroInvoice();

    // The designated checker may never submit; a cashier holds no request permission.
    expect(zogDenied(fn () => ricSubmit($invoice, test()->checker, zogRaisingLines($invoice))))->toBeIn([
        RmeInvoiceCorrectionDenied::PERMISSION_MISSING,
        RmeInvoiceCorrectionDenied::PILOT_IDENTITY_NOT_AUTHORIZED,
    ])->and(zogDenied(fn () => ricSubmit($invoice, test()->cashier, zogRaisingLines($invoice))))
        ->toBe(RmeInvoiceCorrectionDenied::PERMISSION_MISSING);

    // A branch outside the pilot is refused before eligibility is read.
    ricPilot([]);
    expect(zogDenied(fn () => ricSubmit($invoice, test()->maker, zogRaisingLines($invoice))))
        ->toBe(RmeInvoiceCorrectionDenied::BRANCH_NOT_IN_PILOT)
        ->and(RmeInvoiceCorrection::query()->count())->toBe(0);
});

// ─── Later versions: v0 is the frozen ORIGINAL document version ─────────────

/**
 * Builds the history a pre-guard correction could have left: a Rp0 v0 frozen,
 * the invoice raised to 200.000 at version 1. No service path can produce this
 * any more — that is the point — so it is written directly.
 */
function zogRaisedFromZeroAtVersionOne(): RmeInvoice
{
    $invoice = zogZeroInvoice();
    $repo = app(RmeInvoiceCorrectionRepositoryInterface::class);
    $v0 = RmeInvoiceSnapshot::capture($invoice->fresh());

    $repo->createDocumentVersion([
        'uuid' => (string) Str::uuid(), 'rme_invoice_id' => $invoice->id, 'branch_id' => $invoice->branch_id,
        'version_number' => 0, 'version_kind' => RmeInvoiceDocumentVersion::KIND_ORIGINAL,
        'document_reference' => (string) $invoice->invoice_number, 'source_correction_id' => null,
        'capture_basis' => RmeInvoiceDocumentVersion::BASIS_PRE_FIRST_CORRECTION,
        'snapshot_schema_version' => RmeInvoiceSnapshot::SCHEMA_VERSION, 'snapshot' => $v0,
        'snapshot_sha256' => RmeInvoiceSnapshot::documentHash($v0), 'issued_by' => test()->checker->id, 'issued_at' => now(),
    ]);

    $item = $invoice->items()->firstOrFail();
    DB::table('trx_rme_invoice_items')->where('id', $item->id)->update(['unit_price' => 200000, 'subtotal' => 200000]);
    DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['subtotal' => 200000, 'grand_total' => 200000, 'correction_version' => 1]);

    // The v1 REVISION document is deliberately not fabricated: PostgreSQL's
    // shape CHECK requires it to name its source correction, and the guard reads
    // only v0 — the frozen ORIGINAL is the authority under test.

    return $invoice->fresh();
}

it('refuses a correction against a later version of an originally-Rp0 invoice', function () {
    $invoice = zogRaisedFromZeroAtVersionOne();
    expect((float) $invoice->grand_total)->toEqual(200000.0)
        ->and((int) $invoice->correction_version)->toBe(1);

    $refusal = zogValidationRefusal(fn () => ricSubmit($invoice, test()->maker, zogRaisingLines($invoice, '180000')));

    expect($refusal)->toContain('CORRECTION_ORIGINAL_TOTAL_ZERO')
        ->and(RmeInvoiceCorrection::query()->count())->toBe(0);
});

it('refuses approve and the recovery apply of a correction filed against a later version of an originally-Rp0 invoice', function () {
    $invoice = zogRaisedFromZeroAtVersionOne();

    // Filed at version 1 (pre-guard shape): staleness passes — the invoice is
    // still the reviewed document — so only the v0 authority can refuse it.
    $correction = zogPreGuardZeroOriginalCorrection($invoice);
    expect((int) $correction->invoice_version_at_request)->toBe(1);
    $before = zogFingerprint($invoice);

    expect(zogValidationRefusal(fn () => ricApprove($correction)))->toContain('CORRECTION_ORIGINAL_TOTAL_ZERO')
        ->and($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);

    $legacy = ricLegacyApproved($correction->fresh());
    expect(zogValidationRefusal(fn () => ricApply($legacy)))->toContain('CORRECTION_ORIGINAL_TOTAL_ZERO')
        ->and($legacy->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::APPROVED)
        ->and(zogFingerprint($invoice))->toBe($before);
});

it('fails closed when the frozen v0 snapshot was edited out of band or carries no integer total', function () {
    $invoice = zogRaisedFromZeroAtVersionOne();
    $v0 = DB::table('trx_rme_invoice_document_versions')->where('rme_invoice_id', $invoice->id)->where('version_number', 0)->first();

    // (a) A raw edit of v0 (0 → 500.000) that does not re-hash: integrity fails.
    $tampered = json_decode((string) $v0->snapshot, true);
    $tampered['totals']['grand_total_cents'] = 50000000;
    DB::table('trx_rme_invoice_document_versions')->where('id', $v0->id)->update(['snapshot' => json_encode($tampered)]);

    expect(zogValidationRefusal(fn () => ricSubmit($invoice->fresh(), test()->maker, zogRaisingLines($invoice, '180000'))))
        ->toContain('CORRECTION_ORIGINAL_TOTAL_UNVERIFIABLE');

    // (b) A consistent hash over a snapshot whose total is not an integer.
    $odd = $tampered;
    $odd['totals']['grand_total_cents'] = '50000000';
    DB::table('trx_rme_invoice_document_versions')->where('id', $v0->id)->update([
        'snapshot' => json_encode($odd),
        'snapshot_sha256' => RmeInvoiceSnapshot::documentHash($odd),
    ]);

    expect(zogValidationRefusal(fn () => ricSubmit($invoice->fresh(), test()->maker, zogRaisingLines($invoice, '180000'))))
        ->toContain('CORRECTION_ORIGINAL_TOTAL_UNVERIFIABLE')
        ->and(RmeInvoiceCorrection::query()->count())->toBe(0);
});

it('refuses a later version whose CURRENT total is Rp0 even when its v0 was positive', function () {
    $invoice = ricInvoice();
    $repo = app(RmeInvoiceCorrectionRepositoryInterface::class);
    $v0 = RmeInvoiceSnapshot::capture($invoice->fresh());
    $repo->createDocumentVersion([
        'uuid' => (string) Str::uuid(), 'rme_invoice_id' => $invoice->id, 'branch_id' => $invoice->branch_id,
        'version_number' => 0, 'version_kind' => RmeInvoiceDocumentVersion::KIND_ORIGINAL,
        'document_reference' => (string) $invoice->invoice_number, 'source_correction_id' => null,
        'capture_basis' => RmeInvoiceDocumentVersion::BASIS_PRE_FIRST_CORRECTION,
        'snapshot_schema_version' => RmeInvoiceSnapshot::SCHEMA_VERSION, 'snapshot' => $v0,
        'snapshot_sha256' => RmeInvoiceSnapshot::documentHash($v0), 'issued_by' => test()->checker->id, 'issued_at' => now(),
    ]);
    // History no service path can produce any more: v1 reduced to Rp0.
    DB::table('trx_rme_invoice_items')->where('rme_invoice_id', $invoice->id)->update(['unit_price' => 0, 'subtotal' => 0]);
    DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['subtotal' => 0, 'grand_total' => 0, 'correction_version' => 1]);

    $refusal = zogValidationRefusal(fn () => ricSubmit($invoice->fresh(), test()->maker, ricLinesReducing($invoice->fresh())));

    expect($refusal)->toContain('CORRECTION_ORIGINAL_TOTAL_ZERO')
        ->and(RmeInvoiceCorrection::query()->count())->toBe(0);
});

it('refuses a later version whose frozen v0 was negative', function () {
    $invoice = ricInvoice();
    $v0 = RmeInvoiceSnapshot::capture($invoice->fresh());
    $v0['totals']['grand_total_cents'] = -1000000; // historical over-discount
    app(RmeInvoiceCorrectionRepositoryInterface::class)->createDocumentVersion([
        'uuid' => (string) Str::uuid(), 'rme_invoice_id' => $invoice->id, 'branch_id' => $invoice->branch_id,
        'version_number' => 0, 'version_kind' => RmeInvoiceDocumentVersion::KIND_ORIGINAL,
        'document_reference' => (string) $invoice->invoice_number, 'source_correction_id' => null,
        'capture_basis' => RmeInvoiceDocumentVersion::BASIS_PRE_FIRST_CORRECTION,
        'snapshot_schema_version' => RmeInvoiceSnapshot::SCHEMA_VERSION, 'snapshot' => $v0,
        'snapshot_sha256' => RmeInvoiceSnapshot::documentHash($v0), 'issued_by' => test()->checker->id, 'issued_at' => now(),
    ]);
    DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['correction_version' => 1]);

    $refusal = zogValidationRefusal(fn () => ricSubmit($invoice->fresh(), test()->maker, ricLinesReducing($invoice->fresh())));

    expect($refusal)->toContain('CORRECTION_INVOICE_TOTAL_NEGATIVE')
        ->and(RmeInvoiceCorrection::query()->count())->toBe(0);
});

it('fails closed when a corrected invoice has no frozen original version to prove its v0', function () {
    $invoice = ricInvoice();
    // correction_version > 0 but no ORIGINAL document version: the original is unknowable.
    DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['correction_version' => 1]);

    $refusal = zogValidationRefusal(fn () => ricSubmit($invoice->fresh(), test()->maker, ricLinesReducing($invoice)));

    expect($refusal)->toContain('CORRECTION_ORIGINAL_TOTAL_UNVERIFIABLE')
        ->and(RmeInvoiceCorrection::query()->count())->toBe(0);
});

it('still corrects a later version of an originally-positive invoice', function () {
    $invoice = ricInvoice();
    $first = ricApprove(ricSubmit($invoice));
    expect($first->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((int) $invoice->fresh()->correction_version)->toBe(1);

    $second = ricSubmit($invoice->fresh(), test()->maker, ricLinesReducing($invoice->fresh(), '40000'));
    $applied = ricApprove($second);

    expect($applied->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((float) $invoice->fresh()->grand_total)->toEqual(340000.0)
        ->and((int) $invoice->fresh()->correction_version)->toBe(2);
});

// ─── Approve (= apply, D1a) and the recovery apply ──────────────────────────

it('refuses to approve a pre-existing correction of a Rp0 original and changes nothing', function () {
    $invoice = zogZeroInvoice();
    $correction = zogPreGuardZeroOriginalCorrection($invoice);
    $before = zogFingerprint($invoice);
    $eventsBefore = DB::table('trx_rme_invoice_correction_events')->count();
    $auditBefore = AuditLog::query()->count();

    $refusal = zogValidationRefusal(fn () => ricApprove($correction));

    expect($refusal)->toContain('CORRECTION_ORIGINAL_TOTAL_ZERO')
        // Refused BEFORE any mutation: not APPROVED, not APPLIED, not even STALE.
        ->and($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and($correction->fresh()->decided_by)->toBeNull()
        ->and(zogFingerprint($invoice))->toBe($before)
        ->and(DB::table('trx_rme_invoice_correction_events')->count())->toBe($eventsBefore)
        ->and(AuditLog::query()->count())->toBe($auditBefore);
});

it('refuses the recovery apply of a pre-existing APPROVED correction of a Rp0 original', function () {
    $invoice = zogZeroInvoice();
    $correction = ricLegacyApproved(zogPreGuardZeroOriginalCorrection($invoice));
    $before = zogFingerprint($invoice);

    $refusal = zogValidationRefusal(fn () => ricApply($correction));

    expect($refusal)->toContain('CORRECTION_ORIGINAL_TOTAL_ZERO')
        ->and($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::APPROVED)
        ->and($correction->fresh()->applied_by)->toBeNull()
        ->and(zogFingerprint($invoice))->toBe($before);
});

it('keeps both de-escalations open for a Rp0-original correction: the checker rejects, the maker cancels', function () {
    $invoice = zogZeroInvoice();

    $rejected = zogService()->reject(zogPreGuardZeroOriginalCorrection($invoice), test()->checker, 'Tagihan Rp0 menunggu keputusan owner.');
    expect($rejected->status)->toBe(RmeInvoiceCorrectionStatus::REJECTED);

    $cancelled = zogService()->cancel(zogPreGuardZeroOriginalCorrection($invoice), test()->maker, 'Ditarik: tagihan Rp0 tidak boleh dikoreksi.');
    expect($cancelled->status)->toBe(RmeInvoiceCorrectionStatus::CANCELLED)
        ->and((float) $invoice->fresh()->grand_total)->toEqual(0.0)
        ->and(DB::table('trx_rme_invoice_document_versions')->count())->toBe(0);
});

it('keeps an approval replay a no-op instead of re-judging an already applied correction', function () {
    $invoice = ricInvoice();
    $applied = ricApprove(ricSubmit($invoice));

    $replay = ricApprove($applied);

    expect($replay->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and(DB::table('trx_rme_invoice_document_versions')->where('rme_invoice_id', $invoice->id)->count())->toBe(2);
});

// ─── Positive originals and the rest of the policy are unchanged ────────────

it('still runs the full positive pre-payment correction: submit → approve applies v0/v1 with manual-entry provenance', function () {
    $invoice = ricInvoice();

    $applied = ricApprove(ricSubmit($invoice));

    $invoice = $invoice->fresh(['items']);
    expect($applied->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((float) $invoice->grand_total)->toEqual(350000.0)
        ->and((int) $invoice->correction_version)->toBe(1)
        ->and($invoice->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($invoice->items->sortBy('id')->last()->price_source)->toBe('CORRECTION_MANUAL_ENTRY')
        ->and(DB::table('trx_rme_invoice_document_versions')->where('rme_invoice_id', $invoice->id)->orderBy('version_number')->pluck('version_number')->all())->toBe([0, 1])
        ->and(ricAudit(RmeFinancialAuditEvent::CORRECTION_ACTIONS[RmeInvoiceCorrectionStatus::APPLIED]))->toHaveCount(1);
});

it('keeps refusing a partially paid, an already actively corrected and a paid positive invoice', function () {
    $partial = ricInvoice();
    ricPay($partial, 100000);
    expect(zogValidationRefusal(fn () => ricSubmit($partial->fresh())))->toContain('CORRECTION_TYPE_NOT_ENABLED');

    $active = ricInvoice();
    ricSubmit($active);
    expect(zogValidationRefusal(fn () => ricSubmit($active->fresh())))->toContain('CORRECTION_ALREADY_ACTIVE');

    $paid = ricInvoice();
    ricPay($paid, 500000);
    expect(zogValidationRefusal(fn () => ricSubmit($paid->fresh())))->toContain('CORRECTION_TYPE_NOT_ENABLED');
});

it('keeps D10: an active correction on a positive invoice blocks its payment', function () {
    $invoice = ricInvoice();
    ricSubmit($invoice);

    expect(zogValidationRefusal(fn () => ricPay($invoice, 100000)))->toContain('INVOICE_CORRECTION_ACTIVE')
        ->and(DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('still issues a Rp0 invoice — the guard is about correction, not issuance', function () {
    $invoice = zogZeroInvoice();

    expect($invoice->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and((float) $invoice->grand_total)->toEqual(0.0)
        ->and($invoice->items)->toHaveCount(1);
});

// ─── Inactive accounts never mutate (rule 200 at the correction service) ────

it('refuses every correction mutation to an account deactivated before the call — including cancel', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);
    $legacy = ricLegacyApproved(ricSubmit(ricInvoice()));

    // Deactivated in the database; the in-memory objects still say "active",
    // exactly like a session or a long-running caller that loaded them earlier.
    DB::table('users')->where('id', test()->maker->id)->update(['is_active' => false]);
    $staleMaker = test()->maker;

    expect(zogDenied(fn () => zogService()->cancel($correction, $staleMaker, 'Ditarik oleh akun yang sudah nonaktif.')))
        ->toBe(RmeInvoiceCorrectionDenied::ACTOR_UNUSABLE)
        ->and($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);

    DB::table('users')->where('id', test()->checker->id)->update(['is_active' => false]);
    expect(zogDenied(fn () => zogService()->reject($correction, test()->checker, 'Ditolak oleh akun yang sudah nonaktif.')))
        ->toBe(RmeInvoiceCorrectionDenied::ACTOR_UNUSABLE)
        ->and(zogDenied(fn () => zogService()->approve($correction, test()->checker)))
        ->toBe(RmeInvoiceCorrectionDenied::ACTOR_UNUSABLE)
        ->and($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);

    // The recovery apply of a pre-D1a APPROVED row: the applier, deactivated.
    DB::table('users')->where('id', test()->applier->id)->update(['is_active' => false]);
    expect(zogDenied(fn () => zogService()->apply($legacy, test()->applier)))
        ->toBe(RmeInvoiceCorrectionDenied::ACTOR_UNUSABLE)
        ->and($legacy->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::APPROVED);

    // The refused attempts are audited after the rollback, with the account code.
    expect(ricAudit(RmeFinancialAuditEvent::ACTION_CORRECTION_REFUSED)->pluck('new_values.code')->unique()->values()->all())
        ->toBe([RmeInvoiceCorrectionDenied::ACTOR_UNUSABLE]);
});

it('judges the permission on the persisted account, not on roles loaded into a stale object', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);

    // The checker's object was loaded while it held the permission; the grant
    // is then revoked in the database. The stale object would still say yes.
    $checker = test()->checker;
    expect($checker->can('approve_rme_invoice_correction'))->toBeTrue();
    DB::table('model_has_permissions')->where('model_id', $checker->id)->where('model_type', User::class)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(zogDenied(fn () => zogService()->approve($correction, $checker)))
        ->toBe(RmeInvoiceCorrectionDenied::PERMISSION_MISSING)
        ->and($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

it('judges the branch scope on the persisted account, not on roles loaded into a stale object', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);

    // Loaded while the checker held no context-bound role (scope: every RME
    // branch). The account is then given the context-bound Kasir role in the
    // database — with no working context its scope is EMPTY (fail closed).
    $checker = test()->checker;
    expect($checker->hasRole('Kasir'))->toBeFalse();
    DB::table('model_has_roles')->insert([
        'role_id' => DB::table('roles')->where('name', 'Kasir')->value('id'),
        'model_type' => User::class,
        'model_id' => $checker->id,
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(zogDenied(fn () => zogService()->approve($correction, $checker)))
        ->toBe(RmeInvoiceCorrectionDenied::BRANCH_OUT_OF_SCOPE)
        ->and($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

// ─── Governance: the guard is temporary; the policy stays undecided ─────────

it('records the guard as a temporary boundary and keeps D21g and D22 pending', function () {
    $register = (string) file_get_contents(base_path('docs/decisions/rme-billing-correction-owner-decisions.md'));
    $rule = (string) file_get_contents(base_path('.cursor/rules/201-rme-invoice-correction-zero-original-guard.mdc'));

    expect($register)->toMatch('/\|\s*D21g\s*\|[^\n]*PENDING/')
        ->and($register)->toContain('ZERO_ORIGINAL_CORRECTION_GUARD')
        ->and($register)->toContain('TEMPORARY_FAIL_CLOSED')
        ->and($register)->not->toMatch('/\|\s*D21g\s*\|[^\n]*OWNER_POLICY_APPROVED/')
        ->and($rule)->toContain('D21g')
        ->and($rule)->toContain('D22')
        ->and($rule)->toContain('PENDING')
        ->and($rule)->toContain('CORRECTION_ORIGINAL_TOTAL_ZERO');
});
