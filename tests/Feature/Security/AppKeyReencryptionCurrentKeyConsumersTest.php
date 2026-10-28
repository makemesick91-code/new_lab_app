<?php

/**
 * SECURITY-G7B-APP-KEY-REENCRYPTION-1 — code that signs with the CURRENT
 * application key only.
 *
 * APP_PREVIOUS_KEYS keeps old ciphertext readable; it does nothing for a value
 * computed with hash_hmac(..., config('app.key')). Anything such code issued
 * before a rotation stops verifying the moment the key changes. That is only
 * acceptable while every such value is short-lived and the check fails closed:
 * refused and re-issued, never a 500, never silently accepted.
 *
 * These tests pin both halves for the doctor login ticket and the device pairing
 * code (the payment intent is pinned in AppKeyReencryptionTest), and pin the list
 * of files that read the key directly, so a new consumer cannot appear without
 * someone deciding how it behaves on rotation.
 */

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceEnrollment;
use App\Modules\DoctorDevice\Models\DoctorDeviceLoginTicket;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorAppLoginService;
use App\Modules\DoctorDevice\Services\DoctorDeviceEnrollmentService;
use App\Modules\DoctorDevice\Services\DoctorDeviceSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function akcKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

function akcUseKeys(string $current, array $previous = []): void
{
    config(['app.key' => $current, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

/**
 * Turn device enforcement on. The flag key contains a dot, so the whole flags
 * array is rewritten; dot notation would build a structure nothing reads.
 */
function akcEnforceDevices(): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = true;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = true;
    config()->set('feature_flags.flags', $flags);
}

/** @return list<string> files under app/ that read the application key directly */
function akcKeyReaders(): array
{
    $readers = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        if (preg_match('/[\'"]app\.key[\'"]/', (string) file_get_contents($file->getPathname())) === 1) {
            $readers[] = 'app/'.ltrim(substr($file->getPathname(), strlen(app_path())), '/');
        }
    }

    sort($readers);

    return $readers;
}

/** A doctor login ticket row for $token, hashed with the key configured right now. */
function akcTicket(string $token): DoctorDeviceLoginTicket
{
    $doctor = Doctor::factory()->create();
    $device = DoctorDevice::factory()->create();
    $authorization = DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    $ticket = new DoctorDeviceLoginTicket;
    $ticket->forceFill([
        'uuid' => (string) Str::uuid(),
        'token_hash' => app(DoctorAppLoginService::class)->hashTicket($token),
        'user_id' => User::factory()->create()->id,
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
        'doctor_device_authorization_id' => $authorization->id,
        'expires_at' => now()->addSeconds(60),
    ])->save();

    return $ticket;
}

it('registers every file that reads the application key directly', function () {
    $registered = array_keys((array) config('app_key_rotation.current_key_only_consumers'));
    sort($registered);

    expect($registered)->not->toBeEmpty()
        ->and(akcKeyReaders())->toBe($registered);

    foreach ((array) config('app_key_rotation.current_key_only_consumers') as $path => $behaviour) {
        expect(is_file(base_path($path)))->toBeTrue("{$path} is registered but does not exist")
            ->and(trim((string) $behaviour))->not->toBe('', "{$path} must state how it behaves on rotation");
    }
});

it('refuses a doctor login ticket issued under the previous key without spending it', function () {
    akcEnforceDevices();
    $keyA = akcKey();
    akcUseKeys($keyA);

    $token = bin2hex(random_bytes(32));
    $ticket = akcTicket($token);
    $logins = app(DoctorAppLoginService::class);

    // Control: before rotation the presented token resolves to the ticket.
    expect(DoctorDeviceLoginTicket::query()->where('token_hash', $logins->hashTicket($token))->exists())->toBeTrue();

    akcUseKeys(akcKey(), [$keyA]);

    // The previous key is still configured, and it does not help: the ticket
    // was signed with the old key and is looked up with the new one.
    expect(DoctorDeviceLoginTicket::query()->where('token_hash', $logins->hashTicket($token))->exists())->toBeFalse()
        ->and(fn () => app(DoctorDeviceSessionService::class)->redeem($token, Request::create('/device-login', 'GET')))
        ->toThrow(ValidationException::class);

    expect($ticket->fresh()->consumed_at)->toBeNull();

    $refusal = DB::table('sys_audit_logs')->where('action', 'DOCTOR_APP_LOGIN_AUTHORIZATION_REJECTED')->latest('id')->first();
    expect($refusal)->not->toBeNull()
        ->and(json_decode((string) $refusal->new_values, true)['reason'] ?? null)->toBe('unknown_ticket');
});

it('honours a doctor login ticket issued after the rotation', function () {
    akcEnforceDevices();
    akcUseKeys(akcKey(), [akcKey()]);

    $token = bin2hex(random_bytes(32));
    $ticket = akcTicket($token);

    expect(DoctorDeviceLoginTicket::query()
        ->where('token_hash', app(DoctorAppLoginService::class)->hashTicket($token))
        ->value('id'))->toBe($ticket->id);
});

it('does not resolve a pairing code issued under the previous key, and resolves one issued after', function () {
    $keyA = akcKey();
    akcUseKeys($keyA);
    $enrollments = app(DoctorDeviceEnrollmentService::class);

    $before = DoctorDeviceEnrollment::factory()->create(['pairing_code_hash' => $enrollments->hashPairingCode('ABCD2345')]);
    expect($enrollments->findByPairingCode('abcd2345')?->id)->toBe($before->id);

    akcUseKeys(akcKey(), [$keyA]);

    expect($enrollments->findByPairingCode('ABCD2345'))->toBeNull();

    $after = DoctorDeviceEnrollment::factory()->create(['pairing_code_hash' => $enrollments->hashPairingCode('WXYZ6789')]);
    expect($enrollments->findByPairingCode('WXYZ6789')?->id)->toBe($after->id);
});
