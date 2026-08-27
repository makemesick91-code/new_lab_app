<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-LEGACY-IMPORT-HUB-1 — the daily ceiling, exactly as contracted.
|--------------------------------------------------------------------------
|
| 100 ACCEPTED records, per BRANCH, per CLINICAL DAY, per IMPORT TYPE.
|
| Every assertion below pins one word of that sentence. The boundary tests use
| the real bound repository and a real transaction, so they exercise the same
| code path production takes rather than a stub of it.
*/

use App\Modules\LegacyImport\Models\LegacyImportDailyQuota;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| The contract itself
|--------------------------------------------------------------------------
*/

it('declares a ceiling of 100 for every import type', function () {
    // The canonical number, read through the service rather than the config, so
    // a clamp or a rename cannot pass this silently.
    foreach (LegacyImportType::all() as $type) {
        expect(lihQuota()->limitFor($type))->toBe(100);
    }
});

it('knows exactly three import types', function () {
    expect(LegacyImportType::all())->toBe([
        'legacy_patient',
        'legacy_rme',
        'legacy_odontogram',
    ]);
});

/*
|--------------------------------------------------------------------------
| The boundary
|--------------------------------------------------------------------------
*/

it('admits the first record of the day', function () {
    $branch = lihBranch();

    DB::transaction(fn () => lihQuota()->reserve(LegacyImportType::LEGACY_RME, $branch->id));

    expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_RME, $branch->id))->toBe(1);
    expect(lihQuota()->remainingToday(LegacyImportType::LEGACY_RME, $branch->id))->toBe(99);
});

it('admits the 99th and the 100th record and refuses the 101st', function () {
    $branch = lihBranch();
    $type = LegacyImportType::LEGACY_RME;

    lihConsume($type, $branch->id, 98);
    expect(lihQuota()->consumedToday($type, $branch->id))->toBe(98);

    // 99th
    DB::transaction(fn () => lihQuota()->reserve($type, $branch->id));
    expect(lihQuota()->consumedToday($type, $branch->id))->toBe(99);

    // 100th — the last permitted one.
    DB::transaction(fn () => lihQuota()->reserve($type, $branch->id));
    expect(lihQuota()->consumedToday($type, $branch->id))->toBe(100);

    // 101st — refused, and the counter does not move.
    expect(fn () => DB::transaction(fn () => lihQuota()->reserve($type, $branch->id)))
        ->toThrow(ValidationException::class);

    expect(lihQuota()->consumedToday($type, $branch->id))->toBe(100);
    expect(lihQuota()->remainingToday($type, $branch->id))->toBe(0);
});

it('names the remaining capacity when it refuses', function () {
    $branch = lihBranch();
    $type = LegacyImportType::LEGACY_ODONTOGRAM;

    lihLimit($type, 2);
    lihConsume($type, $branch->id, 2);

    try {
        DB::transaction(fn () => lihQuota()->reserve($type, $branch->id));
        $this->fail('The ceiling should have refused.');
    } catch (ValidationException $exception) {
        $message = $exception->validator->errors()->first('legacy_import_quota');

        // An operator has to be able to act on the refusal, which means knowing
        // what was used, what the ceiling is, and that tomorrow is the remedy.
        expect($message)->toContain('2 dari 2');
        expect($message)->toContain('sisa 0');
        expect($message)->toContain('hari berikutnya');
    }
});

/*
|--------------------------------------------------------------------------
| Per branch
|--------------------------------------------------------------------------
*/

it('gives every branch its own ceiling', function () {
    $a = lihBranch('TKM1', 'Cabang Telkomas');
    $b = lihBranch('LDK2', 'Cabang Landak');
    $type = LegacyImportType::LEGACY_RME;

    lihLimit($type, 3);
    lihConsume($type, $a->id, 3);

    // Branch A is full.
    expect(fn () => DB::transaction(fn () => lihQuota()->reserve($type, $a->id)))
        ->toThrow(ValidationException::class);

    // Branch B has not been touched by any of it.
    expect(lihQuota()->consumedToday($type, $b->id))->toBe(0);
    expect(lihQuota()->remainingToday($type, $b->id))->toBe(3);

    DB::transaction(fn () => lihQuota()->reserve($type, $b->id));
    expect(lihQuota()->consumedToday($type, $b->id))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Per import type
|--------------------------------------------------------------------------
*/

it('counts the three import types separately', function () {
    $branch = lihBranch();

    foreach (LegacyImportType::all() as $type) {
        lihLimit($type, 2);
    }

    lihConsume(LegacyImportType::LEGACY_RME, $branch->id, 2);

    // RME is full for this branch today...
    expect(fn () => DB::transaction(fn () => lihQuota()->reserve(LegacyImportType::LEGACY_RME, $branch->id)))
        ->toThrow(ValidationException::class);

    // ...and neither sibling has lost a single slot.
    expect(lihQuota()->remainingToday(LegacyImportType::LEGACY_PATIENT, $branch->id))->toBe(2);
    expect(lihQuota()->remainingToday(LegacyImportType::LEGACY_ODONTOGRAM, $branch->id))->toBe(2);

    DB::transaction(fn () => lihQuota()->reserve(LegacyImportType::LEGACY_PATIENT, $branch->id));
    DB::transaction(fn () => lihQuota()->reserve(LegacyImportType::LEGACY_ODONTOGRAM, $branch->id));

    expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_PATIENT, $branch->id))->toBe(1);
    expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_ODONTOGRAM, $branch->id))->toBe(1);
    expect(lihQuota()->consumedToday(LegacyImportType::LEGACY_RME, $branch->id))->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Per clinical day
