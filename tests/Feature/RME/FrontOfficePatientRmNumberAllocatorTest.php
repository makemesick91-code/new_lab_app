<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §1) — the automatic
| Nomor RM allocator (foundation, dormant)
|--------------------------------------------------------------------------
|
| Owner decision 2026-11-17 "Auto, read-only": the system issues the next
| DG-{KODE}-{TAHUN}-{NNNN} number per branch and clinic year, never reuses a
| number in ANY spelling (historical alias codes TKM1/SUN4, any zero padding,
| soft-deleted patients, merge aliases), widens past 9999 instead of stopping
| registration, refuses MAIN, and only runs inside the registration
| transaction (the branch-year row is locked FOR UPDATE).
|
| Nothing in the registration path calls the allocator yet (PR-2 activates it).
|
| Selected by the existing `FrontOffice` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `fprn` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Patient\Exceptions\PatientRmNumberUnavailableException;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientRmSequence;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use App\Modules\Patient\Services\PatientRmNumberAllocator;
use App\Modules\PatientMerge\Interfaces\PatientRmAliasRepositoryInterface;
use App\Modules\PatientMerge\Models\PatientRmAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

const FPRN_YEAR = 2031;

function fprnBranch(string $code): Branch
{
    return Branch::factory()->create(['code' => $code, 'is_active' => true, 'is_rme_enabled' => true]);
}

function fprnPatient(Branch $branch, string $number, bool $trashed = false): Patient
{
    $patient = Patient::factory()->create(['branch_id' => $branch->id, 'medical_record_number' => $number]);

    if ($trashed) {
        $patient->delete();
    }

    return $patient;
}

function fprnCounter(Branch $branch, int $last): void
{
    PatientRmSequence::query()->create([
        'branch_id' => $branch->id,
        'year' => FPRN_YEAR,
        'last_number' => $last,
        'seed_source' => PatientRmSequence::SOURCE_COMMAND,
        'seeded_at' => now(),
    ]);
}

function fprnAllocate(Branch $branch, int $year = FPRN_YEAR): string
{
    return DB::transaction(fn (): string => app(PatientRmNumberAllocator::class)->allocate($branch, $year));
}

it('AL-01 issues 0001 for an empty branch-year, then 0002, padded to four digits', function () {
    $branch = fprnBranch('FPA1');

    expect(fprnAllocate($branch))->toBe('DG-FPA1-2031-0001')
        ->and(fprnAllocate($branch))->toBe('DG-FPA1-2031-0002')
        ->and((int) PatientRmSequence::query()->where('branch_id', $branch->id)->value('last_number'))->toBe(2);
});

it('AL-02 continues after the highest existing number in any padding on first use', function () {
    $branch = fprnBranch('FPA1');
    fprnPatient($branch, 'DG-FPA1-2031-7');
    fprnPatient($branch, 'DG-FPA1-2031-0012');
    fprnPatient($branch, 'DG-FPA1-2031-REG-4');
    fprnPatient($branch, 'DG-FPA1-2030-0999');

    expect(fprnAllocate($branch))->toBe('DG-FPA1-2031-0013')
        ->and(PatientRmSequence::query()->where('branch_id', $branch->id)->value('seed_source'))
        ->toBe(PatientRmSequence::SOURCE_LAZY);
});

it('AL-03 never reissues a number taken under a historical alias code or another padding', function () {
    $telkomas = fprnBranch('TLK1');
    fprnCounter($telkomas, 6);
    fprnPatient($telkomas, 'DG-TKM1-2031-0007');
    fprnPatient($telkomas, 'DG-TLK1-2031-8');
    fprnPatient($telkomas, 'DG-TLK1-2031-000009');

    expect(fprnAllocate($telkomas))->toBe('DG-TLK1-2031-0010');
});

