<?php

/**
 * DAENGTISIAMS-SUNU-FINAL-RELEASE-CANDIDATE-1 / D3 — the registration-date
 * field must default to the CLINIC's calendar day, not the server's UTC one.
 *
 * The manual registration date itself was already implemented: `registered_at`
 * exists on `mst_patients`, both write paths validate it, and it is what
 * `PatientMedicalRecordNumberService::composeForRegistration()` reads to build
 * the YEAR inside the RM number.
 *
 * What was missed is the PRE-FILLED DEFAULT on the two forms. FIX-03 moved
 * `PatientService`, the RM number service and both FormRequests onto
 * `ClinicalClock` (Asia/Makassar), but the Blade inputs still wrote
 * `now()->format('Y-m-d')`, which resolves `config('app.timezone')` = UTC.
 *
 * Between 00:00 and 08:00 WITA those disagree by a day. The server trusts a
 * submitted date verbatim, so an operator registering a patient early in the
 * morning would silently persist YESTERDAY as the clinical registration day —
 * and across a new-year boundary, the wrong RM year, permanently.
 *
 * Production blast radius when this was found was zero: no patient had yet
 * been created inside the risk window. These tests exist so it stays zero.
 *
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §2) — the forms no
 * longer offer a registration date or a manual number at all: the server stamps
 * the clinical day and issues the number. What the forms still show is the
 * automatic pattern with the RM year, and that year must be the clinical one.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'SPN4', 'is_rme_enabled' => true]);
});

/**
 * An instant inside the window where UTC and WITA disagree about the date.
 *
 * 17:00 UTC is 01:00 the NEXT day in Asia/Makassar, so a UTC-derived default
 * is provably a different string from the clinical one.
 */
function d3PinEarlyMorningWita(): array
{
    pinTestClock('2026-09-19 17:00:00');

    $utcDate = now()->format('Y-m-d');            // 2026-09-19
    $clinicalDate = clinicalToday()->toDateString(); // 2026-09-20

    expect($clinicalDate)->not->toBe($utcDate, 'fixture is vacuous: the two dates agree');

    return ['utc' => $utcDate, 'clinical' => $clinicalDate];
}

/** 17:00 UTC on 31 December is 01:00 on 1 January in Asia/Makassar. */
function d3PinNewYearWita(): void
{
    pinTestClock('2026-12-31 17:00:00');

    expect(now()->format('Y'))->toBe('2026')
        ->and(clinicalToday()->format('Y'))->toBe('2027', 'fixture is vacuous: the two years agree');
}

it('shows the master-data automatic RM year as the clinical year, not UTC, with no date input', function () {
    d3PinNewYearWita();

    $actor = userWith(['manage patients']);

    $this->actingAs($actor)
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('DG-{KODE}-2027-####', false)
        ->assertDontSee('DG-{KODE}-2026-####', false)
        ->assertDontSee('name="registered_at"', false);

    freeTestClock();
});

it('shows the RME new-patient automatic RM year as the clinical year, not UTC, with no date input', function () {
    d3PinNewYearWita();

    $actor = userWith(['view_clinic_visits', 'manage_clinic_visits']);

    $html = $this->actingAs($actor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('DG-{KODE}-2027-####')
        ->and($html)->not->toContain('DG-{KODE}-2026-####')
        ->and($html)->not->toContain('name="new_patient[registered_at]"');

    freeTestClock();
});

it('cannot be backdated: old input no longer brings a registration date back', function () {
    d3PinEarlyMorningWita();

    $actor = userWith(['manage patients']);

    // Rule 244 §2.1 — the registration date (and with it the RM year) is the
    // server's clinical day; a bounced form cannot carry an operator date.
    $this->actingAs($actor)
        ->withSession(['_old_input' => ['registered_at' => '2023-06-13']])
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertDontSee('value="2023-06-13"', false)
        ->assertDontSee('name="registered_at"', false);

    freeTestClock();
});
