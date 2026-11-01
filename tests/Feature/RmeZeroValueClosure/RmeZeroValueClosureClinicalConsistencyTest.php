<?php

declare(strict_types=1);

/*
| HARDENING-RME-RP0-CLINICAL-BILLING-CONSISTENCY-1 (rule 218) — the clinical vs
| billing cross-check of "Selesai Tanpa Biaya".
|
| THE FINDING THIS SUITE PINS. DaengtisiaMS holds no structured record of a
| treatment the doctor PERFORMED: the RME is handwriting, the odontogram is a
| tooth-condition chart, a structured diagnosis is a diagnosis. Owner decisions of
| 2026-11-01: CB-Q1 — the authoritative evidence will be a structured doctor input,
| built in a separate sprint (CB-Q3); CB-Q2 — until then every signal is DETECTIVE:
| it is recorded and surfaced, and it never refuses a closure.
|
| So the cross-check can only ever say REVIEW_REQUIRED (a structured signal fired),
| INSUFFICIENT_STRUCTURED_EVIDENCE (none fired — which is NOT "consistent"), or
| NOT_APPLICABLE (not a Rp0 invoice). It never says CONSISTENT and never says
| CONFIRMED_DISCREPANCY: there is no authoritative source to confirm either.
|
| Each test carries the scenario id of the master prompt (CBC-xx).
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\Odontogram\Interfaces\OdontogramRepositoryInterface;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Services\RmeClinicalBillingConsistencyService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeClinicalBillingConsistency;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\Treatment\Models\Treatment;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

const RCC_SECRET_NOTE = 'CATATAN-KLINIS-RAHASIA-RCC';

beforeEach(function () {
    rzvSetup();

    $t = test();
    $category = fn (string $code) => TreatmentCategory::query()->firstOrCreate(['code' => $code], ['name' => ucfirst($code)]);
    $make = fn (string $code, string $name, string $categoryCode, bool $lab = false) => Treatment::factory()->create([
        'code' => $code, 'name' => $name, 'treatment_category_id' => $category($categoryCode)->id,
        'is_active' => true, 'requires_lab' => $lab,
    ]);

    $t->scaling = $make('TRT-SCAL', 'Scaling Gigi', 'preventive');
    $t->tambal = $make('TRT-FILL', 'Tambal Komposit', 'restoration');
    $t->controlService = $make('TRT-KONTROL', 'Kontrol', 'control');
});

/** An examined earlier visit of the SAME patient, $daysAgo clinical days before today. */
function rccEarlierVisit(int $daysAgo = 7, ?Branch $branch = null, ?Patient $patient = null): ClinicVisit
{
    return ClinicVisit::factory()->completed()->create([
        'branch_id' => ($branch ?? test()->branch)->id,
        'clinic_id' => test()->clinic->id,
        'patient_id' => ($patient ?? test()->patient)->id,
        'doctor_id' => test()->doctor->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'visit_number' => 'VIS-RCC-'.uniqid(),
        'visit_date' => clinicalToday()->subDays($daysAgo)->toDateString(),
    ]);
}

/**
 * A charted odontogram of $visit. $teeth is FDI => status; the free-text note is
 * always set so every test also proves free text is never read or copied.
 *
 * @param  array<string, string>  $teeth
 */
function rccChart(ClinicVisit $visit, array $teeth): Odontogram
{
    $payload = [];
    foreach ($teeth as $fdi => $status) {
        $payload[(string) $fdi] = ['status' => $status, 'additional_note' => RCC_SECRET_NOTE, 'note' => RCC_SECRET_NOTE];
    }

    return Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'status' => Odontogram::STATUS_FINALIZED,
        'summary_notes' => RCC_SECRET_NOTE,
        'tooth_map_payload' => ['teeth' => $payload],
    ]);
}

function rccRegister(ClinicVisit $visit, ?Treatment $treatment): ClinicVisit
{
    DB::table('trx_clinic_visits')->where('id', $visit->id)->update(['initial_treatment_id' => $treatment?->id]);

    return $visit->fresh();
}

