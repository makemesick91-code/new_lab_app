<?php

declare(strict_types=1);

use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/helpers.php';

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 PR-C — structural governance
|--------------------------------------------------------------------------
|
| Fixture-based tests prove the code behaves correctly on the estate a test
| builds. These prove properties that survive a future sprint changing that
| estate — the premises the behavioural tests silently rest on.
*/

/** Every PHP file that makes up PR-C's own writable surface. */
function dbaSourceFiles(): array
{
    return [
        app_path('Modules/DoctorAccess/Services/DoctorDeviceBulkAuthorizationService.php'),
        app_path('Modules/DoctorAccess/Support/DoctorDeviceBulkAuthorizationPlan.php'),
        app_path('Modules/DoctorAccess/Support/DoctorDeviceBulkAuthorizationPair.php'),
        app_path('Modules/DoctorAccess/Support/DoctorDeviceBulkAuthorizationOutcome.php'),
        app_path('Modules/DoctorAccess/Support/DoctorDeviceBulkAuthorizationRefusal.php'),
        app_path('Console/Commands/DoctorDeviceBulkAuthorizeCommand.php'),
    ];
}

/** One file's executable tokens, with every comment stripped. */
function dbaExecutable(string $path): string
{
    $executable = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $executable .= is_array($token) ? $token[1] : $token;
    }

    return $executable;
}

it('promotes no device anywhere in the estate, which is the premise the guard rests on', function (): void {
    // THE PREMISE TEST, and the one that matters most. The apply suite proves
    // the device table is untouched on the fixtures it builds; this proves WHY
    // that generalises — `pending_approval` is a birth-only state, written by
    // exactly one INSERT and never by an UPDATE. The moment a future sprint
    // adds an active -> pending_approval transition, the reuse of approve()
    // stops being provably safe and this reddens, while the fixture-based
    // tests would all still pass on estates that never exercise it.
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $executable = dbaExecutable($file->getPathname());

        if (! str_contains($executable, 'STATUS_PENDING_APPROVAL') && ! str_contains($executable, "'pending_approval'")) {
            continue;
        }

        $offenders[] = str_replace(app_path().'/', '', $file->getPathname());
    }

    sort($offenders);

    expect($offenders)->toBe([
        // The constant declaration, the STATUSES list and the isPendingApproval() comparison.
        'Modules/DoctorDevice/Models/DoctorDevice.php',
        // The only INSERT: a doctor's first login registers the tablet without trusting it.
        'Modules/DoctorDevice/Services/DoctorAppLoginService.php',
        // The old-value side of the DOCTOR_DEVICE_ADMITTED audit payload — the
        // branch PR-C's guard keeps unreachable.
        'Modules/DoctorDevice/Services/DoctorDeviceAuthorizationService.php',
    ], 'A new writer of pending_approval falsifies the premise that lets PR-C reuse approve().');
});

it('owns no state transition of its own: every write goes through the lifecycle service', function (): void {
    foreach (dbaSourceFiles() as $path) {
        $executable = dbaExecutable($path);
        $name = basename($path);

        // A second writer of STATUS_ACTIVE on the authorization table would be
        // a drift liability: approve() already carries the locking, the
        // re-validation, the idempotence and the audit trail.
        expect(str_contains($executable, 'DoctorDeviceAuthorization::STATUS_ACTIVE,'))
            ->toBeFalse("{$name} assigns an authorization status directly");

        expect(str_contains($executable, '->forceFill('))
            ->toBeFalse("{$name} writes a model attribute directly");

        expect(str_contains($executable, 'DoctorDevice::STATUS_'))
            ->toBeFalse("{$name} names a device status, which only a device writer would need");
    }
});

it('reads no branch context, so a console run is never scoped to one operator branch', function (): void {
    foreach (dbaSourceFiles() as $path) {
        $executable = dbaExecutable($path);
        $name = basename($path);

        // BranchContext::forUser() in a fleet-wide console tool would silently
        // scope the estate to the operator's own branch and under-provision
        // everyone else — a failure that looks exactly like success.
        expect(str_contains($executable, 'BranchContext'))
            ->toBeFalse("{$name} reads BranchContext");
    }
});

it('touches no credential, lease, lock, cover, flag or enforcement scope', function (): void {
    $forbidden = [
        'WebAuthnCredential',
        'DoctorSessionLease',
        'DoctorBranchLock',
        'DoctorBranchCover',
        'FeatureFlagService',
        'AndroidDoctorEnforcementScope',
        'doctor.single_active_session',
        'doctor.branch_lock',
        'doctor.pwa_webauthn_device_login',
    ];

    foreach (dbaSourceFiles() as $path) {
        $executable = dbaExecutable($path);
        $name = basename($path);

        foreach ($forbidden as $needle) {
            expect(str_contains($executable, $needle))
                ->toBeFalse("{$name} references {$needle}");
        }
    }
});

