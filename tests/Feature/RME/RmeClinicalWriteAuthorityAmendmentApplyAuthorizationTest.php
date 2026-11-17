<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR5 — who may approve, and who may read the version history
|--------------------------------------------------------------------------
|
| Rule 240 §7. Approve sits behind `approve_rme_amendments`, RmeAmendmentPolicy
| (404 for an amendment the account may not see, the service's reason
| otherwise) and RmeAmendmentService inside the transaction (binding Super
| Admin). The detail page offers "Setujui dan Terapkan" only where the service
| would accept it. The version history of a record is read like the record
| (404 outside its scope), `private, no-store`, and never renders an object key:
| a page image is linked only through the existing policy-gated page route.
| Prints and the record page carry "Diamandemen n×".
|
| Selected by the existing `RmeClinicalWriteAuthority` critical-gate token in
| both runner variants and declared in critical_gate_mandatory_suites (CG-19).
| Helper prefix `m3az`.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Services\RmeAmendmentService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    seedAccessControl();

    $this->branch = Branch::factory()->create(['code' => 'AMP1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $this->doctorUser = userInRole('Doctor');
    $this->doctor = Doctor::factory()->create(['user_id' => $this->doctorUser->id, 'branch_id' => $this->branch->id, 'is_active' => true]);
    rmeMakeDoctorOnline($this->doctor, $this->branch, $this->room, $this->doctorUser);
    $this->doctorUser = $this->doctorUser->fresh();
    $this->supervisor = userInRole('Supervisor RME');
});

function m3azSheet(?User $finalizer = null): MedicalRecord
{
    $visit = ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id,
        'clinic_room_id' => test()->room->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
    ]);
    $sheet = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => test()->doctor->id,
        'assessment' => 'm3az pulpitis',
    ]);
    $sheet->forceFill(['status' => MedicalRecord::STATUS_FINAL, 'finalized_at' => now()->subDay(), 'finalized_by' => ($finalizer ?? test()->doctorUser)->id])->save();

    return $sheet->fresh();
}

function m3azPending(?MedicalRecord $sheet = null, ?User $by = null): RmeAmendment
{
    return app(RmeAmendmentService::class)->request($sheet ?? m3azSheet(), $by ?? test()->doctorUser, 'm3az diagnosis kerja salah ketik', ['fields' => ['assessment' => 'm3az pulpitis ireversibel']]);
}

it('lets a Supervisor RME approve over HTTP and refuses the front desk, the requester, the doctor of record and the finalizer', function () {
    $amendment = m3azPending();

    foreach (['Front Office' => 'rmeMakeFrontOfficeActive', 'Kasir' => 'rmeMakeKasirActive', 'Admin Klinik' => 'rmeMakeAdminClinicActive', 'Perawat' => 'rmeMakePerawatActive'] as $role => $activate) {
        $user = userInRole($role);
        $activate($user, $this->branch);
        $this->actingAs($user)->post(route('rme.amendments.approve', $amendment))->assertForbidden();
    }

    $this->doctorUser->givePermissionTo(RmeAmendmentService::PERMISSION_APPROVE);
    $this->actingAs($this->doctorUser->fresh())->post(route('rme.amendments.approve', $amendment))->assertForbidden();

    $finalizedBySupervisor = m3azPending(m3azSheet($this->supervisor));
    $this->actingAs($this->supervisor)->get(route('rme.amendments.show', $finalizedBySupervisor))
        ->assertOk()
        ->assertDontSee('data-amendment-approve', false);
    $this->actingAs($this->supervisor)->post(route('rme.amendments.approve', $finalizedBySupervisor))->assertForbidden();

    $superAdmin = userInRole('Super Admin');
    $own = m3azPending(m3azSheet($superAdmin), $superAdmin);
    $this->actingAs($superAdmin)->post(route('rme.amendments.approve', $own))->assertSessionHasErrors('amendment');
    expect($own->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING);

    $this->actingAs($this->supervisor)->get(route('rme.amendments.show', $amendment))
        ->assertOk()
        ->assertSee('data-amendment-approve', false);
    $this->actingAs($this->supervisor)->post(route('rme.amendments.approve', $amendment), ['decision_reason' => str_repeat('x', 1001)])
        ->assertSessionHasErrors('decision_reason');
    $this->actingAs($this->supervisor)->post(route('rme.amendments.approve', $amendment), ['decision_reason' => ''])
        ->assertRedirect(route('rme.amendments.show', $amendment));

    expect($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_APPLIED)
        ->and($amendment->fresh()->decision_reason)->toBeNull()
        ->and($finalizedBySupervisor->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING);
});

