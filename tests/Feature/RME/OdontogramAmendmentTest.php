<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR4 — supervised amendment of a finalized odontogram
|--------------------------------------------------------------------------
|
| Rule 240 §6. A finalized chart is corrected only through a supervised
| amendment: the doctor of record (the chart visit's doctor) or the finalizer
| requests it with a reason and the proposed chart (summary notes, additional
| conditions and the tooth map, validated like a chart save), the requester may
| cancel it, a Supervisor RME who is neither the requester nor the doctor of
| record rejects it with a reason. The chart stays locked while a request is
| pending. The chart page offers "Ajukan Amandemen" only to an account the
| server would accept (rule 135 mirror; a Super Admin who is neither sees none)
| and shows the amendment status.
|
| Selected by the existing `Odontogram` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `m33q`.
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
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();

    $this->branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $this->doctorUser = userInRole('Doctor');
    $this->doctor = Doctor::factory()->create(['user_id' => $this->doctorUser->id, 'branch_id' => $this->branch->id, 'is_active' => true]);
    // RME pages need a doctor's online context (EnsureRmeOnlineContext).
    rmeMakeDoctorOnline($this->doctor, $this->branch, $this->room, $this->doctorUser);
    $this->doctorUser = $this->doctorUser->fresh();
    $this->supervisor = userInRole('Supervisor RME');
});

/** @return array<string, mixed> */
function m33qPayload(string $status = 'caries', string $care = 'm33q tumpatan'): array
{
    return ['teeth' => ['36' => ['status' => $status, 'additional_note' => $care]]];
}

/** A chart of a finished visit, finalized by the visit's doctor unless told otherwise. */
function m33qChart(?User $finalizer = null, string $status = Odontogram::STATUS_FINALIZED): Odontogram
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
        'summary_notes' => 'm33q ringkasan',
        'tooth_map_payload' => m33qPayload(),
    ]);

    if ($status === Odontogram::STATUS_FINALIZED) {
        $chart->forceFill([
            'status' => Odontogram::STATUS_FINALIZED,
            'finalized_at' => now()->subDay(),
            'finalized_by' => ($finalizer ?? test()->doctorUser)->id,
        ])->save();
    }

    return $chart->fresh();
}

/** @return array<string, mixed> */
function m33qProposal(): array
{
    return [
        'summary_notes' => 'm33q ringkasan',
        'additional_conditions' => null,
        'tooth_map_payload' => m33qPayload('filling', 'm33q tumpatan komposit'),
    ];
}

function m33qRefusal(callable $act): string
{
    try {
        $act();
    } catch (ValidationException $e) {
        return collect($e->errors())->flatten()->implode(' | ');
    }

    test()->fail('Expected a refusal, the action succeeded.');
}

it('requests, cancels, requests again and rejects an amendment of a finalized chart', function () {
    $chart = m33qChart();
    $service = app(RmeAmendmentService::class);

    $first = $service->request($chart, $this->doctorUser, 'm33q diagnosis gigi 36 salah pilih', m33qProposal());

    expect($first->status)->toBe(RmeAmendment::STATUS_PENDING)
        ->and($first->subject_type)->toBe(RmeRecordVersion::SUBJECT_ODONTOGRAM)
        ->and((int) $first->subject_id)->toBe((int) $chart->id)
        ->and((int) $first->branch_id)->toBe((int) $chart->branch_id)
        ->and($first->proposed_content['tooth_map_payload']['teeth']['36']['status'])->toBe('filling')
        ->and((int) $first->base_version)->toBe(1)
        ->and(RmeRecordVersion::query()->where('subject_type', RmeRecordVersion::SUBJECT_ODONTOGRAM)->where('subject_id', $chart->id)->value('kind'))->toBe(RmeRecordVersion::KIND_BASELINE);

    $service->cancel($first, $this->doctorUser);
    $second = $service->request($chart, $this->doctorUser, 'm33q diagnosis gigi 36 salah pilih', m33qProposal());
    $rejected = $service->reject($second, $this->supervisor, 'm33q hasil pemeriksaan mendukung karies');

    expect($first->fresh()->status)->toBe(RmeAmendment::STATUS_CANCELLED)
        ->and($rejected->status)->toBe(RmeAmendment::STATUS_REJECTED)
        ->and($chart->fresh()->tooth_map_payload['teeth']['36']['status'])->toBe('caries');
});

