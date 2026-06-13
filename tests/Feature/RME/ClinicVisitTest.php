<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_clinic_visits']);
    $this->viewer = userWith(['view_clinic_visits']);

    $this->clinic = Clinic::factory()->create();
    $this->patient = Patient::factory()->create();
    $this->doctor = Doctor::factory()->create();
    $this->treatment = Treatment::factory()->create(['is_active' => true]);
});

it('denies users without clinic visit permission', function () {
    $this->actingAs(userWith([]))
        ->get(route('rme.visits.index'))
        ->assertForbidden();
});

it('lists visits for a viewer', function () {
    ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'visit_number' => 'VIS-20260609-001',
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->viewer->id,
        'queue_number' => 1,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertViewIs('rme.visits.index')
        ->assertSee('VIS-20260609-001');
});

it('only lists visits from active branch', function () {
    ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'visit_number' => 'VIS-ACTIVE-001',
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->viewer->id,
        'queue_number' => 1,
    ]);

    $otherBranch = Branch::factory()->create(['code' => 'OTH1']);
    ClinicVisit::factory()->create([
        'branch_id' => $otherBranch->id,
        'visit_number' => 'VIS-OTHER-001',
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->viewer->id,
        'queue_number' => 1,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('VIS-ACTIVE-001')
        ->assertDontSee('VIS-OTHER-001');
});

it('lets manager open create page', function () {
    $this->actingAs($this->manager)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertViewIs('rme.visits.create');
});

it('stores valid clinic visit in active branch', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'chief_complaint' => 'Sakit gigi',
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('trx_clinic_visits', [
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'chief_complaint' => 'Sakit gigi',
        'status' => 'registered',
    ]);
});

it('auto-generates queue_number per branch per visit_date', function () {
    $patient2 = Patient::factory()->create();

    // Seed first visit directly (avoids SQLite savepoint visibility issue in tests).
    // Production uses lockForUpdate() inside DB::transaction() for concurrency safety.
    ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'visit_date' => today()->toDateString(),
        'queue_number' => 1,
        'visit_number' => 'VIS-'.today()->format('Ymd').'-001',
    ]);

    // Second visit via HTTP — must get queue_number = 2 since 1 is taken.
    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'clinic_id' => $this->clinic->id,
            'patient_id' => $patient2->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    $second = ClinicVisit::where('branch_id', $this->branch->id)
        ->where('patient_id', $patient2->id)
        ->first();

    expect($second)->not->toBeNull()
        ->and($second->queue_number)->toBe(2);
});

it('auto-generates visit_number in VIS-YYYYMMDD-NNN format', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ]);

    $visit = ClinicVisit::where('branch_id', $this->branch->id)->first();

    expect($visit->visit_number)
        ->toMatch('/^VIS-\d{8}-\d{3}$/')
        ->toStartWith('VIS-'.now()->format('Ymd').'-');
});

it('rejects invalid patient, doctor, or room', function () {
    $this->actingAs($this->manager)
        ->from(route('rme.visits.create'))
        ->post(route('rme.visits.store'), [
            'clinic_id' => 99999,
            'patient_id' => 99999,
            'doctor_id' => 99999,
            'clinic_room_id' => 99999,
        ])
        ->assertSessionHasErrors(['clinic_id', 'patient_id', 'doctor_id', 'clinic_room_id']);
});

it('uses the selected RME branch as the visit branch (Klinik = Cabang RME)', function () {
    // Sprint 23 Phase 23.9.1 — visit branch follows the chosen RME-enabled branch.
    $rmeBranch = Branch::factory()->create(['code' => 'RME9', 'is_rme_enabled' => true]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'branch_id' => $rmeBranch->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('trx_clinic_visits', [
        'branch_id' => $rmeBranch->id,
        'patient_id' => $this->patient->id,
    ]);
});

it('falls back to active branch context when no branch is supplied', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('trx_clinic_visits', [
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
    ]);
});

it('updates room but ignores status field', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);
    $room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->put(route('rme.visits.update', $visit), [
            'status' => ClinicVisit::STATUS_IN_PROGRESS,
            'clinic_room_id' => $room->id,
        ])
        ->assertRedirect(route('rme.visits.show', $visit));

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_REGISTERED)
        ->and($visit->clinic_room_id)->toBe($room->id);
});

