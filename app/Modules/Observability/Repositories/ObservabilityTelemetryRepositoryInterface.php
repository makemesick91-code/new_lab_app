<?php

namespace App\Modules\Observability\Repositories;

use App\Modules\Observability\Models\ObservabilityRequestEvent;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — the only door to the telemetry
 * tables. Every read is bounded (a window, a limit or a page); there is no
 * "load everything" method, and there must never be one.
 */
interface ObservabilityTelemetryRepositoryInterface
{
    /** @param array<string, mixed> $row */
    public function insertEvent(array $row): int;

    /** @param list<array<string, mixed>> $rows */
    public function insertSlowQueries(array $rows): void;

    /**
     * Latest event per user seen since $since (one row per user).
     *
     * @return Collection<int, ObservabilityRequestEvent>
     */
    public function latestEventPerUserSince(CarbonInterface $since): Collection;

    /** @return Collection<int, ObservabilityRequestEvent> */
    public function recentActivityForUser(int $userId, CarbonInterface $since, int $limit): Collection;

    /** @param array<string, mixed> $filters */
    public function paginateErrors(array $filters, int $perPage): LengthAwarePaginator;

    /** @param array<string, mixed> $filters */
    public function paginateSlowRequests(array $filters, int $perPage): LengthAwarePaginator;

    /** @param array<string, mixed> $filters */
    public function paginateSlowQueries(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Raw (fingerprint, duration, route) rows for aggregation, newest first,
     * capped at $cap so the aggregation can never read the whole table.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    public function slowQuerySamples(array $filters, int $cap): Collection;

    public function findEventByUuid(string $uuid): ?ObservabilityRequestEvent;

    /** @return Collection<int, object> */
    public function slowQueriesForRequest(string $requestId): Collection;

    public function countErrorsSince(CarbonInterface $since): int;

    public function countServerErrorsSince(CarbonInterface $since): int;

    public function countSlowRequestsSince(CarbonInterface $since): int;

    public function countTimeoutsSince(CarbonInterface $since): int;

    public function countSlowQueriesSince(CarbonInterface $since): int;

    /** @return array{hits: int, misses: int, requests: int} */
    public function cacheTotalsSince(CarbonInterface $since): array;

    /**
     * Event counts per UTC hour bucket ("Y-m-d H") since $since, aggregated in SQL.
     *
     * @param  'error'|'slow'|'slow_query'  $series
     * @return array<string, int>
     */
    public function hourlyCountsSince(string $series, CarbonInterface $since): array;

    /**
     * p95 request duration per UTC hour bucket. Exact on PostgreSQL; a capped,
     * explicitly flagged sample elsewhere.
     *
     * @return array{buckets: array<string, int>, sampled: bool}
     */
    public function hourlyP95Since(CarbonInterface $since, int $cap): array;

    /**
     * Distinct user ids that appear in telemetry since $since (filter options).
     *
     * @return list<int>
     */
    public function userIdsSeenSince(CarbonInterface $since): array;

    /**
     * Delete at most $batch expired rows per retention class.
     *
     * @param  array<string, CarbonInterface>  $cutoffs  access|error|slow_request|slow_query
     * @return array<string, int>
     */
    public function prune(array $cutoffs, int $batch): array;
}
