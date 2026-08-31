<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeWaveBranch;
use App\Modules\LegacyRme\Support\LegacyRmeWaveBranchStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

/**
 * LEGACY-RME-PDF-ROLL-4 — authorization of the operations control plane, and the
 * one interaction that matters clinically: migration state must never change
 * what a doctor can read.
 *
 * WHY THAT SECOND HALF IS HERE. HISTORY-1A separated the migration CAPABILITY
 * from the PUBLISHED clinical READ, and HISTORY-1B scoped a doctor's read to
 * their practice branches. ROLL-4 introduces four new ways for a migration to
 * stop — wave paused, wave draining, branch drained, wave closed — and each one
 * is a fresh opportunity to accidentally take a patient's history away from the
 * doctor treating them. These tests pin that none of them do.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    seedAccessControl();
    Storage::fake('legacy_rme_private');
    Bus::fake();
    legacyRmeArchiveFlag(true);

    // MAIN is never an RME clinic branch; leaving it RME-enabled would let an
    // unpinned BranchContext fall back into scope and mask a real failure.
    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
});

function opsAccessWave(): LegacyRmeMigrationWave
{
    return LegacyRmeMigrationWave::query()->where('code', 'TEST-WAVE')->firstOrFail();
}

function opsAccessBranch(string $code = 'TLK1'): LegacyRmeWaveBranch
{
    return LegacyRmeWaveBranch::query()->where('branch_code', $code)->firstOrFail();
}

/** A published archive owned by the patient's own branch. */
function opsAccessPublished(Patient $patient, string $date = '2019-04-02'): LegacyRmeRecord
{
    return LegacyRmeRecord::factory()->create([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => $patient->branch_id,
        'rme_date' => $date,
        'latest_rme_date' => null,
        'status' => LegacyRmeRecord::STATUS_PUBLISHED,
        'page_count' => 3,
    ]);
}

