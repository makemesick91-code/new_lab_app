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

it('defaults the master-data registration date to the clinical day, not UTC', function () {
    ['utc' => $utc, 'clinical' => $clinical] = d3PinEarlyMorningWita();

    $actor = userWith(['manage patients']);

    $this->actingAs($actor)
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('value="'.$clinical.'"', false)
        ->assertDontSee('value="'.$utc.'"', false);

    freeTestClock();
});

it('defaults the RME new-patient registration date to the clinical day, not UTC', function () {
    ['utc' => $utc, 'clinical' => $clinical] = d3PinEarlyMorningWita();

    $actor = userWith(['view_clinic_visits', 'manage_clinic_visits']);

    $response = $this->actingAs($actor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.create'));

    $response->assertOk();

    // Scope the assertion to the registration-date input: other fields on this
    // page legitimately render today's date too, so a bare assertSee would
    // pass for the wrong reason.
    $html = $response->getContent();
    expect($html)->toContain('name="new_patient[registered_at]"');

    preg_match('/name="new_patient\[registered_at\]"[^>]*value="([^"]*)"/', $html, $m);

    expect($m[1] ?? null)->toBe($clinical, "registered_at defaulted to {$utc} (UTC) instead of the clinical day");

    freeTestClock();
});

it('still honours an operator-submitted date over the default', function () {
    d3PinEarlyMorningWita();

    $actor = userWith(['manage patients']);

    // old() input must win, otherwise a validation bounce would silently reset
    // a deliberately backdated registration to today.
    $this->actingAs($actor)
        ->withSession(['_old_input' => ['registered_at' => '2023-06-13']])
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->assertSee('value="2023-06-13"', false);

    freeTestClock();
});
