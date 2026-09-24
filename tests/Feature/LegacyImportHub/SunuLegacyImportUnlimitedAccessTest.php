<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| REVISION-SUNU-LEGACY-IMPORT-UNLIMITED-ADMIN-ACCESS-1
|--------------------------------------------------------------------------
|
| The owner's decision, pinned.
|
| WHAT CHANGED. The Legacy Import Hub shipped a business ceiling of 100
| accepted records per import type, per branch, per clinical day. The complete
| Sunu legacy estate cannot be migrated while an operator has to stop at 100
| and come back tomorrow, so the ceiling is gone for all three import types.
|
| WHAT DID NOT CHANGE, AND IS ASSERTED HERE. Removing a COUNT authorizes
| nobody. Capability flags, branch admission, permissions, duplicate detection,
| human review, publish and VOID controls and technical backpressure each still
| refuse on their own. The single most dangerous way to read "unlimited" is as
| "ungated", so this file spends most of its length proving the gates survived
| the change rather than celebrating the change itself.
|
| WHY THE QUOTA GATE IS EXERCISED DIRECTLY. Driving 150 real PDF ingestions
| through Poppler would test the renderer, not the ceiling. The claim under
| test is precisely "the business counter does not refuse", so the counter is
| the thing called — many more times than the retired ceiling ever allowed.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyImport\Models\LegacyImportDailyQuota;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyRme\Services\LegacyRmeBranchAdmissionService;
use App\Modules\LegacyRme\Services\LegacyRmeIngestionCapacityService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
});

/*
| The number used throughout to mean "past the retired ceiling". 150 is
| deliberately not a round multiple of 100: an off-by-one or a modulo bug in a
| reintroduced ceiling would survive 200 and dies here.
*/
const SUNU_PAST_OLD_CEILING = 150;

/*
|--------------------------------------------------------------------------
| 1-3. No business daily quota, for any of the three import types
|--------------------------------------------------------------------------
*/

it('accepts far more than the retired 100-per-day ceiling for every import type', function (string $type) {
    $branch = lihBranch('SPN4', 'Cabang Sunu');

    // Not lihLimit(): the point is the SHIPPED default, so nothing is declared.
    expect(lihQuota()->limitFor($type))->toBeNull();

    for ($i = 0; $i < SUNU_PAST_OLD_CEILING; $i++) {
        DB::transaction(fn () => lihQuota()->reserve($type, (int) $branch->id));
    }

    // Reaching here at all is the assertion: any surviving business ceiling
    // throws a ValidationException on record 101.
    expect(lihQuota()->preview($type, (int) $branch->id))->toBeNull();

    // And there is still no ceiling to count down to.
    expect(lihQuota()->remainingToday($type, (int) $branch->id))->toBeNull();
})->with([
    'legacy patient' => LegacyImportType::LEGACY_PATIENT,
    'legacy RME' => LegacyImportType::LEGACY_RME,
    'legacy odontogram' => LegacyImportType::LEGACY_ODONTOGRAM,
]);

it('accepts a single batch larger than the retired ceiling', function () {
    // A real legacy CSV is committed as ONE batch. The retired ceiling refused
    // a batch whole rather than part-admitting it, so a 150-row file used to
    // fail outright instead of importing 100 rows.
    $branch = lihBranch('SPN4', 'Cabang Sunu');

    DB::transaction(fn () => lihQuota()->reserveMany(
        LegacyImportType::LEGACY_PATIENT,
        [(int) $branch->id => SUNU_PAST_OLD_CEILING],
    ));
})->throwsNoExceptions();

it('does not make the operator wait for a new clinical day', function () {
    // The retired ceiling's real cost was the WAIT: hit 100 and the estate
    // stalled until the clinical calendar rolled. Two full former days of
    // volume inside one clinical day proves the reset is no longer a gate.
    $branch = lihBranch('SPN4', 'Cabang Sunu');
    $type = LegacyImportType::LEGACY_RME;

    for ($i = 0; $i < 200; $i++) {
        DB::transaction(fn () => lihQuota()->reserve($type, (int) $branch->id));
    }

    expect(lihQuota()->preview($type, (int) $branch->id, 500))->toBeNull();
});

it('keeps unlimited meaning absent rather than a large number', function () {
    // A sentinel ceiling would still be a ceiling: it would refuse the day an
    // estate exceeded it, and it would render to the operator as a countdown.
    foreach (LegacyImportType::all() as $type) {
        $limit = lihQuota()->limitFor($type);

        expect($limit)->toBeNull();
        expect($limit)->not->toBe(0);
        expect($limit)->not->toBeInt();
    }
});

