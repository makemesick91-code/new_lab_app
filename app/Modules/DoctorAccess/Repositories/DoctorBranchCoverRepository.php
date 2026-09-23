<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Repositories;

use App\Modules\DoctorAccess\Interfaces\DoctorBranchCoverRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorBranchCover;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DoctorBranchCoverRepository implements DoctorBranchCoverRepositoryInterface
{
    /**
     * @var array<int, string>
     */
    private const LIST_RELATIONS = [
        'doctor.user',
        'sourceHomeBranch',
        'targetBranch',
        'requester',
        'decidedBy',
    ];

    public function findById(int $id): ?DoctorBranchCover
    {
        return DoctorBranchCover::query()->find($id);
    }

    public function lockById(int $id): ?DoctorBranchCover
    {
        return DoctorBranchCover::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->first();
    }

    public function findPendingForDoctor(int $doctorId): ?DoctorBranchCover
    {
        return DoctorBranchCover::query()
            ->where('doctor_id', $doctorId)
            ->where('status', DoctorBranchCover::STATUS_PENDING)
            ->first();
    }

    /**
     * The initial status is written EXPLICITLY, for the same reason as the lock
     * request repository: `status` is not fillable, so a mass-assigned create
     * hands the caller a model with no status attribute while the row is
     * pending in the database.
     */
    public function create(array $attributes): DoctorBranchCover
    {
        $cover = new DoctorBranchCover;

        $cover->forceFill($attributes + [
            'status' => DoctorBranchCover::STATUS_PENDING,
        ])->save();

        return $cover;
    }

    public function update(DoctorBranchCover $cover, array $attributes): DoctorBranchCover
    {
        $cover->forceFill($attributes)->save();

        return $cover->refresh();
    }

    public function activeApprovedForDoctor(int $doctorId, CarbonInterface $at): ?DoctorBranchCover
    {
        $at = $this->asStoredInstant($at);

        return $this->approvedForDoctorQuery($doctorId)
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();
    }

    public function overlappingApproved(
        int $doctorId,
        CarbonInterface $starts,
        CarbonInterface $ends,
        ?int $excludeId = null,
    ): Collection {
        $starts = $this->asStoredInstant($starts);
        $ends = $this->asStoredInstant($ends);

        return $this->approvedForDoctorQuery($doctorId)
            ->when($excludeId !== null, fn (Builder $query) => $query->whereKeyNot($excludeId))
            ->where('starts_at', '<', $ends)
            ->where('ends_at', '>', $starts)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Put a caller's instant into the frame the COLUMNS are stored in, before it
     * becomes a query binding.
     *
     * THE TRAP THIS CLOSES, WHICH IS SILENT IN BOTH DIRECTIONS.
     * `starts_at` and `ends_at` hold UTC instants — DoctorBranchCoverPeriod
     * normalises every write, for the reason its own docblock gives — but
     * `Connection::prepareBindings()` renders a DateTimeInterface binding with
     * `$value->format($grammar->getDateFormat())`, which uses the timezone the
     * object is CARRYING. Hand these predicates a ClinicalClock (WITA) reading
     * of the very same instant and the binding is a string eight hours ahead of
     * the column, so `starts_at <= at < ends_at` quietly matches nothing: the
     * caller sees "no active cover" and every guard built on it returns
     * normally. That is exactly how the permanent-transfer block in
     * DoctorBranchLockApprovalService::approve() came to be dead code.
     *
     * Normalising HERE, at the one boundary where these instants stop being PHP
     * objects and become SQL, means no present or future caller has to know
     * about `prepareBindings()`. It converts a frame, never an instant, so it
     * cannot change which rows any correct caller already matched: a caller that
     * was already passing UTC is unaffected byte for byte.
     */
    private function asStoredInstant(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->utc();
    }

    public function pending(): Collection
    {
        return DoctorBranchCover::query()
            ->with(self::LIST_RELATIONS)
            ->where('status', DoctorBranchCover::STATUS_PENDING)
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get();
    }

    public function approvedForDoctor(int $doctorId): Collection
    {
        return $this->approvedForDoctorQuery($doctorId)
            ->with(self::LIST_RELATIONS)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();
    }

    public function recentlyDecided(int $limit = 50): Collection
    {
        return DoctorBranchCover::query()
            ->with(self::LIST_RELATIONS)
            ->whereNotNull('decided_at')
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The SQL half of the model predicate, and the only place it is written.
     *
     * {@see DoctorBranchCover::isApproved()} reads `status === 'approved' AND
     * cancelled_at === null`, and this clause says exactly the same thing in
     * SQL. Both halves are asserted equal by test: a cancel stamps the status
     * AND the timestamp, so either condition alone would be enough today, and
     * matching the model exactly is what keeps it that way.
     */
    private function approvedForDoctorQuery(int $doctorId): Builder
    {
        return DoctorBranchCover::query()
            ->where('doctor_id', $doctorId)
            ->where('status', DoctorBranchCover::STATUS_APPROVED)
            ->whereNull('cancelled_at');
    }
}