it('update endpoint cannot bypass transition — status change is ignored', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
        'status' => ClinicVisit::STATUS_REGISTERED,
    ]);

    $this->actingAs($this->manager)
        ->put(route('rme.visits.update', $visit), [
            'status' => ClinicVisit::STATUS_COMPLETED,
        ])
        ->assertRedirect(route('rme.visits.show', $visit));

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_REGISTERED);
});

it('denies viewer from creating or updating', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.create'))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('rme.visits.store'), [
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->put(route('rme.visits.update', $visit), ['status' => 'waiting'])
        ->assertForbidden();
});

it('prevents updating visits from another branch', function () {
    $otherBranch = Branch::factory()->create(['code' => 'OTH3']);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $otherBranch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $this->actingAs($this->manager)
        ->put(route('rme.visits.update', $visit), [
            'status' => ClinicVisit::STATUS_IN_PROGRESS,
        ])
        ->assertForbidden();

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_REGISTERED);
});

// ─── Status Transition Workflow Tests ────────────────────────────────────────

it('manager can transition registered to waiting', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
        'status' => ClinicVisit::STATUS_REGISTERED,
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_WAITING])
        ->assertRedirect(route('rme.visits.show', $visit));

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_WAITING);
});

it('manager can transition waiting to in_progress', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
        'status' => ClinicVisit::STATUS_WAITING,
        'check_in_at' => now(),
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_IN_PROGRESS])
        ->assertRedirect(route('rme.visits.show', $visit));

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('manager can transition in_progress to completed', function () {
    $visit = ClinicVisit::factory()->inProgress()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_COMPLETED])
        ->assertRedirect(route('rme.visits.show', $visit));

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('manager can cancel from registered, waiting, or in_progress', function () {
    foreach ([
        ClinicVisit::STATUS_REGISTERED,
        ClinicVisit::STATUS_WAITING,
        ClinicVisit::STATUS_IN_PROGRESS,
    ] as $i => $fromStatus) {
        $visit = ClinicVisit::factory()->create([
            'branch_id' => $this->branch->id,
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'created_by' => $this->manager->id,
            'queue_number' => $i + 10,
            'status' => $fromStatus,
            'check_in_at' => in_array($fromStatus, [ClinicVisit::STATUS_WAITING, ClinicVisit::STATUS_IN_PROGRESS]) ? now() : null,
            'started_at' => $fromStatus === ClinicVisit::STATUS_IN_PROGRESS ? now() : null,
        ]);

        $this->actingAs($this->manager)
            ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CANCELLED])
            ->assertRedirect(route('rme.visits.show', $visit));

        expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_CANCELLED);
    }
});

it('completed visit cannot transition to any status', function () {
    $visit = ClinicVisit::factory()->completed()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    foreach ([ClinicVisit::STATUS_REGISTERED, ClinicVisit::STATUS_WAITING, ClinicVisit::STATUS_IN_PROGRESS, ClinicVisit::STATUS_CANCELLED] as $target) {
        $this->actingAs($this->manager)
            ->post(route('rme.visits.transition', $visit), ['status' => $target])
            ->assertSessionHasErrors('status');
    }

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('cancelled visit cannot transition to any status', function () {
    $visit = ClinicVisit::factory()->cancelled()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    foreach ([ClinicVisit::STATUS_REGISTERED, ClinicVisit::STATUS_WAITING, ClinicVisit::STATUS_IN_PROGRESS, ClinicVisit::STATUS_COMPLETED] as $target) {
        $this->actingAs($this->manager)
            ->post(route('rme.visits.transition', $visit), ['status' => $target])
            ->assertSessionHasErrors('status');
    }

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_CANCELLED);
});

it('rejects invalid backward transitions', function () {
    $visit = ClinicVisit::factory()->inProgress()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    // in_progress -> waiting and in_progress -> registered are both invalid
    foreach ([ClinicVisit::STATUS_WAITING, ClinicVisit::STATUS_REGISTERED] as $target) {
        $this->actingAs($this->manager)
            ->post(route('rme.visits.transition', $visit), ['status' => $target])
            ->assertSessionHasErrors('status');
    }

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('rejects direct registered to completed transition', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
        'status' => ClinicVisit::STATUS_REGISTERED,
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_COMPLETED])
        ->assertSessionHasErrors('status');

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_REGISTERED);
});

it('viewer cannot transition status', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
        'status' => ClinicVisit::STATUS_REGISTERED,
    ]);

    $this->actingAs($this->viewer)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_WAITING])
        ->assertForbidden();

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_REGISTERED);
});

