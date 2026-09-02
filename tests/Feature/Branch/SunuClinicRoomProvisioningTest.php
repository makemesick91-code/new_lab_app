<?php

declare(strict_types=1);

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use Database\Seeders\RmeBranchRoomSeeder;
use Database\Seeders\RmeBranchSeeder;
use Illuminate\Validation\ValidationException;

/**
 * REVISION-SUNU-ADD-ROOM-A-B-1
 *
 * Cabang Sunu — canonical branch code SPN4 — operates two rooms, "Ruangan A"
 * and "Ruangan B", and both must be ACTIVE and attached to the EXISTING Sunu
 * branch identity.
 *
 * The interesting half of this sprint is not the two rows; it is everything
 * that must NOT happen while they are created. `mst_clinic_rooms` carries
 * UNIQUE(branch_id, code) and UNIQUE(branch_id, name), and NEITHER index is
 * conditioned on `deleted_at` — a soft-deleted room therefore still occupies
 * its slot. A blind insert against a trashed "Ruangan A" does not create a
 * duplicate; it raises a constraint violation and takes the deploy with it.
 * Provisioning has to look through the soft-delete, and where the room's
 * identity is genuinely ambiguous it has to refuse rather than guess.
 */
/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Seed the canonical RME branch registry so Cabang Sunu exists, exactly as a
 * real deployment reaches this seeder (DatabaseSeeder orders RmeBranchSeeder
 * first).
 */
function sunuRoomBranch(): Branch
{
    (new RmeBranchSeeder)->run();

    return Branch::query()->where('code', BranchCodeAlias::SUNU_CANONICAL)->firstOrFail();
}

function provisionSunuRooms(): void
{
    (new RmeBranchRoomSeeder)->run();
}

/**
 * Assert that room assignment was refused FOR THE STATED REASON.
 *
 * Asserting only `ValidationException` is not enough here and mutation testing
 * proved it: with the branch check deleted, assignment still threw — from
 * doctor resolution further down ("Belum ada dokter online di ruangan ini") —
 * so a type-only assertion passed against code that had lost the branch
 * boundary entirely. The message identifies WHICH guard fired.
 */
function expectRoomRefusal(Closure $act, string $expectedReason): void
{
    try {
        $act();
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->implode(' '))->toContain($expectedReason);

        return;
    }

    throw new RuntimeException("Expected room assignment to be refused with: {$expectedReason}");
}

/**
 * A stable fingerprint of every room that does NOT belong to the given branch,
 * so "we changed nothing else" is asserted against real bytes rather than a
 * row count that a compensating pair of edits could keep constant.
 */
function sunuRoomFingerprintExcludingBranch(int $branchId): string
{
    return ClinicRoom::withTrashed()
        ->where('branch_id', '!=', $branchId)
        ->orderBy('id')
        ->get()
        ->map(fn (ClinicRoom $r) => implode(':', [
            $r->id, $r->branch_id, $r->code, $r->name,
            $r->type, $r->status, $r->deleted_at?->toString() ?? '-',
        ]))
        ->implode('|');
}

/*
|--------------------------------------------------------------------------
| The product decision: two active rooms on the existing Sunu identity
|--------------------------------------------------------------------------
*/

it('gives Cabang Sunu exactly one active Ruangan A', function () {
    $sunu = sunuRoomBranch();

    provisionSunuRooms();

    $rooms = ClinicRoom::withTrashed()
        ->where('branch_id', $sunu->id)
        ->where('name', 'Ruangan A')
        ->get();

    expect($rooms)->toHaveCount(1);
    expect($rooms->first()->status)->toBe(ClinicRoom::STATUS_ACTIVE);
    expect($rooms->first()->deleted_at)->toBeNull();
});

it('gives Cabang Sunu exactly one active Ruangan B', function () {
    $sunu = sunuRoomBranch();

    provisionSunuRooms();

    $rooms = ClinicRoom::withTrashed()
        ->where('branch_id', $sunu->id)
        ->where('name', 'Ruangan B')
        ->get();

    expect($rooms)->toHaveCount(1);
    expect($rooms->first()->status)->toBe(ClinicRoom::STATUS_ACTIVE);
    expect($rooms->first()->deleted_at)->toBeNull();
});

