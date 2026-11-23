<?php

/*
| DMS-SPN4-READY-PILOT-1 G6 / REM-2a, R2-6 — a guardian's identity number never
| reaches the session when the consent form is refused.
|
| When the consenter is not the patient, the form asks for the consenter's
| KTP/SIM number (`consenter_identity_number`, rme/consents/create). The input is
| refilled with old(), so a refused consent used to copy the number into the
| database session store (CG-11). It is now in the exception handler's dontFlash.
| Both refusal paths reach the handler: the FormRequest rules and the service's
| own ValidationException. The consent controller re-flashes nothing itself.
|
| Nothing else changes: every other field is still flashed, and a valid consent
| still stores the number on the consent exactly as before. The number below is
| synthetic (region code 99 does not exist).
|
| Selected by the `RmeVisitConsent` critical-gate token in both runner variants
| and declared in critical_gate_mandatory_suites (CG-19). Helper prefix `rvgi`.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;

const RVGI_IDENTITY = '9971010101900007';

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake('local');
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = Branch::factory()->create(['code' => 'RVG1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->operator = userWith(['view_clinic_visits', 'manage_rme_consents', 'view_rme_consents']);
});

function rvgiVisit(): ClinicVisit
{
    $visit = ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => test()->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    return $visit->refresh();
}

/** A guardian (mother) signs for the patient, giving her own KTP number. */
function rvgiGuardianPayload(array $overrides = []): array
{
    return array_merge([
        'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
        'consenter_relationship' => 'ibu',
        'consenter_name' => 'Ibu Wali Sintetis',
        'consenter_age' => '41',
        'consenter_gender' => 'P',
        'consenter_address' => 'Jl. Wali No. 3',
        'consenter_identity_number' => RVGI_IDENTITY,
        'medical_action' => 'Pencabutan gigi 36',
        'treatment_summary' => 'Pencabutan gigi',
        'documentation_consent' => '0',
        'consenter_signature' => validPodSignatureData(),
    ], $overrides);
}

function rvgiSessionHoldsIdentity(): bool
{
    return str_contains((string) json_encode(session()->all()), RVGI_IDENTITY);
}

it('RVGI-01 never flashes the guardian identity number when the request rules refuse the consent', function () {
    $visit = rvgiVisit();

    $this->actingAs($this->operator)
        ->from(route('rme.visits.consent.create', $visit))
        ->post(route('rme.visits.consent.store', $visit), rvgiGuardianPayload(['medical_action' => '']))
        ->assertRedirect(route('rme.visits.consent.create', $visit))
        ->assertSessionHasErrors('medical_action');

    expect(session()->getOldInput())->not->toHaveKey('consenter_identity_number')
        ->and(session()->getOldInput('consenter_name'))->toBe('Ibu Wali Sintetis')
        ->and(session()->getOldInput('consenter_relationship'))->toBe('ibu')
        ->and(session()->getOldInput('consenter_address'))->toBe('Jl. Wali No. 3')
        ->and(rvgiSessionHoldsIdentity())->toBeFalse()
        ->and(RmeVisitConsent::count())->toBe(0);
});

it('RVGI-02 never flashes the guardian identity number when the consent service refuses it', function () {
    $visit = rvgiVisit();
    $payload = rvgiGuardianPayload();
    unset($payload['documentation_consent']);

    $this->actingAs($this->operator)
        ->from(route('rme.visits.consent.create', $visit))
        ->post(route('rme.visits.consent.store', $visit), $payload)
        ->assertRedirect(route('rme.visits.consent.create', $visit))
        ->assertSessionHasErrors('documentation_consent');

    expect(session()->getOldInput())->not->toHaveKey('consenter_identity_number')
        ->and(session()->getOldInput('consenter_name'))->toBe('Ibu Wali Sintetis')
        ->and(rvgiSessionHoldsIdentity())->toBeFalse()
        ->and(RmeVisitConsent::count())->toBe(0);
});

it('RVGI-03 still signs a valid guardian consent and stores the number on it, exactly as before', function () {
    $visit = rvgiVisit();

    $this->actingAs($this->operator)
        ->from(route('rme.visits.consent.create', $visit))
        ->post(route('rme.visits.consent.store', $visit), rvgiGuardianPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('rme.consents.show', RmeVisitConsent::query()->sole()));

    expect(RmeVisitConsent::query()->sole()->consenter_identity_number)->toBe(RVGI_IDENTITY)
        ->and(RmeVisitConsent::query()->sole()->consenter_name)->toBe('Ibu Wali Sintetis');
});
