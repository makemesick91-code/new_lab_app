<?php

// POST-RME-ODONTOGRAM-STABILIZATION-1 / FIX-03
//
// THE RESIDUAL THIS CLOSES, quoted from
// docs/sprints/fix-clinic-ops-branch-context-wa-1.md ("Known residual"):
//
//   "StoreClinicVisitRequest still falls back to a UTC today() when composing
//    a new patient's RM number and no registered_at is supplied. ... It is
//    reported, not fixed."
//
// It was broader than reported: FIVE sites derived the registration day from
// the process clock — the two patient FormRequests, the visit FormRequest,
// PatientService::resolveRegisteredAt (the PERSISTING site) and
// PatientMedicalRecordNumberService::composeForRegistration's own fallback.
//
// WHY IT MATTERS: config/app.php hard-codes 'timezone' => 'UTC' deliberately
// (technical instants stay UTC), so Carbon::today() is the UTC calendar day.
// Asia/Makassar is UTC+8, so for the first EIGHT HOURS of every clinical day
// the UTC date is still yesterday. On 1 January that is a different YEAR, and
// the year is baked permanently into the patient's Nomor RM.
//
// These tests pin the clinical calendar as the authority by freezing real UTC
// instants either side of the WITA boundary. They never assert on the machine's
// local timezone.

use App\Modules\Branch\Models\Branch;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use App\Modules\Patient\Services\PatientService;
use App\Support\Clinical\ClinicalClock;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    $this->rmNumbers = app(PatientMedicalRecordNumberService::class);
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Freeze an absolute UTC instant. Asia/Makassar is UTC+8, always (no DST). */
function freezeUtc(string $utcInstant): void
{
    Carbon::setTestNow(Carbon::parse($utcInstant, 'UTC'));
}

/*
|--------------------------------------------------------------------------
| The clinical calendar is the authority — the year boundary
|--------------------------------------------------------------------------
*/

it('composes the RM year from the clinical day, not the UTC day, after WITA midnight', function () {
    // 2026-12-31 16:00 UTC is already 2027-01-01 00:00 in Makassar.
    freezeUtc('2026-12-31 16:00:00');

    expect(app(ClinicalClock::class)->todayString())->toBe('2027-01-01')
        ->and(Carbon::now()->toDateString())->toBe('2026-12-31')  // the UTC day it is NOT
        ->and($this->rmNumbers->composeForRegistration('TKM1', null, '0001'))
        ->toBe('DG-TKM1-2027-0001');
});

it('still composes the outgoing year one second before WITA midnight', function () {
    // 2026-12-31 15:59:59 UTC is 2026-12-31 23:59:59 in Makassar.
    freezeUtc('2026-12-31 15:59:59');

    expect(app(ClinicalClock::class)->todayString())->toBe('2026-12-31')
        ->and($this->rmNumbers->composeForRegistration('TKM1', null, '0001'))
        ->toBe('DG-TKM1-2026-0001');
});

it('composes the same year on both sides of UTC midnight within one clinical day', function () {
    // 23:30 UTC on 1 Jan and 00:30 UTC on 2 Jan are both 2 January in Makassar,
    // and the UTC day changes between them. The RM year must not notice.
    freezeUtc('2027-01-01 23:30:00');
    $beforeUtcMidnight = $this->rmNumbers->composeForRegistration('TKM1', null, '0001');

    freezeUtc('2027-01-02 00:30:00');
    $afterUtcMidnight = $this->rmNumbers->composeForRegistration('TKM1', null, '0001');

    expect($beforeUtcMidnight)->toBe('DG-TKM1-2027-0001')
        ->and($afterUtcMidnight)->toBe($beforeUtcMidnight);
});

it('composes the ordinary daytime year unchanged', function () {
    freezeUtc('2026-06-15 03:00:00'); // 11:00 WITA — same calendar day either way

    expect($this->rmNumbers->composeForRegistration('LDK2', null, '25'))
        ->toBe('DG-LDK2-2026-25');
});

/*
|--------------------------------------------------------------------------
| The persisting site agrees with the pre-check
|--------------------------------------------------------------------------
*/