it('AL-04 treats a soft-deleted patient and an active merge alias as holding their numbers', function () {
    $branch = fprnBranch('FPA1');
    fprnCounter($branch, 0);
    fprnPatient($branch, 'DG-FPA1-2031-0001', trashed: true);

    $aliases = Mockery::mock(PatientRmAliasRepositoryInterface::class);
    $aliases->shouldReceive('findActiveByNumbers')->andReturnUsing(
        fn (array $numbers) => in_array('DG-FPA1-2031-0002', $numbers, true) ? new PatientRmAlias : null,
    );
    app()->instance(PatientRmAliasRepositoryInterface::class, $aliases);

    expect(fprnAllocate($branch))->toBe('DG-FPA1-2031-0003');
});

it('AL-05 refuses MAIN, which is never an RME branch', function () {
    $main = fprnBranch(Branch::MAIN_CODE);

    expect(fn () => fprnAllocate($main))->toThrow(PatientRmNumberUnavailableException::class);
    expect(PatientRmSequence::query()->count())->toBe(0);
});

it('AL-06 refuses to allocate outside the registration transaction', function () {
    $branch = fprnBranch('FPA1');

    // RefreshDatabase wraps the test in a transaction on the default connection.
    // A second connection to the same database has no open transaction, which is
    // exactly what a caller outside the registration transaction would present.
    $default = config('database.default');
    config()->set('database.connections.fprn_plain', config('database.connections.'.$default));
    config()->set('database.default', 'fprn_plain');
    DB::purge('fprn_plain');

    try {
        expect(DB::transactionLevel())->toBe(0);
        expect(fn () => app(PatientRmNumberAllocator::class)->allocate($branch, FPRN_YEAR))
            ->toThrow(LogicException::class);
    } finally {
        config()->set('database.default', $default);
        DB::purge('fprn_plain');
    }

    expect(PatientRmSequence::query()->count())->toBe(0);
});

it('AL-07 widens past 9999 instead of stopping registration, and the number still parses to its branch', function () {
    $branch = fprnBranch('FPA1');
    fprnCounter($branch, 9999);

    $number = fprnAllocate($branch);
    $numbers = app(PatientMedicalRecordNumberService::class);

    expect($number)->toBe('DG-FPA1-2031-10000')
        ->and($numbers->parse($number)?->toString())->toBe($number)
        ->and($numbers->branchCodeFrom($number))->toBe('FPA1');
});

it('AL-08 refuses cleanly once the sequence would need nine digits', function () {
    $branch = fprnBranch('FPA1');
    fprnCounter($branch, 99_999_999);

    expect(fn () => fprnAllocate($branch))->toThrow(PatientRmNumberUnavailableException::class);
    expect((int) PatientRmSequence::query()->where('branch_id', $branch->id)->value('last_number'))->toBe(99_999_999);
});

it('AL-09 warns from 9000 without writing any number into the log', function () {
    $branch = fprnBranch('FPA1');
    fprnCounter($branch, 8999);

    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
        return ! str_contains($message.json_encode($context), 'DG-')
            && ! str_contains(json_encode($context), '9000');
    });

    expect(fprnAllocate($branch))->toBe('DG-FPA1-2031-9000');
});

it('AL-10 spells a number in every alias code and padding width, and reads only numeric sequences', function () {
    $numbers = app(PatientMedicalRecordNumberService::class);
    $spellings = $numbers->numericSpellings('TLK1', 2031, 7);

    expect($spellings)->toContain('DG-TLK1-2031-7', 'DG-TLK1-2031-0007', 'DG-TLK1-2031-00000007', 'DG-TKM1-2031-0007', 'DG-TKM1-2031-7')
        ->and($numbers->formatSequence(7))->toBe('0007')
        ->and($numbers->formatSequence(12345))->toBe('12345')
        ->and($numbers->numericSequenceOf('DG-TLK1-2031-0007'))->toBe(7)
        ->and($numbers->numericSequenceOf('DG-TLK1-2031-7A'))->toBeNull()
        ->and($numbers->numericSequenceOf('MRN-ABC'))->toBeNull()
        ->and(fn () => $numbers->formatSequence(0))->toThrow(InvalidArgumentException::class);
});

