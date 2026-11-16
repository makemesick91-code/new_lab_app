<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-7-5) — lab candidate backfill
|--------------------------------------------------------------------------
|
| Owner decision 2026-11-16, "fix with backfill command": a lab-work candidate a
| payment failed to generate (the payment stands; only a redacted log line was
| left) is recovered by `rme:lab-candidates-backfill` — dry run by default with
| counts per clinic month of the latest payment, --apply only with an explicit
| --paid-from window (independent review: "paid and missing" is wider than
| "failed"), idempotent per invoice item, one audit row per invoice it fills,
| counts only on the console. Running --apply on production stays a separate
| owner-approved action.
|
| Selected by the existing `RmePayment` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rlcb` is unique because the critical gate loads every suite into one process.
*/

use App\Console\Commands\RmeLabCandidatesBackfillCommand;
use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::factory()->create(['code' => 'LCB1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->labTreatment = Treatment::factory()->create(['requires_lab' => true, 'is_active' => true]);
    $this->plainTreatment = Treatment::factory()->create(['requires_lab' => false, 'is_active' => true]);
});

/**
 * A PAID invoice whose candidate generation "failed": a lab item with no
 * candidate. A paid / partial invoice carries one payment at `$paidAtUtc`.
 */
function rlcbInvoice(string $state = 'paid', ?Branch $branch = null, ?string $paidAtUtc = null): RmeInvoice
{
    $branch ??= test()->branch;
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id, 'status' => ClinicVisit::STATUS_COMPLETED]);
    $invoice = RmeInvoice::factory()->for($visit, 'clinicVisit')->{$state}()->create([
        'branch_id' => $branch->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
    ]);

    foreach ([test()->labTreatment, test()->plainTreatment] as $treatment) {
        RmeInvoiceItem::query()->create([
            'rme_invoice_id' => $invoice->id,
            'treatment_id' => $treatment->id,
            'description' => $treatment->name,
            'qty' => 1,
            'unit_price' => 100000,
            'discount' => 0,
            'subtotal' => 100000,
        ]);
    }

    if ($state !== 'unpaid') {
        RmePayment::query()->create([
            'branch_id' => $branch->id,
            'rme_invoice_id' => $invoice->id,
            'clinic_visit_id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'cashier_id' => User::factory()->create()->id,
            'payment_number' => 'RMEPAY-LCB-'.Str::upper(Str::random(10)),
            'amount' => 200000,
            'paid_at' => $paidAtUtc ?? now()->subHour()->format('Y-m-d H:i:s'),
        ]);
    }

    return $invoice->fresh();
}

/** A window start safely before every fixture payment (clinic date, WITA). */
function rlcbSince(): string
{
    return Carbon::now('Asia/Makassar')->subDays(2)->toDateString();
}

function rlcbRun(array $options = []): array
{
    $code = Artisan::call('rme:lab-candidates-backfill', $options);

    return [$code, Artisan::output()];
}

it('LB-01 finds the missing candidate on a dry run and writes nothing', function () {
    rlcbInvoice();

    [$code, $out] = rlcbRun();

    expect($code)->toBe(0)
        ->and($out)->toContain('INVOICES=1')
        ->and($out)->toContain('LAB_CANDIDATE_BACKFILL=DRY_RUN')
        ->and(LabCaseCandidate::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', RmeLabCandidatesBackfillCommand::AUDIT_ACTION)->count())->toBe(0);
});

it('LB-02 generates exactly the lab item candidate on --apply and audits the invoice', function () {
    $invoice = rlcbInvoice();
    $labItem = $invoice->items()->where('treatment_id', $this->labTreatment->id)->firstOrFail();

    [$code, $out] = rlcbRun(['--apply' => true, '--paid-from' => rlcbSince()]);

    $candidate = LabCaseCandidate::query()->sole();
    $audit = AuditLog::query()->where('action', RmeLabCandidatesBackfillCommand::AUDIT_ACTION)->sole();

    expect($code)->toBe(0)
        ->and($out)->toContain('CANDIDATES_CREATED=1')
        ->and($out)->toContain('LAB_CANDIDATE_BACKFILL=APPLIED')
        ->and((int) $candidate->rme_invoice_item_id)->toBe((int) $labItem->id)
        ->and($candidate->status)->toBe(LabCaseCandidate::STATUS_PENDING_REVIEW)
        ->and((int) $audit->entity_id)->toBe((int) $invoice->id)
        ->and($audit->new_values['candidates_created'])->toBe(1);
});

it('LB-03 is idempotent: a second --apply finds nothing and creates nothing', function () {
    rlcbInvoice();
    rlcbRun(['--apply' => true, '--paid-from' => rlcbSince()]);

    [$code, $out] = rlcbRun(['--apply' => true, '--paid-from' => rlcbSince()]);

    expect($code)->toBe(0)
        ->and($out)->toContain('INVOICES=0')
        ->and(LabCaseCandidate::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', RmeLabCandidatesBackfillCommand::AUDIT_ACTION)->count())->toBe(1);
});

it('LB-04 never touches an unpaid or partially paid invoice, nor a non-RME branch', function () {
    rlcbInvoice('unpaid');
    rlcbInvoice('partial');
    rlcbInvoice('paid', Branch::factory()->create(['code' => 'LCB9', 'is_active' => true, 'is_rme_enabled' => false]));

    [, $out] = rlcbRun(['--apply' => true, '--paid-from' => rlcbSince()]);

    expect($out)->toContain('INVOICES=0')
        ->and(LabCaseCandidate::query()->count())->toBe(0);
});

it('LB-05 keeps a candidate that already exists and prints no patient detail', function () {
    $invoice = rlcbInvoice();
    rlcbRun(['--apply' => true, '--paid-from' => rlcbSince()]);
    $existing = LabCaseCandidate::query()->sole();

    [, $out] = rlcbRun(['--apply' => true, '--paid-from' => rlcbSince()]);

    expect(LabCaseCandidate::query()->sole()->id)->toBe($existing->id)
        ->and($out)->not->toContain((string) $invoice->invoice_number)
        ->and($out)->not->toContain((string) $invoice->clinicVisit?->patient?->name);
});

it('LB-06 refuses --apply without an explicit --paid-from window and writes nothing', function () {
    rlcbInvoice();

    [$code, $out] = rlcbRun(['--apply' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('--paid-from')
        ->and(LabCaseCandidate::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', RmeLabCandidatesBackfillCommand::AUDIT_ACTION)->count())->toBe(0);
});

it('LB-07 bounds the set by the clinic date of the latest payment (WITA), --paid-to inclusive', function () {
    // 2026-11-10 23:30 UTC is 2026-11-11 07:30 WITA: inside a window starting on the 11th.
    $inside = rlcbInvoice('paid', null, '2026-11-10 23:30:00');
    // 2026-11-10 15:00 UTC is 2026-11-10 23:00 WITA: the day before.
    $before = rlcbInvoice('paid', null, '2026-11-10 15:00:00');
    // 2026-11-12 16:30 UTC is 2026-11-13 00:30 WITA: after a window ending on the 12th.
    $after = rlcbInvoice('paid', null, '2026-11-12 16:30:00');

    [$code, $out] = rlcbRun(['--apply' => true, '--paid-from' => '2026-11-11', '--paid-to' => '2026-11-12']);

    $filled = LabCaseCandidate::query()->pluck('rme_invoice_item_id')->all();
    $labItemOf = fn (RmeInvoice $invoice) => (int) $invoice->items()->where('treatment_id', $this->labTreatment->id)->value('id');

    expect($code)->toBe(0)
        ->and($out)->toContain('INVOICES=1')
        ->and(array_map('intval', $filled))->toBe([$labItemOf($inside)])
        ->and($filled)->not->toContain($labItemOf($before))
        ->and($filled)->not->toContain($labItemOf($after));
});

it('LB-08 prints dry-run counts per clinic month of the latest payment, and refuses an invalid date', function () {
    rlcbInvoice('paid', null, '2026-10-31 16:30:00'); // 2026-11-01 00:30 WITA
    rlcbInvoice('paid', null, '2026-10-31 15:30:00'); // 2026-10-31 23:30 WITA

    [$code, $out] = rlcbRun();

    expect($code)->toBe(0)
        ->and($out)->toMatch('/^PAID_MONTH_2026-10=1$/m')
        ->and($out)->toMatch('/^PAID_MONTH_2026-11=1$/m')
        ->and(LabCaseCandidate::query()->count())->toBe(0);

    [$bad] = rlcbRun(['--paid-from' => '2026-02-30']);

    expect($bad)->toBe(1);
});
