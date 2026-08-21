<?php

/**
 * FIX-04b — a doctor reaches an archive only for a patient they actually treat.
 *
 * Branch scope alone is not enough. DoctorPatientScopeService admits a patient
 * to a doctor only through a real clinical relationship, and a same-branch
 * doctor with no such relationship is refused the patient's NATIVE odontogram.
 * If the archive stopped at branch scope it would be the BROADER door — a
 * legacy chart would be readable to someone who cannot open the live one.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPatientHistoryService;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramRecordStatus;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    lodoFlag(true);
    Storage::fake('legacy_odontogram_private');
    Bus::fake();

    // MAIN is never an RME clinic branch; leaving it RME-enabled would let an
    // unpinned BranchContext fall back into scope and hide a real failure.
    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    // Sprint 66's global EnsureRmeOnlineContext redirects any Doctor-role user
    // who has not picked an online context (302). That is orthogonal to what
    // these tests are about — the archive's OWN access boundary — and without
    // bypassing it every assertion below would measure the session selector
    // instead of the policy.
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);
});

/** A Doctor-role user, optionally with a real clinical relationship. */
function lodoDoctor(Patient $patient, bool $treating): User
{
    $user = User::factory()->create(['branch_id' => $patient->branch_id]);
    $user->assignRole('Doctor');
    $user->givePermissionTo('view_legacy_odontogram_archive');

    $doctor = Doctor::factory()->create([
        'user_id' => $user->getKey(),
        'branch_id' => $patient->branch_id,
        'is_active' => true,
    ]);

    if ($treating) {
        PatientDoctorAssignment::factory()->create([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'unassigned_at' => null,
        ]);
    }

    return $user->refresh();
}

function lodoPublishedRow(Patient $patient, string $date = '2018-02-03'): LegacyOdontogramRecord
{
    return LegacyOdontogramRecord::factory()->create([
        'patient_id' => $patient->getKey(),
        'branch_id' => $patient->branch_id,
        'source_branch_code' => 'TKM1',
        'odontogram_date' => $date,
        'status' => LegacyOdontogramRecordStatus::PUBLISHED,
        'page_count' => 2,
    ]);
}

it('lets a TREATING doctor read the patient archive', function () {
    $patient = lodoPatient();
    $record = lodoPublishedRow($patient);
    $doctor = lodoDoctor($patient, treating: true);

    expect(app(LegacyOdontogramPatientHistoryService::class)
        ->publishedRecordsFor($doctor, (int) $patient->id))
        ->toHaveCount(1);

    $this->actingAs($doctor)
        ->get(route('rme.legacy-odontograms.show', $record->getKey()))
        ->assertOk();
});

it('refuses a same-branch doctor with NO clinical relationship to the patient', function () {
    $patient = lodoPatient();
    $record = lodoPublishedRow($patient);
    $doctor = lodoDoctor($patient, treating: false);

    // Empty, not an exception: the caller renders this inside a page that must
    // still work.
    expect(app(LegacyOdontogramPatientHistoryService::class)
        ->publishedRecordsFor($doctor, (int) $patient->id))
        ->toHaveCount(0);

    $this->actingAs($doctor)
        ->get(route('rme.legacy-odontograms.show', $record->getKey()))
        ->assertForbidden();
});

it('refuses a doctor account with no linked doctor master record', function () {
    $patient = lodoPatient();
    $record = lodoPublishedRow($patient);

    $orphan = User::factory()->create(['branch_id' => $patient->branch_id]);
    $orphan->assignRole('Doctor');
    $orphan->givePermissionTo('view_legacy_odontogram_archive');

    expect(app(LegacyOdontogramPatientHistoryService::class)
        ->publishedRecordsFor($orphan->refresh(), (int) $patient->id))
        ->toHaveCount(0);

    // A 404 rather than a 403, and that is the stronger outcome: an orphan
    // doctor resolves to an EMPTY branch set, so the repository refuses before
    // the policy is even consulted. Fail-closed at the scope layer means the id
    // is not even confirmed to exist.
    $this->actingAs($orphan)
        ->get(route('rme.legacy-odontograms.show', $record->getKey()))
        ->assertNotFound();
});

it('does not let a read permission widen a doctor to every RME branch', function () {
    $mine = lodoPatient([], 'TKM1');
    $theirs = lodoPatient([], 'LDK2');

    lodoPublishedRow($mine);
    $theirRecord = LegacyOdontogramRecord::factory()->create([
        'patient_id' => $theirs->getKey(),
        'branch_id' => $theirs->branch_id,
        'odontogram_date' => '2018-02-03',
        'status' => LegacyOdontogramRecordStatus::PUBLISHED,
    ]);

    $doctor = lodoDoctor($mine, treating: true);

    // Even if the doctor somehow reached the other patient, the branch scope
    // stops them first — the read permission is deliberately NOT governance tier.
    $this->actingAs($doctor)
        ->get(route('rme.legacy-odontograms.show', $theirRecord->getKey()))
        ->assertNotFound();
});
