<?php

namespace App\Modules\RmeOnlineContext\Middleware;

use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sprint 66.0 — Keep online presence fresh on authenticated requests.
 */
class TouchOnlineContextLastSeen
{
    public function __construct(
        private readonly UserOnlineContextService $onlineContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $this->onlineContext->touchLastSeen($user);
        }

        return $next($request);
    }
}