it('stamps registered_at on the clinical day when none is supplied', function () {
    // 2026-06-14 16:30 UTC is already 2026-06-15 00:30 in Makassar.
    freezeUtc('2026-06-14 16:30:00');

    $patient = app(PatientService::class)->create([
        'name' => 'Boundary Patient',
        'branch_id' => $this->branch->id,
        'manual_rm_number' => '0042',
    ]);

    expect($patient->registered_at->toDateString())->toBe('2026-06-15')
        ->and($patient->medical_record_number)->toBe('DG-'.$this->branch->code.'-2026-0042');
});

it('persists the same RM year the request-time pre-check would have composed', function () {
    // The uniqueness pre-check (FormRequest) and the persist (PatientService)
    // used to derive "today" independently. At the year boundary they could
    // disagree, so the value validated was not the value stored.
    freezeUtc('2026-12-31 16:30:00'); // 2027-01-01 00:30 WITA

    $precomposed = $this->rmNumbers->composeForRegistration($this->branch->code, null, '0007');

    $patient = app(PatientService::class)->create([
        'name' => 'New Year Patient',
        'branch_id' => $this->branch->id,
        'manual_rm_number' => '0007',
    ]);

    expect($patient->medical_record_number)->toBe($precomposed)
        ->and($patient->medical_record_number)->toBe('DG-'.$this->branch->code.'-2027-0007');
});

/*
|--------------------------------------------------------------------------
| A supplied date is a calendar date and is never shifted
|--------------------------------------------------------------------------
*/

it('honours an explicitly supplied registration date verbatim', function () {
    freezeUtc('2027-03-01 09:00:00');

    expect($this->rmNumbers->composeForRegistration('TKM1', Carbon::parse('2024-08-09'), '9985'))
        ->toBe('DG-TKM1-2024-9985');
});

it('never timezone-shifts a supplied registration date across a day boundary', function () {
    freezeUtc('2027-03-01 09:00:00');

    $patient = app(PatientService::class)->create([
        'name' => 'Backdated Patient',
        'branch_id' => $this->branch->id,
        'manual_rm_number' => '0100',
        'registered_at' => '2025-01-01',
    ]);

    expect($patient->registered_at->toDateString())->toBe('2025-01-01')
        ->and($patient->medical_record_number)->toBe('DG-'.$this->branch->code.'-2025-0100');
});

/*
|--------------------------------------------------------------------------
| The identifier FORMAT and the parser are untouched by this fix
|--------------------------------------------------------------------------
*/

it('leaves the Nomor RM format and its parser unchanged', function () {
    $value = $this->rmNumbers->compose('TKM1', 2024, '9985');

    expect($value)->toBe('DG-TKM1-2024-9985');

    $parts = $this->rmNumbers->parse($value);

    expect($parts)->not->toBeNull()
        ->and($parts->branchCode)->toBe('TKM1')
        ->and($parts->year)->toBe('2024')
        ->and($parts->sequence)->toBe('9985')
        ->and($parts->toString())->toBe($value)
        ->and($this->rmNumbers->branchCodeFrom($value))->toBe('TKM1');
});

it('still detects an existing medical record number including soft-deleted patients', function () {
    $patient = Patient::factory()->create(['medical_record_number' => 'DG-TKM1-2026-0001']);

    expect($this->rmNumbers->exists('DG-TKM1-2026-0001'))->toBeTrue();

    $patient->delete();

    expect($this->rmNumbers->exists('DG-TKM1-2026-0001'))->toBeTrue()
        ->and($this->rmNumbers->exists('DG-TKM1-2026-9999'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The registration transaction is internally consistent
|--------------------------------------------------------------------------
*/

it('agrees with the clinical day ClinicVisitService already uses for visit_date', function () {
    // ClinicVisitService adopted ClinicalClock for visit_date in
    // FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 / FIX-06 while RM composition was left
    // on the UTC clock. Inside a single registration that produced a visit
    // dated 2027 next to a patient numbered 2026. Both now read one clock.
    freezeUtc('2026-12-31 17:00:00'); // 2027-01-01 01:00 WITA

    $clinicalDay = app(ClinicalClock::class)->todayString();

    $patient = app(PatientService::class)->create([
        'name' => 'Consistency Patient',
        'branch_id' => $this->branch->id,
        'manual_rm_number' => '0500',
    ]);

    expect($clinicalDay)->toBe('2027-01-01')
        ->and($patient->registered_at->toDateString())->toBe($clinicalDay)
        ->and($patient->medical_record_number)->toContain('-2027-');
});
