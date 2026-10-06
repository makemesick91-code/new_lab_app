<?php

namespace App\Modules\Observability\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Observability\Models\ObservabilityRequestEvent;
use App\Modules\Observability\Repositories\ObservabilityTelemetryRepositoryInterface;
use App\Modules\Observability\Support\Percentile;
use App\Support\Health\HealthCheckService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Throwable;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — read models for the Observability
 * Console. Read-only: it never writes, never repairs, never reaches past the
 * telemetry repository and the existing ENT-8 health probes.
 *
 * Every value it hands a view is already a label or a number. Raw request
 * data never exists in telemetry to begin with (see TelemetryRedactor).
 */
class ObservabilityConsoleService
{
    public const PRESENCE_ONLINE = 'ONLINE';

    public const PRESENCE_IDLE = 'IDLE';

    public const PRESENCE_OFFLINE = 'OFFLINE';

    public function __construct(
        private readonly ObservabilityTelemetryRepositoryInterface $telemetry,
        private readonly HealthCheckService $health,
        private readonly CacheObservabilityService $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $hours = (int) config('observability_console.trend.hours', 24);
        $since = now()->subHours($hours);
        $presence = $this->presence();
        $cache = $this->cache->snapshot($since);

        return [
            'window_hours' => $hours,
            'telemetry_enabled' => (bool) config('observability_console.enabled', true),
            'health' => $this->health(),
            'kpis' => [
                'online_users' => $presence['counts'][self::PRESENCE_ONLINE],
                'idle_users' => $presence['counts'][self::PRESENCE_IDLE],
                'errors' => $this->telemetry->countErrorsSince($since),
                'server_errors' => $this->telemetry->countServerErrorsSince($since),
                'slow_requests' => $this->telemetry->countSlowRequestsSince($since),
                'timeouts' => $this->telemetry->countTimeoutsSince($since),
                'slow_queries' => $this->telemetry->countSlowQueriesSince($since),
                'cache_hit_rate' => $cache['app']['hit_rate'],
            ],
            'trends' => $this->trends($since, $hours),
        ];
    }