it('answers 404 to an approver outside the amendment\'s branch scope, before validating', function () {
    $amendment = m3azPending();
    $other = Branch::factory()->create(['code' => 'AMP2', 'is_active' => true, 'is_rme_enabled' => true]);
    $pinned = userInRole('Perawat');
    $pinned->givePermissionTo(RmeAmendmentService::PERMISSION_APPROVE);
    rmeMakePerawatActive($pinned, $other);

    $this->actingAs($pinned)->post(route('rme.amendments.approve', $amendment), ['decision_reason' => str_repeat('x', 1001)])->assertNotFound();
    expect($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING);
});

it('reads the version history like the record: private, no-store, 404 outside its scope, no object key', function () {
    $sheet = m3azSheet();
    // A sealed page of the book: in every version's snapshot, with its object key.
    $page = MedicalRecordHandwritingPage::query()->forceCreate([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $sheet->clinic_visit_id,
        'branch_id' => $sheet->branch_id,
        'page_number' => 2,
        'handwriting_path' => 'clinical-evidence/m3az/secret-object-key.png',
        'handwriting_hash' => str_repeat('a', 64),
        'sealed_at' => now()->subHour(),
        'sealed_by_medical_record_id' => $sheet->id,
    ]);
    app(RmeAmendmentService::class)->approve(m3azPending($sheet), $this->supervisor);

    foreach ([$this->doctorUser, $this->supervisor] as $reader) {
        $this->actingAs($reader)->get(route('rme.amendments.versions.sheet', $sheet))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertSee('data-version-row="2"', false);
    }

    $this->actingAs($this->supervisor)->get(route('rme.amendments.versions.sheet.show', [$sheet, 2]))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertSee('m3az pulpitis ireversibel')
        ->assertSee(route('rme.handwriting-pages.image', $page), false)
        ->assertDontSee('secret-object-key');

    $stranger = rmeMakeDoctorOnline(Doctor::factory()->create(['branch_id' => $this->branch->id]), $this->branch);
    $this->actingAs($stranger)->get(route('rme.amendments.versions.sheet', $sheet))->assertNotFound();
    $this->actingAs($stranger)->get(route('rme.amendments.versions.sheet.show', [$sheet, 1]))->assertNotFound();
});

it('marks the RME print and the sheet page "Diamandemen 1×" once an amendment is applied', function () {
    $sheet = m3azSheet();

    $this->actingAs($this->supervisor)->get(route('rme.visits.print', $sheet->clinicVisit))
        ->assertOk()
        ->assertDontSee('Diamandemen');

    app(RmeAmendmentService::class)->approve(m3azPending($sheet), $this->supervisor);

    $this->actingAs($this->supervisor)->get(route('rme.visits.print', $sheet->clinicVisit))
        ->assertOk()
        ->assertSee('Diamandemen 1×');
    $this->actingAs($this->supervisor)->get(route('rme.visits.medical-record.show', $sheet->clinicVisit))
        ->assertOk()
        ->assertSee('Diamandemen 1×')
        ->assertSee(route('rme.amendments.versions.sheet', $sheet), false);
});

// ─── Review fixes (M3.3 PR5 review) ──────────────────────────────────────────

it('answers 404, never a server error, for an out-of-range key or version number (LOW)', function () {
    $sheet = m3azSheet();
    $huge = '99999999999999999999';

    foreach ([
        "/rme/medical-records/{$sheet->id}/versions/{$huge}",
        "/rme/medical-records/{$huge}/versions",
        "/rme/medical-records/{$huge}/amendments/create",
        "/rme/odontograms/{$huge}/versions",
        "/rme/amendments/{$huge}",
        "/rme/medical-records/{$sheet->id}/versions/0",
    ] as $uri) {
        $this->actingAs($this->supervisor)->get($uri)->assertNotFound();
    }
});

it('loads the version list without a query per version for its author (LOW)', function () {
    $sheet = m3azSheet();
    app(RmeAmendmentService::class)->approve(m3azPending($sheet), $this->supervisor);
    app(RmeAmendmentService::class)->approve(
        app(RmeAmendmentService::class)->request($sheet->fresh(), $this->doctorUser, 'm3az koreksi kedua sheet', ['fields' => ['assessment' => 'm3az pulpitis akut']]),
        userInRole('Supervisor RME'),
    );

    $userQueries = 0;
    Event::listen(QueryExecuted::class, function ($q) use (&$userQueries): void {
        $sql = strtolower($q->sql);
        if (str_contains($sql, 'from "users"') && str_contains($sql, '"users"."id" in')) {
            $userQueries++;
        }
    });

    $this->actingAs($this->supervisor)->get(route('rme.amendments.versions.sheet', $sheet))
        ->assertOk()
        ->assertSee('data-version-row="3"', false);

    expect($userQueries)->toBe(1);
});