/** The cross-check snapshot of the closure audit row of $invoice. */
function rccSnapshot(RmeInvoice $invoice): ?array
{
    $row = AuditLog::query()
        ->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_ZERO_VALUE_CLOSED)
        ->where('entity_id', $invoice->id)
        ->sole();

    return $row->new_values['clinical_billing_consistency'] ?? null;
}

function rccCashierPage(RmeInvoice $invoice)
{
    return test()->actingAs(test()->cashier)->get(route('rme.cashier.show', [$invoice->clinic_visit_id, $invoice->id]));
}

// ─── The vocabulary: never "consistent", never "confirmed" ───────────────────

it('CBC-01: a free Konsultasi with no structured clinical evidence closes and is recorded as INSUFFICIENT_STRUCTURED_EVIDENCE — never as consistent', function () {
    $closed = rzvClose(rzvConsultationInvoice());

    expect($closed->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and(rccSnapshot($closed))->toMatchArray([
            'version' => 1,
            'status' => 'INSUFFICIENT_STRUCTURED_EVIDENCE',
            'signals' => [],
            'registered_treatment_id' => null,
            'odontogram_id' => null,
            'baseline_odontogram_id' => null,
            'procedure_change_tooth_count' => 0,
        ]);
});

it('CBC-02: a valid free Kontrol closes and its cross-check is recorded', function () {
    $visit = rccRegister(rzvControlVisit(), test()->controlService);
    $closed = rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)]));

    expect($closed->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and(rccSnapshot($closed)['status'])->toBe('INSUFFICIENT_STRUCTURED_EVIDENCE')
        // The Kontrol service is an approved free service on a VALID Kontrol (S6-Q3a).
        ->and(rccSnapshot($closed)['signals'])->toBe([])
        ->and(rccSnapshot($closed)['registered_treatment_free'])->toBeTrue();
});

it('never produces a status outside the detective vocabulary', function () {
    $statuses = [];
    foreach ([null, test()->scaling, test()->consultation] as $registered) {
        $statuses[] = rccSnapshot(rzvClose(rzvInvoice(rccRegister(rzvNewVisit(), $registered), [rzvLine(test()->consultation)])))['status'];
    }

    expect($statuses)->each->toBeIn(['REVIEW_REQUIRED', 'INSUFFICIENT_STRUCTURED_EVIDENCE'])
        ->and($statuses)->not->toContain('CONSISTENT')
        ->and($statuses)->not->toContain('CONFIRMED_DISCREPANCY');
});

// ─── Signal 1: the registered treatment (Tindakan Awal) ──────────────────────

it('CBC-03: Tindakan Awal Scaling billed only as a free Konsultasi is REVIEW_REQUIRED — and the closure is still allowed (CB-Q2 detective)', function () {
    $visit = rccRegister(rzvNewVisit(), test()->scaling);
    $before = rzvMoney();

    $closed = rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)]));

    expect($closed->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and(rccSnapshot($closed))->toMatchArray([
            'status' => 'REVIEW_REQUIRED',
            'signals' => ['CBC_REGISTERED_TREATMENT_NOT_BILLED'],
            'registered_treatment_id' => (int) test()->scaling->id,
            'registered_treatment_free' => false,
            'registered_treatment_billed' => false,
        ])
        // CBC-27/28/29 — a signal is a finding, never a financial adjustment.
        ->and(rzvMoney())->toBe($before)
        ->and(DB::table('trx_rme_payments')->where('rme_invoice_id', $closed->id)->count())->toBe(0)
        ->and(rzvClosureAudits($closed))->toBe(1);
});

it('CBC-05: Tindakan Awal Konsultasi (an approved free service) raises no signal', function () {
    $closed = rzvClose(rzvInvoice(rccRegister(rzvNewVisit(), test()->consultation), [rzvLine(test()->consultation)]));

    expect(rccSnapshot($closed))->toMatchArray([
        'status' => 'INSUFFICIENT_STRUCTURED_EVIDENCE',
        'signals' => [],
        'registered_treatment_free' => true,
        'registered_treatment_billed' => true,
    ]);
});

