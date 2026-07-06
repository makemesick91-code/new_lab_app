<?php

namespace App\Http\Controllers;

use App\Support\Health\HealthCheckService;
use Illuminate\Http\JsonResponse;

/**
 * ENT-8 — Observability & Health Check Pack endpoints.
 *
 * Minimal, unauthenticated, non-sensitive liveness/readiness endpoints for
 * uptime monitors and load balancers. Output carries only overall status +
 * per-component ok/degraded/down — never APP_KEY, DB host/user, env values,
 * route list, user/branch/patient data, secrets, or stack traces.
 */
class HealthCheckController extends Controller
{
    public function __construct(private readonly HealthCheckService $health) {}

    public function live(): JsonResponse
    {
        return response()->json($this->health->liveness());
    }

    public function ready(): JsonResponse
    {
        $payload = $this->health->readiness();

        $status = $payload['status'] === HealthCheckService::STATUS_DOWN ? 503 : 200;

        return response()->json($payload, $status);
    }
}
