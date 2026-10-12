<?php

namespace App\Modules\ClinicOversight\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicOversight\Interfaces\OversightMonitoringRepositoryInterface;
use App\Modules\ClinicOversight\Support\ClinicalDayWindow;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — read-only operational
 * monitoring: active dental rooms, patients per room, BILLED treatments per
 * room, and the visit queue of every branch.
 *
 * Nothing here can move a patient, call a queue number, change a status,
 * assign a room or a doctor: the module exposes no write route, and this
 * service composes SELECT-only repository calls. In particular it never calls
 * UserOnlineContextService, whose room resolution can write presence rows.
 */
class OversightMonitoringService
{
    public const STATUS_LABELS = [
        ClinicVisit::STATUS_REGISTERED => 'Terdaftar',
        ClinicVisit::STATUS_WAITING => 'Menunggu',
        ClinicVisit::STATUS_IN_PROGRESS => 'Dalam Pemeriksaan',
        ClinicVisit::STATUS_CASHIER_PENDING => 'Menunggu Kasir',
        ClinicVisit::STATUS_COMPLETED => 'Selesai',
        ClinicVisit::STATUS_CANCELLED => 'Dibatalkan',
    ];

    public const ROOM_TYPE_LABELS = [
        ClinicRoom::TYPE_TREATMENT_ROOM => 'Ruang Perawatan',
        ClinicRoom::TYPE_CONSULTATION_ROOM => 'Ruang Konsultasi',
    ];

    public const UNCLASSIFIED_TREATMENT_LABEL = 'Lainnya / Item Tidak Terklasifikasi';

    public function __construct(
        private readonly OversightMonitoringRepositoryInterface $monitoring,
        private readonly ClinicOversightScope $scope,
        private readonly ClinicalClock $clock,
    ) {}

    public function day(?string $requestedDate): ClinicalDayWindow
    {
        return ClinicalDayWindow::resolve($requestedDate, $this->clock);
    }

    /**
     * @return Collection<int, Branch>
     */
    public function branchOptions(User $user): Collection
    {
        return $this->scope->branchesFor($user, ClinicOversightScope::PERMISSION_MONITORING);
    }

    /**
     * @return list<int>
     */
    public function branchIdsFor(User $user, mixed $requestedBranch): array
    {
        return $this->scope->narrow(
            $this->scope->branchIdsFor($user, ClinicOversightScope::PERMISSION_MONITORING),
            $requestedBranch,
        );
    }

    /**
     * The active-room board: every active dental room with today's (or the
     * selected date's) visit counts by status.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rooms: Collection, counts: array<int, array<string, int>>, day: ClinicalDayWindow}
     */
    public function roomBoard(User $user, array $filters): array
    {
        $day = $this->day($filters['date'] ?? null);
        $branchIds = $this->branchIdsFor($user, $filters['branch_id'] ?? null);

        return [
            'rooms' => $this->monitoring->activeDentalRooms($branchIds),
            'counts' => $this->monitoring->roomVisitStatusCounts($branchIds, $day->date),
            'day' => $day,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function roomPatients(User $user, array $filters, ?int $perPage = 25): LengthAwarePaginator|Collection
    {
        $day = $this->day($filters['date'] ?? null);

        return $this->monitoring->roomPatients(
            $this->branchIdsFor($user, $filters['branch_id'] ?? null),
            $day->date,
            $filters,
            $perPage,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function roomTreatments(User $user, array $filters, ?int $perPage = 25): LengthAwarePaginator|Collection
    {
        $day = $this->day($filters['date'] ?? null);

        return $this->monitoring->roomTreatments(
            $this->branchIdsFor($user, $filters['branch_id'] ?? null),
            $day->date,
            $filters,
            $perPage,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function roomTreatmentSummary(User $user, array $filters): Collection
    {
        $day = $this->day($filters['date'] ?? null);

        return $this->monitoring->roomTreatmentSummary(
            $this->branchIdsFor($user, $filters['branch_id'] ?? null),
            $day->date,
            $filters,
        );
    }

    /**
     * Rooms offered by the room filter: active dental rooms of the branches.
     */
    public function roomOptions(User $user, mixed $requestedBranch): Collection
    {
        return $this->monitoring->activeDentalRooms($this->branchIdsFor($user, $requestedBranch));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function queue(User $user, array $filters, ?int $perPage = 50): LengthAwarePaginator|Collection
    {
        $day = $this->day($filters['date'] ?? null);

        return $this->monitoring->queue(
            $this->branchIdsFor($user, $filters['branch_id'] ?? null),
            $day->date,
            $filters,
            $perPage,
        );
    }

    /**
     * Per-branch queue summary: status counts for the date plus the stale
     * backlog (unfinished visits from earlier dates), kept separate so an old
     * unfinished visit is never read as part of today's queue.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function queueByBranch(User $user, array $filters): array
    {
        $day = $this->day($filters['date'] ?? null);
        $branchIds = $this->branchIdsFor($user, $filters['branch_id'] ?? null);
        $counts = $this->monitoring->queueStatusCountsByBranch($branchIds, $day->date);
        $stale = $this->monitoring->staleUnfinishedByBranch($branchIds, $day->date);

        $rows = [];
        foreach ($this->branchOptions($user) as $branch) {
            $id = (int) $branch->id;
            if (! in_array($id, $branchIds, true)) {
                continue;
            }

            $statusCounts = [];
            foreach (array_keys(self::STATUS_LABELS) as $status) {
                $statusCounts[$status] = $counts[$id][$status] ?? 0;
            }

            $rows[] = [
                'branch' => $branch,
                'counts' => $statusCounts,
                'total' => array_sum($statusCounts),
                'stale_unfinished' => $stale[$id] ?? 0,
            ];
        }

        return $rows;
    }

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? (string) $status;
    }
}
