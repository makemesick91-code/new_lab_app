<?php

namespace App\Modules\RME\Services;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 (rule 243 §2) — is this visit
 * still "in service"?
 *
 * A visit is in service while it is open (registered, waiting, in_progress or
 * cashier_pending) or when it was completed TODAY on the clinical calendar
 * (`ClinicalClock`, Asia/Makassar — never the UTC date of the stored instant). A
 * cancelled visit never opens a window, nor does a soft-deleted one, nor a
 * completed one without a completion instant (fail closed).
 *
 * The PHP predicate {@see isInService()} and its SQL twin
 * {@see constrainInService()} MUST stay mirror images: the per-record check and
 * the list scope are the same rule. Change both or neither.
 */
class DoctorEncounterWindow
{
    /** The statuses that keep a visit in service whatever its date. */
    public const OPEN_STATUSES = [
        ClinicVisit::STATUS_REGISTERED,
        ClinicVisit::STATUS_WAITING,
        ClinicVisit::STATUS_IN_PROGRESS,
        ClinicVisit::STATUS_CASHIER_PENDING,
    ];

    public function __construct(
        private readonly ClinicalClock $clock,
    ) {}

    public function isInService(ClinicVisit $visit): bool
    {
        if ($visit->trashed()) {
            return false;
        }

        if (in_array($visit->status, self::OPEN_STATUSES, true)) {
            return true;
        }

        if ($visit->status !== ClinicVisit::STATUS_COMPLETED || $visit->completed_at === null) {
            return false;
        }

        return $this->clock->toClinicalDateString($visit->completed_at) === $this->clock->todayString();
    }

    /**
     * The SQL twin of {@see isInService()} over the visit table aliased `$alias`.
     */
    public function constrainInService(EloquentBuilder|QueryBuilder $query, string $alias = 'trx_clinic_visits'): EloquentBuilder|QueryBuilder
    {
        [$from, $until] = $this->todayBoundsUtc();

        return $query
            ->whereNull($alias.'.deleted_at')
            ->where(function ($window) use ($alias, $from, $until) {
                $window->whereIn($alias.'.status', self::OPEN_STATUSES)
                    ->orWhere(function ($completedToday) use ($alias, $from, $until) {
                        $completedToday->where($alias.'.status', ClinicVisit::STATUS_COMPLETED)
                            ->where($alias.'.completed_at', '>=', $from)
                            ->where($alias.'.completed_at', '<', $until);
                    });
            });
    }

    /**
     * Today's clinical day as stored instants: [00:00 WITA, next 00:00 WITA) in
     * UTC, formatted the way the timestamps are stored (the application frame).
     *
     * @return array{0: string, 1: string}
     */
    public function todayBoundsUtc(): array
    {
        $start = $this->clock->today();

        return [
            $this->asStoredInstant($start),
            $this->asStoredInstant($start->addDay()),
        ];
    }

    private function asStoredInstant(CarbonImmutable $instant): string
    {
        return $instant->setTimezone('UTC')->format('Y-m-d H:i:s');
    }
}