it('AL-11 never touches an existing patient number while allocating', function () {
    $branch = fprnBranch('FPA1');
    $patient = fprnPatient($branch, 'DG-FPA1-2031-0004');
    $before = $patient->fresh()->getAttributes();

    fprnAllocate($branch);

    expect($patient->fresh()->getAttributes())->toBe($before);
});

it('AL-12 seeds a missing branch-year from a number issued under the historical alias code', function () {
    // No counter row: the first allocation seeds it lazily through the real
    // BranchCodeAlias authority (TKM1 is TLK1's historical code), not a mock.
    $telkomas = fprnBranch('TLK1');
    fprnPatient($telkomas, 'DG-TKM1-2031-0007');

    expect(PatientRmSequence::query()->count())->toBe(0)
        ->and(fprnAllocate($telkomas))->toBe('DG-TLK1-2031-0008')
        ->and(PatientRmSequence::query()->where('branch_id', $telkomas->id)->value('seed_source'))
        ->toBe(PatientRmSequence::SOURCE_LAZY);
});

it('AL-13 skips a number held only by an active merge alias, through the real alias lookup', function () {
    $telkomas = fprnBranch('TLK1');
    fprnCounter($telkomas, 0);

    // Neither number is on any patient row: only the real (unmocked) merge-alias
    // repository can hold them — one under the historical code, one unpadded and
    // lower-case (the alias lookup is case-insensitive).
    $source = fprnPatient($telkomas, 'DG-TLK1-2030-0001');
    $canonical = fprnPatient($telkomas, 'DG-TLK1-2030-0002');
    $user = User::factory()->create();
    $caseId = DB::table('trx_patient_merge_cases')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'case_number' => 'FPRN-MERGE-1',
        'patient_a_id' => $source->id,
        'patient_b_id' => $canonical->id,
        'requested_by' => $user->id,
        'requested_at' => now(),
        'request_reason' => 'Allocator alias fixture',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach (['DG-TKM1-2031-0001', 'dg-tlk1-2031-2'] as $alias) {
        PatientRmAlias::query()->create([
            'alias_medical_record_number' => $alias,
            'source_patient_id' => $source->id,
            'canonical_patient_id' => $canonical->id,
            'merge_case_id' => $caseId,
            'merged_at' => now(),
        ]);
    }

    expect(fprnAllocate($telkomas))->toBe('DG-TLK1-2031-0003');
});

it('AL-14 ignores an existing number too wide for the allocator when it seeds, instead of exhausting the branch-year', function () {
    // A phone number typed as the sequence can never collide with an issuable
    // (at most eight-digit) number, so it must not lift the counter out of reach.
    $branch = fprnBranch('FPA1');
    fprnPatient($branch, 'DG-FPA1-2031-081234567890');
    fprnPatient($branch, 'DG-FPA1-2031-123456789');
    fprnPatient($branch, 'DG-FPA1-2031-0005');

    expect(fprnAllocate($branch))->toBe('DG-FPA1-2031-0006')
        ->and((int) PatientRmSequence::query()->where('branch_id', $branch->id)->value('last_number'))->toBe(6);

    $empty = fprnBranch('FPA2');
    fprnPatient($empty, 'DG-FPA2-2031-081234567890');

    expect(fprnAllocate($empty))->toBe('DG-FPA2-2031-0001');
});

it('AL-15 refuses a branch code whose composed number would not parse back to the branch', function () {
    $branch = fprnBranch('BR-01');

    try {
        fprnAllocate($branch);
        $this->fail('A number that cannot be parsed back must never be issued.');
    } catch (PatientRmNumberUnavailableException $e) {
        expect($e->getMessage())->not->toContain('DG-')
            ->and($e->getMessage())->not->toContain('BR-01');
    }

    expect(PatientRmSequence::query()->count())->toBe(0);
});
