<?php

namespace App\Modules\Observability\Services;

use App\Models\User;
use App\Modules\Observability\Repositories\ObservabilityTelemetryRepositoryInterface;
use App\Modules\Observability\Support\LatencyCategory;
use App\Modules\Observability\Support\TelemetryCollector;
use App\Modules\Observability\Support\TelemetryRedactor;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — turns one finished HTTP request into
 * at most one telemetry event (+ its slow queries).
 *
 * CALLED FROM terminate(), AFTER THE RESPONSE HAS BEEN SENT. Nothing here may
 * add latency the user feels, and nothing here may ever throw: every path is
 * wrapped, and a telemetry failure is swallowed rather than reported (a
 * report would itself be telemetry — the feedback loop rule).
 *
 * WHAT IS RECORDED:
 *   - every authenticated request (presence + recent activity),
 *   - every error (status in the configured list, or any 5xx),
 *   - every slow request (WATCH and above),
 *   - guest requests ONLY when they are errors or slow, rate-capped.
 */
class RequestTelemetryRecorder
{
    public const ATTR_STARTED = 'obs.started_at';

    public const ATTR_DURATION = 'obs.duration_ms';

    public const ATTR_RECORDED = 'obs.recorded';

    public function __construct(
        private readonly ObservabilityTelemetryRepositoryInterface $telemetry,
        private readonly TelemetryCollector $collector,
        private readonly TelemetryRedactor $redactor,
        private readonly TelemetryActorResolver $actors,
        private readonly ObservabilityTelemetryRetention $retention,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('observability_console.enabled', true);
    }

    public function isExcluded(Request $request): bool
    {
        $exclude = (array) config('observability_console.exclude', []);
        $path = ltrim($request->path(), '/');

        // An entry ending in "/" is a PREFIX ("health/"); anything else is an
        // EXACT path ("up") — so "uploads/x" is never silently excluded.
        foreach ((array) ($exclude['path_prefixes'] ?? []) as $entry) {
            $entry = (string) $entry;
            if (str_ends_with($entry, '/') ? str_starts_with($path, $entry) : $path === $entry) {
                return true;
            }
        }

        $routeName = $request->route()?->getName();

        return $routeName !== null && in_array($routeName, (array) ($exclude['route_names'] ?? []), true);
    }

    public function record(Request $request, Response $response): void
    {
        if (! $this->enabled() || $request->attributes->get(self::ATTR_RECORDED) === true) {
            return;
        }

        try {
            $this->collector->startFlush();
            $this->persist($request, $response->getStatusCode(), $this->collector->exception(), false);
        } catch (Throwable) {
            // Telemetry never breaks, delays or reports from a request.
        } finally {
            $request->attributes->set(self::ATTR_RECORDED, true);
            $this->collector->end();
        }
    }

    /**
     * A PHP fatal (most usefully "Maximum execution time exceeded") ends the
     * request before terminate() can run, so it is recorded at the moment it
     * is reported instead. It is the only timeout the APPLICATION can see:
     * proxy (nginx 502/504) and client timeouts never reach Laravel.
     */
    public function recordFatal(Throwable $exception): void
    {
        if (! $this->enabled() || ! $this->collector->isActive() || ! app()->bound('request')) {
            return;
        }

        $request = app('request');
        if (! $request instanceof Request || $request->attributes->get(self::ATTR_RECORDED) === true) {
            return;
        }

        try {
            $this->collector->startFlush();
            $timeout = str_contains($exception->getMessage(), 'Maximum execution time');
            $this->persist($request, 500, $exception, $timeout);
        } catch (Throwable) {
            // see record()
        } finally {
            $request->attributes->set(self::ATTR_RECORDED, true);
            $this->collector->end();
        }
    }

