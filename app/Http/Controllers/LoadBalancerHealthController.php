<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * LB-1 — minimal, unauthenticated health endpoint for load balancer checks.
 *
 * Output is intentionally tiny and non-sensitive: no APP_KEY, DB host/user,
 * env values, route list, user/branch/patient data, or stack traces.
 */
class LoadBalancerHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        if (! config('load_balancer.health_deep_check')) {
            return response()->json([
                'status' => 'ok',
                'service' => 'daengtisiams',
                'check' => 'lb',
            ]);
        }

        try {
            DB::select('select 1');
        } catch (Throwable) {
            return response()->json([
                'status' => 'fail',
                'service' => 'daengtisiams',
                'check' => 'lb',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'service' => 'daengtisiams',
            'check' => 'lb',
        ]);
    }
}