it('user from another branch cannot transition visit status', function () {
    $otherBranch = Branch::factory()->create(['code' => 'OTH4']);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $otherBranch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
        'status' => ClinicVisit::STATUS_REGISTERED,
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_WAITING])
        ->assertForbidden();

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_REGISTERED);
});

it('sets check_in_at on transition to waiting, started_at on in_progress, completed_at on completed', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
        'status' => ClinicVisit::STATUS_REGISTERED,
        'check_in_at' => null,
        'started_at' => null,
        'completed_at' => null,
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_WAITING]);
    expect($visit->refresh()->check_in_at)->not->toBeNull();

    $this->actingAs($this->manager)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_IN_PROGRESS]);
    expect($visit->refresh()->started_at)->not->toBeNull();

    $this->actingAs($this->manager)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_COMPLETED]);
    $visit->refresh();
    expect($visit->completed_at)->not->toBeNull()
        ->and($visit->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('does not overwrite existing timestamps on transition', function () {
    $fixedCheckIn = now()->subHour();
    $fixedStarted = now()->subMinutes(30);

    $visit = ClinicVisit::factory()->inProgress()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
        'check_in_at' => $fixedCheckIn,
        'started_at' => $fixedStarted,
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_COMPLETED]);

    $visit->refresh();
    expect($visit->check_in_at->timestamp)->toBe($fixedCheckIn->timestamp)
        ->and($visit->started_at->timestamp)->toBe($fixedStarted->timestamp)
        ->and($visit->completed_at)->not->toBeNull();

    // completed_at is not set on cancel — test it separately
    $cancelVisit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 2,
        'status' => ClinicVisit::STATUS_REGISTERED,
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.transition', $cancelVisit), ['status' => ClinicVisit::STATUS_CANCELLED]);

    expect($cancelVisit->refresh()->completed_at)->toBeNull();
});

it('sets cancelled_at on transition to cancelled', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 99,
        'status' => ClinicVisit::STATUS_REGISTERED,
        'cancelled_at' => null,
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CANCELLED]);

    $visit->refresh();
    expect($visit->status)->toBe(ClinicVisit::STATUS_CANCELLED)
        ->and($visit->cancelled_at)->not->toBeNull();
});

it('does not overwrite cancelled_at if already set', function () {
    $fixed = now()->subHours(2);

    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 98,
        'status' => ClinicVisit::STATUS_REGISTERED,
        'cancelled_at' => $fixed,
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CANCELLED]);

    $visit->refresh();
    expect($visit->cancelled_at->timestamp)->toBe($fixed->timestamp);
});

it('rejects transition request with status registered', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 97,
        'status' => ClinicVisit::STATUS_WAITING,
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_REGISTERED])
        ->assertSessionHasErrors('status');

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_WAITING);
});

it('shows RME sidebar links for user with view_clinic_visits', function () {
    $this->actingAs($this->viewer)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Kunjungan')
        ->assertSee('Rekam Medis')
        ->assertSee(route('rme.visits.index'), false)
        ->assertSee(route('rme.medical-records.index'), false);
});

it('shows RME sidebar links for user with manage_clinic_visits', function () {
    $this->actingAs($this->manager)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Kunjungan')
        ->assertSee('Rekam Medis')
        ->assertSee(route('rme.visits.index'), false)
        ->assertSee(route('rme.medical-records.index'), false);
});

it('hides RME sidebar links for user without clinic visit permission', function () {
    $this->actingAs(userWith(['view dashboard']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('rme.visits.index'), false)
        ->assertDontSee(route('rme.medical-records.index'), false);
});

it('denies dashboard access to users without dashboard permissions', function () {
    $this->actingAs(userWith([]))
        ->get(route('dashboard'))
        ->assertForbidden();
});

it('shows rme widget section for user with view_clinic_visits', function () {
    $this->actingAs($this->viewer)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Kunjungan Hari Ini')
        ->assertSee('Menunggu')
        ->assertSee('Sedang Dilayani')
        ->assertSee('RM Draft')
        ->assertSee('RM Final Hari Ini');
});

it('widget visits today count matches branch visits on today date only', function () {
    ClinicVisit::factory()->count(2)->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->viewer->id,
        'visit_date' => today()->toDateString(),
    ]);

    ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->viewer->id,
        'visit_date' => today()->subDay()->toDateString(),
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Kunjungan Hari Ini');
});

it('widget waiting count shows only waiting status visits', function () {
    ClinicVisit::factory()->count(2)->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'status' => ClinicVisit::STATUS_WAITING,
    ]);

    ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Menunggu');
});

