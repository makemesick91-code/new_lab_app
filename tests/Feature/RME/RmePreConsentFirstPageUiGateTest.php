<?php

// BUGFIX-RME-PRECONSENT-FIRST-PAGE-UI-GATE-1
//
// The bug this file pins, stated as the mismatch it was:
//
//   Doctor + visit in_progress + consent UNSIGNED + patient has NO RM sheet
//     UI      : offered an actionable "Buat Halaman RM Pertama"
//     BACKEND : refused the very same create
//
// The UI was right about the permission and wrong about the act. The empty
// state asked `can('create', [MedicalRecord::class, $visit])`, and
// MedicalRecordPolicy::create answers a narrower question than the one the
// button implies: may this user manage RME for this branch and this patient.
// It says nothing about consent, because consent is not a permission — it is a
// property of the encounter, and it is resolved by
// RmeVisitConsentService::canAuthorRmeForPatient(), the same authority
// MedicalRecordService::createDraft() asserts.
//
// So the populated workspace and the EMPTY workspace were reading two different
// truths about one act. The fix is not a new rule; it is the empty state
// joining the rule that already existed.
//
// Direction of the fix, permanently: UI DENY + BACKEND DENY. Never the reverse.
// Every backend assertion pinned here is pinned so that a future "make the UI
// and backend agree" cannot be satisfied by weakening the server.
//
// What this bug does NOT touch, and what is therefore pinned here as hard as
// the fix itself:
//   - REVISION-RME-CONSENT-ODONTOGRAM-PRECONSENT-EDIT-1: the ACTIVE odontogram
//     stays editable before consent. Blocking the record must never drag the
//     chart down with it.
//   - The finish gate: "Selesai Pemeriksaan" stays refused before consent.
//   - Reading: history, legacy archive and previous charts stay readable.

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Consent\Interfaces\RmeVisitConsentRepositoryInterface;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Services\OdontogramService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Collection;
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

/**
 * A visit for the shared patient in the RME branch, with NO consent signed
 * unless a test asks for one. Deliberately the opposite default from
 * PatientCentricRmWorkspaceTest: this file is about the gate itself.
 */
function pcfpVisit(string $status = ClinicVisit::STATUS_IN_PROGRESS, ?Patient $patient = null): ClinicVisit
{
    return ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => ($patient ?? test()->patient)->id,
        'status' => $status,
    ]);
}

/**
 * The one thing "actionable" means here: a real submittable POST form aimed at
 * the medical-record store route.
 *
 * Asserting on the LABEL alone would be satisfied by a disabled button that
 * still says "Buat Halaman RM Pertama" — which is exactly the state we WANT
 * before consent — so the label is not the signal. The form is.
 */
function pcfpCreateFormMarkup(ClinicVisit $workspaceVisit): string
{
    return 'action="'.route('rme.visits.medical-record.store', $workspaceVisit).'"';
}

/**
 * A `Doctor`-role account linked to its own master record — the only shape in
 * which DoctorPatientScopeService actually engages, since it short-circuits to
 * "allowed" for every non-Doctor role.
 */
function pcfpLinkedDoctorUser(): object
{
    $user = userWith([
        'view_clinic_visits', 'manage_clinic_visits', 'complete_rme_examination', 'manage_rme_consents',
    ]);
    $user->assignRole('Doctor');

    $doctor = Doctor::factory()->create([
        'branch_id' => test()->branch->id,
        'user_id' => $user->id,
    ]);

    return (object) ['user' => $user->fresh(), 'doctorRecordId' => $doctor->id];
}

/*
|--------------------------------------------------------------------------
| §1 — the bug: in_progress + no consent + no sheet
|--------------------------------------------------------------------------
*/

it('does not offer an actionable first-page create control while consent is unsigned', function () {
    $visit = pcfpVisit();

    expect(app(RmeVisitConsentService::class)->canAuthorRmeForPatient($this->patient->id, $this->doctorUser))
        ->toBeFalse('fixture precondition: this encounter has no signed consent');

    $response = $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk();

    // The empty state still renders — reading is never gated.
    $response->assertSee('Belum ada halaman RM tulisan tangan untuk pasien ini.');

    // But nothing on the page can actually submit the create.
    $response->assertDontSee(pcfpCreateFormMarkup($visit), false);
});

it('tells the doctor WHY the first page cannot be created yet', function () {
    $visit = pcfpVisit();

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Persetujuan Tindakan Medis belum ditandatangani');
});