    /**
     * Application, PostgreSQL, Redis, Queue, Storage — each from a real probe
     * (the ENT-8 HealthCheckService), never a hardcoded GREEN.
     *
     * @return list<array{name: string, status: string, detail: string}>
     */
    public function health(): array
    {
        $rows = [[
            'name' => 'Application',
            'status' => 'ok',
            'detail' => 'Melayani request ini · env '.app()->environment().' · debug '.(config('app.debug') ? 'ON' : 'OFF'),
        ]];

        try {
            $details = $this->health->componentDetails();
        } catch (Throwable) {
            $details = [];
        }

        $labels = ['database' => 'PostgreSQL', 'queue' => 'Queue', 'storage' => 'Storage', 'cache' => 'Cache'];
        foreach ($labels as $key => $label) {
            if (isset($details[$key])) {
                $rows[] = [
                    'name' => $key === 'database' ? 'Database ('.config('database.default').')' : $label,
                    'status' => (string) $details[$key]['status'],
                    'detail' => (string) $details[$key]['detail'],
                ];
            } else {
                $rows[] = ['name' => $label, 'status' => 'unknown', 'detail' => 'Probe tidak tersedia.'];
            }
        }

        $redis = $this->cache->redisInfo();
        $rows[] = [
            'name' => 'Redis',
            'status' => match ($redis['status']) {
                CacheObservabilityService::REDIS_CONNECTED => 'ok',
                CacheObservabilityService::REDIS_UNAVAILABLE => 'down',
                default => 'not_in_use',
            },
            'detail' => $redis['status'] === CacheObservabilityService::REDIS_CONNECTED
                ? 'Terhubung'.($redis['redis_version'] ? ' · v'.$redis['redis_version'] : '')
                : (string) $redis['reason'],
        ];

        return $rows;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function presence(): array
    {
        $windowHours = (int) config('observability_console.presence.window_hours', 24);
        $latest = $this->telemetry->latestEventPerUserSince(now()->subHours($windowHours));

        $users = $this->usersById($latest->pluck('user_id')->filter()->all());
        $branches = $this->branchesById($latest->pluck('branch_id')->filter()->all());

        $counts = [self::PRESENCE_ONLINE => 0, self::PRESENCE_IDLE => 0, self::PRESENCE_OFFLINE => 0];
        $rows = [];

        foreach ($latest as $event) {
            $status = $this->presenceStatus($event);
            $counts[$status]++;
            $rows[] = [
                'user_id' => (int) $event->user_id,
                'user' => $users[$event->user_id] ?? ('User #'.$event->user_id),
                'role' => $event->user_role,
                'branch' => $branches[$event->branch_id] ?? null,
                'status' => $status,
                'activity' => $event->activity,
                'last_activity' => $event->occurred_at,
            ];
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    public function presenceStatus(ObservabilityRequestEvent $event): string
    {
        if ($event->route_name === 'logout') {
            return self::PRESENCE_OFFLINE;
        }

        $age = max(0, (int) $event->occurred_at->diffInSeconds(now(), true));

        return match (true) {
            $age <= (int) config('observability_console.presence.online_seconds', 120) => self::PRESENCE_ONLINE,
            $age <= (int) config('observability_console.presence.idle_seconds', 300) => self::PRESENCE_IDLE,
            default => self::PRESENCE_OFFLINE,
        };
    }

    /** @return Collection<int, ObservabilityRequestEvent> */
    public function recentActivity(int $userId): Collection
    {
        $hours = (int) config('observability_console.retention.access_hours', 72);

        return $this->telemetry->recentActivityForUser(
            $userId,
            now()->subHours($hours),
            (int) config('observability_console.presence.recent_activity_limit', 50),
        );
    }

    /** @param array<string, mixed> $filters */
    public function errors(array $filters): LengthAwarePaginator
    {
        return $this->telemetry->paginateErrors($filters, $this->perPage());
    }

    /** @param array<string, mixed> $filters */
    public function slowRequests(array $filters): LengthAwarePaginator
    {
        return $this->telemetry->paginateSlowRequests($filters, $this->perPage());
    }

    /** @param array<string, mixed> $filters */
    public function slowQueries(array $filters): LengthAwarePaginator
    {
        return $this->telemetry->paginateSlowQueries($filters, $this->perPage());
    }

    /** An ERROR event by its public uuid, or null (never a non-error event). */
    public function errorEvent(string $uuid): ?ObservabilityRequestEvent
    {
        $event = $this->telemetry->findEventByUuid($uuid);

        return $event !== null && $event->is_error ? $event : null;
    }

    /** @return Collection<int, object> */
    public function slowQueriesForRequest(?string $requestId): Collection
    {
        return $requestId !== null && $requestId !== '' ? $this->telemetry->slowQueriesForRequest($requestId) : collect();
    }

    private function perPage(): int
    {
        return max(5, min(100, (int) config('observability_console.pagination', 25)));
    }

    /**
     * Rows for a paginator, decorated with user + branch labels in TWO
     * queries for the whole page (never one per row).
     *
     * @param  iterable<object>  $events
     * @return array{users: array<int, string>, branches: array<int, string>}
     */
    public function labelsFor(iterable $events): array
    {
        $collection = collect($events);

        return [
            'users' => $this->usersById($collection->pluck('user_id')->filter()->all()),
            'branches' => $this->branchesById($collection->pluck('branch_id')->filter()->all()),
        ];
    }

    /**
     * Slow-query aggregation per fingerprint: count, avg, p95, max, routes.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function slowQueryAggregates(array $filters, int $cap = 5000): array
    {
        $samples = $this->telemetry->slowQuerySamples($filters, $cap);

        return $samples->groupBy('fingerprint')
            ->map(function (Collection $rows, string $fingerprint) {
                $durations = $rows->pluck('duration_ms')->map(fn ($d) => (int) $d)->sort()->values();

                return [
                    'fingerprint' => $fingerprint,
                    'count' => $durations->count(),
                    'avg_ms' => (int) round($durations->avg() ?? 0),
                    'p95_ms' => self::percentile($durations->all(), 95),
                    'max_ms' => (int) $durations->max(),
                    'routes' => $rows->pluck('route_name')->filter()->unique()->take(5)->values()->all(),
                    'requests' => $rows->pluck('request_id')->filter()->unique()->count(),
                ];
            })
            ->sortByDesc(fn ($row) => $row['count'] * $row['avg_ms'])
            ->take(20)
            ->values()
            ->all();
    }

    /**
     * Nearest-rank percentile over a sorted list.
     *
     * @param  list<int>  $sorted
     */
    public static function percentile(array $sorted, int $p): int
    {
        return Percentile::nearestRank($sorted, $p);
    }

    /**
     * What the application CAN and CANNOT see about timeouts — stated, never
     * implied.
     *
     * @return array<string, mixed>
     */
    public function timeoutVisibility(): array
    {
        $since = now()->subHours((int) config('observability_console.retention.slow_request_hours', 336));

        return [
            'php_max_execution_time' => (string) ini_get('max_execution_time'),
            'captured_timeouts' => $this->telemetry->countTimeoutsSince($since),
        ];
    }

    /**
     * @return array{users: array<int, string>, branches: array<int, string>, routes_hint: string}
     */
    public function filterOptions(): array
    {
        $since = now()->subHours((int) config('observability_console.retention.error_hours', 720));

        return [
            'users' => $this->usersById($this->telemetry->userIdsSeenSince($since)),
            'branches' => Branch::query()->orderBy('code')->pluck('code', 'id')->map(fn ($c) => (string) $c)->all(),
            'routes_hint' => 'Awalan nama route, mis. rme.visits',
        ];
    }

    /**
     * @return array<string, list<array{label: string, value: int}>>
     */
    private function trends(CarbonInterface $since, int $hours): array
    {
        $tz = (string) config('clinical.timezone', 'Asia/Makassar');
        $buckets = [];
        $start = $since->copy()->startOfHour();
        for ($i = 0; $i <= $hours; $i++) {
            $buckets[$start->copy()->addHours($i)->format('Y-m-d H')] = $start->copy()->addHours($i);
        }

        $count = fn (array $counts): array => $this->series($counts, $buckets, $tz);

        $p95 = $this->telemetry->hourlyP95Since($since, (int) config('observability_console.trend.max_latency_samples', 20000));

        return [
            'errors' => $count($this->telemetry->hourlyCountsSince('error', $since)),
            'slow_requests' => $count($this->telemetry->hourlyCountsSince('slow', $since)),
            'slow_queries' => $count($this->telemetry->hourlyCountsSince('slow_query', $since)),
            'p95_ms' => $this->series($p95['buckets'], $buckets, $tz),
            'p95_sampled' => $p95['sampled'],
        ];
    }

    /**
     * @param  array<string, int>  $values
     * @param  array<string, CarbonInterface>  $buckets
     * @return list<array{label: string, value: int}>
     */
    private function series(array $values, array $buckets, string $tz): array
    {
        $out = [];
        foreach ($buckets as $key => $at) {
            $out[] = ['label' => $at->copy()->setTimezone($tz)->format('H:00'), 'value' => (int) ($values[$key] ?? 0)];
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function usersById(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function branchesById(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        return Branch::query()->whereIn('id', $ids)->pluck('code', 'id')->map(fn ($c) => (string) $c)->all();
    }
}