    private function persist(Request $request, int $responseStatus, ?Throwable $exception, bool $timeout): void
    {
        if ($this->isExcluded($request)) {
            return;
        }

        // A logout request ends with no authenticated user, yet it is the
        // event that should mark the user OFFLINE; the Logout listener noted
        // who it was.
        $user = $this->authenticatedUser() ?? $this->collector->loggedOutUser();
        $statusCode = $this->effectiveStatus($responseStatus, $exception);
        $errorStatuses = array_map('intval', (array) config('observability_console.error_statuses', []));
        $isError = $timeout || $statusCode >= 500 || in_array($statusCode, $errorStatuses, true);

        $durationMs = $this->durationMs($request);
        $category = $timeout ? LatencyCategory::TIMEOUT : LatencyCategory::forDuration($durationMs);
        $isSlow = $timeout || LatencyCategory::isSlow($category);

        if ($user === null && ! $isError && ! $isSlow) {
            return;
        }

        if ($user === null && ! $this->guestBudgetAvailable()) {
            return;
        }

        $actor = $user !== null ? $this->actors->contextFor($user) : ['role' => null, 'branch_id' => null];
        $routeName = $request->route()?->getName();
        $pathTemplate = $this->redactor->pathTemplate($request);
        $requestId = $this->requestId($request);
        $now = now();

        $reportable = $isError ? $exception : null;

        $eventId = $this->telemetry->insertEvent([
            'uuid' => (string) Str::uuid(),
            'occurred_at' => $now,
            'request_id' => $requestId,
            'user_id' => $user?->getKey(),
            'user_role' => $actor['role'],
            'branch_id' => $actor['branch_id'],
            'method' => substr($request->method(), 0, 8),
            'route_name' => $routeName !== null ? substr($routeName, 0, 160) : null,
            'path_template' => $pathTemplate,
            'activity' => $this->activity($request->method(), $routeName, $pathTemplate),
            'status_code' => $statusCode,
            'response_status' => $responseStatus,
            'duration_ms' => $durationMs,
            'db_time_ms' => (int) round($this->collector->dbTimeMs()),
            'query_count' => $this->collector->queryCount(),
            'cache_hits' => $this->collector->cacheHits(),
            'cache_misses' => $this->collector->cacheMisses(),
            'latency_category' => $category,
            'is_error' => $isError,
            'is_slow' => $isSlow,
            'is_timeout' => $timeout,
            'exception_class' => $reportable !== null ? substr($this->exceptionClass($reportable), 0, 191) : null,
            'exception_message' => $reportable !== null ? $this->redactor->exceptionMessage($reportable, $pathTemplate) : null,
            'exception_file' => $reportable !== null ? $this->redactor->relativeFile($reportable->getFile()) : null,
            'exception_line' => $reportable?->getLine(),
            'trace_summary' => $reportable !== null && $statusCode >= 500
                ? json_encode($this->redactor->traceSummary($reportable))
                : null,
        ]);

        $slowRows = [];
        foreach ($this->collector->slowQueries() as $query) {
            $normalized = $this->redactor->normalizeSql($query['sql']);
            $duration = (int) round($query['time_ms']);
            $slowRows[] = [
                'occurred_at' => $now,
                'request_id' => $requestId,
                'request_event_id' => $eventId,
                'user_id' => $user?->getKey(),
                'branch_id' => $actor['branch_id'],
                'route_name' => $routeName !== null ? substr($routeName, 0, 160) : null,
                'connection' => $query['connection'] !== null ? substr($query['connection'], 0, 32) : null,
                'fingerprint' => $this->redactor->fingerprint($normalized),
                'sql_normalized' => $normalized,
                'duration_ms' => $duration,
                'severity' => LatencyCategory::slowQuerySeverity($duration),
            ];
        }
        $this->telemetry->insertSlowQueries($slowRows);

        $this->retention->maybePrune();
    }

    /**
     * Only a user the guard ALREADY resolved during the request. Resolving it
     * here could read a recaller cookie and log someone in from terminate().
     */
    private function authenticatedUser(): ?User
    {
        try {
            $guard = Auth::guard();
            if (method_exists($guard, 'hasUser') && $guard->hasUser()) {
                $user = $guard->user();

                return $user instanceof User ? $user : null;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * The status the console reports. A web validation failure is delivered
     * as a 302 redirect and an unauthenticated hit as a 302 to login; both
     * are recorded as what actually happened (422 / 401). The real response
     * status is stored beside it.
     */
    private function effectiveStatus(int $responseStatus, ?Throwable $exception): int
    {
        return match (true) {
            $exception instanceof ValidationException => 422,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => $responseStatus,
        };
    }

    private function exceptionClass(Throwable $exception): string
    {
        // A rendered AuthorizationException arrives wrapped in
        // AccessDeniedHttpException; the original class is the useful one.
        $previous = $exception->getPrevious();

        return $exception instanceof HttpExceptionInterface && $previous !== null
            ? $previous::class
            : $exception::class;
    }

    private function durationMs(Request $request): int
    {
        $duration = $request->attributes->get(self::ATTR_DURATION);
        if (is_int($duration)) {
            return $duration;
        }

        $start = defined('LARAVEL_START') ? (float) LARAVEL_START : (float) $request->attributes->get(self::ATTR_STARTED, microtime(true));

        return max(0, (int) round((microtime(true) - $start) * 1000));
    }

    private function requestId(Request $request): string
    {
        $id = $request->attributes->get('request_id');

        return is_string($id) && $id !== '' ? substr($id, 0, 80) : (string) Str::uuid();
    }

    private function activity(string $method, ?string $routeName, string $pathTemplate): string
    {
        $verb = match (strtoupper($method)) {
            'GET', 'HEAD' => 'Lihat',
            'POST' => 'Kirim',
            'PUT', 'PATCH' => 'Ubah',
            'DELETE' => 'Hapus',
            default => strtoupper($method),
        };

        return substr($verb.' '.($routeName ?? $pathTemplate), 0, 160);
    }

    /**
     * Guest events are capped per minute so a scanner cannot flood the table.
     */
    private function guestBudgetAvailable(): bool
    {
        $limit = (int) config('observability_console.guest_events_per_minute', 60);
        if ($limit <= 0) {
            return false;
        }

        try {
            $key = 'obs:guest-budget:'.now()->format('YmdHi');
            Cache::add($key, 0, 120);

            return (int) Cache::increment($key) <= $limit;
        } catch (Throwable) {
            return false;
        }
    }
}