it('does not instruct the doctor to press a control it will not honour', function () {
    // The pre-fix copy said "Buat halaman RM pertama pada kunjungan pertama
    // pasien" as a plain instruction, next to a button the server would refuse.
    // Whatever the wording becomes, it must not read as an instruction to act
    // while the act is forbidden.
    $visit = pcfpVisit();

    $html = $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('Buat halaman RM pertama pada kunjungan pertama pasien');
});

/*
|--------------------------------------------------------------------------
| §2 — the backend stays authoritative (the wrong fix is impossible)
|--------------------------------------------------------------------------
*/

it('still refuses a direct first-page create POST while consent is unsigned', function () {
    $visit = pcfpVisit();

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.medical-record.store', $visit), [])
        ->assertSessionHasErrors('consent');

    expect(MedicalRecord::where('patient_id', $this->patient->id)->count())->toBe(0);
});

it('still refuses the create at the service, below any request or view', function () {
    $visit = pcfpVisit();

    expect(fn () => app(MedicalRecordService::class)->createDraft($visit, $this->doctorUser->id))
        ->toThrow(ValidationException::class);

    expect(MedicalRecord::where('patient_id', $this->patient->id)->count())->toBe(0);
});

it('refuses a forged create even when the client pretends another visit is the source', function () {
    // Client-side tampering: the button is gone, so the only way to reach the
    // create is to build the request by hand. It must still be refused, and the
    // submitted source_visit_id must not become an authority.
    $visit = pcfpVisit();
    $other = pcfpVisit(ClinicVisit::STATUS_COMPLETED);

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.medical-record.store', $visit), ['source_visit_id' => $other->id])
        ->assertSessionHasErrors('consent');

    expect(MedicalRecord::where('patient_id', $this->patient->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| §3 — after a valid same-visit consent, the control returns and works
|--------------------------------------------------------------------------
*/

it('offers the first-page create control once a valid same-visit consent exists', function () {
    $visit = rmeActiveConsentedEncounter(pcfpVisit());

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Buat Halaman RM Pertama')
        ->assertSee(pcfpCreateFormMarkup($visit), false);
});

it('creates the first page once a valid same-visit consent exists', function () {
    $visit = rmeActiveConsentedEncounter(pcfpVisit());

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.medical-record.store', $visit), [])
        ->assertRedirect();

    expect(MedicalRecord::where('patient_id', $this->patient->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| §4 — same-visit consent, not any consent
|--------------------------------------------------------------------------
*/

it('does not unlock the control from a DIFFERENT visit of the same patient', function () {
    // A terminal earlier visit carries a signed consent; today's encounter does
    // not. The capability must follow the live encounter, not the archive.
    //
    // Sprint 64.0 anchors the RM book on the patient's earliest non-cancelled
    // visit, so opening today's URL redirects to that older canonical one. The
    // page under test is therefore the redirect TARGET, and the control that
    // must stay unavailable there is the canonical visit's own create form.
    $old = pcfpVisit(ClinicVisit::STATUS_COMPLETED);
    rmeSignedConsentFor($old);

    $today = pcfpVisit();

    $this->actingAs($this->doctorUser)
        ->followingRedirects()
        ->get(route('rme.visits.medical-record.show', $today))
        ->assertOk()
        ->assertDontSee(pcfpCreateFormMarkup($old), false)
        ->assertDontSee(pcfpCreateFormMarkup($today), false);

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.medical-record.store', $today), [])
        ->assertSessionHasErrors('consent');
});

it('does not unlock the control from ANOTHER patient consent', function () {
    $otherPatient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    rmeActiveConsentedEncounter(pcfpVisit(ClinicVisit::STATUS_IN_PROGRESS, $otherPatient));

    $mine = pcfpVisit();

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.medical-record.show', $mine))
        ->assertOk()
        ->assertDontSee(pcfpCreateFormMarkup($mine), false);
});

/*
|--------------------------------------------------------------------------
| §5 — before "Mulai Pemeriksaan": consent is never the ONLY condition
|--------------------------------------------------------------------------
*/

it('does not offer the control on a visit that has not started, even with a signed consent', function () {
    $visit = pcfpVisit(ClinicVisit::STATUS_REGISTERED);
    rmeSignedConsentFor($visit);

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertDontSee(pcfpCreateFormMarkup($visit), false);

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.medical-record.store', $visit), [])
        ->assertSessionHasErrors('consent');
});

it('names "start the examination" as the reason before the examination has started', function () {
    $visit = pcfpVisit(ClinicVisit::STATUS_REGISTERED);

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Belum ada pemeriksaan yang berjalan');
});

/*
|--------------------------------------------------------------------------
| §6 — historical visits stay closed
|--------------------------------------------------------------------------
*/

it('does not offer the control on a terminal visit that carries a signed consent', function (string $status) {
    $visit = pcfpVisit($status);
    rmeSignedConsentFor($visit);

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertDontSee(pcfpCreateFormMarkup($visit), false);

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.medical-record.store', $visit), [])
        ->assertSessionHasErrors('consent');

    expect(MedicalRecord::where('patient_id', $this->patient->id)->count())->toBe(0);
})->with([
    ClinicVisit::STATUS_CASHIER_PENDING,
    ClinicVisit::STATUS_COMPLETED,
]);

it('leaves a cancelled visit with no workspace to offer the control on', function () {
    // A cancelled visit is not an eligible RM anchor at all, so the workspace
    // 404s before any capability question is asked. Pinned separately from the
    // other terminal states because the refusal happens a layer earlier — and
    // pinned so that "make cancelled visits reachable" can never quietly become
    // a way to reach the create.
    $visit = pcfpVisit(ClinicVisit::STATUS_CANCELLED);
    rmeSignedConsentFor($visit);

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertNotFound();

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.medical-record.store', $visit), [])
        ->assertSessionHasErrors('consent');

    expect(MedicalRecord::where('patient_id', $this->patient->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| §7 — authorization is unchanged: consent never widens who may act
|--------------------------------------------------------------------------
*/

it('does not offer the control to a view-only user even with a valid consent', function () {
    $visit = rmeActiveConsentedEncounter(pcfpVisit());

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertDontSee(pcfpCreateFormMarkup($visit), false)
        ->assertDontSee('Buat Halaman RM Pertama');

    $this->actingAs($this->viewer)
        ->post(route('rme.visits.medical-record.store', $visit), [])
        ->assertForbidden();
});

it('shows a view-only user no consent prompt at all, not merely no button', function () {
    // A mutation that dropped the `$canEdit` half from the ALERT guard survived
    // every other assertion here: the button stayed gated by the second guard,
    // so nothing failed — but a read-only user was being told about a signature
    // they have no part in. The consent prompt is addressed to whoever may act,
    // so it belongs to the same condition as the control.
    $visit = pcfpVisit();

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertDontSee('Persetujuan Tindakan Medis belum ditandatangani')
        ->assertDontSee('Belum ada pemeriksaan yang berjalan')
        // Not even the DISABLED control. A read-only user has no business being
        // shown an action that is not theirs in any state — and asserting only
        // on the form would miss it, since the pre-consent control is a button
        // with no form behind it.
        ->assertDontSee('Buat Halaman RM Pertama')
        ->assertDontSee(pcfpCreateFormMarkup($visit), false);
});

it('keeps the capability default failing CLOSED in the template itself', function () {
    // Defense in depth, pinned at the source because it is unreachable at
    // runtime: the controller always supplies the key, so no request can
    // exercise the fallback. That is exactly why it needs a guard — an absent
    // key must mean "assume not allowed". A `?? false` here would silently
    // restore a live create form on any future render path that forgot it,
    // which is the regression this whole sprint exists to close.
    $blade = file_get_contents(resource_path('views/rme/visits/medical-record/empty.blade.php'));

    expect($blade)->toContain('($addSheetConsentRequired ?? true)')
        ->and($blade)->not->toContain('($addSheetConsentRequired ?? false)');
});

it('does not offer the control to a DIFFERENT doctor, consent or no consent', function () {
    // Doctor scope only engages for the `Doctor` role, so this is the real
    // shape of the cross-doctor case: A treats the patient, B does not.
    // A valid consent belongs to the ENCOUNTER, never to whoever is looking.
    $doctorA = pcfpLinkedDoctorUser();
    $doctorB = pcfpLinkedDoctorUser();

    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $doctorA->doctorRecordId,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);
    rmeSignedConsentFor($visit);

    expect(app(RmeVisitConsentService::class)->canAuthorRmeForPatient($this->patient->id, $doctorA->user))
        ->toBeTrue('the treating doctor keeps the capability')
        ->and(app(RmeVisitConsentService::class)->canAuthorRmeForPatient($this->patient->id, $doctorB->user))
        ->toBeFalse('an unrelated doctor never inherits it');

    // The refusal lands EARLIER than the consent gate for this actor: the
    // policy already denies an unrelated doctor, so the response is a flat 403.
    // Asserting on the consent message here would be asserting the wrong layer.
    $this->actingAs($doctorB->user)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.visits.medical-record.store', $visit), [])
        ->assertForbidden();

    expect(MedicalRecord::where('patient_id', $this->patient->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| §8 — the previous GO behaviour survives (no regression either way)
|--------------------------------------------------------------------------
*/

it('keeps the ACTIVE odontogram editable while consent is unsigned', function () {
    // REVISION-RME-CONSENT-ODONTOGRAM-PRECONSENT-EDIT-1. Blocking the record
    // must not drag the chart with it: charting is observation.
    $visit = pcfpVisit();

    app(OdontogramService::class)->saveForVisit(
        $visit,
        ['tooth_map_payload' => ['teeth' => ['11' => ['conditions' => ['caries']]]]],
        $this->doctorUser,
    );

    $chart = Odontogram::where('clinic_visit_id', $visit->id)->firstOrFail();

    expect($chart->tooth_map_payload['teeth']['11']['conditions'])->toBe(['caries'])
        ->and(app(RmeVisitConsentService::class)->hasValidConsent($visit->fresh()))->toBeFalse();
});

it('keeps the odontogram page reachable while the record is blocked', function () {
    $visit = pcfpVisit();

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk();
});

it('keeps "Selesai Pemeriksaan" refused while consent is unsigned', function () {
    $visit = pcfpVisit();

    expect(fn () => app(ClinicVisitService::class)->transitionStatus(
        $visit,
        ClinicVisit::STATUS_CASHIER_PENDING,
        $this->doctorUser,
    ))->toThrow(ValidationException::class);

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('keeps an EXISTING record read-only while consent is unsigned, and writable after', function () {
    // Driving the service directly still needs a resolvable actor: the gate
    // fails closed on a null Auth::user() rather than skipping the scope check.
    $this->actingAs($this->doctorUser);

    $visit = pcfpVisit();
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    expect(fn () => app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'x']))
        ->toThrow(ValidationException::class);

    rmeActiveConsentedEncounter($visit);

    app(MedicalRecordService::class)->updateDraft($record->fresh(), ['notes' => 'ditulis setelah consent']);

    expect($record->fresh()->notes)->toBe('ditulis setelah consent');
});

it('never turns a fault in the capability path into an actionable control', function () {
    // FAIL CLOSED ON ERROR. A mutation that wrapped canAuthorRmeForPatient() in
    // a try/catch returning TRUE survived every other assertion in this file,
    // because nothing here made the capability path throw. That direction is the
    // dangerous one: an exception would hand the doctor a live create over an
    // act the server refuses.
    //
    // So make it throw for real, and pin the only acceptable outcomes: the page
    // may fail, but it may never render a submittable create form.
    // The fault must land INSIDE the capability computation and nowhere else.
    // Throwing from ActiveEncounterResolver looked right and was useless: the
    // controller resolves the encounter a SECOND time for the banner, outside
    // any try/catch, so the page 500s for that reason and the assertion below
    // passes without ever exercising the capability. The consent lookup is
    // reached only from resolveAuthorizedEncounter(), so it isolates the path.
    $visit = rmeActiveConsentedEncounter(pcfpVisit());

    app()->bind(RmeVisitConsentRepositoryInterface::class, fn () => new class implements RmeVisitConsentRepositoryInterface
    {
        public function validForVisit(int $clinicVisitId): ?RmeVisitConsent
        {
            throw new RuntimeException('capability path fault');
        }

        public function historyForVisit(int $clinicVisitId): Collection
        {
            return collect();
        }

        public function create(array $attributes): RmeVisitConsent
        {
            throw new RuntimeException('not used');
        }

        public function latestConsentNumberForMonth(string $month): ?string
        {
            return null;
        }
    });

    $response = $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.medical-record.show', $visit));

    expect($response->status())->not->toBe(200);
    expect($response->getContent())->not->toContain(pcfpCreateFormMarkup($visit));
});

/*
|--------------------------------------------------------------------------
| §9 — the UI reads the canonical capability, not a permission
|--------------------------------------------------------------------------
*/

it('drives the control from the same authority the service asserts', function () {
    // The structural claim of this sprint. The actor holds `manage_clinic_visits`
    // and passes MedicalRecordPolicy::create for this visit — the pre-fix gate —
    // and yet the control must be withheld, because the canonical capability
    // says no. If a future refactor reverts the empty state to the permission,
    // this is the assertion that catches it.
    $visit = pcfpVisit();

    expect($this->doctorUser->can('create', [MedicalRecord::class, $visit]))
        ->toBeTrue('pre-fix gate still passes — that was exactly the bug')
        ->and(app(RmeVisitConsentService::class)->canAuthorRmeForPatient($this->patient->id, $this->doctorUser))
        ->toBeFalse();

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertDontSee(pcfpCreateFormMarkup($visit), false);
});
