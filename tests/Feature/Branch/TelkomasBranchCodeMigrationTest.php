<?php

declare(strict_types=1);

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Facades\DB;

/**
 * REVISION-TELKOMAS-BRANCH-CODE-TKM1-TO-TLK1-1 — the data migration.
 *
 * The migration has already run by the time a test boots (RefreshDatabase), so
 * these tests re-run the SAME migration file against a table deliberately put
 * back into the pre-migration state. That exercises the real artifact, not a
 * re-implementation of it, and it doubles as the idempotency proof: the file is
 * executed a second time on every one of these tests.
 */
function runTelkomasBranchCodeMigration(): void
{
    $migration = require database_path('migrations/2026_08_31_100001_revise_telkomas_branch_code_tkm1_to_tlk1.php');

    $migration->up();
}

it('renames the branch code in place, keeping the SAME branch identity', function () {
    $branch = Branch::query()->create([
        'code' => 'TKM1',
        'name' => 'Cabang Telkomas',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $branchCountBefore = Branch::withTrashed()->count();

    runTelkomasBranchCodeMigration();

    $branch->refresh();

    expect($branch->code)->toBe('TLK1')
        ->and($branch->name)->toBe('Cabang Telkomas')
        ->and($branch->is_active)->toBeTrue()
        ->and($branch->is_rme_enabled)->toBeTrue()
        // The primary key is the isolation boundary — it must not move.
        ->and(Branch::withTrashed()->count())->toBe($branchCountBefore)
        ->and(Branch::withTrashed()->where('code', 'TKM1')->count())->toBe(0);
});

it('migrates the branch segment of patient medical record numbers without touching the patient', function () {
    $branch = Branch::query()->create([
        'code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true,
    ]);

    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-TKM1-2024-9985',
    ]);
    $originalName = $patient->name;
    $patientCountBefore = Patient::withTrashed()->count();

    runTelkomasBranchCodeMigration();

    $patient->refresh();

    expect($patient->medical_record_number)->toBe('DG-TLK1-2024-9985')
        ->and($patient->name)->toBe($originalName)
        ->and((int) $patient->branch_id)->toBe((int) $branch->id)
        ->and(Patient::withTrashed()->count())->toBe($patientCountBefore);
});

