<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 PR-C — one classified cell of the
 * (doctor x trusted device) matrix.
 *
 * Immutable, and carrying IDS RATHER THAN MODELS on purpose. A plan is built
 * from one bounded set of reads and may be printed, hashed, serialised to JSON
 * and only then acted on. Holding Eloquent models would let a stale in-memory
 * row travel from the preview into the write, which is precisely the staleness
 * the apply path re-reads under a lock to defeat.
 *
 * The two id spaces are named in full because they are NOT interchangeable and
 * have already been confused once in this estate: `doctorId` is `mst_doctors.id`
 * and `userId` is `users.id`. On the live pilot drg Karmila is user 18 and
 * doctor 21.
 */
final class DoctorDeviceBulkAuthorizationPair
{
    /**
     * @param  int  $doctorId  mst_doctors.id — what an authorization row keys on
     * @param  int  $userId  users.id — what the Doctor role and enforcement key on
     * @param  string  $doctorName  for the operator's report only, never a predicate
     * @param  int  $deviceId  mst_doctor_devices.id
     * @param  string  $deviceName  free text; explicitly not a trust input
     * @param  string|null  $deviceBranchCode  REPORTED, never filtered on — see the class note on DoctorDeviceBulkAuthorizationPlan
     * @param  string  $bucket  one of DoctorDeviceBulkAuthorizationOutcome::BUCKET_*
     * @param  int|null  $authorizationId  the existing row, when the pair already has one
     * @param  string|null  $existingStatus  that row's status, verbatim
     */
    public function __construct(
        public readonly int $doctorId,
        public readonly int $userId,
        public readonly string $doctorName,
        public readonly int $deviceId,
        public readonly string $deviceName,
        public readonly ?string $deviceBranchCode,
        public readonly string $bucket,
        public readonly ?int $authorizationId = null,
        public readonly ?string $existingStatus = null,
    ) {}

    /** A pair this run intends to write to. Exactly buckets B and C. */
    public function isActionable(): bool
    {
        return $this->bucket === DoctorDeviceBulkAuthorizationOutcome::BUCKET_CREATE
            || $this->bucket === DoctorDeviceBulkAuthorizationOutcome::BUCKET_ADOPT_PENDING;
    }

    public function needsCreate(): bool
    {
        return $this->bucket === DoctorDeviceBulkAuthorizationOutcome::BUCKET_CREATE;
    }

    /**
     * The report row. Scalars only — this is printed, JSON-encoded and read by
     * more people than the tables behind it, so no model and no key material
     * may travel in it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'doctor_id' => $this->doctorId,
            'user_id' => $this->userId,
            'doctor_name' => $this->doctorName,
            'device_id' => $this->deviceId,
            'device_name' => $this->deviceName,
            'device_branch' => $this->deviceBranchCode,
            'bucket' => $this->bucket,
            'authorization_id' => $this->authorizationId,
            'existing_status' => $this->existingStatus,
        ];
    }
}
