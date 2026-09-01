<?php

// FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 — CORRECTIVE-01 + CORRECTIVE-02
//
// CORRECTIVE-01  Consent is signable ONLY while the examination is running.
// CORRECTIVE-02  Current-RME authoring requires POSITIVE authority: an authorized,
//                consented, in_progress encounter. The absence of a blocker is
//                never authority to write.

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\DiagnosisRolloutService;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Services\OdontogramService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_rme_enabled' => true]);
    $this->otherBranch = Branch::factory()->create(['code' => 'SUN4', 'is_rme_enabled' => true]);

    $this->doctorUser = userWith([
        'view_clinic_visits', 'manage_clinic_visits', 'complete_rme_examination', 'manage_rme_consents',
    ]);
    $this->cashier = userWith(['manage_rme_billing']);
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);

    // CORRECTIVE-02 condition 3 — an RME write must have a resolvable, authorized
    // actor, so the gate fails closed when none can be resolved. Service-level
    // tests therefore act as a real user; individual tests override this, and the
    // "no actor" case logs out explicitly.
    $this->actingAs($this->doctorUser);
});

function corrVisit(string $status, ?Patient $patient = null, ?Branch $branch = null): ClinicVisit
{
    return ClinicVisit::factory()->create([
        'branch_id' => ($branch ?? test()->branch)->id,
        'patient_id' => ($patient ?? test()->patient)->id,
        'status' => $status,
    ]);
}

function corrRecord(ClinicVisit $visit): MedicalRecord
{
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
    ]);

    return $record;
}

/*
|--------------------------------------------------------------------------
| CORRECTIVE-01 — consent timing (cases 1-7)
|--------------------------------------------------------------------------
*/

it('denies consent signing while the visit is only registered', function () {
    $visit = corrVisit(ClinicVisit::STATUS_REGISTERED);

    expect(app(RmeVisitConsentService::class)->isSignable($visit))->toBeFalse()
        ->and(fn () => app(RmeVisitConsentService::class)->assertSignable($visit))
        ->toThrow(ValidationException::class);
});

it('denies opening the consent form while the visit is only registered', function () {
    $visit = corrVisit(ClinicVisit::STATUS_REGISTERED);

    $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.consent.create', $visit))
        ->assertRedirect(route('rme.visits.show', $visit));
});

it('denies consent signing while the visit is waiting', function () {
    $visit = corrVisit(ClinicVisit::STATUS_WAITING);

    expect(app(RmeVisitConsentService::class)->isSignable($visit))->toBeFalse();
});

it('allows consent signing once the examination is in progress', function () {
    $visit = corrVisit(ClinicVisit::STATUS_IN_PROGRESS);

    expect(app(RmeVisitConsentService::class)->isSignable($visit))->toBeTrue();

    $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.consent.create', $visit))
        ->assertOk();
});

it('denies NEW consent signing once the examination is finished (cashier_pending)', function () {
    $visit = corrVisit(ClinicVisit::STATUS_CASHIER_PENDING);

    expect(app(RmeVisitConsentService::class)->isSignable($visit))->toBeFalse();
});

it('denies new consent signing on a completed visit', function () {
    $visit = corrVisit(ClinicVisit::STATUS_COMPLETED);

    expect(app(RmeVisitConsentService::class)->isSignable($visit))->toBeFalse();
});

it('denies new consent signing on a cancelled visit', function () {
    $visit = corrVisit(ClinicVisit::STATUS_CANCELLED);

    expect(app(RmeVisitConsentService::class)->isSignable($visit))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| CORRECTIVE-02 — positive authoring authority (cases 8-16)
|--------------------------------------------------------------------------
*/

it('denies adding an RME sheet when the patient has no examination in progress', function () {
    $visit = corrVisit(ClinicVisit::STATUS_REGISTERED);

    expect(fn () => app(MedicalRecordService::class)->createDraft($visit, $this->doctorUser->id))
        ->toThrow(ValidationException::class);
});

it('denies editing the record when the patient has no examination in progress', function () {
    $visit = corrVisit(ClinicVisit::STATUS_WAITING);
    $record = corrRecord($visit);

    expect(fn () => app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'x']))
        ->toThrow(ValidationException::class);

    expect($record->refresh()->notes)->not->toBe('x');
});