it('never revokes, rejects or re-opens an authorization', function (): void {
    foreach (dbaSourceFiles() as $path) {
        $executable = dbaExecutable($path);
        $name = basename($path);

        // Withdrawing trust and forgiving a refusal are deliberate human acts
        // with their own routes, their own mandatory reasons and their own
        // audit events. A synchroniser must never make either decision.
        foreach (['->revoke(', '->reject(', '->allowReRequest('] as $needle) {
            expect(str_contains($executable, $needle))
                ->toBeFalse("{$name} calls {$needle} — that is a human lifecycle decision");
        }
    }
});

it('installs no observer or listener that would authorize anyone automatically', function (): void {
    $providers = glob(app_path('Providers/*.php')) ?: [];
    $moduleProviders = glob(app_path('Modules/*/Providers/*.php')) ?: [];

    foreach (array_merge($providers, $moduleProviders) as $path) {
        $executable = dbaExecutable($path);

        // A new doctor or a newly admitted tablet must NOT silently acquire the
        // whole matrix. Provisioning stays an explicit, reviewed, audited run.
        expect(str_contains($executable, 'DoctorDeviceBulkAuthorizationService'))
            ->toBeFalse(basename($path).' wires the bulk provisioner into the boot path');
    }

    // And its only caller is the command.
    $callers = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if ($file->getFilename() === 'DoctorDeviceBulkAuthorizationService.php') {
            continue;
        }

        if (str_contains(dbaExecutable($file->getPathname()), 'DoctorDeviceBulkAuthorizationService')) {
            $callers[] = $file->getFilename();
        }
    }

    expect($callers)->toBe(['DoctorDeviceBulkAuthorizeCommand.php']);
});

it('gates on exactly one permission, and that permission is already seeded', function (): void {
    $executable = dbaExecutable(app_path('Console/Commands/DoctorDeviceBulkAuthorizeCommand.php'));

    preg_match_all("/'((?:view|manage|approve|release|create)_[a-z0-9_]+)'/", $executable, $matches);

    $permissions = array_values(array_unique($matches[1]));

    // Non-empty first: a regex that matched nothing would make every assertion
    // below vacuously true.
    expect($permissions)->not->toBeEmpty()
        ->and($permissions)->toBe(['manage_doctor_device_authorizations'])
        ->and(PermissionSeeder::PERMISSIONS)->toContain('manage_doctor_device_authorizations');

    daSeedAccessControl();

    // In the DATABASE, not just in the seeder constant. $user->can() reads the
    // database, and both sibling PRs shipped with their new permission absent
    // on the host — a green seeder test is not a seeded host.
    expect(Permission::query()
        ->where('name', 'manage_doctor_device_authorizations')->exists())->toBeTrue();
});

it('mints no permission, so there is no seeder step for a deploy to forget', function (): void {
    // The single strongest deploy-safety property of this sprint. PR-A and PR-B
    // each shipped with their new permission missing on the host; PR-C cannot
    // repeat that because it adds nothing to seed.
    $granted = RoleSeeder::ROLE_PERMISSIONS['Supervisor RME'] ?? [];

    expect($granted)->toContain('manage_doctor_device_authorizations');

    foreach (RoleSeeder::ROLE_PERMISSIONS as $role => $permissions) {
        if ($role === 'Super Admin' || $permissions === ['*']) {
            continue;
        }

        foreach ($permissions as $permission) {
            expect($permission)->not->toBe('bulk_authorize_doctor_devices');
        }
    }
});

it('is registered as an artisan command with a dry-run default in its own signature', function (): void {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('doctor:device-bulk-authorize');

    $executable = dbaExecutable(app_path('Console/Commands/DoctorDeviceBulkAuthorizeCommand.php'));

    // The flags that make the default safe, asserted structurally so a future
    // edit cannot quietly make --apply the default.
    expect($executable)->toContain('--apply')
        ->and($executable)->toContain('--confirm-plan')
        ->and($executable)->toContain('--actor')
        ->and($executable)->toContain('--reason');
});

it('keeps the pair unique index unconditional, which is what makes duplicates impossible', function (): void {
    $branch = daBranch('Cabang Unique Index');
    $account = daDoctorAccount([$branch]);
    $device = dbaTrustedDevice([], $branch);

    dbaAuthorization($account['doctor'], $device, DoctorDeviceAuthorization::STATUS_REVOKED);

    // A PARTIAL unique index — one scoped to active rows — would let a second
    // row exist beside a revoked one, and "exactly one active authorization per
    // pair" would become an application promise instead of a database fact.
    // This asserts the index refuses a second row at ANY status.
    $second = fn () => DoctorDeviceAuthorization::factory()->create([
        'doctor_id' => $account['doctor']->id,
        'doctor_device_id' => $device->id,
    ]);

    expect($second)->toThrow(QueryException::class);

    expect(DoctorDevice::query()->count())->toBe(1);
});
