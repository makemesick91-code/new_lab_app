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
|
| Rule 241 §2 (PTM) then added the EXECUTED consent to the authority, and rule
| 240 §1 (memo M3.3, owner decision 2026-11-17) superseded that last pin: a
| finalized chart is locked on ANY encounter, whatever the PTM state, and the
| lock answers before the consent (rule 240 §1.8); the test is flipped, not
| deleted.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
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
    // Review LOW-2 (rule 241 §2) — an EXECUTED consent, so the refusal can only
    // come from the status condition this case pins, not from the consent.
    rmeSignedConsentFor($visit);

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
    // Review LOW-2 (rule 241 §2) — an EXECUTED consent, so the refusal can only
    // come from the status condition this case pins, not from the consent.
    rmeSignedConsentFor($visit);
    expect(odmRefused(fn () => app(OdontogramService::class)->saveForVisit($visit, odmPayload(), $this->doctorUser)))->toBeTrue()
        ->and($attempts)->toBe(0);

    // Rule 241 §2 — the positive control needs the EXECUTED consent.
    app(OdontogramService::class)->saveForVisit(rmeActiveConsentedEncounter(odmVisit(ClinicVisit::STATUS_IN_PROGRESS)), odmPayload(), $this->doctorUser);
    expect($attempts)->toBe(1);
})->with($closedStatuses);