it('denies saving handwriting when the patient has no examination in progress', function () {
    $visit = corrVisit(ClinicVisit::STATUS_REGISTERED);
    $record = corrRecord($visit);
    $before = MedicalRecordHandwriting::count();

    $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.visits.medical-record.handwriting.store', [$visit, $record]), [
            'handwriting_data' => 'data:image/png;base64,'.base64_encode('x'),
            'page_number' => 2,
        ])
        ->assertSessionHasErrors();

    expect(MedicalRecordHandwriting::count())->toBe($before);
});

it('CANCEL-THEN-AUTHOR is impossible: cancelling the encounter does not unlock the canonical record', function () {
    // THE REPRODUCER. Under an absence-of-blocker rule, cancelling the live visit
    // removed the only unconsented open encounter and the patient's canonical
    // record — an old terminal visit's record — became writable again.
    $canonical = corrVisit(ClinicVisit::STATUS_COMPLETED);
    $canonicalRecord = corrRecord($canonical);

    $live = corrVisit(ClinicVisit::STATUS_IN_PROGRESS);

    // Blocked while the live encounter is unconsented.
    expect(fn () => app(MedicalRecordService::class)->updateDraft($canonicalRecord, ['notes' => 'a']))
        ->toThrow(ValidationException::class);

    // Cancel it — and the record must STILL be unwritable.
    $live->forceFill(['status' => ClinicVisit::STATUS_CANCELLED])->save();

    expect(fn () => app(MedicalRecordService::class)->updateDraft($canonicalRecord, ['notes' => 'a']))
        ->toThrow(ValidationException::class);

    expect($canonicalRecord->refresh()->notes)->not->toBe('a');
});

it('COMPLETE-THEN-AUTHOR is impossible: finishing the encounter does not unlock the canonical record', function () {
    $canonical = corrVisit(ClinicVisit::STATUS_COMPLETED);
    $canonicalRecord = corrRecord($canonical);

    $live = corrVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $live->forceFill(['status' => ClinicVisit::STATUS_CASHIER_PENDING])->save();

    expect(fn () => app(MedicalRecordService::class)->updateDraft($canonicalRecord, ['notes' => 'b']))
        ->toThrow(ValidationException::class);
});

it('denies authoring while the in-progress encounter has no signed consent', function () {
    $visit = corrVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $record = corrRecord($visit);

    expect(fn () => app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'x']))
        ->toThrow(ValidationException::class);
    expect(fn () => app(MedicalRecordService::class)->finalize($record))
        ->toThrow(ValidationException::class);
});

it('allows authoring with an authorized consented in-progress encounter', function () {
    $visit = corrVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($visit);
    $record = corrRecord($visit);

    app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'catatan']);
    app(MedicalRecordService::class)->finalize($record);

    expect($record->refresh()->notes)->toBe('catatan')
        ->and($record->status)->toBe(MedicalRecord::STATUS_FINAL);
});

it('does not let a consent signed for another visit authorise this encounter', function () {
    $other = corrVisit(ClinicVisit::STATUS_CASHIER_PENDING);
    rmeSignedConsentFor($other);

    $live = corrVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $record = corrRecord($live);

    expect(fn () => app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'x']))
        ->toThrow(ValidationException::class);
});

it('does not let another patient consent authorise this encounter', function () {
    $otherPatient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $otherVisit = corrVisit(ClinicVisit::STATUS_IN_PROGRESS, $otherPatient);
    rmeSignedConsentFor($otherVisit);

    $live = corrVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $record = corrRecord($live);

    expect(fn () => app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'x']))
        ->toThrow(ValidationException::class);
});

it('does not let an out-of-branch actor author the encounter', function () {
    $live = corrVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($live);
    $record = corrRecord($live);

    $outsider = userWith(['view_clinic_visits', 'manage_clinic_visits']);
    rmeMakeKasirActive($outsider, $this->otherBranch);

    $this->actingAs($outsider);

    expect(fn () => app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'x']))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| Historical access must remain readable (cases 17-20)
