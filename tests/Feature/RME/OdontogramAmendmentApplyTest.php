<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR5 — approve and apply an amendment of a finalized odontogram
|--------------------------------------------------------------------------
|
| Rule 240 §7. Approving applies the proposed chart in one transaction (visit →
| chart → amendment), re-validated with the chart-save rules; the chart stays
| finalized and locked; the next version is chained and verifiable; the chart
| page and the odontogram print carry "Diamandemen n×"; the version page shows a
| chart version against the previous one with the changed teeth marked.
|
| Selected by the existing `Odontogram` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `m3aq`.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Services\OdontogramService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Models\RmeRecordVersion;
use App\Modules\RmeAmendment\Services\RmeAmendmentService;
use App\Modules\RmeAmendment\Services\RmeRecordVersionService;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();

    $this->branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $this->doctorUser = userInRole('Doctor');
    $this->doctor = Doctor::factory()->create(['user_id' => $this->doctorUser->id, 'branch_id' => $this->branch->id, 'is_active' => true]);
    rmeMakeDoctorOnline($this->doctor, $this->branch, $this->room, $this->doctorUser);
    $this->doctorUser = $this->doctorUser->fresh();
    $this->supervisor = userInRole('Supervisor RME');
});

function m3aqChart(?User $finalizer = null): Odontogram
{
    $visit = ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id,
        'clinic_room_id' => test()->room->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
    ]);
    $chart = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'status' => Odontogram::STATUS_DRAFT,
        'summary_notes' => 'm3aq ringkasan',
        'tooth_map_payload' => ['teeth' => [
            '11' => ['status' => 'normal'],
            '36' => ['status' => 'caries', 'note' => 'm3aq catatan lama'],
        ]],
    ]);
    $chart->forceFill([
        'status' => Odontogram::STATUS_FINALIZED,
        'finalized_at' => now()->subDay(),
        'finalized_by' => ($finalizer ?? test()->doctorUser)->id,
    ])->save();

    return $chart->fresh();
}

/** @return array<string, mixed> */
function m3aqProposal(): array
{
    return [
        'summary_notes' => 'm3aq ringkasan dikoreksi',
        'tooth_map_payload' => ['teeth' => [
            '11' => ['status' => 'normal'],
            '36' => ['status' => 'filling', 'note' => 'm3aq catatan baru', 'conditions' => ['filling']],
        ]],
    ];
}

it('applies the proposed chart, keeps it finalized and locked, and chains a verifiable version 2', function () {
    $chart = m3aqChart();
    $amendment = app(RmeAmendmentService::class)->request($chart, $this->doctorUser, 'm3aq diagnosis gigi 36 salah', m3aqProposal());

    $applied = app(RmeAmendmentService::class)->approve($amendment, $this->supervisor);
    $fresh = $chart->fresh();

    expect($applied->status)->toBe(RmeAmendment::STATUS_APPLIED)
        ->and($fresh->isFinalized())->toBeTrue()
        ->and($fresh->summary_notes)->toBe('m3aq ringkasan dikoreksi')
        // jsonb keeps no key order: compared by content.
        ->and($fresh->tooth_map_payload)->toEqual(m3aqProposal()['tooth_map_payload'])
        ->and(app(RmeRecordVersionService::class)->verifyChain($fresh))->toBeTrue()
        ->and(app(RmeRecordVersionService::class)->amendmentCount($fresh))->toBe(1)
        ->and(RmeRecordVersion::query()->where('subject_type', RmeRecordVersion::SUBJECT_ODONTOGRAM)->where('subject_id', $chart->id)->orderByDesc('version')->first()->snapshot['tooth_map_payload'])
        ->toEqual(m3aqProposal()['tooth_map_payload']);

    // The normal chart save is still refused.
    expect(fn () => app(OdontogramService::class)->updatePlaceholder($fresh, ['summary_notes' => 'm3aq langsung'], $this->doctorUser))
        ->toThrow(ValidationException::class);
});

it('marks the chart page and the odontogram print "Diamandemen 1×" after an applied amendment', function () {
    $chart = m3aqChart();

    $this->actingAs($this->supervisor)->get(route('rme.odontograms.print', $chart))
        ->assertOk()
        ->assertDontSee('Diamandemen');

    app(RmeAmendmentService::class)->approve(
        app(RmeAmendmentService::class)->request($chart, $this->doctorUser, 'm3aq diagnosis gigi 36 salah', m3aqProposal()),
        $this->supervisor,
    );

    $this->actingAs($this->supervisor)->get(route('rme.odontograms.print', $chart))
        ->assertOk()
        ->assertSee('Diamandemen 1×');
    $this->actingAs($this->doctorUser)->get(route('rme.visits.odontogram.show', $chart->clinicVisit))
        ->assertOk()
        ->assertSee('Diamandemen 1×');
});

it('shows a chart version against the previous one with the changed tooth marked', function () {
    $chart = m3aqChart();
    app(RmeAmendmentService::class)->approve(
        app(RmeAmendmentService::class)->request($chart, $this->doctorUser, 'm3aq diagnosis gigi 36 salah', m3aqProposal()),
        $this->supervisor,
    );

    $this->actingAs($this->supervisor)->get(route('rme.amendments.versions.chart', $chart))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertSee('data-version-row="1"', false)
        ->assertSee('data-version-row="2"', false);

    $this->actingAs($this->supervisor)->get(route('rme.amendments.versions.chart.show', [$chart, 2]))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertSee('data-chart-row-changed="36"', false)
        ->assertDontSee('data-chart-row-changed="11"', false)
        ->assertSeeInOrder(['m3aq catatan lama', 'm3aq catatan baru']);

    $this->actingAs($this->supervisor)->get(route('rme.amendments.versions.chart.show', [$chart, 9]))->assertNotFound();
});
