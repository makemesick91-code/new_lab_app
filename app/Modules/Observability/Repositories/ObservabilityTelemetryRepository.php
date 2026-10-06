<?php

namespace App\Modules\Observability\Repositories;

use App\Modules\Observability\Models\ObservabilityRequestEvent;
use App\Modules\Observability\Models\ObservabilitySlowQuery;
use App\Modules\Observability\Support\Percentile;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — telemetry persistence and bounded
 * read models. Every query is PostgreSQL + SQLite portable: time bucketing is
 * done in PHP rather than with a driver-specific date function.
 */
class ObservabilityTelemetryRepository implements ObservabilityTelemetryRepositoryInterface
{
    public function insertEvent(array $row): int
    {
        return (int) DB::table('sys_obs_request_events')->insertGetId($row);
    }

    public function insertSlowQueries(array $rows): void
    {
        if ($rows !== []) {
            DB::table('sys_obs_slow_queries')->insert($rows);
        }
    }

    public function latestEventPerUserSince(CarbonInterface $since): Collection
    {
        // One row per user: the event with the highest id (ids are monotonic
        // with occurred_at for a single writer path). One query, no N+1.
        $latestIds = DB::table('sys_obs_request_events')
            ->selectRaw('MAX(id) as id')
            ->whereNotNull('user_id')
            ->where('occurred_at', '>=', $since)
            ->groupBy('user_id');

        return ObservabilityRequestEvent::query()
            ->whereIn('id', $latestIds)
            ->orderByDesc('occurred_at')
            ->get();
    }