it('attaches both rooms to the existing Sunu branch identity and to no other branch', function () {
    $sunu = sunuRoomBranch();
    $landak = Branch::query()->where('code', 'LDK2')->firstOrFail();

    provisionSunuRooms();

    $rooms = ClinicRoom::query()
        ->whereIn('name', ['Ruangan A', 'Ruangan B'])
        ->where('branch_id', $sunu->id)
        ->get();

    expect($rooms)->toHaveCount(2);
    expect($rooms->pluck('branch_id')->unique()->all())->toBe([$sunu->id]);
    expect($rooms->pluck('branch_id'))->not->toContain($landak->id);
});

it('never creates a second Cabang Sunu', function () {
    $sunu = sunuRoomBranch();

    provisionSunuRooms();
    provisionSunuRooms();

    expect(Branch::withTrashed()->where('name', 'Cabang Sunu')->count())->toBe(1);
    expect(Branch::withTrashed()->whereIn('code', BranchCodeAlias::equivalentCodes(BranchCodeAlias::SUNU_CANONICAL))->count())->toBe(1);
    expect(Branch::query()->where('code', BranchCodeAlias::SUNU_CANONICAL)->first()->id)->toBe($sunu->id);
});

/*
|--------------------------------------------------------------------------
| Idempotence — the property that makes this safe to put in a deploy
|--------------------------------------------------------------------------
*/

it('is idempotent: provisioning twice leaves exactly one of each room', function () {
    $sunu = sunuRoomBranch();

    provisionSunuRooms();
    $afterFirst = ClinicRoom::withTrashed()->where('branch_id', $sunu->id)->pluck('id')->sort()->values();

    provisionSunuRooms();
    $afterSecond = ClinicRoom::withTrashed()->where('branch_id', $sunu->id)->pluck('id')->sort()->values();

    expect($afterSecond->all())->toBe($afterFirst->all());
    expect(ClinicRoom::withTrashed()->where('branch_id', $sunu->id)->where('name', 'Ruangan A')->count())->toBe(1);
    expect(ClinicRoom::withTrashed()->where('branch_id', $sunu->id)->where('name', 'Ruangan B')->count())->toBe(1);
});

