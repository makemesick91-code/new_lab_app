<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HARDENING-RME-OWNER-REFERENCE-REGISTRY-1 — the database-backed registry of
| consumed Owner approval references (decision PP-2, refund decision package
| migration plan §2a).
|--------------------------------------------------------------------------
|
| One Owner approval reference authorises ONE financial decision scope. The
| post-payment correction scope is the INVOICE: a resubmission on the same
| invoice (after REJECTED / CANCELLED / STALE) may repeat its reference (PP-2);
| any other invoice — and any other workflow — is refused. The registry is the
| database guarantee behind that rule; the application check that scans
| corrections stays for its clear message.
|
| These tests run on SQLite and PostgreSQL. The real two-session races and the
| PostgreSQL CHECKs are in RmeOwnerReferenceRegistryConcurrencyTest.
*/

use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeOwnerApprovalReference;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionReadinessService;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Services\RmeOwnerApprovalReferenceRegistry;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../RmeInvoiceCorrection/postpayment-helpers.php';

const RORR_MIGRATION = 'database/migrations/2026_10_31_100001_create_trx_rme_owner_approval_references_table.php';

beforeEach(function () {
    ricSetup();
    rppFlag(true);
});

function rorrRegistry(): RmeOwnerApprovalReferenceRegistry
{
    return app(RmeOwnerApprovalReferenceRegistry::class);
}

/** @return array{scope_type: string, scope_id: int}|null */
function rorrOwnerOf(string $reference): ?array
{
    $row = DB::table('trx_rme_owner_approval_references')
        ->where('reference_folded', strtolower($reference))
        ->first();

    return $row === null ? null : ['scope_type' => (string) $row->scope_type, 'scope_id' => (int) $row->scope_id];
}

function rorrMigration(): object
{
    return require base_path(RORR_MIGRATION);
}

// ─── Submit registers the reference in the invoice scope ─────────────────────

it('registers a post-payment reference, case-folded, in the scope of its invoice', function () {
    $invoice = rppPaidInvoice(200000);
    $correction = rppSubmit($invoice, reference: 'OWNER-COR-IC-20261031-A1');

    $row = DB::table('trx_rme_owner_approval_references')->sole();

    expect($correction->correction_type)->toBe(RmeInvoiceCorrectionType::POST_PAYMENT)
        ->and($correction->owner_approval_reference)->toBe('OWNER-COR-IC-20261031-A1')
        ->and($row->reference_folded)->toBe('owner-cor-ic-20261031-a1')
        ->and($row->scope_type)->toBe(RmeOwnerApprovalReferenceRegistry::SCOPE_POST_PAYMENT_CORRECTION_INVOICE)
        ->and((int) $row->scope_id)->toBe((int) $invoice->id)
        ->and((int) $row->first_consumed_by)->toBe((int) test()->maker->id);
});

