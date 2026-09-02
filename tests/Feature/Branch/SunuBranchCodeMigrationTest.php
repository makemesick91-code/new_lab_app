<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Facades\DB;

/**
 * REVISION-SUNU-BRANCH-CODE-SUN4-TO-SPN4-1 — the data migration.
 *
 * The migration has already run by the time a test boots (RefreshDatabase), so
 * these tests re-run the SAME migration file against a table deliberately put
 * back into the pre-migration state. That exercises the real artifact, not a
 * re-implementation of it, and it doubles as the idempotency proof: the file is
 * executed a second time on every one of these tests.
 */
function runSunuBranchCodeMigration(): void
{
    $migration = require database_path('migrations/2026_09_02_100001_revise_sunu_branch_code_sun4_to_spn4.php');

    $migration->up();
}

it('renames the branch code in place, keeping the SAME branch identity', function () {
    $branch = Branch::query()->create([
        'code' => 'SUN4',
        'name' => 'Cabang Sunu',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $branchCountBefore = Branch::withTrashed()->count();

    runSunuBranchCodeMigration();

    $branch->refresh();

    expect($branch->code)->toBe('SPN4')
        ->and($branch->name)->toBe('Cabang Sunu')
        ->and($branch->is_active)->toBeTrue()
        ->and($branch->is_rme_enabled)->toBeTrue()
        // The primary key is the isolation boundary — it must not move.
        ->and(Branch::withTrashed()->count())->toBe($branchCountBefore)
        ->and(Branch::withTrashed()->where('code', 'SUN4')->count())->toBe(0);
});

it('repairs the PRODUCTION shape — branch already renamed, patient RM left behind', function () {
    // This is the exact state production was found in: the branch row had been
    // renamed by hand, so nothing held SUN4 any more, while a patient's Nomor RM
    // still named a branch code that existed nowhere at all.
    $branch = Branch::query()->create([
        'code' => 'SPN4',
        'name' => 'Cabang Sunu',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-SUN4-2026-564',
    ]);

    runSunuBranchCodeMigration();

    $patient->refresh();
    $branch->refresh();

    expect($patient->medical_record_number)->toBe('DG-SPN4-2026-564')
        ->and((int) $patient->branch_id)->toBe((int) $branch->id)
        ->and($branch->code)->toBe('SPN4')
        ->and(Branch::withTrashed()->where('code', 'SUN4')->count())->toBe(0);
});

it('migrates the branch segment of patient medical record numbers without touching the patient', function () {
    $branch = Branch::query()->create([
        'code' => 'SUN4',
        'name' => 'Cabang Sunu',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-SUN4-2026-564',
    ]);

    // Compared as scalars: refresh() rebuilds the Carbon instance, so an
    // object-identity comparison would fail for a reason that has nothing to do
    // with whether the migration touched the patient.
    $snapshot = static fn (Patient $p): array => [
        'id' => (int) $p->id,
        'name' => (string) $p->name,
        'branch_id' => (int) $p->branch_id,
        'date_of_birth' => (string) $p->date_of_birth,
        'ktp_number' => (string) $p->ktp_number,
    ];

    $before = $snapshot($patient);
    $patientCountBefore = Patient::withTrashed()->count();

    runSunuBranchCodeMigration();

    $patient->refresh();

    expect($patient->medical_record_number)->toBe('DG-SPN4-2026-564')
        ->and($snapshot($patient))->toBe($before)
        ->and(Patient::withTrashed()->count())->toBe($patientCountBefore);
});

it('migrates soft-deleted patients too, because the unique index spans them', function () {
    $branch = Branch::query()->create(['code' => 'SUN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true]);

    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-SUN4-2026-999',
    ]);
    $patient->delete();

    runSunuBranchCodeMigration();

    expect(Patient::withTrashed()->find($patient->id)->medical_record_number)->toBe('DG-SPN4-2026-999');
});

it('FAILS CLOSED on a medical record number collision — nothing is overwritten or deleted', function () {
    $branch = Branch::query()->create(['code' => 'SUN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true]);

    $historical = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-SUN4-2026-564',
    ]);
    $canonical = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-SPN4-2026-564',
    ]);

    $patientCountBefore = Patient::withTrashed()->count();

    expect(fn () => runSunuBranchCodeMigration())
        ->toThrow(RuntimeException::class, 'REVISION-SUNU-BRANCH-CODE');

    // Neither patient was touched, merged, renumbered or removed.
    expect(Patient::withTrashed()->find($historical->id)->medical_record_number)->toBe('DG-SUN4-2026-564')
        ->and(Patient::withTrashed()->find($canonical->id)->medical_record_number)->toBe('DG-SPN4-2026-564')
        ->and(Patient::withTrashed()->count())->toBe($patientCountBefore);
});

