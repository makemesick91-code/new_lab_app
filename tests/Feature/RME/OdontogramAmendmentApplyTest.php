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
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Services\OdontogramService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Models\RmeRecordVersion;
use App\Modules\RmeAmendment\Services\RmeAmendmentService;
use App\Modules\RmeAmendment\Services\RmeRecordVersionService;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Services\SatusehatCandidateService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
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

// ─── Review fixes (M3.3 PR5 review) ──────────────────────────────────────────

it('revokes an approved SATUSEHAT candidate after a chart apply: the chart feeds its dental readiness (MEDIUM)', function () {
    $chart = m3aqChart();
    // A candidate needs the visit's final sheet.
    $sheet = MedicalRecord::factory()->create([
        'clinic_visit_id' => $chart->clinic_visit_id,
        'branch_id' => $chart->branch_id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);
    $sheet->forceFill(['status' => MedicalRecord::STATUS_FINAL, 'finalized_at' => now()->subDay(), 'finalized_by' => $this->doctorUser->id])->save();
    $candidate = app(SatusehatCandidateService::class)->generateForVisit($chart->clinicVisit()->firstOrFail());
    $candidate->forceFill([
        'review_status' => SatusehatCandidate::REVIEW_APPROVED,
        'approved_source_hash' => $candidate->source_hash,
        'approved_dental_source_hash' => $candidate->dental_source_hash,
    ])->save();

    app(RmeAmendmentService::class)->approve(
        app(RmeAmendmentService::class)->request($chart, $this->doctorUser, 'm3aq diagnosis gigi 36 salah', m3aqProposal()),
        $this->supervisor,
    );

    $fresh = $candidate->fresh();
    expect($fresh->dental_readiness_status)->toBe(SatusehatCandidate::DENTAL_SOURCE_CHANGED)
        ->and($fresh->readiness_status)->toBe(SatusehatCandidate::READINESS_SOURCE_CHANGED)
        ->and($fresh->revoked_at)->not->toBeNull();
});

it('validates the whole chart after the change at request time, so an accepted request can be approved (LOW)', function () {
    $chart = m3aqChart();
    // A chart saved under older rules: a per-tooth key the chart save no longer validates.
    $chart->forceFill(['tooth_map_payload' => ['teeth' => ['36' => ['status' => 'caries', 'x_legacy' => 'lama']]]])->save();

    try {
        app(RmeAmendmentService::class)->request($chart->fresh(), $this->doctorUser, 'm3aq ringkasan saja dikoreksi', ['summary_notes' => 'm3aq ringkasan baru']);
        $this->fail('Expected a refusal, the request was accepted.');
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->implode(' '))->toContain(RmeAmendmentService::MESSAGE_TOOTH_FIELD);
    }

    expect(RmeAmendment::query()->count())->toBe(0);
});

it('counts the amendments of a patient\'s charts once for the whole history list, not per chart (LOW)', function () {
    $countQueries = function (int $historyCharts): int {
        foreach (range(1, $historyCharts) as $i) {
            m3aqChart();
        }
        $current = m3aqChart();
        $queries = 0;
        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$queries): void {
            $sql = strtolower($q->sql);
            if (str_contains($sql, 'from "trx_rme_record_versions"') && str_contains($sql, 'count(')) {
                $queries++;
            }
        });

        $this->actingAs($this->doctorUser)->get(route('rme.visits.odontogram.show', $current->clinicVisit))->assertOk();

        return $queries;
    };

    $few = $countQueries(1);
    Event::forget(QueryExecuted::class);
    $many = $countQueries(4);

    expect($many)->toBe($few);
});