|--------------------------------------------------------------------------
*/

it('resets on the next clinical day', function () {
    $branch = lihBranch();
    $type = LegacyImportType::LEGACY_RME;
    $clock = app(ClinicalClock::class);

    // Fill today, in the clinical calendar's own wall clock.
    $today = $clock->today();
    $this->travelTo($today->setTime(10, 0)->setTimezone('UTC'));

    lihLimit($type, 2);
    lihConsume($type, $branch->id, 2);

    expect(fn () => DB::transaction(fn () => lihQuota()->reserve($type, $branch->id)))
        ->toThrow(ValidationException::class);

    // Tomorrow is a different bucket, so the ceiling is whole again.
    $this->travelTo($today->addDay()->setTime(10, 0)->setTimezone('UTC'));

    expect(lihQuota()->consumedToday($type, $branch->id))->toBe(0);
    DB::transaction(fn () => lihQuota()->reserve($type, $branch->id));
    expect(lihQuota()->consumedToday($type, $branch->id))->toBe(1);

    $this->travelBack();
});

it('rolls the day over on the clinical calendar, not on UTC midnight', function () {
    $branch = lihBranch();
    $type = LegacyImportType::LEGACY_RME;
    $clock = app(ClinicalClock::class);

    // 23:59:59 clinical time is still TODAY. The same instant in UTC has already
    // been tomorrow for hours, which is exactly the bug delegating to
    // ClinicalClock prevents.
    $lastMoment = $clock->today()->setTime(23, 59, 59);
    $this->travelTo($lastMoment->setTimezone('UTC'));

    $dayBefore = lihQuota()->today()->toDateString();
    DB::transaction(fn () => lihQuota()->reserve($type, $branch->id));

    // One second later is the next clinical day.
    $this->travelTo($lastMoment->addSecond()->setTimezone('UTC'));

    $dayAfter = lihQuota()->today()->toDateString();

    expect($dayAfter)->not->toBe($dayBefore);
    expect(lihQuota()->consumedToday($type, $branch->id))->toBe(0);

    $this->travelBack();
});

it('charges the bucket to the clinical date, not the UTC date', function () {
    $branch = lihBranch();
    $type = LegacyImportType::LEGACY_RME;
    $clock = app(ClinicalClock::class);

    $this->travelTo($clock->today()->setTime(7, 30)->setTimezone('UTC'));

    DB::transaction(fn () => lihQuota()->reserve($type, $branch->id));

    $bucket = LegacyImportDailyQuota::query()
        ->where('import_type', $type)
        ->where('branch_id', $branch->id)
        ->firstOrFail();

    expect($bucket->quota_date->toDateString())->toBe($clock->todayString());

    $this->travelBack();
});

/*
|--------------------------------------------------------------------------
| What consumes, and what does not
|--------------------------------------------------------------------------
*/

