<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — the lock itself.
|--------------------------------------------------------------------------
|
| The property under test: for Kasir and Admin Klinik, the FIRST branch of a
| clinical day is free and every later, different branch is refused until a
| Super Admin approves it.
|
| Every selection here goes through the real UserOnlineContextService — the same
| entry point the HTTP endpoint calls. A test that wrote `trx_daily_branch_contexts`
| directly would prove the table exists and nothing about the guard.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\RmeOnlineContext\Models\DailyBranchContext;
use App\Modules\RmeOnlineContext\Models\UserOnlineContext;
use App\Modules\RmeOnlineContext\Services\DailyBranchContextService;
use App\Support\Clinical\ClinicalClock;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
});

afterEach(function () {
    // Never leak a frozen clock into a sibling suite.
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| First selection is free
|--------------------------------------------------------------------------
*/

it('lets a kasir choose any eligible branch as the first selection of the day', function () {
    $branch = dbcBranch('AAA');
    $user = userInRole('Kasir');

    dbcStart($user, $branch);

    $context = dbcDaily()->currentFor($user);

    expect($context)->not->toBeNull()
        ->and((int) $context->current_branch_id)->toBe((int) $branch->id)
        ->and((int) $context->initial_branch_id)->toBe((int) $branch->id)
        ->and($context->role_context)->toBe(UserOnlineContext::ROLE_KASIR)
        ->and((int) $context->change_count)->toBe(0);
});

it('lets an admin klinik choose any eligible branch as the first selection of the day', function () {
    $branch = dbcBranch('BBB');
    $user = userInRole('Admin Klinik');

    dbcStart($user, $branch, 'admin_clinic');

    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $branch->id);
});

it('records the clinical day on the clinic calendar, not the UTC one', function () {
    // 17:30 UTC on the 29th is already the 30th in Asia/Makassar. A UTC-derived
    // key would file this selection under the wrong clinical day and hand the
    // operator a second free choice.
    Carbon::setTestNow(Carbon::parse('2026-08-29 17:30:00', 'UTC'));

    $branch = dbcBranch('TZ1');
    $user = userInRole('Kasir');

    dbcStart($user, $branch);

    expect(dbcDaily()->currentFor($user)->clinical_date->toDateString())
        ->toBe('2026-08-30')
        ->toBe(app(ClinicalClock::class)->todayString());
});

/*
|--------------------------------------------------------------------------
| Re-selecting the same branch is idempotent
|--------------------------------------------------------------------------
*/

it('allows re-selecting the same branch without creating a second context', function () {
    $branch = dbcBranch('AAA');
    $user = userInRole('Kasir');

    dbcStart($user, $branch);
    dbcStart($user, $branch);
    dbcStart($user, $branch);

    expect(DailyBranchContext::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $branch->id);
});

/*
|--------------------------------------------------------------------------
| A second, different branch is refused
|--------------------------------------------------------------------------
*/

it('refuses a kasir a second different branch on the same clinical day', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    dbcStart($user, $a);

    expect(fn () => dbcStart($user, $b))->toThrow(ValidationException::class);

    // The refusal did not half-apply: the authority is untouched.
    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id);
});

it('refuses an admin klinik a second different branch on the same clinical day', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Admin Klinik');

    dbcStart($user, $a, 'admin_clinic');

    expect(fn () => dbcStart($user, $b, 'admin_clinic'))->toThrow(ValidationException::class);
    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id);
});

it('names the locked branch in the refusal so the operator knows where they stand', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    dbcStart($user, $a);

    try {
        dbcStart($user, $b);
        $this->fail('The second selection should have been refused.');
    } catch (ValidationException $exception) {
        $message = implode(' ', $exception->validator->errors()->all());

        expect($message)->toContain('Cabang AAA')
            ->and($message)->toContain('persetujuan Super Admin');
    }
});

/*
|--------------------------------------------------------------------------
| The multi-role hole
|--------------------------------------------------------------------------
*/

it('locks the DAY, not the role context, so a kasir+admin klinik user cannot work two branches', function () {
    // The bypass this closes: hold both constrained roles, open an admin-clinic
    // context at one branch and a cashier context at another. Two role contexts,
    // one human, one day — which is exactly what the lock forbids.
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');

    $user = userInRole('Kasir');
    $user->assignRole('Admin Klinik');

    dbcStart($user, $a, 'admin_clinic');

    expect(fn () => dbcStart($user, $b, 'kasir'))->toThrow(ValidationException::class);

    expect(DailyBranchContext::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id);
});

