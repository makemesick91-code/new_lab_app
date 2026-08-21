<?php

// POST-RME-ODONTOGRAM-STABILIZATION-1 / FIX-01
//
// THE CONTRACT THIS FILE PINS:
//
//   Opening the odontogram is a READ. It must leave no clinical record behind.
//   A trx_odontograms row is created only by the FIRST authorized, consented
//   clinical write — never by a GET, and never by a write that is refused.
//
// Everything CORRECTIVE-03 established stays true and is re-pinned here from
// the new angle: an unsigned consent still makes the active chart read-only,
// a previous visit's chart is still history, and none of those refusals may
// leave an empty row behind now that refusal happens before the insert.

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyOdontogram\Services\PatientEarliestNativeOdontogramDateResolver;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Services\OdontogramService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_rme_enabled' => true]);

    $this->doctorUser = userWith([
        'view_clinic_visits', 'manage_clinic_visits', 'complete_rme_examination', 'manage_rme_consents',
    ]);
    $this->viewer = userWith(['view_clinic_visits']);
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
});

/** An in_progress encounter with NO signed consent. */
function stabVisit(string $status = ClinicVisit::STATUS_IN_PROGRESS, array $attributes = []): ClinicVisit
{
    return ClinicVisit::factory()->create($attributes + [
        'branch_id' => test()->branch->id,
        'patient_id' => test()->patient->id,
        'status' => $status,
    ]);
}

/** An in_progress encounter WITH a signed consent — a writable chart workspace. */
function stabConsentedVisit(array $attributes = []): ClinicVisit
{
    return rmeActiveConsentedEncounter(stabVisit(ClinicVisit::STATUS_IN_PROGRESS, $attributes));
}

/** A real charted finding, so the payload is clinical content and not an empty draft. */
function stabToothPayload(string $status = 'caries'): array
{
    return ['tooth_map_payload' => ['teeth' => ['11' => ['status' => $status]]]];
}

function stabRowCount(ClinicVisit $visit): int
{
    return Odontogram::withTrashed()->where('clinic_visit_id', $visit->id)->count();
}

/*
|--------------------------------------------------------------------------
| Viewing the chart writes nothing
|--------------------------------------------------------------------------
*/

it('opening the odontogram page creates no clinical row', function () {
    $visit = stabConsentedVisit();

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk();

    expect(stabRowCount($visit))->toBe(0);
});

it('opening the odontogram page repeatedly still creates no clinical row', function () {
    $visit = stabConsentedVisit();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($this->doctorUser)
            ->get(route('rme.visits.odontogram.show', $visit))
            ->assertOk();
    }

    expect(stabRowCount($visit))->toBe(0);
});

it('a view-only operator can read the chart and still creates no row', function () {
    $visit = stabConsentedVisit();

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk();

    expect(stabRowCount($visit))->toBe(0);
});

