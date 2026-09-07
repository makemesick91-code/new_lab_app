<?php

namespace App\Modules\DoctorDevice\Interfaces;

use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use Illuminate\Support\Collection;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — credential reads, behind the canonical boundary.
 *
 * Every method here answers a question about ONE credential or the credentials
 * of ONE device. There is deliberately no "all credentials" accessor: a
 * credential list is a map of the clinic's hardware estate, and the login path
 * has no reason to be able to enumerate it.
 */
interface DoctorDeviceWebAuthnCredentialRepositoryInterface
{
    public function findByCredentialId(string $credentialId): ?DoctorDeviceWebAuthnCredential;

    /** @return Collection<int, DoctorDeviceWebAuthnCredential> */
    public function usableForDevice(int $doctorDeviceId): Collection;

    /**
     * Credentials a given doctor could legitimately assert with.
     *
     * Scoped through ACTIVE authorizations and USABLE devices, so the
     * allowCredentials list handed to a browser never advertises a credential
     * the doctor would be refused for anyway.
     *
     * @return Collection<int, DoctorDeviceWebAuthnCredential>
     */
    public function usableForDoctor(int $doctorId): Collection;
}
