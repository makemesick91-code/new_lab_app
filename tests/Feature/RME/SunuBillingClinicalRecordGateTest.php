<?php

/**
 * FIX-SUNU-GO-LIVE-BLOCKERS-1 / B5 — a visit is billable only when the
 * clinical record behind it is FINALIZED.
 *
 * Sprint 20 already stated the rule ("Cashier billing requires finalized RME +
 * cashier_pending visit"), but the gate read `if ($medicalRecord && ...)`, so
 * it only ever judged a record that EXISTED. A visit carrying no record at all
 * fell straight through and was billed with a null medical_record_id — a
 * charge with nothing clinical behind it.
 *
 * That was reachable through the ordinary workflow, not only by misuse:
 * reaching `cashier_pending` does not itself write a record. SPN4 visit 52 sat
 * in exactly that state for twelve days, in the Sunu cashier queue, billable.
 *
 * The matrix below pins ABSENT and UNFINALIZED to the same answer, because the
 * defect was treating "missing" as "nothing to object to".
 *
 * Driven through the service, not HTTP: a failure should name the billing rule
 * that broke, not the screen that showed it.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    $this->branch = Branch::factory()->create(['code' => 'SPN4', 'is_rme_enabled' => true]);
    $this->cashier = userWith(['manage_rme_billing']);

    $this->actingAs($this->cashier);
});

function b5Service(): RmeInvoiceService
{
    return app(RmeInvoiceService::class);
}

function b5Items(): array
{
    return [[
        'treatment_id' => null,
        'description' => 'Pemeriksaan Gigi',
        'qty' => 1,
        'unit_price' => 150000,
        'discount' => 0,
    ]];
}

/** A visit at the cashier with NO clinical record — the visit 52 shape. */
function b5VisitWithoutRecord(Branch $branch): ClinicVisit
{
    return ClinicVisit::factory()->cashierPending()->create(['branch_id' => $branch->id]);
}

function b5RecordFor(ClinicVisit $visit, Branch $branch, string $state): MedicalRecord
{
    $factory = $state === 'final'
        ? MedicalRecord::factory()->final()
        : MedicalRecord::factory();

    return $factory->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        ...($state === 'final' ? [] : ['status' => MedicalRecord::STATUS_DRAFT]),
    ]);
}

// ─── The defect itself ───────────────────────────────────────────────────────

it('refuses to bill a cashier-pending visit that has no clinical record at all', function () {
    $visit = b5VisitWithoutRecord($this->branch);

    expect($visit->medicalRecord)->toBeNull();

    expect(fn () => b5Service()->create($visit, $this->cashier, ['items' => b5Items()]))
        ->toThrow(ValidationException::class);

    expect(RmeInvoice::query()->where('clinic_visit_id', $visit->id)->count())->toBe(0);
});

it('gives the missing record the same refusal as an unfinalized one', function () {
    $visit = b5VisitWithoutRecord($this->branch);

    try {
        b5Service()->create($visit, $this->cashier, ['items' => b5Items()]);
        $this->fail('A visit with no clinical record was billed.');
    } catch (ValidationException $e) {
        expect($e->errors()['clinic_visit_id'][0] ?? '')->toContain('RME belum difinalisasi');
    }
});

// ─── The rest of the matrix, so the fix is not a blunt instrument ────────────

it('still refuses a visit whose record exists but is not finalized', function () {
    $visit = b5VisitWithoutRecord($this->branch);
    b5RecordFor($visit, $this->branch, 'draft');

    expect(fn () => b5Service()->create($visit->fresh(), $this->cashier, ['items' => b5Items()]))
        ->toThrow(ValidationException::class);
});

it('bills a visit whose record is finalized', function () {
    $visit = b5VisitWithoutRecord($this->branch);
    $record = b5RecordFor($visit, $this->branch, 'final');

    $invoice = b5Service()->create($visit->fresh(), $this->cashier, ['items' => b5Items()]);

    expect($invoice->exists)->toBeTrue()
        ->and((int) $invoice->branch_id)->toBe((int) $this->branch->id)
        // The charge now always names the record it bills for. A null here was
        // the observable symptom of the old fall-through.
        ->and((int) $invoice->medical_record_id)->toBe((int) $record->id);
});

it('still refuses a visit that has not reached the cashier, record or not', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);
    b5RecordFor($visit, $this->branch, 'final');

    expect(fn () => b5Service()->create($visit->fresh(), $this->cashier, ['items' => b5Items()]))
        ->toThrow(ValidationException::class);
});

it('refuses a finalized visit that is not in an RME-enabled branch', function () {
    $nonRme = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => false]);
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $nonRme->id]);
    b5RecordFor($visit, $nonRme, 'final');

    expect(fn () => b5Service()->create($visit->fresh(), $this->cashier, ['items' => b5Items()]))
        ->toThrow(ValidationException::class);
});

// ─── Fail closed, not open ───────────────────────────────────────────────────

it('refuses rather than bills when the record relation cannot be resolved', function () {
    $visit = b5VisitWithoutRecord($this->branch);

    // A record row that belongs to a DIFFERENT visit must not satisfy this
    // visit's gate. If the guard ever widened to "a final record exists
    // somewhere", this is the test that would catch it.
    $other = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $this->branch->id]);
    b5RecordFor($other, $this->branch, 'final');

    expect(fn () => b5Service()->create($visit->fresh(), $this->cashier, ['items' => b5Items()]))
        ->toThrow(ValidationException::class);
});