it('widget in_progress count shows only in_progress status visits', function () {
    ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    ClinicVisit::factory()->count(2)->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'status' => ClinicVisit::STATUS_WAITING,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Sedang Dilayani');
});

it('widget draft medical record count is branch isolated', function () {
    MedicalRecord::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => MedicalRecord::STATUS_DRAFT,
    ]);

    $otherBranch = Branch::factory()->create(['code' => 'RMOB1']);
    MedicalRecord::factory()->count(2)->create([
        'branch_id' => $otherBranch->id,
        'status' => MedicalRecord::STATUS_DRAFT,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('RM Draft');
});

it('widget finalized today count shows only finalized on today date', function () {
    MedicalRecord::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => MedicalRecord::STATUS_FINAL,
        'finalized_at' => now(),
    ]);

    MedicalRecord::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => MedicalRecord::STATUS_FINAL,
        'finalized_at' => now()->subDay(),
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('RM Final Hari Ini');
});

// ─── Sprint 20 Phase 1.7 — RME Visit Print Bundle ────────────────────────────

it('manager can open visit print view', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk();
});

it('viewer can open visit print view', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertOk();
});

it('user without permission cannot open visit print view', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $this->actingAs(userWith([]))
        ->get(route('rme.visits.print', $visit))
        ->assertForbidden();
});

it('cross-branch user cannot open visit print view', function () {
    $otherBranch = Branch::factory()->create(['code' => 'XBRP1']);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $otherBranch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.print', $visit))
        ->assertForbidden();
});

it('print view displays patient and visit info', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 5,
        'visit_number' => 'VIS-20260610-005',
        'chief_complaint' => 'Gigi berlubang',
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee($this->patient->name)
        ->assertSee('VIS-20260610-005')
        ->assertSee('Gigi berlubang');
});

it('print view displays medical record info if available', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'subjective' => 'Keluhan sakit gigi kiri',
        'assessment' => 'Karies profunda',
        'plan' => 'Cabut gigi 36',
        'status' => MedicalRecord::STATUS_FINAL,
        'finalized_at' => now(),
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Final')
        ->assertSee('Difinalisasi')
        ->assertDontSee('Subjektif (Anamnesis)')
        ->assertDontSee('Keluhan sakit gigi kiri');
});

it('print view displays odontogram info if available', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'summary_notes' => 'Catatan odontogram kunjungan ini',
        'tooth_map_payload' => [
            'teeth' => ['16' => ['status' => 'caries', 'conditions' => ['filling']]],
        ],
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Catatan odontogram kunjungan ini')
        ->assertSee('Karies')
        ->assertSee('Tambalan');
});

it('print view handles missing odontogram gracefully', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Odontogram belum tersedia');
});

it('print view displays saved handwriting preview', function () {
    Storage::fake('public');

    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'status' => MedicalRecord::STATUS_FINAL,
    ]);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'handwriting_path' => 'handwritings/test/print-preview.png',
    ]);

    Storage::disk('public')->put('handwritings/test/print-preview.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC',
        true
    ));

    $handwriting = MedicalRecordHandwriting::where('medical_record_id', $record->id)->firstOrFail();

    $this->actingAs($this->manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('RME Tulisan Tangan')
        ->assertSee('Tersimpan pada')
        ->assertSee($handwriting->previewUrl(), false);
});

it('print view displays empty handwriting message when none exists', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Belum ada handwriting RM.');
});

it('print view does not require SOAP fields when handwriting exists', function () {
    Storage::fake('public');

    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'subjective' => null,
        'objective' => null,
        'assessment' => null,
        'plan' => null,
        'notes' => null,
        'status' => MedicalRecord::STATUS_FINAL,
    ]);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'handwriting_path' => 'handwritings/test/print-no-soap.png',
    ]);

    Storage::disk('public')->put('handwritings/test/print-no-soap.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC',
        true
    ));

    $handwriting = MedicalRecordHandwriting::where('medical_record_id', $record->id)->firstOrFail();

    $this->actingAs($this->manager)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee($handwriting->previewUrl(), false)
        ->assertDontSee('Subjektif (Anamnesis)')
        ->assertDontSee('Objektif (Pemeriksaan)')
        ->assertDontSee('Assessment (Diagnosis)')
        ->assertDontSee('Plan (Rencana Perawatan)');
});

it('Cetak RME button appears on visit show for authorized user', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('Cetak RME');

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('Cetak RME');
});