    public function recentActivityForUser(int $userId, CarbonInterface $since, int $limit): Collection
    {
        return ObservabilityRequestEvent::query()
            ->where('user_id', $userId)
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function paginateErrors(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->eventQuery($filters)->where('is_error', true);

        if (! empty($filters['status'])) {
            $query->where('status_code', (int) $filters['status']);
        }

        return $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    public function paginateSlowRequests(array $filters, int $perPage): LengthAwarePaginator
    {
        // A timeout is always also is_slow, so one indexed predicate suffices.
        $query = $this->eventQuery($filters)->where('is_slow', true);

        if (! empty($filters['category'])) {
            $query->where('latency_category', (string) $filters['category']);
        }

        return $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    public function paginateSlowQueries(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->slowQueryQuery($filters)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function slowQuerySamples(array $filters, int $cap): Collection
    {
        return $this->slowQueryQuery($filters)
            ->toBase()
            ->select(['fingerprint', 'duration_ms', 'route_name', 'request_id'])
            ->orderByDesc('occurred_at')
            ->limit($cap)
            ->get();
    }

    public function findEventByUuid(string $uuid): ?ObservabilityRequestEvent
    {
        return ObservabilityRequestEvent::query()->where('uuid', $uuid)->first();
    }

    public function slowQueriesForRequest(string $requestId): Collection
    {
        return DB::table('sys_obs_slow_queries')
            ->select(['fingerprint', 'sql_normalized', 'duration_ms', 'severity'])
            ->where('request_id', $requestId)
            ->orderByDesc('duration_ms')
            ->limit(50)
            ->get();
    }

    public function countErrorsSince(CarbonInterface $since): int
    {
        return DB::table('sys_obs_request_events')->where('is_error', true)->where('occurred_at', '>=', $since)->count();
    }

    public function countServerErrorsSince(CarbonInterface $since): int
    {
        return DB::table('sys_obs_request_events')
            ->where('is_error', true)
            ->where('status_code', '>=', 500)
            ->where('occurred_at', '>=', $since)
            ->count();
    }

    public function countSlowRequestsSince(CarbonInterface $since): int
    {
        return DB::table('sys_obs_request_events')->where('is_slow', true)->where('occurred_at', '>=', $since)->count();
    }

    public function countTimeoutsSince(CarbonInterface $since): int
    {
        return DB::table('sys_obs_request_events')->where('is_timeout', true)->where('occurred_at', '>=', $since)->count();
    }

    public function countSlowQueriesSince(CarbonInterface $since): int
    {
        return DB::table('sys_obs_slow_queries')->where('occurred_at', '>=', $since)->count();
    }

    public function cacheTotalsSince(CarbonInterface $since): array
    {
        $row = DB::table('sys_obs_request_events')
            ->selectRaw('COALESCE(SUM(cache_hits), 0) as hits, COALESCE(SUM(cache_misses), 0) as misses, COUNT(*) as requests')
            ->where('occurred_at', '>=', $since)
            ->first();

        return [
            'hits' => (int) ($row->hits ?? 0),
            'misses' => (int) ($row->misses ?? 0),
            'requests' => (int) ($row->requests ?? 0),
        ];
    }

    public function hourlyCountsSince(string $series, CarbonInterface $since): array
    {
        $query = $series === 'slow_query'
            ? DB::table('sys_obs_slow_queries')
            : DB::table('sys_obs_request_events')->where($series === 'error' ? 'is_error' : 'is_slow', true);

        $bucket = $this->hourBucketExpression();

        // Aggregated in SQL: exact for any volume, and the console never pulls
        // individual rows into PHP to draw a trend.
        return $query->where('occurred_at', '>=', $since)
            ->selectRaw($bucket.' as bucket, COUNT(*) as total')
            ->groupByRaw($bucket)
            ->pluck('total', 'bucket')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    public function hourlyP95Since(CarbonInterface $since, int $cap): array
    {
        $bucket = $this->hourBucketExpression();

        if (DB::connection()->getDriverName() === 'pgsql') {
            $rows = DB::table('sys_obs_request_events')
                ->where('occurred_at', '>=', $since)
                ->selectRaw($bucket.' as bucket, percentile_disc(0.95) WITHIN GROUP (ORDER BY duration_ms) as p95')
                ->groupByRaw($bucket)
                ->pluck('p95', 'bucket')
                ->map(fn ($v) => (int) $v)
                ->all();

            return ['buckets' => $rows, 'sampled' => false];
        }

        // Portable fallback (SQLite in tests / dev): newest $cap samples; the
        // result says so, and buckets older than the oldest sample are marked.
        $samples = DB::table('sys_obs_request_events')
            ->where('occurred_at', '>=', $since)
            ->selectRaw($bucket.' as bucket, duration_ms')
            ->orderByDesc('occurred_at')
            ->limit($cap + 1)
            ->get();

        $sampled = $samples->count() > $cap;
        $grouped = [];
        foreach ($samples->take($cap) as $row) {
            $grouped[(string) $row->bucket][] = (int) $row->duration_ms;
        }

        $out = [];
        foreach ($grouped as $key => $values) {
            sort($values);
            $out[$key] = Percentile::nearestRank($values, 95);
        }

        return ['buckets' => $out, 'sampled' => $sampled];
    }

    private function hourBucketExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char(occurred_at, 'YYYY-MM-DD HH24')",
            'mysql', 'mariadb' => "DATE_FORMAT(occurred_at, '%Y-%m-%d %H')",
            default => "strftime('%Y-%m-%d %H', occurred_at)",
        };
    }

    public function userIdsSeenSince(CarbonInterface $since): array
    {
        return DB::table('sys_obs_request_events')
            ->whereNotNull('user_id')
            ->where('occurred_at', '>=', $since)
            ->distinct()
            ->limit(500)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function prune(array $cutoffs, int $batch): array
    {
        $events = DB::table('sys_obs_request_events');

        // A row lives for the LONGEST window that applies to it: every row is
        // an access row, so the access window always applies too.
        $earliest = fn (CarbonInterface ...$cuts) => collect($cuts)->sortBy(fn ($c) => $c->getTimestamp())->first();
        $slowCut = $earliest($cutoffs['slow_request'], $cutoffs['access']);
        $errorCut = $earliest($cutoffs['error'], $cutoffs['slow_request'], $cutoffs['access']);
        $errorOnlyCut = $earliest($cutoffs['error'], $cutoffs['access']);

        $classes = [
            'access' => fn () => (clone $events)->where('is_error', false)->where('is_slow', false)
                ->where('occurred_at', '<', $cutoffs['access']),
            'slow_request' => fn () => (clone $events)->where('is_error', false)->where('is_slow', true)
                ->where('occurred_at', '<', $slowCut),
            'error' => fn () => (clone $events)->where('is_error', true)
                ->where(fn ($q) => $q->where(fn ($w) => $w->where('is_slow', true)->where('occurred_at', '<', $errorCut))
                    ->orWhere(fn ($w) => $w->where('is_slow', false)->where('occurred_at', '<', $errorOnlyCut))),
        ];

        $deleted = [];
        foreach ($classes as $class => $build) {
            $ids = $build()->orderBy('id')->limit($batch)->pluck('id')->all();
            $deleted[$class] = $ids === [] ? 0 : DB::table('sys_obs_request_events')->whereIn('id', $ids)->delete();
        }

        $sqIds = DB::table('sys_obs_slow_queries')->where('occurred_at', '<', $cutoffs['slow_query'])
            ->orderBy('id')->limit($batch)->pluck('id')->all();
        $deleted['slow_query'] = $sqIds === [] ? 0 : DB::table('sys_obs_slow_queries')->whereIn('id', $sqIds)->delete();

        return $deleted;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ObservabilityRequestEvent>
     */
    private function eventQuery(array $filters): Builder
    {
        $query = ObservabilityRequestEvent::query();

        if (! empty($filters['from'])) {
            $query->where('occurred_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('occurred_at', '<=', $filters['to']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }
        if (! empty($filters['route'])) {
            // A prefix match on a route NAME (an identifier, never user text
            // from a record). LIKE metacharacters are escaped.
            $query->whereRaw("route_name LIKE ? ESCAPE '!'", [$this->likePrefix((string) $filters['route'])]);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ObservabilitySlowQuery>
     */
    private function slowQueryQuery(array $filters): Builder
    {
        $query = ObservabilitySlowQuery::query();

        if (! empty($filters['from'])) {
            $query->where('occurred_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('occurred_at', '<=', $filters['to']);
        }
        if (! empty($filters['route'])) {
            $query->whereRaw("route_name LIKE ? ESCAPE '!'", [$this->likePrefix((string) $filters['route'])]);
        }
        if (! empty($filters['min_ms'])) {
            $query->where('duration_ms', '>=', (int) $filters['min_ms']);
        }
        if (! empty($filters['fingerprint'])) {
            $query->where('fingerprint', (string) $filters['fingerprint']);
        }

        return $query;
    }

    private function likePrefix(string $value): string
    {
        // Escape with "!" — never a backslash (a backslash-closed SQL literal
        // breaks PDO placeholder parsing on pgsql + PHP 8.3).
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }
}
