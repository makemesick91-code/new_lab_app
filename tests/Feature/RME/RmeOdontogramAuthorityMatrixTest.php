<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-6 / OD-01 — the active odontogram's full matrix
|--------------------------------------------------------------------------
|
| Rule 134: the single authority is
| RmeVisitConsentService::assertOdontogramAuthoringAllowed() — a live
| in_progress encounter, an actor who may work on it, the chart belonging to
| it, and deliberately NO consent condition. The audit found that behaviour
| correct; this suite closes the coverage gaps it reported: every visit status
| through EVERY write entry point (first save, revision, finalize), and a
| finalized chart staying revisable on an UNSIGNED live encounter. It changes
| no behaviour.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Services\OdontogramService;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_rme_enabled' => true]);
    $this->doctorUser = rmeClinicalAuthor([
        'view_clinic_visits', 'manage_clinic_visits', 'complete_rme_examination', 'manage_rme_consents',
    ]);
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $this->actingAs($this->doctorUser);
});

function odmVisit(string $status): ClinicVisit
{
    return ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => test()->patient->id,
        'status' => $status,
    ]);
}

function odmPayload(): array
{
    return ['tooth_map_payload' => ['teeth' => ['16' => ['conditions' => ['caries']]]]];
}

/**
 * True only when the write is refused BY THE AUTHORING AUTHORITY — a
 * ValidationException keyed `consent` (the key every refusal in
 * assertOdontogramAuthoringAllowed() uses). Any other exception propagates, so
 * an unrelated failure can never pass as a refusal.
 */
function odmRefused(callable $write): bool
{
    try {
        $write();
    } catch (ValidationException $e) {
        return array_key_exists('consent', $e->errors());
    }

    return false;
}

$closedStatuses = [
    'registered' => [ClinicVisit::STATUS_REGISTERED],
    'waiting' => [ClinicVisit::STATUS_WAITING],
    'cashier_pending' => [ClinicVisit::STATUS_CASHIER_PENDING],
    'completed' => [ClinicVisit::STATUS_COMPLETED],
    'cancelled' => [ClinicVisit::STATUS_CANCELLED],
];

it('refuses a FIRST chart and writes no row outside a live encounter', function (string $status) {
    $visit = odmVisit($status);

    expect(odmRefused(fn () => app(OdontogramService::class)->saveForVisit($visit, odmPayload(), $this->doctorUser)))->toBeTrue()
        ->and(Odontogram::query()->where('clinic_visit_id', $visit->id)->exists())->toBeFalse();
})->with($closedStatuses);

// The row-count test above cannot tell "refused before the insert" from
// "inserted, then rolled back by the outer transaction" — both leave no row.
// saveForVisit() promises the former (the authority is asserted BEFORE the
// insert), so this pins the ATTEMPT, with an in-progress save as the positive
// control that proves the listener is live.
it('refuses a FIRST chart before attempting the insert, not by rolling it back', function (string $status) {
    $attempts = 0;
    Odontogram::creating(function () use (&$attempts): void {
        $attempts++;
    });

    $visit = odmVisit($status);
    expect(odmRefused(fn () => app(OdontogramService::class)->saveForVisit($visit, odmPayload(), $this->doctorUser)))->toBeTrue()
        ->and($attempts)->toBe(0);

    app(OdontogramService::class)->saveForVisit(odmVisit(ClinicVisit::STATUS_IN_PROGRESS), odmPayload(), $this->doctorUser);
    expect($attempts)->toBe(1);
})->with($closedStatuses);

it('refuses to FINALIZE a chart outside a live encounter and leaves it unchanged', function (string $status) {
    $visit = odmVisit($status);
    $chart = Odontogram::factory()->create(['clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id]);
    $before = $chart->status;

    expect(odmRefused(fn () => app(OdontogramService::class)->finalize($chart, $this->doctorUser)))->toBeTrue()
        ->and($chart->fresh()->status)->toBe($before);
})->with($closedStatuses);

it('refuses a REVISION outside a live encounter and leaves the chart unchanged', function (string $status) {
    $visit = odmVisit($status);
    $chart = Odontogram::factory()->create(['clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id]);
    $before = $chart->tooth_map_payload;

    expect(odmRefused(fn () => app(OdontogramService::class)->updatePlaceholder($chart, odmPayload(), $this->doctorUser)))->toBeTrue()
        ->and($chart->fresh()->tooth_map_payload)->toBe($before);
})->with($closedStatuses);

it('allows first save, revision and finalize on an in-progress encounter with and without consent', function (bool $signed) {
    $visit = odmVisit(ClinicVisit::STATUS_IN_PROGRESS);

    if ($signed) {
        rmeSignedConsentFor($visit);
    }

    $chart = app(OdontogramService::class)->saveForVisit($visit, odmPayload(), $this->doctorUser);
    app(OdontogramService::class)->updatePlaceholder($chart, ['tooth_map_payload' => ['teeth' => ['17' => ['conditions' => ['missing']]]]], $this->doctorUser);
    app(OdontogramService::class)->finalize($chart->fresh(), $this->doctorUser);

    expect($chart->fresh()->status)->toBe(Odontogram::STATUS_FINALIZED)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS)
        ->and(app(RmeVisitConsentService::class)->hasValidConsent($visit->fresh()))->toBe($signed);
})->with(['consent unsigned' => [false], 'consent signed' => [true]]);

it('keeps a finalized chart revisable on an UNSIGNED live encounter', function () {
    $visit = odmVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $chart = app(OdontogramService::class)->saveForVisit($visit, odmPayload(), $this->doctorUser);
    app(OdontogramService::class)->finalize($chart, $this->doctorUser);

    app(OdontogramService::class)->updatePlaceholder(
        $chart->fresh(),
        ['tooth_map_payload' => ['teeth' => ['21' => ['conditions' => ['filling']]]]],
        $this->doctorUser,
    );

    expect($chart->fresh()->tooth_map_payload['teeth'])->toHaveKey('21')
        ->and(app(RmeVisitConsentService::class)->hasValidConsent($visit->fresh()))->toBeFalse();
});

it('renders the structured odontogram print template with table layout only, never flexbox or grid', function () {
    $template = File::get(resource_path('views/rme/visits/odontogram/partials/structured-print-template.blade.php'));

    expect($template)->toContain('<table')
        ->and(preg_match('/display\s*:\s*(inline-)?(flex|grid)/i', $template))->toBe(0);
});