it('keeps PP-2: the same invoice may repeat its reference, in any case, after a cancelled or rejected request', function () {
    $invoice = rppPaidInvoice(200000);
    $first = rppSubmit($invoice, reference: rppReference('21'));
    app(RmeInvoiceCorrectionService::class)->cancel($first->fresh(), test()->maker, 'Salah baris, diajukan ulang.');

    $second = rppSubmit($invoice->fresh(), reference: strtolower(rppReference('21')));
    app(RmeInvoiceCorrectionService::class)->reject($second->fresh(), test()->checker, 'Harga belum sesuai, ajukan ulang.');

    $third = rppSubmit($invoice->fresh(), reference: rppReference('21'));

    expect($third->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and(DB::table('trx_rme_owner_approval_references')->count())->toBe(1)
        ->and(rorrOwnerOf(rppReference('21')))->toBe([
            'scope_type' => RmeOwnerApprovalReferenceRegistry::SCOPE_POST_PAYMENT_CORRECTION_INVOICE,
            'scope_id' => (int) $invoice->id,
        ]);
});

it('refuses a reference the registry binds to another invoice even when no correction row is visible', function () {
    // The concurrent winner's correction is not visible to this transaction
    // (READ COMMITTED); only its registry claim decides. Simulated here by a
    // claim with no correction behind it.
    $other = rppPaidInvoice(200000);
    DB::transaction(fn () => expect(rorrRegistry()->consume(
        rppReference('22'),
        RmeOwnerApprovalReferenceRegistry::SCOPE_POST_PAYMENT_CORRECTION_INVOICE,
        (int) $other->id,
        test()->maker,
    ))->toBeTrue());

    $invoice = rppPaidInvoice(200000);
    rppExpectRefusal(fn () => rppSubmit($invoice, reference: strtolower(rppReference('22'))), 'CORRECTION_OWNER_APPROVAL_REFERENCE_REUSED');

    expect(RmeInvoiceCorrection::where('rme_invoice_id', $invoice->id)->count())->toBe(0)
        ->and(DB::table('trx_rme_owner_approval_references')->count())->toBe(1)
        ->and(rorrOwnerOf(rppReference('22'))['scope_id'])->toBe((int) $other->id);
});

it('never lets one reference cross workflows: a refund-scoped reference refuses a correction, and the reverse', function () {
    DB::transaction(fn () => expect(rorrRegistry()->consume(
        rppReference('23'),
        RmeOwnerApprovalReferenceRegistry::SCOPE_REFUND_REQUEST,
        987654,
        test()->maker,
    ))->toBeTrue());

    $invoice = rppPaidInvoice(200000);
    rppExpectRefusal(fn () => rppSubmit($invoice, reference: rppReference('23')), 'CORRECTION_OWNER_APPROVAL_REFERENCE_REUSED');

    $owned = rppPaidInvoice(200000);
    rppSubmit($owned, reference: rppReference('24'));

    $refused = DB::transaction(fn () => rorrRegistry()->consume(
        strtolower(rppReference('24')),
        RmeOwnerApprovalReferenceRegistry::SCOPE_REFUND_REQUEST,
        987655,
        test()->maker,
    ));

    expect($refused)->toBeFalse()
        ->and(rorrOwnerOf(rppReference('24'))['scope_type'])
        ->toBe(RmeOwnerApprovalReferenceRegistry::SCOPE_POST_PAYMENT_CORRECTION_INVOICE);
});

it('leaves no registry row behind when the submission is refused', function () {
    $first = rppPaidInvoice(200000);
    rppSubmit($first, reference: rppReference('25'));

    $second = rppPaidInvoice(200000);
    rppExpectRefusal(fn () => rppSubmit($second, reference: rppReference('25')), 'CORRECTION_OWNER_APPROVAL_REFERENCE_REUSED');
    rppExpectRefusal(fn () => rppSubmit($second, reference: null), 'CORRECTION_OWNER_APPROVAL_REFERENCE_REQUIRED');

    expect(DB::table('trx_rme_owner_approval_references')->pluck('reference_folded')->all())
        ->toBe([strtolower(rppReference('25'))]);
});

it('rolls a claim back with the transaction that made it', function () {
    $invoice = rppPaidInvoice(200000);

    try {
        DB::transaction(function () use ($invoice) {
            rorrRegistry()->consume(rppReference('26'), RmeOwnerApprovalReferenceRegistry::SCOPE_POST_PAYMENT_CORRECTION_INVOICE, (int) $invoice->id, test()->maker);

            throw new RuntimeException('refused later in the same transaction');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('trx_rme_owner_approval_references')->count())->toBe(0);

    // The reference is still free for its legitimate scope.
    rppSubmit($invoice->fresh(), reference: rppReference('26'));
    expect(rorrOwnerOf(rppReference('26'))['scope_id'])->toBe((int) $invoice->id);
});

it('leaves pre-payment corrections outside the registry', function () {
    $correction = rppSubmit(ricInvoice(), reference: rppReference('27'));

    expect($correction->correction_type)->toBe(RmeInvoiceCorrectionType::PRE_PAYMENT)
        ->and(DB::table('trx_rme_owner_approval_references')->count())->toBe(0);
});

// ─── Fail closed ─────────────────────────────────────────────────────────────

it('fails closed outside a transaction', function () {
    $invoice = rppPaidInvoice(200000);

    // RefreshDatabase keeps the default connection inside a transaction, so the
    // call is made on a second connection that has none (production's state
    // when a caller forgets the transaction). The refusal comes before any query.
    $default = config('database.default');
    config()->set('database.connections.rorr_plain', config('database.connections.'.$default));
    config()->set('database.default', 'rorr_plain');

    try {
        expect(DB::connection()->transactionLevel())->toBe(0)
            ->and(fn () => rorrRegistry()->consume(rppReference('28'), RmeOwnerApprovalReferenceRegistry::SCOPE_POST_PAYMENT_CORRECTION_INVOICE, (int) $invoice->id, test()->maker))
            ->toThrow(LogicException::class, 'inside the transaction');
    } finally {
        config()->set('database.default', $default);
        DB::purge('rorr_plain');
    }

    expect(DB::table('trx_rme_owner_approval_references')->count())->toBe(0);
});

it('fails closed on an unknown scope, a non-positive scope id or a value that is not a normalized reference', function (string $reference, string $scope, int $scopeId) {
    expect(fn () => DB::transaction(fn () => rorrRegistry()->consume($reference, $scope, $scopeId, test()->maker)))
        ->toThrow(InvalidArgumentException::class);

    expect(DB::table('trx_rme_owner_approval_references')->count())->toBe(0);
})->with([
    'unknown scope' => ['OWNER-COR-IC-20261031-B1', 'SOME_OTHER_SCOPE', 1],
    'zero scope id' => ['OWNER-COR-IC-20261031-B2', 'POST_PAYMENT_CORRECTION_INVOICE', 0],
    'surrounding whitespace' => ['  OWNER-COR-IC-20261031-B3', 'POST_PAYMENT_CORRECTION_INVOICE', 1],
    'too short' => ['ABC12', 'POST_PAYMENT_CORRECTION_INVOICE', 1],
    'character outside the reference alphabet' => ['OWNER COR 2026', 'POST_PAYMENT_CORRECTION_INVOICE', 1],
]);

it('is append-only: a registry row can be neither updated nor deleted through the model', function () {
    $invoice = rppPaidInvoice(200000);
    rppSubmit($invoice, reference: rppReference('29'));
    $row = RmeOwnerApprovalReference::query()->sole();

    $elsewhere = (int) $invoice->id + 1000; // a value that really changes the row

    expect(fn () => $row->update(['scope_id' => $elsewhere]))->toThrow(MassAssignmentException::class)
        ->and(fn () => $row->forceFill(['scope_id' => $elsewhere])->save())->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $row->delete())->toThrow(LogicException::class, 'never be deleted');

    expect(rorrOwnerOf(rppReference('29'))['scope_id'])->toBe((int) $invoice->id);
});

// ─── Approval verifies the claim ─────────────────────────────────────────────

it('approves a post-payment correction whose reference the registry binds to its invoice', function () {
    $invoice = rppPaidInvoice(200000);
    $correction = rppSubmit($invoice, reference: rppReference('30'));

    $applied = ricApprove($correction->fresh());

    expect($applied->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED);
});

it('refuses approval when the registry does not bind the reference to the corrected invoice', function (string $tamper) {
    $invoice = rppPaidInvoice(200000);
    $correction = rppSubmit($invoice, reference: rppReference('31'));

    $query = DB::table('trx_rme_owner_approval_references')->where('reference_folded', strtolower(rppReference('31')));
    $tamper === 'missing'
        ? $query->delete()
        : $query->update(['scope_id' => (int) $invoice->id + 1000]);

    rppExpectRefusal(fn () => ricApprove($correction->fresh()), 'CORRECTION_OWNER_APPROVAL_REFERENCE_INVALID');

    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and((int) $invoice->fresh()->correction_version)->toBe(0)
        ->and(DB::table('trx_rme_overpayment_obligations')->count())->toBe(0);
})->with(['missing', 'scoped to another invoice']);

// ─── The migration and existing data ─────────────────────────────────────────

it('backfills existing post-payment corrections when the registry is created', function () {
    $a = rppPaidInvoice(200000);
    $b = rppPaidInvoice(200000);
    rppSubmit($a, reference: rppReference('32'));
    rppSubmit($b, reference: rppReference('33'));
    rppSubmit(ricInvoice(), reference: rppReference('34')); // pre-payment: not registered

    DB::table('trx_rme_owner_approval_references')->delete();
    $migration = rorrMigration();
    $migration->down();
    $migration->up();

    expect(DB::table('trx_rme_owner_approval_references')->orderBy('reference_folded')->get(['reference_folded', 'scope_type', 'scope_id'])
        ->map(fn ($r) => [$r->reference_folded, $r->scope_type, (int) $r->scope_id])->all())
        ->toBe([
            [strtolower(rppReference('32')), RmeOwnerApprovalReferenceRegistry::SCOPE_POST_PAYMENT_CORRECTION_INVOICE, (int) $a->id],
            [strtolower(rppReference('33')), RmeOwnerApprovalReferenceRegistry::SCOPE_POST_PAYMENT_CORRECTION_INVOICE, (int) $b->id],
        ]);
});

it('refuses to create the registry when existing data already reuses one reference on two invoices', function () {
    $a = rppPaidInvoice(200000);
    $b = rppPaidInvoice(200000);
    rppSubmit($a, reference: rppReference('35'));
    $second = rppSubmit($b, reference: rppReference('36'));

    // Data the registry must not paper over: the same reference (case variant)
    // recorded on two invoices.
    DB::table('trx_rme_invoice_corrections')->where('id', $second->id)
        ->update(['owner_approval_reference' => strtolower(rppReference('35'))]);
    DB::table('trx_rme_owner_approval_references')->delete();
    $migration = rorrMigration();
    $migration->down();

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'reference recorded on more than one invoice');
    expect(DB::getSchemaBuilder()->hasTable('trx_rme_owner_approval_references'))->toBeFalse();
});

it('refuses to drop the registry once it holds a claim', function () {
    rppSubmit(rppPaidInvoice(200000), reference: rppReference('37'));

    expect(fn () => rorrMigration()->down())->toThrow(RuntimeException::class);
    expect(DB::getSchemaBuilder()->hasTable('trx_rme_owner_approval_references'))->toBeTrue();
});

// ─── Readiness reports the registry ──────────────────────────────────────────

it('reports the registry as present and consistent in the readiness report', function () {
    rppSubmit(rppPaidInvoice(200000), reference: rppReference('38'));

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report();

    expect($report['post_payment']['owner_reference_registry'])->toBe([
        'present' => true,
        'claims' => 1,
        'unregistered_post_payment_corrections' => 0,
    ])->and(collect($report['findings'])->pluck('code')->all())->not->toContain('OWNER_REFERENCE_UNREGISTERED');
});

it('fails readiness when a post-payment correction carries a reference the registry does not bind to its invoice', function () {
    rppSubmit(rppPaidInvoice(200000), reference: rppReference('39'));
    DB::table('trx_rme_owner_approval_references')->delete();

    $report = app(RmeInvoiceCorrectionReadinessService::class)->report();

    expect($report['post_payment']['owner_reference_registry']['unregistered_post_payment_corrections'])->toBe(1)
        ->and($report['decision'])->toBe(RmeInvoiceCorrectionReadinessService::FAIL)
        ->and(collect($report['findings'])->firstWhere('code', 'OWNER_REFERENCE_UNREGISTERED')['level'])
        ->toBe(RmeInvoiceCorrectionReadinessService::FAIL);
});