it('treats the Kontrol service as free only on a VALID Kontrol — on a new visit Tindakan Awal Kontrol is a signal', function () {
    $closed = rzvClose(rzvInvoice(rccRegister(rzvNewVisit(), test()->controlService), [rzvLine(test()->consultation)]));

    expect(rccSnapshot($closed)['signals'])->toBe(['CBC_REGISTERED_TREATMENT_NOT_BILLED'])
        ->and(rccSnapshot($closed)['registered_treatment_free'])->toBeFalse();
});

it('reads the registered treatment from the server row, soft-deleted master data included', function () {
    $visit = rccRegister(rzvNewVisit(), test()->scaling);
    test()->scaling->delete();

    expect(rccSnapshot(rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)])))['signals'])
        ->toBe(['CBC_REGISTERED_TREATMENT_NOT_BILLED']);
});

// ─── Signal 2: the odontogram compared with the previous chart ───────────────

it('CBC-04: caries → filling on the same tooth since the previous chart is REVIEW_REQUIRED with one changed tooth', function () {
    $baseline = rccChart(rccEarlierVisit(7), ['16' => 'caries']);
    $visit = rzvNewVisit();
    $current = rccChart($visit, ['16' => 'filling']);

    $closed = rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)]));

    expect($closed->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and(rccSnapshot($closed))->toMatchArray([
            'status' => 'REVIEW_REQUIRED',
            'signals' => ['CBC_ODONTOGRAM_PROCEDURE_CHANGE'],
            'odontogram_id' => (int) $current->id,
            'baseline_odontogram_id' => (int) $baseline->id,
            'procedure_change_tooth_count' => 1,
        ]);
});

it('CBC-06/07: counts a new filling, extraction, crown or root treatment — a newly found caries is a finding, not a procedure', function () {
    rccChart(rccEarlierVisit(7), ['16' => 'caries', '17' => 'normal', '26' => 'normal', '36' => 'caries', '46' => 'normal']);
    $visit = rzvNewVisit();
    rccChart($visit, ['16' => 'filling', '17' => 'missing', '26' => 'crown', '36' => 'root_treated', '46' => 'caries']);

    expect(rccSnapshot(rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)])))['procedure_change_tooth_count'])->toBe(4);
});

it('CBC-07: a structured diagnosis alone never raises a signal — a diagnosis is not a treatment', function () {
    $visit = rzvNewVisit();
    $record = MedicalRecord::query()->where('clinic_visit_id', $visit->id)->firstOrFail();
    MedicalRecordDiagnosis::query()->create([
        'medical_record_id' => $record->id, 'clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id,
        'clinical_diagnosis_id' => ClinicalDiagnosis::factory()->create()->id,
        'diagnosis_role' => MedicalRecordDiagnosis::ROLE_PRIMARY,
    ]);

    expect(rccSnapshot(rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)])))['status'])->toBe('INSUFFICIENT_STRUCTURED_EVIDENCE');
});

it('CBC-08: free text is never interpreted and never copied — not the RME notes, not the odontogram notes', function () {
    $visit = rzvNewVisit();
    MedicalRecord::query()->where('clinic_visit_id', $visit->id)->update(['notes' => 'Scaling dilakukan '.RCC_SECRET_NOTE]);
    rccChart(rccEarlierVisit(7), ['16' => 'filling']);
    rccChart($visit, ['16' => 'filling']);

    $closed = rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)]));
    $audit = (string) json_encode(AuditLog::query()->where('entity_id', $closed->id)
        ->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_ZERO_VALUE_CLOSED)->sole()->new_values);

    expect(rccSnapshot($closed)['status'])->toBe('INSUFFICIENT_STRUCTURED_EVIDENCE')
        ->and($audit)->not->toContain(RCC_SECRET_NOTE)
        ->and($audit)->not->toContain('Scaling dilakukan');
});

it('CBC-09: a first chart with no earlier chart has no baseline and raises no odontogram signal', function () {
    $visit = rzvNewVisit();
    rccChart($visit, ['16' => 'filling', '26' => 'crown']);

    expect(rccSnapshot(rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)]))))->toMatchArray([
        'status' => 'INSUFFICIENT_STRUCTURED_EVIDENCE',
        'baseline_odontogram_id' => null,
        'procedure_change_tooth_count' => 0,
    ]);
});