it('releases the slot when the surrounding transaction rolls back', function () {
    $branch = lihBranch();
    $type = LegacyImportType::LEGACY_RME;

    // A record that is written and then discarded — a failed insert, a later
    // validation error, anything that aborts the transaction — must not leave a
    // slot consumed behind it.
    try {
        DB::transaction(function () use ($type, $branch): void {
            lihQuota()->reserve($type, $branch->id);

            throw new RuntimeException('the record could not be written');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(lihQuota()->consumedToday($type, $branch->id))->toBe(0);
});

it('refuses a batch whole rather than admitting the part that fits', function () {
    $branch = lihBranch();
    $type = LegacyImportType::LEGACY_PATIENT;

    lihLimit($type, 10);
    lihConsume($type, $branch->id, 8);

    // Three rows into two remaining slots. Admitting two of them would leave the
    // operator with a partially imported file and no way to tell which rows
    // landed.
    expect(fn () => DB::transaction(
        fn () => lihQuota()->reserveMany($type, [$branch->id => 3]),
    ))->toThrow(ValidationException::class);

    expect(lihQuota()->consumedToday($type, $branch->id))->toBe(8);

    // Exactly two fits.
    DB::transaction(fn () => lihQuota()->reserveMany($type, [$branch->id => 2]));
    expect(lihQuota()->consumedToday($type, $branch->id))->toBe(10);
});

it('refuses a multi-branch batch when any one of its branches is over', function () {
    $a = lihBranch('TKM1', 'Cabang Telkomas');
    $b = lihBranch('LDK2', 'Cabang Landak');
    $type = LegacyImportType::LEGACY_PATIENT;

    lihLimit($type, 5);
    lihConsume($type, $b->id, 5);

    expect(fn () => DB::transaction(
        fn () => lihQuota()->reserveMany($type, [$a->id => 1, $b->id => 1]),
    ))->toThrow(ValidationException::class);

    // The branch that had room is NOT charged: the check runs for every branch
    // before any of them is written.
    expect(lihQuota()->consumedToday($type, $a->id))->toBe(0);
    expect(lihQuota()->consumedToday($type, $b->id))->toBe(5);
});

/*
|--------------------------------------------------------------------------
| Null is not zero
|--------------------------------------------------------------------------
*/

it('declines to limit when no ceiling is declared', function () {
    $branch = lihBranch();
    $type = LegacyImportType::LEGACY_RME;

    lihLimit($type, null);

    expect(lihQuota()->limitFor($type))->toBeNull();
    expect(lihQuota()->remainingToday($type, $branch->id))->toBeNull();

    // Far beyond any ceiling, and never refused.
    lihConsume($type, $branch->id, 250);

    expect(lihQuota()->preview($type, $branch->id))->toBeNull();
});

it('admits nothing when the ceiling is zero', function () {
    $branch = lihBranch();
    $type = LegacyImportType::LEGACY_RME;

    lihLimit($type, 0);

    expect(lihQuota()->limitFor($type))->toBe(0);
    expect(fn () => DB::transaction(fn () => lihQuota()->reserve($type, $branch->id)))
        ->toThrow(ValidationException::class);
});

it('clamps a ceiling declared above the maximum, and says so', function () {
    $type = LegacyImportType::LEGACY_RME;

    lihLimit($type, 1_000_000);

    expect(lihQuota()->limitFor($type))->toBe(lihQuota()->maxDeclarable());
    expect(lihQuota()->limitIsClamped($type))->toBeTrue();

    lihLimit($type, 100);
    expect(lihQuota()->limitIsClamped($type))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The vocabulary is closed
|--------------------------------------------------------------------------
*/

it('refuses an unknown import type rather than opening an uncounted bucket', function () {
    $branch = lihBranch();

    expect(fn () => DB::transaction(fn () => lihQuota()->reserve('legacy_something_else', $branch->id)))
        ->toThrow(InvalidArgumentException::class);

    expect(LegacyImportDailyQuota::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The lock
|--------------------------------------------------------------------------
*/

it('takes a row lock before deciding', function () {
    // WHY THIS IS A SOURCE ASSERTION. The suite runs on SQLite, where
    // `lockForUpdate()` compiles to nothing, so no captured query could show the
    // lock. The behaviour it guards is proven against real PostgreSQL by the
    // concurrency test in this directory, which skips on any other driver.
    //
    // What this pins is that the read the decision is taken from is the LOCKING
    // one. Deleting `lockForUpdate()` would leave every other test in this file
    // green while making the ceiling raceable.
    $source = file_get_contents(
        base_path('app/Modules/LegacyImport/Repositories/LegacyImportDailyQuotaRepository.php'),
    );

    expect($source)->toContain('lockForUpdate()');
    expect($source)->toContain('insertOrIgnore');
    // Deterministic acquisition order is what stops two requests that need the
    // same pair of branches from deadlocking.
    expect($source)->toContain("orderBy('branch_id')");
});

it('creates the bucket before locking it', function () {
    $branch = lihBranch();
    $type = LegacyImportType::LEGACY_RME;

    expect(LegacyImportDailyQuota::query()->count())->toBe(0);

    DB::transaction(fn () => lihQuota()->reserve($type, $branch->id));

    // Exactly one bucket, not one per reservation: the row is the lock target
    // and it has to be stable across requests.
    lihConsume($type, $branch->id, 3);

    expect(LegacyImportDailyQuota::query()->count())->toBe(1);
    expect(lihQuota()->consumedToday($type, $branch->id))->toBe(4);
});