it('refuses to FINALIZE a chart outside a live encounter and leaves it unchanged', function (string $status) {
    $visit = odmVisit($status);
    // Review LOW-2 (rule 241 §2) — an EXECUTED consent, so the refusal can only
    // come from the status condition this case pins, not from the consent.
    rmeSignedConsentFor($visit);
    $chart = Odontogram::factory()->create(['clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id]);
    $before = $chart->status;

    expect(odmRefused(fn () => app(OdontogramService::class)->finalize($chart, $this->doctorUser)))->toBeTrue()
        ->and($chart->fresh()->status)->toBe($before);
})->with($closedStatuses);

it('refuses a REVISION outside a live encounter and leaves the chart unchanged', function (string $status) {
    $visit = odmVisit($status);
    // Review LOW-2 (rule 241 §2) — an EXECUTED consent, so the refusal can only
    // come from the status condition this case pins, not from the consent.
    rmeSignedConsentFor($visit);
    $chart = Odontogram::factory()->create(['clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id]);
    $before = $chart->tooth_map_payload;

    expect(odmRefused(fn () => app(OdontogramService::class)->updatePlaceholder($chart, odmPayload(), $this->doctorUser)))->toBeTrue()
        ->and($chart->fresh()->tooth_map_payload)->toBe($before);
})->with($closedStatuses);

// FLIPPED by DMS memo M1.1–M1.3 PR2 (rule 241 §2): was 'allows first save,
// revision and finalize on an in-progress encounter with and without consent'.
// The unsigned row is now refused; the executed row is allowed.
it('allows first save, revision and finalize on an in-progress encounter only with the executed consent', function (bool $signed) {
    $visit = odmVisit(ClinicVisit::STATUS_IN_PROGRESS);

    if ($signed) {
        rmeSignedConsentFor($visit);
    }

    if (! $signed) {
        expect(odmRefused(fn () => app(OdontogramService::class)->saveForVisit($visit, odmPayload(), $this->doctorUser)))->toBeTrue()
            ->and(Odontogram::query()->where('clinic_visit_id', $visit->id)->exists())->toBeFalse()
            ->and(app(RmeVisitConsentService::class)->hasValidConsent($visit->fresh()))->toBeFalse();

        return;
    }

    $chart = app(OdontogramService::class)->saveForVisit($visit, odmPayload(), $this->doctorUser);
    app(OdontogramService::class)->updatePlaceholder($chart, ['tooth_map_payload' => ['teeth' => ['17' => ['conditions' => ['missing']]]]], $this->doctorUser);
    app(OdontogramService::class)->finalize($chart->fresh(), $this->doctorUser);

    expect($chart->fresh()->status)->toBe(Odontogram::STATUS_FINALIZED)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS)
        ->and(app(RmeVisitConsentService::class)->hasExecutedConsent($visit->fresh()))->toBeTrue();
})->with(['consent unsigned' => [false], 'consent signed' => [true]]);

// FLIPPED twice, never deleted: 'keeps a finalized chart revisable on an UNSIGNED
// live encounter' (rule 134) became, under rule 241 §2, 'revisable on an EXECUTED
// live encounter, refused unsigned', and rule 240 §1 (memo M3.3) then locked a
// finalized chart on ANY encounter. The combined truth: a FINALIZED chart is
// locked whatever the PTM says, and the lock answers BEFORE the consent — signing
// the PTM can never unlock it, so it is never the reason given; a DRAFT chart
// needs the EXECUTED consent. Both directions, through both write entry points.

/**
 * Rule 241 §2 — the PTM of a live encounter as a fixture: executed (patient and
 * doctor), awaiting the doctor (patient only) or awaiting the patient (none).
 * The state machine itself is pinned through the services by
 * RmeOdontogramTwoSignatureGateTest; here only the refusal order is the subject.
 */
function odmPtm(ClinicVisit $visit, string $state): void
{
    if ($state === RmeVisitConsentService::PTM_EXECUTED) {
        rmeSignedConsentFor($visit);
    } elseif ($state === RmeVisitConsentService::PTM_AWAITING_DOCTOR) {
        RmeVisitConsent::factory()->create([
            'clinic_visit_id' => $visit->id,
            'branch_id' => $visit->branch_id,
            'patient_id' => $visit->patient_id,
            'doctor_id' => $visit->doctor_id,
            'patient_name_snapshot' => $visit->patient?->name ?? 'Pasien',
        ]);
    }

    expect(app(RmeVisitConsentService::class)->ptmStateFor($visit->fresh()))->toBe($state);
}

/** The validation errors a write answers with; [] when it is not refused. */
function odmErrors(callable $write): array
{
    try {
        $write();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

$ptmStates = [
    'PTM executed' => [RmeVisitConsentService::PTM_EXECUTED],
    'PTM awaiting the doctor' => [RmeVisitConsentService::PTM_AWAITING_DOCTOR],
    'PTM awaiting the patient' => [RmeVisitConsentService::PTM_AWAITING_PATIENT],
];

it('locks a FINALIZED chart on the live encounter whatever the PTM state, the lock answering before the consent (rule 240 §1, rule 241 §2)', function (string $ptm) {
    $visit = odmVisit(ClinicVisit::STATUS_IN_PROGRESS);
    odmPtm($visit, $ptm);
    $chart = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now(),
        'tooth_map_payload' => ['teeth' => ['16' => ['conditions' => ['caries']]]],
    ]);
    $locked = ['odontogram' => [OdontogramService::MESSAGE_FINALIZED_CHART_LOCKED]];
    $revision = ['tooth_map_payload' => ['teeth' => ['21' => ['conditions' => ['filling']]]]];

    expect(odmErrors(fn () => app(OdontogramService::class)->updatePlaceholder($chart->fresh(), $revision, $this->doctorUser)))->toBe($locked)
        ->and(odmErrors(fn () => app(OdontogramService::class)->saveForVisit($visit, $revision, $this->doctorUser)))->toBe($locked);

    // HTTP: the revision is denied by OdontogramPolicy::update with the lock (403);
    // the visit save (`author` policy) reaches the service and gets the same lock.
    $this->patch(route('rme.odontograms.update', $chart), ['summary_notes' => 'x'])->assertForbidden();
    $this->patch(route('rme.visits.odontogram.store', $visit), ['summary_notes' => 'x'])
        ->assertSessionHasErrors(['odontogram' => OdontogramService::MESSAGE_FINALIZED_CHART_LOCKED]);

    expect($chart->fresh()->tooth_map_payload['teeth'])->not->toHaveKey('21')
        ->and($chart->fresh()->summary_notes)->not->toBe('x')
        ->and(app(RmeVisitConsentService::class)->ptmStateFor($visit->fresh()))->toBe($ptm);
})->with($ptmStates);

it('refuses a DRAFT chart on an unsigned live encounter with the consent and writes it on the executed one (rule 241 §2)', function (string $ptm) {
    $visit = odmVisit(ClinicVisit::STATUS_IN_PROGRESS);
    odmPtm($visit, $ptm);
    $chart = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => ['16' => ['conditions' => ['caries']]]],
    ]);
    $revision = ['tooth_map_payload' => ['teeth' => ['21' => ['conditions' => ['filling']]]]];
    $update = fn () => app(OdontogramService::class)->updatePlaceholder($chart->fresh(), $revision, $this->doctorUser);
    $save = fn () => app(OdontogramService::class)->saveForVisit($visit, ['summary_notes' => 'disimpan'], $this->doctorUser);

    if ($ptm === RmeVisitConsentService::PTM_EXECUTED) {
        expect(odmErrors($update))->toBe([])
            ->and(odmErrors($save))->toBe([])
            ->and($chart->fresh()->tooth_map_payload['teeth'])->toHaveKey('21')
            ->and($chart->fresh()->summary_notes)->toBe('disimpan')
            ->and($chart->fresh()->status)->not->toBe(Odontogram::STATUS_FINALIZED);

        return;
    }

    $consent = ['consent' => [$ptm === RmeVisitConsentService::PTM_AWAITING_DOCTOR
        ? RmeVisitConsentService::MESSAGE_ODONTOGRAM_AWAITING_DOCTOR
        : RmeVisitConsentService::MESSAGE_ODONTOGRAM_AWAITING_PATIENT]];

    expect(odmErrors($update))->toBe($consent)
        ->and(odmErrors($save))->toBe($consent)
        ->and($chart->fresh()->tooth_map_payload['teeth'])->not->toHaveKey('21')
        ->and($chart->fresh()->summary_notes)->not->toBe('disimpan');
})->with($ptmStates);

it('renders the structured odontogram print template with table layout only, never flexbox or grid', function () {
    $template = File::get(resource_path('views/rme/visits/odontogram/partials/structured-print-template.blade.php'));

    expect($template)->toContain('<table')
        ->and(preg_match('/display\s*:\s*(inline-)?(flex|grid)/i', $template))->toBe(0);
});
