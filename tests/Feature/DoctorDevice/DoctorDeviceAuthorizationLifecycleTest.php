<?php

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — the doctor/device
 * authorization lifecycle.
 *
 * The domain rules, tested through the service rather than through HTTP, so a
 * failure here names the rule that broke rather than the screen that showed it.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceAuthorizationRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorDeviceAuthorizationService;
use App\Modules\DoctorDevice\Services\DoctorDeviceProofService;
use App\Modules\DoctorDevice\Services\DoctorDeviceService;
use App\Modules\DoctorDevice\Support\DeviceKeyMaterial;
use App\Modules\LabOrder\Models\AuditLog;
use Database\Factories\DoctorDeviceEnrollmentFactory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

function authFixture(): array
{
    seedAccessControl();

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $doctor = Doctor::factory()->withAllowedBranches([$branch])->create(['is_active' => true]);
    [$pub] = DoctorDeviceEnrollmentFactory::generateKeyPair();

    $device = DoctorDevice::factory()->create([
        'branch_id' => $branch->id,
        'public_key' => $pub,
        'public_key_fingerprint' => DeviceKeyMaterial::fingerprint($pub),
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED,
    ]);

    return [
        'branch' => $branch,
        'doctor' => $doctor,
        'device' => $device,
        'admin' => superAdmin(),
        'service' => app(DoctorDeviceAuthorizationService::class),
    ];
}

// ---------------------------------------------------------------------------
// Creation, and the idempotency that stops an approver's inbox filling up
// ---------------------------------------------------------------------------

it('creates one pending authorization for a new doctor and device pair', function () {
    $f = authFixture();

    $authorization = $f['service']->resolveOrRequest($f['doctor'], $f['device']);

    expect($authorization->status)->toBe(DoctorDeviceAuthorization::STATUS_PENDING)
        ->and($authorization->request_source)->toBe(DoctorDeviceAuthorization::SOURCE_APP_LOGIN)
        ->and($authorization->requested_at)->not->toBeNull()
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(1);
});

it('returns the same pending row however many times a doctor taps login', function () {
    $f = authFixture();

    $ids = [];
    for ($i = 0; $i < 10; $i++) {
        $ids[] = $f['service']->resolveOrRequest($f['doctor'], $f['device'])->id;
    }

    expect(array_unique($ids))->toHaveCount(1)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(1);
});

it('refuses a duplicate pair at the database level', function () {
    $f = authFixture();
    $f['service']->resolveOrRequest($f['doctor'], $f['device']);

    // The unique index is the actual guarantee. If someone ever removes it, the
    // application-level check alone would let two concurrent logins through.
    expect(fn () => DoctorDeviceAuthorization::factory()->create([
        'doctor_id' => $f['doctor']->id,
        'doctor_device_id' => $f['device']->id,
    ]))->toThrow(QueryException::class);
});

it('lets the database settle a genuine race rather than the application', function () {
    // MUTATION FINDING (M1). Three layers guard idempotency: the read before
    // the transaction, a locked re-read inside it, and the unique index. In a
    // single-process suite the first two always answer, so the third — the one
    // that is actually the guarantee — was never exercised.
    //
    // A real race is: another connection COMMITTED the row while this request
    // was between its checks, so the locked re-read still saw nothing and the
    // insert collides. Simulating it by inserting from inside this same
    // transaction does NOT reproduce that — the competing row rolls back with
    // the failed insert and there is no winner to adopt, which is how the first
    // attempt at this test failed for the wrong reason.
    //
    // So the blindness is injected where a race actually puts it: the locked
    // re-read returns nothing while the row genuinely exists and is visible to
    // an unlocked read.
    $f = authFixture();

    $existing = DoctorDeviceAuthorization::factory()->create([
        'doctor_id' => $f['doctor']->id,
        'doctor_device_id' => $f['device']->id,
    ]);

    $real = app(DoctorDeviceAuthorizationRepositoryInterface::class);

    app()->instance(
        DoctorDeviceAuthorizationRepositoryInterface::class,
        new class($real) implements DoctorDeviceAuthorizationRepositoryInterface
        {
            private bool $blind = true;

            public function __construct(private readonly DoctorDeviceAuthorizationRepositoryInterface $inner) {}

            /** Both pre-checks miss once, exactly as a lost race looks. */
            public function findPair(int $doctorId, int $deviceId): ?DoctorDeviceAuthorization
            {
                if ($this->blind) {
                    $this->blind = false;

                    return null;
                }

                return $this->inner->findPair($doctorId, $deviceId);
            }

            public function findPairForUpdate(int $doctorId, int $deviceId): ?DoctorDeviceAuthorization
            {
                return null;
            }

            public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
            {
                return $this->inner->paginate($filters, $perPage);
            }

            public function findForUpdate(int $id): ?DoctorDeviceAuthorization
            {
                return $this->inner->findForUpdate($id);
            }

            public function create(array $data): DoctorDeviceAuthorization
            {
                return $this->inner->create($data);
            }

            public function update(DoctorDeviceAuthorization $authorization, array $data): DoctorDeviceAuthorization
            {
                return $this->inner->update($authorization, $data);
            }

            public function countPending(): int
            {
                return $this->inner->countPending();
            }
        }
    );

    $service = app()->makeWith(DoctorDeviceAuthorizationService::class, []);

    $authorization = $service->resolveOrRequest($f['doctor'], $f['device']);

    // The committed winner is adopted; no duplicate, no unhandled exception.
    expect($authorization->id)->toBe($existing->id)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(1);
});