/*
|--------------------------------------------------------------------------
| Roles that are deliberately NOT locked
|--------------------------------------------------------------------------
*/

it('leaves perawat free to change branch, exactly as before', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Perawat');

    dbcStart($user, $a, 'perawat');
    dbcStart($user, $b, 'perawat');

    // No daily context is created for an unlocked role, and the switch stands.
    expect(dbcDaily()->currentFor($user))->toBeNull();

    $this->actingAs($user);
    expect(app(BranchContext::class)->forUser($user))->toBe((int) $b->id);
});

it('leaves a doctor free to change branch and room', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');

    $doctorUser = doctorWithOnlineContext($a);
    $doctor = Doctor::query()->where('user_id', $doctorUser->id)->firstOrFail();
    $doctor->branches()->syncWithoutDetaching([(int) $b->id]);

    $room = ClinicRoom::factory()->create([
        'branch_id' => $b->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);

    dbcOnline()->startDoctorSession($doctorUser, (int) $b->id, (int) $room->id);

    expect(dbcDaily()->currentFor($doctorUser))->toBeNull();

    $this->actingAs($doctorUser);
    expect(app(BranchContext::class)->forUser($doctorUser))->toBe((int) $b->id);
});

it('declares exactly kasir and admin klinik as the locked role contexts', function () {
    expect(DailyBranchContextService::LOCKED_ROLE_CONTEXTS)
        ->toEqualCanonicalizing([
            UserOnlineContext::ROLE_ADMIN_CLINIC,
            UserOnlineContext::ROLE_KASIR,
        ]);

    expect(DailyBranchContextService::isLockedRoleContext(UserOnlineContext::ROLE_PERAWAT))->toBeFalse();
    expect(DailyBranchContextService::isLockedRoleContext(UserOnlineContext::ROLE_DOCTOR))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The clinical day boundary
|--------------------------------------------------------------------------
*/

it('holds the lock at 23:59:59 and releases it at 00:00:00 of the next clinical day', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    // 15:00 UTC == 23:00 WITA, comfortably inside the clinical day.
    Carbon::setTestNow(Carbon::parse('2026-08-29 15:00:00', 'UTC'));
    dbcStart($user, $a);

    // 15:59:59 UTC == 23:59:59 WITA — the last second of the same clinical day.
    Carbon::setTestNow(Carbon::parse('2026-08-29 15:59:59', 'UTC'));
    expect(fn () => dbcStart($user, $b))->toThrow(ValidationException::class);

    // 16:00:00 UTC == 00:00:00 WITA — a new clinical day, a new free selection.
    Carbon::setTestNow(Carbon::parse('2026-08-29 16:00:00', 'UTC'));
    dbcStart($user, $b);

    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $b->id);

    // Yesterday's context is still on record — the day did not overwrite it.
    expect(DailyBranchContext::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('locks a calendar day, not a rolling 24 hours from the first selection', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    // 09:00 WITA. A rolling window would keep this locked until 09:00 tomorrow.
    Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
    dbcStart($user, $a);

    // 08:00 WITA the next morning — 23 hours later, but a new calendar day.
    Carbon::setTestNow(Carbon::parse('2026-08-30 00:00:00', 'UTC'));
    dbcStart($user, $b);

    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $b->id);
});

/*
|--------------------------------------------------------------------------
| Eligibility is asserted before the lock is consulted
|--------------------------------------------------------------------------
*/

it('refuses an ineligible branch without consuming the free first selection', function () {
    $inactive = Branch::factory()->create([
        'code' => 'OFF',
        'is_active' => false,
        'is_rme_enabled' => true,
    ]);
    $good = dbcBranch('AAA');
    $user = userInRole('Kasir');

    expect(fn () => dbcStart($user, $inactive))->toThrow(ValidationException::class);

    // The refused attempt must not have committed the day to anything: the
    // operator still gets their one legitimate free choice.
    expect(dbcDaily()->currentFor($user))->toBeNull();

    dbcStart($user, $good);
    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $good->id);
});