/** A Doctor-role user who really practises at the patient's branch. */
function opsAccessDoctor(Patient $patient, bool $treating): User
{
    $user = User::factory()->create(['branch_id' => $patient->branch_id]);
    $user->assignRole('Doctor');
    $user->givePermissionTo('view_legacy_rme_archive');

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

// ---------------------------------------------------------------------------
// Surface authorization
// ---------------------------------------------------------------------------

it('404s the operations surface while the migration capability is off', function () {
    legacyRmeBranch('TLK1');
    legacyRmeArchiveFlag(false);

    // 404 rather than 403: a disabled deployment does not advertise that a
    // migration control plane exists.
    $this->actingAs(superAdmin())
        ->get(route('settings.rme.migration-operations.index'))
        ->assertNotFound();
});

it('denies the operations surface to a user without either operations permission', function () {
    legacyRmeBranch('TLK1');

    $this->actingAs(userWith(['view_legacy_rme_imports', 'create_legacy_rme_imports']))
        ->get(route('settings.rme.migration-operations.index'))
        ->assertForbidden();
});

it('allows a read-only viewer to see the operations dashboard', function () {
    legacyRmeBranch('TLK1');

    $this->actingAs(userWith(['view_legacy_rme_migration_operations']))
        ->get(route('settings.rme.migration-operations.index'))
        ->assertOk();
});

it('denies wave governance to a read-only viewer', function () {
    legacyRmeBranch('TLK1');
    $wave = opsAccessWave();

    // Reading the rollout and steering it are different duties.
    $this->actingAs(userWith(['view_legacy_rme_migration_operations']))
        ->post(route('settings.rme.migration-operations.transition', $wave), [
            'action' => 'pause',
            'reason' => 'Percobaan tanpa izin kelola.',
        ])
        ->assertForbidden();

    expect($wave->fresh()->status)->toBe(LegacyRmeWaveStatus::ACTIVE);
});

it('denies wave approval to someone who may manage but not approve', function () {
    legacyRmeBranch('TLK1');
    $wave = opsAccessWave();
    $wave->forceFill(['status' => LegacyRmeWaveStatus::DRAFT])->save();

    $this->actingAs(userWith([
        'view_legacy_rme_migration_operations',
        'manage_legacy_rme_migration_operations',
    ]))
        ->post(route('settings.rme.migration-operations.approve', $wave))
        ->assertForbidden();

    expect($wave->fresh()->status)->toBe(LegacyRmeWaveStatus::DRAFT);
});

// ---------------------------------------------------------------------------
// IDOR
// ---------------------------------------------------------------------------

it('refuses to operate on a branch enrollment that belongs to another wave', function () {
    legacyRmeBranch('TLK1');
    $wave = opsAccessWave();
    $branch = opsAccessBranch();

    $otherWave = LegacyRmeMigrationWave::query()->create([
        'code' => 'TEST-WAVE-OTHER',
        'name' => 'Gelombang Lain',
        'status' => LegacyRmeWaveStatus::ACTIVE,
    ]);

    // A branch id from the URL is only ever operated on when it genuinely
    // belongs to the wave in the same URL.
    $this->actingAs(superAdmin())
        ->post(route('settings.rme.migration-operations.branches.update', [$otherWave, $branch]), [
            'action' => 'pause',
            'reason' => 'Percobaan lintas gelombang.',
        ])
        ->assertNotFound();

    expect($branch->fresh()->status)->toBe(LegacyRmeWaveBranchStatus::ACTIVE);
});

it('refuses to assign an operator onto a branch from another wave', function () {
    legacyRmeBranch('TLK1');
    $branch = opsAccessBranch();

    $otherWave = LegacyRmeMigrationWave::query()->create([
        'code' => 'TEST-WAVE-OTHER',
        'name' => 'Gelombang Lain',
        'status' => LegacyRmeWaveStatus::ACTIVE,
    ]);

    $operator = userWith(['view_legacy_rme_imports', 'create_legacy_rme_imports']);

    $this->actingAs(superAdmin())
        ->post(route('settings.rme.migration-operations.operators.assign', $otherWave), [
            'user_id' => $operator->getKey(),
            'wave_branch_id' => $branch->getKey(),
        ])
        ->assertNotFound();
});

it('rejects a wave code that is not a canonical token', function () {
    legacyRmeBranch('TLK1');

    $this->actingAs(superAdmin())
        ->post(route('settings.rme.migration-operations.store'), [
            'code' => 'WAVE 1; DROP TABLE users',
            'name' => 'Gelombang Tidak Valid',
            'branch_codes' => ['TLK1'],
        ])
        ->assertSessionHasErrors('code');

    expect(LegacyRmeMigrationWave::query()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Migration state NEVER changes clinical read — the HISTORY-1A/1B contract
// ---------------------------------------------------------------------------

it('keeps a published archive readable to the treating doctor while the wave is paused', function () {
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1980-01-01'], 'TLK1');
    $record = opsAccessPublished($patient);
    $doctor = opsAccessDoctor($patient, treating: true);

    opsAccessWave()->forceFill([
        'status' => LegacyRmeWaveStatus::PAUSED,
        'paused_at' => now(),
    ])->save();

    // Pausing a MIGRATION must never withdraw a patient's history from the
    // doctor treating them: read is governed by record state plus authorization,
    // never by the rollout's operational state.
    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record))
        ->assertOk();
});

it('keeps a published archive readable after the branch is drained and the wave closed', function () {
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1980-01-01'], 'TLK1');
    $record = opsAccessPublished($patient);
    $doctor = opsAccessDoctor($patient, treating: true);

    opsAccessBranch()->forceFill(['status' => LegacyRmeWaveBranchStatus::COMPLETED])->save();
    opsAccessWave()->forceFill([
        'status' => LegacyRmeWaveStatus::COMPLETED,
        'completed_at' => now(),
    ])->save();

    // A finished migration is the NORMAL end state. If closing a wave hid the
    // archive it produced, the whole exercise would be self-defeating.
    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record))
        ->assertOk();
});

it('still denies a non-treating doctor while the wave is paused', function () {
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1980-01-01'], 'TLK1');
    $record = opsAccessPublished($patient);
    $stranger = opsAccessDoctor($patient, treating: false);

    opsAccessWave()->forceFill(['status' => LegacyRmeWaveStatus::PAUSED])->save();

    // The read boundary is unchanged in BOTH directions: an operational pause
    // neither withdraws access from the treating doctor nor grants it to anyone
    // else.
    $this->actingAs($stranger)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record))
        ->assertForbidden();
});
