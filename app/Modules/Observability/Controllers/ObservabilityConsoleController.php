<?php

namespace App\Modules\Observability\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Observability\Requests\ObservabilityFilterRequest;
use App\Modules\Observability\Services\CacheObservabilityService;
use App\Modules\Observability\Services\ObservabilityConsoleService;
use App\Support\DeveloperConsole\DeveloperConsoleService;
use Illuminate\View\View;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — the Observability Console pages.
 *
 * Thin: authorize (route middleware + FormRequest), audit the view through the
 * existing ENT-7 audit path, ask the read service, render. GET only — the
 * console never exposes a mutating route (ENT7-DC001).
 */
class ObservabilityConsoleController extends Controller
{
    public function __construct(
        private readonly ObservabilityConsoleService $console,
        private readonly CacheObservabilityService $cache,
        private readonly DeveloperConsoleService $audit,
    ) {}

    public function overview(ObservabilityFilterRequest $request): View
    {
        $this->audit->recordAccess($request->user(), $request->ip());

        return view('dev-console.overview', ['data' => $this->console->overview()]);
    }

    public function liveUsers(ObservabilityFilterRequest $request): View
    {
        $this->audit->recordAccess($request->user(), $request->ip());
        $filters = $request->filters();
        $selected = isset($filters['user']) ? (int) $filters['user'] : null;

        return view('dev-console.live-users', [
            'presence' => $this->console->presence(),
            'selectedUserId' => $selected,
            'recent' => $selected !== null ? $this->console->recentActivity($selected) : collect(),
        ]);
    }

    public function errors(ObservabilityFilterRequest $request): View
    {
        $this->audit->recordAccess($request->user(), $request->ip());
        $events = $this->console->errors($request->filters());

        return view('dev-console.errors', [
            'events' => $events,
            'labels' => $this->console->labelsFor($events->items()),
            'options' => $this->console->filterOptions(),
        ]);
    }

    public function errorShow(ObservabilityFilterRequest $request, string $event): View
    {
        $this->audit->recordAccess($request->user(), $request->ip());
        $record = $this->console->errorEvent($event);
        abort_if($record === null, 404);

        return view('dev-console.error-show', [
            'event' => $record,
            'labels' => $this->console->labelsFor([$record]),
            'queries' => $this->console->slowQueriesForRequest($record->request_id),
        ]);
    }

    public function slowRequests(ObservabilityFilterRequest $request): View
    {
        $this->audit->recordAccess($request->user(), $request->ip());
        $events = $this->console->slowRequests($request->filters());

        return view('dev-console.slow-requests', [
            'events' => $events,
            'labels' => $this->console->labelsFor($events->items()),
            'options' => $this->console->filterOptions(),
            'visibility' => $this->console->timeoutVisibility(),
        ]);
    }

    public function slowQueries(ObservabilityFilterRequest $request): View
    {
        $this->audit->recordAccess($request->user(), $request->ip());
        $filters = $request->filters();
        $queries = $this->console->slowQueries($filters);

        return view('dev-console.slow-queries', [
            'queries' => $queries,
            'aggregates' => $this->console->slowQueryAggregates($filters),
            'labels' => $this->console->labelsFor($queries->items()),
        ]);
    }

    public function cache(ObservabilityFilterRequest $request): View
    {
        $this->audit->recordAccess($request->user(), $request->ip());
        $hours = (int) config('observability_console.trend.hours', 24);

        return view('dev-console.cache', [
            'snapshot' => $this->cache->snapshot(now()->subHours($hours)),
            'windowHours' => $hours,
        ]);
    }
}