it('CBC-10: a tooth the earlier chart did not record is not a change — an uncharted tooth is unknown, not normal', function () {
    rccChart(rccEarlierVisit(7), ['11' => 'normal']);
    $visit = rzvNewVisit();
    rccChart($visit, ['16' => 'filling']);

    expect(rccSnapshot(rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)])))['procedure_change_tooth_count'])->toBe(0);
});

it('CBC-11: an existing restoration that stays the same is not a change', function () {
    rccChart(rccEarlierVisit(7), ['16' => 'filling', '26' => 'crown']);
    $visit = rzvNewVisit();
    rccChart($visit, ['16' => 'filling', '26' => 'crown']);

    expect(rccSnapshot(rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)])))['signals'])->toBe([]);
});

it('CBC-12: the MOST RECENT earlier chart is the baseline', function () {
    rccChart(rccEarlierVisit(14), ['16' => 'caries']);
    $recent = rccChart(rccEarlierVisit(7), ['16' => 'filling']);
    $visit = rzvNewVisit();
    rccChart($visit, ['16' => 'filling']);

    expect(rccSnapshot(rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)]))))->toMatchArray([
        'baseline_odontogram_id' => (int) $recent->id,
        'procedure_change_tooth_count' => 0,
    ]);
});

it('CBC-13: another patient\'s chart is never the baseline', function () {
    $other = Patient::factory()->create(['branch_id' => test()->branch->id]);
    rccChart(rccEarlierVisit(7, null, $other), ['16' => 'caries']);
    $visit = rzvNewVisit();
    rccChart($visit, ['16' => 'filling']);

    expect(rccSnapshot(rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)]))))->toMatchArray([
        'baseline_odontogram_id' => null,
        'procedure_change_tooth_count' => 0,
    ]);
});

it('CBC-14: a LATER visit\'s chart is never the baseline, and a soft-deleted chart is ignored', function () {
    rccChart(rccEarlierVisit(-3), ['16' => 'caries']); // three days in the future
    $deleted = rccChart(rccEarlierVisit(7), ['16' => 'caries']);
    $deleted->delete();
    $visit = rzvNewVisit();
    rccChart($visit, ['16' => 'filling']);

    expect(rccSnapshot(rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)]))))->toMatchArray([
        'baseline_odontogram_id' => null,
        'procedure_change_tooth_count' => 0,
    ]);
});

it('CBC-14b: a soft-deleted chart of THIS visit is no evidence', function () {
    rccChart(rccEarlierVisit(7), ['16' => 'caries']);
    $visit = rzvNewVisit();
    rccChart($visit, ['16' => 'filling'])->delete();

    expect(rccSnapshot(rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)]))))->toMatchArray([
        'odontogram_id' => null,
        'procedure_change_tooth_count' => 0,
    ]);
});

it('CBC-15: a chart at a branch that is not RME-enabled is never the baseline', function () {
    $main = Branch::factory()->create(['code' => 'RCCMAIN', 'is_rme_enabled' => false, 'is_active' => true]);
    rccChart(rccEarlierVisit(7, $main), ['16' => 'caries']);
    $visit = rzvNewVisit();
    rccChart($visit, ['16' => 'filling']);

    expect(rccSnapshot(rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)])))['baseline_odontogram_id'])->toBeNull();
});

it('CBC-15b: the patient\'s chart at ANOTHER RME branch is a valid baseline — patient history is cross-branch, the output is a count', function () {
    $other = Branch::factory()->create(['code' => 'RCCB2', 'is_rme_enabled' => true, 'is_active' => true]);
    $baseline = rccChart(rccEarlierVisit(7, $other), ['16' => 'caries']);
    $visit = rzvNewVisit();
    rccChart($visit, ['16' => 'filling']);

    expect(rccSnapshot(rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)])))['baseline_odontogram_id'])->toBe((int) $baseline->id);
});