|--------------------------------------------------------------------------
*/

it('keeps historical native RME readable while the current encounter is unconsented', function () {
    $old = corrVisit(ClinicVisit::STATUS_COMPLETED);
    corrRecord($old);
    corrVisit(ClinicVisit::STATUS_IN_PROGRESS);

    $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->followingRedirects()
        ->get(route('rme.visits.medical-record.show', $old))
        ->assertOk();
});

it('keeps the odontogram history readable while the current encounter is unconsented', function () {
    $old = corrVisit(ClinicVisit::STATUS_COMPLETED);
    Odontogram::factory()->create([
        'clinic_visit_id' => $old->id,
        'branch_id' => $old->branch_id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
    ]);

    $live = corrVisit(ClinicVisit::STATUS_IN_PROGRESS);

    expect(app(OdontogramService::class)->patientHistoryForVisit($live, $this->doctorUser))
        ->toHaveCount(1);

    $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.odontogram.show', $live))
        ->assertOk()
        ->assertSee('Riwayat Odontogram Pasien');
});

/*
|--------------------------------------------------------------------------
| Explicit completion, unchanged (cases 21-24)
|--------------------------------------------------------------------------
*/

it('keeps the visit in_progress when RME and odontogram are complete, and advances only on the explicit action', function () {
    $visit = corrVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($visit);
    $record = corrRecord($visit);

    app(MedicalRecordService::class)->finalize($record);
    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);

    $odontogram = app(OdontogramService::class)->getOrCreateForVisit($visit, $this->doctorUser);
    app(OdontogramService::class)->updatePlaceholder($odontogram, [
        'tooth_map_payload' => ['teeth' => ['16' => ['status' => 'filling']]],
    ], $this->doctorUser);
    app(OdontogramService::class)->finalize($odontogram, $this->doctorUser);

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);

    $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CASHIER_PENDING]);

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('charts the active odontogram before the consent, and still after it', function () {
    /*
     * This case has now been inverted TWICE, which is worth stating plainly.
     *
     * Originally it asserted the active odontogram was deliberately OUTSIDE the
     * consent gate. CORRECTIVE-03 inverted it, on the reasoning that a chart is a
     * clinical finding recorded during a treatment decision, so gating the note
     * and not the chart consents to nothing coherent.
     * REVISION-RME-CONSENT-ODONTOGRAM-PRECONSENT-EDIT-1 restores the original
     * behaviour, for a reason CORRECTIVE-03 did not weigh: charting is
     * OBSERVATION, and the consent form's named treatment is derived FROM it, so
     * requiring the signature first made the workflow circular.
     *
     * What survives every inversion is the security intent: charting is reachable
     * only inside a legitimate, in-scope, in-progress encounter, and consent
     * still gates the RME and the finish. Those are pinned in
     * RmeConsentOdontogramPreConsentEditTest.
     */
    $visit = corrVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $odontogram = app(OdontogramService::class)->getOrCreateForVisit($visit, $this->doctorUser);

    app(OdontogramService::class)->updatePlaceholder($odontogram, [
        'tooth_map_payload' => ['teeth' => ['21' => ['status' => 'caries']]],
    ], $this->doctorUser);

    expect($odontogram->refresh()->tooth_map_payload['teeth'])->toHaveKey('21');

    rmeSignedConsentFor($visit);

    app(OdontogramService::class)->updatePlaceholder($odontogram, [
        'tooth_map_payload' => ['teeth' => ['22' => ['status' => 'caries']]],
    ], $this->doctorUser);

    expect($odontogram->refresh()->tooth_map_payload['teeth'])->toHaveKey('22');
});

/*
|--------------------------------------------------------------------------
| Payment stays consent-independent (cases 25-26)
|--------------------------------------------------------------------------
*/