it('keeps one physical device serving several doctors without duplicating it', function () {
    $f = authFixture();
    $second = Doctor::factory()->withAllowedBranches([$f['branch']])->create(['is_active' => true]);

    $a = $f['service']->resolveOrRequest($f['doctor'], $f['device']);
    $b = $f['service']->resolveOrRequest($second, $f['device']);

    expect($a->id)->not->toBe($b->id)
        ->and($a->doctor_device_id)->toBe($b->doctor_device_id)
        // One tablet, one registry row — never one device row per doctor.
        ->and(DoctorDevice::query()->count())->toBe(1);
});

it('gives one doctor a separate authorization per device', function () {
    $f = authFixture();
    [$pub2] = DoctorDeviceEnrollmentFactory::generateKeyPair();
    $second = DoctorDevice::factory()->create([
        'branch_id' => $f['branch']->id,
        'public_key' => $pub2,
        'public_key_fingerprint' => DeviceKeyMaterial::fingerprint($pub2),
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
    ]);

    $f['service']->resolveOrRequest($f['doctor'], $f['device']);
    $f['service']->resolveOrRequest($f['doctor'], $second);

    expect(DoctorDeviceAuthorization::query()->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Transitions
// ---------------------------------------------------------------------------

it('approves a pending pair and records who decided', function () {
    $f = authFixture();
    $authorization = $f['service']->resolveOrRequest($f['doctor'], $f['device']);

    $approved = $f['service']->approve($authorization, $f['admin']);

    expect($approved->status)->toBe(DoctorDeviceAuthorization::STATUS_ACTIVE)
        ->and($approved->approved_by)->toBe($f['admin']->id)
        ->and($approved->approved_at)->not->toBeNull();
});

it('rejects only with a reason, and records it', function () {
    $f = authFixture();
    $authorization = $f['service']->resolveOrRequest($f['doctor'], $f['device']);

    expect(fn () => $f['service']->reject($authorization, '  ', $f['admin']))
        ->toThrow(ValidationException::class);

    $rejected = $f['service']->reject($authorization, 'Perangkat pribadi.', $f['admin']);

    expect($rejected->status)->toBe(DoctorDeviceAuthorization::STATUS_REJECTED)
        ->and($rejected->rejected_reason)->toBe('Perangkat pribadi.')
        ->and($rejected->rejected_by)->toBe($f['admin']->id);
});

it('never lets a rejected pair reopen itself on the next login', function () {
    $f = authFixture();
    $authorization = $f['service']->resolveOrRequest($f['doctor'], $f['device']);
    $f['service']->reject($authorization, 'Bukan perangkat klinik.', $f['admin']);

    // This is the rule the brief is most explicit about: a refused doctor must
    // not be able to re-queue themselves simply by tapping login again.
    for ($i = 0; $i < 5; $i++) {
        $again = $f['service']->resolveOrRequest($f['doctor'], $f['device']);
        expect($again->status)->toBe(DoctorDeviceAuthorization::STATUS_REJECTED);
    }

    expect(DoctorDeviceAuthorization::query()->count())->toBe(1);
});

it('reopens a rejected pair only after a privileged allow-re-request', function () {
    $f = authFixture();
    $authorization = $f['service']->resolveOrRequest($f['doctor'], $f['device']);
    $f['service']->reject($authorization, 'Salah perangkat.', $f['admin']);

    $allowed = $f['service']->allowReRequest($authorization->fresh(), $f['admin']);

    // Allowing is not approving: the pair is still REJECTED until the doctor
    // actually asks again.
    expect($allowed->status)->toBe(DoctorDeviceAuthorization::STATUS_REJECTED)
        ->and($allowed->re_request_allowed_by)->toBe($f['admin']->id)
        // And the original refusal is still on the record.
        ->and($allowed->rejected_reason)->toBe('Salah perangkat.');

    $reopened = $f['service']->resolveOrRequest($f['doctor'], $f['device']);

    expect($reopened->status)->toBe(DoctorDeviceAuthorization::STATUS_PENDING)
        ->and($reopened->id)->toBe($authorization->id);
});

it('spends the allowance so a second rejection is not reopened by the first permission', function () {
    $f = authFixture();
    $authorization = $f['service']->resolveOrRequest($f['doctor'], $f['device']);

    $f['service']->reject($authorization, 'Pertama.', $f['admin']);
    $f['service']->allowReRequest($authorization->fresh(), $f['admin']);
    $f['service']->resolveOrRequest($f['doctor'], $f['device']);          // -> pending
    // The clock has one-second resolution in some drivers; make the second
    // rejection unambiguously later than the allowance.
    $this->travel(2)->seconds();
    $f['service']->reject($authorization->fresh(), 'Kedua.', $f['admin']); // -> rejected again

    $again = $f['service']->resolveOrRequest($f['doctor'], $f['device']);

    expect($again->status)->toBe(DoctorDeviceAuthorization::STATUS_REJECTED);
});

it('revokes an approved pair and treats revocation as terminal', function () {
    $f = authFixture();
    $authorization = $f['service']->resolveOrRequest($f['doctor'], $f['device']);
    $f['service']->approve($authorization, $f['admin']);

    $revoked = $f['service']->revoke($authorization->fresh(), 'Tablet hilang.', $f['admin']);

    expect($revoked->status)->toBe(DoctorDeviceAuthorization::STATUS_REVOKED)
        ->and($revoked->revoked_reason)->toBe('Tablet hilang.');

    // Neither an approver nor a login attempt may resurrect it.
    expect(fn () => $f['service']->approve($revoked->fresh(), $f['admin']))
        ->toThrow(ValidationException::class);

    expect(fn () => $f['service']->allowReRequest($revoked->fresh(), $f['admin']))
        ->toThrow(ValidationException::class);

    expect($f['service']->resolveOrRequest($f['doctor'], $f['device'])->status)
        ->toBe(DoctorDeviceAuthorization::STATUS_REVOKED);
});

it('refuses to approve when the doctor or the device is no longer fit', function () {
    $f = authFixture();

    $authorization = $f['service']->resolveOrRequest($f['doctor'], $f['device']);
    $f['doctor']->forceFill(['is_active' => false])->save();

    // The screen was rendered when everything was fine. Approval re-reads.
    expect(fn () => $f['service']->approve($authorization->fresh(), $f['admin']))
        ->toThrow(ValidationException::class);

    $f['doctor']->forceFill(['is_active' => true])->save();
    app(DoctorDeviceService::class)->revoke($f['device'], 'Hilang.', $f['admin']);

    expect(fn () => $f['service']->approve($authorization->fresh(), $f['admin']))
        ->toThrow(ValidationException::class);
});

it('refuses to approve a device that has never proved its key', function () {
    $f = authFixture();
    $f['device']->forceFill(['identity_state' => DoctorDevice::IDENTITY_UNVERIFIED])->save();

    $authorization = $f['service']->resolveOrRequest($f['doctor'], $f['device']->fresh());

    expect(fn () => $f['service']->approve($authorization, $f['admin']))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// One operator decision covers both halves
// ---------------------------------------------------------------------------

it('admits a provisional device in the same act as approving the doctor', function () {
    $f = authFixture();
    $f['device']->forceFill(['status' => DoctorDevice::STATUS_PENDING_APPROVAL])->save();

    $authorization = $f['service']->resolveOrRequest($f['doctor'], $f['device']->fresh());
    $f['service']->approve($authorization, $f['admin']);

    expect($f['device']->fresh()->status)->toBe(DoctorDevice::STATUS_ACTIVE);
});

it('does not let reactivate become a side door that admits an unapproved device', function () {
    $f = authFixture();
    $f['device']->forceFill(['status' => DoctorDevice::STATUS_PENDING_APPROVAL])->save();

    // `reactivate` exists to undo a DISABLE. If it also promoted
    // `pending_approval`, an operator could admit hardware nobody approved
    // without ever visiting the approval screen.
    expect(fn () => app(DoctorDeviceService::class)->reactivate($f['device']->fresh(), $f['admin']))
        ->toThrow(ValidationException::class);

    expect($f['device']->fresh()->status)->toBe(DoctorDevice::STATUS_PENDING_APPROVAL);
});

it('leaves a pending_approval device untrusted by every gate that matters', function () {
    $f = authFixture();
    $f['device']->forceFill(['status' => DoctorDevice::STATUS_PENDING_APPROVAL])->save();
    $device = $f['device']->fresh();

    expect($device->isActive())->toBeFalse()
        ->and(app(DoctorDeviceProofService::class)->isTrustworthy($device))->toBeFalse()
        ->and(app(DoctorAppLoginGate::class)->deviceUsable($device))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Audit
// ---------------------------------------------------------------------------

it('audits every decision without recording a credential or key material', function () {
    $f = authFixture();
    $authorization = $f['service']->resolveOrRequest($f['doctor'], $f['device'], User::factory()->create());
    $f['service']->approve($authorization, $f['admin']);
    $f['service']->revoke($authorization->fresh(), 'Selesai kontrak.', $f['admin']);

    $actions = AuditLog::query()
        ->where('entity_type', 'mst_doctor_device_authorizations')
        ->pluck('action')
        ->all();

    expect($actions)->toContain('DOCTOR_DEVICE_AUTHORIZATION_PENDING')
        ->toContain('DOCTOR_DEVICE_AUTHORIZATION_APPROVED')
        ->toContain('DOCTOR_DEVICE_AUTHORIZATION_REVOKED');

    $payloads = AuditLog::query()->get()->map(fn ($row) => json_encode([$row->old_values, $row->new_values]))->implode(' ');

    expect($payloads)->not->toContain('password')
        ->not->toContain('BEGIN')
        ->not->toContain((string) $f['device']->public_key);
});