it('an unsigned encounter renders the chart read-only and creates no row', function () {
    $visit = stabVisit(ClinicVisit::STATUS_IN_PROGRESS);

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk();

    expect(stabRowCount($visit))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The page still offers the doctor a way to chart
|--------------------------------------------------------------------------
|
| Removing creation-on-view must not strand the doctor: the save form's action
| used to be the odontogram id, so on an uncharted visit it now has to target
| the visit-scoped first-save route instead. If this regressed, a doctor could
| open a chart and have no way to record anything — which no "creates no row"
| assertion would catch.
*/

it('offers a consented doctor a save form targeting the first-save route', function () {
    $visit = stabConsentedVisit();

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        // NOTE: the store route shares its PATH with the show route (they differ
        // only by verb), so the assertion has to be on the form ACTION, not on
        // the bare URL — the page's own URL appears in navigation regardless.
        ->assertSee('action="'.route('rme.visits.odontogram.store', $visit).'"', false)
        ->assertSee('Hasil Odontogram yang Dipilih');
});

it('switches the save form to the model-bound update route once a chart exists', function () {
    $visit = stabConsentedVisit();
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('action="'.route('rme.odontograms.update', $odontogram).'"', false)
        ->assertDontSee('action="'.route('rme.visits.odontogram.store', $visit).'"', false);
});

it('offers no save form at all while consent is unsigned', function () {
    $visit = stabVisit(ClinicVisit::STATUS_IN_PROGRESS);

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertDontSee('action="'.route('rme.visits.odontogram.store', $visit).'"', false);
});

it('offers no save form to a view-only operator', function () {
    $visit = stabConsentedVisit();

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertDontSee('action="'.route('rme.visits.odontogram.store', $visit).'"', false);
});

/*
|--------------------------------------------------------------------------
| The FIRST clinical write is what creates the chart
|--------------------------------------------------------------------------
*/

it('the first save creates exactly one chart and persists the finding', function () {
    $visit = stabConsentedVisit();

    $this->actingAs($this->doctorUser)
        ->patch(route('rme.visits.odontogram.store', $visit), stabToothPayload())
        ->assertRedirect(route('rme.visits.odontogram.show', $visit));

    expect(stabRowCount($visit))->toBe(1);

    $odontogram = Odontogram::where('clinic_visit_id', $visit->id)->firstOrFail();

    expect($odontogram->tooth_map_payload['teeth']['11']['status'])->toBe('caries')
        ->and($odontogram->created_by)->toBe($this->doctorUser->id)
        ->and($odontogram->branch_id)->toBe($visit->branch_id);
});

it('a second save updates the same chart and never duplicates it', function () {
    $visit = stabConsentedVisit();

    $this->actingAs($this->doctorUser)
        ->patch(route('rme.visits.odontogram.store', $visit), stabToothPayload('caries'))
        ->assertRedirect();

    $first = Odontogram::where('clinic_visit_id', $visit->id)->firstOrFail();

    $this->actingAs($this->doctorUser)
        ->patch(route('rme.odontograms.update', $first), stabToothPayload('filling'))
        ->assertRedirect();

    expect(stabRowCount($visit))->toBe(1);

    $fresh = $first->fresh();

    expect($fresh->id)->toBe($first->id)
        ->and($fresh->tooth_map_payload['teeth']['11']['status'])->toBe('filling');
});

it('submitting the first-save route twice still yields a single chart', function () {
    $visit = stabConsentedVisit();

    $this->actingAs($this->doctorUser)
        ->patch(route('rme.visits.odontogram.store', $visit), stabToothPayload('caries'))
        ->assertRedirect();
    $this->actingAs($this->doctorUser)
        ->patch(route('rme.visits.odontogram.store', $visit), stabToothPayload('missing'))
        ->assertRedirect();

    expect(stabRowCount($visit))->toBe(1)
        ->and(Odontogram::where('clinic_visit_id', $visit->id)->firstOrFail()
            ->tooth_map_payload['teeth']['11']['status'])->toBe('missing');
});

/*
|--------------------------------------------------------------------------
| A REFUSED write leaves no trace — the consent gate now precedes the insert
|--------------------------------------------------------------------------
*/

it('refuses the first save while consent is unsigned and creates no row', function () {
    $visit = stabVisit(ClinicVisit::STATUS_IN_PROGRESS);

    $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->patch(route('rme.visits.odontogram.store', $visit), stabToothPayload())
        ->assertSessionHasErrors();

    expect(stabRowCount($visit))->toBe(0);
});

it('refuses the service-level first save while consent is unsigned and creates no row', function () {
    $visit = stabVisit(ClinicVisit::STATUS_IN_PROGRESS);

    expect(fn () => app(OdontogramService::class)
        ->saveForVisit($visit, stabToothPayload(), $this->doctorUser))
        ->toThrow(ValidationException::class);

    expect(stabRowCount($visit))->toBe(0);
});

it('refuses the first save on a cancelled visit and creates no row', function () {
    $visit = stabVisit(ClinicVisit::STATUS_CANCELLED);

    expect(fn () => app(OdontogramService::class)
        ->saveForVisit($visit, stabToothPayload(), $this->doctorUser))
        ->toThrow(ValidationException::class);

    expect(stabRowCount($visit))->toBe(0);
});

it('refuses the first save on a completed visit and creates no row', function () {
    $visit = stabVisit(ClinicVisit::STATUS_COMPLETED);

    expect(fn () => app(OdontogramService::class)
        ->saveForVisit($visit, stabToothPayload(), $this->doctorUser))
        ->toThrow(ValidationException::class);

    expect(stabRowCount($visit))->toBe(0);
});

it('refuses the first save on a PREVIOUS visit while a later encounter is live', function () {
    $previous = stabVisit(ClinicVisit::STATUS_COMPLETED);
    $live = stabConsentedVisit();

    expect(fn () => app(OdontogramService::class)
        ->saveForVisit($previous, stabToothPayload(), $this->doctorUser))
        ->toThrow(ValidationException::class);

    expect(stabRowCount($previous))->toBe(0)
        ->and(stabRowCount($live))->toBe(0);
});

it('refuses the first save for a view-only operator and creates no row', function () {
    $visit = stabConsentedVisit();

    $this->actingAs($this->viewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->patch(route('rme.visits.odontogram.store', $visit), stabToothPayload())
        ->assertForbidden();

    expect(stabRowCount($visit))->toBe(0);
});

it('refuses the first save on a visit in a non-RME branch and creates no row', function () {
    $foreign = Branch::factory()->create(['code' => 'ZZ9', 'is_rme_enabled' => false]);
    $visit = stabConsentedVisit(['branch_id' => $foreign->id]);

    expect(fn () => app(OdontogramService::class)
        ->saveForVisit($visit, stabToothPayload(), $this->doctorUser))
        ->toThrow(ValidationException::class);

    expect(stabRowCount($visit))->toBe(0);
});

it('keeps the room gate on the first-save route and creates no row', function () {
    $visit = stabConsentedVisit(['clinic_room_id' => null]);

    $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->patch(route('rme.visits.odontogram.store', $visit), stabToothPayload())
        ->assertRedirect(route('rme.visits.show', $visit));

    expect(stabRowCount($visit))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| History and the previously established read-only rules are unchanged
|--------------------------------------------------------------------------
*/

it('still refuses to mutate a previous encounter chart directly', function () {
    $previous = stabVisit(ClinicVisit::STATUS_COMPLETED);
    $historical = Odontogram::factory()->create([
        'clinic_visit_id' => $previous->id,
        'branch_id' => $previous->branch_id,
        'tooth_map_payload' => ['teeth' => ['21' => ['status' => 'caries']]],
    ]);
    stabConsentedVisit();

    expect(fn () => app(OdontogramService::class)
        ->updatePlaceholder($historical, stabToothPayload('filling'), $this->doctorUser))
        ->toThrow(ValidationException::class);

    expect($historical->fresh()->tooth_map_payload['teeth']['21']['status'])->toBe('caries');
});

it('lists a previous charted odontogram as read-only history on the live chart page', function () {
    $previous = stabVisit(ClinicVisit::STATUS_COMPLETED);
    Odontogram::factory()->create([
        'clinic_visit_id' => $previous->id,
        'branch_id' => $previous->branch_id,
        'tooth_map_payload' => ['teeth' => ['21' => ['status' => 'caries']]],
    ]);

    $live = stabConsentedVisit();

    $history = app(OdontogramService::class)->patientHistoryForVisit($live, $this->doctorUser);

    expect($history)->toHaveCount(1)
        ->and($history->first()['source'])->toBe('native')
        // Read-only by construction: a history row carries no edit target.
        ->and($history->first()['view_url'])->toBeNull();
});

it('cannot pollute history because a viewed chart is never persisted', function () {
    $previous = stabVisit(ClinicVisit::STATUS_COMPLETED);

    // The previous visit's chart page is merely OPENED — never charted.
    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.odontogram.show', $previous))
        ->assertOk();

    $live = stabConsentedVisit();

    expect(stabRowCount($previous))->toBe(0)
        ->and(app(OdontogramService::class)->patientHistoryForVisit($live, $this->doctorUser))
        ->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| Legacy Odontogram archive cutoff — the coupling, now RESOLVED
|--------------------------------------------------------------------------
|
| PatientEarliestNativeOdontogramDateResolver anchors the Legacy Odontogram
| import's chronology gate. Its repository used to qualify a visit purely on the
| EXISTENCE of a trx_odontograms row (whereHas('odontogram')), with no payload
| predicate, so before FIX-01 a merely-OPENED page anchored the cutoff.
|
| This sprint pinned that residual rather than changing it, and recorded that
| narrowing the resolver was "an owner decision for a separate sprint".
| LEGACY-ODONTOGRAM-NATIVE-REFERENCE-CUTOFF-1 is that sprint: the cutoff now
| requires meaningful clinical content (Odontogram::hasRecordedTeeth()), the
| same predicate the doctor's Patient Odontogram History already applied.
|
| Measured read-only on production before the change: of 15 patients with a
| native row, 14 kept an identical bound, 0 gained eligibility, and exactly one
| — whose only row was a contentless draft — lost a bound that was never real.
*/

it('no longer anchors the legacy odontogram cutoff by merely opening a chart page', function () {
    $visit = stabConsentedVisit();
    $resolver = app(PatientEarliestNativeOdontogramDateResolver::class);

    expect($resolver->resolve($this->patient->id))->toBeNull();

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk();

    // Opening the page used to create the anchoring row. It no longer does, so
    // the patient still has no native odontogram reference.
    expect(stabRowCount($visit))->toBe(0)
        ->and($resolver->resolve($this->patient->id))->toBeNull();
});

it('anchors the legacy odontogram cutoff once a chart is actually saved', function () {
    $visit = stabConsentedVisit();
    $resolver = app(PatientEarliestNativeOdontogramDateResolver::class);

    $this->actingAs($this->doctorUser)
        ->patch(route('rme.visits.odontogram.store', $visit), stabToothPayload())
        ->assertRedirect();

    expect($resolver->resolve($this->patient->id)?->toDateString())
        ->toBe($visit->fresh()->visit_date->toDateString());
});

it('no longer anchors the cutoff from a pre-existing empty row — and still does not delete it', function () {
    // A contentless row is not clinical evidence, so it neither opens the
    // eligibility gate nor draws the chronological bound.
    //
    // The row itself is untouched. This sprint changed ELIGIBILITY SEMANTICS,
    // never data: no historical odontogram is deleted, edited or backfilled, so
    // the assertion on the surviving row is the point, not an afterthought.
    $visit = stabVisit(ClinicVisit::STATUS_COMPLETED);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => null,
    ]);

    $resolver = app(PatientEarliestNativeOdontogramDateResolver::class);

    expect($resolver->resolve($this->patient->id))->toBeNull()
        ->and(stabRowCount($visit))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The read resolver itself
|--------------------------------------------------------------------------
*/

it('resolves an unsaved draft for a visit with no chart and writes nothing', function () {
    $visit = stabConsentedVisit();

    $draft = app(OdontogramService::class)->draftForVisit($visit);

    expect($draft)->toBeInstanceOf(Odontogram::class)
        ->and($draft->exists)->toBeFalse()
        ->and($draft->clinic_visit_id)->toBe($visit->id)
        ->and($draft->branch_id)->toBe($visit->branch_id)
        ->and($draft->status)->toBe(Odontogram::STATUS_DRAFT)
        ->and($draft->tooth_map_payload)->toBeNull()
        ->and(stabRowCount($visit))->toBe(0);
});

it('resolves the saved chart when one exists', function () {
    $visit = stabConsentedVisit();
    $saved = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    $resolved = app(OdontogramService::class)->draftForVisit($visit);

    expect($resolved->exists)->toBeTrue()
        ->and($resolved->id)->toBe($saved->id)
        ->and(stabRowCount($visit))->toBe(1);
});

it('findForVisit returns null for an uncharted visit and writes nothing', function () {
    $visit = stabConsentedVisit();

    expect(app(OdontogramService::class)->findForVisit($visit))->toBeNull()
        ->and(stabRowCount($visit))->toBe(0);
});