it('keeps a historical receivable collectible with no consent anywhere', function () {
    $visit = corrVisit(ClinicVisit::STATUS_CASHIER_PENDING);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
    ]);

    $invoice = app(RmeInvoiceService::class)->create($visit, $this->cashier, [
        'items' => [['treatment_id' => null, 'description' => 'Tindakan', 'qty' => 1, 'unit_price' => 200000, 'discount' => 0]],
    ]);

    expect($visit->consents()->count())->toBe(0);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, ['amount' => 50000, 'paid_at' => now()->toDateString()]);
    expect($invoice->refresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL);

    app(RmePaymentService::class)->pay($invoice->refresh(), $this->cashier, ['amount' => 150000, 'paid_at' => now()->toDateString()]);
    expect($invoice->refresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('does not block a cashier_pending payment on consent state', function () {
    $visit = corrVisit(ClinicVisit::STATUS_CASHIER_PENDING);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
    ]);

    $invoice = app(RmeInvoiceService::class)->create($visit, $this->cashier, [
        'items' => [['treatment_id' => null, 'description' => 'Tindakan', 'qty' => 1, 'unit_price' => 100000, 'discount' => 0]],
    ]);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, ['amount' => 100000, 'paid_at' => now()->toDateString()]);

    expect($invoice->refresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($visit->refresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

/*
|--------------------------------------------------------------------------
| Adversarial re-review regressions — found after the corrective, fixed, pinned
|--------------------------------------------------------------------------
*/

it('renders the odontogram page without a driver-specific SQL error', function () {
    /*
     * The history query briefly compared the `jsonb` tooth_map_payload to an empty
     * string. sqlite accepts that; PostgreSQL has no jsonb = text operator and
     * fatals, so every odontogram page in production would have returned 500 while
     * every local test passed. The predicate is now IS NOT NULL, which is portable
     * and is also exactly what excludes the auto-created empty drafts.
     */
    $old = corrVisit(ClinicVisit::STATUS_COMPLETED);
    Odontogram::factory()->create([
        'clinic_visit_id' => $old->id,
        'branch_id' => $old->branch_id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
    ]);
    // An auto-created empty draft must not appear as history.
    $empty = corrVisit(ClinicVisit::STATUS_COMPLETED);
    Odontogram::factory()->create([
        'clinic_visit_id' => $empty->id,
        'branch_id' => $empty->branch_id,
        'tooth_map_payload' => null,
    ]);

    $live = corrVisit(ClinicVisit::STATUS_IN_PROGRESS);

    $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.odontogram.show', $live))
        ->assertOk();

    expect(app(OdontogramService::class)->patientHistoryForVisit($live, $this->doctorUser))
        ->toHaveCount(1);
});

it('runs the emergency diagnosis override through the positive authority gate', function () {
    // The corrective renamed the assertion and this call site was missed, making
    // the override endpoint a guaranteed fatal error.
    $live = corrVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $record = corrRecord($live);

    // No consent yet: refused by the gate, NOT by a PHP error.
    expect(fn () => app(DiagnosisRolloutService::class)
        ->grantOverride($record, 'darurat: pasien tidak sadarkan diri', $this->doctorUser))
        ->toThrow(ValidationException::class);
});

it('fails closed when the patient has TWO examinations in progress', function () {
    // Otherwise a second encounter's consent authorises writes attributed to the
    // first — defeating a refusal on the visit actually happening.
    $first = corrVisit(ClinicVisit::STATUS_IN_PROGRESS);
    $record = corrRecord($first);

    $second = corrVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($second);

    expect(fn () => app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'x']))
        ->toThrow(ValidationException::class);

    // Closing the stale one restores a single unambiguous encounter.
    $second->forceFill(['status' => ClinicVisit::STATUS_CANCELLED])->save();
    rmeSignedConsentFor($first);

    app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'x']);
    expect($record->refresh()->notes)->toBe('x');
});

it('fails closed when no actor can be resolved', function () {
    // The scope check must never be skipped just because the caller is unauthenticated.
    $live = corrVisit(ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($live);
    $record = corrRecord($live);

    auth()->logout();

    expect(app(RmeVisitConsentService::class)->canAuthorRmeForPatient($this->patient->id, null))
        ->toBeFalse();

    expect(fn () => app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'x']))
        ->toThrow(ValidationException::class);
});