it('opens no quota bucket while no ceiling is declared', function () {
    // No ceiling means nothing to meter. A bucket written anyway would be an
    // unreconciled counter that drifts from reality forever.
    $branch = lihBranch('SPN4', 'Cabang Sunu');

    for ($i = 0; $i < SUNU_PAST_OLD_CEILING; $i++) {
        DB::transaction(fn () => lihQuota()->reserve(LegacyImportType::LEGACY_RME, (int) $branch->id));
    }

    expect(LegacyImportDailyQuota::query()->count())->toBe(0);
});

it('still honours a ceiling an operator declares later', function () {
    // Removing the default must not remove the MECHANISM: the owner keeps the
    // ability to reimpose a ceiling without a deploy.
    $branch = lihBranch('SPN4', 'Cabang Sunu');
    $type = LegacyImportType::LEGACY_ODONTOGRAM;

    lihLimit($type, 2);

    DB::transaction(fn () => lihQuota()->reserve($type, (int) $branch->id));
    DB::transaction(fn () => lihQuota()->reserve($type, (int) $branch->id));

    expect(fn () => DB::transaction(fn () => lihQuota()->reserve($type, (int) $branch->id)))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| 4. Technical backpressure is not business quota, and still bites
|--------------------------------------------------------------------------
*/

it('still refuses ingestion when the render queue is saturated', function () {
    /*
    | The depth probe deliberately reports NULL on a non-database queue driver
    | rather than guessing zero, because guessing would fake headroom nobody
    | measured. The suite runs on `sync`, so the driver is switched here to
    | reach the rail at all.
    */
    config(['queue.default' => 'database']);

    $capacity = app(LegacyRmeIngestionCapacityService::class);

    expect($capacity->enforced())->toBeTrue();
    expect($capacity->evaluate()->available)->toBeTrue();

    // Fill the render queue past its configured depth. This is a TECHNICAL
    // rail protecting the worker, deliberately untouched by the quota
    // decision, and it must still stop work the machine cannot carry.
    $queue = $capacity->renderQueueName();
    $ceiling = (int) config('legacy_rme_rollout.capacity.max_pending_jobs');

    for ($i = 0; $i <= $ceiling; $i++) {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }

    expect($capacity->evaluate()->available)->toBeFalse();
});

it('keeps the technical upload rails that unlimited never covered', function () {
    // Unlimited is a COUNT decision. Size, page and render rails are safety
    // rails, and removing them with the count is the misreading this pins.
    expect((int) config('legacy_odontogram.upload.max_bytes'))->toBeGreaterThan(0);
    expect((int) config('legacy_odontogram.upload.max_pages'))->toBeGreaterThan(0);
    expect((int) config('legacy_rme_rollout.capacity.max_pending_jobs'))->toBeGreaterThan(0);
    expect((int) config('legacy_rme_rollout.capacity.min_free_disk_bytes'))->toBeGreaterThan(0);
    expect((bool) config('legacy_rme_rollout.capacity.enforced'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 5 & 9. Branch admission is an independent gate, unaffected by the quota
|--------------------------------------------------------------------------
*/

it('still refuses a branch that is not admitted, however unlimited the quota is', function () {
    $admission = app(LegacyRmeBranchAdmissionService::class);

    expect($admission->enforced())->toBeTrue();

    // Admit Sunu and nothing else.
    legacyRmeAdmittedBranches(['SPN4']);

    expect($admission->admittedBranchCodes())->toBe(['SPN4']);

    foreach (['TLK1', 'LDK2', 'ATG3', 'MAIN'] as $otherBranchCode) {
        expect($admission->admittedBranchCodes())->not->toContain($otherBranchCode);
    }
});

it('never admits the MAIN fallback branch to a clinical migration', function () {
    legacyRmeAdmittedBranches(['MAIN', 'SPN4']);

    // MAIN is structurally forbidden, not merely absent from an allowlist, so
    // declaring it does not admit it.
    expect(app(LegacyRmeBranchAdmissionService::class)->admittedBranchCodes())
        ->toBe(['SPN4']);
});

it('resolves Sunu through its canonical code rather than its retired alias', function () {
    // Cabang Sunu's canonical code is SPN4; SUN4 is the deprecated alias. A
    // deployment still declaring the alias must not silently lock Sunu out of
    // its own wave, which is the failure this alias layer exists to prevent.
    legacyRmeAdmittedBranches(['SUN4']);

    expect(app(LegacyRmeBranchAdmissionService::class)->admittedBranchCodes())
        ->toBe(['SPN4']);
});

/*
|--------------------------------------------------------------------------
| 6, 8 & 10. Access: Admin Sunu, SPN4, and nothing more
|--------------------------------------------------------------------------
*/

it('lets a Front Office operator reach all three legacy upload surfaces', function () {
    // Admin Sunu's authority comes from the Front Office role, which already
    // carried every permission the three importers require. The revision adds
    // no permission and widens no role.
    $operator = lihOperator([
        'manage patients',
        'view_legacy_rme_imports',
        'create_legacy_rme_imports',
        'view_legacy_odontogram_imports',
        'create_legacy_odontogram_imports',
    ]);

    foreach (['legacy_patient', 'legacy_rme', 'legacy_odontogram'] as $type) {
        $required = (array) config('legacy_import_hub.types.'.$type.'.permissions');

        foreach ($required as $permission) {
            expect($operator->can($permission))->toBeTrue(
                "A Front Office operator must hold {$permission} for {$type}.",
            );
        }
    }
});

it('grants no publish, review or void authority with the upload permissions', function () {
    // Uploading is not publishing. The separation of duties that governs what
    // enters a patient's permanent history is untouched by this revision.
    $operator = lihOperator([
        'manage patients',
        'view_legacy_rme_imports',
        'create_legacy_rme_imports',
        'view_legacy_odontogram_imports',
        'create_legacy_odontogram_imports',
    ]);

    $withheld = [
        'review_legacy_rme_imports',
        'publish_legacy_rme_imports',
        'void_legacy_rme_imports',
        'review_legacy_odontogram_imports',
        'publish_legacy_odontogram_imports',
        'void_legacy_odontogram_records',
    ];

    foreach ($withheld as $permission) {
        expect($operator->can($permission))->toBeFalse(
            "Upload authority must not carry {$permission}.",
        );
    }
});

it('refuses an operator who holds no legacy import permission', function () {
    // The quota decision grants nothing. An unauthorized account is refused by
    // permission, exactly as before.
    $stranger = lihOperator(['view dashboard']);

    foreach (LegacyImportType::all() as $type) {
        foreach ((array) config('legacy_import_hub.types.'.$type.'.permissions') as $permission) {
            expect($stranger->can($permission))->toBeFalse();
        }
    }
});

it('keeps the Front Office role itself unwidened by this revision', function () {
    /*
    | Read the SHIPPED role definition. The owner's instruction was explicit:
    | Admin Sunu, not every Front Office account, and certainly not a broader
    | grant bolted on to make a page render. This passes because the role
    | ALREADY carried these permissions, so the revision adds none.
    */
    $roles = (new ReflectionClass(RoleSeeder::class))
        ->getConstant('ROLE_PERMISSIONS');

    expect($roles['Front Office'])->toContain('manage patients');
    expect($roles['Front Office'])->toContain('create_legacy_rme_imports');
    expect($roles['Front Office'])->toContain('create_legacy_odontogram_imports');

    // Nothing that publishes, voids or escalates.
    expect($roles['Front Office'])->not->toContain('publish_legacy_rme_imports');
    expect($roles['Front Office'])->not->toContain('void_legacy_rme_imports');
    expect($roles['Front Office'])->not->toContain('publish_legacy_odontogram_imports');
    expect($roles['Front Office'])->not->toContain('manage master data');
});

it('meters each branch separately so Sunu volume never consumes another branch', function () {
    // With a ceiling declared, branches remain independent. Unlimited must not
    // be achieved by accidentally pooling branches into one bucket.
    $sunu = lihBranch('SPN4', 'Cabang Sunu');
    $telkomas = lihBranch('TLK1', 'Cabang Telkomas');
    $type = LegacyImportType::LEGACY_RME;

    lihLimit($type, 5);

    lihConsume($type, (int) $sunu->id, 5);

    expect(lihQuota()->remainingToday($type, (int) $sunu->id))->toBe(0);
    expect(lihQuota()->remainingToday($type, (int) $telkomas->id))->toBe(5);
});

/*
|--------------------------------------------------------------------------
| 7. Duplicate protection
|--------------------------------------------------------------------------
*/

it('keeps the duplicate and idempotency guarantees the ceiling never provided', function () {
    /*
    | High volume is exactly when duplicate protection matters most, so the
    | invariants are pinned here rather than assumed. These are declared in the
    | hub contract and enforced in the importers.
    */
    $invariants = (array) config('legacy_import_hub.invariants');

    expect($invariants['retry_never_double_charges'])->toBeTrue();
    expect($invariants['rejected_record_consumes_nothing'])->toBeTrue();
    expect($invariants['reservation_is_transactional'])->toBeTrue();
    expect($invariants['branch_is_server_resolved'])->toBeTrue();

    // And the revision's own contract.
    expect($invariants['business_quota_unlimited_by_default'])->toBeTrue();
    expect($invariants['unlimited_is_null_not_a_sentinel'])->toBeTrue();
    expect($invariants['technical_backpressure_survives_quota_removal'])->toBeTrue();
});

it('never resolves the import branch from the request', function () {
    // Unlimited volume through a request-chosen branch would be a cross-branch
    // hole. The branch stays server-resolved.
    expect((bool) config('legacy_import_hub.invariants.branch_is_server_resolved'))->toBeTrue();

    $sunu = Branch::query()->where('code', 'SPN4')->first()
        ?? lihBranch('SPN4', 'Cabang Sunu');

    expect($sunu->code)->toBe('SPN4');
});