it('validates the proposed chart like a chart save and refuses a proposal that changes nothing', function () {
    $chart = m33qChart();
    $service = app(RmeAmendmentService::class);

    expect(m33qRefusal(fn () => $service->request($chart, $this->doctorUser, 'm33q nomor gigi tidak valid', ['tooth_map_payload' => ['teeth' => ['99' => ['status' => 'caries']]]])))->not->toBe('')
        ->and(m33qRefusal(fn () => $service->request($chart, $this->doctorUser, 'm33q status gigi tidak valid', ['tooth_map_payload' => ['teeth' => ['36' => ['status' => 'broken']]]])))->not->toBe('')
        ->and(m33qRefusal(fn () => $service->request($chart, $this->doctorUser, 'm33q tidak ada perubahan', ['summary_notes' => 'm33q ringkasan', 'tooth_map_payload' => m33qPayload()])))->toContain(RmeAmendmentService::MESSAGE_NO_CHANGE)
        ->and(RmeAmendment::query()->count())->toBe(0);
});

it('refuses a draft chart, another doctor and a Super Admin who is neither', function () {
    $service = app(RmeAmendmentService::class);
    $other = userInRole('Doctor');

    expect(m33qRefusal(fn () => $service->request(m33qChart(status: Odontogram::STATUS_DRAFT), $this->doctorUser, 'm33q draf belum final', m33qProposal())))->toContain(RmeAmendmentService::MESSAGE_NOT_FINAL)
        ->and(m33qRefusal(fn () => $service->request(m33qChart(), $other, 'm33q dokter lain mengajukan', m33qProposal())))->toContain(RmeAmendmentService::MESSAGE_NOT_REQUESTER)
        ->and(m33qRefusal(fn () => $service->request(m33qChart(), userInRole('Super Admin'), 'm33q super admin bukan pemilik', m33qProposal())))->toContain(RmeAmendmentService::MESSAGE_NOT_REQUESTER)
        ->and(RmeAmendment::query()->count())->toBe(0);
});

it('keeps the chart locked while an amendment is pending', function () {
    $chart = m33qChart();
    app(RmeAmendmentService::class)->request($chart, $this->doctorUser, 'm33q koreksi diagnosis gigi', m33qProposal());

    expect(m33qRefusal(fn () => app(OdontogramService::class)->updatePlaceholder($chart->fresh(), m33qProposal(), $this->doctorUser)))->not->toBe('')
        ->and($chart->fresh()->tooth_map_payload['teeth']['36']['status'])->toBe('caries');
});

it('offers "Ajukan Amandemen" on the chart page only to an account the server would accept, and shows the status', function () {
    $chart = m33qChart();
    $visit = $chart->clinicVisit;

    $this->actingAs($this->doctorUser)->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('data-amendment-request', false)
        ->assertSee(route('rme.amendments.create-chart', $chart), false);

    foreach ([$this->supervisor, userInRole('Super Admin')] as $viewer) {
        $this->actingAs($viewer)->get(route('rme.visits.odontogram.show', $visit))
            ->assertOk()
            ->assertDontSee('data-amendment-request', false);
    }

    $amendment = app(RmeAmendmentService::class)->request($chart, $this->doctorUser, 'm33q koreksi diagnosis gigi', m33qProposal());

    $this->actingAs($this->supervisor)->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('data-amendment-status="pending"', false)
        ->assertSee(route('rme.amendments.show', $amendment), false);

    $this->actingAs($this->doctorUser)->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertSee('data-amendment-status="pending"', false)
        ->assertDontSee('data-amendment-request', false);
});

it('renders no amendment block on a draft chart', function () {
    $chart = m33qChart(status: Odontogram::STATUS_DRAFT);

    $this->actingAs($this->doctorUser)->get(route('rme.visits.odontogram.show', $chart->clinicVisit))
        ->assertOk()
        ->assertDontSee('data-amendment-panel', false);
});

it('accepts the chart request form over HTTP with the editor payload as JSON', function () {
    $chart = m33qChart();

    $this->actingAs($this->doctorUser)->get(route('rme.amendments.create-chart', $chart))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

    $response = $this->actingAs($this->doctorUser)->post(route('rme.amendments.store-chart', $chart), [
        'reason' => 'm33q diagnosis gigi 36 salah pilih',
        'proposed' => [
            'summary_notes' => 'm33q ringkasan',
            'additional_conditions' => '',
            'tooth_map_payload' => json_encode(m33qPayload('filling', 'm33q tumpatan komposit')),
        ],
    ]);

    $amendment = RmeAmendment::query()->sole();
    $response->assertRedirect(route('rme.amendments.show', $amendment));
    expect($amendment->proposed_content['tooth_map_payload']['teeth']['36']['status'])->toBe('filling');

    // Before (the baseline version) and after (the proposal), side by side.
    $this->actingAs($this->supervisor)->get(route('rme.amendments.show', $amendment))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertSeeInOrder(['caries', 'm33q tumpatan', 'filling', 'm33q tumpatan komposit'])
        ->assertSee('data-amendment-reject', false);
});