it('adopts an already-active room instead of creating a second one', function () {
    $sunu = sunuRoomBranch();

    $existing = ClinicRoom::factory()->create([
        'branch_id' => $sunu->id,
        'code' => 'SPN-A',
        'name' => 'Ruangan A',
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);

    provisionSunuRooms();

    expect(ClinicRoom::withTrashed()->where('branch_id', $sunu->id)->where('name', 'Ruangan A')->count())->toBe(1);
    expect(ClinicRoom::query()->where('branch_id', $sunu->id)->where('name', 'Ruangan A')->first()->id)->toBe($existing->id);
});

it('reactivates an inactive room rather than duplicating it', function () {
    $sunu = sunuRoomBranch();

    $inactive = ClinicRoom::factory()->inactive()->create([
        'branch_id' => $sunu->id,
        'code' => 'SPN-A',
        'name' => 'Ruangan A',
    ]);

    provisionSunuRooms();

    $inactive->refresh();

    expect($inactive->status)->toBe(ClinicRoom::STATUS_ACTIVE);
    expect(ClinicRoom::withTrashed()->where('branch_id', $sunu->id)->where('name', 'Ruangan A')->count())->toBe(1);
});

it('restores a soft-deleted room rather than colliding with the unique index it still occupies', function () {
    $sunu = sunuRoomBranch();

    $trashed = ClinicRoom::factory()->create([
        'branch_id' => $sunu->id,
        'code' => 'SPN-B',
        'name' => 'Ruangan B',
        'status' => ClinicRoom::STATUS_INACTIVE,
    ]);
    $trashed->delete();

    expect($trashed->fresh()->deleted_at)->not->toBeNull();

    provisionSunuRooms();

    $restored = ClinicRoom::query()->where('branch_id', $sunu->id)->where('name', 'Ruangan B')->first();

    expect($restored)->not->toBeNull();
    expect($restored->id)->toBe($trashed->id);
    expect($restored->status)->toBe(ClinicRoom::STATUS_ACTIVE);
    expect(ClinicRoom::withTrashed()->where('branch_id', $sunu->id)->where('name', 'Ruangan B')->count())->toBe(1);
});

it('adopts a room that matches by name under an operator-chosen code, without rewriting that code', function () {
    $sunu = sunuRoomBranch();

    $operatorRoom = ClinicRoom::factory()->create([
        'branch_id' => $sunu->id,
        'code' => 'R-A-OPERATOR',
        'name' => 'Ruangan A',
        'status' => ClinicRoom::STATUS_INACTIVE,
    ]);

    provisionSunuRooms();

    $operatorRoom->refresh();

    expect($operatorRoom->code)->toBe('R-A-OPERATOR');
    expect($operatorRoom->status)->toBe(ClinicRoom::STATUS_ACTIVE);
    expect(ClinicRoom::withTrashed()->where('branch_id', $sunu->id)->where('name', 'Ruangan A')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Ambiguity fails closed
|--------------------------------------------------------------------------
*/

it('refuses to guess when the declared code is held by a differently-named room', function () {
    $sunu = sunuRoomBranch();

    ClinicRoom::factory()->create([
        'branch_id' => $sunu->id,
        'code' => 'SPN-A',
        'name' => 'Ruang Bedah Sunu',
    ]);

    expect(fn () => provisionSunuRooms())->toThrow(RuntimeException::class);

    expect(ClinicRoom::withTrashed()->where('branch_id', $sunu->id)->where('name', 'Ruangan A')->count())->toBe(0);
});

it('refuses to guess when the declared code and name point at two different rooms', function () {
    $sunu = sunuRoomBranch();

    ClinicRoom::factory()->create([
        'branch_id' => $sunu->id,
        'code' => 'SPN-A',
        'name' => 'Ruang Lain',
    ]);
    ClinicRoom::factory()->create([
        'branch_id' => $sunu->id,
        'code' => 'LAIN-A',
        'name' => 'Ruangan A',
    ]);

    expect(fn () => provisionSunuRooms())->toThrow(RuntimeException::class);

    expect(ClinicRoom::withTrashed()->where('branch_id', $sunu->id)->where('name', 'Ruangan A')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Branch-code foundation: SPN4 canonical, SUN4 historical
|--------------------------------------------------------------------------
*/

it('resolves Cabang Sunu through the canonical SPN4 code', function () {
    expect(BranchCodeAlias::SUNU_CANONICAL)->toBe('SPN4');
    expect(RmeBranchRoomSeeder::CANONICAL_BRANCH_ROOMS)->toHaveKey(BranchCodeAlias::SUNU_CANONICAL);
    expect(RmeBranchRoomSeeder::CANONICAL_BRANCH_ROOMS)->not->toHaveKey(BranchCodeAlias::SUNU_HISTORICAL);
});

it('still finds Cabang Sunu on a deployment whose branch row is not renamed yet, without duplicating it', function () {
    // A deployment still carrying the deprecated code. Provisioning must
    // RECOGNISE this branch, never create a second Cabang Sunu beside it.
    $legacySunu = Branch::query()->create([
        'code' => BranchCodeAlias::SUNU_HISTORICAL,
        'name' => 'Cabang Sunu',
        'is_active' => true,
        'is_rme_enabled' => true,
        'is_inventory_enabled' => false,
    ]);

    provisionSunuRooms();

    expect(Branch::withTrashed()->where('name', 'Cabang Sunu')->count())->toBe(1);
    expect(ClinicRoom::query()->where('branch_id', $legacySunu->id)->whereIn('name', ['Ruangan A', 'Ruangan B'])->count())->toBe(2);
});

it('never re-emits the deprecated Sunu code as a room identifier', function () {
    $codes = collect(RmeBranchRoomSeeder::CANONICAL_BRANCH_ROOMS[BranchCodeAlias::SUNU_CANONICAL])->pluck('code');

    foreach ($codes as $code) {
        expect($code)->not->toContain(BranchCodeAlias::SUNU_HISTORICAL);
    }
});

/*
|--------------------------------------------------------------------------
| Nothing else moves
|--------------------------------------------------------------------------
*/

it('leaves every other branch\'s rooms byte-identical', function () {
    $sunu = sunuRoomBranch();

    $landak = Branch::query()->where('code', 'LDK2')->firstOrFail();
    ClinicRoom::factory()->create(['branch_id' => $landak->id, 'code' => 'LDK-A', 'name' => 'Ruangan A']);
    ClinicRoom::factory()->inactive()->create(['branch_id' => $landak->id, 'code' => 'LDK-C', 'name' => 'Ruangan C']);

    $before = sunuRoomFingerprintExcludingBranch((int) $sunu->id);

    provisionSunuRooms();

    expect(sunuRoomFingerprintExcludingBranch((int) $sunu->id))->toBe($before);
});

it('lets the same room name exist on another branch, because room identity is branch-scoped', function () {
    $sunu = sunuRoomBranch();
    $landak = Branch::query()->where('code', 'LDK2')->firstOrFail();

    ClinicRoom::factory()->create(['branch_id' => $landak->id, 'code' => 'LDK-A', 'name' => 'Ruangan A']);

    provisionSunuRooms();

    expect(ClinicRoom::query()->where('name', 'Ruangan A')->count())->toBe(2);
    expect(ClinicRoom::query()->where('name', 'Ruangan A')->pluck('branch_id')->sort()->values()->all())
        ->toBe(collect([$landak->id, $sunu->id])->sort()->values()->all());
});

it('does not touch existing visits: no room is auto-assigned and no roomless visit is filled in', function () {
    $sunu = sunuRoomBranch();

    $roomless = ClinicVisit::factory()->create(['branch_id' => $sunu->id, 'clinic_room_id' => null]);
    $existingRoom = ClinicRoom::factory()->create(['branch_id' => $sunu->id, 'code' => 'SPN-Z', 'name' => 'Ruang Lama']);
    $assigned = ClinicVisit::factory()->create(['branch_id' => $sunu->id, 'clinic_room_id' => $existingRoom->id]);

    provisionSunuRooms();

    expect($roomless->fresh()->clinic_room_id)->toBeNull();
    expect($assigned->fresh()->clinic_room_id)->toBe($existingRoom->id);
});

/*
|--------------------------------------------------------------------------
| Operational availability and the branch boundary
|--------------------------------------------------------------------------
*/

it('offers both rooms to the Sunu room selector, and only active ones', function () {
    $sunu = sunuRoomBranch();

    provisionSunuRooms();

    ClinicRoom::factory()->inactive()->create([
        'branch_id' => $sunu->id, 'code' => 'SPN-X', 'name' => 'Ruangan Nonaktif',
    ]);

    $names = app(ClinicVisitService::class)->activeRoomsForBranch((int) $sunu->id)->pluck('name');

    expect($names)->toContain('Ruangan A')
        ->and($names)->toContain('Ruangan B')
        ->and($names)->not->toContain('Ruangan Nonaktif');
});

it('does not leak Sunu rooms into another branch\'s selector', function () {
    $sunu = sunuRoomBranch();
    $landak = Branch::query()->where('code', 'LDK2')->firstOrFail();

    provisionSunuRooms();

    $landakRooms = app(ClinicVisitService::class)->activeRoomsForBranch((int) $landak->id);

    expect($landakRooms->pluck('branch_id')->unique()->all())->not->toContain($sunu->id);
});

it('refuses a Sunu room supplied for a visit belonging to another branch', function () {
    $sunu = sunuRoomBranch();
    $landak = Branch::query()->where('code', 'LDK2')->firstOrFail();

    provisionSunuRooms();

    $sunuRoom = ClinicRoom::query()->where('branch_id', $sunu->id)->where('name', 'Ruangan A')->firstOrFail();
    $landakVisit = ClinicVisit::factory()->create(['branch_id' => $landak->id, 'clinic_room_id' => null]);

    expectRoomRefusal(
        fn () => app(ClinicVisitService::class)->assignRoom($landakVisit, (int) $sunuRoom->id),
        'Ruangan harus berasal dari cabang yang sama'
    );

    expect($landakVisit->fresh()->clinic_room_id)->toBeNull();
});

it('refuses another branch\'s room supplied for a Sunu visit', function () {
    $sunu = sunuRoomBranch();
    $landak = Branch::query()->where('code', 'LDK2')->firstOrFail();

    provisionSunuRooms();

    $landakRoom = ClinicRoom::factory()->create(['branch_id' => $landak->id, 'code' => 'LDK-A', 'name' => 'Ruangan A']);
    $sunuVisit = ClinicVisit::factory()->create(['branch_id' => $sunu->id, 'clinic_room_id' => null]);

    expectRoomRefusal(
        fn () => app(ClinicVisitService::class)->assignRoom($sunuVisit, (int) $landakRoom->id),
        'Ruangan harus berasal dari cabang yang sama'
    );

    expect($sunuVisit->fresh()->clinic_room_id)->toBeNull();
});

it('accepts a Sunu room for a Sunu visit once a doctor is online in it', function () {
    // The room being present is necessary but not sufficient: Sprint 66.1.4
    // resolves the treating doctor FROM the room, so a room is only assignable
    // while exactly one doctor is online in it. That gate is unchanged here —
    // this asserts the new rooms slot into it rather than bypassing it.
    $sunu = sunuRoomBranch();

    provisionSunuRooms();

    $room = ClinicRoom::query()->where('branch_id', $sunu->id)->where('name', 'Ruangan B')->firstOrFail();
    seedAccessControl();
    $doctor = Doctor::factory()->create();
    rmeMakeDoctorOnline($doctor, $sunu, $room);

    $visit = ClinicVisit::factory()->create(['branch_id' => $sunu->id, 'clinic_room_id' => null]);

    app(ClinicVisitService::class)->assignRoom($visit, (int) $room->id);

    expect($visit->fresh()->clinic_room_id)->toBe($room->id);
    expect($visit->fresh()->doctor_id)->toBe($doctor->id);
});

it('leaves a new Sunu room unassignable until a doctor goes online in it', function () {
    // No auto-assignment: creating the rooms does not put a doctor in them.
    $sunu = sunuRoomBranch();

    provisionSunuRooms();

    $room = ClinicRoom::query()->where('branch_id', $sunu->id)->where('name', 'Ruangan A')->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $sunu->id, 'clinic_room_id' => null]);

    expect(fn () => app(ClinicVisitService::class)->assignRoom($visit, (int) $room->id))
        ->toThrow(ValidationException::class);

    expect($visit->fresh()->clinic_room_id)->toBeNull();
});

it('refuses an inactive Sunu room for a Sunu visit, naming the status as the reason', function () {
    // Killing the "inactive room is fine" mutant needs the REASON, not just a
    // throw: with the status guard removed, assignment still fails further
    // down at doctor resolution, which would let a type-only assertion pass.
    $sunu = sunuRoomBranch();

    provisionSunuRooms();

    $room = ClinicRoom::query()->where('branch_id', $sunu->id)->where('name', 'Ruangan A')->firstOrFail();
    $room->forceFill(['status' => ClinicRoom::STATUS_INACTIVE])->save();

    $visit = ClinicVisit::factory()->create(['branch_id' => $sunu->id, 'clinic_room_id' => null]);

    expectRoomRefusal(
        fn () => app(ClinicVisitService::class)->assignRoom($visit, (int) $room->id),
        'Ruangan tidak ditemukan atau tidak aktif'
    );

    expect($visit->fresh()->clinic_room_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Branch identity is the CODE, never the display name
|--------------------------------------------------------------------------
*/

it('resolves Cabang Sunu by branch code even after its display name is edited', function () {
    // Master Data Cabang owns the display name and may change it at any time.
    // Resolving on the name would make provisioning depend on an editable
    // label instead of the branch's identity.
    $sunu = sunuRoomBranch();
    $sunu->forceFill(['name' => 'Cabang Sunu (Jl. Sunu)'])->save();

    provisionSunuRooms();

    expect(ClinicRoom::query()->where('branch_id', $sunu->id)->whereIn('name', ['Ruangan A', 'Ruangan B'])->count())->toBe(2);
});

it('does not attach Sunu rooms to a different branch that merely shares the display name', function () {
    $sunu = sunuRoomBranch();

    $impostor = Branch::query()->create([
        'code' => 'IMP9',
        'name' => 'Cabang Sunu',
        'is_active' => true,
        'is_rme_enabled' => true,
        'is_inventory_enabled' => false,
    ]);
    $sunu->forceFill(['name' => 'Cabang Sunu Utama'])->save();

    provisionSunuRooms();

    expect(ClinicRoom::query()->where('branch_id', $impostor->id)->count())->toBe(0);
    expect(ClinicRoom::query()->where('branch_id', $sunu->id)->whereIn('name', ['Ruangan A', 'Ruangan B'])->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Registry shape
|--------------------------------------------------------------------------
*/

it('declares Sunu rooms with codes derived from the existing branch-prefix convention', function () {
    $rooms = RmeBranchRoomSeeder::CANONICAL_BRANCH_ROOMS[BranchCodeAlias::SUNU_CANONICAL];

    expect(collect($rooms)->pluck('name')->all())->toBe(['Ruangan A', 'Ruangan B']);
    expect(collect($rooms)->pluck('code')->all())->toBe(['SPN-A', 'SPN-B']);
    expect(collect($rooms)->pluck('type')->unique()->all())->toBe([ClinicRoom::TYPE_TREATMENT_ROOM]);
});

it('declares rooms for Cabang Sunu only, so no sibling branch is re-provisioned', function () {
    expect(array_keys(RmeBranchRoomSeeder::CANONICAL_BRANCH_ROOMS))->toBe([BranchCodeAlias::SUNU_CANONICAL]);
});