it('records both signals when both fire', function () {
    rccChart(rccEarlierVisit(7), ['16' => 'caries']);
    $visit = rccRegister(rzvNewVisit(), test()->tambal);
    rccChart($visit, ['16' => 'filling']);

    expect(rccSnapshot(rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)])))['signals'])
        ->toBe(['CBC_ODONTOGRAM_PROCEDURE_CHANGE', 'CBC_REGISTERED_TREATMENT_NOT_BILLED']);
});

// ─── The cross-check never relaxes eligibility ───────────────────────────────

it('CBC-20/22: the cross-check never makes an ineligible invoice closable — a paid treatment at Rp0 is still refused', function () {
    $visit = rccRegister(rzvNewVisit(), test()->scaling);
    $invoice = rzvInvoice($visit, [rzvLine(test()->scaling, 0, 0, 'Konsultasi dokter')]);

    expect(rzvRefusal($invoice))->toContain('Konsultasi Dokter')
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and(rzvClosureAudits($invoice))->toBe(0);
});

it('CBC-26: a mixed invoice is NOT_APPLICABLE and is paid normally — the cross-check never enters a payment', function () {
    $visit = rccRegister(rzvNewVisit(), test()->scaling);
    $invoice = rzvInvoice($visit, [rzvLine(test()->consultation), rzvLine(test()->scaling, 250000, 0, 'Scaling')]);

    $result = app(RmeClinicalBillingConsistencyService::class)->evaluate($invoice, $visit);

    expect($result->status())->toBe('NOT_APPLICABLE')
        ->and($result->signals())->toBe([])
        ->and(app(RmePaymentService::class)->zeroValueClosurePanel($invoice)['applies'])->toBeFalse();
});

it('CBC-43: an idempotent replay writes no second snapshot', function () {
    $closed = rzvClose(rzvInvoice(rccRegister(rzvNewVisit(), test()->scaling), [rzvLine(test()->consultation)]));
    rzvClose($closed);

    expect(rzvClosureAudits($closed))->toBe(1)
        ->and(rccSnapshot($closed)['status'])->toBe('REVIEW_REQUIRED');
});

it('CBC-16/47: the snapshot is the state AT CLOSURE — a later edit of the visit or master data never rewrites it', function () {
    $closed = rzvClose(rzvConsultationInvoice());
    $before = rccSnapshot($closed);

    rccRegister($closed->clinicVisit, test()->scaling);
    test()->consultation->update(['requires_lab' => true]);

    expect(rccSnapshot($closed))->toBe($before)
        ->and($before['status'])->toBe('INSUFFICIENT_STRUCTURED_EVIDENCE')
        ->and($closed->fresh()->status)->toBe(RmeInvoice::STATUS_NO_CHARGE);
});

it('pure: a registered paid treatment that IS on the bill raises no signal (the billed half of signal 1)', function () {
    // Unreachable through an ELIGIBLE closure (a paid line at Rp0 is refused first),
    // so the pure function is pinned directly.
    $visit = rzvNewVisit();
    $registered = Treatment::query()->with('category')->findOrFail(test()->scaling->id);
    $invoice = (new RmeInvoice)->forceFill(['grand_total' => 0]);
    $billed = (new RmeInvoiceItem)->forceFill(['treatment_id' => $registered->id]);

    $onBill = RmeClinicalBillingConsistency::evaluate(
        $invoice->setRelation('items', collect([$billed])), $visit, $registered, null, null,
    );
    $offBill = RmeClinicalBillingConsistency::evaluate(
        (new RmeInvoice)->forceFill(['grand_total' => 0])->setRelation('items', collect()), $visit, $registered, null, null,
    );

    expect($onBill->signals())->toBe([])
        ->and($onBill->evidence()['registered_treatment_billed'])->toBeTrue()
        ->and($offBill->signals())->toBe(['CBC_REGISTERED_TREATMENT_NOT_BILLED']);
});

it('pure: a positive-total invoice is NOT_APPLICABLE even when a signal would otherwise fire (mutation M01)', function () {
    $visit = rzvNewVisit();
    $registered = Treatment::query()->with('category')->findOrFail(test()->scaling->id);
    $paid = (new RmeInvoice)->forceFill(['grand_total' => 250000])->setRelation('items', collect());

    $result = RmeClinicalBillingConsistency::evaluate($paid, $visit, $registered, null, null);

    expect($result->status())->toBe('NOT_APPLICABLE')
        ->and($result->signals())->toBe([])
        ->and($result->evidence())->toBe([]);
});

