<?php

namespace App\Modules\Observability\Middleware;

use App\Modules\Observability\Services\RequestTelemetryRecorder;
use App\Modules\Observability\Support\TelemetryCollector;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — GLOBAL request telemetry.
 *
 * Registered globally (not in the web group) so a 404 for a path that matches
 * no route — which never enters a route group — is still observed.
 *
 * handle() only arms the in-memory collector and measures; it never touches
 * the database. All persistence happens in terminate(), which PHP-FPM runs
 * after the response has been flushed to the client.
 */
class RecordRequestTelemetry
{
    public function __construct(
        private readonly RequestTelemetryRecorder $recorder,
        private readonly TelemetryCollector $collector,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->recorder->enabled()) {
            return $next($request);
        }

        $request->attributes->set(RequestTelemetryRecorder::ATTR_STARTED, microtime(true));
        $this->collector->begin();

        $response = $next($request);

        $start = defined('LARAVEL_START')
            ? (float) LARAVEL_START
            : (float) $request->attributes->get(RequestTelemetryRecorder::ATTR_STARTED);
        $request->attributes->set(
            RequestTelemetryRecorder::ATTR_DURATION,
            max(0, (int) round((microtime(true) - $start) * 1000))
        );

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->collector->isActive()) {
            $this->recorder->record($request, $response);
        }
    }
}