it('FAILS CLOSED when the canonical branch code is already held by a DIFFERENT branch', function () {
    $sunu = Branch::query()->create(['code' => 'SUN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true]);
    $other = Branch::query()->create(['code' => 'SPN4', 'name' => 'Some Other Clinic', 'is_active' => true, 'is_rme_enabled' => true]);

    expect(fn () => runSunuBranchCodeMigration())
        ->toThrow(RuntimeException::class, 'REVISION-SUNU-BRANCH-CODE');

    // No branch was renamed, merged or deleted — this is a human decision.
    expect(Branch::withTrashed()->find($sunu->id)->code)->toBe('SUN4')
        ->and(Branch::withTrashed()->find($other->id)->code)->toBe('SPN4')
        ->and(Branch::withTrashed()->find($other->id)->name)->toBe('Some Other Clinic');
});

it('is idempotent — a second run changes nothing', function () {
    $branch = Branch::query()->create(['code' => 'SUN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true]);
    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-SUN4-2026-564',
    ]);

    runSunuBranchCodeMigration();

    $afterFirst = [
        'branch' => Branch::withTrashed()->find($branch->id)->code,
        'rm' => Patient::withTrashed()->find($patient->id)->medical_record_number,
        'branches' => Branch::withTrashed()->count(),
        'patients' => Patient::withTrashed()->count(),
    ];

    runSunuBranchCodeMigration();
    runSunuBranchCodeMigration();

    expect([
        'branch' => Branch::withTrashed()->find($branch->id)->code,
        'rm' => Patient::withTrashed()->find($patient->id)->medical_record_number,
        'branches' => Branch::withTrashed()->count(),
        'patients' => Patient::withTrashed()->count(),
    ])->toBe($afterFirst)
        ->and($afterFirst['branch'])->toBe('SPN4')
        ->and($afterFirst['rm'])->toBe('DG-SPN4-2026-564');
});

it('runs cleanly on a database that has nothing to migrate', function () {
    $countBefore = Branch::withTrashed()->count();

    runSunuBranchCodeMigration();

    expect(Branch::withTrashed()->count())->toBe($countBefore);
});

it('leaves a medical record number belonging to another branch alone', function () {
    Branch::query()->create(['code' => 'SUN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true]);
    $other = Branch::query()->create(['code' => 'LDK2', 'name' => 'Cabang Landak', 'is_active' => true, 'is_rme_enabled' => true]);

    $patient = Patient::factory()->create([
        'branch_id' => $other->id,
        'medical_record_number' => 'DG-LDK2-2026-564',
    ]);
    // A sequence that merely CONTAINS the deprecated token must survive whole.
    $sequenceLookalike = Patient::factory()->create([
        'branch_id' => $other->id,
        'medical_record_number' => 'DG-LDK2-2026-SUN4',
    ]);

    runSunuBranchCodeMigration();

    expect($patient->refresh()->medical_record_number)->toBe('DG-LDK2-2026-564')
        ->and($sequenceLookalike->refresh()->medical_record_number)->toBe('DG-LDK2-2026-SUN4');
});

it('PRESERVES issued visit numbers — they are historical transactional identifiers', function () {
    $branch = Branch::query()->create(['code' => 'SUN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true]);
    $patient = Patient::factory()->create(['branch_id' => $branch->id, 'medical_record_number' => 'DG-SUN4-2026-564']);

    $visit = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'visit_number' => 'VIS-SUN4-20260101-0001',
    ]);

    runSunuBranchCodeMigration();

    // Printed on paper already handed to a patient; nothing derives a branch
    // from it, so rewriting it would invalidate that paper to buy nothing.
    expect($visit->refresh()->visit_number)->toBe('VIS-SUN4-20260101-0001');
});

it('creates NO clinical or financial side effects', function () {
    $branch = Branch::query()->create(['code' => 'SUN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true]);
    Patient::factory()->create(['branch_id' => $branch->id, 'medical_record_number' => 'DG-SUN4-2026-564']);

    $count = fn (): array => [
        'visits' => DB::table('trx_clinic_visits')->count(),
        'medical_records' => DB::table('trx_medical_records')->count(),
        'odontograms' => DB::table('trx_odontograms')->count(),
        'invoices' => DB::table('trx_rme_invoices')->count(),
        'payments' => DB::table('trx_rme_payments')->count(),
        'legacy_records' => DB::table('trx_rme_legacy_records')->count(),
        'audit' => DB::table('sys_audit_logs')->count(),
    ];

    $before = $count();

    runSunuBranchCodeMigration();

    expect($count())->toBe($before);
});

it('canonicalizes LIVE rollout rows and leaves a terminal wave untouched', function () {
    Branch::query()->create(['code' => 'SUN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true]);
    $sunuId = (int) Branch::query()->where('code', 'SUN4')->value('id');

    $liveWave = DB::table('ops_rme_legacy_migration_waves')->insertGetId([
        'code' => 'LIVE-WAVE', 'name' => 'Live wave', 'status' => 'ACTIVE',
        'approved_branch_codes' => json_encode(['TLK1', 'LDK2', 'ATG3', 'SUN4']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $deadWave = DB::table('ops_rme_legacy_migration_waves')->insertGetId([
        'code' => 'DEAD-WAVE', 'name' => 'Cancelled wave', 'status' => 'CANCELLED',
        'approved_branch_codes' => json_encode(['SUN4']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $liveRow = DB::table('ops_rme_legacy_wave_branches')->insertGetId([
        'wave_id' => $liveWave, 'branch_code' => 'SUN4', 'branch_id' => $sunuId,
        'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // A non-terminal branch row on a CANCELLED wave — production holds exactly
    // this shape for an earlier revision, and it must stay historical.
    $deadRow = DB::table('ops_rme_legacy_wave_branches')->insertGetId([
        'wave_id' => $deadWave, 'branch_code' => 'SUN4', 'branch_id' => $sunuId,
        'status' => 'DRAINING', 'created_at' => now(), 'updated_at' => now(),
    ]);

    runSunuBranchCodeMigration();

    // The stored value is decoded rather than string-compared: PostgreSQL's
    // jsonb re-normalizes whitespace, so a raw string assertion would pass on
    // SQLite and fail on the database CI and production actually run.
    $approved = fn (int $id): array => (array) json_decode(
        (string) DB::table('ops_rme_legacy_migration_waves')->where('id', $id)->value('approved_branch_codes'),
        true,
    );

    expect(DB::table('ops_rme_legacy_wave_branches')->where('id', $liveRow)->value('branch_code'))->toBe('SPN4')
        ->and(DB::table('ops_rme_legacy_wave_branches')->where('id', $deadRow)->value('branch_code'))->toBe('SUN4')
        ->and($approved($liveWave))->toBe(['TLK1', 'LDK2', 'ATG3', 'SPN4'])
        // The cancelled wave's approval record states what was approved, then.
        ->and($approved($deadWave))->toBe(['SUN4']);
});

it('canonicalizes LIVE wave operators and leaves terminal-wave operators alone', function () {
    Branch::query()->create(['code' => 'SUN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true]);
    $sunuId = (int) Branch::query()->where('code', 'SUN4')->value('id');
    $user = User::factory()->create();

    $liveWave = DB::table('ops_rme_legacy_migration_waves')->insertGetId([
        'code' => 'LIVE-OPS', 'name' => 'Live wave', 'status' => 'ACTIVE',
        'approved_branch_codes' => json_encode(['SUN4']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $deadWave = DB::table('ops_rme_legacy_migration_waves')->insertGetId([
        'code' => 'DEAD-OPS', 'name' => 'Cancelled wave', 'status' => 'CANCELLED',
        'approved_branch_codes' => json_encode(['SUN4']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $liveOperator = DB::table('ops_rme_legacy_wave_operators')->insertGetId([
        'wave_id' => $liveWave, 'user_id' => $user->id, 'branch_id' => $sunuId,
        'branch_code' => 'SUN4', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $deadOperator = DB::table('ops_rme_legacy_wave_operators')->insertGetId([
        'wave_id' => $deadWave, 'user_id' => $user->id, 'branch_id' => $sunuId,
        'branch_code' => 'SUN4', 'created_at' => now(), 'updated_at' => now(),
    ]);

    runSunuBranchCodeMigration();

    expect(DB::table('ops_rme_legacy_wave_operators')->where('id', $liveOperator)->value('branch_code'))->toBe('SPN4')
        ->and(DB::table('ops_rme_legacy_wave_operators')->where('id', $deadOperator)->value('branch_code'))->toBe('SUN4');
});

it('does not rewrite the audit trail', function () {
    Branch::query()->create(['code' => 'SUN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true]);

    $auditId = DB::table('sys_audit_logs')->insertGetId([
        'entity_type' => 'legacy_rme_import',
        'entity_id' => 1,
        'action' => 'LEGACY_RME_IMPORT_CREATED',
        'new_values' => json_encode(['branch_code' => 'SUN4']),
        'performed_at' => now(),
        'created_at' => now(),
    ]);

    runSunuBranchCodeMigration();

    // The log states what was true when it was written. It stays true.
    expect(DB::table('sys_audit_logs')->where('id', $auditId)->value('new_values'))
        ->toContain('SUN4');
});