it('the baseline lookup fails closed on an empty branch set (mutation M23)', function () {
    rccChart(rccEarlierVisit(7), ['16' => 'caries']);
    $visit = rzvNewVisit();
    $repository = app(OdontogramRepositoryInterface::class);

    expect($repository->latestChartedBeforeVisit([], $visit))->toBeNull()
        // Control: the same lookup over the real RME branch set does find the chart.
        ->and($repository->latestChartedBeforeVisit([(int) test()->branch->id], $visit))->not->toBeNull();
});

it('an EMPTY draft chart of this visit is no evidence', function () {
    rccChart(rccEarlierVisit(7), ['16' => 'caries']);
    $visit = rzvNewVisit();
    Odontogram::factory()->create(['clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id, 'tooth_map_payload' => ['teeth' => []]]);

    expect(rccSnapshot(rzvClose(rzvInvoice($visit, [rzvLine(test()->consultation)]))))->toMatchArray([
        'odontogram_id' => null,
        'baseline_odontogram_id' => null,
    ]);
});

it('takes no actor — every authorized closer, Super Admin included, gets the same answer', function () {
    $method = new ReflectionMethod(RmeClinicalBillingConsistencyService::class, 'evaluate');
    $types = array_map(fn (ReflectionParameter $p) => (string) $p->getType(), $method->getParameters());

    expect($types)->not->toContain(User::class);
});

// ─── Cashier page: a notice, never a refusal ─────────────────────────────────

it('shows a non-blocking notice on REVIEW_REQUIRED and keeps the closure form', function () {
    $visit = rccRegister(rzvNewVisit(), test()->scaling);
    rccChart(rccEarlierVisit(7), ['16' => 'caries']);
    rccChart($visit, ['16' => 'filling']);
    $invoice = rzvInvoice($visit, [rzvLine(test()->consultation)]);

    $html = rccCashierPage($invoice)->assertOk()
        ->assertSee('data-zero-value-consistency-notice', false)
        ->assertSee('data-zero-value-closure-form', false)
        ->assertSee('Scaling Gigi')
        ->getContent();

    // CBC-35/37 — the NOTICE adds no clinical detail: a count, never a tooth number
    // or a note. (The page's pre-existing clinical summary, Sprint 62 / e48c6450,
    // already shows the cashier this visit's odontogram; this rule does not widen it.)
    $notice = rccNoticeBlock($html);
    expect($notice)->not->toContain(RCC_SECRET_NOTE)
        ->and($notice)->not->toContain('16')
        ->and($html)->toMatch('/data-zero-value-consistency-tooth-count>\s*1\s*</');
});

/** The rendered cross-check notice element, alone. */
function rccNoticeBlock(string $html): string
{
    $start = strpos($html, 'data-zero-value-consistency-notice');
    expect($start)->not->toBeFalse();
    $end = strpos($html, 'data-zero-value-closure-form', (int) $start);

    return substr($html, (int) $start, ($end === false ? strlen($html) : $end) - (int) $start);
}

it('review F1: the notice tells the cashier to ESCALATE, never to re-bill a Rp0 invoice (D28 — there is no in-app path)', function () {
    $invoice = rzvInvoice(rccRegister(rzvNewVisit(), test()->scaling), [rzvLine(test()->consultation)]);

    $notice = rccNoticeBlock(rccCashierPage($invoice)->assertOk()->getContent());

    expect($notice)->toContain('data-zero-value-consistency-escalation')
        ->and($notice)->toContain('laporkan ke penanggung jawab cabang sebagai insiden')
        ->and($notice)->toContain('tidak dapat dikoreksi di aplikasi')
        ->and($notice)->not->toContain('tagih dengan harga lebih dari Rp0');
});

it('review F4: on a Kontrol whose previous chart is its PARENT visit, the change is flagged as possibly done and billed there', function () {
    $parent = rzvNewVisit();
    rccChart($parent, ['16' => 'caries']);
    $control = rzvControlVisit($parent);
    rccChart($control, ['16' => 'filling']);
    $invoice = rzvInvoice($control, [rzvLine(test()->consultation)]);

    $notice = rccNoticeBlock(rccCashierPage($invoice)->assertOk()->getContent());

    expect($notice)->toContain('kunjungan asal Kontrol ini')
        ->and(rccSnapshot(rzvClose($invoice)))->toMatchArray([
            'signals' => ['CBC_ODONTOGRAM_PROCEDURE_CHANGE'],
            'baseline_is_control_parent' => true,
        ]);
});

it('review F2: a FAULT in the cross-check fails the closure CLOSED with a clear refusal — never a 500, never a NO_CHARGE without its snapshot', function () {
    $invoice = rzvConsultationInvoice();
    $before = rzvMoney();
    app()->instance(OdontogramRepositoryInterface::class, rccThrowingOdontograms());

    expect(rzvRefusal($invoice))->toContain('Pemeriksaan konsistensi klinis tidak dapat dijalankan')
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($invoice->fresh()->clinicVisit->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING)
        ->and(rzvClosureAudits($invoice))->toBe(0)
        ->and(rzvMoney())->toBe($before);
});

it('review F2: a FAULT in the cross-check never takes the cashier page down — it renders without a notice', function () {
    $invoice = rzvConsultationInvoice();
    app()->instance(OdontogramRepositoryInterface::class, rccThrowingOdontograms());

    rccCashierPage($invoice)->assertOk()
        ->assertSee('data-zero-value-closure-form', false)
        ->assertDontSee('data-zero-value-consistency-notice', false);
});

/** An odontogram repository whose every read fails — a stand-in for a database fault. */
function rccThrowingOdontograms(): OdontogramRepositoryInterface
{
    return new class implements OdontogramRepositoryInterface
    {
        public function findByClinicVisit(int $clinicVisitId): ?Odontogram
        {
            throw new RuntimeException('simulated fault');
        }

        public function findByClinicVisitForUpdate(int $clinicVisitId): ?Odontogram
        {
            throw new RuntimeException('simulated fault');
        }

        public function createForClinicVisit(ClinicVisit $clinicVisit, array $data = []): Odontogram
        {
            throw new RuntimeException('simulated fault');
        }

        public function updatePlaceholder(Odontogram $odontogram, array $data): Odontogram
        {
            throw new RuntimeException('simulated fault');
        }

        public function finalize(Odontogram $odontogram, array $data): Odontogram
        {
            throw new RuntimeException('simulated fault');
        }

        public function patientHistoryForBranches(array $branchIds, int $patientId, ?int $excludeVisitId = null, ?Closure $scope = null, int $limit = 50): Collection
        {
            throw new RuntimeException('simulated fault');
        }

        public function latestChartedBeforeVisit(array $branchIds, ClinicVisit $visit): ?Odontogram
        {
            throw new RuntimeException('simulated fault');
        }
    };
}

it('shows no notice when no signal fired', function () {
    rccCashierPage(rzvConsultationInvoice())->assertOk()
        ->assertSee('data-zero-value-closure-form', false)
        ->assertDontSee('data-zero-value-consistency-notice', false);
});

it('CBC-48: with the flag OFF the panel carries no cross-check and the cashier page is unchanged', function () {
    $invoice = rzvInvoice(rccRegister(rzvNewVisit(), test()->scaling), [rzvLine(test()->consultation)]);
    rzvFlag(false);

    expect(app(RmePaymentService::class)->zeroValueClosurePanel($invoice))->not->toHaveKey('consistency');
    rccCashierPage($invoice)->assertOk()->assertDontSee('data-zero-value-consistency-notice', false);
});

it('evaluates nothing on an ineligible Rp0 invoice — it is refused for its own reason', function () {
    $invoice = rzvIssueWhileOff(rccRegister(rzvNewVisit(), test()->scaling), [rzvLine(test()->scaling)]);

    expect(app(RmePaymentService::class)->zeroValueClosurePanel($invoice)['consistency'] ?? null)->toBeNull();
});