it('migrates soft-deleted patients too, because the unique index spans them', function () {
    Branch::query()->create(['code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true]);

    $patient = Patient::factory()->create(['medical_record_number' => 'DG-TKM1-2020-0007']);
    $patient->delete();

    runTelkomasBranchCodeMigration();

    expect(Patient::withTrashed()->find($patient->id)->medical_record_number)->toBe('DG-TLK1-2020-0007');
});

it('FAILS CLOSED on a medical record number collision — nothing is overwritten or deleted', function () {
    Branch::query()->create(['code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true]);

    $historical = Patient::factory()->create(['medical_record_number' => 'DG-TKM1-2024-9985']);
    $canonical = Patient::factory()->create(['medical_record_number' => 'DG-TLK1-2024-9985']);
    $patientCountBefore = Patient::withTrashed()->count();

    expect(fn () => runTelkomasBranchCodeMigration())
        ->toThrow(RuntimeException::class, 'already held by patient');

    // Both patients survive, untouched, and no renumbering was invented.
    expect(Patient::withTrashed()->find($historical->id)->medical_record_number)->toBe('DG-TKM1-2024-9985')
        ->and(Patient::withTrashed()->find($canonical->id)->medical_record_number)->toBe('DG-TLK1-2024-9985')
        ->and(Patient::withTrashed()->count())->toBe($patientCountBefore);
});

it('FAILS CLOSED when the canonical branch code is already held by a DIFFERENT branch', function () {
    $historical = Branch::query()->create(['code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true]);
    $other = Branch::query()->create(['code' => 'TLK1', 'name' => 'Cabang Lain', 'is_active' => true, 'is_rme_enabled' => true]);

    expect(fn () => runTelkomasBranchCodeMigration())
        ->toThrow(RuntimeException::class, 'already held by branch');

    // Neither branch code was stolen.
    expect(Branch::withTrashed()->find($historical->id)->code)->toBe('TKM1')
        ->and(Branch::withTrashed()->find($other->id)->code)->toBe('TLK1');
});

it('is idempotent — a second run changes nothing', function () {
    $branch = Branch::query()->create(['code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true]);
    $patient = Patient::factory()->create(['branch_id' => $branch->id, 'medical_record_number' => 'DG-TKM1-2024-9985']);

    runTelkomasBranchCodeMigration();
    $afterFirst = [
        'code' => $branch->fresh()->code,
        'rm' => $patient->fresh()->medical_record_number,
        'branches' => Branch::withTrashed()->count(),
        'patients' => Patient::withTrashed()->count(),
    ];

    runTelkomasBranchCodeMigration();
    runTelkomasBranchCodeMigration();

    expect($branch->fresh()->code)->toBe($afterFirst['code'])
        ->and($patient->fresh()->medical_record_number)->toBe($afterFirst['rm'])
        ->and(Branch::withTrashed()->count())->toBe($afterFirst['branches'])
        ->and(Patient::withTrashed()->count())->toBe($afterFirst['patients']);
});

it('runs cleanly on a database that has nothing to migrate', function () {
    $before = [Branch::withTrashed()->count(), Patient::withTrashed()->count()];

    runTelkomasBranchCodeMigration();

    expect([Branch::withTrashed()->count(), Patient::withTrashed()->count()])->toBe($before);
});

it('leaves a medical record number belonging to another branch alone', function () {
    Branch::query()->create(['code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true]);

    // The branch is LDK2; only the manual segment happens to read TKM1.
    $other = Patient::factory()->create(['medical_record_number' => 'DG-LDK2-2024-TKM1']);

    runTelkomasBranchCodeMigration();

    expect($other->fresh()->medical_record_number)->toBe('DG-LDK2-2024-TKM1');
});

it('PRESERVES issued visit numbers — they are historical transactional identifiers', function () {
    $branch = Branch::query()->create(['code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true]);
    $patient = Patient::factory()->create(['branch_id' => $branch->id, 'medical_record_number' => 'DG-TKM1-2024-9985']);

    $visit = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'visit_number' => 'VIS-TKM1-20260820-001',
        'status' => ClinicVisit::STATUS_COMPLETED,
    ]);

    runTelkomasBranchCodeMigration();

    // The paper already handed to the patient still matches the record.
    expect($visit->fresh()->visit_number)->toBe('VIS-TKM1-20260820-001');
});

it('creates NO clinical or financial side effects', function () {
    $branch = Branch::query()->create(['code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true]);
    Patient::factory()->create(['branch_id' => $branch->id, 'medical_record_number' => 'DG-TKM1-2024-9985']);

    $before = [
        'visits' => DB::table('trx_clinic_visits')->count(),
        'medical_records' => DB::table('trx_medical_records')->count(),
        'odontograms' => DB::table('trx_odontograms')->count(),
        'invoices' => DB::table('trx_rme_invoices')->count(),
        'payments' => DB::table('trx_rme_payments')->count(),
        'legacy_records' => DB::table('trx_rme_legacy_records')->count(),
        'audit' => DB::table('sys_audit_logs')->count(),
    ];

    runTelkomasBranchCodeMigration();

    expect([
        'visits' => DB::table('trx_clinic_visits')->count(),
        'medical_records' => DB::table('trx_medical_records')->count(),
        'odontograms' => DB::table('trx_odontograms')->count(),
        'invoices' => DB::table('trx_rme_invoices')->count(),
        'payments' => DB::table('trx_rme_payments')->count(),
        'legacy_records' => DB::table('trx_rme_legacy_records')->count(),
        'audit' => DB::table('sys_audit_logs')->count(),
    ])->toBe($before);
});

it('canonicalizes LIVE rollout rows and leaves a terminal wave untouched', function () {
    Branch::query()->create(['code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true]);
    $telkomasId = (int) Branch::query()->where('code', 'TKM1')->value('id');

    $liveWave = DB::table('ops_rme_legacy_migration_waves')->insertGetId([
        'code' => 'LIVE-WAVE', 'name' => 'Live wave', 'status' => 'ACTIVE',
        'approved_branch_codes' => json_encode(['TKM1', 'LDK2']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $deadWave = DB::table('ops_rme_legacy_migration_waves')->insertGetId([
        'code' => 'DEAD-WAVE', 'name' => 'Cancelled wave', 'status' => 'CANCELLED',
        'approved_branch_codes' => json_encode(['TKM1']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $liveRow = DB::table('ops_rme_legacy_wave_branches')->insertGetId([
        'wave_id' => $liveWave, 'branch_code' => 'TKM1', 'branch_id' => $telkomasId,
        'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Production holds exactly this shape: a DRAINING branch row on a CANCELLED
    // wave. Its status is non-terminal, but the wave that owns it is not live.
    $deadRow = DB::table('ops_rme_legacy_wave_branches')->insertGetId([
        'wave_id' => $deadWave, 'branch_code' => 'TKM1', 'branch_id' => $telkomasId,
        'status' => 'DRAINING', 'created_at' => now(), 'updated_at' => now(),
    ]);

    runTelkomasBranchCodeMigration();

    // The stored value is decoded rather than string-compared: PostgreSQL's
    // jsonb re-normalizes whitespace, so a raw string assertion would pass on
    // SQLite and fail on the database CI and production actually run.
    $approved = fn (int $id): array => (array) json_decode(
        (string) DB::table('ops_rme_legacy_migration_waves')->where('id', $id)->value('approved_branch_codes'),
        true,
    );

    expect(DB::table('ops_rme_legacy_wave_branches')->where('id', $liveRow)->value('branch_code'))->toBe('TLK1')
        ->and(DB::table('ops_rme_legacy_wave_branches')->where('id', $deadRow)->value('branch_code'))->toBe('TKM1')
        ->and($approved($liveWave))->toBe(['TLK1', 'LDK2'])
        // The cancelled wave's approval record states what was approved, then.
        ->and($approved($deadWave))->toBe(['TKM1']);
});

it('does not rewrite the audit trail', function () {
    Branch::query()->create(['code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true]);

    $auditId = DB::table('sys_audit_logs')->insertGetId([
        'entity_type' => 'legacy_rme_import',
        'entity_id' => 1,
        'action' => 'LEGACY_RME_IMPORT_CREATED',
        'new_values' => json_encode(['branch_code' => 'TKM1']),
        'performed_at' => now(),
        'created_at' => now(),
    ]);

    runTelkomasBranchCodeMigration();

    // The log states what was true when it was written. It stays true.
    expect(DB::table('sys_audit_logs')->where('id', $auditId)->value('new_values'))
        ->toContain('TKM1');
});
